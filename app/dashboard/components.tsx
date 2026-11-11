"use client";

import Image from "next/image";
import {
  BarChart3,
  Bell,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  FileText,
  FolderOpen,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageSquareText,
  Search,
  Settings,
  Sparkles,
  X
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useState } from "react";
import type { ReactNode } from "react";
import { getProfileInitials } from "../settings/storage";
import { useLocalSettings } from "../settings/useLocalSettings";
import { useAuth } from "../auth/AuthProvider";

type NavigationItem = {
  label: string;
  icon: LucideIcon;
  href: string;
};

const navigationItems: NavigationItem[] = [
  { label: "Overview", icon: LayoutDashboard, href: "/dashboard#overview" },
  { label: "My documents", icon: FolderOpen, href: "/documents" },
  { label: "AI assistant", icon: MessageSquareText, href: "/dashboard#quick-actions" },
  { label: "Templates", icon: FileText, href: "/templates" },
  { label: "Usage & billing", icon: BarChart3, href: "/dashboard#usage" }
];

type SidebarProps = {
  collapsed: boolean;
  mobileOpen: boolean;
  activeItem?: string | null;
  onToggleCollapse: () => void;
  onCloseMobile: () => void;
};

export function Sidebar({
  collapsed,
  mobileOpen,
  activeItem = "Overview",
  onToggleCollapse,
  onCloseMobile
}: SidebarProps) {
  const { settings } = useLocalSettings();
  const { user, workspaces, workspaceId, setWorkspaceId, signOut } = useAuth();
  const profileName = user?.name ?? settings.profile.fullName;
  const initials = getProfileInitials(profileName);

  return (
    <>
      {mobileOpen && (
        <button
          aria-label="Close navigation menu"
          className="dash-mobile-overlay"
          onClick={onCloseMobile}
          type="button"
        />
      )}
      <aside
        className={`dash-sidebar${collapsed ? " is-collapsed" : ""}${mobileOpen ? " is-mobile-open" : ""}`}
        id="dashboard-navigation"
      >
        <div className="dash-brand">
          <div className="dash-logo-frame">
            <Image
              alt="Likindy Digital Solutions"
              height={42}
              priority
              src="/images/lds-logo.png"
              width={42}
            />
          </div>
          {!collapsed && (
            <div className="dash-brand-copy">
              <strong>Likindy <span>AI</span></strong>
              <small>OFFICE</small>
            </div>
          )}
          <button
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            className="dash-collapse-button"
            onClick={onToggleCollapse}
            type="button"
          >
            {collapsed ? <ChevronRight size={17} /> : <ChevronLeft size={17} />}
          </button>
          <button
            aria-label="Close navigation menu"
            className="dash-mobile-close"
            onClick={onCloseMobile}
            type="button"
          >
            <X size={19} />
          </button>
        </div>

        <div className="dash-workspace">
          <div className="dash-workspace-avatar">K</div>
          {!collapsed && (
            <div className="dash-workspace-copy">
              <span>WORKSPACE</span>
              {workspaces.length > 1 ? (
                <select
                  aria-label="Active workspace"
                  className="dash-workspace-select"
                  onChange={(event) => setWorkspaceId(event.target.value)}
                  value={workspaceId ?? ""}
                >
                  {workspaces.map((workspace) => <option key={workspace.id} value={workspace.id}>{workspace.name}</option>)}
                </select>
              ) : <strong>{workspaces[0]?.name ?? settings.workspace.name}</strong>}
            </div>
          )}
          {!collapsed && workspaces.length <= 1 && <ChevronDown className="dash-workspace-chevron" size={15} />}
        </div>

        <nav aria-label="Main navigation" className="dash-nav">
          <p className="dash-nav-label">{collapsed ? "MENU" : "WORKSPACE"}</p>
          {navigationItems.map(({ label, icon: Icon, href }) => (
            <a
              aria-current={label === activeItem ? "page" : undefined}
              className={`dash-nav-link${label === activeItem ? " is-active" : ""}`}
              href={href}
              key={label}
              onClick={onCloseMobile}
              title={collapsed ? label : undefined}
            >
              <Icon size={19} strokeWidth={label === activeItem ? 2.2 : 1.8} />
              {!collapsed && <span>{label}</span>}
            </a>
          ))}
        </nav>

        <div className="dash-sidebar-bottom">
          <div className="dash-plan-card">
            <div className="dash-plan-icon"><Sparkles size={17} /></div>
            {!collapsed && (
              <div className="dash-plan-copy">
                <strong>Professional plan</strong>
                <span>Upgrade your workspace</span>
              </div>
            )}
            {!collapsed && <ChevronRight size={16} />}
          </div>
          <a
            aria-current={activeItem === "Settings" ? "page" : undefined}
            className={`dash-nav-link${activeItem === "Settings" ? " is-active" : ""}`}
            href="/settings"
            onClick={onCloseMobile}
            title={collapsed ? "Settings" : undefined}
          >
            <Settings size={19} strokeWidth={1.8} />
            {!collapsed && <span>Settings</span>}
          </a>
          <div className="dash-profile">
            <div className="dash-avatar">{initials}</div>
            {!collapsed && (
              <div className="dash-profile-copy">
                <strong>{profileName}</strong>
                <span>{user?.email ?? settings.profile.jobTitle}</span>
              </div>
            )}
            {!collapsed && <button
              aria-label="Sign out"
              className="dash-logout"
              onClick={() => void signOut().then(() => window.location.assign("/")).catch((error: unknown) => {
                window.alert(error instanceof Error ? error.message : "Could not sign out. Please try again.");
              })}
              type="button"
            ><LogOut size={17} /></button>}
          </div>
        </div>
      </aside>
    </>
  );
}

