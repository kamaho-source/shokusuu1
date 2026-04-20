# CLAUDE.md — shokusuu1 プロジェクト設定

> このファイルは Claude Code がプロジェクトを理解するための設定ファイルです。
> 必ずプロジェクトルートに配置し、チーム全員が最新状態を維持してください。

---

## 1. プロジェクト概要

| 項目 | 内容 |
|------|------|
| **アプリ名** | shokusuu1（食数管理システム） |
| **フレームワーク** | CakePHP |
| **言語** | PHP |
| **アーキテクチャ** | クリーンアーキテクチャ |
| **チーム規模** | 2〜5人 |

---

## 2. アーキテクチャ方針（最重要）

### クリーンアーキテクチャの層構造

本プロジェクトは以下の **4層** で構成される。  
**依存関係は必ず外側 → 内側の一方向のみ。** 内側の層が外側を知ってはならない。

```
┌─────────────────────────────────────┐
│  Frameworks & Drivers（外側）        │  CakePHP, DB, HTTP
│  ┌───────────────────────────────┐  │
│  │  Interface Adapters           │  │  Controller, Presenter, Repository実装
│  │  ┌─────────────────────────┐ │  │
│  │  │  Application            │ │  │  UseCase, DTO
│  │  │  ┌───────────────────┐ │ │  │
│  │  │  │  Domain（内側）   │ │ │  │  Entity, Repository Interface, Value Object
│  │  │  └───────────────────┘ │ │  │
│  │  └─────────────────────────┘ │  │
│  └───────────────────────────────┘  │
└─────────────────────────────────────┘
```

### 依存関係のルール

- **Domain** は何にも依存しない（フレームワーク・DBを知らない）
- **Application** は Domain にのみ依存する
- **Interface Adapters** は Application・Domain に依存する
- **Frameworks** は全層を知るが、内側から参照されない
- Repository の **Interface** は Domain に、**実装** は Infrastructure に置く

---

## 3. ディレクトリ構成

```
src/
├── Domain/                        # ドメイン層（コアビジネスロジック）
│   ├── Entity/                    # ドメインエンティティ
│   │   └── MealCount.php
│   ├── ValueObject/               # 値オブジェクト
│   │   └── MealDate.php
│   ├── Repository/                # リポジトリインターフェース（抽象）
│   │   └── MealCountRepositoryInterface.php
│   └── Exception/                 # ドメイン例外
│       └── InvalidMealCountException.php
│
├── Application/                   # アプリケーション層（ユースケース）
│   ├── UseCase/
│   │   ├── RegisterMealCount/
│   │   │   ├── RegisterMealCountUseCase.php
│   │   │   ├── RegisterMealCountInput.php   # DTO（入力）
│   │   │   └── RegisterMealCountOutput.php  # DTO（出力）
│   │   └── GetMealCount/
│   │       ├── GetMealCountUseCase.php
│   │       ├── GetMealCountInput.php
│   │       └── GetMealCountOutput.php
│   └── Exception/                 # アプリケーション例外
│
├── Infrastructure/                # インフラ層（外部依存の実装）
│   ├── Repository/                # リポジトリ実装（CakePHP ORM使用）
│   │   └── CakeMealCountRepository.php
│   └── Persistence/               # CakePHP Table クラス
│       └── MealCountsTable.php
│
├── Presentation/                  # プレゼンテーション層
│   └── Controller/                # CakePHP コントローラー
│       └── MealCountsController.php
│
templates/                         # CakePHP テンプレート
config/                            # 設定ファイル
tests/
│   └── TestCase/
│       ├── Domain/
│       ├── Application/
│       ├── Infrastructure/
│       └── Presentation/
```

---

## 4. 各層の実装ルール

### Domain 層

- フレームワーク・DB・外部ライブラリへの依存を**一切持たない**
- ビジネスルールをメソッドとして持つ
- バリデーションはエンティティ・値オブジェクト内で完結させる

```php
// ✅ Good: Domain/Entity/MealCount.php
final class MealCount
{
    public function __construct(
        private readonly MealCountId $id,
        private readonly MealDate $date,
        private readonly int $count,
    ) {
        if ($count < 0) {
            throw new InvalidMealCountException('食数は0以上である必要があります');
        }
    }

    public function getCount(): int
    {
        return $this->count;
    }
}

// ❌ Bad: ドメインエンティティがCakePHPのEntityを継承する
class MealCount extends \Cake\ORM\Entity  // 禁止
```

