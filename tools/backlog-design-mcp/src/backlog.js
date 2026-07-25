/**
 * Minimal Backlog API helper (Documents / Issues / Attachments).
 */
import fs from "node:fs";
import path from "node:path";
import { Blob } from "node:buffer";

export class BacklogClient {
  /**
   * @param {{ domain: string, apiKey: string }} opts
   */
  constructor({ domain, apiKey }) {
    if (!domain || !apiKey) {
      throw new Error("BACKLOG_DOMAIN と BACKLOG_API_KEY が必要です");
    }
    this.domain = domain.replace(/^https?:\/\//, "").replace(/\/$/, "");
    this.apiKey = apiKey;
    this.base = `https://${this.domain}/api/v2`;
  }

  /**
   * @param {string} method
   * @param {string} apiPath
   * @param {Record<string, unknown> | null} [form]
   */
  async request(method, apiPath, form = null) {
    const url = new URL(`${this.base}${apiPath}`);
    url.searchParams.set("apiKey", this.apiKey);

    /** @type {RequestInit} */
    const init = { method, headers: {} };

    if (form && method !== "GET") {
      const body = new URLSearchParams();
      for (const [k, v] of Object.entries(form)) {
        if (v === undefined || v === null) continue;
        if (Array.isArray(v)) {
          for (const item of v) body.append(k, String(item));
        } else {
          body.append(k, String(v));
        }
      }
      init.body = body;
      init.headers = { "Content-Type": "application/x-www-form-urlencoded" };
    } else if (form && method === "GET") {
      for (const [k, v] of Object.entries(form)) {
        if (v === undefined || v === null) continue;
        if (Array.isArray(v)) {
          for (const item of v) url.searchParams.append(k, String(item));
        } else {
          url.searchParams.set(k, String(v));
        }
      }
    }

    const res = await fetch(url, init);
    const text = await res.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch {
      json = { raw: text };
    }
    if (!res.ok) {
      const msg = typeof json === "object" ? JSON.stringify(json) : text;
      throw new Error(`Backlog ${method} ${apiPath} → HTTP ${res.status}: ${msg}`);
    }
    return json;
  }

  /** @param {string} projectIdOrKey */
  async getProject(projectIdOrKey) {
    return this.request("GET", `/projects/${encodeURIComponent(projectIdOrKey)}`);
  }

  /** @param {string} issueIdOrKey */
  async getIssue(issueIdOrKey) {
    return this.request("GET", `/issues/${encodeURIComponent(issueIdOrKey)}`);
  }

  /**
   * @param {string} issueIdOrKey
   * @param {Record<string, unknown>} fields
   */
  async updateIssue(issueIdOrKey, fields) {
    return this.request(
      "PATCH",
      `/issues/${encodeURIComponent(issueIdOrKey)}`,
      fields
    );
  }

  /**
   * Upsert a marked section in the issue description (idempotent).
   * @param {string} issueIdOrKey
   * @param {string} sectionMarkdown  full section including heading
   * @param {string} sectionId  e.g. "spec" | "design"
   */
  async upsertIssueDescriptionSection(issueIdOrKey, sectionMarkdown, sectionId) {
    const start = `<!-- backlog-docs:${sectionId} -->`;
    const end = `<!-- /backlog-docs:${sectionId} -->`;
    const issue = await this.getIssue(issueIdOrKey);
    const current = String(issue.description || "");
    const block = `${start}\n${sectionMarkdown.trim()}\n${end}`;
    let next;
    if (current.includes(start) && current.includes(end)) {
      const re = new RegExp(
        `${start.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}[\\s\\S]*?${end.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}`,
        "m"
      );
      next = current.replace(re, block);
    } else {
      next = current.trim()
        ? `${current.trim()}\n\n---\n\n${block}\n`
        : `${block}\n`;
    }
    return this.updateIssue(issueIdOrKey, { description: next });
  }

  /**
   * @param {{ projectId: number, title: string, content: string, emoji?: string, parentId?: string, addLast?: boolean }} p
   */
  async addDocument(p) {
    return this.request("POST", "/documents", {
      projectId: p.projectId,
      title: p.title,
      content: p.content,
      emoji: p.emoji,
      parentId: p.parentId,
      addLast: p.addLast ?? true,
    });
  }

  /** @param {string} projectIdOrKey */
  async getDocumentTree(projectIdOrKey) {
    return this.request("GET", "/documents/tree", {
      projectIdOrKey,
    });
  }

  /** @param {string} documentId */
  async getDocument(documentId) {
    return this.request("GET", `/documents/${encodeURIComponent(documentId)}`);
  }

  /** @param {string} documentId */
  async deleteDocument(documentId) {
    return this.request("DELETE", `/documents/${encodeURIComponent(documentId)}`);
  }

  /**
   * Delete document; ignore already-missing (404 / code 6).
   * @param {string} documentId
   */
  async deleteDocumentIfExists(documentId) {
    try {
      return await this.deleteDocument(documentId);
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      if (/\bHTTP 404\b/.test(msg) || /No such document/.test(msg)) {
        return null;
      }
      throw e;
    }
  }

  /**
   * Upload a local file to space attachment pool.
   * @param {string} filePath
   * @returns {Promise<{ id: number, name: string, size: number }>}
   */
  async postAttachment(filePath) {
    const abs = path.resolve(filePath);
    if (!fs.existsSync(abs)) {
      throw new Error(`ファイルがありません: ${abs}`);
    }
    const buf = fs.readFileSync(abs);
    const name = path.basename(abs);
    const form = new FormData();
    form.append("file", new Blob([buf]), name);

    const url = new URL(`${this.base}/space/attachment`);
    url.searchParams.set("apiKey", this.apiKey);
    const res = await fetch(url, { method: "POST", body: form });
    const text = await res.text();
    const json = JSON.parse(text);
    if (!res.ok) {
      throw new Error(`attachment upload HTTP ${res.status}: ${text}`);
    }
    return json;
  }

  /**
   * @param {string} issueIdOrKey
   * @param {{ content: string, attachmentId?: number[] }} p
   */
  async addIssueComment(issueIdOrKey, p) {
    /** @type {Record<string, unknown>} */
    const form = { content: p.content };
    if (p.attachmentId?.length) {
      form["attachmentId[]"] = p.attachmentId;
    }
    return this.request(
      "POST",
      `/issues/${encodeURIComponent(issueIdOrKey)}/comments`,
      form
    );
  }
}

/**
 * Flatten document tree nodes with depth.
 * @param {any} node
 * @param {number} [depth]
 * @param {any[]} [out]
 */
export function walkDocumentTree(node, depth = 0, out = []) {
  if (!node) return out;
  const title = node.name || node.title;
  if (title || node.id) {
    out.push({ ...node, depth, name: title, title });
  }
  for (const c of node.children || []) walkDocumentTree(c, depth + 1, out);
  return out;
}

/**
 * Walk document tree and find nodes by exact title.
 * @param {any} node
 * @param {string} title
 * @param {any[]} out
 */
export function findTreeNodesByTitle(node, title, out = []) {
  if (!node) return out;
  if (node.name === title || node.title === title) out.push(node);
  const children = node.children || [];
  for (const c of children) findTreeNodesByTitle(c, title, out);
  return out;
}