type HeaderProps = {
  onOpenMobileNav: () => void;
  mobileNavOpen: boolean;
  search: string;
  onSearchChange: (value: string) => void;
};

export function Header({ onOpenMobileNav, mobileNavOpen, search, onSearchChange }: HeaderProps) {
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const { settings } = useLocalSettings();
  const { user } = useAuth();
  const profileName = user?.name ?? settings.profile.fullName;
  const initials = getProfileInitials(profileName);

  return (
    <header className="dash-header">
      <div className="dash-header-left">
        <button
          aria-controls="dashboard-navigation"
          aria-expanded={mobileNavOpen}
          aria-label="Open navigation menu"
          className="dash-mobile-menu"
          onClick={onOpenMobileNav}
          type="button"
        >
          <Menu size={21} />
        </button>
        <label className="dash-search">
          <Search aria-hidden="true" size={18} />
          <input
            aria-label="Search documents"
            onChange={(event) => onSearchChange(event.target.value)}
            placeholder="Search anything..."
            type="search"
            value={search}
          />
          <kbd>⌘ K</kbd>
        </label>
      </div>
      <div className="dash-header-actions">
        <div className="dash-notification-wrap">
          <button
            aria-expanded={notificationsOpen}
            aria-label="Notifications"
            className="dash-icon-button"
            onClick={() => setNotificationsOpen(!notificationsOpen)}
            type="button"
          >
            <Bell size={19} />
            <span className="dash-notification-dot" />
          </button>
          {notificationsOpen && (
            <div className="dash-notification-popover" role="status">
              <strong>You&apos;re all caught up</strong>
              <span>Your latest workspace updates will appear here.</span>
            </div>
          )}
        </div>
        <span className="dash-header-divider" />
        <button className="dash-header-user" type="button">
          <span className="dash-avatar dash-avatar-small">{initials}</span>
          <span className="dash-header-user-copy">
            <strong>{profileName}</strong>
            <small>{user?.email ?? settings.profile.jobTitle}</small>
          </span>
          <ChevronDown size={16} />
        </button>
      </div>
    </header>
  );
}

type StatCardProps = {
  label: string;
  value: string;
  detail: ReactNode;
  icon: LucideIcon;
  tone: "green" | "blue" | "violet" | "amber";
  progress?: number;
};

export function StatCard({ label, value, detail, icon: Icon, tone, progress }: StatCardProps) {
  return (
    <article className="dash-stat-card">
      <div className="dash-stat-top">
        <span className={`dash-stat-icon tone-${tone}`}><Icon size={19} /></span>
        <span className="dash-stat-period">This month</span>
      </div>
      <p className="dash-stat-label">{label}</p>
      <div className="dash-stat-value-row">
        <strong className="dash-stat-value">{value}</strong>
        {progress !== undefined && <span className="dash-stat-progress-label">{progress}%</span>}
      </div>
      {progress !== undefined ? (
        <div aria-label={`${progress}% of monthly AI usage used`} className="dash-progress-track" role="img">
          <span style={{ width: `${progress}%` }} />
        </div>
      ) : (
        <div className="dash-stat-detail">{detail}</div>
      )}
      {progress !== undefined && <div className="dash-stat-detail">{detail}</div>}
    </article>
  );
}

type QuickActionProps = {
  title: string;
  description: string;
  icon: LucideIcon;
  tone: "green" | "blue" | "violet" | "amber";
  onClick: (title: string) => void;
};

export function QuickAction({ title, description, icon: Icon, tone, onClick }: QuickActionProps) {
  return (
    <button className="dash-quick-action" onClick={() => onClick(title)} type="button">
      <span className={`dash-action-icon tone-${tone}`}><Icon size={20} /></span>
      <span className="dash-action-copy">
        <strong>{title}</strong>
        <small>{description}</small>
      </span>
      <ChevronRight className="dash-action-arrow" size={18} />
    </button>
  );
}

export function DashboardNotice({ children }: { children: ReactNode }) {
  return <div className="dash-notice" role="status">{children}</div>;
}
