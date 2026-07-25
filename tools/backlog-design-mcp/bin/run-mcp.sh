#!/usr/bin/env bash
# Resolve repo-root absolute path so MCP works from any client cwd.
# BACKLOG_* は Node 側が .env から自動読込する（export 不要）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
exec node "$ROOT/tools/backlog-design-mcp/src/index.js"
