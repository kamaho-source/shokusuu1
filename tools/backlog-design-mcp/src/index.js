#!/usr/bin/env node
/**
 * backlog-design-mcp — MCP entry (Cursor / Claude Code / Codex / etc.)
 */
import fs from "node:fs";
import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { PROMPT_DIR, REPO_ROOT, readPromptBundle } from "./prompts.js";
import {
  createClientFromEnv,
  publishDesignDocs,
  publishSpecDocs,
} from "./publish.js";
import { loadBacklogEnvFromDotenv } from "./env.js";

loadBacklogEnvFromDotenv();

function textResult(obj) {
  return {
    content: [{ type: "text", text: JSON.stringify(obj, null, 2) }],
  };
}

const server = new McpServer({
  name: "backlog-design-mcp",
  version: "1.1.0",
});

server.tool(
  "list_design_prompts",
  "リポジトリ内の設計書生成プロンプト一式（docs/design-prompts）を返す",
  {},
  async () => textResult({ promptDir: PROMPT_DIR, prompts: readPromptBundle() })
);

server.tool(
  "get_issue",
  "Backlog課題を取得する",
  { issueKey: z.string().describe("課題キー例: SHOKUSU-10") },
  async ({ issueKey }) => textResult(await createClientFromEnv().getIssue(issueKey))
);

server.tool(
  "get_document_tree",
  "Backlogドキュメントツリーを取得する",
  {
    projectIdOrKey: z.string().describe("プロジェクトIDまたはキー（例: SHOKUSU）"),
  },
  async ({ projectIdOrKey }) =>
    textResult(await createClientFromEnv().getDocumentTree(projectIdOrKey))
);

server.tool(
  "add_document",
  "Backlogドキュメントを1件追加する（Markdown可）",
  {
    projectId: z.number().describe("数値のプロジェクトID"),
    title: z.string(),
    content: z.string().describe("Markdown本文"),
    emoji: z.string().optional(),
    parentId: z.string().optional().describe("親ドキュメントID"),
    addLast: z.boolean().optional().default(true),
  },
  async (args) => textResult(await createClientFromEnv().addDocument(args))
);

server.tool(
  "upload_attachment",
  "ローカルファイルを Backlog space/attachment にアップロードし attachmentId を返す",
  {
    filePath: z.string().describe("リポジトリ内の相対パス（リポジトリ外は拒否）"),
  },
  async ({ filePath }) => {
    const client = createClientFromEnv();
    const resolved = path.resolve(
      path.isAbsolute(filePath) ? filePath : path.join(REPO_ROOT, filePath)
    );
    const root = path.resolve(REPO_ROOT);
    if (resolved !== root && !resolved.startsWith(root + path.sep)) {
      throw new Error(`リポジトリ外のパスは指定できません: ${resolved}`);
    }
    if (!fs.existsSync(resolved)) {
      throw new Error(`ファイルがありません: ${resolved}`);
    }
    return textResult(await client.postAttachment(resolved));
  }
);

server.tool(
  "add_issue_comment_with_attachments",
  "課題へコメントを追加し、任意で添付ファイルIDを紐づける",
  {
    issueKey: z.string(),
    content: z.string(),
    attachmentIds: z.array(z.number()).optional(),
  },
  async ({ issueKey, content, attachmentIds }) =>
    textResult(
      await createClientFromEnv().addIssueComment(issueKey, {
        content,
        attachmentId: attachmentIds,
      })
    )
);

server.tool(
  "publish_design_docs",
  "概要/基本/詳細設計書と使用プロンプト記録を Backlog ドキュメントへ作成し、課題へリンクコメント（プロンプトファイル添付可）を残す",
  {
    issueKey: z.string().describe("例: SHOKUSU-10"),
    projectKey: z.string().optional(),
    featureName: z.string().describe("機能名（親ドキュメント名に使用）"),
    overviewMarkdown: z.string(),
    basicMarkdown: z.string(),
    detailedMarkdown: z.string(),
    modelNote: z.string().optional(),
    extraNotes: z.string().optional(),
    attachPromptFiles: z.boolean().optional().default(true),
    replaceExisting: z.boolean().optional().default(false),
  },
  async (args) => textResult(await publishDesignDocs(createClientFromEnv(), args))
);

server.tool(
  "publish_spec_docs",
  "機能仕様書と使用プロンプト記録を Backlog ドキュメントへ作成し、課題へリンクコメント（プロンプトファイル添付可）を残す",
  {
    issueKey: z.string().describe("例: SHOKUSU-10"),
    projectKey: z.string().optional(),
    featureName: z.string().describe("機能名（親ドキュメント名に使用）"),
    specificationMarkdown: z.string().describe("機能仕様書本文（Markdown）"),
    modelNote: z.string().optional(),
    extraNotes: z.string().optional(),
    attachPromptFiles: z.boolean().optional().default(true),
    replaceExisting: z.boolean().optional().default(false),
  },
  async (args) => textResult(await publishSpecDocs(createClientFromEnv(), args))
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
