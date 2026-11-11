export type LocalSettings = {
  profile: {
    fullName: string;
    email: string;
    jobTitle: string;
  };
  preferences: {
    language: "English" | "Kiswahili";
    timezone: string;
    dateFormat: "DD MMM YYYY" | "MM/DD/YYYY" | "YYYY-MM-DD";
    appearance: "comfortable" | "compact";
  };
  workspace: {
    name: string;
    type: "Individual" | "Institution" | "Business";
    description: string;
    contact: string;
  };
  notifications: {
    documentActivity: boolean;
    productUpdates: boolean;
    usageAlerts: boolean;
  };
};

export const defaultLocalSettings: LocalSettings = {
  profile: {
    fullName: "Amara Mushi",
    email: "amara.mushi@example.com",
    jobTitle: "Workspace Administrator"
  },
  preferences: {
    language: "English",
    timezone: "Africa/Dar_es_Salaam",
    dateFormat: "DD MMM YYYY",
    appearance: "comfortable"
  },
  workspace: {
    name: "Kilimanjaro Group",
    type: "Business",
    description: "A collaborative workspace for the Kilimanjaro Group team.",
    contact: "office@kilimanjarogroup.example"
  },
  notifications: {
    documentActivity: true,
    productUpdates: false,
    usageAlerts: true
  }
};

export const localSettingsStorageKey = "likindy-local-settings-v1";
export const localSettingsChangedEvent = "likindy-local-settings-changed";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isLocalSettings(value: unknown): value is LocalSettings {
  if (!isRecord(value)
    || !isRecord(value.profile)
    || !isRecord(value.preferences)
    || !isRecord(value.workspace)
    || !isRecord(value.notifications)) return false;

  const { profile, preferences, workspace, notifications } = value;
  return typeof profile.fullName === "string"
    && typeof profile.email === "string"
    && typeof profile.jobTitle === "string"
    && (preferences.language === "English" || preferences.language === "Kiswahili")
    && typeof preferences.timezone === "string"
    && ["DD MMM YYYY", "MM/DD/YYYY", "YYYY-MM-DD"].includes(String(preferences.dateFormat))
    && (preferences.appearance === "comfortable" || preferences.appearance === "compact")
    && typeof workspace.name === "string"
    && ["Individual", "Institution", "Business"].includes(String(workspace.type))
    && typeof workspace.description === "string"
    && typeof workspace.contact === "string"
    && typeof notifications.documentActivity === "boolean"
    && typeof notifications.productUpdates === "boolean"
    && typeof notifications.usageAlerts === "boolean";
}

export function getLocalSettings(): LocalSettings {
  const raw = localStorage.getItem(localSettingsStorageKey);
  if (raw === null) return defaultLocalSettings;
  const parsed: unknown = JSON.parse(raw);
  if (!isLocalSettings(parsed)) throw new Error("Saved settings have an invalid format.");
  return parsed;
}

export function saveLocalSettings(settings: LocalSettings): void {
  localStorage.setItem(localSettingsStorageKey, JSON.stringify(settings));
  window.dispatchEvent(new Event(localSettingsChangedEvent));
}

export function getProfileInitials(fullName: string): string {
  return fullName.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? "").join("") || "U";
}
