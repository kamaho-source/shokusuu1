#!/usr/bin/env python3
"""
既存BacklogイシューへPR情報を手動で追記する一時スクリプト。

対象: PR #559 → SHOKUSU-10
"""

import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import load_backlog_env, resolve_bl_base, bl_request
from sync_utils import extract_backlog_key, get_linked_prs, format_pr_section
import urllib.request
import json


def gh_request(token: str, method: str, path: str):
    url = f"https://api.github.com{path}"
    req = urllib.request.Request(url, method=method)
    req.add_header("Authorization", f"Bearer {token}")
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def main():
    api_key, space_id, project_key, domain = load_backlog_env()
    base  = resolve_bl_base(space_id, domain)
    token = os.environ.get("GH_TOKEN", "")
    repo  = os.environ.get("GH_REPO", "")

    # PR番号と対象Backlogキーのマッピング（既存未反映分）
    mappings = [
        {"pr_number": 559, "backlog_key": f"{project_key}-10"},
    ]

    for m in mappings:
        pr_num      = m["pr_number"]
        backlog_key = m["backlog_key"]

        # PR情報を取得
        pr = gh_request(token, "GET", f"/repos/{repo}/pulls/{pr_num}")
        pr_title  = pr.get("title", "")
        pr_url    = pr.get("html_url", "")
        pr_user   = pr.get("user", {}).get("login", "")
        pr_merged = pr.get("merged_at") is not None
        pr_state  = "merged" if pr_merged else pr.get("state", "open")

        state_label = "✅ マージ済み" if pr_merged else ("🔄 オープン" if pr_state == "open" else "❌ クローズ")

        comment = (
            f"<!-- github-pr-id:{pr_num} -->\n\n"
            f"## {state_label} PRが紐づいています\n\n"
            f"- **PRタイトル:** [{pr_title}]({pr_url}) (#{pr_num})\n"
            f"- **操作者:** @{pr_user}\n"
            f"- **PR URL:** {pr_url}\n"
        )

        result = bl_request(base, api_key, "POST", f"/issues/{backlog_key}/comments",
                            {"content": comment}, fatal=False)
        if result:
            print(f"[OK] {backlog_key} にPR #{pr_num} の情報を追加しました")
        else:
            print(f"[ERROR] {backlog_key} へのコメント追加に失敗しました")


if __name__ == "__main__":
    main()
