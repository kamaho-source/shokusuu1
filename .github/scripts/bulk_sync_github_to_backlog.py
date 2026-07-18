#!/usr/bin/env python3
"""
GitHub 既存Issue → Backlog 一括同期スクリプト

- オープン中の全GitHub Issueを取得（ページング対応）
- Backlog起源（<!-- backlog-synced -->）はスキップ
- 既にBacklogキーが付与済みのIssueはスキップ
- 残りのIssueをBacklogに新規作成
- GitHub Issue タイトルと本文にBacklogキーを反映
"""

import os
import sys
import re
import json
import time
import urllib.request
import urllib.error
import urllib.parse

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import load_backlog_env, resolve_bl_base, bl_request
from sync_utils import get_gh_token, get_gh_repo, gh_request, extract_backlog_key, is_backlog_synced, get_linked_prs, format_pr_section

GH_API_BASE = "https://api.github.com"


def get_all_open_issues(token: str, repo: str) -> list:
    """オープン中のGitHub Issueを全件取得する（PR除外・ページング対応）。"""
    issues = []
    page = 1
    per_page = 100

    while True:
        result = gh_request(
            token, "GET",
            f"/repos/{repo}/issues?state=open&per_page={per_page}&page={page}&sort=created&direction=asc"
        )
        if not result:
            break

        # PRを除外（PRはpull_requestキーを持つ）
        page_issues = [i for i in result if "pull_request" not in i]
        issues.extend(page_issues)

        print(f"  ページ {page}: {len(page_issues)}件取得（PR除外後）")

        if len(result) < per_page:
            break
        page += 1

    return issues


def get_issue_type_id(base: str, api_key: str, project_key: str) -> int:
    types = bl_request(base, api_key, "GET", f"/projects/{project_key}/issueTypes")
    if not types:
        print("[ERROR] 課題種別取得失敗")
        sys.exit(1)
    for t in types:
        if t.get("name") == "タスク":
            return t["id"]
    return types[0]["id"]


def create_backlog_issue(base: str, api_key: str, project_id: int, issue_type_id: int,
                         title: str, description: str) -> dict | None:
    return bl_request(base, api_key, "POST", "/issues", {
        "projectId":   project_id,
        "summary":     title,
        "issueTypeId": issue_type_id,
        "priorityId":  3,
        "description": description,
    })


def update_github_issue(token: str, repo: str, issue_number: int,
                        original_title: str, original_body: str,
                        backlog_key: str) -> None:
    # タイトルから既存の[KEY]プレフィックスを除去してから付与
    clean_title = re.sub(r"^\[[\w]+-\d+\]\s*", "", original_title)
    new_title = f"[{backlog_key}] {clean_title}"

    marker = f"<!-- backlog-key:{backlog_key} -->"
    if marker not in (original_body or ""):
        new_body = f"{marker}\n\n{original_body or ''}"
    else:
        new_body = original_body

    gh_request(token, "PATCH", f"/repos/{repo}/issues/{issue_number}", {
        "title": new_title,
        "body":  new_body,
    })


def main():
    api_key, space_id, project_key, domain = load_backlog_env()
    token = get_gh_token()
    repo  = get_gh_repo()
    base  = resolve_bl_base(space_id, domain)

    dry_run = os.environ.get("DRY_RUN", "false").lower() == "true"
    if dry_run:
        print("[DRY RUN] 実際の作成・更新は行いません")

    # プロジェクト情報取得
    proj = bl_request(base, api_key, "GET", f"/projects/{project_key}")
    if not proj:
        print(f"[ERROR] プロジェクト {project_key} が見つかりません")
        sys.exit(1)
    project_id = proj["id"]
    print(f"Backlogプロジェクト: {proj['name']} (id={project_id})")

    issue_type_id = get_issue_type_id(base, api_key, project_key)

    # 既存GitHub Issueを全件取得
    print(f"\nGitHub Issue を取得中 ({repo})...")
    issues = get_all_open_issues(token, repo)
    print(f"合計 {len(issues)} 件のオープンIssueを取得しました\n")

    created = skipped_synced = skipped_has_key = failed = 0

    for iss in issues:
        number = iss.get("number")
        title  = iss.get("title", "")
        body   = iss.get("body", "") or ""
        url    = iss.get("html_url", "")

        # Backlog起源Issueはスキップ
        if is_backlog_synced(body):
            print(f"  [SKIP] #{number} Backlog起源のIssue: {title[:50]}")
            skipped_synced += 1
            continue

        # 既にBacklogキーが付与済みならスキップ
        existing_key = extract_backlog_key(body, project_key)
        if not existing_key:
            # タイトルからも確認
            existing_key = extract_backlog_key(title, project_key)

        if existing_key:
            print(f"  [SKIP] #{number} 既にBacklogキー付与済み ({existing_key}): {title[:50]}")
            skipped_has_key += 1
            continue

        # タイトルの[KEY]プレフィックスを除去
        clean_title = re.sub(r"^\[[\w]+-\d+\]\s*", "", title)

        # 紐づくPRを取得
        linked_prs = get_linked_prs(token, repo, number) if not dry_run else []
        pr_section = format_pr_section(linked_prs)

        start_marker = "<!-- github-sync:start -->"
        end_marker   = "<!-- github-sync:end -->"
        description = (
            f"GitHub Issue: {url}\n\n"
            + (f"{pr_section}\n\n" if pr_section else "")
            + f"{start_marker}\n\n"
            f"{body}\n\n"
            f"{end_marker}"
        )

        print(f"  [CREATE] #{number}: {clean_title[:60]}")

        if dry_run:
            created += 1
            continue

        result = create_backlog_issue(base, api_key, project_id, issue_type_id, clean_title, description)
        if not result:
            print(f"  [ERROR] #{number} Backlog課題の作成に失敗しました")
            failed += 1
            continue

        backlog_key = result.get("issueKey", "")
        print(f"    → Backlog課題を作成しました: {backlog_key}")

        # GitHub IssueにBacklogキーを反映
        update_github_issue(token, repo, number, title, body, backlog_key)
        print(f"    → GitHub Issue #{number} を更新しました（タイトル・本文にキーを付与）")

        created += 1
        # API制限対策
        time.sleep(0.5)

    print(f"""
=== 一括同期完了 ===
作成:             {created} 件
スキップ(Backlog起源): {skipped_synced} 件
スキップ(キー付与済み): {skipped_has_key} 件
エラー:           {failed} 件
""")

    if failed > 0:
        sys.exit(1)


if __name__ == "__main__":
    main()
