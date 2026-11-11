"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import {
  ArrowDownRight,
  ArrowRight,
  ArrowUpRight,
  FileCheck2,
  FileText,
  Files,
  FileSearch,
  Plus,
  Sparkles
} from "lucide-react";
import {
  DashboardNotice,
  Header,
  QuickAction,
  Sidebar,
  StatCard
} from "./components";
import { useLocalSettings } from "../settings/useLocalSettings";

const recentDocuments = [
  { name: "Q3 Regional Performance Report", category: "Report", updated: "Today, 10:42 AM", status: "Ready", initials: "RP", tone: "blue" },
  { name: "Partnership Proposal — Serengeti Co.", category: "Proposal", updated: "Today, 9:18 AM", status: "In progress", initials: "SP", tone: "violet" },
  { name: "Client Onboarding Letter", category: "Letter", updated: "Yesterday", status: "Ready", initials: "CL", tone: "green" },
  { name: "Supplier Agreement Review", category: "Analysis", updated: "Nov 06, 2026", status: "Ready", initials: "SA", tone: "amber" },
  { name: "Annual Strategy Brief 2026", category: "Report", updated: "Nov 04, 2026", status: "Ready", initials: "AS", tone: "blue" }
] as const;

const quickActions = [
  { title: "Write a letter", description: "Professional letters in minutes", icon: FileText, tone: "green" },
  { title: "Create a report", description: "Turn insights into clear reports", icon: Files, tone: "blue" },
  { title: "Draft a proposal", description: "Build a compelling business case", icon: FileCheck2, tone: "violet" },
  { title: "Analyze a document", description: "Ask questions and find key details", icon: FileSearch, tone: "amber" }
] as const;

export default function DashboardPage() {
  const router = useRouter();
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [notice, setNotice] = useState("");
  const { settings } = useLocalSettings();
  const firstName = settings.profile.fullName.trim().split(/\s+/)[0] || "there";

  const filteredDocuments = useMemo(() => {
    const query = search.trim().toLowerCase();
    if (!query) return recentDocuments;
    return recentDocuments.filter((document) =>
      `${document.name} ${document.category} ${document.status}`.toLowerCase().includes(query)
    );
  }, [search]);

  function showActionNotice(action: string) {
    setNotice(`${action} is ready to start. Document creation is a demo in this workspace.`);
  }

  return (
    <main className="dashboard-shell" id="overview">
      <Sidebar
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
        <div className="dash-content">
          <section className="dash-welcome-row">
            <div>
              <p className="dash-date-label">TUESDAY, NOVEMBER 10, 2026</p>
              <h1>Good morning, {firstName} <span className="dash-wave">✦</span></h1>
              <p className="dash-welcome-subtitle">Here&apos;s what&apos;s happening in your workspace today.</p>
            </div>
            <button className="dash-primary-button" onClick={() => router.push("/documents/new")} type="button">
              <Plus size={18} />
              <span>New document</span>
            </button>
          </section>

          {notice && (
            <div className="dash-notice-row">
              <DashboardNotice>{notice}</DashboardNotice>
              <button aria-label="Dismiss message" onClick={() => setNotice("")} type="button">Dismiss</button>
            </div>
          )}

          <section aria-label="Workspace statistics" className="dash-stats-grid" id="usage">
            <StatCard
              detail="Across your workspace"
              icon={Files}
              label="Documents created"
              tone="green"
              value="128"
            />
            <StatCard
              detail={<><ArrowUpRight size={14} /> 12% from last month</>}
              icon={Sparkles}
              label="AI generations"
              tone="blue"
              value="342"
            />
            <StatCard
              detail="5 created this week"
              icon={FileCheck2}
              label="Completed this month"
              tone="violet"
              value="46"
            />
            <StatCard
              detail="1,360 credits remaining"
              icon={ArrowDownRight}
              label="Monthly AI usage"
              tone="amber"
              value="68%"
              progress={68}
            />
          </section>

          <section aria-labelledby="quick-actions-heading" className="dash-section">
            <div className="dash-section-heading">
              <div>
                <h2 id="quick-actions-heading">What would you like to create?</h2>
                <p>Start with a task and let AI help you move faster.</p>
              </div>
              <button className="dash-subtle-link" onClick={() => router.push("/templates")} type="button">
                Browse templates <ArrowRight size={15} />
              </button>
            </div>
            <div className="dash-quick-grid" id="quick-actions">
              {quickActions.map((action) => (
                <QuickAction
                  description={action.description}
                  icon={action.icon}
                  key={action.title}
                  onClick={(title) => {
                    const type = title === "Write a letter" ? "official-letter"
                      : title === "Create a report" ? "report"
                        : title === "Draft a proposal" ? "proposal" : "general-document";
                    router.push(`/documents/new?type=${type}`);
                  }}
                  title={action.title}
                  tone={action.tone}
                />
              ))}
            </div>
          </section>

          <section aria-labelledby="recent-documents-heading" className="dash-section dash-documents-section" id="recent-documents">
            <div className="dash-section-heading dash-documents-heading">
              <div>
                <h2 id="recent-documents-heading">Recent documents</h2>
                <p>Your latest work, all in one place.</p>
              </div>
              <a className="dash-subtle-link" href="#recent-documents">
                View all documents <ArrowRight size={15} />
              </a>
            </div>
            <div className="dash-table-wrap">
              <table className="dash-documents-table">
                <thead>
                  <tr>
                    <th scope="col">DOCUMENT NAME</th>
                    <th scope="col">TYPE</th>
                    <th scope="col">LAST UPDATED</th>
                    <th scope="col">STATUS</th>
                    <th scope="col"><span className="sr-only">Open document</span></th>
                  </tr>
                </thead>
                <tbody>
                  {filteredDocuments.map((document) => (
                    <tr key={document.name}>
                      <td>
                        <div className="dash-document-name">
                          <span className={`dash-document-icon tone-${document.tone}`}>{document.initials}</span>
                          <strong>{document.name}</strong>
                        </div>
                      </td>
                      <td><span className="dash-document-category">{document.category}</span></td>
                      <td className="dash-document-updated">{document.updated}</td>
                      <td><span className={`dash-status${document.status === "In progress" ? " is-progress" : ""}`}><span />{document.status}</span></td>
                      <td><button aria-label={`Open ${document.name}`} className="dash-row-action" onClick={() => showActionNotice(document.name)} type="button"><ArrowRight size={17} /></button></td>
                    </tr>
                  ))}
                  {filteredDocuments.length === 0 && (
                    <tr>
                      <td className="dash-empty-state" colSpan={5}>
                        No documents match “{search}”. Try a different search.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="dash-table-footer">
              <span>Showing {filteredDocuments.length} of 128 documents</span>
              <a href="#recent-documents">Open document library <ArrowRight size={14} /></a>
            </div>
          </section>

          <footer className="dash-footer">
            <span>© 2026 Likindy Digital Solutions</span>
            <span>Secure workspace <span className="dash-footer-dot" /> All systems operational</span>
          </footer>
        </div>
      </div>
    </main>
  );
}
