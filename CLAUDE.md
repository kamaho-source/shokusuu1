# CLAUDE.md — shokusuu1 プロジェクト設定

> Claude Code がプロジェクトを理解するための設定ファイル。

---

## 1. プロジェクト概要

| 項目 | 内容 |
|------|------|
| **アプリ名** | shokusuu1（食数管理システム） |
| **フレームワーク** | CakePHP |
| **言語** | PHP |
| **アーキテクチャ** | クリーンアーキテクチャ |

---

## 2. アーキテクチャ方針（最重要）

**依存方向は外側 → 内側の一方向のみ。内側の層が外側を知ってはならない。**

```
Frameworks & Drivers（CakePHP, DB, HTTP）
  └─ Interface Adapters（Controller, Repository実装）
       └─ Application（UseCase, DTO）
            └─ Domain（Entity, Repository Interface, Value Object）
```

- **Domain** は何にも依存しない
- **Application** は Domain にのみ依存する
- **Interface Adapters** は Application・Domain に依存する
- Repository の **Interface** は Domain に、**実装** は Infrastructure に置く

---

## 3. ディレクトリ構成

```
src/
├── Domain/
│   ├── Entity/            # 純粋な業務エンティティ（CakePHP に依存しない。例: MealReservation）
│   ├── ValueObject/
│   ├── Service/           # ドメインサービス（エンティティに属さない純粋な業務ルール。例: ReservationDateRule）
│   ├── Repository/        # インターフェースのみ
│   └── Exception/         # 業務の例外（DomainException の子。getStatusCode() で HTTP が決まる。Application 層も同じものを使う）
├── Application/
│   ├── UseCase/           # 書き込み。{UseCaseName}/{Input,Output,UseCase}.php
│   ├── Query/             # 読み取りのインターフェース（〜QueryInterface）と、画面に渡す形の Dto/
│   └── Port/              # Repository 以外の外部への口（キャッシュ・トランザクション・監査ログ・通知など）。実装は Infrastructure
├── Infrastructure/
│   ├── Repository/        # Domain/Repository/ の実装
│   ├── Query/             # Application/Query/ の実装（Cake〜Query）
│   ├── Notification/      # 通知（メール・Slack）の Port の実装
│   ├── Persistence/       # CakePHP の Table/ と ORM の Entity/（探索先は Application::TABLE_LOCATION。ORM の Entity は CakePHP に依存するので Domain には置かない）
│   └── ReservationUseCases.php # 予約の UseCase の組み立て口（services() から使う）
├── Service/               # 下の「Service 層の位置づけ」を参照。新しい業務ロジックは足さない
└── Controller/            # Presentation 層
```

---

## 4. 各層の実装ルール

**Domain 層** — フレームワーク・DB依存を一切持たない。バリデーションはエンティティ・値オブジェクト内で完結。「今日」などの現在時刻は引数で受け取り、Domain の中で取得しない。日付型は cakephp/chronos（`ChronosDate`）を使ってよい（CakePHP 本体ではない）。依存方向は `tests/TestCase/Architecture/LayerDependencyTest.php` で検査している。

**Application 層** — 1クラス1機能。UseCase の入口は `execute(XxxInput): XxxOutput` の1つだけにする。
- スカラーの引数は並べず Input DTO にまとめる。戻り値も bool・文字列・配列ではなく Output DTO にする。
- UseCase に読み取り用の public メソッド（対象を探す・選択肢を返すなど）を置かない。読み取りは Query に置く（例: 利用者の存在確認・選べる部屋は `UserQueryInterface`）。
- 入口の違う流れは UseCase を分ける（例: 画面からの返信 `AddContactReplyFromUserUseCase` とメールからの返信 `AddContactReplyFromEmailUseCase`）。
- 同じ判定を共有する UseCase（コピーとプレビューなど）は、同じ名前空間の小さなクラスに共通部分を置く（例: `CopyReservations/ReservationCopyPlanner` を `CopyReservationsUseCase` と `PreviewReservationCopyUseCase` が使う）。
- 「今日」は PSR-20 の `Psr\Clock\ClockInterface` をコンストラクタで受け取り（`new ChronosDate($this->clock->now())`）、`ChronosDate::today()` や `date()` で直接読まない（`LayerDependencyTest` で検査）。時計はコンテナから渡す（§5）。