```php
// ✅ Good: Domain/Repository/MealCountRepositoryInterface.php
interface MealCountRepositoryInterface
{
    public function findByDate(MealDate $date): ?MealCount;
    public function save(MealCount $mealCount): void;
}
```

### Application 層（UseCase）

- ユースケースは **1クラス1機能** とし、`execute()` メソッドを持つ
- Input / Output は専用 DTO クラスとして定義する
- DB・HTTPなど外部の詳細を知らない

```php
// ✅ Good: Application/UseCase/RegisterMealCount/RegisterMealCountUseCase.php
final class RegisterMealCountUseCase
{
    public function __construct(
        private readonly MealCountRepositoryInterface $repository,
    ) {}

    public function execute(RegisterMealCountInput $input): RegisterMealCountOutput
    {
        $mealCount = new MealCount(
            id: MealCountId::generate(),
            date: new MealDate($input->date),
            count: $input->count,
        );

        $this->repository->save($mealCount);

        return new RegisterMealCountOutput(id: $mealCount->getId()->value());
    }
}
```

### Infrastructure 層（Repository実装）

- `Domain/Repository/` のインターフェースを必ず実装する
- CakePHP ORM への依存はこの層に閉じ込める
- ドメインエンティティ ↔ CakePHP Entity の変換責務を持つ

```php
// ✅ Good: Infrastructure/Repository/CakeMealCountRepository.php
final class CakeMealCountRepository implements MealCountRepositoryInterface
{
    public function __construct(
        private readonly MealCountsTable $table,
    ) {}

    public function findByDate(MealDate $date): ?MealCount
    {
        $record = $this->table->find()
            ->where(['meal_date' => $date->value()])
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->toDomain($record);
    }

    private function toDomain(\Cake\ORM\Entity $record): MealCount
    {
        return new MealCount(
            id: new MealCountId($record->id),
            date: new MealDate($record->meal_date),
            count: $record->count,
        );
    }
}
```

### Presentation 層（Controller）

- コントローラーは薄く保つ（ビジネスロジックを書かない）
- UseCase を呼び出し、結果をテンプレートに渡すだけ
- バリデーションはリクエスト受け取り後すぐに行い、UseCase には正常値のみ渡す

```php
// ✅ Good: Presentation/Controller/MealCountsController.php
final class MealCountsController extends AppController
{
    public function __construct(
        private readonly RegisterMealCountUseCase $registerUseCase,
    ) {}

    public function add(): void
    {
        if (!$this->request->is('post')) {
            return;
        }

        $input = new RegisterMealCountInput(
            date: $this->request->getData('meal_date'),
            count: (int)$this->request->getData('count'),
        );

        $output = $this->registerUseCase->execute($input);
        $this->set('result', $output);
    }
}

// ❌ Bad: コントローラーにビジネスロジック・ORM直接記述
public function add(): void
{
    $count = $this->request->getData('count');
    if ($count < 0) { ... }           // ドメインロジックをここに書かない
    $this->MealCounts->save(...);     // ORMを直接叩かない
}
```

---

## 5. 依存性注入（DI）

- コンストラクタインジェクションを使用する
- CakePHP のサービスコンテナ（`Application.php` の `services()` メソッド）でバインドする

```php
// src/Application.php
public function services(ContainerInterface $container): void
{
    // Repository: インターフェース → 具象クラスをバインド
    $container->add(MealCountRepositoryInterface::class, CakeMealCountRepository::class);

    // UseCase はコンテナが依存を自動解決
    $container->add(RegisterMealCountUseCase::class);
}
```

---

## 6. 命名規則

