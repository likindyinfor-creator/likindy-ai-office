export type StoredDocumentType =
  | "official-letter"
  | "report"
  | "proposal"
  | "meeting-minutes"
  | "general-document";

export type StoredDocumentLanguage = "en" | "sw";
export type StoredDocumentStatus = "draft" | "completed";

export type StoredDocument = {
  id: string;
  title: string;
  type: StoredDocumentType;
  language: StoredDocumentLanguage;
  content: string;
  typeset: boolean;
  status: StoredDocumentStatus;
  wordCount: number;
  createdAt: string;
  updatedAt: string;
};

const documentsStorageKey = "likindy-documents-v1";
const legacyDraftStorageKey = "likindy-document-draft";
const allowedContentTags = new Set(["P", "BR", "B", "STRONG", "I", "EM", "U", "H1", "H2", "H3", "UL", "OL", "LI", "BLOCKQUOTE"]);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isStoredDocument(value: unknown): value is StoredDocument {
  if (!isRecord(value)) return false;
  return typeof value.id === "string"
    && typeof value.title === "string"
    && ["official-letter", "report", "proposal", "meeting-minutes", "general-document"].includes(String(value.type))
    && (value.language === "en" || value.language === "sw")
    && typeof value.content === "string"
    && typeof value.typeset === "boolean"
    && (value.status === "draft" || value.status === "completed")
    && typeof value.wordCount === "number"
    && typeof value.createdAt === "string"
    && typeof value.updatedAt === "string";
}

export function getDocumentWordCount(content: string): number {
  const plainText = typeof DOMParser === "undefined"
    ? content.replace(/<[^>]*>/g, " ")
    : new DOMParser().parseFromString(content, "text/html").body.textContent ?? "";
  return plainText.trim().split(/\s+/).filter(Boolean).length;
}

export function sanitizeDocumentContent(html: string): string {
  const parsed = new DOMParser().parseFromString(html, "text/html");

  function copySafeNodes(nodes: NodeListOf<ChildNode>): Node[] {
    return Array.from(nodes).flatMap((node): Node[] => {
      if (node.nodeType === Node.TEXT_NODE) {
        return [document.createTextNode(node.textContent ?? "")];
      }
      if (node.nodeType !== Node.ELEMENT_NODE) return [];
      const element = node as Element;
      const children = copySafeNodes(element.childNodes);
      if (!allowedContentTags.has(element.tagName)) return children;
      const safeElement = document.createElement(element.tagName.toLowerCase());
      children.forEach((child) => safeElement.appendChild(child));
      return [safeElement];
    });
  }

  const wrapper = document.createElement("div");
  copySafeNodes(parsed.body.childNodes).forEach((node) => wrapper.appendChild(node));
  return wrapper.innerHTML;
}

function readDocumentList(): StoredDocument[] {
  const raw = localStorage.getItem(documentsStorageKey);
  if (raw === null) return [];
  const parsed: unknown = JSON.parse(raw);
  if (!Array.isArray(parsed) || !parsed.every(isStoredDocument)) {
    throw new Error("Saved document library has an invalid format.");
  }
  return parsed;
}

function writeDocumentList(documents: StoredDocument[]) {
  localStorage.setItem(documentsStorageKey, JSON.stringify(documents));
}

function migrateLegacyDraft(documents: StoredDocument[]): StoredDocument[] {
  if (documents.some((document) => document.id === "legacy-draft")) return documents;
  const rawDraft = localStorage.getItem(legacyDraftStorageKey);
  if (!rawDraft) return documents;

  const parsed: unknown = JSON.parse(rawDraft);
  if (!isRecord(parsed)) throw new Error("Saved draft has an invalid format.");

  const title = typeof parsed.title === "string" && parsed.title.trim()
    ? parsed.title.trim()
    : "Untitled document";
  const content = typeof parsed.html === "string" ? parsed.html : "";
  const timestamp = typeof parsed.savedAt === "string" ? parsed.savedAt : new Date().toISOString();
  const typeValue = parsed.documentType;
  const type: StoredDocumentType = typeof typeValue === "string"
    && ["official-letter", "report", "proposal", "meeting-minutes", "general-document"].includes(typeValue)
    ? typeValue as StoredDocumentType
    : "general-document";

  return [{
    id: "legacy-draft",
    title,
    type,
    language: parsed.language === "sw" ? "sw" : "en",
    content,
    typeset: false,
    status: "draft",
    wordCount: getDocumentWordCount(content),
    createdAt: timestamp,
    updatedAt: timestamp
  }, ...documents];
}

export function getDocuments(): StoredDocument[] {
  const documents = readDocumentList();
  const migrated = migrateLegacyDraft(documents);
  if (migrated !== documents) writeDocumentList(migrated);
  return migrated;
}

export function getDocumentById(id: string): StoredDocument | undefined {
  return getDocuments().find((document) => document.id === id);
}

export function saveDocument(input: Omit<StoredDocument, "createdAt" | "updatedAt"> & { updatedAt?: string }): StoredDocument {
  const documents = getDocuments();
  const existing = documents.find((document) => document.id === input.id);
  const now = new Date().toISOString();
  const content = sanitizeDocumentContent(input.content);
  const document: StoredDocument = {
    ...input,
    content,
    wordCount: getDocumentWordCount(content),
    createdAt: existing?.createdAt ?? now,
    updatedAt: input.updatedAt ?? now
  };
  writeDocumentList([document, ...documents.filter((item) => item.id !== document.id)]);
  return document;
}

export function duplicateDocument(id: string): StoredDocument {
  const documents = getDocuments();
  const original = documents.find((document) => document.id === id);
  if (!original) throw new Error("The document to duplicate could not be found.");
  const now = new Date().toISOString();
  const duplicate: StoredDocument = {
    ...original,
    id: crypto.randomUUID(),
    title: `${original.title} (Copy)`,
    status: "draft",
    createdAt: now,
    updatedAt: now
  };
  writeDocumentList([duplicate, ...documents]);
  return duplicate;
}

export function deleteDocument(id: string) {
  const documents = getDocuments();
  if (!documents.some((document) => document.id === id)) {
    throw new Error("The document to delete could not be found.");
  }
  writeDocumentList(documents.filter((document) => document.id !== id));
}

export function updateDocumentStatus(id: string, status: StoredDocumentStatus): StoredDocument {
  const documents = getDocuments();
  const existing = documents.find((document) => document.id === id);
  if (!existing) throw new Error("The document to update could not be found.");
  const updated = { ...existing, status, updatedAt: new Date().toISOString() };
  writeDocumentList(documents.map((document) => document.id === id ? updated : document));
  return updated;
}