**失敗の伝え方（UseCase）** — 2通りに分ける。
- 入力の誤り・見つからない・競合・権限は Domain 例外を投げる（`InvalidInputException`・`NotFoundException`・`ConflictException`・`UnauthorizedException` など。`DomainException::getStatusCode()` で HTTP の状態が決まり、Controller は `catch (DomainException $e)` で受ける）。
- 画面で選択肢として扱う結果（確認つき上書きの競合、部分成功、保存できなかった など）は Output の status で返す（例: `ChangeUserRoleOutput::CONFLICT`、`CreateUserOutput::SAVE_FAILED`）。
- UseCase から HTTP の数値（422 など）や配列の `ok` を返さない。HTTP への変換は Controller の仕事。

**Output の書き方** — 次の2つのどちらかにする。
- 結果が1通りのもの: readonly プロパティだけの final クラス（例: `CreateMealPriceOutput`）。
- 結果が何通りかあるもの: status の定数＋private コンストラクタ＋名前付きの static コンストラクタ（例: `CreateUserOutput::created()` / `rejected($message)` / `saveFailed()`）。
- JS が読む JSON の形を保つ必要があるものは、Output に `toArray()` を持たせて今と同じ配列を返す（例: `ToggleMealOutput`・`CopyReservationsOutput`）。

**Infrastructure 層** — Domain の Repository Interface を必ず実装。CakePHP ORMへの依存はこの層に閉じ込める。

**Presentation 層** — コントローラーは薄く保つ。書き込みは UseCase、読み取りは Query を呼び、結果をテンプレートに渡すだけ。ビジネスロジック・ORM直接記述は禁止。
- 1つの URL に複数の流れがある（例: 直前編集 `changeEdit` の画面・JSON・まとめて保存・個人の保存）ときは、アクションは「どの流れか」の振り分けだけにし、流れごとの private メソッドに分ける。使える人かの判定は Policy、行ごとの可否は Query、保存の決まりは UseCase・Domain に置く。
- Query・Repository・時計を `new` しない（§5）。
- 例外（Table を Controller から読んでよい場合）: ① Paginator の並べ替え・ページ送りを使う一覧（ユーザー・部屋・監査ログ）、② Policy の判定にエンティティの中身が要るとき、③ FormHelper にエンティティを渡すフォーム。いずれも読み込みだけで、書き込みは必ず UseCase を通す。
- 権限の判定のためだけに空のエンティティが要るときは、Table を使わず `new MUserInfo()` のように直接作る。
- リクエストの値は `queryString()` / `dataString()`（AppController）で文字列として受け取る（配列が来ても警告を出さない）。

**読み取り（Query）** — 画面・API の読み取りは Application の `〜QueryInterface` と `Dto/` に形を決め、Infrastructure の `Cake〜Query` で実装する。書き込みの UseCase とは分ける。キャッシュのキーと中身の形は、リリースをまたいでも読めるように変えない。「食べる」の判定は `ReservationDateRule::countedValue`、「部屋に所属」は `RoomMembershipRepository` / `RoomQueryInterface`（所属中かつ削除されていない部屋）に一本化している。

**Service 層の位置づけ（`src/Service`）** — 新しい業務ロジック・DB の読み書きは足さない（Domain・Application・Infrastructure に置く）。残してよいのは次のものだけ。
- DB を読まない画面の組み立て・小さな部品（`BulkReservationFormService`・`DashboardService`・`ApiResponseService`・`ReservationDatePolicy`。`RoomMemberOrderService` は Query が使う並び順の部品）
- 監査ログの書き込みの本体（`AuditLogService`。Application からは `AuditLogInterface` 経由で使う）
- 運用を止めている・対象外の機能（承認の差し戻し通知 `NotificationService`（Application からは `ApprovalNotifierInterface` 経由）・`MealSummaryExportService` の未承認プレビュー、AI 集計 `AiStatsContextService`・`FeatureUsageSummaryService`）。これらを動かし直すときに同じ層構成へ移す。

