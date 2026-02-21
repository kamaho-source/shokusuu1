# Authorization 方針

このプロジェクトでは CakePHP Authorization Plugin を使い、
「どのユーザーが、どの操作を実行できるか」を Policy で管理します。

## 現在の適用範囲

- 対象コントローラ: `MUserInfoController`
- 対象アクション:
  - `edit`
  - `delete`
  - `updateAdminStatus`
  - `restore`
- Policy: `src/Policy/MUserInfoPolicy.php`

- 対象コントローラ: `TReservationInfoController`
- 対象アクション:
  - `add`
  - `copy`
  - `changeEdit`
  - `bulkAddSubmit`
  - `bulkChangeEditSubmit`
  - `toggle`
- Policy: `src/Policy/TReservationInfoPolicy.php`

## 判定ルール（MUserInfo）

- `edit`: 管理者、または本人のみ許可
- `delete`: 管理者のみ許可
- `updateAdminStatus`: 管理者のみ許可
- `restore`: 管理者のみ許可

管理者判定は `i_admin === 1` を使用します。

## 判定ルール（TReservationInfo）

- `index/view/getPersonalReservation/reportNoMeal/toggle`: 認証済みユーザー
- `add/bulkAddForm/bulkChangeEditForm/changeEdit/bulkAddSubmit/bulkChangeEditSubmit/exportJson/exportJsonrank`: 職員 or 管理者
- `copy/getAllRoomsMealCounts`: 管理者のみ
- `roomDetails/getUsersByRoom/getUsersByRoomForBulk/getUsersByRoomForEdit/getReservationSnapshots/getRoomMealCounts`: 職員 or 管理者 かつ所属部屋のみ（管理者は全部屋可）
- `events/calendarEvents`: 職員 or 管理者
- `checkDuplicateReservation`: 職員 or 管理者 かつ所属部屋のみ（管理者は全部屋可）

## ロール定義

- `i_admin = 1`: 管理者（全体管理権限）
- `i_user_level = 0`: 職員
- `i_user_level = 1`: 児童
- 判定優先: 管理者判定を最優先し、管理者以外は職員/本人/所属部屋で判定

## 実装構成

- `src/Application.php`
  - `AuthorizationServiceProviderInterface` を実装
  - `AuthorizationMiddleware` を追加
- `src/Controller/AppController.php`
  - `Authorization.Authorization` コンポーネントをロード
- 各Controller
  - `\$this->Authorization->authorize(\$resource, 'action')` を呼び出して判定
  - JSON API は拒否時に `{"ok":false,"message":"権限がありません。"} + 403` を返却

## 追加実装の手順

1. `src/Policy/<Entity>Policy.php` に `can<Action>()` を追加する
2. Controller の該当アクションで対象 Entity を取得する
3. `\$this->Authorization->authorize(\$entity, '<action>')` を呼ぶ
4. 許可/拒否のテストを追加する

## テスト方針

- 管理者ユーザーで許可されること
- 非管理者ユーザーで拒否されること
- 期待する副作用（DB更新/未更新）が一致すること

`MUserInfoControllerTest` では `restore` の管理者許可・非管理者拒否を検証済みです。
`TReservationInfoControllerTest` では `copy` / `bulkAddSubmit` の許可・拒否を検証済みです。

## 対象一覧（2026-02-14時点）

- 適用済み:
  - `MUserInfoController`: `edit/delete/updateAdminStatus/restore`
  - `TReservationInfoController`: `index/view/add/copy/bulkAddForm/bulkChangeEditForm/changeEdit/bulkAddSubmit/bulkChangeEditSubmit/toggle/events/calendarEvents/checkDuplicateReservation/roomDetails/getUsersByRoom/getUsersByRoomForBulk/getUsersByRoomForEdit/getPersonalReservation/getReservationSnapshots/exportJson/exportJsonrank/reportNoMeal/getAllRoomsMealCounts/getRoomMealCounts`
- 未適用:
  - `TReservationInfoController`: 参照系・補助APIの一部（必要に応じて追加）

## 注意点

- 認可は「アクセス可能か」を判定する機能です。入力値の妥当性検証は別で実装します。
- 新規アクション追加時は、Policy 未定義のまま運用しないこと。

## Export API契約

- `exportJson`: `{"ok":true,"data":{"overall":[],"rooms":{}}}`
- `exportJsonrank`: `{"ok":true,"data":[...]}`
- バリデーションエラー: `{"ok":false,"message":"...","data":[]}` + 400
