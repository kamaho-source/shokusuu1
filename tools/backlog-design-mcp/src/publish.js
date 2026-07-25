/**
 * Core publisher used by MCP and CLI.
 *
 * Backlog Documents API は追加・削除のみ（更新 API なし）。
 * そのため子を先に作成して ID を取得し、親（目次）に Markdown リンクを埋め込む。
 */
import fs from "node:fs";
import path from "node:path";
import { BacklogClient, walkDocumentTree } from "./backlog.js";
import {
  PROMPT_DIR,
  buildProvenanceDoc,
  promptFilesForKind,
  readPromptBundle,
} from "./prompts.js";

/**
 * @param {string} projectKey
 * @param {string} spaceHost
 * @param {string} id
 */
function docUrl(projectKey, spaceHost, id) {
  return `https://${spaceHost}/document/${encodeURIComponent(projectKey)}/${id}`;
}

/**
 * @param {Array<{ label: string, url: string }>} links
 */
function formatLinkList(links) {
  return links.map((l) => `- [${l.label}](${l.url})`).join("\n");
}

/**
 * @param {BacklogClient} client
 * @param {any} treeRoot
 * @param {string} issueKey
 * @param {string} parentTitle
 */
async function deleteExistingPackage(client, treeRoot, issueKey, parentTitle) {
  const issuePrefix = `[${issueKey}]`;
  const nodes = walkDocumentTree(treeRoot).filter((n) => {
    const title = String(n.name || n.title || "");
    if (!title) return false;
    if (title === parentTitle) return true;
    // SHOKUSU-1 が SHOKUSU-13 に部分一致しないよう、"[KEY] " プレフィックスのみ許可
    if (!title.startsWith(`${issuePrefix} `) && title !== issuePrefix) return false;
    return /(仕様書|設計書|機能仕様|概要設計|基本設計|詳細設計|プロンプト|ドキュメントリンク)/.test(
      title
    );
  });

  // 深いノードから削除（子→親）
  nodes.sort((a, b) => (b.depth || 0) - (a.depth || 0));
  const seen = new Set();
  for (const n of nodes) {
    const id = n.id != null ? String(n.id) : "";
    if (!id || id === "Active" || seen.has(id)) continue;
    seen.add(id);
    await client.deleteDocumentIfExists(id);
  }
}

/**
 * @param {BacklogClient} client
 * @param {{
 *   issueKey: string,
 *   projectKey?: string,
 *   featureName: string,
 *   parentSuffix: string,
 *   parentEmoji?: string,
 *   parentIntroLines: string[],
 *   parentSummary?: string,
 *   localSourceDir?: string,
 *   primaryMarkdown: string,
 *   children: Array<{ title: string, content: string, emoji?: string }>,
 *   modelNote?: string,
 *   extraNotes?: string,
 *   attachPromptFiles?: boolean,
 *   replaceExisting?: boolean,
 *   kind: "design" | "spec",
 * }} args
 */
