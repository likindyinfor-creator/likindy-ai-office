"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import {
  ArrowRight,
  FileStack,
  FileText,
  FileCode2,
  Languages,
  Search,
  Sparkles
} from "lucide-react";
import { Header, Sidebar } from "../dashboard/components";
import { TemplateCard, TemplatePreviewModal } from "./components";
import {
  documentTemplates,
  templateCategories
} from "./data";
import type {
  DocumentTemplate,
  TemplateCategory,
  TemplateLanguage
} from "./data";

type LanguageFilter = "All languages" | TemplateLanguage;

const languageFilters: LanguageFilter[] = ["All languages", "English", "Kiswahili"];
const initialRecentIds = ["quarterly-performance-report", "official-letter-en", "academic-thesis-latex"];
const recentStorageKey = "likindy-recent-templates";

export default function TemplatesPage() {
  const router = useRouter();
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState<TemplateCategory | "All templates">("All templates");
  const [language, setLanguage] = useState<LanguageFilter>("All languages");
  const [previewTemplate, setPreviewTemplate] = useState<DocumentTemplate | null>(null);
  const [recentIds, setRecentIds] = useState(initialRecentIds);

  useEffect(() => {
    try {
      const stored = localStorage.getItem(recentStorageKey);
      if (!stored) return;
      const parsed: unknown = JSON.parse(stored);
      if (Array.isArray(parsed)) {
        const validIds = parsed.filter((id): id is string =>
          typeof id === "string" && documentTemplates.some((template) => template.id === id)
        );
        if (validIds.length) setRecentIds(validIds);
      }
    } catch (error) {
      console.error("Could not load recently used templates.", error);
    }
  }, []);

  const filteredTemplates = useMemo(() => {
    const query = search.trim().toLocaleLowerCase();
    return documentTemplates.filter((template) => {
      const matchesCategory = category === "All templates" || template.category === category;
      const matchesLanguage = language === "All languages" || template.language === language;
      const searchableText = [
        template.name,
        template.description,
        template.category,
        template.language,
        ...template.tags,
        template.format === "latex" ? "latex tex source" : ""
      ].join(" ").toLocaleLowerCase();
      return matchesCategory && matchesLanguage && (!query || searchableText.includes(query));
    });
  }, [category, language, search]);

  const recentTemplates = useMemo(
    () => recentIds
      .map((id) => documentTemplates.find((template) => template.id === id))
      .filter((template): template is DocumentTemplate => Boolean(template))
      .slice(0, 3),
    [recentIds]
  );

  const closePreview = useCallback(() => setPreviewTemplate(null), []);

  function handleUseTemplate(template: DocumentTemplate) {
    const nextRecentIds = [template.id, ...recentIds.filter((id) => id !== template.id)].slice(0, 8);
    setRecentIds(nextRecentIds);
    try {
      localStorage.setItem(recentStorageKey, JSON.stringify(nextRecentIds));
    } catch (error) {
      console.error("Could not save recently used templates.", error);
    }
    setPreviewTemplate(null);
    router.push(`/documents/new?template=${encodeURIComponent(template.id)}`);
  }

  const matchingStatus = `${filteredTemplates.length} ${filteredTemplates.length === 1 ? "template" : "templates"} found`;

  return (
    <main className="dashboard-shell templates-shell" id="templates">
      <Sidebar
        activeItem="Templates"
        collapsed={sidebarCollapsed}
        mobileOpen={mobileNavOpen}
        onCloseMobile={() => setMobileNavOpen(false)}
        onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
      />
      <div className={`dash-main${sidebarCollapsed ? " sidebar-collapsed" : ""}`}>
        <Header
          mobileNavOpen={mobileNavOpen}
          onOpenMobileNav={() => setMobileNavOpen(true)}
          onSearchChange={setSearch}
          search={search}
        />
        <div className="templates-page-content">
          <section className="templates-hero">
            <div className="templates-hero-copy">
              <span className="templates-kicker"><Sparkles size={13} /> YOUR WORKSPACE STARTS HERE</span>
              <h1>Templates that move work forward.</h1>
              <p>Start with a proven structure, make it yours, and get to your best work faster.</p>
            </div>
            <div className="templates-hero-art" aria-hidden="true">
              <div className="templates-hero-sheet sheet-back" />
              <div className="templates-hero-sheet sheet-front">
                <span /><span /><span /><i /><span /><span /><span />
              </div>
              <div className="templates-hero-badge"><FileStack size={17} /></div>
              <div className="templates-hero-sparkle"><Sparkles size={15} /></div>
            </div>
          </section>

          <section aria-labelledby="recent-templates-heading" className="templates-recent-section">
            <div className="templates-section-heading">
              <div>
                <span className="templates-section-eyebrow">PICK UP WHERE YOU LEFT OFF</span>
                <h2 id="recent-templates-heading">Recently used</h2>
              </div>
            </div>
            <div className="templates-recent-grid">
              {recentTemplates.map((template) => (
                <button className="templates-recent-card" key={template.id} onClick={() => handleUseTemplate(template)} type="button">
                  <span className={`templates-recent-icon${template.format === "latex" ? " is-latex" : ""}`}>
                    {template.format === "latex" ? <FileCode2 size={17} /> : <FileText size={17} />}
                  </span>
                  <span className="templates-recent-copy">
                    <strong>{template.name}</strong>
                    <small>{template.category} · {template.language}</small>
                  </span>
                  <ArrowRight className="templates-recent-arrow" size={16} />
                </button>
              ))}
            </div>
          </section>

          <section aria-labelledby="template-gallery-heading" className="templates-gallery-section">
            <div className="templates-gallery-heading">
              <div>
                <span className="templates-section-eyebrow">MADE FOR YOUR WORK</span>
                <h2 id="template-gallery-heading">Explore templates</h2>
                <p>Professionally structured examples, ready to customize. All templates are demonstrations.</p>
              </div>
              <span className="templates-result-count">{matchingStatus}</span>
            </div>

            <div className="templates-filter-panel">
              <label className="templates-search">
                <Search aria-hidden="true" size={17} />
                <input
                  aria-label="Search templates"
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Search templates, topics or formats..."
                  type="search"
                  value={search}
                />
              </label>
              <label className="templates-language-filter">
                <Languages size={15} />
                <select
                  aria-label="Filter by language"
                  onChange={(event) => setLanguage(event.target.value as LanguageFilter)}
                  value={language}
                >
                  {languageFilters.map((filter) => <option key={filter} value={filter}>{filter}</option>)}
                </select>
              </label>
            </div>

            <div aria-label="Filter templates by category" className="templates-category-filters" role="group">
              <button
                aria-pressed={category === "All templates"}
                className={category === "All templates" ? "is-selected" : ""}
                onClick={() => setCategory("All templates")}
                type="button"
              >
                All templates <span>{documentTemplates.length}</span>
              </button>
              {templateCategories.map((item) => {
                const count = documentTemplates.filter((template) => template.category === item).length;
                return (
                  <button
                    aria-pressed={category === item}
                    className={category === item ? "is-selected" : ""}
                    key={item}
                    onClick={() => setCategory(item)}
                    type="button"
                  >
                    {item} <span>{count}</span>
                  </button>
                );
              })}
            </div>

            {filteredTemplates.length ? (
              <div aria-live="polite" className="templates-grid">
                {filteredTemplates.map((template) => (
                  <TemplateCard
                    key={template.id}
                    onPreview={setPreviewTemplate}
                    onUse={handleUseTemplate}
                    template={template}
                  />
                ))}
              </div>
            ) : (
              <div className="templates-empty-state" role="status">
                <span><Search size={22} /></span>
                <h3>No templates found</h3>
                <p>Try a different search or clear one of the filters to see more templates.</p>
                <button onClick={() => { setSearch(""); setCategory("All templates"); setLanguage("All languages"); }} type="button">
                  Clear filters
                </button>
              </div>
            )}
          </section>
          <footer className="templates-footer">© 2026 Likindy Digital Solutions <span /> Sample templates for demonstration · Review and customize before use</footer>
        </div>
      </div>
      {previewTemplate && (
        <TemplatePreviewModal
          onClose={closePreview}
          onUse={handleUseTemplate}
          template={previewTemplate}
        />
      )}
    </main>
  );
}
