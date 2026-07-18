"""Backlog API共通クライアント"""
import os
import sys
import time
import urllib.parse
import urllib.request
import urllib.error
import json


def load_backlog_env() -> tuple[str, str, str, str]:
    """API_KEY, SPACE_ID, PROJECT_KEY, DOMAINを返す。"""
    api_key     = os.environ.get("BACKLOG_API_KEY", "")
    space_id    = os.environ.get("BACKLOG_SPACE_ID", "")
    project_key = os.environ.get("BACKLOG_PROJECT_KEY", "")
    domain      = os.environ.get("BACKLOG_DOMAIN", "backlog.com")

    missing = [k for k, v in {
        "BACKLOG_API_KEY": api_key,
        "BACKLOG_SPACE_ID": space_id,
        "BACKLOG_PROJECT_KEY": project_key,
    }.items() if not v]

    if missing:
        print(f"[ERROR] 必須環境変数が未設定です: {', '.join(missing)}")
        sys.exit(1)

    print(f"Backlog設定: space={space_id}, project={project_key}, domain={domain}")
    print(f"apiKey configured={'true' if api_key else 'false'}")
    return api_key, space_id.strip(), project_key.strip(), domain.strip()


def resolve_bl_base(space_id: str, domain: str = "backlog.com") -> str:
    """Backlog APIのベースURLを返す。"""
    return f"https://{space_id}.{domain}/api/v2"


def bl_request(
    base: str,
    api_key: str,
    method: str,
    path: str,
    data: dict | None = None,
    *,
    fatal: bool = True,
    max_retries: int = 3,
):
    """Backlog APIへリクエストする。再試行付き。"""
    url = f"{base}{path}"
    if "?" in url:
        url += f"&apiKey={api_key}"
    else:
        url += f"?apiKey={api_key}"

    # GETパラメータをURLに付与
    if method == "GET" and data:
        params = []
        for k, v in data.items():
            if isinstance(v, list):
                for item in v:
                    params.append((k, str(item)))
            else:
                params.append((k, str(v)))
        url += "&" + urllib.parse.urlencode(params)
        body = None
    elif data:
        body = urllib.parse.urlencode(data).encode("utf-8")
    else:
        body = None

    for attempt in range(max_retries):
        try:
            req = urllib.request.Request(url, data=body, method=method)
            if body:
                req.add_header("Content-Type", "application/x-www-form-urlencoded")
            with urllib.request.urlopen(req, timeout=30) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            status = e.code
            body_txt = e.read().decode("utf-8", errors="replace")

            if status == 401:
                print("[ERROR] Backlog APIの認証に失敗しました。")
                print("確認項目:")
                print("- BACKLOG_API_KEYが正しいか")
                print("- Secretの前後に空白や改行がないか")
                print(f"- BACKLOG_SPACE_IDが正しいか")
                print(f"- BACKLOG_DOMAINが正しいか")
                if fatal:
                    sys.exit(1)
                return None

            if status in (429, 500, 502, 503, 504) and attempt < max_retries - 1:
                wait = 2 ** attempt
                print(f"[WARN] HTTP {status} - {wait}秒後に再試行 ({attempt+1}/{max_retries})")
                time.sleep(wait)
                continue

            print(f"[ERROR] Backlog API {method} {path} → HTTP {status}: {body_txt[:200]}")
            if fatal:
                sys.exit(1)
            return None
        except Exception as e:
            if attempt < max_retries - 1:
                wait = 2 ** attempt
                print(f"[WARN] リクエスト失敗: {e} - {wait}秒後に再試行")
                time.sleep(wait)
                continue
            print(f"[ERROR] Backlog APIリクエスト失敗: {e}")
            if fatal:
                sys.exit(1)
            return None

    return None
