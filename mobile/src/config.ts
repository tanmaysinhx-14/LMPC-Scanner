export const API_BASE_URL = (process.env.EXPO_PUBLIC_API_BASE_URL ?? "http://127.0.0.1/SIH-2026-Prototype").replace(/\/+$/, "");

export const appConfig = {
  apiBaseUrl: API_BASE_URL,
  hasExplicitApiUrl: Boolean(process.env.EXPO_PUBLIC_API_BASE_URL),
};

export function resolveAssetUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  if (/^https?:\/\//i.test(path)) return path;
  return `${API_BASE_URL}/${path.replace(/^\/+/, "")}`;
}