| 対象 | 規則 | 例 |
|------|------|----|
| **クラス名** | UpperCamelCase | `RegisterMealCountUseCase` |
| **インターフェース** | `〜Interface` サフィックス | `MealCountRepositoryInterface` |
| **値オブジェクト** | 概念名そのまま | `MealDate`, `MealCountId` |
| **DTO（入力）** | `〜Input` サフィックス | `RegisterMealCountInput` |
| **DTO（出力）** | `〜Output` サフィックス | `RegisterMealCountOutput` |
| **UseCase** | `〜UseCase` サフィックス | `GetMealCountUseCase` |
| **メソッド名** | lowerCamelCase | `findByDate()` |
| **変数名** | lowerCamelCase | `$mealCount` |
| **定数** | UPPER_SNAKE_CASE | `MAX_MEAL_COUNT` |
| **DBテーブル名** | スネークケース・複数形 | `meal_counts` |
| **DBカラム名** | スネークケース | `meal_date` |

---

## 7. コーディング規約

### 7-1. 基本フォーマット

| 項目 | ルール |
|------|--------|
| インデント | スペース **4つ**（タブ禁止） |
| 1行の最大文字数 | **120文字** |
| 文字コード | UTF-8（BOMなし） |
| 改行コード | LF |
| PHP開始タグ | `<?php` のみ（`?>` は書かない） |
| 空白行 | メソッド間に1行、論理ブロック間に1行 |

### 7-2. クラス設計

```php
// ✅ Good: finalを基本とする（意図的な継承以外は全てfinal）
final class RegisterMealCountUseCase
{
    // コンストラクタプロモーションを使用
    public function __construct(
        private readonly MealCountRepositoryInterface $repository,
    ) {}
}

// ❌ Bad: 理由なくfinalを省略
class RegisterMealCountUseCase
{
    private MealCountRepositoryInterface $repository;

    public function __construct(MealCountRepositoryInterface $repository)
    {
        $this->repository = $repository;  // プロモーション未使用
    }
}
```

**クラス設計ルール**

- 意図的な継承以外はすべて `final` を付ける
- コンストラクタインジェクション（プロモーション構文）を使う
- 1クラス1責務を徹底する（SRP）
- クラスの行数が150行を超えたら分割を検討する

### 7-3. 型宣言

```php
// ✅ Good: 引数・戻り値・プロパティすべてに型を付ける
final class MealCountsController extends AppController
{
    public function index(): void { ... }
    public function findByDate(MealDate $date): ?MealCount { ... }
    private function buildInput(array $data): RegisterMealCountInput { ... }
}

// ❌ Bad: 型なし・mixed 乱用
public function index() { ... }
public function findByDate($date) { ... }
```

- `mixed` は使用禁止（やむを得ない場合はコメントで理由を記載）
- `null` を返す可能性がある場合は `?Type` または `Type|null` を明示する
- PHP 8.1 以降の `never` 型（例外のみをスローするメソッド）も活用する

### 7-4. 値オブジェクト・DTO

```php
// ✅ Good: 値オブジェクトはreadonly + バリデーション内包
final class MealDate
{
    public function __construct(
        private readonly string $value,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidMealDateException("日付フォーマットが不正です: {$value}");
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

// ✅ Good: DTOはreadonly properties（PHP 8.2以降はreadonly class）
final class RegisterMealCountInput
{
    public function __construct(
        public readonly string $date,
        public readonly int $count,
    ) {}
}
```

### 7-5. 制御構文・早期リターン

```php
// ✅ Good: 早期リターンでネストを浅く保つ
public function execute(RegisterMealCountInput $input): RegisterMealCountOutput
{
    if ($input->count < 0) {
        throw new InvalidMealCountException('食数は0以上を指定してください');
    }

    $existing = $this->repository->findByDate(new MealDate($input->date));
    if ($existing !== null) {
        throw new DuplicateMealCountException('同日の食数はすでに登録されています');
    }

    // メイン処理
    $mealCount = new MealCount(...);
    $this->repository->save($mealCount);

    return new RegisterMealCountOutput(id: $mealCount->getId()->value());
}

// ❌ Bad: else / ネストが深い
public function execute(RegisterMealCountInput $input): RegisterMealCountOutput
{
    if ($input->count >= 0) {
        $existing = $this->repository->findByDate(new MealDate($input->date));
        if ($existing === null) {
            // メイン処理（深いネスト）
        } else {
            throw new DuplicateMealCountException(...);
        }
    } else {
        throw new InvalidMealCountException(...);
    }
}
```

- `else` は原則禁止（早期リターン・早期スローで代替）
- ネストは **最大3段まで**（それ以上はメソッド分割）
- 三項演算子は1行に収まる単純な条件のみ使用可

