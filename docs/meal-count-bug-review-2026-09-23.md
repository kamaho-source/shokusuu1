# 食数まわり バグ調査報告書（再現確認済み）

調査日：2026年9月23日
対象：`src_web/kamaho-shokusu`（ブランチ `feature/merge-pc-mobile-css` / HEAD `e64c801`）
目的：食数の計上・取消・集計にバグがないかの確認
対応範囲：**調査のみ。アプリケーションコードは一切変更していない。**

前回報告書 `docs/meal-management-bug-review-2026-09-12.md` の指摘に対する現状確認を含む。

---

## 0. 対応状況（2026-09-24 修正）

本報告書で指摘した 8 件はすべて修正済み。リグレッションテスト 8 件を `tests/TestCase/Service/MealCountRegressionTest.php` に追加し、修正前のコードでは失敗すること（7/8 が失敗）も確認した。

| ID | 対応 | 主な修正箇所 |
|----|------|--------------|
| A・B | `applyIndividualMealChanges()` / `applyGroupMealChanges()` の判定・更新基準を `ReservationDatePolicy` に統一。直前期間は `i_change_flag` のみを動かし、発注済みの `eat_flag` は保持する | `ReservationWriteService` |
| C | 昼食・弁当の排他を保存前に明示検証（`updateAll()` では `buildRules()` が走らないため） | `ReservationWriteService::assertLunchBentoExclusive()` |
| D | トグル時に別部屋の同一食事をチェック。集計は人単位で重複排除 | `ReservationWriteService::assertMealNotActiveInAnotherRoom()` / `ReservationReportService::getMealCounts()` |
| E | 有効判定を `ReservationDatePolicy::isActiveReservation()` の1箇所へ集約。コピー機能もフラグをそのまま複製せず、コピー先日付に合わせて組み立てる | `ReservationDatePolicy` ほか計6サービス／`ReservationCopyService` |
| F | 直前編集の日付検証 `fn($d) => true` を過去日拒否へ差し替え | `TReservationInfoController` |
| G | 食数集計キャッシュのキーに基準日を追加 | `ReservationReportService::mealCountsCacheKey()` |
| H | 個人タブの `meals[...]` を送信するよう修正。送信ハンドラのバインドを一覧取得の成否から切り離し、単体ページでも JS を読み込む。未チェックを 0 として送るため hidden を追加 | `ce-change-edit.js` / `change_edit.php` |

### 実機（ブラウザ）での確認

ローカル Docker 上の実画面で、直前編集モーダルの「個人」タブを操作して確認した。

| 操作 | 修正前 | 修正後（実測） |
|------|--------|----------------|
| 個人タブで保存 | 何も送信されず「変更された項目がありません。」 | `meals` 形式で送信され保存される |
| 朝食のチェックを外す | 反映されない | `i_change_flag` 1→0、`eat_flag` は 1 のまま（発注済みを保持） |
| もう一度チェックする | 「既に存在するためスキップ」で戻らない | `i_change_flag` 0→1 で復活 |

既存テストは 611 件すべて成功（リグレッション 8 件を含め計 619 件）。

---

## 1. 結論

**バグはある。8件中5件が食数を直接ずらし、うち4件は通常のUI操作だけで踏める。** 前回「コード上の指摘」に留めた内容を、今回は **PHPUnit で実際に再現して確認した**（7件すべて再現成功）。

既存テスト 611件はすべて成功している。つまり **これらの経路は既存テストで守られていない**。

| ID | 深刻度 | 症状 | 食数への影響 | 通常UIで踏めるか |
|----|--------|------|--------------|------------------|
| A | 高 | 予約を取り消せず「システムエラーが発生しました。」と出る | 取り消したはずの食数が残る | **踏める**（コピー機能が引き金） |
| B | 高 | 再予約しても復活せず「既に存在するためスキップ」と出る | 食べるのに食数が0のまま | **踏める**（コピー機能が引き金） |
| C | 高 | 昼食と弁当を同時に有効化できる | 1人が同じ日に2食計上される | 踏めない（UIが排他を強制） |
| D | 高 | 同一人物・同一日・同一食事が複数部屋で二重計上される | 1人が2食計上される | **踏める**（複数部屋所属時） |
| E | 中〜高 | 「有効な予約」の判定基準が集計系と画面系で2系統あり食い違う | 食数予定表と発注集計の数がずれる | **踏める**（コピー機能が引き金） |
| F | 中 | 直前編集（個人）が日付を検証せず過去日を書き換えられる | 確定済みの過去食数・請求額が変わる | 踏めない（後述Hのため到達不能） |
| H | 中 | 直前編集モーダルの「個人」タブが保存されない | 直前の個人予約変更が一切反映されない | **踏める**（機能が死んでいる） |
| G | 低 | 食数集計キャッシュのキーに版数・基準日が入っていない | 最大1時間、古い食数が表示され得る | ほぼ無害 |

