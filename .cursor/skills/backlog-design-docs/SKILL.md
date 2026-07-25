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
2. CLI（**既定は非破壊**。既存文書の削除置換はユーザー確認後のみ `--replace`）:

```bash
# 仕様書（初回・追加公開）
node tools/backlog-design-mcp/src/cli.js publish-spec \
  --issue SHOKUSU-13 --feature "機能名" \
  --spec docs/<slug>/00-specification.md --model "<実行クライアント名>"

# 設計書（初回・追加公開）
node tools/backlog-design-mcp/src/cli.js publish \
  --issue SHOKUSU-13 --feature "機能名" \
  --overview docs/<slug>/01-overview-design.md \
  --basic docs/<slug>/02-basic-design.md \
  --detailed docs/<slug>/03-detailed-design.md \
  --model "<実行クライアント名>"

# 明示的な再生成（既存パッケージ削除 → 再作成）。ユーザー確認後のみ付与:
#   ... --replace
```

手順: `docs/backlog-design-docs.md`

## 仕様書手順（/spec-docs）

1. **課題取得**（必須）: MCP/CLI `get_issue` で本文・コメントを取得し、生成に反映する  
2. プロンプト: `00-system.md` + `05-specification.md`  
3. 機能仕様書生成 → `docs/<slug>/00-specification.md`  
4. `publish_spec_docs` / `publish-spec`（置換は確認後のみ `--replace`）  
5. URL とプロンプト添付を報告  

## 設計書手順（/design-docs）

1. **課題取得**（必須）: `get_issue`  
2. プロンプト: `00-system` + `01/02/03`  
3. 3文書生成 → 公開 `publish_design_docs`（置換は確認後のみ）  
4. URL とプロンプト添付を報告  

## 呼び出し例

```text
/spec-docs SHOKUSU-13
/design-docs SHOKUSU-13
```
