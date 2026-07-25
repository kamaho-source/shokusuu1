#!/usr/bin/env python3
"""
ブランチ到達に応じて Backlog 課題ステータスを更新する。

- develop 到達 → ステージング反映済み
- release 到達 → リリース待ち
- main 到達    → 完了

トリガー想定:
  - develop / release / main 向け PR の merge
  - 同ブランチへの push（コミットメッセージから課題キーを抽出）
"""

from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(__file__))
from backlog_client import (  # noqa: E402
    bl_request,
    get_status_id,
    load_backlog_env,
    resolve_bl_base,
)
from sync_utils import extract_backlog_key, get_gh_repo, get_gh_token, gh_request  # noqa: E402

CLOSING_PATTERN = re.compile(
    r"(?:closes?|fixes?|resolves?)\s+#(\d+)",
    re.IGNORECASE,
)
ISSUE_REF_PATTERN = re.compile(r"(?<![A-Za-z0-9_-])#(\d+)\b")

# 進行方向のみ進める（降格しない）
STATUS_RANK = {
    "ステージング反映済み": 1,
    "リリース待ち": 2,
    "完了": 3,
}


def branch_to_status_name(branch: str) -> str | None:
    mapping = {
        "develop": os.environ.get("BACKLOG_STATUS_ON_DEVELOP", "ステージング反映済み"),
        "release": os.environ.get("BACKLOG_STATUS_ON_RELEASE", "リリース待ち"),
        "main": os.environ.get("BACKLOG_STATUS_ON_MAIN", "完了"),
    }
    return mapping.get(branch)


def find_issue_numbers(*texts: str) -> list[int]:
    found: list[int] = []
    for text in texts:
        if not text:
            continue
        for m in CLOSING_PATTERN.finditer(text):
            found.append(int(m.group(1)))
        # Closes 以外の素の #N もコミットメッセージ向けに拾う（PR番号誤検知を減らすため closes 優先）
        if not CLOSING_PATTERN.search(text):
            for m in ISSUE_REF_PATTERN.finditer(text):
                found.append(int(m.group(1)))
    # 順序維持で重複除去
    seen: set[int] = set()
    out: list[int] = []
    for n in found:
        if n not in seen:
            seen.add(n)
            out.append(n)
    return out


def find_backlog_keys_in_text(project_key: str, *texts: str) -> list[str]:
    keys: list[str] = []
    pattern = re.compile(rf"\b({re.escape(project_key)}-\d+)\b", re.IGNORECASE)
    for text in texts:
        if not text:
            continue
        marker_key = extract_backlog_key(text, project_key)
        if marker_key:
            keys.append(marker_key)
        for m in pattern.finditer(text):
            keys.append(m.group(1))

    normalized: list[str] = []
    seen: set[str] = set()
    for key in keys:
        m = re.match(rf"^({re.escape(project_key)})-(\d+)$", key, re.IGNORECASE)
        if not m:
            continue
        nk = f"{project_key}-{m.group(2)}"
        if nk not in seen:
            seen.add(nk)
            normalized.append(nk)
    return normalized


def get_backlog_key_from_issue(token: str, repo: str, issue_number: int, project_key: str) -> str | None:
    issue = gh_request(token, "GET", f"/repos/{repo}/issues/{issue_number}")
    if not issue:
        return None
    title = issue.get("title", "") or ""
    body = issue.get("body", "") or ""
    return extract_backlog_key(title + "\n" + body, project_key)


def collect_keys_from_pr(
    token: str,
    repo: str,
    project_key: str,
    pr_number: int,
    pr_title: str,
    pr_body: str,
) -> set[str]:
    keys = set(find_backlog_keys_in_text(project_key, pr_title, pr_body))

    for iss_num in find_issue_numbers(pr_title, pr_body):
        key = get_backlog_key_from_issue(token, repo, iss_num, project_key)
        if key:
            keys.add(key)
            print(f"  PR #{pr_number} Issue #{iss_num} → {key}")
        else:
            print(f"  PR #{pr_number} Issue #{iss_num} に Backlog キーなし")

    commits = gh_request(token, "GET", f"/repos/{repo}/pulls/{pr_number}/commits?per_page=100") or []
    for commit in commits:
        message = (commit.get("commit") or {}).get("message", "") or ""
        keys.update(find_backlog_keys_in_text(project_key, message))
        for iss_num in find_issue_numbers(message):
            key = get_backlog_key_from_issue(token, repo, iss_num, project_key)
            if key:
                keys.add(key)

    return keys