全件 PHPUnit で再現確認済み（G のみコード確認）。到達性の検証内容は「6. 実運用で踏めるか」を参照。

前回報告の BUG-03（昼/弁当の相手側が承認済みでも変更される）は `a73db99` で**修正済み**を確認した。
前回 BUG-01 の「承認済みデータの保護」も `updateRowWithVersion()` への集約で**修正済み**。
一方、BUG-01 の日付チェック部分（本書 F）、BUG-02（本書 A・B）、BUG-04（本書 D）は**未修正のまま残っている**。

---

## 2. 検証方法

再現テスト 9件（単体7件＋コピー連鎖2件）を作成し、既存テストと同時に実行した。

| 項目 | 内容 |
|------|------|
| 実行環境 | ローカル PHP 8.5.2 / PHPUnit 10.5.63 |
| データベース | 調査用の一時 SQLite（本番・ステージングには一切接続していない） |
| 結果 | 618件成功 / 1,345アサーション（うち7件が本書の再現テスト） |

```sh
DATABASE_TEST_URL="sqlite://127.0.0.1/<絶対パス>/full.sqlite" php vendor/bin/phpunit
```

再現テストは調査用のため本リポジトリには含めていない。ファイルは以下に置いてある（修正着手時にそのまま `tests/TestCase/Service/` へ戻せる）。

```
<scratchpad>/MealCountBugReproTest.php   … 単体再現7件
<scratchpad>/CopyChainReproTest.php      … コピー連鎖2件
```

---

## 3. 各バグの詳細

### 背景：この2つのフラグの使い分けがすべての原因

`t_individual_reservation_info` は1つの予約を2列で持っている。

- `eat_flag` … 通常予約（今日+15日以降）で使う列
- `i_change_flag` … 直前編集（今日〜+14日）で使う列

`ReservationDatePolicy` はこのルールを明文化しているが、**個人予約の保存処理 `applyIndividualMealChanges()` がこのポリシーを参照していない**。A・B・E はすべてここに起因する。

---

### A：直前追加した予約を取り消せない（＋「システムエラー」表示）

**深刻度：高（過剰発注に直結）**

#### 根拠

`src/Service/ReservationWriteService.php:535`

```php
foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
    if ((int)$row->eat_flag !== 1) {
        continue;          // ← 直前追加の行は eat_flag=0 なので必ずここで飛ばされる
    }
    ...
}
```

直前トグルで追加された予約は `eat_flag=0 / i_change_flag=1`（食数としては1食計上される状態）である。
取消時の判定が `eat_flag` を見ているため、この行は**取消対象から外れる**。
さらに他に変更がなければ `$performed` が false のままとなり、`processIndividualReservation()` 末尾の

```php
throw new PersistenceException('システムエラーが発生しました。');
```

に到達する。利用者から見ると「エラーが出て、しかも予約が消えていない」状態になる。

#### 再現結果

```
[再現1] 取消後 eat_flag=0 i_change_flag=1 例外=PersistenceException:システムエラーが発生しました。
```

`i_change_flag` が 1 のままなので、その日の食数に1食残り続ける。

---

### B：直前キャンセルした食事を再予約しても復活しない

**深刻度：高（欠食に直結）**

#### 根拠

`src/Service/ReservationWriteService.php:558`

```php
if ($existing) {
    if ((int)$existing->eat_flag === 0) {   // ← eat_flag で再有効化を判定している
        ... eat_flag=1 / i_change_flag=1 に更新 ...
    } else {
        $duplicates[] = [...];              // ← 直前キャンセル済み行はこちらに落ちる
    }
    continue;
}
```

直前キャンセルされた予約は `eat_flag=1 / i_change_flag=0` である（`resolveEatFlag()` が元の `eat_flag` を保持する仕様のため）。
`eat_flag` が 1 なので「既に予約済み」と誤判定され、`i_change_flag` は 0 のまま放置される。

