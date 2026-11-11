export type ApiEnvelope<T> = {
  data: T;
  meta?: {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
  };
};

export type ApiDocument = {
  id: string;
  workspaceId: string;
  ownerId: string;
  title: string;
  type: "official-letter" | "report" | "proposal" | "meeting-minutes" | "general-document";
  language: "en" | "sw";
  visibility: "private" | "workspace";
  status: "draft" | "completed";
  contentFormat: "html" | "latex";
  contentHtml: string | null;
  latexSource: string | null;
  wordCount: number;
  version: number;
  createdAt: string;
  updatedAt: string;
};

export type ApiWorkspace = {
  id: string;
  name: string;
  type: string;
  role: string;
  status: string;
};

export type ApiUser = {
  id: string;
  name: string;
  email: string;
  email_verified: boolean;
};

export type ApiGrant = {
  userId: string;
  name: string;
  email: string;
  permission: "read" | "edit";
  grantedAt: string;
  updatedAt: string;
};

export type ApiVersion = {
  id: string;
  versionNumber: number;
  title: string;
  status: "draft" | "completed";
  contentHtml: string | null;
  latexSource: string | null;
  changeSummary: string | null;
  changedByUserId: string;
  createdAt: string;
};

export type ApiAttachment = {
  id: string;
  documentId: string;
  originalFilename: string;
  mimeType: string;
  size: number;
  uploadedByUserId: string;
  createdAt: string;
  updatedAt: string;
};

export type ApiMember = {
  id: string;
  user: { id: string; name: string; email: string } | null;
  role: string;
  status: string;
};

export class ApiError extends Error {
  readonly status: number;
  readonly validation: Record<string, string[]>;

  constructor(message: string, status: number, validation: Record<string, string[]> = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.validation = validation;
  }
}

export function formatApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const validationMessages = Object.values(error.validation).flat();
    return [error.message, ...validationMessages].filter(Boolean).join(" ");
  }
  return error instanceof Error ? error.message : fallback;
}

function csrfToken(): string {
  const cookie = document.cookie.split("; ").find((part) => part.startsWith("XSRF-TOKEN="));
  return cookie ? decodeURIComponent(cookie.slice("XSRF-TOKEN=".length)) : "";
}

async function ensureCsrfCookie(): Promise<void> {
  const response = await fetch("/sanctum/csrf-cookie", {
    credentials: "include",
    headers: { Accept: "application/json" }
  });
  if (!response.ok) {
    throw new ApiError("Could not initialize the secure session. Check the API connection and try again.", response.status);
  }
}

type RequestOptions = {
  method?: string;
  body?: unknown;
  formData?: FormData;
  signal?: AbortSignal;
};

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? "GET";
  const mutating = !["GET", "HEAD", "OPTIONS"].includes(method.toUpperCase());
  if (mutating) await ensureCsrfCookie();

  const headers: Record<string, string> = { Accept: "application/json" };
  if (mutating) {
    const token = csrfToken();
    if (!token) throw new ApiError("The secure session token is unavailable. Refresh and try again.", 419);
    headers["X-XSRF-TOKEN"] = token;
  }
  if (options.body !== undefined) headers["Content-Type"] = "application/json";

  const response = await fetch(path, {
    method,
    credentials: "include",
    headers,
    body: options.formData ?? (options.body === undefined ? undefined : JSON.stringify(options.body)),
    signal: options.signal
  });

  if (!response.ok) {
    const payload: unknown = await response.json().catch(() => null);
    const record = typeof payload === "object" && payload !== null ? payload as Record<string, unknown> : {};
    const validation = typeof record.errors === "object" && record.errors !== null
      ? record.errors as Record<string, string[]>
      : {};
    const message = typeof record.message === "string" ? record.message : `The request failed (${response.status}).`;
    throw new ApiError(message, response.status, validation);
  }
  if (response.status === 204) return undefined as T;
  return await response.json() as T;
}

export function apiRequest<T>(path: string, options?: RequestOptions): Promise<T> {
  return request<T>(path, options);
}

export async function apiDownload(path: string): Promise<{ blob: Blob; filename: string }> {
  const response = await fetch(path, {
    credentials: "include",
    headers: { Accept: "application/octet-stream, application/json" }
  });
  if (!response.ok) {
    const payload: unknown = await response.json().catch(() => null);
    const message = typeof payload === "object" && payload !== null && "message" in payload
      && typeof payload.message === "string"
      ? payload.message
      : `The download failed (${response.status}).`;
    throw new ApiError(message, response.status);
  }
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] ?? "attachment";
  return { blob: await response.blob(), filename };
}

export function resourceData<T>(envelope: ApiEnvelope<T>): T {
  return envelope.data;
}

export function apiPath(...segments: string[]): string {
  return `/api/v1/${segments.map((segment) => encodeURIComponent(segment)).join("/")}`;
}