---

## 5. 依存性注入（DI）

依存はすべて `Application.php` の `services()` に登録し、コンテナから受け取る。受け取り方は次の使い分けにする。
- **全アクションで使う依存**（`RoomQueryInterface` など）は Controller の**コンストラクタ**で受け取り、`services()` で `addArguments()` を書く。
- **そのアクションだけで使う依存**（UseCase・そのアクション専用の Query）は**アクションの引数**で受け取る（CakePHP がコンテナから渡す。例: `bulkAddSubmit(BulkAddReservationsUseCase $bulkAdd)`）。
- 時計は `ClockInterface` を `services()` で1つだけ登録する（`addShared`、Asia/Tokyo）。他の場所で `new ClockFactory` を書かない。
- Controller・Policy・Service で `new Cake*Query` / `new Cake*Repository` / `new ClockFactory` をしない（`tests/TestCase/Architecture/LayerDependencyTest.php` が検査する）。`initialize()` で Query を作るのもやめる。
- Policy は依存をコンストラクタで受け取る（既定値の `?? new ...` を書かない）。`getAuthorizationService()` で Resolver（`MapResolver`・`PersistenceOrmResolver`）にコンテナを渡し、Policy をコンテナから作らせる。
- 予約系の UseCase は `ReservationUseCases`（1リクエストで Repository を共有する組み立て口）が作る。時計はこれにもコンテナから渡す。

```php
public function services(ContainerInterface $container): void
{
    // 時計は1つだけ
    $container->addShared(ClockInterface::class, static fn(): ClockInterface => new ClockFactory('Asia/Tokyo'));
    $clock = static fn(): ClockInterface => $container->get(ClockInterface::class);

    // 読み取り（Query）は Interface で登録する
    $container->add(RoomQueryInterface::class, CakeRoomQuery::class);
    $container->add(CalendarQueryInterface::class, static fn() => new CakeCalendarQuery($clock()));

    // UseCase（アクションの引数で受け取る）
    $container->add(CreateMealPriceUseCase::class, static fn() => new CreateMealPriceUseCase(new CakeMealPriceRepository(), new AuditLogAdapter()));

    // Controller のコンストラクタ注入（全アクションで使うものだけ）
    $container->add(MRoomInfoController::class)->addArguments([ServerRequest::class, RoomQueryInterface::class]);

    // Policy（Resolver がコンテナから作る）
    $container->add(MRoomInfoPolicy::class)->addArgument(RoomQueryInterface::class);
}
```

---

## 6. 命名規則

| 対象 | 規則 | 例 |
|------|------|----|
| クラス名 | UpperCamelCase | `RegisterMealCountUseCase` |
| インターフェース | `〜Interface` サフィックス | `MealCountRepositoryInterface` |
| DTO（入力/出力） | `〜Input` / `〜Output` | `RegisterMealCountInput` |
| UseCase | `〜UseCase` サフィックス | `GetMealCountUseCase` |
| メソッド・変数名 | lowerCamelCase | `findByDate()`, `$mealCount` |
| 定数 | UPPER_SNAKE_CASE | `MAX_MEAL_COUNT` |
| DBテーブル・カラム | スネークケース | `meal_counts`, `meal_date` |

---

## 7. コーディング規約

詳細は **[docs/coding-conventions.md](docs/coding-conventions.md)** を参照。

主要ルール：
- `final`・`readonly`・型宣言を積極的に使用する
- `else` 禁止（早期リターン・早期スロー）
- `mixed` 型禁止
- 例外は層ごとに定義し、`catch (\Exception $e)` で握りつぶさない
- PHPDoc（`@throws` 含む）をクラス・公開メソッドに必ず記述する

---

## 8. テスト方針

