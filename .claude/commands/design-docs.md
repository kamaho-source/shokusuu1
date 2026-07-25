# Backlog 設計書を生成・公開する

チャット入力の **このコマンド名の後ろに付けた引数**（課題キーなど）を最優先で使う。

## 引数

| 位置 | 例 | 意味 |
|------|-----|------|
| 1 | `SHOKUSU-10` | Backlog 課題キー（必須。無ければユーザーに確認） |
| 2 | `システムレポート` | 機能名（任意。無ければ課題タイトルから決める） |

例: `/design-docs SHOKUSU-10` または `/design-docs SHOKUSU-10 システムレポート`

## 必ず行うこと

1. Skill / 手順の正本に従う:
   - `skills/backlog-design-docs/SKILL.md`
   - `docs/backlog-design-docs.md`
2. **課題取得**（必須）: MCP/CLI `get_issue` で課題本文・コメントを取得し、以降の生成に反映する
3. プロンプト原本を使う（要約だけで済ませない）:
   - `docs/design-prompts/00-system.md`
   - `docs/design-prompts/01-overview.md`
   - `docs/design-prompts/02-basic.md`
   - `docs/design-prompts/03-detailed.md`
4. 概要設計書・基本設計書・詳細設計書の Markdown を生成する  
   推奨保存先: `docs/<feature-slug>/01-overview-design.md` など
5. Backlog へ公開し、**使用プロンプトを添付・記録**する（**既定は非破壊**）:
   - 優先: MCP `backlog-design` の `publish_design_docs`
   - 代替:  
     `node tools/backlog-design-mcp/src/cli.js publish --issue ... --feature ... --overview ... --basic ... --detailed ... --model "<実行クライアント名>"`
   - 既存文書の削除置換はユーザー確認後のみ `--replace` を付与
6. 親ドキュメント URL・3設計書 URL・使用プロンプト記録 URL・課題コメント添付の有無を報告する

## 禁止

- 課題未取得のまま設計書を書くこと
- プロンプト原本の添付・「99 使用プロンプト記録」の省略
- 確認なしの `--replace`
- API キーを出力・コミットすること
