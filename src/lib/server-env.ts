const DEFAULT_LARAVEL_ORIGIN = "http://127.0.0.1:8082";

function trimTrailingSlash(value: string) {
  return value.replace(/\/+$/, "");
}

function normalizeApiBase(value: string) {
  const normalized = trimTrailingSlash(value.trim());
  return normalized.endsWith("/api") ? normalized : `${normalized}/api`;
}

export function getLaravelApiBaseFromEnv(
  env: Record<string, string | undefined> = process.env,
) {
  const explicitApiBase = env.LARAVEL_API_URL || env.VITE_LARAVEL_API_URL;
  if (explicitApiBase) {
    return normalizeApiBase(explicitApiBase);
  }

  if (env.APP_URL) {
    return `${trimTrailingSlash(env.APP_URL.trim())}/api`;
  }

  return `${DEFAULT_LARAVEL_ORIGIN}/api`;
}

export function getLaravelOriginFromEnv(
  env: Record<string, string | undefined> = process.env,
) {
  return getLaravelApiBaseFromEnv(env).replace(/\/api$/, "");
}
