# shokusuu1 – 児童養護施設向け食数予約管理システム

## 📘 プロジェクトの概要と目的

**shokusuu1** は、児童養護施設における食事の提供数を事前に予約・管理するためのWebアプリケーションです。  
利用者（児童・職員）が、朝食・昼食・夕食・弁当の利用予定をカレンダー形式で登録し、  
施設側で日々の必要食数を集計・確認できます。

- **対象施設**：児童養護施設（入所児童が生活する福祉施設）
- **主な目的**：食材発注・調理計画・食事提供の無駄削減・会計処理の効率化

---

## ✨ 主な機能一覧

### 予約・日々の運用

- **食数予約（個人・一括・コピー）**  
  朝食・昼食・夕食・弁当を日別に登録。職員による部屋単位の一括登録・一括変更、週／月単位の予約コピー（プレビュー付き）に対応。
- **直前予約と通常予約のルール**  
  今日から14日後までを「直前予約」、15日後以降を「通常予約」として扱い、職員の予約は直前期間には管理者以外キャンセルできない等の制限を適用。
- **食事ごとの部屋指定**  
  カレンダーの予約ポップアップで、食事ごとに部屋を選べる。一括設定と、あとからの部屋変更にも対応。
- **カレンダー表示（業務用／子ども用）**  
  月間カレンダー（祝日判定つき）。子ども用画面は日付カードで朝・昼・夜・弁当を切り替える。
- **実食入力と承認**  
  実際に食べた食数の入力と、ブロック長・管理者による承認。
- **部屋異動予約**  
  所属部屋の将来の異動日を登録し、毎日 0:00（JST）に自動で反映。
- **食数集計・Excel 出力**  
  部屋別・食事種別の食数を集計して Excel で出力（予定表・実施表）。食事控除表の集計・出力にも対応。

### マスタ・ユーザー管理

- **部屋情報**：部屋の追加・編集・削除。
- **ユーザー管理**：登録・編集・詳細、退職処理と復元、一括取込、パスワード再設定。生年月日から年齢・年代を自動で選び、毎年4月1日に年齢を更新。本人は自分の性別・職員IDを変更できる。
- **権限**：児童、一般職員、ブロック長、管理者、システム管理者。役職「その他」でも予約を登録できる。
- **食数単価**：単価マスタ（年度は暦年の1〜12月で運用）。
- **お知らせ**：全体へのお知らせの登録。

### サポート・通知

- **お問い合わせ**：起票と管理者の返信。メールの返信を受け取る Resend Inbound に対応（[設定手順](docs/resend-inbound-email-setup.md)）。
- **通知**：未読・既読の管理。
- **AI アシスタント**：画面右下から質問できる。ログイン中のロールの権限に合わせて案内し、「予約をキャンセルしたい」のように直前予約か通常予約かがわからない質問は聞き返す。
- **統計 AI**：集計データをもとにした質問に答える。

### システム管理

- **システムレポート**：部屋別使用率、日別の子ども総数、ログイン情報（Excel 出力、集計除外に対応）。管理者はレポート閲覧権限なしでも表示できる。
- **監査ログ**：操作履歴の閲覧と CSV 出力。バックアップからの復元ワークフローあり。
- **機能使用頻度**・**部屋使用状況**：利用状況の可視化。
- **メンテナンス画面**：GitHub Actions から ON/OFF でき、開始時刻・終了予定・内容を表示（503）。デプロイ中も自動で表示。

システム全体の画面と機能一覧は [docs/system-overview](docs/system-overview/README.md) にあります。

---

## 🛠 技術スタック・使用ライブラリ

- **バックエンド**：CakePHP 5.3、PHP 8.3（クリーンアーキテクチャ。[CLAUDE.md](CLAUDE.md) と [コーディング規約](docs/coding-conventions.md) を参照）
- **データベース**：MySQL
- **インフラ**：Docker（本番はさくらの VPS、ステージングは OCI）。ステージングの構成は `terraform/staging`
- **CI/CD**：GitHub Actions（PHPUnit、ステージング・本番デプロイ、DB バックアップ、死活・リソース監視、証明書更新 ほか）
- **AI**：Groq（`openai/gpt-oss-120b`）。キーは環境変数 `GROQ_API_KEY`
- **フロントエンド**：
  - FullCalendar（カレンダーUI）
  - japaneseholiday（祝日判定）
  - Bootstrap（UIスタイル）

---

## 🌿 ブランチと開発の流れ

`feature/`・`fix/` は `main` から切り、PR のベースは `develop`。本番へは `develop → release → main` の順に昇格します（`develop` から直接 `main` へはマージしません）。コミットメッセージは Conventional Commits に従います。

```bash
vendor/bin/phpunit    # テスト（src_web/kamaho-shokusu で実行）
```

---

## 🚀 セットアップ手順（Docker）

1. **リポジトリをクローン**
   ```bash
   git clone https://github.com/kamaho-source/shokusuu1.git
   cd shokusuu1
   ```
2. **Docker で起動**（詳細は `docker/` 配下の compose ファイルを参照）
   ```bash
   docker compose -f docker/docker-compose.yml up -d
   ```
3. **環境変数**：`src_web/kamaho-shokusu/.env` を用意する。AI アシスタントを使う場合は `GROQ_API_KEY` を設定する（本番と共有しない別のキーを使うこと）。

## 🧱 DB変更の自動反映ルール（SQLベース）

- ステージングデプロイ時に `scripts/apply_sql_updates.sh` が `sql/updates/*.sql` を自動適用します。
- 適用済みSQLは `schema_sql_history` テーブルで管理し、同じファイルは再実行しません。
- DBの変更や新規追加を行う場合は、`sql/updates` に新しいSQLファイルを追加してください（既存の適用済みファイルは変更しない運用）。

### SQL追加手順（例）

1. 連番付きで新規SQLを追加
   ```bash
   mkdir -p sql/updates
   vi sql/updates/20260403_002_add_example_column.sql
   ```
2. SQLを記述（`ALTER TABLE ...` など）
3. `develop` へマージしてステージングデプロイを実行

---

## 🔄 Backlog ↔ GitHub Issue 双方向連携

BacklogとGitHub Issueを自動同期する仕組みを導入しています。

| 方向 | トリガー | 内容 |
|------|----------|------|
| Backlog → GitHub | 15分ごとのスケジュール | Backlog課題の作成・更新・完了をGitHub Issueに反映 |
| GitHub → Backlog | Issue イベント | GitHub Issueの作成・編集・クローズ・再オープンをBacklog課題に反映 |
| GitHub → Backlog | Issue Comment イベント | GitHub IssueへのコメントをBacklogコメントへ反映 |

### 初期設定（初回のみ必要）

以下の GitHub Secret を登録してください。

```bash
# BACKLOG_API_KEY のみ手動登録が必要（BacklogのAPIキーを取得して設定）
gh secret set BACKLOG_API_KEY --body "YOUR_BACKLOG_API_KEY" --repo kamaho-source/shokusuu1
```

詳細は [docs/backlog-github-sync.md](docs/backlog-github-sync.md) を参照してください。
