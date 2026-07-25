# Backlog 機能仕様書を生成・公開する

チャット入力の **このコマンド名の後ろに付けた引数**（課題キーなど）を最優先で使う。

## 引数

| 位置 | 例 | 意味 |
|------|-----|------|
| 1 | `SHOKUSU-13` | Backlog 課題キー（必須。無ければユーザーに確認） |
| 2 | `機能名` | 任意。無ければ課題タイトルから決める |

例: `/spec-docs SHOKUSU-13` または `/spec-docs SHOKUSU-13 PHPアプリからBacklogへのエラー・イベント通知連携`

## 必ず行うこと

1. Skill / 手順の正本に従う:
   - `skills/backlog-design-docs/SKILL.md`
   - `docs/backlog-design-docs.md`（仕様書セクション）
2. **課題取得**（必須）: MCP/CLI `get_issue` で課題本文・コメントを取得し、以降の生成に反映する
3. プロンプト原本を使う（要約だけで済ませない）:
   - `docs/design-prompts/00-system.md`
   - `docs/design-prompts/05-specification.md`
4. **機能仕様書** Markdown を生成する  
   推奨保存先: `docs/<feature-slug>/00-specification.md`
5. Backlog へ公開し、**使用プロンプトを添付・記録**する（**既定は非破壊**）:
   - 優先: MCP `publish_spec_docs`
   - 代替:  
     `node tools/backlog-design-mcp/src/cli.js publish-spec --issue ... --feature ... --spec ... --model "<実行クライアント名>"`
   - 既存文書の削除置換はユーザー確認後のみ `--replace` を付与
6. 親ドキュメント URL・仕様書 URL・使用プロンプト記録 URL・課題コメント添付の有無を報告する

設計書（概要／基本／詳細）が必要なら `/design-docs` を別途実行する。

## 禁止

- 課題未取得のまま仕様書を書くこと
- プロンプト原本の添付・「99 使用プロンプト記録」の省略
- 確認なしの `--replace`
- API キーを出力・コミットすること
