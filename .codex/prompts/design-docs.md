---
description: Backlog課題から概要・基本・詳細設計書を生成し、使用プロンプト付きで公開する
argument-hint: ISSUE_KEY [FEATURE_NAME]
---

# Backlog 設計書を生成・公開する

課題キー: $1  
機能名（任意）: $2  
全引数: $ARGUMENTS

`$1` が空ならユーザーに課題キーを確認すること。

## 必ず行うこと

1. Skill / 手順:
   - `.agents/skills/backlog-design-docs/SKILL.md`
   - `docs/backlog-design-docs.md`
2. プロンプト原本（要約だけで済ませない）:
   - `docs/design-prompts/00-system.md`
   - `docs/design-prompts/01-overview.md`
   - `docs/design-prompts/02-basic.md`
   - `docs/design-prompts/03-detailed.md`
3. 概要 / 基本 / 詳細の Markdown を生成（推奨: `docs/<feature-slug>/`）
4. Backlog 公開（使用プロンプト添付必須）:
   - 優先: MCP `publish_design_docs`
   - 代替: `node tools/backlog-design-mcp/src/cli.js publish ... --model "Codex" --replace`
5. 公開 URL と課題コメント添付の有無を報告

## 禁止

- プロンプト原本添付・「99 使用プロンプト記録」の省略
- API キーの出力・コミット

注: Codex の custom prompts は非推奨傾向のため、通常は Skill を優先。  
このファイルは `/prompts:design-docs` 用（`~/.codex/prompts` へコピーしても可）。
