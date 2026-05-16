import { authStorage, laravelRequest } from "@/lib/laravel-api";

type FnArgs<T> = { data: T };

function token() {
  return authStorage.getToken();
}

export async function listBots() {
  return laravelRequest<{ bots: any[] }>("/chatbots", { token: token() });
}

export async function getBot({ data }: FnArgs<{ id: string }>) {
  return laravelRequest<{ bot: any }>(`/chatbots/${data.id}`, { token: token() });
}

export async function createBot({ data }: FnArgs<{ name: string }>) {
  return laravelRequest<{ bot: any }>("/chatbots", {
    method: "POST",
    token: token(),
    body: JSON.stringify(data),
  });
}

export async function updateBot({ data }: FnArgs<Record<string, any> & { id: string }>) {
  const { id, ...patch } = data;

  return laravelRequest<{ bot: any }>(`/chatbots/${id}`, {
    method: "PATCH",
    token: token(),
    body: JSON.stringify(patch),
  });
}

export async function deleteBot({ data }: FnArgs<{ id: string }>) {
  return laravelRequest<{ ok: boolean }>(`/chatbots/${data.id}`, {
    method: "DELETE",
    token: token(),
  });
}

export async function uploadBotLogo({ data }: FnArgs<{ chatbotId: string; file: File }>) {
  const body = new FormData();
  body.set("file", data.file);

  return laravelRequest<{ bot: any }>(`/chatbots/${data.chatbotId}/logo`, {
    method: "POST",
    token: token(),
    body,
  });
}

export async function deleteBotLogo({ data }: FnArgs<{ chatbotId: string }>) {
  return laravelRequest<{ bot: any }>(`/chatbots/${data.chatbotId}/logo`, {
    method: "DELETE",
    token: token(),
  });
}

export async function listSources({ data }: FnArgs<{ chatbotId: string }>) {
  return laravelRequest<{ sources: any[] }>(`/chatbots/${data.chatbotId}/sources`, { token: token() });
}

export async function deleteSource({ data }: FnArgs<{ id: string }>) {
  return laravelRequest<{ ok: boolean }>(`/sources/${data.id}`, {
    method: "DELETE",
    token: token(),
  });
}

export async function downloadSourceChunks({ data }: FnArgs<{ id: string; name: string }>) {
  const response = await fetch(`${getApiBaseUrl()}/sources/${data.id}/chunks/download`, {
    method: "GET",
    headers: {
      Accept: "text/plain",
      ...(token() ? { Authorization: `Bearer ${token()}` } : {}),
    },
  });

  if (!response.ok) {
    const text = await response.text();
    let message = "Request failed";
    try {
      const parsed = text ? JSON.parse(text) : null;
      message = parsed?.message || Object.values(parsed?.errors ?? {})?.flat()?.[0] || message;
    } catch {
      if (text) message = text;
    }
    throw new Error(String(message));
  }

  const blob = await response.blob();
  const fileName = `${sanitizeFilename(data.name || "source")}-chunks.txt`;
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = fileName;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

function sanitizeFilename(value: string) {
  return value
    .trim()
    .replace(/\.[^.]+$/, "")
    .replace(/[^a-z0-9-_]+/gi, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "") || "source";
}

function getApiBaseUrl() {
  const envBase = import.meta.env.VITE_LARAVEL_API_URL?.replace(/\/$/, "");
  return envBase || "/api";
}

export async function ingestUrl({ data }: FnArgs<{ chatbotId: string; url: string }>) {
  return laravelRequest<{ sourceId: string; chunks: number }>(`/chatbots/${data.chatbotId}/sources/url`, {
    method: "POST",
    token: token(),
    body: JSON.stringify({ url: data.url }),
  });
}

export async function ingestRawText({ data }: FnArgs<{ chatbotId: string; name: string; text: string }>) {
  return laravelRequest<{ sourceId: string; chunks: number }>(`/chatbots/${data.chatbotId}/sources/text`, {
    method: "POST",
    token: token(),
    body: JSON.stringify({ name: data.name, text: data.text }),
  });
}

export async function ingestFile({ data }: FnArgs<{ chatbotId: string; file: File }>) {
  const body = new FormData();
  body.set("file", data.file);

  return laravelRequest<{ sourceId: string; chunks: number }>(`/chatbots/${data.chatbotId}/sources/file`, {
    method: "POST",
    token: token(),
    body,
  });
}

export async function listConversations({ data }: FnArgs<{ chatbotId: string }>) {
  return laravelRequest<{ conversations: any[] }>(`/chatbots/${data.chatbotId}/conversations`, { token: token() });
}

export async function getMessages({ data }: FnArgs<{ conversationId: string }>) {
  return laravelRequest<{ messages: any[] }>(`/conversations/${data.conversationId}/messages`, { token: token() });
}

export async function playgroundChat({
  data,
}: FnArgs<{
  chatbotId: string;
  message: string;
  conversationId?: string;
  history?: Array<{ role: "user" | "assistant"; content: string }>;
}>) {
  const { chatbotId, ...payload } = data;

  return laravelRequest<{ reply: string; conversationId: string }>(`/chatbots/${chatbotId}/playground-chat`, {
    method: "POST",
    token: token(),
    body: JSON.stringify(payload),
  });
}

export async function getAnalytics({ data }: FnArgs<{ chatbotId: string }>) {
  return laravelRequest<{ conversations30d: number; messages30d: number; daily: any[] }>(
    `/chatbots/${data.chatbotId}/analytics`,
    { token: token() },
  );
}

export async function getDashboardAnalytics() {
  return laravelRequest<{
    messagesThisMonth: number;
    sessionsThisMonth: number;
    avgMessagesPerSession: number;
    daily: Array<{ date: string; messages: number }>;
    perBot: Array<{
      id: string;
      name: string;
      primary_color: string | null;
      is_active: boolean;
      messages: number;
      sessions: number;
      avg_messages_per_session: number;
    }>;
  }>("/analytics", { token: token() });
}

export async function listAdminUsers() {
  return laravelRequest<{
    summary: {
      users: number;
      admins: number;
      chatbots: number;
    };
    users: Array<{
      id: string;
      name: string | null;
      email: string;
      role: string;
      chatbots_count: number;
      created_at: string | null;
      chatbots: Array<{
        id: string;
        name: string;
        primary_color: string | null;
        is_active: boolean;
        created_at: string | null;
      }>;
    }>;
  }>("/admin/users", { token: token() });
}
