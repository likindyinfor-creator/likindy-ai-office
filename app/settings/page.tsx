"use client";

import { useEffect, useState } from "react";
import {
  Bell,
  Building2,
  Check,
  ChevronRight,
  CreditCard,
  Globe2,
  LockKeyhole,
  Monitor,
  Save,
  ShieldCheck,
  UserRound,
  UsersRound
} from "lucide-react";
import { Header, Sidebar } from "../dashboard/components";
import { useLocalSettings } from "./useLocalSettings";
import { defaultLocalSettings, getProfileInitials } from "./storage";
import type { LocalSettings } from "./storage";

type SettingsSection = {
  id: string;
  label: string;
  icon: typeof UserRound;
};

const settingsSections: SettingsSection[] = [
  { id: "profile", label: "My profile", icon: UserRound },
  { id: "preferences", label: "Preferences", icon: Monitor },
  { id: "workspace", label: "Workspace", icon: Building2 },
  { id: "notifications", label: "Notifications", icon: Bell },
  { id: "security", label: "Security", icon: ShieldCheck },
  { id: "subscription", label: "Subscription & usage", icon: CreditCard }
];

function formatPreviewDate(timezone: string, format: LocalSettings["preferences"]["dateFormat"]): string {
  try {
    const parts = new Intl.DateTimeFormat("en-GB", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      timeZone: timezone
    }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
    if (format === "MM/DD/YYYY") return `${values.month}/${values.day}/${values.year}`;
    if (format === "YYYY-MM-DD") return `${values.year}-${values.month}-${values.day}`;
    const month = new Intl.DateTimeFormat("en-GB", { month: "short", timeZone: timezone }).format(new Date());
    return `${values.day} ${month} ${values.year}`;
  } catch {
    return "Choose a valid timezone";
  }
}

