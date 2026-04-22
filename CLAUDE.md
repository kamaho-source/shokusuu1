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
│   ├── Entity/
│   ├── ValueObject/
│   ├── Repository/        # インターフェースのみ
│   └── Exception/
├── Application/
│   ├── UseCase/           # {UseCaseName}/{Input,Output,UseCase}.php
│   └── Exception/
├── Infrastructure/
│   ├── Repository/        # Domain/Repository/ の実装
│   └── Persistence/       # CakePHP Table クラス
└── Presentation/
    └── Controller/
```

---

## 4. 各層の実装ルール

**Domain 層** — フレームワーク・DB依存を一切持たない。バリデーションはエンティティ・値オブジェクト内で完結。

**Application 層** — 1クラス1機能・`execute()` メソッドを持つ。Input/Output は専用DTOクラス。

**Infrastructure 層** — Domain の Repository Interface を必ず実装。CakePHP ORMへの依存はこの層に閉じ込める。

**Presentation 層** — コントローラーは薄く保つ。UseCase を呼び出し結果をテンプレートに渡すだけ。ビジネスロジック・ORM直接記述は禁止。

---

## 5. 依存性注入（DI）

コンストラクタインジェクションを使用。`Application.php` の `services()` でバインドする。

```php
public function services(ContainerInterface $container): void
{
    $container->add(MealCountRepositoryInterface::class, CakeMealCountRepository::class);
    $container->add(RegisterMealCountUseCase::class);
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

```bash
vendor/bin/phpunit                          # 全テスト
vendor/bin/phpunit tests/TestCase/Domain/   # 層ごと
```

---

## 9. ブランチ戦略

```
main     ← 本番リリース用（直接push禁止）
develop  ← 統合ブランチ（PRのベース）
feature/ ← 機能開発
fix/     ← バグ修正
hotfix/  ← 緊急本番修正
```

**PRのベースブランチは必ず `develop` を指定すること。`main` への直接PRは禁止。**

```bash
gh pr create --base develop --title "feat: 機能名" --body "..."
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
