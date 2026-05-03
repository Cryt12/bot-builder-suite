import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { requireSupabaseAuth } from "@/integrations/supabase/auth-middleware";
import { supabaseAdmin } from "@/integrations/supabase/client.server";
import { chunkText, fetchUrlText, parsePdf, parseDocx } from "./ingest.server";

// ---------- Bots ----------
export const listBots = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }) => {
    const { data, error } = await context.supabase
      .from("chatbots")
      .select("*")
      .order("created_at", { ascending: false });
    if (error) throw new Error(error.message);
    return { bots: data ?? [] };
  });

export const getBot = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { id: string }) => z.object({ id: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const { data: bot, error } = await context.supabase
      .from("chatbots")
      .select("*")
      .eq("id", data.id)
      .single();
    if (error) throw new Error(error.message);
    return { bot };
  });

export const createBot = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { name: string }) =>
    z.object({ name: z.string().min(1).max(80) }).parse(d)
  )
  .handler(async ({ data, context }) => {
    const { data: bot, error } = await context.supabase
      .from("chatbots")
      .insert({ name: data.name, user_id: context.userId })
      .select()
      .single();
    if (error) throw new Error(error.message);
    return { bot };
  });

export const updateBot = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: any) =>
    z.object({
      id: z.string().uuid(),
      name: z.string().min(1).max(80).optional(),
      welcome_message: z.string().max(500).optional(),
      system_prompt: z.string().max(4000).optional(),
      primary_color: z.string().regex(/^#[0-9a-fA-F]{6}$/).optional(),
      bubble_position: z.enum(["right", "left"]).optional(),
      tone: z.enum(["friendly", "formal", "playful", "concise"]).optional(),
      collect_email: z.boolean().optional(),
      is_active: z.boolean().optional(),
    }).parse(d)
  )
  .handler(async ({ data, context }) => {
    const { id, ...patch } = data;
    const { error } = await context.supabase.from("chatbots").update(patch).eq("id", id);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

export const deleteBot = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { id: string }) => z.object({ id: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase.from("chatbots").delete().eq("id", data.id);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

// ---------- Knowledge sources ----------
export const listSources = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { chatbotId: string }) => z.object({ chatbotId: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const { data: rows, error } = await context.supabase
      .from("knowledge_sources")
      .select("*")
      .eq("chatbot_id", data.chatbotId)
      .order("created_at", { ascending: false });
    if (error) throw new Error(error.message);
    return { sources: rows ?? [] };
  });

export const deleteSource = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { id: string }) => z.object({ id: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const { error } = await context.supabase.from("knowledge_sources").delete().eq("id", data.id);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

// ---------- Ingest helpers ----------
async function ingestText(opts: {
  userId: string;
  chatbotId: string;
  sourceType: "file" | "url" | "text";
  name: string;
  text: string;
  url?: string;
  storage_path?: string;
  size_bytes?: number;
}) {
  // Create source row
  const { data: source, error: e1 } = await supabaseAdmin
    .from("knowledge_sources")
    .insert({
      user_id: opts.userId,
      chatbot_id: opts.chatbotId,
      source_type: opts.sourceType,
      name: opts.name,
      url: opts.url ?? null,
      storage_path: opts.storage_path ?? null,
      size_bytes: opts.size_bytes ?? null,
      status: "processing",
    })
    .select()
    .single();
  if (e1 || !source) throw new Error(e1?.message ?? "source insert failed");

  try {
    const chunks = chunkText(opts.text);
    if (chunks.length === 0) throw new Error("No extractable text");
    const rows = chunks.map((content, idx) => ({
      source_id: source.id,
      chatbot_id: opts.chatbotId,
      user_id: opts.userId,
      content,
      chunk_index: idx,
    }));
    // Insert in batches of 200
    for (let i = 0; i < rows.length; i += 200) {
      const slice = rows.slice(i, i + 200);
      const { error } = await supabaseAdmin.from("document_chunks").insert(slice);
      if (error) throw new Error(error.message);
    }
    await supabaseAdmin
      .from("knowledge_sources")
      .update({ status: "ready", chunk_count: chunks.length })
      .eq("id", source.id);
    return { sourceId: source.id, chunks: chunks.length };
  } catch (err: any) {
    await supabaseAdmin
      .from("knowledge_sources")
      .update({ status: "error", error_message: err.message })
      .eq("id", source.id);
    throw err;
  }
}

export const ingestUrl = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { chatbotId: string; url: string }) =>
    z.object({ chatbotId: z.string().uuid(), url: z.string().url().max(2048) }).parse(d)
  )
  .handler(async ({ data, context }) => {
    // Verify ownership
    const { data: bot } = await context.supabase.from("chatbots").select("id").eq("id", data.chatbotId).single();
    if (!bot) throw new Error("Bot not found");

    const { title, text } = await fetchUrlText(data.url);
    return ingestText({
      userId: context.userId,
      chatbotId: data.chatbotId,
      sourceType: "url",
      name: title.slice(0, 200),
      url: data.url,
      text,
    });
  });

export const ingestRawText = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { chatbotId: string; name: string; text: string }) =>
    z.object({
      chatbotId: z.string().uuid(),
      name: z.string().min(1).max(200),
      text: z.string().min(20).max(500_000),
    }).parse(d)
  )
  .handler(async ({ data, context }) => {
    const { data: bot } = await context.supabase.from("chatbots").select("id").eq("id", data.chatbotId).single();
    if (!bot) throw new Error("Bot not found");
    return ingestText({
      userId: context.userId,
      chatbotId: data.chatbotId,
      sourceType: "text",
      name: data.name,
      text: data.text,
    });
  });