export default function SettingsPage() {
  const { settings, updateSettings, error: storageError } = useLocalSettings();
  const [profileDraft, setProfileDraft] = useState(settings.profile);
  const [workspaceDraft, setWorkspaceDraft] = useState(settings.workspace);
  const [feedback, setFeedback] = useState("");
  const [feedbackError, setFeedbackError] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [search, setSearch] = useState("");

  useEffect(() => setProfileDraft(settings.profile), [settings.profile]);
  useEffect(() => setWorkspaceDraft(settings.workspace), [settings.workspace]);
  function showFeedback(message: string, isError = false) {
    setFeedback(message);
    setFeedbackError(isError);
  }

  function updatePreferences(patch: Partial<LocalSettings["preferences"]>) {
    const saved = updateSettings({ preferences: { ...settings.preferences, ...patch } });
    showFeedback(saved ? "Your preference has been saved on this browser." : "We couldn’t save that preference. Check browser storage and try again.", !saved);
  }

  function updateNotifications(patch: Partial<LocalSettings["notifications"]>) {
    const saved = updateSettings({ notifications: { ...settings.notifications, ...patch } });
    showFeedback(saved ? "Notification preferences saved on this browser." : "We couldn’t save your notification preference. Try again.", !saved);
  }

  function saveProfile(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const saved = updateSettings({ profile: profileDraft });
    showFeedback(saved ? "Profile details saved on this browser." : "We couldn’t save your profile. Check browser storage and try again.", !saved);
  }

  function saveWorkspace(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const saved = updateSettings({ workspace: workspaceDraft });
    showFeedback(saved ? "Workspace details saved on this browser." : "We couldn’t save your workspace. Check browser storage and try again.", !saved);
  }

  function resetDemoSettings() {
    if (!window.confirm("Reset your local profile, workspace, and preferences to the demo defaults? This cannot be undone.")) return;
    const saved = updateSettings(defaultLocalSettings);
    showFeedback(saved ? "Demo settings have been reset on this browser." : "We couldn’t reset your settings. Try again.", !saved);
  }

  return (
    <main className="dashboard-shell settings-shell">
      <Sidebar
        activeItem="Settings"
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
        <div className="settings-page-content">
          <div className="settings-page-heading">
            <div>
              <p className="settings-eyebrow">YOUR ACCOUNT</p>
              <h1>Settings</h1>
              <p>Manage your profile, workspace, and preferences.</p>
            </div>
          </div>

          {(feedback || storageError) && (
            <div className={`settings-feedback${feedbackError || storageError ? " is-error" : ""}`} role={feedbackError || storageError ? "alert" : "status"}>
              <span>{storageError || feedback}</span>
              <button aria-label="Dismiss message" onClick={() => { setFeedback(""); setFeedbackError(false); }} type="button">Dismiss</button>
            </div>
          )}

          <div className="settings-layout">
            <nav aria-label="Settings sections" className="settings-nav">
              <p>SETTINGS</p>
              {settingsSections.map(({ id, label, icon: Icon }) => (
                <a href={`#${id}`} key={id}><Icon size={17} />{label}<ChevronRight aria-hidden="true" size={14} /></a>
              ))}
              <div className="settings-local-note"><ShieldCheck size={15} /><span>Demo settings are saved only in this browser.</span></div>
            </nav>

            <div className="settings-sections">
              <section aria-labelledby="profile-heading" className="settings-card" id="profile">
                <div className="settings-card-heading">
                  <span className="settings-section-icon"><UserRound size={18} /></span>
                  <div><h2 id="profile-heading">My profile</h2><p>Your personal details for this demo workspace.</p></div>
                </div>
                <form onSubmit={saveProfile}>
                  <div className="settings-profile-avatar">
                    <span aria-label={`${profileDraft.fullName || "User"} profile avatar`} className="settings-avatar" role="img">{getProfileInitials(profileDraft.fullName)}</span>
                    <div><strong>Profile avatar</strong><span>Your initials are shown as a local demo avatar.</span></div>
                    <button className="settings-tertiary-button" onClick={() => showFeedback("Profile photo uploads require backend integration. Your initials avatar remains active.")} type="button">Change photo</button>
                  </div>
                  <div className="settings-form-grid">
                    <label className="settings-field"><span>Full name</span><input autoComplete="name" onChange={(event) => setProfileDraft({ ...profileDraft, fullName: event.target.value })} required value={profileDraft.fullName} /></label>
                    <label className="settings-field"><span>Email address</span><input autoComplete="email" onChange={(event) => setProfileDraft({ ...profileDraft, email: event.target.value })} required type="email" value={profileDraft.email} /></label>
                    <label className="settings-field settings-field-wide"><span>Job title</span><input autoComplete="organization-title" onChange={(event) => setProfileDraft({ ...profileDraft, jobTitle: event.target.value })} placeholder="e.g. Operations Manager" value={profileDraft.jobTitle} /></label>
                  </div>
                  <div className="settings-card-footer"><span>Saved locally · Not shared with a server</span><button className="settings-save-button" type="submit"><Save size={15} />Save profile</button></div>
                </form>
              </section>

              <section aria-labelledby="preferences-heading" className="settings-card" id="preferences">
                <div className="settings-card-heading">
                  <span className="settings-section-icon"><Monitor size={18} /></span>
                  <div><h2 id="preferences-heading">Preferences</h2><p>Choose how this demo workspace is displayed.</p></div>
                </div>
                <div className="settings-form-grid">
                  <label className="settings-field"><span>Interface language</span><select onChange={(event) => updatePreferences({ language: event.target.value as LocalSettings["preferences"]["language"] })} value={settings.preferences.language}><option>English</option><option>Kiswahili</option></select><small>Saved for future interface localization; the demo UI remains in English.</small></label>
                  <label className="settings-field"><span>Timezone</span><select onChange={(event) => updatePreferences({ timezone: event.target.value })} value={settings.preferences.timezone}><option value="Africa/Dar_es_Salaam">East Africa Time (Dar es Salaam)</option><option value="Africa/Nairobi">East Africa Time (Nairobi)</option><option value="UTC">Coordinated Universal Time (UTC)</option><option value="Europe/London">United Kingdom (London)</option></select></label>
                  <label className="settings-field"><span>Date format</span><select onChange={(event) => updatePreferences({ dateFormat: event.target.value as LocalSettings["preferences"]["dateFormat"] })} value={settings.preferences.dateFormat}><option>DD MMM YYYY</option><option>MM/DD/YYYY</option><option>YYYY-MM-DD</option></select><small>Preview: {formatPreviewDate(settings.preferences.timezone, settings.preferences.dateFormat)}</small></label>
                  <label className="settings-field"><span>Display density</span><select onChange={(event) => updatePreferences({ appearance: event.target.value as LocalSettings["preferences"]["appearance"] })} value={settings.preferences.appearance}><option value="comfortable">Comfortable</option><option value="compact">Compact</option></select><small>Applies a roomier or more compact layout across the dashboard.</small></label>
                </div>
              </section>

              <section aria-labelledby="workspace-heading" className="settings-card" id="workspace">
                <div className="settings-card-heading">
                  <span className="settings-section-icon"><Building2 size={18} /></span>
                  <div><h2 id="workspace-heading">Workspace settings</h2><p>Update your organization details for this browser.</p></div>
                </div>
                <form onSubmit={saveWorkspace}>
                  <div className="settings-workspace-logo">
                    <span aria-hidden="true" className="settings-workspace-mark">{workspaceDraft.name.trim().charAt(0).toUpperCase() || "W"}</span>
                    <div><strong>Institution logo</strong><span>Logo upload is a placeholder until backend integration.</span></div>
                    <button className="settings-tertiary-button" onClick={() => showFeedback("Workspace logo uploads require backend integration.")} type="button">Add logo</button>
                  </div>
                  <div className="settings-form-grid">
                    <label className="settings-field"><span>Workspace name</span><input onChange={(event) => setWorkspaceDraft({ ...workspaceDraft, name: event.target.value })} required value={workspaceDraft.name} /></label>
                    <label className="settings-field"><span>Workspace type</span><select onChange={(event) => setWorkspaceDraft({ ...workspaceDraft, type: event.target.value as LocalSettings["workspace"]["type"] })} value={workspaceDraft.type}><option>Individual</option><option>Institution</option><option>Business</option></select></label>
                    <label className="settings-field settings-field-wide"><span>Workspace description</span><textarea onChange={(event) => setWorkspaceDraft({ ...workspaceDraft, description: event.target.value })} rows={3} value={workspaceDraft.description} /></label>
                    <label className="settings-field settings-field-wide"><span>Contact information</span><input onChange={(event) => setWorkspaceDraft({ ...workspaceDraft, contact: event.target.value })} placeholder="Email or phone number" value={workspaceDraft.contact} /></label>
                  </div>
                  <div className="settings-card-footer"><span>Visible only in this browser demo</span><button className="settings-save-button" type="submit"><Save size={15} />Save workspace</button></div>
                </form>
              </section>

              <section aria-labelledby="notifications-heading" className="settings-card" id="notifications">
                <div className="settings-card-heading">
                  <span className="settings-section-icon"><Bell size={18} /></span>
                  <div><h2 id="notifications-heading">Notifications</h2><p>Choose which demo updates you would like to see.</p></div>
                </div>
                <div className="settings-toggle-list">
                  {([
                    ["documentActivity", "Document activity", "Updates when documents are created or changed."],
                    ["productUpdates", "Product updates", "New features, improvements, and announcements."],
                    ["usageAlerts", "Usage alerts", "Reminders when you are approaching usage limits."]
                  ] as const).map(([key, title, description]) => (
                    <label className="settings-toggle-row" key={key}>
                      <span><strong>{title}</strong><small>{description}</small></span>
                      <input aria-label={title} checked={settings.notifications[key]} onChange={(event) => updateNotifications({ [key]: event.target.checked })} role="switch" type="checkbox" />
                    </label>
                  ))}
                </div>
                <p className="settings-inline-note"><Bell size={14} />Notification delivery requires backend integration; these local choices are saved for the demo.</p>
              </section>

              <section aria-labelledby="security-heading" className="settings-card" id="security">
                <div className="settings-card-heading">
                  <span className="settings-section-icon"><ShieldCheck size={18} /></span>
                  <div><h2 id="security-heading">Security</h2><p>Account security controls are not connected in this demo.</p></div>
                </div>
                <div className="settings-security-row">
                  <span className="settings-security-icon"><LockKeyhole size={18} /></span>
                  <div><strong>Password management</strong><small>Password changes require authentication and a secure backend.</small></div>
                  <button className="settings-tertiary-button" onClick={() => showFeedback("Password management is unavailable until secure authentication is connected.")} type="button">Manage password</button>
                </div>
                <div className="settings-security-row">
                  <span className="settings-security-icon"><UsersRound size={18} /></span>
                  <div><strong>Active sessions</strong><small>Session information will be available when authentication is implemented.</small></div>
                  <span className="settings-coming-soon">Not connected</span>
                </div>
                <div className="settings-card-footer"><span>Passwords and credentials are never stored in this browser demo.</span><button className="settings-danger-button" onClick={() => { if (window.confirm("Sign-out is not connected. Continue to see the demo status?")) showFeedback("Sign-out is unavailable until authentication is connected. No session was changed."); }} type="button">Sign out</button></div>
              </section>

              <section aria-labelledby="subscription-heading" className="settings-card" id="subscription">
                <div className="settings-card-heading">
                  <span className="settings-section-icon"><CreditCard size={18} /></span>
                  <div><h2 id="subscription-heading">Subscription &amp; usage</h2><p>Illustrative plan and usage data for this workspace.</p></div>
                </div>
                <div className="settings-plan-summary">
                  <div className="settings-plan-icon"><Globe2 size={19} /></div>
                  <div><span>CURRENT PLAN</span><strong>Professional</strong><small>Demo plan · billing is not connected</small></div>
                  <button className="settings-tertiary-button" onClick={() => showFeedback("Plan upgrades and billing require backend integration.")} type="button">Upgrade plan</button>
                </div>
                <div className="settings-usage-grid">
                  <div><span>Monthly AI usage</span><strong>680 <small>/ 1,000 actions</small></strong><div className="settings-usage-track"><span /></div></div>
                  <div><span>Remaining credits</span><strong>320 <small>demo credits</small></strong><p>Usage resets at the beginning of each month.</p></div>
                </div>
                <p className="settings-inline-note"><Check size={14} />Usage and credits are sample values and do not represent a real subscription.</p>
              </section>

              <div className="settings-reset-row"><span>Want to start over? This resets profile and preferences on this browser.</span><button onClick={resetDemoSettings} type="button">Reset demo settings</button></div>
              <footer className="settings-footer"><span>© 2026 Likindy Digital Solutions</span><span><ShieldCheck size={13} />Local demo · Not synced to the cloud</span></footer>
            </div>
          </div>
        </div>
      </div>
    </main>
  );
}
