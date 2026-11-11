"use client";

import { useEffect } from "react";
import {
  Check,
  Copy,
  Download,
  Ellipsis,
  Eye,
  FileText,
  Pencil,
  Trash2,
  X
} from "lucide-react";
import type { StoredDocument, StoredDocumentStatus } from "./storage";
import { sanitizeDocumentContent } from "./storage";

export function DocumentActions({
  document,
  menuOpen,
  onToggleMenu,
  onOpen,
  onPreview,
  onDuplicate,
  onDelete,
  onDownload,
  onToggleStatus
}: {
  document: StoredDocument;
  menuOpen: boolean;
  onToggleMenu: () => void;
  onOpen: () => void;
  onPreview: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
  onDownload: () => void;
  onToggleStatus: (status: StoredDocumentStatus) => void;
}) {
  return (
    <div className="library-actions">
      <button
        aria-expanded={menuOpen}
        aria-label={`Actions for ${document.title}`}
        className="library-action-trigger"
        onClick={onToggleMenu}
        type="button"
      >
        <Ellipsis size={18} />
      </button>
      {menuOpen && (
        <div className="library-action-menu" role="menu">
          <button onClick={onOpen} role="menuitem" type="button"><Pencil size={14} /> Open and edit</button>
          <button onClick={onPreview} role="menuitem" type="button"><Eye size={14} /> Preview</button>
          <button onClick={onDuplicate} role="menuitem" type="button"><Copy size={14} /> Duplicate</button>
          <button onClick={onDownload} role="menuitem" type="button"><Download size={14} /> Download TXT</button>
          <button onClick={() => onToggleStatus(document.status === "draft" ? "completed" : "draft")} role="menuitem" type="button">
            <Check size={14} /> Mark as {document.status === "draft" ? "completed" : "draft"}
          </button>
          <span className="library-menu-divider" />
          <button className="is-destructive" onClick={onDelete} role="menuitem" type="button"><Trash2 size={14} /> Delete document</button>
        </div>
      )}
    </div>
  );
}

export function DocumentPreview({
  document,
  onClose,
  onEdit
}: {
  document: StoredDocument;
  onClose: () => void;
  onEdit: () => void;
}) {
  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  const content = sanitizeDocumentContent(document.content);
  const languageLabel = document.language === "en" ? "English" : "Kiswahili";

  return (
    <div
      className="library-preview-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <section aria-labelledby="library-preview-title" aria-modal="true" className="library-preview-modal" role="dialog">
        <header className="library-preview-header">
          <div className="library-preview-file-icon"><FileText size={18} /></div>
          <div className="library-preview-heading">
            <span>LOCAL DOCUMENT PREVIEW</span>
            <h2 id="library-preview-title">{document.title}</h2>
          </div>
          <button aria-label="Close document preview" className="library-preview-close" onClick={onClose} type="button"><X size={18} /></button>
        </header>
        <div className="library-preview-meta">
          <span>{document.type.replaceAll("-", " ")}</span>
          <span>{languageLabel}</span>
          <span>{document.wordCount} words</span>
          {document.typeset && <span>Typeset layout</span>}
        </div>
        <div className="library-preview-page">
          <div className={`library-preview-content${document.typeset ? " is-typeset-document" : ""}`} dangerouslySetInnerHTML={{ __html: content }} />
        </div>
        <footer className="library-preview-footer">
          <span>Stored in this browser · Not synced to the cloud</span>
          <button className="library-primary-button" onClick={onEdit} type="button"><Pencil size={14} /> Open and edit</button>
        </footer>
      </section>
    </div>
  );
}
