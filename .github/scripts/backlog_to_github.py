#!/usr/bin/env python3
"""
Backlog課題 → GitHub Issue 同期スクリプト

- 15分ごとのスケジュール実行 or 手動実行
- Backlogで更新された課題をGitHub Issueに反映
- 重複作成防止: タイトル[KEY]とbodyマーカーで確認
- 無限ループ防止: GitHub起源課題("GitHub Issue: ...")はスキップ
"""

import os
import sys
import re
import json
from datetime import datetime, timezone, timedelta

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import load_backlog_env, resolve_bl_base, bl_request
from sync_utils import get_gh_token, get_gh_repo, gh_request, update_sync_section

GITHUB_ISSUE_URL_PATTERN = r"GitHub Issue: https://github\.com/"

def get_project_id(base: str, api_key: str, project_key: str) -> int:
    proj = bl_request(base, api_key, "GET", f"/projects/{project_key}")
    if not proj:
        print(f"[ERROR] プロジェクト {project_key} が見つかりません")
        sys.exit(1)
    print(f"Backlogプロジェクトを取得しました: {project_key} (id={proj['id']})")
    return proj["id"]


def get_updated_issues(base: str, api_key: str, project_id: int, updated_since: str, minutes_back: int) -> list:
    """ページングしながら更新済み課題を取得する。"""
    threshold = datetime.now(timezone.utc) - timedelta(minutes=minutes_back)
    offset = 0
    count  = 100
    all_issues = []

    while True:
        issues = bl_request(base, api_key, "GET", "/issues", {
            "projectId[]": project_id,
            "updatedSince": updated_since,
            "count":  count,
            "offset": offset,
            "sort":   "updated",
            "order":  "desc",
        })
        if not issues:
            break

        for iss in issues:
            updated_str = iss.get("updated", "")
            try:
                # Backlog日時: "2026-07-18T12:34:56Z"
                updated_dt = datetime.fromisoformat(updated_str.replace("Z", "+00:00"))
                if updated_dt >= threshold:
                    all_issues.append(iss)
            except Exception:
                all_issues.append(iss)

        if len(issues) < count:
            break
        offset += count

    return all_issues


def find_github_issue(token: str, repo: str, backlog_key: str) -> dict | None:
    """Backlog課題キーに対応するGitHub Issueを検索する (open+closed)。"""
    query = f"[{backlog_key}] repo:{repo} in:title is:issue"
    result = gh_request(token, "GET", f"/search/issues?q={query.replace(' ', '+')}&per_page=5")
    if result and result.get("items"):
        for item in result["items"]:
            title = item.get("title", "")
            body  = item.get("body", "") or ""
            if title.startswith(f"[{backlog_key}]") or f"<!-- backlog-key:{backlog_key} -->" in body:
                return item
    return None


def is_github_origin(description: str) -> bool:
    """GitHub起源の課題かどうかを判定する（無限ループ防止）。"""
    return bool(re.search(GITHUB_ISSUE_URL_PATTERN, description or ""))


def backlog_status_to_gh_state(status_name: str) -> str:
    """Backlogステータス名 → GitHub Issue状態。"""
    closed_names = {"完了", "resolved", "closed", "done", "完了済み"}
    return "closed" if status_name.lower() in {s.lower() for s in closed_names} else "open"


def create_github_issue(token: str, repo: str, backlog_key: str, title: str, description: str, space_id: str, domain: str) -> dict | None:
    backlog_url = f"https://{space_id}.{domain}/view/{backlog_key}"
    body = (
        f"<!-- backlog-synced -->\n"
        f"<!-- backlog-key:{backlog_key} -->\n\n"
        f"**Backlog課題:** [{backlog_key}]({backlog_url})\n\n"
        f"---\n\n"
        f"{description or ''}"
    )
    issue = gh_request(token, "POST", f"/repos/{repo}/issues", {
        "title": f"[{backlog_key}] {title}",
        "body": body,
    })
    return issue