### 7-6. 例外処理

```php
// ✅ Good: 例外は層ごとに定義・使い分ける
// Domain層の例外
namespace App\Domain\Exception;
final class InvalidMealCountException extends \DomainException {}

// Application層の例外
namespace App\Application\Exception;
final class DuplicateMealCountException extends \RuntimeException {}

// ✅ Good: Controllerでは例外を受け取り、HTTPレスポンスに変換する
public function add(): void
{
    try {
        $output = $this->registerUseCase->execute($input);
    } catch (InvalidMealCountException $e) {
        $this->response = $this->response->withStatus(400);
        $this->set('error', $e->getMessage());
        return;
    } catch (DuplicateMealCountException $e) {
        $this->response = $this->response->withStatus(409);
        $this->set('error', $e->getMessage());
        return;
    }
}

// ❌ Bad: 例外を握りつぶす
try {
    $this->repository->save($mealCount);
} catch (\Exception $e) {
    // 何もしない（禁止）
}

// ❌ Bad: 基底Exceptionをそのまま使う
throw new \Exception('エラーが発生しました');
```

- 例外クラスは必ずドメイン・アプリケーション固有のものを定義する
- `catch (\Exception $e)` で握りつぶすのは禁止
- エラーメッセージは日本語で具体的に書く

### 7-7. アクセス修飾子

```php
// ✅ Good: 必要最小限の可視性にする
final class MealCount
{
    public function __construct(
        private readonly MealCountId $id,   // 外部から直接変更不可
        private readonly MealDate $date,
        private readonly int $count,
    ) {}

    public function getId(): MealCountId { return $this->id; }    // 公開
    public function getCount(): int { return $this->count; }

    private function validate(): void { ... }  // 内部のみ
}

// ❌ Bad: 何でもpublic
public MealCountId $id;
public MealDate $date;
public int $count;
```

- プロパティはすべて `private` または `private readonly`（`public` プロパティ禁止）
- DTOの公開プロパティは `public readonly` のみ許可
- `protected` は継承が必要な場合のみ

### 7-8. コメント・PHPDoc

```php
// ✅ Good: クラスの責務をPHPDocで明記
/**
 * 食数を登録するユースケース
 *
 * 同日に食数が既に登録されている場合は例外をスローする。
 */
final class RegisterMealCountUseCase
{
    /**
     * @throws DuplicateMealCountException 同日の食数が既に存在する場合
     * @throws InvalidMealCountException 食数が不正な値の場合
     */
    public function execute(RegisterMealCountInput $input): RegisterMealCountOutput
    {
        ...
    }
}

// ✅ Good: 「なぜ」を説明するコメント
// CakePHP の ORM は null を返すため、ドメインエンティティに変換する前にチェックする
if ($record === null) {
    return null;
}

// ❌ Bad: 「何をしているか」だけのコメント（コードを読めばわかる）
// $countを取得する
$count = $mealCount->getCount();
```

- PHPDoc はクラス・公開メソッドに必ず記述する
- `@throws` は必ず記載する
- インラインコメントは「なぜそう書くか」の理由を説明する

### 7-9. 禁止事項

| 禁止 | 代替 |
|------|------|
| `var_dump` / `print_r` / `die` | ログ・例外を使う |
| `@` エラー抑制演算子 | 例外処理で対応 |
| `global` 変数 | DI・コンテナを使う |
| 生SQL（`$pdo->query(...)` 直接） | CakePHP ORM 経由 |
| `mixed` 型 | 具体的な型を宣言 |
| マジックナンバー | 定数・値オブジェクトに切り出す |
| `else` 句（原則） | 早期リターン・早期スロー |

---

## 8. テスト方針

- テストディレクトリは `src/` の層構成をミラーリングする
- **Domain・Application 層のテストは必須**（外部依存なしで単体テスト可能）
- Infrastructure 層はリポジトリインターフェースをモックして UseCase をテストする

```bash
# 全テスト実行
vendor/bin/phpunit

# 層ごとに実行
vendor/bin/phpunit tests/TestCase/Domain/
vendor/bin/phpunit tests/TestCase/Application/
vendor/bin/phpunit tests/TestCase/Infrastructure/
```

