#!/usr/bin/env bash
# Skill / カスタムコマンドを各クライアント向けパスへ同期する
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

SRC_SKILL="$ROOT/skills/backlog-design-docs/SKILL.md"
for dest in \
  "$ROOT/.cursor/skills/backlog-design-docs" \
  "$ROOT/.claude/skills/backlog-design-docs" \
  "$ROOT/.agents/skills/backlog-design-docs"
do
  mkdir -p "$dest"
  cp "$SRC_SKILL" "$dest/SKILL.md"
  echo "synced skill -> $dest/SKILL.md"
done

mkdir -p "$ROOT/.cursor/commands" "$ROOT/.claude/commands" "$ROOT/.codex/prompts"

cp "$ROOT/commands/design-docs.md" "$ROOT/.cursor/commands/design-docs.md"
cp "$ROOT/commands/spec-docs.md" "$ROOT/.cursor/commands/spec-docs.md"
echo "synced commands -> .cursor/commands/{design,spec}-docs.md"

echo "kept .claude/commands/*.md and .codex/prompts/*.md (frontmatter versions)"
echo "done. /design-docs  /spec-docs  (Cursor, Claude)  |  /prompts:design-docs  /prompts:spec-docs (Codex)"