def collect_keys_from_push(token: str, repo: str, project_key: str, commits_json: str) -> set[str]:
    import json

    keys: set[str] = set()
    try:
        commits = json.loads(commits_json or "[]")
    except json.JSONDecodeError:
        print("[WARN] GH_PUSH_COMMITS の JSON 解析に失敗しました")
        commits = []

    for commit in commits:
        message = commit.get("message", "") or ""
        keys.update(find_backlog_keys_in_text(project_key, message))
        for iss_num in find_issue_numbers(message):
            key = get_backlog_key_from_issue(token, repo, iss_num, project_key)
            if key:
                keys.add(key)
                print(f"  commit Issue #{iss_num} → {key}")
    return keys


def current_status_name(base: str, api_key: str, backlog_key: str) -> str | None:
    issue = bl_request(base, api_key, "GET", f"/issues/{backlog_key}", fatal=False)
    if not issue:
        return None
    return (issue.get("status") or {}).get("name")


def should_update(current: str | None, target: str) -> bool:
    if current is None:
        return True
    if current == target:
        return False
    cur_rank = STATUS_RANK.get(current, 0)
    tgt_rank = STATUS_RANK.get(target, 0)
    # 既知の進行ステータス同士は降格しない。未知ステータスからは昇格可能。
    if cur_rank and tgt_rank and tgt_rank < cur_rank:
        print(f"  降格のためスキップ: {current} → {target}")
        return False
    return True


def update_status(
    base: str,
    api_key: str,
    project_key: str,
    backlog_key: str,
    target_status: str,
    branch: str,
    source: str,
) -> bool:
    status_id = get_status_id(base, api_key, project_key, target_status, fatal=False)
    if status_id is None:
        return False

    current = current_status_name(base, api_key, backlog_key)
    if not should_update(current, target_status):
        print(f"  [{backlog_key}] 変更なし ({current})")
        return False

    result = bl_request(
        base,
        api_key,
        "PATCH",
        f"/issues/{backlog_key}",
        {"statusId": status_id},
        fatal=False,
    )
    if not result:
        print(f"  [{backlog_key}] ステータス更新失敗")
        return False

    comment = (
        f"<!-- github-branch-status:{branch} -->\n\n"
        f"GitHub ブランチ `{branch}` に到達したため、ステータスを「{target_status}」に更新しました。\n"
        f"- トリガー: {source}\n"
        f"- 以前のステータス: {current or '(不明)'}"
    )
    bl_request(
        base,
        api_key,
        "POST",
        f"/issues/{backlog_key}/comments",
        {"content": comment},
        fatal=False,
    )
    print(f"  [{backlog_key}] {current} → {target_status}")
    return True


def main() -> None:
    api_key, space_id, project_key, domain = load_backlog_env()
    base = resolve_bl_base(space_id, domain)
    token = get_gh_token()
    repo = get_gh_repo()

    event_name = os.environ.get("GITHUB_EVENT_NAME", "")
    branch = os.environ.get("GH_TARGET_BRANCH", "").strip()
    pr_merged = os.environ.get("GH_PR_MERGED", "false").lower() == "true"
    pr_number = int(os.environ.get("GH_PR_NUMBER", "0") or "0")
    pr_title = os.environ.get("GH_PR_TITLE", "")
    pr_body = os.environ.get("GH_PR_BODY", "")
    push_commits = os.environ.get("GH_PUSH_COMMITS", "[]")

    if not branch:
        print("[ERROR] GH_TARGET_BRANCH が未設定です")
        sys.exit(1)

    target_status = branch_to_status_name(branch)
    if not target_status:
        print(f"対象外ブランチのためスキップ: {branch}")
        return

    print(f"イベント={event_name} branch={branch} → status={target_status}")

    keys: set[str] = set()
    source = event_name

    if event_name == "pull_request":
        if not pr_merged:
            print("未マージの PR のためスキップします")
            return
        if pr_number <= 0:
            print("[ERROR] PR番号が取得できません")
            sys.exit(1)
        source = f"PR #{pr_number} merge → {branch}"
        keys = collect_keys_from_pr(token, repo, project_key, pr_number, pr_title, pr_body)
    elif event_name == "push":
        source = f"push → {branch}"
        keys = collect_keys_from_push(token, repo, project_key, push_commits)
    else:
        # workflow_dispatch 等: PR番号があれば PR 基準、なければ push commits
        if pr_number > 0:
            source = f"manual PR #{pr_number} → {branch}"
            keys = collect_keys_from_pr(token, repo, project_key, pr_number, pr_title, pr_body)
        else:
            source = f"manual → {branch}"
            keys = collect_keys_from_push(token, repo, project_key, push_commits)

    if not keys:
        print("紐づく Backlog 課題が見つかりませんでした。スキップします")
        return

    print(f"更新対象: {', '.join(sorted(keys))}")
    updated = 0
    for key in sorted(keys):
        if update_status(base, api_key, project_key, key, target_status, branch, source):
            updated += 1
    print(f"完了: updated={updated}/{len(keys)}")


if __name__ == "__main__":
    main()
