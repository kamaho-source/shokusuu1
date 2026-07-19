#!/usr/bin/env python3
"""
GitHub PR → Backlog課題 PR情報同期スクリプト

- PR が opened / edited / closed / reopened / merged になったとき
- PRの本文から紐づくIssueのBacklogキーを取得
- BacklogにPR情報をコメントとして追加（重複防止付き）
- BacklogのPRステータスセクションを更新
"""

import os
import sys
import re

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import load_backlog_env, resolve_bl_base, bl_request, get_status_id
from sync_utils import extract_backlog_key

CLOSING_PATTERN = re.compile(
    r"(?:closes?|fixes?|resolves?)\s+#(\d+)",
    re.IGNORECASE,
)

# マージ先ブランチ → Backlogステータス名 のマッピング
def resolve_status_name(base_branch: str) -> str:
    """マージ先ブランチからBacklogステータス名を決定する。"""
    if base_branch == "develop":
        return "ステージング環境反映"
    if base_branch == "release" or base_branch.startswith("release/"):
        return "リリース待ち"
    if base_branch == "main":
        return "完了"
    # feature→feature など標準フロー外
    return "完了済み"


def find_linked_issue_numbers(pr_body: str) -> list[int]:
    """PR本文から "Closes/Fixes/Resolves #N" パターンのIssue番号を抽出する。"""
    return [int(m.group(1)) for m in CLOSING_PATTERN.finditer(pr_body or "")]


def get_backlog_key_from_issue(token: str, repo: str, issue_number: int, project_key: str) -> str | None:
    """GitHub IssueのタイトルまたはボディからBacklogキーを取得する。"""
    import urllib.request
    import urllib.error
    import json

    url = f"https://api.github.com/repos/{repo}/issues/{issue_number}"
    req = urllib.request.Request(url)
    req.add_header("Authorization", f"Bearer {token}")
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            issue = json.loads(resp.read().decode("utf-8"))
    except Exception:
        return None

    title = issue.get("title", "")
    body  = issue.get("body", "") or ""
    return extract_backlog_key(title + "\n" + body, project_key)


def pr_comment_already_exists(base: str, api_key: str, backlog_key: str, pr_number: int) -> bool:
    """同じPR番号のコメントが既にBacklogにあるか確認する。"""
    marker = f"<!-- github-pr-id:{pr_number} -->"
    comments = bl_request(base, api_key, "GET", f"/issues/{backlog_key}/comments",
                          {"count": 100, "order": "desc"}, fatal=False)
    if not comments:
        return False
    for c in comments:
        if marker in (c.get("content", "") or ""):
            return True
    return False


def build_pr_comment(pr_number: int, pr_title: str, pr_url: str,
                     pr_user: str, pr_action: str, repo: str,
                     issue_number: int | None = None) -> str:
    issue_url = f"https://github.com/{repo}/issues/{issue_number}" if issue_number else ""
    action_labels = {
        "opened":      "📋 PRが作成されました",
        "edited":      "✏️ PRが更新されました",
        "closed":      "❌ PRがクローズされました",
        "reopened":    "🔄 PRが再オープンされました",
        "merged":      "✅ PRがマージされました",
        "synchronize": "🔄 PRが更新されました（コミット追加）",
    }
    action_text = action_labels.get(pr_action, f"PR が {pr_action} されました")

    lines = [
        f"<!-- github-pr-id:{pr_number} -->",
        f"",
        f"## {action_text}",
        f"",
        f"- **PRタイトル:** [{pr_title}]({pr_url}) (#{pr_number})",
        f"- **操作者:** @{pr_user}",
        f"- **PR URL:** {pr_url}",
    ]
    if issue_url:
        lines.append(f"- **関連Issue:** {issue_url}")

    return "\n".join(lines)


def main():
    api_key, space_id, project_key, domain = load_backlog_env()
    base = resolve_bl_base(space_id, domain)

    gh_token    = os.environ.get("GH_TOKEN", "")
    repo        = os.environ.get("GH_REPO", "")
    pr_number   = int(os.environ.get("GH_PR_NUMBER", "0"))
    pr_title    = os.environ.get("GH_PR_TITLE", "")
    pr_body     = os.environ.get("GH_PR_BODY", "")
    pr_url      = os.environ.get("GH_PR_URL", "")
    pr_user     = os.environ.get("GH_PR_USER", "")
    pr_action      = os.environ.get("GH_PR_ACTION", "opened")
    pr_merged      = os.environ.get("GH_PR_MERGED", "false").lower() == "true"
    pr_base_branch = os.environ.get("GH_PR_BASE_BRANCH", "")

    if pr_merged and pr_action == "closed":
        pr_action = "merged"

    if not pr_number:
        print("[ERROR] PR番号が取得できません")
        sys.exit(1)

    print(f"GitHub PR #{pr_number} の処理: action={pr_action}")

    # PR本文から紐づくIssue番号を取得
    issue_numbers = find_linked_issue_numbers(pr_body)

    # PRタイトルから [SHOKUSU-N] 形式も検索
    key_from_title = extract_backlog_key(pr_title, project_key)
    if key_from_title:
        # タイトルに直接キーがある場合はそれを使う
        backlog_keys = [key_from_title]
        linked_issues = {key_from_title: None}
    else:
        # 紐づくIssueのBacklogキーを収集
        backlog_keys = []
        linked_issues = {}
        for iss_num in issue_numbers:
            key = get_backlog_key_from_issue(gh_token, repo, iss_num, project_key)
            if key:
                backlog_keys.append(key)
                linked_issues[key] = iss_num
                print(f"  Issue #{iss_num} → Backlog課題: {key}")
            else:
                print(f"  Issue #{iss_num} にはBacklogキーがありません。スキップします")

    if not backlog_keys:
        print("紐づくBacklog課題が見つかりませんでした。スキップします")
        return

    for backlog_key in backlog_keys:
        issue_number = linked_issues.get(backlog_key)

        # 重複チェック（openedのみ。edited/mergedは常にコメント追加）
        if pr_action == "opened" and pr_comment_already_exists(base, api_key, backlog_key, pr_number):
            print(f"  [{backlog_key}] PR #{pr_number} のコメントは既に存在します。スキップします")
            continue

        comment = build_pr_comment(pr_number, pr_title, pr_url, pr_user, pr_action, repo, issue_number)
        result = bl_request(base, api_key, "POST", f"/issues/{backlog_key}/comments",
                            {"content": comment}, fatal=False)
        if result:
            print(f"  [{backlog_key}] BacklogにPR情報を追加しました (action={pr_action})")
        else:
            print(f"  [{backlog_key}] Backlogへのコメント追加に失敗しました")

        # マージ時はBacklogステータスを更新する
        if pr_merged and pr_action == "merged":
            status_name = resolve_status_name(pr_base_branch)
            status_id   = get_status_id(base, api_key, project_key, status_name)
            if status_id is not None:
                patch = bl_request(base, api_key, "PATCH", f"/issues/{backlog_key}",
                                   {"statusId": status_id}, fatal=False)
                if patch:
                    print(f"  [{backlog_key}] Backlogステータスを更新しました: '{status_name}' (base={pr_base_branch})")
                else:
                    print(f"  [{backlog_key}] Backlogステータスの更新に失敗しました")
            else:
                print(f"  [{backlog_key}] ステータス '{status_name}' がBacklogに存在しないためスキップしました")


if __name__ == "__main__":
    main()
