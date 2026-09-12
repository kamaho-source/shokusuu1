# ステージングでの国内アクセス制限検証

実施日: 2026-09-12（JST）。対象: `https://stg.kamaho-shokusu.jp`。

## 結果

GeoIP2 による国内アクセス制限をステージングの公開 Nginx に反映し、
日本からの通過と米国からの遮断を実際の通信で確認した。現在も有効。
本番サーバーおよび PR #671 は変更していない。

| 確認 | 結果 |
|---|---|
| 国内回線 → `/kamaho-shokusu/MUserInfo/login` | 200、Nginx ログで `country=JP upstream=200` |
| 米国 GitHub Actions → `/`・ログイン両表記 | 403、`country=US upstream=-`（アプリ未到達） |
| 米国回線で日本IPを `X-Forwarded-For` / `X-Real-IP` に設定 | 403、回避不可 |
| 国内回線で米国IPをヘッダーに設定 | 200、接続元IPを使用 |
| 米国 → `/healthz` | GET 200、POST 405 |
| 米国 → HTTP `/` | HTTPS へ 301 |
| 米国 → 存在しない HTTP ACME トークン | 404（国制限では遮断されない） |
| インターネット → 8091 | 接続不可、ホストの待ち受けも `127.0.0.1:8091` のみ |
| `nginx -t` | 成功 |
| Certbot 証明書更新ドライラン | 成功 |

海外からの実測ログ:
https://github.com/kamaho-source/shokusuu1/actions/runs/34669739960

別途、ステージング上のループバック専用 Nginx で PROXY protocol を使って
接続元を再現し、日本・米国・中国・判定不能IP・日米IPv6・ヘッダー偽装を検証した。
日本と判定不能IPは通過、米国・中国は403。ログインへの空POSTは6件まで
アプリのCSRFで403、その後429となり、GETは200のままだった。
テスト用リスナー（127.0.0.1:18080 / 18443）は終了済み。
公開 Nginx に PROXY protocol やテスト用IPの信頼設定は追加していない。
IPv6はこの再現テストでの確認であり、公開IPv6回線での実測ではない。
ログイン画面の表示までを検証し、実ユーザーの認証操作は行っていない。

## 配置内容

- OS: Ubuntu 22.04.5 LTS、Nginx 1.18.0。
- GeoIP2 モジュールは導入済みのものを利用。
- DB-IP Country Lite 2026-09 を `/usr/share/GeoIP/dbip-country-lite.mmdb` に配置。
- DB SHA-256: `d284ae2e7427fe33d83465e1506b2b21aae47eb8a9b099f8f4dac6a98c99f041`。
- `infra/nginx/stg.kamaho-shokusu.jp.conf` を `/etc/nginx/sites-available/staging` に配置。
- 設定 SHA-256: `e6553a46fb24f9874cc857c08e494eaa5039d280913f369de6b74d6105c5a1f7`。
- ステージングの Compose は Web の公開ポートだけ localhost に変更。
- アプリイメージは変更せず、既存のサーバー上のアプリ修正も保持。
- 国・HTTPステータス・upstream結果は `/var/log/nginx/staging-geoip-access.log` に記録。
  既存の Nginx logrotate（毎日、14世代）の対象。

元の `release` ブランチの案から、ステージングのドメイン・証明書を変更し、
ログインのパスに `/kamaho-shokusu/` を含めた。
`/healthz` は `ok` だけを返す Nginx の死活確認とした。アプリ・DBの正常性は保証しない。
元の案の `/healthz` はアプリ `/` へ転送して302を返すため、監視用途に適さなかった。

## 運用上の範囲

- 元の案と同じく、国が判定できないIPは許可する。厳密な「JP以外すべて拒否」ではない。
  DB自体が欠損・破損した場合の起動／再読み込み成功を保証する設定でもない。
- 国別DBの月次自動更新は未設定。今回配置したのは2026年9月版。
  更新時は別ファイルにダウンロード・検証してから同じファイルシステム上で
  atomic renameすること。使用中DBへの直接リダイレクトでの上書きは避ける。
  ステージングからの取得が停滞したため、今回はローカルで取得してSCPで転送した。
- 国判定はIPの登録地。日本のVPN等の経由は許可される。
- 検証ブランチ `fix/staging-nginx-jp` は未マージ。
  通常の自動デプロイは追跡ファイルを強制チェックアウトするため、
  次回実行で Compose の8091設定が元に戻る。継続運用前にこの差分を
  developへ反映する必要がある。ホストNginxの設定は通常デプロイの管理対象外。

## 反映・切り戻し

再反映用: `scripts/deploy-staging-geoip.sh`。
ステージングの `ubuntu` ユーザーとして、配置済みDBと設定ファイルを用意して実行する。

```bash
bash scripts/deploy-staging-geoip.sh infra/nginx/stg.kamaho-shokusu.jp.conf
```

構文検査、Webコンテナの再作成、アプリの200確認、Nginx reload、
`/healthz` の切り替え完了待機を行う。失敗時は設定を復元する。
初回の反映では reload 直後に旧ワーカーが404を返し、自動切り戻しが作動した。
完了待機を追加した再反映で成功し、切り戻し手順も実行確認できた。

今回の反映前バックアップ: `/var/backups/shokusuu-geoip-lm4zF5go`。
国内制限とポート変更を元に戻す場合はステージングで実行する。

```bash
cd /home/ubuntu/shokusuu1/docker
sudo cp -a /var/backups/shokusuu-geoip-lm4zF5go/staging.conf /etc/nginx/sites-available/staging
sudo cp -a /var/backups/shokusuu-geoip-lm4zF5go/docker-compose.staging.yml docker-compose.staging.yml
docker compose -f docker-compose.staging.yml --env-file .env.staging up -d --no-deps --no-build --pull never web
sudo nginx -t && sudo systemctl reload nginx
```

証明書更新の検証コマンド（実際の証明書は更新しない）:

```bash
sudo certbot renew --dry-run --cert-name stg.kamaho-shokusu.jp --no-random-sleep-on-renew
```