#### 再現結果

```
[再現2] 再予約後 eat_flag=1 i_change_flag=0 message=一部の予約は既に存在するため、スキップされました。
```

利用者には「既に予約されています」と表示されるが、**実際の食数は0のまま**。当日その人の食事が無い。

---

### C：昼食と弁当を同時に有効化できる

**深刻度：高（1人2食の過剰計上）**

#### 根拠

昼/弁当の排他は `TIndividualReservationInfoTable::buildRules()` の `uniqueLunchBentoEffective` ルールで担保されている。
ところが **CakePHP の application rules は `save()` 系でしか実行されない**。
`applyIndividualMealChanges()` は既存行の更新に `updateRowWithVersion()`（内部は `updateAll()`）を使うため、**ルールが一切走らない**。

- 新規行（`saveManyOrFail`）→ ルールが走る
- 既存行の再有効化（`updateAll`）→ **ルールが走らない**

したがって「昼食と弁当の行が両方すでに存在する（過去にキャンセル済み）」ユーザーが両方を選択すると、両方が有効化される。
`processIndividualReservation()` / `processGroupReservation()` 側にも昼/弁当の排他チェックは存在しない（`resolveSelectedRoomsPerMeal()` は部屋の重複しか見ていない）。

#### 再現結果

```
[再現3] 昼 eat=1 chg=1 / 弁当 eat=1 chg=1
```

同一人物・同一日で昼食1食＋弁当1食が同時に計上される。

---

### D：同一人物・同一日・同一食事が複数部屋で二重計上される

**深刻度：高（前回 BUG-04 が未修正）**

#### 根拠

- `processToggle()` は対象ユーザーが指定部屋に所属しているかは確認するが、**別部屋の同一食事予約は確認しない**
- 主キーが（ユーザー・日付・部屋・食事区分）のため、部屋違いの行は共存できる
- `buildRules()` の排他ルールは昼/弁当のみで、同一食事の複数部屋を防いでいない
- `ReservationReportService::getMealCounts()`（`src/Service/ReservationReportService.php:23`）は**行数を数えるだけで人単位の重複排除をしていない**

食数予定表の画面（`MealCountGridService`）は他部屋に予約がある場合ロック表示する実装を持っているが、**これは画面側だけの制御でAPIは素通りする**。

#### 再現結果

複数部屋に所属するユーザーで、部屋Aの朝食を予約済みの状態から部屋Bの朝食をトグルON：

```
[再現7] トグル後 getMealCounts=[{"meal_type":1,"count":2}]
```

1人しかいないのに朝食2食で集計される。

---

### E：「有効な予約」の判定基準が2系統あり、集計と画面で食い違う

**深刻度：中〜高（数字が合わない原因になる）**

コードベースに互換性のない2つの判定が併存している。

| 判定方式 | 実装箇所 | 内容 |
|---|---|---|
| **日付基準** | `ReservationReportService`（食数集計API・帳票・ランク別エクスポート）、`ReservationQueryService` | 今日+14日以内なら `i_change_flag`、15日以降なら `eat_flag` |
| **NULL基準** | `MealCountGridService:303`、`ReservationCalendarService`、`RoomUsageService`、`MealSummaryExportService`（職員請求集計）、各テンプレート | `i_change_flag` が NULL でなければ常に `i_change_flag`、NULL なら `eat_flag` |

`eat_flag` と `i_change_flag` が食い違う行が「今日+15日以降」の日付に存在すると、両者の結果が割れる。

**そういう行を作る経路が実在する：`ReservationCopyService`。**
`copyWeek()` / `copyMonth()` は元データのフラグをそのまま複製する（`ReservationCopyService.php:216, 234, 365, 382` — `(int)($r['i_change_flag'] ?? 1)`）。
直前キャンセル済みの行（`eat_flag=1 / i_change_flag=0`）を15日以降の月へコピーすると、そのまま食い違う行が生まれる。

#### 再現結果

```
[再現6] 集計API=1食 / 画面表示=予約なし
```

食数予定表には「予約なし」と出ているのに、発注用の集計には1食計上されている。逆パターン（画面に出ているのに集計されない）も同じ仕組みで起こる。

---

### F：直前編集（個人）が日付を一切検証しない

**深刻度：中（承認済みデータは保護されるが、未承認の過去日は書き換え可能）**

#### 根拠

