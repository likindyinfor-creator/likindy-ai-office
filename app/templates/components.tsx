"use client";

import { useEffect } from "react";
import {
  ArrowRight,
  FileCode2,
  FileText,
  Languages,
  X
} from "lucide-react";
import type { DocumentTemplate } from "./data";

type TemplateCardProps = {
  template: DocumentTemplate;
  onPreview: (template: DocumentTemplate) => void;
  onUse: (template: DocumentTemplate) => void;
};

export function TemplateCard({ template, onPreview, onUse }: TemplateCardProps) {
  return (
    <article className="template-card">
      <div className={`template-card-art${template.format === "latex" ? " is-latex" : ""}`}>
        <div className="template-art-paper">
          <div className={`template-page-preview${template.format === "latex" ? " is-typeset" : ""}`} aria-hidden="true">
            {template.format === "latex" && <span className="template-typeset-title">RESEARCH REPORT</span>}
            {template.format !== "latex" && <span className="template-preview-logo" />}
            <span className="template-preview-line is-heading" />
            <span className="template-preview-line is-short" />
            <span className="template-preview-gap" />
            <span className="template-preview-line" />
            <span className="template-preview-line is-wide" />
            <span className="template-preview-line" />
            <span className="template-preview-line is-short" />
            <span className="template-preview-gap" />
            <span className="template-preview-line is-wide" />
            <span className="template-preview-line" />
          </div>
        </div>
        <span className={`template-art-icon tone-${template.format === "latex" ? "violet" : "blue"}`}>
          {template.format === "latex" ? <FileCode2 size={17} /> : <FileText size={17} />}
        </span>
        {template.format === "latex" && <span className="template-format-badge">LaTeX</span>}
      </div>
      <div className="template-card-body">
        <div className="template-card-meta">
          <span className="template-category-label">{template.category}</span>
          <span className="template-language"><Languages size={12} />{template.language}</span>
        </div>
        <h3>{template.name}</h3>
        <p>{template.description}</p>
        <div className="template-card-actions">
          <button className="template-preview-button" onClick={() => onPreview(template)} type="button">Preview</button>
          <button className="template-use-button" onClick={() => onUse(template)} type="button">Use template <ArrowRight size={14} /></button>
        </div>
      </div>
    </article>
  );
}

type TemplatePreviewModalProps = {
  template: DocumentTemplate;
  onClose: () => void;
  onUse: (template: DocumentTemplate) => void;
};

export function TemplatePreviewModal({ template, onClose, onUse }: TemplatePreviewModalProps) {
  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  const isTypeset = template.format === "latex";
  const sampleContent = template.formattedContent ?? template.content;
  const sampleText = sampleContent.replace(/<[^>]*>/g, " ").replace(/&nbsp;/g, " ").replace(/\s+/g, " ").trim();

  return (
    <div className="template-modal-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <section
        aria-labelledby="template-preview-title"
        aria-modal="true"
        className="template-preview-modal"
        role="dialog"
      >
        <header className="template-modal-header">
          <div>
            <span className="template-modal-eyebrow">TEMPLATE PREVIEW · DEMO</span>
            <h2 id="template-preview-title">{template.name}</h2>
          </div>
          <button aria-label="Close preview" className="template-modal-close" onClick={onClose} type="button"><X size={18} /></button>
        </header>
        <div className="template-modal-meta">
          <span>{template.category}</span>
          <span><Languages size={13} />{template.language}</span>
          {isTypeset && <span className="template-latex-pill"><FileCode2 size={13} /> LaTeX typeset layout</span>}
        </div>
        <div className="template-modal-content">
          <div className="template-structure">
            <h3>Document structure</h3>
            <ol>
              {template.structure.map((item) => <li key={item}>{item}</li>)}
            </ol>
            <div className="template-demo-callout">
              Sample template for demonstration. Review and customize details before use.
            </div>
          </div>
          <div className={`template-sample-document${isTypeset ? " is-typeset" : ""}`}>
            <div className="template-sample-document-bar">
              <span>{isTypeset ? "TYPESET DOCUMENT PREVIEW" : "SAMPLE DOCUMENT"}</span>
              <span>{isTypeset ? "LaTeX style" : template.language}</span>
            </div>
            {isTypeset ? (
              <div className="template-typeset-copy" dangerouslySetInnerHTML={{ __html: sampleContent }} />
            ) : (
              <div className="template-sample-copy">
                {sampleText.split(/(?<=[.!?])\s+(?=[A-Z])/).slice(0, 12).map((line, index) => (
                  <p key={`${index}-${line.slice(0, 16)}`}>{line}</p>
                ))}
              </div>
            )}
          </div>
        </div>
        <footer className="template-modal-footer">
          <span>Use this template to open it in your document workspace.</span>
          <div>
            <button className="template-modal-cancel" onClick={onClose} type="button">Close preview</button>
            <button className="template-use-button" onClick={() => onUse(template)} type="button">Use this template <ArrowRight size={14} /></button>
          </div>
        </footer>
      </section>
    </div>
  );
}