```php
// ✅ Good: UseCaseのテスト（Repositoryをモック）
class RegisterMealCountUseCaseTest extends TestCase
{
    public function testExecute(): void
    {
        $mockRepository = $this->createMock(MealCountRepositoryInterface::class);
        $mockRepository->expects($this->once())->method('save');

        $useCase = new RegisterMealCountUseCase($mockRepository);
        $output = $useCase->execute(new RegisterMealCountInput(
            date: '2025-04-20',
            count: 30,
        ));

        $this->assertNotEmpty($output->id);
    }
}
```

---

## 9. ブランチ戦略

### ブランチ構成

```
main        ← 本番リリース用（直接push禁止）
develop     ← 統合ブランチ（PRのベース）
feature/*   ← 機能開発
fix/*       ← バグ修正
hotfix/*    ← 緊急本番修正
```

### ブランチ命名規則

```bash
feature/add-register-meal-count-usecase
fix/correct-meal-date-validation
hotfix/fix-login-error
```

### Pull Request ルール

**PRのベースブランチは必ず `develop` を指定すること。`main` への直接PRは禁止。**

```bash
gh pr create --base develop --title "feat: 機能名" --body "変更内容の説明"
```

**PRテンプレート**

```
## 概要
<!-- 何をどう変えたか1〜2行で -->

## 変更対象の層
- [ ] Domain
- [ ] Application（UseCase）
- [ ] Infrastructure
- [ ] Presentation（Controller）

## 変更内容
- 

## 確認事項
- [ ] ローカルで動作確認済み
- [ ] 依存方向が守られている（内側が外側を参照していない）
- [ ] Domain・Application 層のテストが通る
- [ ] マイグレーションが必要な場合は記載
```

---

## 10. Claude Code への指示ルール

Claude がコードを生成・修正する際は以下を**必ず**守ること。

### コード生成時

- クリーンアーキテクチャの**層の責務と依存方向**を常に意識すること
- ビジネスロジックは必ず **Domain または Application 層** に置くこと
- コントローラーにビジネスロジックを書かないこと
- Repository はインターフェース経由でのみ参照すること
- 新規ファイルを作る際は、**どの層に属するかを明示してから**生成すること
- 型宣言・`final`・`readonly` を積極的に使用すること

### PR・Git 操作時

コミットメッセージは **Conventional Commits** に従うこと。

```
feat: 食数登録UseCaseを追加
fix: MealDate値オブジェクトのバリデーション修正
refactor: MealCountRepositoryをインターフェース経由に変更
test: RegisterMealCountUseCaseのユニットテストを追加
docs: CLAUDE.mdのアーキテクチャ図を更新
```

PR は必ず `--base develop` で作成すること（最重要）。

### やってはいけないこと

| 禁止事項 | 理由 |
|----------|------|
| `main` への直接 push・PR | ブランチ戦略違反 |
| Domain 層でフレームワーク・DBを参照 | 依存方向違反 |
| コントローラーに ORM を直接記述 | 層の責務違反 |
| UseCase 内でリポジトリの具象クラスを使用 | DIP違反 |
| マイグレーションなしの DB 構造変更 | 変更追跡不能 |
| `var_dump` / `die` のコミット | デバッグコード混入 |
| ハードコードされた認証情報・APIキー | セキュリティリスク |

---

## 11. データベース・マイグレーション

```bash
# マイグレーション生成
bin/cake bake migration AddMealDateToMealCounts meal_date:date

# 実行
bin/cake migrations migrate

# ロールバック
bin/cake migrations rollback
```

---

## 12. よく使うコマンド集

```bash
# 開発サーバー起動
bin/cake server

# キャッシュクリア
bin/cake cache clear_all

# ルーティング確認
bin/cake routes

# テスト実行
vendor/bin/phpunit

# TableクラスのみBake（Infrastructureに生成）
bin/cake bake model MealCount --no-entity
```

---

## 13. 参考リンク

- [CakePHP 公式ドキュメント（日本語）](https://book.cakephp.org/5/ja/)
- [CakePHP DI コンテナ](https://book.cakephp.org/5/ja/development/dependency-injection.html)
- [Clean Architecture — Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Conventional Commits](https://www.conventionalcommits.org/ja/v1.0.0/)