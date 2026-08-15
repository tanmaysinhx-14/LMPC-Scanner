import { API_BASE_URL } from "../config";
import { fetch as expoFetch } from "expo/fetch";
import { File } from "expo-file-system";
import type { AiAnalysis, AnalyzeData, ApiEnvelope, AuthData, CitizenDashboardData, FeedData, HeatmapData, IssueDetail, SubmitData, User, WorkerData } from "./types";

let authToken: string | null = null;
let refreshToken: string | null = null;
let refreshPromise: Promise<boolean> | null = null;
let persistCredentials: ((credentials: AuthData) => void | Promise<void>) | null = null;

export type AuthCredentials = Pick<AuthData, "access_token" | "refresh_token">;

export function setAuthToken(token: string | null): void {
  authToken = token;
}

export function setAuthCredentials(access: string | null, refresh: string | null): void {
  authToken = access;
  refreshToken = refresh;
}

export function setAuthPersistence(callback: ((credentials: AuthData) => void | Promise<void>) | null): void {
  persistCredentials = callback;
}

export function getAuthToken(): string | null {
  return authToken;
}

async function refreshAccessToken(): Promise<boolean> {
  if (!refreshToken) return false;
  if (refreshPromise) return refreshPromise;
  refreshPromise = (async () => {
    try {
      const response = await expoFetch(`${API_BASE_URL}/api/v1/auth/refresh.php`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ refresh_token: refreshToken }),
      });
      const payload = (await response.json()) as ApiEnvelope<AuthData>;
      if (!response.ok || payload.status >= 400 || !payload.data?.access_token || !payload.data?.refresh_token) return false;
      setAuthCredentials(payload.data.access_token, payload.data.refresh_token);
      await persistCredentials?.(payload.data);
      return true;
    } catch {
      return false;
    } finally {
      refreshPromise = null;
    }
  })();
  return refreshPromise;
}

async function request<T>(path: string, init: RequestInit = {}, allowRefresh = true): Promise<ApiEnvelope<T>> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(init.headers as Record<string, string> | undefined),
  };
  if (authToken) headers.Authorization = `Bearer ${authToken}`;

  const response = await expoFetch(`${API_BASE_URL}${path}`, { ...init, headers });

  let payload: ApiEnvelope<T> | null = null;
  try {
    const raw = await response.text();
    payload = JSON.parse(raw) as ApiEnvelope<T>;
  } catch {
    throw new Error(`The CivicConnect API did not return JSON (${response.status}). Check EXPO_PUBLIC_API_BASE_URL; it must point to PHP, not Expo/Metro (usually port 8081).`);
  }

  if (response.status === 401 && allowRefresh && refreshToken && !path.includes("/auth/login") && !path.includes("/auth/refresh")) {
    if (await refreshAccessToken()) return request<T>(path, init, false);
  }

  if (!response.ok || !payload || payload.status >= 400) {
    throw new Error(payload?.message || `CivicConnect API request failed (${response.status}).`);
  }

  return payload;
}

async function get<T>(path: string): Promise<ApiEnvelope<T>> {
  return request<T>(path);
}

async function postJson<T>(path: string, body: unknown): Promise<ApiEnvelope<T>> {
  return request<T>(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}

export type UploadAsset = {
  uri: string;
  fileName?: string | null;
  mimeType?: string | null;
};

function appendUpload(form: FormData, asset: UploadAsset): void {
  // React Native 0.86 no longer accepts the legacy `{ uri, name, type }`
  // object as a FormData part. Expo's File is a real native Blob and keeps
  // the upload compatible with both Android and iOS standalone builds.
  form.append("issueImage", new File(asset.uri));
}

async function postIssueMultipart<T>(path: string, asset: UploadAsset, fields: Record<string, string>): Promise<ApiEnvelope<T>> {
  const form = new FormData();
  appendUpload(form, asset);
  Object.entries(fields).forEach(([key, value]) => form.append(key, value));
  return request<T>(path, { method: "POST", body: form });
}

export const civicConnectApi = {
  getFeed: () => get<FeedData>("/api/issues/list.php?sort=hot&limit=20"),
  getHeatmap: () => get<HeatmapData>("/api/stats/heatmap.php"),
  login: (email: string, password: string, role = "citizen") => postJson<AuthData>("/api/v1/auth/login.php", { email, password, role }),
  refresh: (token: string) => postJson<AuthData>("/api/v1/auth/refresh.php", { refresh_token: token }),
  getMe: () => get<{ user: User }>("/api/v1/auth/me.php"),
  logout: (token: string | null) => postJson<null>("/api/v1/auth/logout.php", { refresh_token: token }),
  getCitizenDashboard: () => get<CitizenDashboardData>("/api/v1/citizen/dashboard.php"),
  getWorkerData: () => get<WorkerData>("/api/v1/work/assignments.php"),
  getIssueDetail: (id: number) => get<{ issue: IssueDetail }>(`/api/issues/detail.php?id=${encodeURIComponent(id)}`),
  toggleUpvote: (id: number) => postJson<{ issue_id: number; upvoted: boolean; upvote_count: number }>("/api/issues/upvote.php", { issue_id: id }),
  updateStatus: (id: number, status: string, note = "") => postJson<{ issue_id: number; old_status: string; new_status: string }>("/api/issues/status.php", { issue_id: id, status, note }),
  requestWork: (id: number, message: string) => postJson<{ request_id: number; issue_id: number; issue_title: string }>("/api/work/request.php", { issue_id: id, message }),
  verifyResolution: (id: number, action: "verify" | "reopen", reason = "") => postJson<{ issue_id: number; action: string }>("/api/issues/verify-resolution.php", { issue_id: id, action, reason }),
  analyzeIssue: (asset: UploadAsset, category: string, latitude?: number, longitude?: number) => postIssueMultipart<AnalyzeData>("/api/issues/analyze.php", asset, {
    issueCategory: category,
    latitude: latitude === undefined ? "" : String(latitude),
    longitude: longitude === undefined ? "" : String(longitude),
  }),
  submitIssue: (asset: UploadAsset, fields: { category: string; description: string; latitude: number; longitude: number; address: string; accuracy?: number }) => postIssueMultipart<SubmitData>("/api/issues/submit.php", asset, {
    issueCategory: fields.category,
    issueDescription: fields.description,
    latitude: String(fields.latitude),
    longitude: String(fields.longitude),
    issueLocation: fields.address,
    gps_accuracy: fields.accuracy === undefined ? "" : String(fields.accuracy),
  }),
};
