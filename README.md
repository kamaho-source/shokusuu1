# shokusuu1 – 児童養護施設向け食数予約管理システム

## 📘 プロジェクトの概要と目的

**shokusuu1** は、児童養護施設における食事の提供数を事前に予約・管理するためのWebアプリケーションです。  
利用者（児童・職員）が、朝食・昼食・夕食・弁当の利用予定をカレンダー形式で登録し、  
施設側で日々の必要食数を集計・確認できます。

- **対象施設**：児童養護施設（入所児童が生活する福祉施設）
- **主な目的**：食材発注・調理計画・食事提供の無駄削減・会計処理の効率化

---

## ✨ 主な機能一覧

- **✅ 食数予約登録（個人・一括）**  
  児童や職員が食事の予約を日別・週単位で登録可能。  
  職員による部屋単位のまとめ予約（集団予約）にも対応。

- **📅 カレンダー表示**  
  月間カレンダーで予約状況を視覚的に表示。祝日判定も自動対応。

- **📊 食数集計・Excel出力**  
  期間を指定し、部屋別・食事種別ごとの食数集計をExcel形式でエクスポート。

- **🔐 管理者専用機能**  
  ユーザー・部屋情報の管理、システム設定などの機能にアクセス可能。

---

## 🛠 技術スタック・使用ライブラリ

- **バックエンド**：CakePHP 5.x, PHP 8.3
- **データベース**：MySQL
- **インフラ**：Docker（本番はさくらのVPS上にデプロイ）
- **フロントエンド**：
  - FullCalendar（カレンダーUI）
  - japaneseholiday（祝日判定）
  - Bootstrap（UIスタイル）

---

## 🚀 セットアップ手順（Docker）

1. **リポジトリをクローン**
   ```bash
   git clone https://github.com/kamaho-source/shokusuu1.git
   cd shokusuu1
   ```

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

## 🔌 外部API連携（承認データ連携）

外部ソフト連携向けに、承認APIをAPIキー認証で利用できます。

### 必須環境変数

- `EXTERNAL_API_KEY`: 外部API用の共有キー
- `EXTERNAL_API_USER_ID`: 代理実行するユーザーID（職員または管理者）

### エンドポイント

- `GET /api/external/reservation-approvals/pending`
  - クエリ任意: `from=YYYY-MM-DD`, `to=YYYY-MM-DD`
- `POST /api/external/reservation-approvals/review`
  - JSON:
    - `i_id_user` (int)
    - `d_reservation_date` (YYYY-MM-DD)
    - `i_id_room` (int)
    - `i_reservation_type` (1..4)
    - `action` (`approve` or `reject`)
    - `reason` (optional)

### 認証ヘッダ

- `X-API-Key: <EXTERNAL_API_KEY>`
  または
- `Authorization: Bearer <EXTERNAL_API_KEY>`
