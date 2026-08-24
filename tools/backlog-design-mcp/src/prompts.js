/**
 * Prompt bundle + provenance markdown builders.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = path.resolve(__dirname, "../../..");
export const PROMPT_DIR = path.join(REPO_ROOT, "docs/design-prompts");

const PROMPT_FILES = [
  "00-system.md",
  "01-overview.md",
  "02-basic.md",
  "03-detailed.md",
  "04-provenance-template.md",
  "05-specification.md",
];

export function readPromptBundle() {
  /** @type {Record<string, string>} */
  const out = {};
  for (const f of PROMPT_FILES) {
    const p = path.join(PROMPT_DIR, f);
    out[f] = fs.existsSync(p) ? fs.readFileSync(p, "utf8") : `(missing: ${p})`;
  }
  return out;
}

/**
 * @param {{
 *   issueKey: string,
 *   featureName: string,
 *   generatedAt: string,
 *   modelNote?: string,
 *   extraNotes?: string,
 *   prompts: Record<string, string>,
 *   kind?: "design" | "spec",
 * }} meta
 */
export function buildProvenanceDoc(meta) {
  const tpl = meta.prompts["04-provenance-template.md"] || "";
  let body = tpl
    .replaceAll("{{ISSUE_KEY}}", meta.issueKey)
    .replaceAll("{{FEATURE_NAME}}", meta.featureName)
    .replaceAll("{{GENERATED_AT}}", meta.generatedAt)
    .replaceAll("{{MODEL_NOTE}}", meta.modelNote || "AI coding agent")
    .replaceAll("{{EXTRA_NOTES}}", meta.extraNotes || "（なし）");

  /** @type {Array<[string, string]>} */
  const sections =
    meta.kind === "spec"
      ? [
          ["00-system.md", "共通システム指示"],
          ["05-specification.md", "機能仕様書プロンプト"],
        ]
      : [
          ["00-system.md", "共通システム指示"],
          ["01-overview.md", "概要設計書プロンプト"],
          ["02-basic.md", "基本設計書プロンプト"],
          ["03-detailed.md", "詳細設計書プロンプト"],
        ];

  for (const [file, label] of sections) {
    body += `\n\n---\n\n## ${label}（\`${file}\`）\n\n\`\`\`markdown\n${meta.prompts[file] || ""}\n\`\`\`\n`;
  }
  return body;
}

/** Prompt files to attach to issue comments. */
export function promptFilesForKind(kind = "design") {
  if (kind === "spec") {
    return ["00-system.md", "05-specification.md"];
  }
  return ["00-system.md", "01-overview.md", "02-basic.md", "03-detailed.md"];
}
