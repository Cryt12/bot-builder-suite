const API_BASE_URL =
  import.meta.env.VITE_LARAVEL_API_URL?.replace(/\/$/, "") || "/api";

export function getLaravelOrigin() {
  if (API_BASE_URL.startsWith("/")) {
    if (typeof window === "undefined") return "";
    return window.location.origin;
  }

  return API_BASE_URL.replace(/\/api$/, "");
}

export function getLaravelApiBaseUrl() {
  return API_BASE_URL;
}

export interface LaravelUser {
  id: string;
  name: string | null;
  email: string;
  role: string;
}

export interface AuthResponse {
  token: string;
  user: LaravelUser;
}

export async function laravelRequest<T>(
  path: string,
  options: RequestInit & { token?: string | null } = {},
): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");

  if (!(options.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }

  if (options.token) {
    headers.set("Authorization", `Bearer ${options.token}`);
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers,
  });

  const text = await response.text();
  let data: any = null;

  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = null;
    }
  }

  if (!response.ok) {
    const message =
      data?.message ||
      Object.values(data?.errors ?? {})?.flat()?.[0] ||
      (text && !text.trim().startsWith("<") ? text : null) ||
      `Request failed with status ${response.status}`;
    throw new Error(String(message));
  }

  return data as T;
}

export const authStorage = {
  tokenKey: "helix_auth_token",
  getToken() {
    if (typeof window === "undefined") return null;
    return localStorage.getItem(this.tokenKey);
  },
  setToken(token: string) {
    localStorage.setItem(this.tokenKey, token);
  },
  clear() {
    localStorage.removeItem(this.tokenKey);
  },
};
