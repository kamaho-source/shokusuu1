# 機能一覧（shokusuu1）

> 最終更新: 2026-07-20

---

## 共通

| 機能 | URL | 備考 |
|------|-----|------|
| ダッシュボード | `/` | ロール・プランに応じてカード表示を切り替え |
| 通知一覧 / 既読処理 | `/Notifications` | ベル通知、未読バッジ |
| お問い合わせ送信 | `/Contacts` | 利用者→運営への問い合わせ |
| ログイン / ログアウト | `/MUserInfo/login` `/MUserInfo/logout` | |

---

## 予約管理

| 機能 | URL | プラン制限 |
|------|-----|----------|
| 予約カレンダー一覧 | `/TReservationInfo` | なし |
| 日付別食数ビュー | `/TReservationInfo/view/{date}` | なし |
| 個別予約追加・編集・削除 | `/TReservationInfo/add`, `edit`, `delete` | なし |
| 直前編集（changeEdit） | `/TReservationInfo/changeEdit/{roomId}/{date}` | なし |
| 予約直接登録（カレンダークリック） | `/TReservationInfo/direct-register` | なし |
| 予約トグル（ON/OFF） | `/TReservationInfo/toggle` | なし |
| 重複チェック | `/TReservationInfo/checkDuplicateReservation` | なし |
| 個人予約取得（API） | `/TReservationInfo/getPersonalReservation` | なし |
| 週間一括予約（新規） | `/TReservationInfo/bulk-add-form` | **スタンダード以上** |
| 週間一括予約（変更） | `/TReservationInfo/bulk-change-edit-form` | **スタンダード以上** |
| 月間一括管理グリッド | `/TReservationInfo/meal-count-grid` | **スタンダード以上** |
| 予約コピー / プレビュー | `/TReservationInfo/copy`, `copyPreview` | なし |

---

## 実食管理

| 機能 | URL | 対象ロール |
|------|-----|-----------|
| 自分の実食入力 | `/TReservationInfo/my-actual-meal` | 全員 |
| 実食確認（代理入力） | `/TReservationInfo/actual-meal-management` | ブロック長・管理者 |
| 実食保存 / 承認申請 | `/TReservationInfo/actual-meal-save`, `actual-meal-request-approval` | ブロック長 |
| 食べる / 食べない（本日報告） | `/TReservationInfo/reportEat`, `reportNoMeal` | 全員 |

---

## 承認フロー

| 機能 | URL | 対象ロール |
|------|-----|-----------|
| ブロック長承認一覧 / 承認 / 差し戻し | `/Approval/blockLeaderIndex`, `blockLeaderApprove`, `blockLeaderReject` | ブロック長 |
| 管理者最終承認 / 差し戻し / 食数反映 | `/Approval/adminIndex`, `adminApprove`, `adminReject`, `adminReflect` | 管理者 |
| 承認履歴 | `/Approval/approval_log` | 管理者 |

---

## ユーザー・マスタ管理（管理者）

| 機能 | URL | 備考 |
|------|-----|------|
| ユーザー一覧・追加・編集・削除・復元 | `/MUserInfo` | |
| ユーザー詳細 | `/MUserInfo/view/{id}` | |
| パスワード変更（本人 / 管理者） | `/MUserInfo/generalPasswordReset`, `adminChangePassword` | |
| 管理者権限付与 / 剥奪 | `/MUserInfo/update-admin-status`, `update-system-admin-status` | |
| ユーザーレベル変更（ブロック長等） | `/MUserInfo/update-user-level` | |
| 部屋情報 CRUD | `/MRoomInfo` | |
| 食数単価マスタ | `/MMealPriceInfo` | |
| 食事控除表 Excel ダウンロード | `/MMealPriceInfo/exportMealSummary` | **ライト以上** |
| 部屋異動予約（事前登録 / キャンセル） | `/MRoomTransferSchedule` | |
| お知らせ管理（作成・編集） | `/MNotice` | |
| お問い合わせ一覧 / 詳細（管理者側） | `/Contacts/admin` | |
| 施設別設定 編集 / 変更履歴 | `/facility-settings/edit`, `/facility-settings/history` | 管理者 |

---

## AI 機能

| 機能 | URL | プラン制限 |
|------|-----|----------|
| お問い合わせ AI（FAB・チャット） | `/AiAssistant/askStream` | **スタンダード以上** |
| 統計 AI（管理者向けチャット） | `/StatsAi` | **プレミアムのみ** |

---

## システム管理者専用

| 機能 | URL | 備考 |
|------|-----|------|
| テナント一覧 / 追加 | `/admin/tenants` | |
| トライアル管理（一覧・状態変更・プラン変更） | `/admin/tenants/trials` | |
| テナント操作モード切替（enter / exit） | `/admin/tenants/enter`, `exit` | |
| テナント公開セルフ登録 | `/tenant/register` | |
| 監査ログ 検索 / CSV エクスポート | `/AuditLog` | |
| 機能使用頻度ダッシュボード | `/FeatureUsageSummary` | |
| 部屋使用率 / 低使用率ピックアップ | `/RoomUsage` | |

---

## プラン制限まとめ

| プラン | 入居者上限 | 週間一括予約 | 月間一括 | Excel 出力 | AI アシスタント | 統計 AI |
|--------|-----------|------------|---------|-----------|----------------|--------|
| スターター | 30名 | ✗ | ✗ | ✗ | ✗ | ✗ |
| ライト | 80名 | ✗ | ✗ | ✓ | ✗ | ✗ |
| スタンダード | 200名 | ✓ | ✓ | ✓ | ✓ | ✗ |
| プレミアム | 無制限 | ✓ | ✓ | ✓ | ✓ | ✓ |

---

## アーキテクチャ概要

- **マルチテナント**: `tenant_id` によるデータ分離、`TenantResolutionMiddleware` で自動解決
- **クリーンアーキテクチャ**: Domain / Application / Infrastructure / Presentation の4層
- **認証**: CakePHP Authentication プラグイン
- **認可**: Authorization プラグイン + 各コントローラー用 Policy クラス
- **監査ログ**: 全操作を `t_audit_log` に記録（`AuditLogService`）
- **施設別設定**: 予約ルール・食事設定・承認フロー等を施設単位で管理、変更履歴付き