`src/Controller/TReservationInfoController.php:761`

```php
$result = $this->writeService->processIndividualReservation(
    (string)$postDate,
    $earlyData,
    $allowedRooms,
    (int)$loginUid,
    (string)($loginUser->get('c_user_name') ?? ''),
    fn($d) => true            // ← 日付検証が無効化されている
);
```

同じサービスでも `add()` は `ReservationDatePolicy::validateReservationDate()` を渡しており、`processToggle()` は `isPastDate()` で過去日を拒否している。**直前編集（個人）だけ制約がない。** 過去日も、何年も先の日付も通る。

承認済み行は `updateRowWithVersion()` の保護で守られるようになったため、被害は**未承認の過去日データ**に限られる。ただし食数集計・職員請求集計は承認状態に関わらず過去日を集計対象にするため、確定済みの数字が動く。

#### 再現結果

```
[再現4] 過去日(2026-09-13) 取消後 eat_flag=0 i_change_flag=0
```

10日前の予約が取り消せてしまう。

---

### G：食数集計キャッシュのキーに版数・基準日が入っていない

**深刻度：低**

`ReservationReportService::mealCountsCacheKey()` は `meal_counts:{日付}:v2` のみで、同クラスの `getReportCacheVersion()`（他のエクスポート系キャッシュでは使用）を使っていない。
また `getMealCounts()` の結果は「今日から何日目か」で判定方式が変わるのに、キーに基準日が入っていない。
日付が「15日以降」から「14日以内」へ切り替わる日に、切替前の基準で計算された値が配信され得る。
`default` キャッシュの TTL は CakePHP 既定の 3600秒なので、**影響は最大1時間**。予約書き込みがあれば即座に無効化される。

---

### H：直前編集モーダルの「個人」タブが保存されない（今回新規発見）

**深刻度：中（機能が死んでいる）**

#### 根拠

`templates/TReservationInfo/change_edit.php` は「個人」「グループ」のタブを持ち、個人タブには `meals[食種][部屋ID]` のチェックボックスがある（199〜203行）。
ところが送信ハンドラ `webroot/js/ce-change-edit.js:328` は、

```js
var usersPayload = {};
tbody.querySelectorAll('tr[data-user-id]').forEach(function(tr){ ... });   // ← グループ側の #ce-tbody だけを読む
...
body: JSON.stringify({ users: usersPayload })                             // ← 常に users 形式で送る
```

と、**常にグループ形式のペイロードだけを送信する**。個人タブのチェックボックスは一切シリアライズされない。
`reservation_type` も送られないため、サーバー側は `$earlyData['reservation_type'] ?? '2'` によりグループ扱いになる。

結果、個人タブで変更して「変更を保存」を押すと **「変更された項目がありません。」** と出るだけで何も保存されない。

#### 副作用

- コントローラーの個人ブランチ（`TReservationInfoController.php:744-761`）は**この画面からは到達しない**。したがって F（過去日書き換え）の現時点の実害は低い。
- ただし利用者一覧の取得が0件または失敗した場合、送信ハンドラが `return` して**バインドされない**（`ce-change-edit.js:283` および エラー分岐）。この場合フォームはネイティブ送信となり、個人タブのチェックボックスごと個人ブランチへ到達する。つまり F は「普段は踏めないが、特定の状況では踏める」状態にある。

---

## 4. 実運用で踏めるか（到達性の検証）

「コード上は穴だがUIがガードしている」ものと「普通に操作していて踏む」ものを切り分けた。

### 意図的な仕様と確認できたもの（バグではない）

| 内容 | 根拠 |
|------|------|
| `eat_flag` と `i_change_flag` の2列運用 | `ReservationDatePolicy` に明文化された設計 |
| 直前編集で通常予約の15日ルールを外していること自体 | 直前編集の目的そのもの |
| 食数予定表が NULL 基準で判定していること | `MealCountGridService:299-302` に「過去日・未来日問わず直前編集を優先することで、実食確認なしでも予約数をグリッドに表示できる」とコメントあり |
| 職員の直前期間キャンセル禁止 | `ReservationWriteService:344` とUI側ガードの両方で実装 |
| 複数部屋所属 | `treservation_index.js:1313` に「複数部屋(N部屋)の合計数を表示中」のトースト。正式サポート |

### A・B・E は「コピー機能」が引き金になり、通常UIだけで踏める

