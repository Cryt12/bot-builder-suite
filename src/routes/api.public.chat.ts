import { createFileRoute } from "@tanstack/react-router";
import { z } from "zod";
import { supabaseAdmin } from "@/integrations/supabase/client.server";
import { chatComplete } from "@/server/ai.server";

const cors = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization",
};

const Body = z.object({
  apiKey: z.string().min(8).max(100),
  message: z.string().min(1).max(2000),
  conversationId: z.string().uuid().optional(),
  visitorId: z.string().min(1).max(64).optional(),
  visitorEmail: z.string().email().max(200).optional().nullable(),
  history: z.array(z.object({
    role: z.enum(["user", "assistant"]),
    content: z.string().max(2000),
  })).max(20).optional(),
});

export const Route = createFileRoute("/api/public/chat")({
  server: {
    handlers: {
      OPTIONS: async () => new Response(null, { status: 204, headers: cors }),
      POST: async ({ request }) => {
        try {
          const json = await request.json();
          const data = Body.parse(json);

          // Look up bot
          const { data: bot, error } = await supabaseAdmin
            .from("chatbots")
            .select("*")
            .eq("api_key", data.apiKey)
            .eq("is_active", true)
            .single();
          if (error || !bot) {
            return new Response(JSON.stringify({ error: "Invalid or inactive API key" }), {
              status: 401, headers: { ...cors, "Content-Type": "application/json" },
            });
          }

          // Search relevant chunks
          const { data: chunks } = await supabaseAdmin.rpc("search_chunks", {
            query_text: data.message,
            match_chatbot_id: bot.id,
            match_count: 6,
          });

          const context = (chunks ?? []).map((c: any, i: number) => `[${i + 1}] ${c.content}`).join("\n\n");

          const toneMap: Record<string, string> = {
            friendly: "Be warm, conversational and helpful.",
            formal: "Be professional and precise.",
            playful: "Be witty, light, and use emojis sparingly.",
            concise: "Be extremely brief and direct.",
          };

          const system = `${bot.system_prompt}

Tone: ${toneMap[bot.tone] ?? toneMap.friendly}
${context ? `\nUse the following context to answer. If the answer isn't in the context, say you don't have that information.\n\n--- CONTEXT ---\n${context}\n--- END CONTEXT ---` : "\nNote: No knowledge base content matched this query. Politely say you don't have that information yet."}`;

          // Get / create conversation
          let conversationId = data.conversationId;
          if (!conversationId) {
            const { data: conv, error: convErr } = await supabaseAdmin
              .from("conversations")
              .insert({
                chatbot_id: bot.id,
                user_id: bot.user_id,
                visitor_id: data.visitorId ?? null,
                visitor_email: data.visitorEmail ?? null,
                source: "widget",
              })
              .select("id")
              .single();
            if (convErr || !conv) throw new Error(convErr?.message);
            conversationId = conv.id;
          }

          // Persist user message
          await supabaseAdmin.from("messages").insert({
            conversation_id: conversationId,
            user_id: bot.user_id,
            role: "user",
            content: data.message,
          });

          // Call LLM
          const messages = [...(data.history ?? []).slice(-10), { role: "user" as const, content: data.message }];
          const aiRes = await chatComplete({ system, messages });
          if (!aiRes.ok) {
            const t = await aiRes.text();
            const status = aiRes.status === 429 || aiRes.status === 402 ? aiRes.status : 500;
            const msg = aiRes.status === 429 ? "Rate limit reached. Please try again in a moment."
              : aiRes.status === 402 ? "AI usage limit reached. Please contact the site owner."
              : "AI service error";
            console.error("AI gateway:", aiRes.status, t);
            return new Response(JSON.stringify({ error: msg }), {
              status, headers: { ...cors, "Content-Type": "application/json" },
            });
          }
          const aiData: any = await aiRes.json();
          const reply = aiData.choices?.[0]?.message?.content ?? "Sorry, I had trouble responding.";

          // Persist assistant message
          await supabaseAdmin.from("messages").insert({
            conversation_id: conversationId,
            user_id: bot.user_id,
            role: "assistant",
            content: reply,
          });

          // Track event
          await supabaseAdmin.from("analytics_events").insert({
            chatbot_id: bot.id,
            user_id: bot.user_id,
            event_type: "message",
            metadata: { length: data.message.length },
          });

          return new Response(JSON.stringify({
            reply,
            conversationId,
            botName: bot.name,
            primaryColor: bot.primary_color,
            welcomeMessage: bot.welcome_message,
            collectEmail: bot.collect_email,
          }), { status: 200, headers: { ...cors, "Content-Type": "application/json" } });
        } catch (err: any) {
          console.error("chat error", err);
          const msg = err?.issues ? "Invalid request" : err?.message ?? "Server error";
          return new Response(JSON.stringify({ error: msg }), {
            status: 400, headers: { ...cors, "Content-Type": "application/json" },
          });
        }
      },
    },
  },
});
