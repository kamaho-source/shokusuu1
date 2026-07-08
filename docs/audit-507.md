# 全面監査レポート（Issue #507）

実施日: 2026-07-08
対象: `src_web/kamaho-shokusu`（CakePHP 5 / Bootstrap 5）

## 総評

全体としてセキュリティ対策は良好。CSRF・FormProtection・セッション固定化対策・オープンリダイレクト対策・監査ログが実装済みで、生SQLは存在せず、テンプレート出力はほぼ全てエスケープされている。composer audit も指摘ゼロ。本監査で発見した問題は下記の通りで、修正済みのものと推奨事項に分けて記載する。

## 修正済みの問題

| # | 深刻度 | 問題 | 修正内容 |
|---|--------|------|----------|
| 1 | 中 | `templates/TReservationInfo/index.php` の `json_encode` に `JSON_HEX_TAG` 等がなく、`<script>` 内へ埋め込む JSON に `</script>` を含む文字列（氏名等）が入るとタグ脱出型XSSが成立しうる | 全 `json_encode` に `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` を付与 |
| 2 | 中 | セキュリティヘッダーが全体に未設定（nginx.conf に add_header なし。X-Frame-Options は1アクションのみ個別設定） | `Application::middleware()` に `SecurityHeadersMiddleware` を追加（`X-Frame-Options: sameorigin` / `X-Content-Type-Options: nosniff` / `Referrer-Policy: same-origin`） |
| 3 | 中 | `config/app.php`・`app_local.example.php` の debug デフォルトが `env('DEBUG', true)` で、本番で環境変数未設定だとスタックトレース露出 | デフォルトを `false` に変更。**注意: 開発環境では `DEBUG=true` の明示が必要**（サーバー側の `app_local.php` も要確認） |
| 4 | 低 | SP幅で管理者承認画面の集計サマリテーブル（6列）が横はみ出しする（`templates/Approval/admin_index.php`） | `.table-responsive` ラッパーを追加 |

## 推奨事項（今回未対応）

| # | 深刻度 | 内容 |
|---|--------|------|
| 1 | 中 | **パスワード最小文字数**: テーブルバリデーションに最小長なし。変更フローも `adminChangePassword` は6文字・`generalPasswordReset` は4文字と不整合。→ **施設側の運用への影響があるためスコープ外（ユーザー判断）**。将来的に方針が決まれば8文字程度への統一を推奨 |
| 2 | 中 | `docker/docker-compose.yml`（Git管理下）にMySQLの認証情報（`MYSQL_PASSWORD`・`MYSQL_ROOT_PASSWORD`）がハードコードされている。ローカル開発用だが、`.env` 参照への移行を推奨 |
| 3 | 低 | `AppController::getClientIp()` が `X-Forwarded-For` を無条件に信頼するため、監査ログのIPがクライアント側ヘッダーで偽装可能。信頼できるプロキシのIPのみ許可する構成を推奨 |
| 4 | 低 | 未ログインでダッシュボード（`/`)の `activeNotices`（お知らせ）と週次カレンダー枠が閲覧可能。仕様であれば問題ないが、お知らせに内部情報を書く運用なら要制限 |
| 5 | 低 | HSTS（`Strict-Transport-Security`）未設定。HTTPS 常時化済みであれば nginx か `HttpsEnforcerMiddleware` での付与を推奨 |
| 6 | 情報 | `config/app_local.php`（Git管理外）にDBパスワードが直書きされている。環境変数化を推奨 |

## 調査結果詳細

### 1. SP（スマートフォン）表示崩れ

- レイアウトは Bootstrap 5 ベース。`viewport` メタタグあり、ナビは `navbar-expand-lg` + トグラーで対応済み。
- テンプレート内の全 `<table>`（24ファイル）を確認。ほぼ全てが `.table-responsive` でラップ済み。
  - `Approval/admin_index.php` の集計サマリのみ未対応 → **修正済み**。
  - `TReservationInfo/meal_count_grid.php` は独自の `.mcg-grid-wrap { overflow-x: auto }` で対応済み。
  - `MUserInfo/import_form.php` のプレビューは `#previewWrap { overflow: auto }` で対応済み。
  - `TReservationInfo/room_details.php` の2テーブルは1列のみで崩れリスクなし。
- 固定幅指定はフォーム部品の `max-width` 中心で、SP幅での致命的な崩れ要因は見つからず。

### 2. セキュリティ脆弱性

