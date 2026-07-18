#!/usr/bin/env python3
"""
GitHub Issueコメント → Backlogコメント 同期スクリプト

- created/edited イベントを受けて Backlog へコメントを追加
- コメントIDマーカーで重複防止
- PRコメントは除外
"""

import os
import sys
import re
import json

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import load_backlog_env, resolve_bl_base, bl_request
from sync_utils import get_gh_token, get_gh_repo, gh_request, extract_backlog_key


def comment_already_synced(base: str, api_key: str, backlog_key: str, comment_id: str) -> bool:
    """同じGitHubコメントIDが既にBacklogに存在するか確認する。"""
    marker = f"<!-- github-comment-id:{comment_id} -->"
    # 最新コメントを取得して確認
    comments = bl_request(base, api_key, "GET", f"/issues/{backlog_key}/comments",
                           {"count": 100, "order": "desc"}, fatal=False)
    if not comments:
        return False
    for c in comments:
        if marker in (c.get("content", "") or ""):
            return True
    return False


def main():
    api_key, space_id, project_key, domain = load_backlog_env()
    base = resolve_bl_base(space_id, domain)

    issue_number  = os.environ.get("GH_ISSUE_NUMBER", "")
    issue_body    = os.environ.get("GH_ISSUE_BODY", "")
    comment_id    = os.environ.get("GH_COMMENT_ID", "")
    comment_body  = os.environ.get("GH_COMMENT_BODY", "")
    comment_user  = os.environ.get("GH_COMMENT_USER", "")
    comment_url   = os.environ.get("GH_COMMENT_URL", "")
    event_action  = os.environ.get("GH_EVENT_ACTION", "created")
    repo          = os.environ.get("GH_REPO", "")

    backlog_key = extract_backlog_key(issue_body, project_key)
    if not backlog_key:
        print(f"Issue #{issue_number} にBacklog課題キーがありません。スキップします")
        return

    # 重複防止
    if comment_already_synced(base, api_key, backlog_key, comment_id):
        print(f"コメントID {comment_id} は既にBacklogに同期済みです。スキップします")
        return

    issue_url = f"https://github.com/{repo}/issues/{issue_number}"
    action_text = "追加" if event_action == "created" else "編集"
    content = (
        f"<!-- github-comment-id:{comment_id} -->\n\n"
        f"GitHub Issue #{issue_number} のコメントが{action_text}されました。\n\n"
        f"- 投稿者: @{comment_user}\n"
        f"- GitHub Issue: {issue_url}\n"
        f"- GitHubコメント: {comment_url}\n\n"
        f"---\n\n"
        f"{comment_body}"
    )

    result = bl_request(base, api_key, "POST", f"/issues/{backlog_key}/comments",
                        {"content": content}, fatal=False)
    if result:
        print(f"Backlogコメントを追加しました: {backlog_key} ← GitHub Issue #{issue_number}")
    else:
        print(f"[ERROR] Backlogコメントの追加に失敗しました: {backlog_key}")
        sys.exit(1)


if __name__ == "__main__":
    main()
