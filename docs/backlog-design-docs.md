# Backlog 設計書自動生成ガイド（マルチクライアント）

概要設計書・基本設計書・詳細設計書を、**使用したプロンプト原文付き**で Backlog ドキュメントへ公開します。  
**Cursor / Claude Code / Codex** および MCP 非対応エージェントから同じ手順で使えます。

---

## 構成

| 部品 | 役割 |
|------|------|
| [`docs/design-prompts/`](./design-prompts/) | 生成に使うプロンプト原本（添付される） |
| [`skills/backlog-design-docs/`](../skills/backlog-design-docs/) | Skill 正本 |
| [`.mcp.json`](../.mcp.json) | **Claude Code** プロジェクト MCP |
| [`.cursor/mcp.json`](../.cursor/mcp.json) | **Cursor** MCP |
| [`.codex/config.toml`](../.codex/config.toml) | **Codex** プロジェクト MCP |
| [`tools/backlog-design-mcp/`](../tools/backlog-design-mcp/) | MCP サーバー + **CLI** |
| [`AGENTS.md`](../AGENTS.md) | Codex / 汎用エージェント向け要約 |
| [`CLAUDE.md`](../CLAUDE.md) §12 | Claude Code 向け要約 |

Skill のコピー先（`npm run sync-skills` で同期）:

- `.cursor/skills/backlog-design-docs/`
- `.claude/skills/backlog-design-docs/`
- `.agents/skills/backlog-design-docs/`

---

## 共通セットアップ

### 1. 依存

```bash
cd tools/backlog-design-mcp
npm install
```

### 2. 認証情報（`.env` 推奨）

CLI / MCP は起動時に次のファイルから `BACKLOG_*` を自動読込します（**既に export 済みの値は上書きしません**）:

1. リポジトリ直下 `.env`
2. リポジトリ直下 `.env.local`
3. `src_web/kamaho-shokusu/.env`
4. `src_web/kamaho-shokusu/config/.env`

例（どれか1つに記載）:

```bash
BACKLOG_DOMAIN=kamaho.backlog.com
BACKLOG_API_KEY=（個人設定 → API で発行）
BACKLOG_PROJECT_KEY=SHOKUSU
```

シェルへ `export` しなくても `.env` だけで足ります。**Git にコミットしないこと。**

GitHub Actions の Secret と同名で揃えると運用しやすいです（[`docs/backlog-github-sync.md`](./backlog-github-sync.md)）。

---

## クライアント別セットアップ

### Cursor

1. `.cursor/mcp.json` が有効であることを確認  
2. MCP をリロード / Cursor 再起動  
3. Skill: `.cursor/skills/backlog-design-docs/`  
4. **カスタムコマンド**: `.cursor/commands/design-docs.md` → チャットで `/design-docs`  
5. `${BACKLOG_*}` が展開されない場合はユーザー MCP 設定に値を書く（コミットしない）

### Claude Code

1. リポジトリ直下の [`.mcp.json`](../.mcp.json) をプロジェクト MCP として承認  
2. Skill: `.claude/skills/backlog-design-docs/`  
3. **カスタムコマンド**: `.claude/commands/design-docs.md` → `/design-docs SHOKUSU-10`  
4. 必要なら `claude mcp list` で `backlog` / `backlog-design` を確認  

`${CLAUDE_PROJECT_DIR}` は Claude Code が展開します。ローカルで手動試す場合はリポジトリルートで実行してください。

### Codex（CLI / IDE）

1. プロジェクトを **trusted** にする（プロジェクト `.codex/config.toml` を読むため）  
2. [`.codex/config.toml`](../.codex/config.toml) の MCP が読み込まれることを確認  
3. Skill: `.agents/skills/backlog-design-docs/`（および `AGENTS.md`）— **推奨**  
4. （任意・非推奨API）カスタムプロンプト: `.codex/prompts/design-docs.md`  
   - プロジェクト配下はクライアント版により未対応のことがある  
   - 確実にスラッシュ起動したい場合:  
     `cp .codex/prompts/design-docs.md ~/.codex/prompts/` → `/prompts:design-docs`  
5. `env_vars` でシェルの `BACKLOG_*` を転送  

### MCP が使えないエージェント全般

CLI だけで完結できます（下記「CLI」）。

---

## カスタムコマンド（スラッシュ）

| クライアント | 仕様書 | 設計書 |
|--------------|--------|--------|
| Cursor | `/spec-docs …`（`.cursor/commands/spec-docs.md`） | `/design-docs …` |
| Claude Code | `/spec-docs …` | `/design-docs …` |
| Codex | `/prompts:spec-docs …` | `/prompts:design-docs …` |

正本: [`commands/spec-docs.md`](../commands/spec-docs.md) / [`commands/design-docs.md`](../commands/design-docs.md)

```text
/spec-docs SHOKUSU-13
/spec-docs SHOKUSU-13 PHPアプリからBacklogへのエラー・イベント通知連携
/design-docs SHOKUSU-13
```

---

## 使い方（エージェントへの依頼文）

### カスタムコマンド（推奨）