// File ingestion: client uploads to storage, then calls this with the path
export const ingestFile = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { chatbotId: string; storagePath: string; name: string; size: number; kind: "pdf" | "docx" | "txt" }) =>
    z.object({
      chatbotId: z.string().uuid(),
      storagePath: z.string().min(1).max(512),
      name: z.string().min(1).max(255),
      size: z.number().int().nonnegative().max(20 * 1024 * 1024),
      kind: z.enum(["pdf", "docx", "txt"]),
    }).parse(d)
  )
  .handler(async ({ data, context }) => {
    const { data: bot } = await context.supabase.from("chatbots").select("id").eq("id", data.chatbotId).single();
    if (!bot) throw new Error("Bot not found");

    // Path must start with the user's id to satisfy storage RLS
    if (!data.storagePath.startsWith(context.userId + "/")) {
      throw new Error("Invalid storage path");
    }

    // Download via admin (bypasses RLS but we already verified ownership)
    const { data: file, error: dlErr } = await supabaseAdmin.storage
      .from("knowledge")
      .download(data.storagePath);
    if (dlErr || !file) throw new Error(dlErr?.message ?? "download failed");

    const buf = await file.arrayBuffer();
    let text = "";
    if (data.kind === "pdf") text = await parsePdf(buf);
    else if (data.kind === "docx") text = await parseDocx(buf);
    else text = new TextDecoder().decode(buf);

    return ingestText({
      userId: context.userId,
      chatbotId: data.chatbotId,
      sourceType: "file",
      name: data.name,
      storage_path: data.storagePath,
      size_bytes: data.size,
      text,
    });
  });

// ---------- Conversations / analytics ----------
export const listConversations = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { chatbotId: string }) => z.object({ chatbotId: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const { data: rows, error } = await context.supabase
      .from("conversations")
      .select("id, created_at, visitor_id, visitor_email, source")
      .eq("chatbot_id", data.chatbotId)
      .order("created_at", { ascending: false })
      .limit(100);
    if (error) throw new Error(error.message);
    return { conversations: rows ?? [] };
  });

export const getMessages = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { conversationId: string }) => z.object({ conversationId: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const { data: rows, error } = await context.supabase
      .from("messages")
      .select("id, role, content, created_at")
      .eq("conversation_id", data.conversationId)
      .order("created_at", { ascending: true });
    if (error) throw new Error(error.message);
    return { messages: rows ?? [] };
  });

export const getAnalytics = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((d: { chatbotId: string }) => z.object({ chatbotId: z.string().uuid() }).parse(d))
  .handler(async ({ data, context }) => {
    const since = new Date(Date.now() - 30 * 24 * 3600 * 1000).toISOString();
    const [{ count: convCount }, { count: msgCount }, { data: msgs }] = await Promise.all([
      context.supabase.from("conversations").select("*", { count: "exact", head: true }).eq("chatbot_id", data.chatbotId).gte("created_at", since),
      context.supabase.from("messages").select("*", { count: "exact", head: true }).eq("user_id", context.userId).gte("created_at", since),
      context.supabase.from("messages").select("created_at, role").eq("user_id", context.userId).gte("created_at", since),
    ]);

    // Build daily buckets
    const days: Record<string, { date: string; chats: number }> = {};
    for (let i = 29; i >= 0; i--) {
      const d = new Date(Date.now() - i * 86400000);
      const key = d.toISOString().slice(0, 10);
      days[key] = { date: key, chats: 0 };
    }
    (msgs ?? []).forEach((m: any) => {
      if (m.role !== "user") return;
      const k = m.created_at.slice(0, 10);
      if (days[k]) days[k].chats += 1;
    });

    return {
      conversations30d: convCount ?? 0,
      messages30d: msgCount ?? 0,
      daily: Object.values(days),
    };
  });
