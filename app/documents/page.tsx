"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  ArrowDownAZ, ArrowUpDown, FilePlus2, FileText, FolderOpen, Search,
  ShieldCheck, Trash2, RotateCcw, LockKeyhole, UsersRound
} from "lucide-react";
import { Header, Sidebar } from "../dashboard/components";
import { useAuth } from "../auth/AuthProvider";
import { apiPath, apiRequest, formatApiError, resourceData } from "../lib/api";
import type { ApiDocument, ApiEnvelope } from "../lib/api";

const typeLabels: Record<ApiDocument["type"], string> = {
  "official-letter": "Official Letter",
  report: "Report",
  proposal: "Proposal",
  "meeting-minutes": "Meeting Minutes",
  "general-document": "General Document"
};
const deletedDocumentKey = "likindy-last-deleted-document";

function formatDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("en-TZ", { day: "numeric", month: "short", year: "numeric" }).format(date);
}

export default function MyDocumentsPage() {
  const router = useRouter();
  const { status, user, workspaceId, workspaces, error: authError } = useAuth();
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [type, setType] = useState("");
  const [language, setLanguage] = useState("");
  const [documentStatus, setDocumentStatus] = useState("");
  const [visibility, setVisibility] = useState("");
  const [createdFrom, setCreatedFrom] = useState("");
  const [createdTo, setCreatedTo] = useState("");
  const [sort, setSort] = useState<"updated_at" | "created_at" | "title">("updated_at");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [documents, setDocuments] = useState<ApiDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [deletedDocument, setDeletedDocument] = useState<{ id: string; title: string; workspaceId: string } | null>(null);

  const loadDocuments = useCallback(async (signal?: AbortSignal) => {
    if (!workspaceId) {
      setDocuments([]);
      setTotal(0);
      setLoading(false);
      return;
    }
    const params = new URLSearchParams({ per_page: "20", page: String(page), sort });
    if (search.trim()) params.set("search", search.trim());
    if (type) params.set("type", type);
    if (language) params.set("language", language);
    if (documentStatus) params.set("status", documentStatus);
    if (visibility) params.set("visibility", visibility);
    if (createdFrom) params.set("created_from", createdFrom);
    if (createdTo) params.set("created_to", createdTo);
    setLoading(true);
    try {
      const envelope = await apiRequest<ApiEnvelope<ApiDocument[]>>(
        `${apiPath("workspaces", workspaceId, "documents")}?${params.toString()}`,
        { signal }
      );
      setDocuments(resourceData(envelope));
      setTotal(envelope.meta?.total ?? resourceData(envelope).length);
      setLastPage(envelope.meta?.last_page ?? 1);
      setError("");
    } catch (reason) {
      if (reason instanceof Error && reason.name === "AbortError") return;
      setError(formatApiError(reason, "Documents could not be loaded."));
      setDocuments([]);
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  }, [workspaceId, page, sort, search, type, language, documentStatus, visibility, createdFrom, createdTo]);

  useEffect(() => {
    if (status !== "authenticated") {
      setLoading(status === "loading");
      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(() => void loadDocuments(controller.signal), search ? 250 : 0);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [status, loadDocuments, search]);

  useEffect(() => {
    try {
      const raw = sessionStorage.getItem(deletedDocumentKey);
      if (raw) setDeletedDocument(JSON.parse(raw) as { id: string; title: string; workspaceId: string });
    } catch (reason) {
      console.error("Could not read the recent document restore reference.", reason);
    }
  }, []);

  function resetPage() {
    setPage(1);
  }

  async function deleteDocument(document: ApiDocument) {
    if (!workspaceId || !window.confirm(`Move “${document.title}” to recently deleted? You can restore it while this browser session is active.`)) return;
    try {
      await apiRequest(apiPath("workspaces", workspaceId, "documents", document.id), { method: "DELETE" });
      const deleted = { id: document.id, title: document.title, workspaceId };
      setDeletedDocument(deleted);
      try {
        sessionStorage.setItem(deletedDocumentKey, JSON.stringify(deleted));
        setNotice(`“${document.title}” was moved to recently deleted.`);
      } catch {
        setNotice(`“${document.title}” was moved to recently deleted. Restore is available until you leave this page.`);
      }
      await loadDocuments();
    } catch (reason) {
      setError(formatApiError(reason, "The document could not be deleted."));
    }
  }

  async function restoreDocument() {
    if (!deletedDocument) return;
    try {
      await apiRequest(apiPath("workspaces", deletedDocument.workspaceId, "documents", deletedDocument.id, "restore"), { method: "POST" });
      setDeletedDocument(null);
      let restoreShortcutCleared = true;
      try {
        sessionStorage.removeItem(deletedDocumentKey);
      } catch {
        restoreShortcutCleared = false;
      }
      setNotice(restoreShortcutCleared
        ? `“${deletedDocument.title}” was restored.`
        : `“${deletedDocument.title}” was restored, but the browser could not clear the restore shortcut.`);
      if (deletedDocument.workspaceId === workspaceId) await loadDocuments();
    } catch (reason) {
      setError(formatApiError(reason, "The document could not be restored."));
    }
  }

  const activeFilters = Boolean(search || type || language || documentStatus || visibility || createdFrom || createdTo);
  const clearFilters = () => {
    setSearch("");
    setType("");
    setLanguage("");
    setDocumentStatus("");
    setVisibility("");
    setCreatedFrom("");
    setCreatedTo("");
    setSort("updated_at");
    setPage(1);
  };
  const openDocument = (document: ApiDocument, mode: "view" | "edit") => {
    router.push(`/documents/new?documentId=${encodeURIComponent(document.id)}${mode === "view" ? "&mode=view" : ""}`);
  };

  if (status === "loading") {
    return <main className="integration-auth-required" role="status">Checking your secure workspace session…</main>;
  }
  if (status === "unauthenticated") {
    return (
      <main className="integration-auth-required">
        <section><ShieldCheck size={24} /><h1>Sign in to your workspace</h1><p>{authError || "Your secure session is not active. Sign in to view authorized documents."}</p><Link className="library-primary-button" href="/">Go to sign in</Link></section>
      </main>
    );
  }

  return (
    <main className="dashboard-shell library-shell">
      <Sidebar
        activeItem="My documents"
        collapsed={sidebarCollapsed}
        mobileOpen={mobileNavOpen}
        onCloseMobile={() => setMobileNavOpen(false)}
        onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
      />
      <div className={`dash-main${sidebarCollapsed ? " sidebar-collapsed" : ""}`}>
        <Header mobileNavOpen={mobileNavOpen} onOpenMobileNav={() => setMobileNavOpen(true)} onSearchChange={(value) => { setSearch(value); resetPage(); }} search={search} />
        <div className="library-page-content">
          <div className="library-heading-row">
            <div>
              <p className="library-eyebrow">YOUR WORKSPACE</p>
              <h1>My Documents</h1>
              <p className="library-heading-description">{workspaces.find((workspace) => workspace.id === workspaceId)?.name ?? "Select a workspace to view its accessible documents."}</p>
            </div>
            <button className="library-primary-button" disabled={!workspaceId} onClick={() => router.push("/documents/new")} type="button">
              <FilePlus2 size={16} /> New document
            </button>
          </div>

          <div className="library-local-notice">
            <ShieldCheck size={15} />
            <span><strong>Private by default</strong> · Only documents authorized for your account are returned by the workspace API.</span>
          </div>

          {status === "authenticated" && workspaces.length === 0 && (
            <div className="library-empty-state"><span className="library-empty-icon"><FolderOpen size={24} /></span><h2>No active workspace</h2><p>Your account has no active workspace membership. Ask a workspace owner to invite you.</p></div>
          )}
          {notice && <div className="library-feedback" role="status"><span>{notice}</span><button onClick={() => setNotice("")} type="button">Dismiss</button></div>}
          {error && <div className="library-error" role="alert">{error}<button className="library-clear-button" onClick={() => void loadDocuments()} type="button">Retry</button></div>}

          {deletedDocument && (
            <div className="integration-notice" role="status">
              <span><Trash2 size={16} /> Recently deleted: <strong>{deletedDocument.title}</strong></span>
              <button className="library-secondary-button" onClick={() => void restoreDocument()} type="button"><RotateCcw size={15} /> Restore</button>
            </div>
          )}

          <section aria-label="Document filters" className="library-filter-panel">
            <label className="library-search"><Search size={16} /><input aria-label="Search title and content" onChange={(event) => { setSearch(event.target.value); resetPage(); }} placeholder="Search title and content..." type="search" value={search} /></label>
            <label className="library-select-wrap"><span>Type</span><select aria-label="Filter by document type" onChange={(event) => { setType(event.target.value); resetPage(); }} value={type}><option value="">All types</option>{Object.entries(typeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className="library-select-wrap"><span>Language</span><select aria-label="Filter by language" onChange={(event) => { setLanguage(event.target.value); resetPage(); }} value={language}><option value="">All languages</option><option value="en">English</option><option value="sw">Kiswahili</option></select></label>
            <label className="library-select-wrap"><span>Status</span><select aria-label="Filter by status" onChange={(event) => { setDocumentStatus(event.target.value); resetPage(); }} value={documentStatus}><option value="">All statuses</option><option value="draft">Draft</option><option value="completed">Completed</option></select></label>
            <label className="library-select-wrap"><span>Access</span><select aria-label="Filter by access" onChange={(event) => { setVisibility(event.target.value); resetPage(); }} value={visibility}><option value="">All access</option><option value="private">Private</option><option value="workspace">Workspace</option></select></label>
            <label className="integration-date-field"><span>Created from</span><input aria-label="Created from" onChange={(event) => { setCreatedFrom(event.target.value); resetPage(); }} type="date" value={createdFrom} /></label>
            <label className="integration-date-field"><span>Created to</span><input aria-label="Created to" onChange={(event) => { setCreatedTo(event.target.value); resetPage(); }} type="date" value={createdTo} /></label>
            <label className="library-sort-wrap"><span className="sr-only">Sort documents</span>{sort === "title" ? <ArrowDownAZ size={14} /> : <ArrowUpDown size={14} />}<select aria-label="Sort documents" onChange={(event) => { setSort(event.target.value as typeof sort); resetPage(); }} value={sort}><option value="updated_at">Recently modified</option><option value="created_at">Recently created</option><option value="title">Alphabetical</option></select></label>
          </section>

          <div className="library-list-heading">
            <div><h2>Accessible documents <span>{total}</span></h2><p>{loading ? "Loading documents…" : `Page ${page} of ${lastPage}`}</p></div>
            {activeFilters && <button className="library-clear-button" onClick={clearFilters} type="button">Clear filters</button>}
          </div>

          {error && documents.length === 0 ? (
            <div className="library-empty-state"><span className="library-empty-icon"><Search size={22} /></span><h2>Documents could not load</h2><p>Use Retry above after checking your connection.</p></div>
          ) : !loading && !error && documents.length === 0 ? (
            <div className="library-empty-state is-filtered">
              <span className="library-empty-icon">{activeFilters ? <Search size={22} /> : <FolderOpen size={24} />}</span>
              <h2>{activeFilters ? "No matching documents" : "Your document library is ready"}</h2>
              <p>{activeFilters ? "Try adjusting your search or filters." : "Create a document to get started. New documents are private by default."}</p>
              {activeFilters ? <button className="library-secondary-button" onClick={clearFilters} type="button">Clear filters</button> : <button className="library-primary-button" disabled={!workspaceId} onClick={() => router.push("/documents/new")} type="button"><FilePlus2 size={15} /> Create a document</button>}
            </div>
          ) : (
            <div className="library-table-wrap">
              <table className="library-table">
                <thead><tr><th scope="col">DOCUMENT</th><th scope="col">TYPE</th><th scope="col">ACCESS</th><th scope="col">CREATED</th><th scope="col">LAST MODIFIED</th><th scope="col">WORDS</th><th scope="col">STATUS</th><th scope="col">ACTIONS</th></tr></thead>
                <tbody>
                  {loading ? <tr><td className="library-loading-cell" colSpan={8}>Loading authorized documents…</td></tr> : documents.map((document) => (
                    <tr key={document.id}>
                      <td><button className="library-document-title" onClick={() => openDocument(document, "view")} type="button"><span className="library-document-icon"><FileText size={16} /></span><span><strong>{document.title}</strong><small>{document.ownerId === user?.id ? "Owned by you" : "Shared with you"}</small></span></button></td>
                      <td><span className="library-type-pill">{typeLabels[document.type]}</span></td>
                      <td><span className="integration-access-indicator">{document.visibility === "private" ? <LockKeyhole size={13} /> : <UsersRound size={13} />}{document.visibility === "private" ? "Private" : "Workspace"}</span></td>
                      <td>{formatDate(document.createdAt)}</td><td>{formatDate(document.updatedAt)}</td><td>{document.wordCount.toLocaleString()}</td>
                      <td><span className={`library-status is-${document.status}`}><i />{document.status === "draft" ? "Draft" : "Completed"}</span></td>
                      <td><div className="integration-row-actions"><button onClick={() => openDocument(document, "view")} type="button">View</button><button onClick={() => openDocument(document, "edit")} type="button">Edit</button>{document.ownerId === user?.id && <button aria-label={`Delete ${document.title}`} onClick={() => void deleteDocument(document)} type="button"><Trash2 size={14} /></button>}</div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <div className="integration-pagination">
            <button className="library-secondary-button" disabled={page <= 1 || loading} onClick={() => setPage((current) => Math.max(1, current - 1))} type="button">Previous</button>
            <span>{total} total · Page {page} of {lastPage}</span>
            <button className="library-secondary-button" disabled={page >= lastPage || loading} onClick={() => setPage((current) => Math.min(lastPage, current + 1))} type="button">Next</button>
          </div>
          <footer className="library-footer"><span>© 2026 Likindy Digital Solutions</span><span><ShieldCheck size={13} /> Workspace-authorized · private by default</span></footer>
        </div>
      </div>
    </main>
  );
}
