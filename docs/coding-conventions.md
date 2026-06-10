# コーディング規約 — shokusuu1

## 基本フォーマット

| 項目 | ルール |
|------|--------|
| インデント | スペース **4つ**（タブ禁止） |
| 1行の最大文字数 | **120文字** |
| 文字コード | UTF-8（BOMなし） |
| 改行コード | LF |
| PHP開始タグ | `<?php` のみ（`?>` は書かない） |
| 空白行 | メソッド間に1行、論理ブロック間に1行 |

---

## クラス設計

- 意図的な継承以外はすべて `final` を付ける
- コンストラクタインジェクション（プロモーション構文）を使う
- 1クラス1責務を徹底する（SRP）
- クラスの行数が150行を超えたら分割を検討する

```php
// ✅ Good
final class RegisterMealCountUseCase
{
    public function __construct(
        private readonly MealCountRepositoryInterface $repository,
    ) {}
}

// ❌ Bad: finalなし・プロモーション未使用
class RegisterMealCountUseCase
{
    private MealCountRepositoryInterface $repository;
    public function __construct(MealCountRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }
}
```

---

## 型宣言

- 引数・戻り値・プロパティすべてに型を付ける
- `mixed` は使用禁止（やむを得ない場合はコメントで理由を記載）
- `null` を返す可能性がある場合は `?Type` または `Type|null` を明示する

---

## 値オブジェクト・DTO

```php
// 値オブジェクト: readonly + バリデーション内包
final class MealDate
{
    public function __construct(
        private readonly string $value,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidMealDateException("日付フォーマットが不正です: {$value}");
        }
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}

// DTO: public readonly プロパティのみ許可
final class RegisterMealCountInput
{
    public function __construct(
        public readonly string $date,
        public readonly int $count,
    ) {}
}
```

---

## 制御構文・早期リターン

- `else` は原則禁止（早期リターン・早期スローで代替）
- ネストは **最大3段まで**（それ以上はメソッド分割）
- 三項演算子は1行に収まる単純な条件のみ使用可

```php
// ✅ Good: 早期リターン
public function execute(RegisterMealCountInput $input): RegisterMealCountOutput
{
    if ($input->count < 0) {
        throw new InvalidMealCountException('食数は0以上を指定してください');
    }

    $existing = $this->repository->findByDate(new MealDate($input->date));
    if ($existing !== null) {
        throw new DuplicateMealCountException('同日の食数はすでに登録されています');
    }

    $mealCount = new MealCount(...);
    $this->repository->save($mealCount);

    return new RegisterMealCountOutput(id: $mealCount->getId()->value());
}
```

---

## 例外処理

```php
// ✅ Good: 層ごとに例外を定義
namespace App\Domain\Exception;
final class InvalidMealCountException extends \DomainException {}

namespace App\Application\Exception;
final class DuplicateMealCountException extends \RuntimeException {}

// ✅ Good: ControllerでHTTPレスポンスに変換
try {
    $output = $this->registerUseCase->execute($input);
} catch (InvalidMealCountException $e) {
    $this->response = $this->response->withStatus(400);
    $this->set('error', $e->getMessage());
    return;
}
```

- `catch (\Exception $e)` で握りつぶすのは禁止
- エラーメッセージは日本語で具体的に書く

---

## アクセス修飾子

- プロパティはすべて `private` または `private readonly`
- DTOの公開プロパティは `public readonly` のみ許可
- `protected` は継承が必要な場合のみ

---

## コメント・PHPDoc

- PHPDoc はクラス・公開メソッドに必ず記述する
- `@throws` は必ず記載する
- インラインコメントは「なぜそう書くか」の理由を説明する（「何をしているか」は不要）

```php
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
    public function execute(RegisterMealCountInput $input): RegisterMealCountOutput { ... }
}
```

---

## 禁止事項

| 禁止 | 代替 |
|------|------|
| `var_dump` / `print_r` / `die` | ログ・例外を使う |
| `@` エラー抑制演算子 | 例外処理で対応 |
| `global` 変数 | DI・コンテナを使う |
| 生SQL（`$pdo->query(...)` 直接） | CakePHP ORM 経由 |
| `mixed` 型 | 具体的な型を宣言 |
| マジックナンバー | 定数・値オブジェクトに切り出す |
| `else` 句（原則） | 早期リターン・早期スロー |