def update_github_issue(token: str, repo: str, issue_number: int, backlog_key: str,
                        title: str, description: str, space_id: str, domain: str,
                        target_state: str, current_issue: dict) -> bool:
    current_title = current_issue.get("title", "")
    current_body  = current_issue.get("body", "") or ""
    current_state = current_issue.get("state", "open")

    new_title = f"[{backlog_key}] {title}"
    backlog_url = f"https://{space_id}.{domain}/view/{backlog_key}"
    new_body = (
        f"<!-- backlog-synced -->\n"
        f"<!-- backlog-key:{backlog_key} -->\n\n"
        f"**Backlog課題:** [{backlog_key}]({backlog_url})\n\n"
        f"---\n\n"
        f"{description or ''}"
    )

    update_data = {}
    if current_title != new_title:
        update_data["title"] = new_title
    if current_body != new_body:
        update_data["body"] = new_body
    if current_state != target_state:
        update_data["state"] = target_state

    if not update_data:
        return False  # 差分なし

    gh_request(token, "PATCH", f"/repos/{repo}/issues/{issue_number}", update_data)
    return True


def main():
    api_key, space_id, project_key, domain = load_backlog_env()
    token = get_gh_token()
    repo  = get_gh_repo()
    base  = resolve_bl_base(space_id, domain)

    minutes_back = int(os.environ.get("MINUTES_BACK", "20"))
    updated_since = (datetime.now(timezone.utc) - timedelta(minutes=minutes_back)).strftime("%Y-%m-%d")

    project_id = get_project_id(base, api_key, project_key)
    issues = get_updated_issues(base, api_key, project_id, updated_since, minutes_back)
    print(f"更新対象のBacklog課題: {len(issues)}件")

    created = updated = closed = skipped = failed = 0

    for iss in issues:
        backlog_key  = iss.get("issueKey", "")
        title        = iss.get("summary", "")
        description  = iss.get("description", "") or ""
        status_name  = iss.get("status", {}).get("name", "未対応")
        target_state = backlog_status_to_gh_state(status_name)

        if not backlog_key:
            skipped += 1
            continue

        # 無限ループ防止: GitHub起源の課題は新規作成しない
        if is_github_origin(description):
            # ただしクローズ/再オープンは反映する
            existing = find_github_issue(token, repo, backlog_key)
            if existing and existing.get("state") != target_state:
                gh_request(token, "PATCH", f"/repos/{repo}/issues/{existing['number']}", {"state": target_state})
                print(f"GitHub Issue状態を更新しました: #{existing['number']} ← {backlog_key} ({target_state})")
                updated += 1
            else:
                skipped += 1
            continue

        existing = find_github_issue(token, repo, backlog_key)

        if not existing:
            result = create_github_issue(token, repo, backlog_key, title, description, space_id, domain)
            if result:
                num = result.get("number")
                print(f"GitHub Issueを作成しました: #{num} ← {backlog_key}")
                if target_state == "closed":
                    gh_request(token, "PATCH", f"/repos/{repo}/issues/{num}", {"state": "closed"})
                created += 1
            else:
                print(f"[ERROR] GitHub Issue作成失敗: {backlog_key}")
                failed += 1
        else:
            changed = update_github_issue(
                token, repo, existing["number"], backlog_key,
                title, description, space_id, domain, target_state, existing
            )
            action = "クローズ" if target_state == "closed" and existing.get("state") == "open" else \
                     "再オープン" if target_state == "open" and existing.get("state") == "closed" else "更新"
            if changed:
                print(f"GitHub Issueを{action}しました: #{existing['number']} ← {backlog_key}")
                updated += 1
            else:
                skipped += 1

    print(f"同期完了: created={created}, updated={updated}, closed={closed}, skipped={skipped}, failed={failed}")


if __name__ == "__main__":
    main()
