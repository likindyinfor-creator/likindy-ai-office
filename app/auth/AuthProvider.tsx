"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { apiRequest, resourceData } from "../lib/api";
import type { ApiEnvelope, ApiUser, ApiWorkspace } from "../lib/api";

type AuthStatus = "loading" | "authenticated" | "unauthenticated";
type AuthContextValue = {
  user: ApiUser | null;
  workspaces: ApiWorkspace[];
  workspaceId: string | null;
  status: AuthStatus;
  error: string;
  setWorkspaceId: (workspaceId: string) => void;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  clearError: () => void;
};

const AuthContext = createContext<AuthContextValue | null>(null);

async function loadWorkspaces(): Promise<ApiWorkspace[]> {
  const response = await apiRequest<ApiEnvelope<ApiWorkspace[]>>("/api/v1/workspaces");
  return resourceData(response);
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<ApiUser | null>(null);
  const [workspaces, setWorkspaces] = useState<ApiWorkspace[]>([]);
  const [workspaceId, setWorkspaceIdState] = useState<string | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");
  const [error, setError] = useState("");

  const refreshSession = useCallback(async () => {
    const response = await apiRequest<ApiEnvelope<{ user: ApiUser }>>("/api/v1/auth/me");
    const activeUser = resourceData(response).user;
    const availableWorkspaces = await loadWorkspaces();
    setUser(activeUser);
    setWorkspaces(availableWorkspaces);
    setWorkspaceIdState((current) => availableWorkspaces.some((workspace) => workspace.id === current)
      ? current
      : availableWorkspaces[0]?.id ?? null);
    setStatus("authenticated");
    setError("");
  }, []);

  useEffect(() => {
    void refreshSession().catch((reason: unknown) => {
      setUser(null);
      setWorkspaces([]);
      setWorkspaceIdState(null);
      setStatus("unauthenticated");
      if (reason instanceof Error && "status" in reason && Number(reason.status) === 401) return;
      setError(reason instanceof Error ? reason.message : "Could not verify the current session.");
    });
  }, [refreshSession]);

  const signIn = useCallback(async (email: string, password: string) => {
    setError("");
    await apiRequest<ApiEnvelope<{ user: ApiUser }>>("/api/v1/auth/login", {
      method: "POST",
      body: { email, password }
    });
    await refreshSession();
    setStatus("authenticated");
  }, [refreshSession]);

  const signOut = useCallback(async () => {
    setError("");
    try {
      await apiRequest("/api/v1/auth/logout", { method: "POST" });
      setUser(null);
      setWorkspaces([]);
      setWorkspaceIdState(null);
      setStatus("unauthenticated");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not sign out. Please try again.");
      throw reason;
    }
  }, []);

  const setWorkspaceId = useCallback((nextId: string) => {
    if (workspaces.some((workspace) => workspace.id === nextId)) setWorkspaceIdState(nextId);
  }, [workspaces]);

  const value = useMemo<AuthContextValue>(() => ({
    user,
    workspaces,
    workspaceId,
    status,
    error,
    setWorkspaceId,
    signIn,
    signOut,
    clearError: () => setError("")
  }), [user, workspaces, workspaceId, status, error, setWorkspaceId, signIn, signOut]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used within AuthProvider.");
  return value;
}