- **XSS**: テンプレートの変数出力はほぼ `h()` 済み。Flash要素は `$params['escape']` を尊重してエスケープ。唯一 `js_config.php` へ渡す JSON にタグ脱出の余地があった → **修正済み**。
- **SQLインジェクション**: 生SQL・文字列結合クエリなし。全て ORM 経由。
- **CSRF**: `CsrfProtectionMiddleware`（httponly）+ `FormProtection` 適用済み。`unlockedActions` はJSON APIエンドポイント等に限定されており、いずれもCSRFミドルウェアの保護下にある。
- **依存パッケージ**: `composer audit` 指摘なし（CakePHP 5系、Authentication/Authorization 更新済み）。
- **機密情報**: Git管理下のファイルにハードコードされた認証情報なし（`app_local.php`・`.env` はGit管理外、`salt` は環境変数参照）。AI APIキーも `env('OPENROUTER_API_KEY')` 経由。
- **セキュリティヘッダー**: 全体設定なし → **SecurityHeadersMiddleware で修正済み**（HSTSは推奨事項）。
- **その他確認済みの対策**: オープンリダイレクト対策（`AppController::isSafeRedirect()`、バックスラッシュバイパスも考慮）、ログイン時のセッションID再生成、無効化アカウントのログイン拒否、ログインの成功・失敗の監査ログ記録。

### 3. 認証・認可

- **認証**: `AuthenticationMiddleware` が全リクエストに適用。未認証許可は `login`（AppController共通）と `Pages::display / dashboard`（ログイン促進画面のため意図的）のみで、適用漏れなし。
- **認可**: `AuthorizationMiddleware` を `requireAuthorizationCheck: true` で適用しているため、認可チェックを忘れたアクションは例外になる構造。ポリシークラスは14個あり、コントローラーマップ＋ORMリゾルバで解決。
- **skipAuthorization の使用箇所**（8カ所）を全数確認。いずれも「全認証ユーザー共通機能」「login/logout」「アクション内で手動ロールチェック実施」のいずれかで、正当な理由付きだった。
- **IDOR**: `TReservationInfoPolicy::canToggle()` が対象ユーザーID・部屋アクセス権・ロールを明示的に検証。ブロック長の承認ログ閲覧も自分の担当部屋に限定されており、水平権限昇格の欠陥は発見されず。
- **セッション管理**: ログイン成功時 `Session::renew()`、ロール変更の即時反映（`identify: true`）、ログアウトの監査ログあり。

### 4. バリデーション

- 全13のTableクラスに `validationDefault` が定義済み。主要マスタ（MUserInfo・TContacts・TNotification 等）は必須・一意・範囲チェックあり。
- 弱い箇所: `TReservationInfoTable`・`MRoomInfoTable`・`MMealPriceInfoTable` はほぼ全フィールドが `allowEmptyString` で、範囲チェック（例: 食数・価格の非負制約）がない。実際の入力経路はサービス層で制御されているため即時のリスクは低いが、多層防御としては追加を推奨。
- パスワード最小長 → 推奨事項#1（スコープ外）。
- **アーキテクチャ上の注記**: CLAUDE.md はドメイン層（Entity/ValueObject）でのバリデーション完結を掲げるが、現状の Domain 層は `UserRole` と例外クラスのみで、バリデーションは CakePHP の Table 層に集中している。クリーンアーキテクチャ移行を進める場合は値オブジェクト化が必要（別Issue推奨）。

## E2Eテスト結果（2026-07-08 / Playwright + Chromium）

ローカル環境（`http://localhost:8091`）に対して実行。**6 passed / 1 skipped（設計上の自動スキップ）/ 0 failed**。

- `tests/e2e/audit.spec.mjs`（本監査で追加）
  - セキュリティヘッダーが全レスポンスに付与される ✅
  - 予約カレンダー画面がJSエラーなく表示される（JSON_HEXフラグ変更後の動作確認）✅
  - SP幅375pxで承認画面・ログイン・ダッシュボードが横はみ出ししない ✅
- `tests/e2e/reservation.spec.mjs`（既存。ログインヘルパーが旧URL `/users/login`・旧フィールド名を参照していたため現行実装に合わせて修正）
  - 集団予約: 部屋選択で利用者一覧が表示される ✅
  - 集団予約: 予約タイプ切替で個人/集団セクションが切り替わる ✅
  - quickDayModal: 予約追加ボタン非表示時は自動スキップする設計のため skipped

実行方法:

```bash
npm install && npx playwright install chromium
E2E_BASE_URL=http://localhost:8091 npx playwright test
```

前提: E2E用ユーザー `e2e_admin`（管理者・部屋1所属）がローカルDBに必要。

視覚版レポート: [docs/audit-507.html](audit-507.html)
