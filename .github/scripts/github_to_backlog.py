#!/usr/bin/env python3
"""
GitHub Issue → Backlog課題 同期スクリプト

- opened:   Backlog課題を新規作成（Backlog起源Issueはスキップ）
- edited:   Backlog課題タイトル・説明を更新
- closed:   Backlog課題を完了に
- reopened: Backlog課題を未対応に
"""

import os
import sys
import re
import json

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import load_backlog_env, resolve_bl_base, bl_request
from sync_utils import get_gh_token, get_gh_repo, gh_request, extract_backlog_key, is_backlog_synced, update_sync_section


def get_issue_type_id(base: str, api_key: str, project_key: str) -> int:
    types = bl_request(base, api_key, "GET", f"/projects/{project_key}/issueTypes")
    if not types:
        print("[WARN] 課題種別取得失敗。デフォルトIDを使用できません")
        sys.exit(1)
    # 「タスク」を優先検索
    for t in types:
        if t.get("name") == "タスク":
            print(f"課題種別を使用: {t['name']} (id={t['id']})")
            return t["id"]
    print(f"課題種別を使用: {types[0]['name']} (id={types[0]['id']})")
    return types[0]["id"]


def get_status_id(base: str, api_key: str, project_key: str, status_name: str) -> int:
    statuses = bl_request(base, api_key, "GET", f"/projects/{project_key}/statuses")
    if statuses:
        for s in statuses:
            if s.get("name") == status_name:
                return s["id"]
    # フォールバック
    return 1 if status_name == "未対応" else 4


def create_backlog_issue(base: str, api_key: str, project_id: int, issue_type_id: int,
                         title: str, description: str) -> dict | None:
    priority_id = 3  # Backlog標準の優先度「中」
    return bl_request(base, api_key, "POST", "/issues", {
        "projectId":    project_id,
        "summary":      title,
        "issueTypeId":  issue_type_id,
        "priorityId":   priority_id,
        "description":  description,
    })


def update_github_issue_with_backlog_key(token: str, repo: str, issue_number: int,
                                          original_title: str, original_body: str,
                                          backlog_key: str) -> None:
    new_title = f"[{backlog_key}] {original_title}"
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
    token  = get_gh_token()
    repo   = get_gh_repo()
    base   = resolve_bl_base(space_id, domain)

    event_name   = os.environ.get("GITHUB_EVENT_NAME", "")
    event_action = os.environ.get("GH_EVENT_ACTION", "")
    issue_number = int(os.environ.get("GH_ISSUE_NUMBER", "0"))
    issue_title  = os.environ.get("GH_ISSUE_TITLE", "")
    issue_body   = os.environ.get("GH_ISSUE_BODY", "")
    issue_url    = os.environ.get("GH_ISSUE_URL", "")
    issue_user   = os.environ.get("GH_ISSUE_USER", "")

    if not issue_number:
        print("[ERROR] Issue番号が取得できません")
        sys.exit(1)

    print(f"GitHub Issue #{issue_number} の処理: action={event_action}")

    proj = bl_request(base, api_key, "GET", f"/projects/{project_key}")
    if not proj:
        print(f"[ERROR] プロジェクト {project_key} が見つかりません")
        sys.exit(1)
    project_id = proj["id"]

    # タイトルから [KEY] プレフィックスを除去
    clean_title = re.sub(rf"^\[{re.escape(project_key)}-\d+\]\s*", "", issue_title)

    if event_action == "opened":
        # Backlog起源Issueは新規作成しない
        if is_backlog_synced(issue_body):
            print(f"Backlog起源のIssueのためスキップします: #{issue_number}")
            return

        issue_type_id = get_issue_type_id(base, api_key, project_key)
        github_issue_url = f"https://github.com/{repo}/issues/{issue_number}"
        start_marker = "<!-- github-sync:start -->"
        end_marker   = "<!-- github-sync:end -->"
        description = (
            f"GitHub Issue: {github_issue_url}\n\n"
            f"{start_marker}\n\n"
            f"{issue_body or ''}\n\n"
            f"{end_marker}"
        )

        result = create_backlog_issue(base, api_key, project_id, issue_type_id, clean_title, description)
        if not result:
            print(f"[ERROR] Backlog課題の作成に失敗しました")
            sys.exit(1)

        backlog_key = result.get("issueKey", "")
        print(f"Backlog課題を作成しました: {backlog_key} ← GitHub Issue #{issue_number}")

        # GitHub IssueにBacklog課題キーを反映
        update_github_issue_with_backlog_key(token, repo, issue_number, clean_title, issue_body, backlog_key)

    elif event_action == "edited":
        backlog_key = extract_backlog_key(issue_body, project_key)
        if not backlog_key:
            print(f"Issue #{issue_number} にBacklog課題キーがありません。スキップします")
            return

        # 現在のBacklog課題説明を取得
        current = bl_request(base, api_key, "GET", f"/issues/{backlog_key}", fatal=False)
        current_desc = current.get("description", "") if current else ""
        current_summary = current.get("summary", "") if current else ""

        github_issue_url = f"https://github.com/{repo}/issues/{issue_number}"
        new_sync_content = issue_body or ""
        new_desc = update_sync_section(current_desc, new_sync_content)

        update_data = {}
        if current_summary != clean_title:
            update_data["summary"] = clean_title
        if current_desc != new_desc:
            update_data["description"] = new_desc

        if update_data:
            bl_request(base, api_key, "PATCH", f"/issues/{backlog_key}", update_data)
            print(f"Backlog課題を更新しました: {backlog_key} ← GitHub Issue #{issue_number}")

            # 更新通知コメントをBacklogへ追加
            comment_body = (
                f"GitHub Issue #{issue_number} が更新されました。\n\n"
                f"- 更新者: @{issue_user}\n"
                f"- タイトル: {clean_title}\n"
                f"- GitHub Issue: {github_issue_url}"
            )
            bl_request(base, api_key, "POST", f"/issues/{backlog_key}/comments",
                       {"content": comment_body}, fatal=False)
        else:
            print(f"差分なし。更新をスキップしました: {backlog_key}")

    elif event_action in ("closed", "reopened"):
        backlog_key = extract_backlog_key(issue_body, project_key)
        if not backlog_key:
            print(f"Issue #{issue_number} にBacklog課題キーがありません。スキップします")
            return

        status_name = "完了" if event_action == "closed" else "未対応"
        status_id   = get_status_id(base, api_key, project_key, status_name)
        bl_request(base, api_key, "PATCH", f"/issues/{backlog_key}", {"statusId": status_id})
        print(f"Backlog課題のステータスを更新しました: {backlog_key} → {status_name}")

    else:
        print(f"未対応のアクション: {event_action}")


if __name__ == "__main__":
    main()
