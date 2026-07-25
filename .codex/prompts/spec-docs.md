---
description: Backlog課題から機能仕様書を生成し、使用プロンプト付きで公開する
argument-hint: ISSUE_KEY [FEATURE_NAME]
---

# Backlog 機能仕様書を生成・公開する

課題キー: $1  
機能名（任意）: $2  
全引数: $ARGUMENTS

`$1` が空ならユーザーに課題キーを確認すること。

## 必ず行うこと

1. プロンプト: `docs/design-prompts/00-system.md` + `05-specification.md`
2. 機能仕様書を生成（推奨: `docs/<slug>/00-specification.md`）
3. 公開: MCP `publish_spec_docs` または  
   `node tools/backlog-design-mcp/src/cli.js publish-spec ... --model "Codex" --replace`
4. URL とプロンプト添付の有無を報告

設計書が必要なら設計書用 Skill / `/prompts:design-docs` を使う。

## 禁止

- プロンプト添付・使用プロンプト記録の省略
- API キーの出力・コミット
