# Agent 向けプロジェクト指示（Codex / 汎用）

このリポジトリは CakePHP 食数管理システム（shokusuu1）です。詳細なアーキ規約は `CLAUDE.md` を参照してください。

## Backlog 設計書（概要 / 基本 / 詳細）

課題から設計書を作り Backlog ドキュメントへ公開し、**使用したプロンプト原文も添付**する。

| 項目 | 場所 |
|------|------|
| 手順 | `docs/backlog-design-docs.md` |
| Skill | `.agents/skills/backlog-design-docs/SKILL.md`（正本: `skills/backlog-design-docs/`） |
| スラッシュ（任意） | `.codex/prompts/design-docs.md` → 必要なら `cp` して `/prompts:design-docs` |
| MCP | `.codex/config.toml`（`backlog-design` のみ） |
| CLI（MCP不要） | `node tools/backlog-design-mcp/src/cli.js` |

環境変数: `BACKLOG_DOMAIN`, `BACKLOG_API_KEY`, （任意）`BACKLOG_PROJECT_KEY`

例:

```text
/prompts:design-docs SHOKUSU-10
```

または:

```text
SHOKUSU-10 の概要・基本・詳細設計書を作成し Backlog に公開。使用プロンプトも添付して。
```