async function publishDocumentPackage(client, args) {
  const projectKey =
    args.projectKey ||
    process.env.BACKLOG_PROJECT_KEY ||
    "SHOKUSU";
  const project = await client.getProject(projectKey);
  const projectId = Number(project.id);
  const prompts = readPromptBundle();
  const generatedAt = new Date().toISOString();
  const parentTitle = `[${args.issueKey}] ${args.featureName} ${args.parentSuffix}`;
  const spaceHost = client.domain;
  const urlOf = (id) => docUrl(projectKey, spaceHost, id);

  if (args.replaceExisting) {
    const tree = await client.getDocumentTree(projectKey);
    await deleteExistingPackage(client, tree?.activeTree, args.issueKey, parentTitle);
    // 旧形式（親配下の短いタイトル）も親タイトル一致で削除済み。念のため再取得はしない。
  }

  /** @type {Record<string, { id: string, title: string }>} */
  const createdChildren = {};

  // 1) 本文ドキュメントを先に作成（ID を得てから親にリンクする）
  for (const child of args.children) {
    const title = `[${args.issueKey}] ${child.title}`;
    const doc = await client.addDocument({
      projectId,
      title,
      content: [
        `> 関連ドキュメントへの **クリック可能なリンク** は、同課題の親ページ（${args.parentSuffix}）先頭、または「00 ドキュメントリンク」にあります。`,
        "",
        child.content,
      ].join("\n"),
      emoji: child.emoji,
      addLast: true,
    });
    createdChildren[child.title] = { id: String(doc.id), title };
  }

  // 2) プロンプト記録
  const provenanceBody = buildProvenanceDoc({
    issueKey: args.issueKey,
    featureName: args.featureName,
    generatedAt,
    modelNote: args.modelNote,
    extraNotes: args.extraNotes,
    prompts,
    kind: args.kind,
  });
  const provenance = await client.addDocument({
    projectId,
    title: `[${args.issueKey}] 99 使用プロンプト記録`,
    content: provenanceBody,
    emoji: "📝",
    addLast: true,
  });

  const navLinks = [
    ...Object.entries(createdChildren).map(([label, d]) => ({
      label,
      url: urlOf(d.id),
    })),
    {
      label: "99 使用プロンプト記録",
      url: urlOf(String(provenance.id)),
    },
  ];

  // 3) 親＝クリック可能な目次 + 要約 + 主本文（開いた瞬間に中身がある）
  const parentContent = [
    `# ${args.featureName} ${args.parentSuffix}`,
    "",
    `- 課題: [${args.issueKey}](https://${spaceHost}/view/${args.issueKey})`,
    `- 生成日時: ${generatedAt}`,
    ...args.parentIntroLines.map((l) => `- ${l}`),
    "",
    "## ドキュメント一覧（リンク）",
    "",
    formatLinkList(navLinks),
    "",
    "> 上記リンクから各ドキュメント本文へ移動できます。",
    "",
    "## 要約",
    "",
    args.parentSummary || "（下記本文およびリンク先を参照）",
    "",
    "---",
    "",
    "## 本文",
    "",
    args.primaryMarkdown,
  ].join("\n");

  const parent = await client.addDocument({
    projectId,
    title: parentTitle,
    content: parentContent,
    emoji: args.parentEmoji || "📁",
    addLast: true,
  });

  // 4) 各子の先頭に相互リンクを付けるため、更新 API が無いので
  //    「00 ドキュメントリンク」を追加（親と同じリンク集）。検索しやすい。
  const linkHub = await client.addDocument({
    projectId,
    title: `[${args.issueKey}] 00 ドキュメントリンク`,
    content: [
      `# ${args.featureName} — ドキュメントリンク`,
      "",
      `- 課題: [${args.issueKey}](https://${spaceHost}/view/${args.issueKey})`,
      `- [親ドキュメント（目次＋本文）](${urlOf(String(parent.id))})`,
      "",
      "## 一覧",
      "",
      formatLinkList(navLinks),
    ].join("\n"),
    emoji: "🔗",
    addLast: true,
  });

  /** @type {number[]} */
  const attachmentIds = [];
  if (args.attachPromptFiles !== false) {
    for (const f of promptFilesForKind(args.kind)) {
      const p = path.join(PROMPT_DIR, f);
      if (fs.existsSync(p)) {
        const att = await client.postAttachment(p);
        attachmentIds.push(Number(att.id));
      }
    }
  }

  const allLinks = [
    { label: `親（${args.parentSuffix}）`, url: urlOf(String(parent.id)) },
    { label: "00 ドキュメントリンク", url: urlOf(String(linkHub.id)) },
    ...navLinks,
  ];

  const commentContent = [
    `## ${args.parentSuffix}を自動生成・公開しました`,
    ``,
    `親ドキュメントに **本文** と **クリック可能なリンク一覧** を入れています。`,
    ``,
    formatLinkList(allLinks),
    ``,
    `生成に使ったプロンプト原本を本コメントに添付しています（\`docs/design-prompts/\`）。`,
    `生成日時: ${generatedAt}`,
  ].join("\n");

  const comment = await client.addIssueComment(args.issueKey, {
    content: commentContent,
    attachmentId: attachmentIds,
  });

  // 課題の詳細（description）にもリンクを残す（コメントだけでなくチケット本文から辿れるように）
  const descLines = [
    `## 関連ドキュメント（${args.parentSuffix}）`,
    ``,
    `生成日時: ${generatedAt}`,
    ``,
    formatLinkList(allLinks),
  ];
  if (args.localSourceDir) {
    descLines.push(``, `ローカル原稿: \`${args.localSourceDir}\``);
  }
  await client.upsertIssueDescriptionSection(
    args.issueKey,
    descLines.join("\n"),
    args.kind
  );

  return {
    ok: true,
    projectId,
    parent,
    linkHub,
    documents: {
      ...Object.fromEntries(
        Object.entries(createdChildren).map(([k, v]) => [k, { id: v.id, title: v.title }])
      ),
      provenance,
      linkHub,
    },
    comment,
    attachmentIds,
    urls: {
      parent: urlOf(String(parent.id)),
      linkHub: urlOf(String(linkHub.id)),
      provenance: urlOf(String(provenance.id)),
      ...Object.fromEntries(
        Object.entries(createdChildren).map(([title, d]) => [title, urlOf(d.id)])
      ),
    },
  };
}

