"""GitHub API共通ユーティリティ"""
import os
import sys
import json
import re
import urllib.request
import urllib.error


GH_API_BASE = "https://api.github.com"


def get_gh_token() -> str:
    token = os.environ.get("GH_TOKEN", "")
    if not token:
        print("[ERROR] GH_TOKENが設定されていません")
        sys.exit(1)
    return token


def get_gh_repo() -> str:
    repo = os.environ.get("GH_REPO", "")
    if not repo:
        print("[ERROR] GH_REPOが設定されていません")
        sys.exit(1)
    return repo


def gh_request(token: str, method: str, path: str, data: dict | None = None):
    """GitHub APIへリクエストする。"""
    url = f"{GH_API_BASE}{path}"
    body = json.dumps(data).encode("utf-8") if data else None
    req = urllib.request.Request(url, data=body, method=method)
    req.add_header("Authorization", f"Bearer {token}")
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    if body:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body_txt = e.read().decode("utf-8", errors="replace")
        print(f"[ERROR] GitHub API {method} {path} → HTTP {e.code}: {body_txt[:300]}")
        return None


def get_linked_prs(token: str, repo: str, issue_number: int) -> list[dict]:
    """Issueに紐づくPR一覧を返す（Closes/Fixes/Resolves #N パターンで検索）。"""
    result = gh_request(
        token, "GET",
        f"/repos/{repo}/issues/{issue_number}/timeline?per_page=100"
    )
    if not result:
        return []

    pr_numbers = set()
    for event in result:
        if event.get("event") == "cross-referenced":
            src = event.get("source", {})
            if src.get("type") == "pullrequest":
                issue_info = src.get("issue", {})
                if issue_info.get("pull_request"):
                    pr_numbers.add(issue_info["number"])

    # PR本文で "Closes/Fixes/Resolves #N" を検索
    search_result = gh_request(
        token, "GET",
        f"/search/issues?q=repo:{repo}+is:pr+{issue_number}+in:body&per_page=20"
    )
    if search_result:
        for item in search_result.get("items", []):
            body = item.get("body", "") or ""
            if re.search(rf"(?:closes?|fixes?|resolves?)\s+#?{issue_number}\b", body, re.IGNORECASE):
                pr_numbers.add(item["number"])

    prs = []
    for pr_num in sorted(pr_numbers):
        pr = gh_request(token, "GET", f"/repos/{repo}/pulls/{pr_num}")
        if pr:
            prs.append(pr)
    return prs


def format_pr_section(prs: list[dict]) -> str:
    """PR一覧をBacklog用テキストに変換する。"""
    if not prs:
        return ""
    lines = ["**関連PR:**"]
    for pr in prs:
        state = pr.get("state", "open")
        state_label = "✅ マージ済み" if pr.get("merged_at") else ("🔄 オープン" if state == "open" else "❌ クローズ")
        lines.append(f"- [{state_label}] [{pr['title']}]({pr['html_url']}) (#{pr['number']})")
    return "\n".join(lines)


def extract_backlog_key(text: str, project_key: str) -> str | None:
    """Issue本文やタイトルからBacklog課題キーを抽出する。"""
    pattern = rf"<!--\s*backlog-key:({re.escape(project_key)}-\d+)\s*-->"
    m = re.search(pattern, text or "")
    if m:
        return m.group(1)
    # タイトルの [SHOKUSU-123] 形式もフォールバックで検索
    pattern2 = rf"\[({re.escape(project_key)}-\d+)\]"
    m2 = re.search(pattern2, text or "")
    return m2.group(1) if m2 else None


def is_backlog_synced(body: str) -> bool:
    """Backlog起源のIssueかどうかを判定する。"""
    return "<!-- backlog-synced -->" in (body or "")


def update_sync_section(description: str, new_content: str) -> str:
    """Backlog課題説明の同期範囲のみを置換する。"""
    start = "<!-- github-sync:start -->"
    end = "<!-- github-sync:end -->"
    new_section = f"{start}\n\n{new_content}\n\n{end}"
    if start in description and end in description:
        before = description[:description.index(start)]
        after  = description[description.index(end) + len(end):]
        return before + new_section + after
    # 同期セクションがない場合は末尾に追加
    return description.rstrip() + f"\n\n{new_section}"