単独では A・B は直前編集の個人ブランチ（H により到達不能）経由でしか踏めない。
しかし **`ReservationCopyService` がフラグをそのまま複製する**ため、直前編集で作られた「`eat_flag` と `i_change_flag` が食い違う行」が15日以降の日付へ運ばれる。
そこから先は **通常の予約画面（`add`）だけで A・B・E が同時に発生する**。

週コピー（今日+3日の週 → 4週間後）＋通常予約画面での操作を再現した結果：

```
[連鎖1] コピー直後(2026-10-25) eat=0 chg=1
[連鎖1] 取消後 eat=0 chg=1 / 集計=0食 / 予定表=予約あり / 例外=システムエラーが発生しました。
[連鎖2] 再予約後 eat=1 chg=0 / 集計=1食 / 予定表=予約なし / message=一部の予約は既に存在するため、スキップされました。
```

- **連鎖1**：食数予定表には「予約あり」と出ているのに集計は0食。取り消そうとすると「システムエラーが発生しました。」が出て、しかも予定表からは消えない。
- **連鎖2**：食数予定表には「予約なし」と出ているのに集計は1食（＝発注される）。予約しようとすると「既に存在するためスキップ」と言われて直せない。

**どちらも「画面の数字と発注される食数が違い、画面から直せない」状態**であり、実運用での影響が最も大きい。

### D は複数部屋所属時に踏める

食数予定表のグリッドは他部屋に予約がある場合ロック表示するが（`MealCountGridService` の `otherRoom`）、**これは画面側の制御にすぎない**。
カレンダーの日付クリックから呼ばれる `DirectRegisterMealsUseCase` → `processToggle()` には他部屋チェックがないため、サーバー側では素通りする。

### C・F は現状APIを直接叩かないと踏めない

- **C**：`webroot/js/add.js:133-143` と `webroot/js/reservation.js:74-115` が昼/弁当の排他をクライアント側で強制している。サーバー側のルール（`buildRules`）は `updateAll` 経路で走らないため、API直叩きなら通る。
- **F**：H により直前編集の個人ブランチへ到達できない。ただし H を修正すると同時に顕在化するため、**H と F はセットで直す必要がある**。

---
## 5. 推奨する対応順序

実運用で踏めるものを先に、根本原因の近いものをまとめて直す。

1. **A・B・E を1箇所で直す（最優先）。** `applyIndividualMealChanges()` に `ReservationDatePolicy::judgeColumn()` を適用し、行の判断基準を `eat_flag` 決め打ちから日付に応じた列へ変える。A・B はこの1箇所で同時に解消する。併せて有効フラグ判定を共通ヘルパーへ集約し、集計系と画面系のどちらを正とするか決める（E）。
2. **コピー機能のフラグ複製を見直す。** A・B・E の引き金。コピー先の日付が通常予約期間なら `eat_flag` と `i_change_flag` を揃えて書き込む。
3. **D を直す。** `processToggle()` に「同一ユーザー・同一日・同一食事の別部屋予約」チェックを入れる（拒否か部屋移動かは運用判断）。併せて `getMealCounts()` を人単位の重複排除に変更する。
4. **H と F をセットで直す。** `ce-change-edit.js` の送信ハンドラに個人タブの `meals[...]` を載せ、同時に `fn($d) => true` を直前編集用の日付検証（過去日拒否）に差し替える。Hだけ直すとFが顕在化する。
5. **C を直す。** 昼/弁当の排他を `updateAll` 経路でも効かせる。`buildRules` に頼らず保存前に明示チェックを入れる。
6. **G を直す。** キャッシュキーに `getReportCacheVersion()` と基準日を含める。

修正時は再現テスト9件をそのままリグレッションテストとして `tests/TestCase/Service/` に配置できる。

## 6. 調査の限界

- 本番・ステージングのDBには接続していない。**実際の被害件数・影響期間は不明。**
- 検証は SQLite 上で行った。MySQL 固有の制約・ロック・同時更新は未確認。
- UI側のガードは JavaScript とテンプレートのコードを読んで判定した。**ブラウザでの実操作による確認は行っていない。** 特に H（個人タブが保存されない）は実画面で1度触れば確定できるため、優先して確認することを勧める。
- 全画面・全組合せを網羅した監査ではない。今回は食数の計上・取消・集計経路に絞っている。

## 7. 成果物

本報告書のみ。アプリケーションコード・既存テストは変更していない。
