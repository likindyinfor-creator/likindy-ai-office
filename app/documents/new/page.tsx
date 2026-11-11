"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  ArrowLeft,
  ArrowDownToLine,
  Bold,
  Check,
  ChevronDown,
  CircleHelp,
  Clock3,
  FileDown,
  Heading2,
  Italic,
  Languages,
  List,
  ListOrdered,
  LoaderCircle,
  MessageSquareText,
  MoreHorizontal,
  Paperclip,
  LockKeyhole,
  UsersRound,
  History,
  Download,
  Trash2,
  RotateCcw,
  Share2,
  Send,
  Sparkles,
  Underline,
  WandSparkles,
  X
} from "lucide-react";
import { Header, Sidebar } from "../../dashboard/components";
import { documentTemplates } from "../../templates/data";
import { getDocumentWordCount, sanitizeDocumentContent } from "../storage";
import { useAuth } from "../../auth/AuthProvider";
import { apiDownload, apiPath, apiRequest, formatApiError, resourceData } from "../../lib/api";
import type { ApiAttachment, ApiDocument, ApiEnvelope, ApiGrant, ApiMember, ApiVersion } from "../../lib/api";

const documentTypes = [
  { value: "official-letter", label: "Official Letter", shortLabel: "Letter" },
  { value: "report", label: "Report", shortLabel: "Report" },
  { value: "proposal", label: "Proposal", shortLabel: "Proposal" },
  { value: "meeting-minutes", label: "Meeting Minutes", shortLabel: "Minutes" },
  { value: "general-document", label: "General Document", shortLabel: "Document" }
] as const;

const aiActions = [
  { label: "Improve Writing", prompt: "Improve the writing", icon: WandSparkles },
  { label: "Summarize", prompt: "Summarize this document", icon: AlignLeft },
  { label: "Expand Text", prompt: "Expand this text with more detail", icon: ArrowDownToLine },
  { label: "Rewrite", prompt: "Rewrite in a professional tone", icon: MessageSquareText },
  { label: "Translate", prompt: "Translate this document", icon: Languages },
  { label: "Correct Grammar", prompt: "Correct grammar and spelling", icon: Check }
] as const;

type DocumentType = typeof documentTypes[number]["value"];
type Language = "en" | "sw";
type Message = { role: "user" | "assistant"; text: string; suggestion?: string };

function isDocumentType(value: string): value is DocumentType {
  return documentTypes.some((type) => type.value === value);
}

function getDocumentType(value: DocumentType) {
  return documentTypes.find((type) => type.value === value) ?? documentTypes[4];
}

