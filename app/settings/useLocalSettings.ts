"use client";

import { useCallback, useEffect, useState } from "react";
import {
  defaultLocalSettings,
  getLocalSettings,
  localSettingsChangedEvent,
  saveLocalSettings
} from "./storage";
import type { LocalSettings } from "./storage";

export function useLocalSettings() {
  const [settings, setSettings] = useState<LocalSettings>(defaultLocalSettings);
  const [error, setError] = useState("");

  useEffect(() => {
    function syncSettings() {
      try {
        setSettings(getLocalSettings());
        setError("");
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "Could not read saved settings.");
      }
    }

    syncSettings();
    window.addEventListener("storage", syncSettings);
    window.addEventListener(localSettingsChangedEvent, syncSettings);
    return () => {
      window.removeEventListener("storage", syncSettings);
      window.removeEventListener(localSettingsChangedEvent, syncSettings);
    };
  }, []);

  useEffect(() => {
    document.documentElement.lang = settings.preferences.language === "Kiswahili" ? "sw" : "en";
    document.documentElement.dataset.appearance = settings.preferences.appearance;
  }, [settings.preferences.appearance, settings.preferences.language]);

  const updateSettings = useCallback((patch: Partial<LocalSettings>) => {
    try {
      const current = getLocalSettings();
      const next = { ...current, ...patch };
      saveLocalSettings(next);
      setSettings(next);
      setError("");
      return true;
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not save your settings.");
      return false;
    }
  }, []);

  return { settings, updateSettings, error };
}