```text
/spec-docs SHOKUSU-13
/design-docs SHOKUSU-13 システムレポート
```

| クライアント | コマンド |
|--------------|----------|
| Cursor / Claude Code | `/spec-docs …` / `/design-docs …` |
| Codex | `/prompts:spec-docs …` / `/prompts:design-docs …` |

### 自然言語

```text
SHOKUSU-13 の機能仕様書を作成し Backlog に公開。使用プロンプトも添付して。
SHOKUSU-10 の概要・基本・詳細設計書を作成し Backlog に公開。使用プロンプトも添付して。
```

Agent の流れ（仕様書）:

1. `00-system` + `05-specification` で機能仕様書を生成  
2. MCP `publish_spec_docs` または CLI `publish-spec`  
3. 課題コメントにプロンプト添付 + 「99 使用プロンプト記録」  
4. **課題の詳細（description）** にもドキュメントリンク一覧を追記／更新  

Agent の流れ（設計書）:

1. `docs/design-prompts/` で3文書を生成  
2. MCP `publish_design_docs` または CLI `publish`  
3. 課題コメントにプロンプト添付 + 「99 使用プロンプト記録」  
4. **課題の詳細（description）** にもドキュメントリンク一覧を追記／更新  

---

## CLI（どのツールからでも可）

```bash
# プロンプト一覧
node tools/backlog-design-mcp/src/cli.js prompts

# 課題取得
node tools/backlog-design-mcp/src/cli.js get-issue SHOKUSU-13

# 仕様書公開
node tools/backlog-design-mcp/src/cli.js publish-spec \
  --issue SHOKUSU-13 \
  --feature "PHPアプリからBacklogへのエラー・イベント通知連携" \
  --spec docs/backlog-notify/00-specification.md \
  --model "Cursor" \
  --replace

# 設計書公開
node tools/backlog-design-mcp/src/cli.js publish \
  --issue SHOKUSU-13 \
  --feature "PHPアプリからBacklogへのエラー・イベント通知連携" \
  --overview docs/backlog-notify/01-overview-design.md \
  --basic docs/backlog-notify/02-basic-design.md \
  --detailed docs/backlog-notify/03-detailed-design.md \
  --model "Cursor" \
  --replace
```

| フラグ | 意味 |
|--------|------|
| `--replace` | 同名親ドキュメントを削除して再作成 |
| `--no-attach` | 課題コメントへのプロンプト添付を省略（非推奨） |
| `--model` | 使用プロンプト記録に残す実行環境メモ |
| `--notes` | 補足 |

---

## Backlog 上の成果物

ドキュメント更新 API が無いため、**子を先に作成して ID を取得し、親に Markdown リンクと主本文を載せる**方式です。  
タイトルは `[課題キー]` プレフィックス付きで一覧から探しやすくしています。

### 仕様書

```text
📋 [SHOKUSU-N] 機能名 仕様書          ← リンク一覧 + 本文
📑 [SHOKUSU-N] 01 機能仕様書
🔗 [SHOKUSU-N] 00 ドキュメントリンク
📝 [SHOKUSU-N] 99 使用プロンプト記録
```

### 設計書

```text
📁 [SHOKUSU-N] 機能名 設計書          ← リンク一覧 + 概要本文
🧭 [SHOKUSU-N] 01 概要設計書
🧱 [SHOKUSU-N] 02 基本設計書
🔧 [SHOKUSU-N] 03 詳細設計書
🔗 [SHOKUSU-N] 00 ドキュメントリンク
📝 [SHOKUSU-N] 99 使用プロンプト記録
```

- 課題コメント: ドキュメントリンク + `docs/design-prompts/` の該当ファイル添付  
- 課題詳細: `関連ドキュメント（仕様書／設計書）` セクションを自動 upsert（再公開で上書き）

---

## プロンプトのカスタマイズ

`docs/design-prompts/` を編集すると、以降の生成・添付・記録に反映されます。

Skill 正本を直したら同期:

```bash
cd tools/backlog-design-mcp && npm run sync-skills
# または
bash tools/backlog-design-mcp/scripts/sync-skills.sh
```

---

## MCP ツール（backlog-design）

| ツール | 説明 |
|--------|------|
| `list_design_prompts` | プロンプト原本 |
| `get_issue` | 課題取得 |
| `get_document_tree` | ドキュメントツリー |
| `add_document` | 1件追加 |
| `upload_attachment` | ファイルアップロード |
| `add_issue_comment_with_attachments` | コメント＋添付 |
| `publish_design_docs` | 設計書一式公開 |
| `publish_spec_docs` | 機能仕様書公開 |

公式 `backlog-mcp-server`（サーバー名 `backlog`）は汎用操作用です。設計書のプロンプト添付まで一貫させるときは `publish_design_docs` か CLI を優先してください。

---

## 注意

- Document の **更新 API は未提供** → 再生成は `--replace` / `replaceExisting: true`  
- API キーをリポジトリ・チャットに書かない  
- Codex のプロジェクト MCP は **trusted プロジェクト**でのみ読み込まれます  

---

## 既存のローカル設計書例

- [`docs/system-report/`](./system-report/)
