# Backlog 設計書を生成・公開する

チャット入力の **このコマンド名の後ろに付けた引数**（課題キーなど）を最優先で使う。

## 引数

| 位置 | 例 | 意味 |
|------|-----|------|
| 1 | `SHOKUSU-10` | Backlog 課題キー（必須。無ければユーザーに確認） |
| 2 | `システムレポート` | 機能名（任意。無ければ課題タイトルから決める） |

例: `/design-docs SHOKUSU-10` または `/design-docs SHOKUSU-10 システムレポート`

## 必ず行うこと

1. Skill / 手順に従う:
   - `skills/backlog-design-docs/SKILL.md`（または `.cursor` / `.claude` / `.agents` 配下の同名 Skill）
   - `docs/backlog-design-docs.md`
2. プロンプト原本を使う（要約だけで済ませない）:
   - `docs/design-prompts/00-system.md`
   - `docs/design-prompts/01-overview.md`
   - `docs/design-prompts/02-basic.md`
   - `docs/design-prompts/03-detailed.md`
3. 概要設計書・基本設計書・詳細設計書の Markdown を生成する  
   推奨保存先: `docs/<feature-slug>/01-overview-design.md` など
4. Backlog へ公開し、**使用プロンプトを添付・記録**する:
   - 優先: MCP `backlog-design` の `publish_design_docs`
   - 代替:  
     `node tools/backlog-design-mcp/src/cli.js publish --issue ... --feature ... --overview ... --basic ... --detailed ... --model "<このクライアント名>" --replace`
5. 親ドキュメント URL・3設計書 URL・使用プロンプト記録 URL・課題コメント添付の有無を報告する

## 禁止

- プロンプト原本の添付・「99 使用プロンプト記録」の省略
- API キーを出力・コミットすること
