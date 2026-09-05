# 本番ホスト側のセキュリティ設定手順（SHOKUSU-32）

Issue #667 / SHOKUSU-32 の対応のうち、**リポジトリのコードだけでは完結せず、本番ホスト上での作業が必要なもの**をまとめる。

## なぜこの手順書が必要か

本番のリクエスト経路は次のとおり。

```
Internet :443
   ↓
ホストnginx（TLS終端 / certbot）      ← リポジトリ管理外。ここが実質の最前段
   ↓ proxy_pass 127.0.0.1:8091
kamakura-shokusu_web コンテナ          ← アプリ本体
```

`docker/nginx/nginx.conf`（`docker-network-reverse-1`）は**この経路上に存在しない**。
したがってリポジトリ側に入れたレート制限や `X-Forwarded-For` の設定は、本番では効かない。
本番で効かせるには、以下を**ホストnginxの設定ファイル**に入れる必要がある。

---

## 1. X-Forwarded-For の付与（最優先・アプリ側の前提条件）

アプリは CakePHP 標準の `clientIp()` でクライアントIPを判定する。
`TRUSTED_PROXIES` 未設定時は **`X-Forwarded-For` の最右の値**を採用する仕様のため、
ホストnginxがこのヘッダを正しく付け替えていることが前提になる。

**現在のホストnginxの設定を必ず確認すること。**
クライアントが送ってきた `X-Forwarded-For` をそのまま素通ししている場合、
ヘッダを偽装するだけでレート制限を回避でき、監査ログのIPも信用できない。

```nginx
location / {
    proxy_pass http://127.0.0.1:8091;

    # クライアント送出値を破棄し、実際の接続元で上書きする
    proxy_set_header X-Forwarded-For   $remote_addr;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Host              $host;
}
```

`$proxy_add_x_forwarded_for`（追記）ではなく `$remote_addr`（上書き）を使う。
追記だと多段構成で最右が中間プロキシになり、アプリが実IPを取得できなくなる。

## 2. ログイン試行のレート制限

アプリ側にもIP単位のthrottle（10回失敗で15分間 429）を実装済みだが、
PHPまで到達する前に落とすほうが軽い。多層で持つ。

`http` ブロック（`server` の外側）に:

```nginx
limit_req_zone $binary_remote_addr zone=login:10m rate=10r/m;
limit_req_status 429;
```

`server` ブロック内に:

```nginx
location = /MUserInfo/login {
    limit_req zone=login burst=5 nodelay;

    proxy_pass http://127.0.0.1:8091/MUserInfo/login;
    proxy_set_header X-Forwarded-For   $remote_addr;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Host              $host;
}
```

2026-08-31 の攻撃は約140リクエスト/分だったため、この設定で確実に落ちる。

## 3. 適用手順

```bash
# 1. 設定を編集後、必ず構文チェック（これを飛ばすと reload でサイトが落ちる）
sudo nginx -t

# 2. 無停止で反映
sudo systemctl reload nginx
```

`restart` ではなく `reload` を使う。`nginx -t` が通らない状態で reload しても
古い設定のまま動き続けるため、サイトは落ちない。

---

## 4. Phase 0 チェックリスト（コード変更では対処できないもの）

| # | 作業 | 対応する指摘 |
|---|------|------|
| 1 | GitHubリポジトリを **PRIVATE** に切り替える | C-1 |
| 2 | DBパスワードをローテーションする | C-1 |
| 3 | VPSのパケットフィルタで 22 / 80 / 443 以外を落とす | C-2 |
| 4 | 本番SSHをパスワード認証から鍵認証へ移行する | H-2 |

### 2 のDBパスワードローテーションについて

**compose の環境変数を変えても、既存DBのパスワードは変わらない。**
`MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` はDBの初回作成時にしか適用されないため、
実際の変更は次の3つを同時に行う必要がある。

```bash
# ① DB内のパスワードを変更
docker exec -it kamakura-shokusu_web_db mysql -u root -p
```
```sql
ALTER USER 'kamakuraadm1n'@'%' IDENTIFIED BY '<新しいパスワード>';
ALTER USER 'root'@'localhost' IDENTIFIED BY '<新しいrootパスワード>';
FLUSH PRIVILEGES;
```

```
② GitHub Secrets の DB_PASS / DB_ROOT_PASS を更新
③ 本番アプリのDB接続設定（config/app_local.php または config/.env）を更新
```

③ を忘れるとアプリからDBに接続できなくなる。②を忘れると次回デプロイのSQL適用が失敗する。

---

## 5. 反映後の確認

```bash
# 外部からDBとアプリ直叩きが閉じたか（手元の端末から実行）
nc -vz kamaho-shokusu.jp 3306      # 接続拒否になること
nc -vz kamaho-shokusu.jp 8091      # 接続拒否になること

# サイトが正常か
curl -sS -o /dev/null -w "%{http_code}\n" https://kamaho-shokusu.jp/   # 200

# セッションCookieに Secure / HttpOnly / SameSite が付いたか
curl -sI https://kamaho-shokusu.jp/MUserInfo/login | grep -i set-cookie
```

ログイン回数制限は、テスト用アカウントで誤ったパスワードを11回送ると11回目が 429 になる。
監査ログに `user_login_blocked` が記録されることもあわせて確認する。

**最後に必ず、正しいパスワードで通常どおりログインできることを回帰確認すること。**

---

## 6. ロールバック

| 症状 | 対処 |
|------|------|
| サイトが 502 / 接続不可 | `docker/docker-compose.yml` のポート指定を `127.0.0.1:8091:80` から `8091:80` に戻して再デプロイ |
| 正規利用者が 429 で締め出される | ホストnginxの `limit_req` の行をコメントアウトして `nginx -t` → `reload` |
| 全員が同一IP扱いになる | ホストnginxの `X-Forwarded-For` の設定を確認（第1章） |

---

## 関連

- Issue #667 / SHOKUSU-32
- WAF（ModSecurity + OWASP CRS）の導入は、上記のポート閉鎖が完了してから別途行う
