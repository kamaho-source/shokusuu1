# backlog-design-mcp

Backlog へ **概要 / 基本 / 詳細設計書** と **使用プロンプト記録** を公開する MCP + CLI です。  
Cursor / Claude Code / Codex などから同じ実装を利用します。

## セットアップ

```bash
npm install
export BACKLOG_DOMAIN=kamaho.backlog.com
export BACKLOG_API_KEY=xxxxx
export BACKLOG_PROJECT_KEY=SHOKUSU
```

## 起動

| 用途 | コマンド |
|------|----------|
| MCP (stdio) | `npm start` または `bin/run-mcp.sh` |
| CLI | `npm run cli -- publish --help` |

クライアント設定はリポジトリ直下:

- Claude Code: `.mcp.json`
- Cursor: `.cursor/mcp.json`
- Codex: `.codex/config.toml`

詳細: [`docs/backlog-design-docs.md`](../../docs/backlog-design-docs.md)