function formatIntegrationDate(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "—" : new Intl.DateTimeFormat("en-TZ", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function createMockSuggestion(action: string, text: string, language: Language) {
  const excerpt = text.trim().replace(/\s+/g, " ").slice(0, 220);
  const subject = excerpt || (language === "en"
    ? "This document is ready for your ideas and key details."
    : "Hati hii iko tayari kwa mawazo na maelezo yako muhimu.");

  if (action === "Summarize") {
    return language === "en"
      ? `Summary (demo): ${subject}${excerpt.length > 180 ? "…" : ""} The key next step is to confirm the objectives, owners, and timeline.`
      : `Muhtasari (majaribio): ${subject}${excerpt.length > 180 ? "…" : ""} Hatua inayofuata ni kuthibitisha malengo, wahusika na ratiba.`;
  }
  if (action === "Expand Text") {
    return language === "en"
      ? `${subject}\n\nTo move this forward, the team should agree on clear responsibilities, a practical timeline, and measurable outcomes. Regular progress reviews will help identify risks early and keep everyone aligned.`
      : `${subject}\n\nIli kutekeleza hili, timu inapaswa kukubaliana kuhusu majukumu, ratiba inayowezekana na matokeo yanayopimika. Mapitio ya mara kwa mara yatasaidia kutambua changamoto mapema.`;
  }
  if (action === "Translate") {
    return language === "en"
      ? `Tafsiri ya mfano kwa Kiswahili: ${subject}`
      : `Sample English translation: ${subject}`;
  }
  if (action === "Correct Grammar") {
    return language === "en"
      ? `Grammar review (demo): ${subject}\n\nThe wording has been presented as a polished example. Please verify names, dates, and organization-specific terms.`
      : `Mapitio ya sarufi (majaribio): ${subject}\n\nManeno yamewasilishwa kama mfano ulioboreshwa. Tafadhali hakiki majina, tarehe na istilahi za taasisi.`;
  }
  if (action === "Rewrite") {
    return language === "en"
      ? `Professional rewrite (demo):\n\n${subject}\n\nWe would be pleased to discuss the next steps and align on a practical way forward.`
      : `Uandishi upya wa kitaalamu (majaribio):\n\n${subject}\n\nTungependa kujadili hatua zinazofuata na kukubaliana kuhusu namna bora ya kuendelea.`;
  }
  return language === "en"
    ? `Polished draft (demo):\n\n${subject}\n\nPlease let us know if you would like to discuss any of these points further. We look forward to working together.`
    : `Rasimu iliyoboreshwa (majaribio):\n\n${subject}\n\nTafadhali tujulishe ikiwa ungependa kujadili zaidi hoja hizi. Tunatarajia kushirikiana nawe.`;
}

function ToolbarButton({
  label,
  children,
  onAction,
  pressed
}: {
  label: string;
  children: React.ReactNode;
  onAction: () => void;
  pressed?: boolean;
}) {
  return (
    <button
      aria-label={label}
      aria-pressed={pressed}
      className="doc-toolbar-button"
      onMouseDown={(event) => event.preventDefault()}
      onClick={onAction}
      title={label}
      type="button"
    >
      {children}
    </button>
  );
}

export default function NewDocumentPage() {
  const router = useRouter();
  const { status, user, workspaceId, workspaces, error: authError } = useAuth();
  const editorRef = useRef<HTMLDivElement>(null);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [headerSearch, setHeaderSearch] = useState("");
  const [documentType, setDocumentType] = useState<DocumentType>("general-document");
  const [language, setLanguage] = useState<Language>("en");
  const [title, setTitle] = useState("Untitled document");
  const [wordCount, setWordCount] = useState(0);
  const [messages, setMessages] = useState<Message[]>([]);
  const [instruction, setInstruction] = useState("");
  const [notice, setNotice] = useState("");
  const [busyAction, setBusyAction] = useState("");
  const [activeFormat, setActiveFormat] = useState("");
  const [draftSaved, setDraftSaved] = useState(false);
  const [exportMenuOpen, setExportMenuOpen] = useState(false);
  const [isTypesetDocument, setIsTypesetDocument] = useState(false);
  const [documentId, setDocumentId] = useState<string | null>(null);
  const [documentVisibility, setDocumentVisibility] = useState<ApiDocument["visibility"]>("private");
  const [contentFormat, setContentFormat] = useState<ApiDocument["contentFormat"]>("html");
  const [documentRecord, setDocumentRecord] = useState<ApiDocument | null>(null);
  const [readOnly, setReadOnly] = useState(false);
  const [loadingDocument, setLoadingDocument] = useState(false);
  const [savingDocument, setSavingDocument] = useState(false);
  const [workspaceError, setWorkspaceError] = useState("");
  const [versions, setVersions] = useState<ApiVersion[]>([]);
  const [attachments, setAttachments] = useState<ApiAttachment[]>([]);
  const [grants, setGrants] = useState<ApiGrant[]>([]);
  const [members, setMembers] = useState<ApiMember[]>([]);
  const [selectedMemberId, setSelectedMemberId] = useState("");
  const [grantPermission, setGrantPermission] = useState<"read" | "edit">("read");
  const [versionPreview, setVersionPreview] = useState<ApiVersion | null>(null);
  const [attachmentFile, setAttachmentFile] = useState<File | null>(null);
  const [managementError, setManagementError] = useState("");
  const [managementBusy, setManagementBusy] = useState(false);
  const [managementLoading, setManagementLoading] = useState(false);

  const selectedType = getDocumentType(documentType);
  const currentText = () => editorRef.current?.innerText.trim() ?? "";

  useEffect(() => {
    if (status !== "authenticated") return;
    if (!workspaceId) {
      setWorkspaceError("Select an active workspace before creating or opening documents.");
      return;
    }
    const controller = new AbortController();
    setDocumentRecord(null);
    setVersions([]);
    setAttachments([]);
    setGrants([]);
    setMembers([]);
    setWorkspaceError("");
    if (editorRef.current) editorRef.current.innerHTML = "";
    const params = new URLSearchParams(window.location.search);
    const requestedDocumentId = params.get("documentId");
    const requestedMode = params.get("mode");
    setReadOnly(requestedMode === "view");
    if (requestedDocumentId) {
      setLoadingDocument(true);
      void apiRequest<ApiEnvelope<ApiDocument>>(apiPath("workspaces", workspaceId, "documents", requestedDocumentId), { signal: controller.signal })
        .then((response) => {
          const record = resourceData(response);
          setDocumentRecord(record);
          setDocumentId(record.id);
          setTitle(record.title);
          setDocumentType(record.type);
          setLanguage(record.language);
          setDocumentVisibility(record.visibility);
          setContentFormat(record.contentFormat);
          setIsTypesetDocument(record.contentFormat === "latex");
          const content = record.contentFormat === "latex" ? record.latexSource ?? "" : record.contentHtml ?? "";
          if (editorRef.current) {
            if (record.contentFormat === "latex") editorRef.current.textContent = content;
            else editorRef.current.innerHTML = sanitizeDocumentContent(content);
            setWordCount(getDocumentWordCount(content));
          }
          setDraftSaved(true);
          setWorkspaceError("");
        })
        .catch((reason: unknown) => {
          if (reason instanceof Error && reason.name === "AbortError") return;
          setWorkspaceError(reason instanceof Error ? reason.message : "The document could not be loaded.");
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoadingDocument(false);
        });
      return () => controller.abort();
    }

    const templateId = params.get("template");
    if (templateId) {
      const selectedTemplate = documentTemplates.find((template) => template.id === templateId);
      if (selectedTemplate) {
        setDocumentType(selectedTemplate.documentType);
        setTitle(selectedTemplate.name);
        setLanguage(selectedTemplate.language === "English" ? "en" : "sw");
        setIsTypesetDocument(selectedTemplate.format === "latex");
        if (editorRef.current) {
          editorRef.current.innerHTML = sanitizeDocumentContent(selectedTemplate.formattedContent ?? selectedTemplate.content);
          setWordCount(countWords(editorRef.current.innerText));
        }
        return;
      }
    }

    const requestedType = params.get("type");
    if (requestedType && isDocumentType(requestedType)) {
      const selected = getDocumentType(requestedType);
      setDocumentType(requestedType);
      setTitle(`Untitled ${selected.shortLabel.toLowerCase()}`);
      return;
    }

  }, [status, workspaceId]);

  const countWords = (text: string) => text.trim().split(/\s+/).filter(Boolean).length;

  function updateEditorStats() {
    const text = editorRef.current?.innerText ?? "";
    setWordCount(countWords(text));
  }

  function runFormatting(command: string, value?: string) {
    if (readOnly) return;
    editorRef.current?.focus();
    document.execCommand(command, false, value);
    setDraftSaved(false);
    updateEditorStats();
    setActiveFormat(command);
  }

  async function saveDraft() {
    if (!workspaceId || readOnly) return;
    try {
      setSavingDocument(true);
      setWorkspaceError("");
      const content = sanitizeDocumentContent(editorRef.current?.innerHTML ?? "");
      const payload = {
        title: title.trim() || "Untitled document",
        type: documentType,
        language,
        visibility: documentVisibility,
        status: documentRecord?.status ?? "draft",
        content_format: contentFormat,
        ...(contentFormat === "latex"
          ? { latex_source: editorRef.current?.innerText ?? "" }
          : { content_html: content }),
        ...(documentId ? { change_summary: "Updated from the document editor" } : {})
      };
      const path = documentId
        ? apiPath("workspaces", workspaceId, "documents", documentId)
        : apiPath("workspaces", workspaceId, "documents");
      const response = await apiRequest<ApiEnvelope<ApiDocument>>(path, {
        method: documentId ? "PATCH" : "POST",
        body: payload
      });
      const savedDocument = resourceData(response);
      setDocumentRecord(savedDocument);
      setDocumentId(savedDocument.id);
      setTitle(savedDocument.title);
      setDraftSaved(true);
      setNotice("Document saved securely to your workspace.");
      router.replace(`/documents/new?documentId=${encodeURIComponent(savedDocument.id)}`);
    } catch (reason) {
      setWorkspaceError(formatApiError(reason, "The document could not be saved."));
    } finally {
      setSavingDocument(false);
    }
  }

  function exportText() {
    const text = currentText();
    if (!text) {
      setNotice("Add some document content before exporting.");
      return;
    }
    const fileContent = `${title}\n\n${text}`;
    const file = new Blob([fileContent], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(file);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `${title.trim().replace(/[<>:"/\\|?*\u0000-\u001F]/g, "-") || "document"}.txt`;
    anchor.click();
    URL.revokeObjectURL(url);
    setNotice("Text file exported. Rich formatting and PDF export are not included in this demo.");
  }

  function applySuggestion(suggestion: string) {
    if (readOnly || !editorRef.current) return;
    editorRef.current.innerText = suggestion;
    editorRef.current.focus();
    setDraftSaved(false);
    updateEditorStats();
    setNotice("Demo suggestion added to the document. Review it before using.");
  }

  function runAssistantAction(action: string, userPrompt?: string) {
    if (busyAction) return;
    const documentText = currentText();
    const prompt = userPrompt ?? action;
    setMessages((current) => [...current, { role: "user", text: prompt }]);
    setBusyAction(action);

    window.setTimeout(() => {
      const suggestion = createMockSuggestion(action, documentText, language);
      setMessages((current) => [
        ...current,
        {
          role: "assistant",
          text: `Demo response — no AI service is connected. This sample is based on the text currently in your editor.`,
          suggestion
        }
      ]);
      setBusyAction("");
    }, 550);
  }

  function submitInstruction(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const prompt = instruction.trim();
    if (!prompt || busyAction) return;
    const normalizedPrompt = prompt.toLowerCase();
    const action = /summar|brief|key points/.test(normalizedPrompt) ? "Summarize"
      : /translate|kiswahili|swahili/.test(normalizedPrompt) ? "Translate"
        : /expand|more detail|elaborate/.test(normalizedPrompt) ? "Expand Text"
          : /rewrite|professional tone/.test(normalizedPrompt) ? "Rewrite"
            : /grammar|proofread|spelling/.test(normalizedPrompt) ? "Correct Grammar"
              : "Improve Writing";
    const documentText = currentText();
    setInstruction("");
    setMessages((current) => [...current, { role: "user", text: prompt }]);
    setBusyAction("custom");
    window.setTimeout(() => {
      setMessages((current) => [
        ...current,
        {
          role: "assistant",
          text: `Demo response — no AI service is connected. Your instruction was interpreted locally as ${action.toLowerCase()}.`,
          suggestion: createMockSuggestion(action, documentText, language)
        }
      ]);
      setBusyAction("");
    }, 400);
  }

  useEffect(() => {
    if (status !== "authenticated" || !workspaceId || !documentId) return;
    const root = apiPath("workspaces", workspaceId, "documents", documentId);
    const controller = new AbortController();
    const activeUserId = user?.id;
    const isOwner = documentRecord?.ownerId === activeUserId && Boolean(activeUserId);
    setManagementLoading(true);
    setManagementError("");
    const sharingRequest = isOwner
      ? Promise.all([
        apiRequest<ApiEnvelope<ApiGrant[]>>(`${root}/sharing`, { signal: controller.signal }),
        apiRequest<ApiEnvelope<ApiMember[]>>(apiPath("workspaces", workspaceId, "members"), { signal: controller.signal })
      ])
      : Promise.resolve(null);
    void Promise.allSettled([
      apiRequest<ApiEnvelope<ApiVersion[]>>(`${root}/versions`, { signal: controller.signal }),
      apiRequest<ApiEnvelope<ApiAttachment[]>>(`${root}/attachments`, { signal: controller.signal }),
      sharingRequest
    ]).then(([versionResult, attachmentResult, sharingResult]) => {
      if (controller.signal.aborted) return;
      const failures: string[] = [];
      if (versionResult.status === "fulfilled") setVersions(resourceData(versionResult.value));
      else failures.push(`Version history: ${versionResult.reason instanceof Error ? versionResult.reason.message : "could not be loaded."}`);
      if (attachmentResult.status === "fulfilled") setAttachments(resourceData(attachmentResult.value));
      else failures.push(`Attachments: ${attachmentResult.reason instanceof Error ? attachmentResult.reason.message : "could not be loaded."}`);
      if (sharingResult.status === "fulfilled" && sharingResult.value) {
        const [grantsResponse, membersResponse] = sharingResult.value;
        setGrants(resourceData(grantsResponse));
        setMembers(resourceData(membersResponse).filter((member) => member.status === "active" && member.user?.id !== activeUserId));
      } else if (sharingResult.status === "rejected") {
        failures.push(`Sharing management: ${sharingResult.reason instanceof Error ? sharingResult.reason.message : "could not be loaded."}`);
      }
      setManagementError(failures.join(" "));
    }).finally(() => {
      if (!controller.signal.aborted) setManagementLoading(false);
    });
    return () => controller.abort();
  }, [status, workspaceId, documentId, documentRecord?.ownerId, user?.id]);

  async function reloadDocumentLists() {
    if (!workspaceId || !documentId) return;
    const root = apiPath("workspaces", workspaceId, "documents", documentId);
    const [versionResponse, attachmentResponse] = await Promise.all([
      apiRequest<ApiEnvelope<ApiVersion[]>>(`${root}/versions`),
      apiRequest<ApiEnvelope<ApiAttachment[]>>(`${root}/attachments`)
    ]);
    setVersions(resourceData(versionResponse));
    setAttachments(resourceData(attachmentResponse));
  }

  async function grantDocumentAccess(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!workspaceId || !documentId || !selectedMemberId) return;
    setManagementBusy(true);
    setManagementError("");
    try {
      await apiRequest(apiPath("workspaces", workspaceId, "documents", documentId, "sharing"), {
        method: "POST",
        body: { user_id: selectedMemberId, permission: grantPermission }
      });
      const response = await apiRequest<ApiEnvelope<ApiGrant[]>>(apiPath("workspaces", workspaceId, "documents", documentId, "sharing"));
      setGrants(resourceData(response));
      setSelectedMemberId("");
    } catch (reason) {
      setManagementError(formatApiError(reason, "The access grant could not be created."));
    } finally {
      setManagementBusy(false);
    }
  }

  async function updateGrant(userId: string, permission: "read" | "edit") {
    if (!workspaceId || !documentId) return;
    setManagementBusy(true);
    setManagementError("");
    try {
      await apiRequest(apiPath("workspaces", workspaceId, "documents", documentId, "sharing", userId), {
        method: "PATCH",
        body: { permission }
      });
      setGrants((current) => current.map((grant) => grant.userId === userId ? { ...grant, permission } : grant));
    } catch (reason) {
      setManagementError(formatApiError(reason, "The permission could not be updated."));
    } finally {
      setManagementBusy(false);
    }
  }

  async function revokeGrant(userId: string) {
    if (!workspaceId || !documentId) return;
    setManagementBusy(true);
    setManagementError("");
    try {
      await apiRequest(apiPath("workspaces", workspaceId, "documents", documentId, "sharing", userId), { method: "DELETE" });
      setGrants((current) => current.filter((grant) => grant.userId !== userId));
    } catch (reason) {
      setManagementError(formatApiError(reason, "Access could not be revoked."));
    } finally {
      setManagementBusy(false);
    }
  }

  async function viewVersion(versionNumber: number) {
    if (!workspaceId || !documentId) return;
    try {
      const response = await apiRequest<ApiEnvelope<ApiVersion>>(apiPath("workspaces", workspaceId, "documents", documentId, "versions", String(versionNumber)));
      setVersionPreview(resourceData(response));
    } catch (reason) {
      setManagementError(formatApiError(reason, "The requested version could not be loaded."));
    }
  }

  async function restoreVersion(versionNumber: number) {
    if (!workspaceId || !documentId) return;
    setManagementBusy(true);
    setManagementError("");
    try {
      const response = await apiRequest<ApiEnvelope<ApiDocument>>(apiPath("workspaces", workspaceId, "documents", documentId, "versions", String(versionNumber), "restore"), { method: "POST" });
      const restored = resourceData(response);
      setDocumentRecord(restored);
      setTitle(restored.title);
      setContentFormat(restored.contentFormat);
      setIsTypesetDocument(restored.contentFormat === "latex");
      if (editorRef.current) {
        if (restored.contentFormat === "latex") editorRef.current.textContent = restored.latexSource ?? "";
        else editorRef.current.innerHTML = sanitizeDocumentContent(restored.contentHtml ?? "");
      }
      setDraftSaved(true);
      await reloadDocumentLists();
      setNotice(`Version ${versionNumber} restored as a new version.`);
    } catch (reason) {
      setManagementError(formatApiError(reason, "The version could not be restored."));
    } finally {
      setManagementBusy(false);
    }
  }

  async function uploadAttachment(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!workspaceId || !documentId || !attachmentFile) return;
    const extension = attachmentFile.name.split(".").pop()?.toLowerCase();
    if (!extension || !["pdf", "png", "jpg", "jpeg", "txt"].includes(extension) || attachmentFile.size > 10 * 1024 * 1024) {
      setManagementError("Choose a PDF, PNG, JPEG, or plain-text file no larger than 10 MB.");
      return;
    }
    const form = event.currentTarget;
    setManagementBusy(true);
    setManagementError("");
    try {
      const formData = new FormData();
      formData.append("file", attachmentFile);
      await apiRequest(apiPath("workspaces", workspaceId, "documents", documentId, "attachments"), { method: "POST", formData });
      setAttachmentFile(null);
      form.reset();
      const response = await apiRequest<ApiEnvelope<ApiAttachment[]>>(apiPath("workspaces", workspaceId, "documents", documentId, "attachments"));
      setAttachments(resourceData(response));
    } catch (reason) {
      setManagementError(formatApiError(reason, "The attachment could not be uploaded."));
    } finally {
      setManagementBusy(false);
    }
  }

  async function downloadAttachment(attachment: ApiAttachment) {
    if (!workspaceId || !documentId) return;
    try {
      const { blob, filename } = await apiDownload(apiPath("workspaces", workspaceId, "documents", documentId, "attachments", attachment.id, "download"));
      const objectUrl = URL.createObjectURL(blob);
      const anchor = window.document.createElement("a");
      anchor.href = objectUrl;
      anchor.download = filename;
      anchor.click();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    } catch (reason) {
      setManagementError(formatApiError(reason, "The attachment could not be downloaded."));
    }
  }

  async function deleteAttachment(attachment: ApiAttachment) {
    if (!workspaceId || !documentId || !window.confirm(`Delete “${attachment.originalFilename}”?`)) return;
    setManagementBusy(true);
    setManagementError("");
    try {
      await apiRequest(apiPath("workspaces", workspaceId, "documents", documentId, "attachments", attachment.id), { method: "DELETE" });
      setAttachments((current) => current.filter((item) => item.id !== attachment.id));
    } catch (reason) {
      setManagementError(formatApiError(reason, "The attachment could not be deleted."));
    } finally {
      setManagementBusy(false);
    }
  }

  async function softDeleteDocument() {
    if (!workspaceId || !documentRecord || !window.confirm(`Move “${documentRecord.title}” to recently deleted?`)) return;
    try {
      await apiRequest(apiPath("workspaces", workspaceId, "documents", documentRecord.id), { method: "DELETE" });
      try {
        sessionStorage.setItem("likindy-last-deleted-document", JSON.stringify({
          id: documentRecord.id,
          title: documentRecord.title,
          workspaceId
        }));
      } catch {
        setNotice("The document was deleted, but the browser could not preserve the restore shortcut.");
      }
      router.push("/documents");
    } catch (reason) {
      setWorkspaceError(formatApiError(reason, "The document could not be deleted."));
    }
  }

  const wordLabel = useMemo(() => wordCount === 1 ? "word" : "words", [wordCount]);

  if (status === "loading") {
    return <main className="integration-auth-required" role="status">Checking your secure workspace session…</main>;
  }
  if (status === "unauthenticated") {
    return (
      <main className="integration-auth-required">
        <section><LockKeyhole size={24} /><h1>Sign in to manage documents</h1><p>{authError || "Your secure session is not active. Sign in to continue."}</p><Link className="library-primary-button" href="/">Go to sign in</Link></section>
      </main>
    );
  }

  return (
    <main className="dashboard-shell doc-shell">
      <Sidebar
        activeItem={null}
        collapsed={sidebarCollapsed}
        mobileOpen={mobileNavOpen}
        onCloseMobile={() => setMobileNavOpen(false)}
        onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
      />
      <div className={`dash-main${sidebarCollapsed ? " sidebar-collapsed" : ""}`}>
        <Header
          mobileNavOpen={mobileNavOpen}
          onOpenMobileNav={() => setMobileNavOpen(true)}
          onSearchChange={setHeaderSearch}
          search={headerSearch}
        />
        <div className="doc-page-content">
          <div className="doc-page-heading">
            <div className="doc-heading-main">
              <button className="doc-back-link" onClick={() => router.push("/documents")} type="button">
                <ArrowLeft size={16} /> Back to My Documents
              </button>
              <div className="doc-title-line">
                <div>
                  <p className="doc-overline">DOCUMENT WORKSPACE</p>
                  <h1>{readOnly ? "View document" : documentId ? "Edit document" : "Create a document"}</h1>
                </div>
                <div className={`doc-save-status${draftSaved ? " is-saved" : ""}`}><span />{draftSaved ? "Saved to workspace" : "Unsaved changes"}</div>
              </div>
            </div>
            <div className="doc-page-actions">
              <div className="doc-export-wrap">
                <button aria-expanded={exportMenuOpen} className="doc-secondary-button" onClick={() => setExportMenuOpen(!exportMenuOpen)} type="button">
                  <FileDown size={16} /><span>Export</span><ChevronDown size={14} />
                </button>
                {exportMenuOpen && (
                  <div className="doc-export-menu" role="menu">
                    <button onClick={() => { setExportMenuOpen(false); exportText(); }} role="menuitem" type="button">Download plain text (.txt)</button>
                    <button onClick={() => { setExportMenuOpen(false); setNotice("PDF export is not implemented in this demo. You can download a plain text copy."); }} role="menuitem" type="button">Export as PDF <span>Demo</span></button>
                  </div>
                )}
              </div>
              {!readOnly && <button className="doc-save-button" disabled={savingDocument || !workspaceId} onClick={() => void saveDraft()} type="button">
                <Check size={16} /><span>{savingDocument ? "Saving…" : documentId ? "Save changes" : "Save document"}</span>
              </button>}
              {documentRecord?.ownerId === user?.id && <button aria-label="Move document to recently deleted" className="doc-secondary-button" onClick={() => void softDeleteDocument()} type="button"><Trash2 size={15} /><span>Delete</span></button>}
            </div>
          </div>

          {status === "authenticated" && workspaces.length === 0 && <div className="library-error" role="alert">Your account has no active workspace. Ask a workspace owner to invite you.</div>}
          {loadingDocument && <div className="integration-notice"><span>Loading document securely…</span></div>}
          {workspaceError && <div className="library-error" role="alert">{workspaceError}</div>}
          {notice && (
            <div className="doc-notice" role="status">
              <span>{notice}</span>
              <button aria-label="Dismiss notification" onClick={() => setNotice("")} type="button"><X size={15} /></button>
            </div>
          )}

          <div className="doc-workspace-grid">
            <section aria-label="Document editor" className="doc-editor-panel">
              <div className="doc-metadata">
                <label className="doc-field doc-title-field">
                  <span>DOCUMENT TITLE</span>
                  <input
                    aria-label="Document title"
                    maxLength={120}
                    disabled={readOnly}
                    onChange={(event) => { setTitle(event.target.value); setDraftSaved(false); }}
                    placeholder="Enter a document title"
                    value={title}
                  />
                </label>
                <label className="doc-field">
                  <span>DOCUMENT TYPE</span>
                  <select
                    aria-label="Document type"
                    disabled={readOnly}
                    onChange={(event) => {
                      const value = event.target.value;
                      if (!isDocumentType(value)) return;
                      const nextType = value;
                      setDocumentType(nextType);
                      setDraftSaved(false);
                      if (title.startsWith("Untitled ")) {
                        setTitle(`Untitled ${getDocumentType(nextType).shortLabel.toLowerCase()}`);
                      }
                    }}
                    value={documentType}
                  >
                    {documentTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                  </select>
                </label>
                <label className="doc-field doc-language-field">
                  <span>LANGUAGE</span>
                  <select aria-label="Document language" disabled={readOnly} onChange={(event) => {
                    if (event.target.value !== "en" && event.target.value !== "sw") return;
                    setLanguage(event.target.value);
                    setDraftSaved(false);
                  }} value={language}>
                    <option value="en">English</option>
                    <option value="sw">Kiswahili</option>
                  </select>
                </label>
                <label className="doc-field">
                  <span>ACCESS</span>
                  <select aria-label="Document visibility" disabled={readOnly} onChange={(event) => {
                    if (event.target.value === "private" || event.target.value === "workspace") {
                      setDocumentVisibility(event.target.value);
                      setDraftSaved(false);
                    }
                  }} value={documentVisibility}>
                    <option value="private">Private</option>
                    <option value="workspace">Workspace</option>
                  </select>
                </label>
              </div>
              <div className="integration-privacy-note">
                {documentVisibility === "private" ? <LockKeyhole size={14} /> : <UsersRound size={14} />}
                <span>{documentVisibility === "private" ? "Private documents are visible only to you and people explicitly granted access." : "Workspace visibility allows access according to the backend workspace policy."}</span>
              </div>
              {isTypesetDocument && (
                <div className="doc-template-format-note">
                  <Languages size={14} />
                  <span><strong>LaTeX-inspired typeset layout</strong> · Edit your document in its formatted view.</span>
                  <span className="doc-latex-tag">TYPESET</span>
                </div>
              )}

              <div aria-label="Text formatting" className="doc-toolbar" role="toolbar">
                  <div className="doc-toolbar-group">
                    <ToolbarButton label="Bold" onAction={() => runFormatting("bold")} pressed={activeFormat === "bold"}><Bold size={16} /></ToolbarButton>
                    <ToolbarButton label="Italic" onAction={() => runFormatting("italic")} pressed={activeFormat === "italic"}><Italic size={16} /></ToolbarButton>
                    <ToolbarButton label="Underline" onAction={() => runFormatting("underline")} pressed={activeFormat === "underline"}><Underline size={16} /></ToolbarButton>
                  </div>
                  <span className="doc-toolbar-divider" />
                  <label className="doc-heading-select">
                    <span className="sr-only">Text style</span>
                    <Heading2 size={16} />
                    <select aria-label="Text style" defaultValue="p" onChange={(event) => runFormatting("formatBlock", event.target.value)}>
                      <option value="p">Paragraph</option>
                      <option value="h1">Heading 1</option>
                      <option value="h2">Heading 2</option>
                      <option value="h3">Heading 3</option>
                    </select>
                    <ChevronDown size={13} />
                  </label>
                  <span className="doc-toolbar-divider" />
                  <div className="doc-toolbar-group">
                    <ToolbarButton label="Bulleted list" onAction={() => runFormatting("insertUnorderedList")}><List size={16} /></ToolbarButton>
                    <ToolbarButton label="Numbered list" onAction={() => runFormatting("insertOrderedList")}><ListOrdered size={16} /></ToolbarButton>
                  </div>
                  <span className="doc-toolbar-divider" />
                  <div className="doc-toolbar-group">
                    <ToolbarButton label="Align left" onAction={() => runFormatting("justifyLeft")}><AlignLeft size={16} /></ToolbarButton>
                    <ToolbarButton label="Align center" onAction={() => runFormatting("justifyCenter")}><AlignCenter size={16} /></ToolbarButton>
                    <ToolbarButton label="Align right" onAction={() => runFormatting("justifyRight")}><AlignRight size={16} /></ToolbarButton>
                  </div>
                  <span className="doc-toolbar-spacer" />
                  <button aria-label="More formatting options" className="doc-toolbar-button doc-more-button" onClick={() => setNotice("Additional formatting options are not included in this demo.")} type="button"><MoreHorizontal size={18} /></button>
              </div>

              <div className="doc-paper-wrap">
                <div className="doc-paper">
                  <div
                    aria-label={`${selectedType.label} content`}
                    aria-multiline="true"
                    className={`doc-editable${isTypesetDocument ? " is-typeset-document" : ""}`}
                    contentEditable={!readOnly}
                    data-placeholder={language === "en" ? "Start writing your document here, or ask the assistant to help you get started..." : "Anza kuandika hati yako hapa, au omba msaidizi akusaidie..."}
                    lang={language}
                    onInput={() => { setDraftSaved(false); updateEditorStats(); }}
                    ref={editorRef}
                    role="textbox"
                    suppressContentEditableWarning
                  />
                  <div className="doc-paper-footer"><span>{selectedType.label}</span><span>1</span></div>
                </div>
              </div>
              <div className="doc-editor-footer">
                <span><span className={`doc-status-dot${draftSaved ? "" : " is-unsaved"}`} />{draftSaved ? "Saved to workspace" : readOnly ? "Read-only view" : "Unsaved changes"}</span>
                <span>{wordCount} {wordLabel} <i /> <Clock3 size={13} /> Just now</span>
              </div>
            </section>

            <aside aria-label="AI writing assistant" className="doc-assistant-panel">
              <div className="doc-assistant-header">
                <div className="doc-assistant-title">
                  <span className="doc-assistant-logo"><Sparkles size={17} /></span>
                  <div><h2>AI Assistant</h2><span>Your writing partner</span></div>
                </div>
                <button aria-label="Assistant help" className="doc-assistant-help" onClick={() => setNotice("AI suggestions are mock examples only. No content is sent to an AI service.")} type="button"><CircleHelp size={17} /></button>
              </div>
              <div className="doc-demo-banner">
                <Sparkles size={14} />
                <span><strong>Demo assistant</strong> · No AI service connected</span>
              </div>

              <div aria-label="AI writing actions" className="doc-ai-actions">
                {aiActions.map(({ label, prompt, icon: Icon }) => (
                  <button disabled={Boolean(busyAction) || readOnly} key={label} onClick={() => runAssistantAction(label, prompt)} type="button">
                    <Icon size={15} /><span>{label}</span>
                  </button>
                ))}
              </div>

              <div aria-live="polite" aria-relevant="additions" className="doc-chat">
                {messages.length === 0 ? (
                  <div className="doc-assistant-empty">
                    <span className="doc-empty-sparkle"><Sparkles size={20} /></span>
                    <strong>How can I help with your writing?</strong>
                    <p>Select an action above or tell me what you need. Suggestions are simulated for this demo.</p>
                  </div>
                ) : (
                  messages.map((message, index) => (
                    <div className={`doc-chat-message is-${message.role}`} key={`${message.role}-${index}`}>
                      <span className="doc-chat-author">{message.role === "user" ? "You" : "LIKINDY AI · DEMO"}</span>
                      <p>{message.text}</p>
                      {message.suggestion && (
                        <div className="doc-suggestion-card">
                          <span>MOCK SUGGESTION</span>
                          <p>{message.suggestion}</p>
                          {!readOnly && <button onClick={() => applySuggestion(message.suggestion ?? "")} type="button"><Check size={14} /> Use suggestion</button>}
                        </div>
                      )}
                    </div>
                  ))
                )}
                {busyAction && <div className="doc-thinking"><LoaderCircle size={15} /> Preparing a demo response…</div>}
              </div>

              <div className="doc-chat-bottom">
                <div className="doc-assistant-disclaimer">Demo only. Review all suggestions before use.</div>
                <form className="doc-chat-form" onSubmit={submitInstruction}>
                  <label className="sr-only" htmlFor="assistant-instruction">Ask the assistant for help</label>
                  <textarea
                    id="assistant-instruction"
                    onChange={(event) => setInstruction(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === "Enter" && !event.shiftKey) {
                        event.preventDefault();
                        event.currentTarget.form?.requestSubmit();
                      }
                    }}
                    placeholder="Ask for help with your document..."
                    rows={2}
                    value={instruction}
                  />
                  <div className="doc-chat-form-actions">
                    <button aria-label="Attachments are not available in this demo" className="doc-attach-button" onClick={() => setNotice("Attachments are not available in this demo.")} type="button"><Paperclip size={15} /></button>
                    <span>Enter to send</span>
                    <button aria-label="Send instruction" className="doc-send-button" disabled={!instruction.trim() || Boolean(busyAction)} type="submit"><Send size={15} /></button>
                  </div>
                </form>
              </div>
            </aside>
          </div>
          {documentId && (
            <section aria-label="Document access and history" className="integration-management-grid">
              <article className="integration-panel">
                <div className="integration-panel-heading"><div><span className="integration-panel-icon"><Share2 size={17} /></span><h2>Sharing & access</h2></div><span>Owner-controlled</span></div>
                {documentRecord?.ownerId === user?.id ? (
                  <>
                    {managementLoading ? <p className="integration-subtle">Loading sharing details…</p> : <form className="integration-share-form" onSubmit={(event) => void grantDocumentAccess(event)}>
                      <label><span>Workspace member</span><select aria-label="Workspace member to share with" onChange={(event) => setSelectedMemberId(event.target.value)} required value={selectedMemberId}><option value="">Select a member</option>{members.map((member) => member.user && <option key={member.user.id} value={member.user.id}>{member.user.name} · {member.user.email}</option>)}</select></label>
                      <label><span>Permission</span><select aria-label="Permission to grant" onChange={(event) => setGrantPermission(event.target.value as "read" | "edit")} value={grantPermission}><option value="read">Read</option><option value="edit">Edit</option></select></label>
                      <button className="library-primary-button" disabled={managementBusy || !members.length} type="submit">Grant access</button>
                    </form>}
                    {!managementLoading && members.length === 0 && <p className="integration-subtle">No other active workspace members are available to share with.</p>}
                    <div className="integration-record-list">
                      {managementLoading ? null : grants.length === 0 ? <p className="integration-subtle">No active grants. This document remains private to you.</p> : grants.map((grant) => (
                        <div className="integration-record" key={grant.userId}>
                          <div><strong>{grant.name}</strong><small>{grant.email}</small></div>
                          <select aria-label={`Permission for ${grant.name}`} disabled={managementBusy} onChange={(event) => void updateGrant(grant.userId, event.target.value as "read" | "edit")} value={grant.permission}><option value="read">Read</option><option value="edit">Edit</option></select>
                          <button aria-label={`Revoke access for ${grant.name}`} disabled={managementBusy} onClick={() => void revokeGrant(grant.userId)} type="button"><Trash2 size={15} /></button>
                        </div>
                      ))}
                    </div>
                  </>
                ) : <p className="integration-subtle">Only the document owner can manage sharing. Workspace administrators do not bypass private-document access.</p>}
              </article>

              <article className="integration-panel">
                <div className="integration-panel-heading"><div><span className="integration-panel-icon"><History size={17} /></span><h2>Version history</h2></div><span>Newest first</span></div>
                {managementLoading ? <p className="integration-subtle">Loading version history…</p> : versions.length === 0 ? <p className="integration-subtle">No saved versions are available.</p> : (
                  <div className="integration-record-list">
                    {versions.map((version) => (
                      <div className="integration-record" key={version.id}>
                        <div><strong>Version {version.versionNumber} · {version.title}</strong><small>{formatIntegrationDate(version.createdAt)}{version.changeSummary ? ` · ${version.changeSummary}` : ""}</small></div>
                        <button onClick={() => void viewVersion(version.versionNumber)} type="button">View</button>
                        {!readOnly && <button disabled={managementBusy || managementLoading} onClick={() => void restoreVersion(version.versionNumber)} type="button"><RotateCcw size={14} /> Restore</button>}
                      </div>
                    ))}
                  </div>
                )}
                {versionPreview && (
                  <div className="integration-version-preview">
                    <div><strong>Version {versionPreview.versionNumber}</strong><button aria-label="Close version preview" onClick={() => setVersionPreview(null)} type="button"><X size={15} /></button></div>
                    <h3>{versionPreview.title}</h3>
                    <div dangerouslySetInnerHTML={{ __html: sanitizeDocumentContent(versionPreview.contentHtml ?? "") }} />
                  </div>
                )}
              </article>

              <article className="integration-panel">
                <div className="integration-panel-heading"><div><span className="integration-panel-icon"><Paperclip size={17} /></span><h2>Attachments</h2></div><span>Private files</span></div>
                {!readOnly && <form className="integration-upload-form" onSubmit={(event) => void uploadAttachment(event)}>
                  <label><span>Upload PDF, PNG, JPEG or TXT · Up to 10 MB</span><input accept=".pdf,.png,.jpg,.jpeg,.txt,application/pdf,image/png,image/jpeg,text/plain" onChange={(event) => setAttachmentFile(event.target.files?.[0] ?? null)} required type="file" /></label>
                  <button className="library-primary-button" disabled={managementBusy || !attachmentFile} type="submit">Upload attachment</button>
                </form>}
                {managementLoading ? <p className="integration-subtle">Loading attachments…</p> : attachments.length === 0 ? <p className="integration-subtle">No attachments on this document.</p> : (
                  <div className="integration-record-list">
                    {attachments.map((attachment) => (
                      <div className="integration-record" key={attachment.id}>
                        <div><strong>{attachment.originalFilename}</strong><small>{attachment.mimeType} · {(attachment.size / 1024).toFixed(0)} KB</small></div>
                        <button aria-label={`Download ${attachment.originalFilename}`} onClick={() => void downloadAttachment(attachment)} type="button"><Download size={15} /></button>
                        {!readOnly && <button aria-label={`Delete ${attachment.originalFilename}`} disabled={managementBusy} onClick={() => void deleteAttachment(attachment)} type="button"><Trash2 size={15} /></button>}
                      </div>
                    ))}
                  </div>
                )}
                <p className="integration-subtle">Downloads are served through authenticated API requests; storage paths and public file URLs are never exposed.</p>
              </article>
            </section>
          )}
          {managementError && <div className="library-error integration-error" role="alert">{managementError}</div>}
          <footer className="doc-workspace-footer">Likindy AI Office <span /> Private documents stay private unless access is explicitly granted.</footer>
        </div>
      </div>
    </main>
  );
}
