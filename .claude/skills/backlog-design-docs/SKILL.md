---
name: backlog-design-docs
description: >-
  Generates Backlog 機能仕様書 and/or 概要・基本・詳細設計書 from an issue, publishes
  them to Backlog Documents, and attaches the exact prompts used. Use for
  /spec-docs, /design-docs, 仕様書, 設計書, or 使用プロンプト添付.
  Works in Cursor, Claude Code, Codex, and any agent that can run the CLI.
---

# Backlog 仕様書・設計書自動生成（マルチクライアント）

## いつ使うか

- **機能仕様書**を作る（`/spec-docs`）
- **概要 / 基本 / 詳細設計書**を作る（`/design-docs`）
- Backlog ドキュメントへ公開し、使用プロンプトを添付する

## 認証

`BACKLOG_*` は `.env`（CakePHP の `export KEY=value` 可）から自動読込。  
場所: リポジトリ `.env` / `src_web/kamaho-shokusu/config/.env` など。

## 公開手段（優先順）

1. MCP `backlog-design` → `publish_spec_docs` / `publish_design_docs`
2. CLI:

```bash
# 仕様書
node tools/backlog-design-mcp/src/cli.js publish-spec \
  --issue SHOKUSU-13 --feature "機能名" \
  --spec docs/<slug>/00-specification.md --model "Cursor" --replace

# 設計書
node tools/backlog-design-mcp/src/cli.js publish \
  --issue SHOKUSU-13 --feature "機能名" \
  --overview docs/<slug>/01-overview-design.md \
  --basic docs/<slug>/02-basic-design.md \
  --detailed docs/<slug>/03-detailed-design.md \
  --model "Cursor" --replace
```

手順: `docs/backlog-design-docs.md`

## 仕様書手順（/spec-docs）

1. プロンプト: `00-system.md` + `05-specification.md`
2. 課題取得 → 機能仕様書生成 → `docs/<slug>/00-specification.md`
3. `publish_spec_docs` / `publish-spec`
4. URL とプロンプト添付を報告

## 設計書手順（/design-docs）

1. プロンプト: `00-system` + `01/02/03`
2. 3文書生成 → 公開 `publish_design_docs`
3. URL とプロンプト添付を報告

## 呼び出し例

```text
/spec-docs SHOKUSU-13
/design-docs SHOKUSU-13
```