/**
 * @param {BacklogClient} client
 * @param {{
 *   issueKey: string,
 *   projectKey?: string,
 *   featureName: string,
 *   overviewMarkdown: string,
 *   basicMarkdown: string,
 *   detailedMarkdown: string,
 *   modelNote?: string,
 *   extraNotes?: string,
 *   parentSummary?: string,
 *   localSourceDir?: string,
 *   attachPromptFiles?: boolean,
 *   replaceExisting?: boolean,
 * }} args
 */
export async function publishDesignDocs(client, args) {
  // 相互リンクは更新 API が無いため、親と「00 ドキュメントリンク」に集約する。
  return publishDocumentPackage(client, {
    ...args,
    kind: "design",
    parentSuffix: "設計書",
    parentEmoji: "📁",
    parentIntroLines: [
      "配下（同一プレフィックス）に概要・基本・詳細設計書とプロンプト記録を配置",
      "このページ先頭のリンクから各文書へ移動できます",
    ],
    parentSummary:
      args.parentSummary ||
      `${args.featureName} の設計書一式。詳細は配下の概要／基本／詳細設計書を参照。`,
    primaryMarkdown: args.overviewMarkdown,
    children: [
      {
        title: "01 概要設計書",
        content: args.overviewMarkdown,
        emoji: "🧭",
      },
      {
        title: "02 基本設計書",
        content: args.basicMarkdown,
        emoji: "🧱",
      },
      {
        title: "03 詳細設計書",
        content: args.detailedMarkdown,
        emoji: "🔧",
      },
    ],
  });
}

/**
 * @param {BacklogClient} client
 * @param {{
 *   issueKey: string,
 *   projectKey?: string,
 *   featureName: string,
 *   specificationMarkdown: string,
 *   modelNote?: string,
 *   extraNotes?: string,
 *   parentSummary?: string,
 *   localSourceDir?: string,
 *   attachPromptFiles?: boolean,
 *   replaceExisting?: boolean,
 * }} args
 */
export async function publishSpecDocs(client, args) {
  return publishDocumentPackage(client, {
    ...args,
    kind: "spec",
    parentSuffix: "仕様書",
    parentEmoji: "📋",
    parentIntroLines: [
      "このページに機能仕様書の本文を掲載しています",
      "関連ドキュメントへのリンクはページ先頭を参照",
    ],
    parentSummary:
      args.parentSummary ||
      `${args.featureName} の機能仕様書。要件・受け入れ条件は本文を参照。実装方式は設計書を参照。`,
    primaryMarkdown: args.specificationMarkdown,
    // 仕様書は親に全文を載せる。子は同内容の単体ページ（印刷・共有用）を1つ置く。
    children: [
      {
        title: "01 機能仕様書",
        content: args.specificationMarkdown,
        emoji: "📑",
      },
    ],
  });
}

export function createClientFromEnv() {
  const domain =
    process.env.BACKLOG_DOMAIN ||
    (process.env.BACKLOG_SPACE_ID
      ? `${process.env.BACKLOG_SPACE_ID}.backlog.com`
      : "");
  const apiKey = process.env.BACKLOG_API_KEY || "";
  return new BacklogClient({ domain, apiKey });
}
