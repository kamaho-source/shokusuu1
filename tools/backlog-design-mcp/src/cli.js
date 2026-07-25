#!/usr/bin/env node
/**
 * CLI — MCP 非対応のエージェント / シェルからも同じ公開処理を実行できる。
 */
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT, readPromptBundle } from "./prompts.js";
import {
  createClientFromEnv,
  publishDesignDocs,
  publishSpecDocs,
} from "./publish.js";
import { loadBacklogEnvFromDotenv } from "./env.js";

loadBacklogEnvFromDotenv();

function usage() {
  console.log(`Usage:
  backlog-design-cli prompts
  backlog-design-cli get-issue <ISSUE_KEY>
  backlog-design-cli publish --issue KEY --feature NAME \\
      --overview PATH --basic PATH --detailed PATH \\
      [--project KEY] [--model NOTE] [--notes TEXT] [--replace] [--no-attach]
  backlog-design-cli publish-spec --issue KEY --feature NAME \\
      --spec PATH \\
      [--project KEY] [--model NOTE] [--notes TEXT] [--replace] [--no-attach]

Credentials: process env or .env (CakePHP \`export KEY=value\` OK)
  BACKLOG_DOMAIN / BACKLOG_SPACE_ID, BACKLOG_API_KEY, BACKLOG_PROJECT_KEY
`);
}

function readArg(argv, name) {
  const i = argv.indexOf(name);
  if (i === -1 || i + 1 >= argv.length) return undefined;
  return argv[i + 1];
}

function hasFlag(argv, name) {
  return argv.includes(name);
}

function resolvePath(p) {
  if (!p) return p;
  return path.isAbsolute(p) ? p : path.join(REPO_ROOT, p);
}

function readFile(p) {
  const abs = resolvePath(p);
  if (!fs.existsSync(abs)) {
    throw new Error(`ファイルがありません: ${abs}`);
  }
  return fs.readFileSync(abs, "utf8");
}

async function main() {
  const argv = process.argv.slice(2);
  const cmd = argv[0];

  if (!cmd || cmd === "-h" || cmd === "--help") {
    usage();
    process.exit(cmd ? 0 : 1);
  }

  if (cmd === "prompts") {
    console.log(JSON.stringify({ prompts: readPromptBundle() }, null, 2));
    return;
  }

  if (cmd === "get-issue") {
    const key = argv[1];
    if (!key) {
      usage();
      process.exit(1);
    }
    const issue = await createClientFromEnv().getIssue(key);
    console.log(JSON.stringify(issue, null, 2));
    return;
  }

  if (cmd === "publish") {
    const issueKey = readArg(argv, "--issue");
    const featureName = readArg(argv, "--feature");
    const overviewPath = readArg(argv, "--overview");
    const basicPath = readArg(argv, "--basic");
    const detailedPath = readArg(argv, "--detailed");
    if (!issueKey || !featureName || !overviewPath || !basicPath || !detailedPath) {
      usage();
      process.exit(1);
    }

    const result = await publishDesignDocs(createClientFromEnv(), {
      issueKey,
      featureName,
      projectKey: readArg(argv, "--project"),
      overviewMarkdown: readFile(overviewPath),
      basicMarkdown: readFile(basicPath),
      detailedMarkdown: readFile(detailedPath),
      modelNote: readArg(argv, "--model"),
      extraNotes: readArg(argv, "--notes"),
      attachPromptFiles: !hasFlag(argv, "--no-attach"),
      replaceExisting: hasFlag(argv, "--replace"),
    });
    console.log(JSON.stringify(result, null, 2));
    return;
  }

  if (cmd === "publish-spec") {
    const issueKey = readArg(argv, "--issue");
    const featureName = readArg(argv, "--feature");
    const specPath = readArg(argv, "--spec");
    if (!issueKey || !featureName || !specPath) {
      usage();
      process.exit(1);
    }

    const result = await publishSpecDocs(createClientFromEnv(), {
      issueKey,
      featureName,
      projectKey: readArg(argv, "--project"),
      specificationMarkdown: readFile(specPath),
      modelNote: readArg(argv, "--model"),
      extraNotes: readArg(argv, "--notes"),
      attachPromptFiles: !hasFlag(argv, "--no-attach"),
      replaceExisting: hasFlag(argv, "--replace"),
    });
    console.log(JSON.stringify(result, null, 2));
    return;
  }

  console.error(`Unknown command: ${cmd}`);
  usage();
  process.exit(1);
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : err);
  process.exit(1);
});
