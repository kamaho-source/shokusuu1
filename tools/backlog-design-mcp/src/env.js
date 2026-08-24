/**
 * Load BACKLOG_* from .env files into process.env (do not override existing).
 *
 * Search order (first wins per key among files; already-set process.env wins):
 * 1. <repo>/.env
 * 2. <repo>/.env.local
 * 3. <repo>/src_web/kamaho-shokusu/.env
 * 4. <repo>/src_web/kamaho-shokusu/config/.env
 */
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT } from "./prompts.js";

const ENV_KEYS = new Set([
  "BACKLOG_API_KEY",
  "BACKLOG_DOMAIN",
  "BACKLOG_SPACE_ID",
  "BACKLOG_PROJECT_KEY",
  "BACKLOG_NOTIFY_ENABLED",
  "BACKLOG_NOTIFY_ISSUE_KEY",
  "BACKLOG_NOTIFY_MODE",
  "BACKLOG_NOTIFY_TIMEOUT",
]);

/**
 * @param {string} content
 * @returns {Record<string, string>}
 */
function parseEnv(content) {
  /** @type {Record<string, string>} */
  const out = {};
  for (const raw of content.split(/\r?\n/)) {
    let line = raw.trim();
    if (!line || line.startsWith("#")) continue;
    // CakePHP config/.env は `export KEY=value` 形式
    if (line.startsWith("export ")) {
      line = line.slice("export ".length).trim();
    }
    const eq = line.indexOf("=");
    if (eq <= 0) continue;
    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    out[key] = value;
  }
  return out;
}

/**
 * @returns {{ loadedFiles: string[], appliedKeys: string[] }}
 */
export function loadBacklogEnvFromDotenv() {
  const candidates = [
    path.join(REPO_ROOT, ".env"),
    path.join(REPO_ROOT, ".env.local"),
    path.join(REPO_ROOT, "src_web/kamaho-shokusu/.env"),
    path.join(REPO_ROOT, "src_web/kamaho-shokusu/config/.env"),
  ];

  /** @type {string[]} */
  const loadedFiles = [];
  /** @type {string[]} */
  const appliedKeys = [];

  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    let parsed;
    try {
      parsed = parseEnv(fs.readFileSync(file, "utf8"));
    } catch {
      continue;
    }
    loadedFiles.push(file);
    for (const [key, value] of Object.entries(parsed)) {
      if (!ENV_KEYS.has(key) && !key.startsWith("BACKLOG_")) continue;
      if (!value) continue;
      if (process.env[key] !== undefined && process.env[key] !== "") continue;
      process.env[key] = value;
      appliedKeys.push(key);
    }
  }

  return { loadedFiles, appliedKeys: [...new Set(appliedKeys)] };
}