- テストディレクトリは `src/` の層構成をミラーリングする
- **Domain・Application 層のテストは必須**（外部依存なしで単体テスト可能）
- Infrastructure 層はリポジトリインターフェースをモックして UseCase をテストする
- **CI（`.github/workflows/phpunit.yml`）と本番デプロイ前（`deployment.yml`）は全テストを実行する。** 対象は `phpunit.xml.dist` の testsuite で決まるので、workflow でディレクトリを絞らない。CI 環境だけで落ちるテストは原因を直し、skip や対象外にして通さない
- **静的解析（PHPStan、level 8）も CI で実行する。** 既存のエラーは `phpstan-baseline.neon` で許容し、新しいコードではエラーを出さない。baseline に載っている箇所を直したら `--generate-baseline` で作り直して減らす（増やさない）

```bash
vendor/bin/phpunit                          # 全テスト
vendor/bin/phpunit tests/TestCase/Domain/   # 層ごと
vendor/bin/phpstan analyse                  # 静的解析（composer stan でも可）
vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon  # baseline の作り直し
```

---

## 9. ブランチ戦略

```
main     ← 本番リリース用（release からのみマージ）
release  ← リリース準備用ブランチ（develop → release → main の順に昇格）
develop  ← 統合ブランチ（PRのベース。feature/・fix/ の起点は main）
feature/ ← 機能開発
fix/     ← バグ修正
hotfix/  ← 緊急本番修正（main から作成し main・develop の両方へ反映）
```

**新規ブランチ（feature/・fix/ 等）は、ユーザーから起点の指定がない限り必ず `main` から切ること。PRのベースブランチは必ず `develop` を指定すること。**

```bash
git fetch origin main
git checkout -b feature/xxx origin/main
gh pr create --base develop --title "feat: 機能名" --body "..."
```

**本番リリースは `release` ブランチを介して行うこと（develop → release → main）。develop から直接 main へはマージしない**（`branch-check` が `release`・`release/*`・`hotfix/*` 以外の main 向けPRを弾く。`hotfix/*` は緊急修正用で、main へマージ後に release・develop へ cherry-pick する）。

```bash
# ① develop の内容を release へ昇格
gh pr create --base release --head develop --title "release: promote develop" --body "..."
# ② release を本番 main へ昇格
gh pr create --base main --head release --title "release: YYYY-MM-DD" --body "..."
# main へマージ後、必要に応じて main → develop を同期
```

コミットメッセージは Conventional Commits に従う（`feat:` / `fix:` / `refactor:` / `test:` / `docs:`）。

---

## 10. Claude Code への指示

- ビジネスロジックは必ず **Domain または Application 層** に置くこと
- コントローラーにビジネスロジックを書かないこと
- Repository はインターフェース経由でのみ参照すること
- 新規ファイルを作る際は **どの層に属するかを明示してから** 生成すること
- `var_dump` / `die` のコミット禁止・ハードコードされた認証情報禁止

---

## 11. よく使うコマンド

```bash
bin/cake server                            # 開発サーバー起動
bin/cake cache clear_all                   # キャッシュクリア
bin/cake routes                            # ルーティング確認
bin/cake migrations migrate                # マイグレーション実行
vendor/bin/phpunit                         # テスト実行
```


---

## 12. Backlog 設計書自動生成（Claude Code / Cursor / Codex 共通）

概要設計書・基本設計書・詳細設計書を課題から生成し、**使用プロンプト付き**で Backlog ドキュメントへ公開できる。

| 項目 | 場所 |
|------|------|
| 手順 | [`docs/backlog-design-docs.md`](docs/backlog-design-docs.md) |
| Skill | `.claude/skills/backlog-design-docs/`（正本: `skills/backlog-design-docs/`） |
| スラッシュコマンド | `/design-docs SHOKUSU-10`（`.claude/commands/design-docs.md`） |
| MCP（プロジェクト） | [`.mcp.json`](.mcp.json) |
| CLI（MCP不要） | `node tools/backlog-design-mcp/src/cli.js` |

環境変数: `BACKLOG_DOMAIN`, `BACKLOG_API_KEY`, （任意）`BACKLOG_PROJECT_KEY`

ユーザーが設計書生成を依頼したら Skill `backlog-design-docs` に従う。`/design-docs` でも起動できる。MCP が無い場合は CLI で公開する。
