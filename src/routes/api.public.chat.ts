import { createFileRoute } from "@tanstack/react-router";
import { z } from "zod";
import { supabaseAdmin } from "@/integrations/supabase/client.server";
import { chatComplete } from "@/server/ai.server";

const cors = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization",
};

const PageContextSchema = z.object({
  pageTitle: z.string().max(500).optional(),
  pageName: z.string().max(500).optional(),
  pageUrl: z.string().max(1000).optional(),
  pageContent: z.string().max(16000).optional(),
  pageSections: z.array(z.object({
    name: z.string().max(200),
    content: z.string().max(4000),
  })).max(20).optional(),
  scrapedAt: z.string().max(100).optional(),
});

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
  pageContext: PageContextSchema.optional(),
});

function hasUsablePageContext(pageContext: z.infer<typeof PageContextSchema> | undefined) {
  if (!pageContext) return false;
  return Boolean(
    pageContext.pageTitle ||
    pageContext.pageName ||
    pageContext.pageContent ||
    pageContext.pageSections?.length,
  );
}

function isCurrentPageQuestion(message: string) {
  const text = message.toLowerCase();
  const patterns = [
    "this page",
    "this webpage",
    "this web page",
    "this screen",
    "current page",
    "where i am",
    "where i am now",
    "explain this",
    "explain this page",
    "explain this webpage",
    "explain this screen",
    "what is this page",
    "what can i do here",
    "explain where i am",
  ];

  return patterns.some((pattern) => text.includes(pattern));
}

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

          const pageAwareQuestion = hasUsablePageContext(data.pageContext) && isCurrentPageQuestion(data.message);

          // Search relevant chunks
          const { data: chunks } = await supabaseAdmin.rpc("search_chunks", {
            query_text: data.message,
            match_chatbot_id: bot.id,
            match_count: pageAwareQuestion ? 3 : 6,
          });

          const context = (chunks ?? []).map((c: any, i: number) => `[${i + 1}] ${c.content}`).join("\n\n");

          const toneMap: Record<string, string> = {
            friendly: "Be warm, conversational and helpful.",
            formal: "Be professional and precise.",
            playful: "Be witty, light, and use emojis sparingly.",
            concise: "Be extremely brief and direct.",
          };

          // Build page context string
          let pageContextStr = "";
          if (data.pageContext) {
            const pc = data.pageContext;
            if (pc.pageTitle || pc.pageName || pc.pageUrl || pc.pageContent || pc.pageSections?.length) {
              pageContextStr = "\n--- CURRENT PAGE CONTEXT ---\n";
              if (pc.pageTitle) pageContextStr += `Page Title: ${pc.pageTitle}\n`;
              if (pc.pageName) pageContextStr += `Current Page Name: ${pc.pageName}\n`;
              if (pc.pageUrl) pageContextStr += `Page URL: ${pc.pageUrl}\n`;
              if (pc.scrapedAt) pageContextStr += `Scraped At: ${pc.scrapedAt}\n`;
              if (pc.pageSections?.length) {
                pageContextStr += "Structured Page Sections:\n";
                for (const section of pc.pageSections) {
                  pageContextStr += `- ${section.name}: ${section.content}\n`;
                }
              }
              if (pc.pageContent) pageContextStr += `Page Content: ${pc.pageContent}\n`;
              pageContextStr += "--- END PAGE CONTEXT ---\n";
            }
          }

          const system = `${bot.system_prompt}

Tone: ${toneMap[bot.tone] ?? toneMap.friendly}
The user may refer to the live page indirectly using phrases like "this page", "this webpage", "this screen", "here", "the current page", or "where I am now".
When that happens, you must answer using the CURRENT PAGE CONTEXT first, even if the user does not paste any page text manually.
If CURRENT PAGE CONTEXT is present, never say you do not know what page the user is referring to.
Use the structured page sections to answer questions about dashboards, point of sale screens, categories, tables, buttons, counts, navigation, forms, cards, and visible content on screen.
If the user asks to "explain" the current page, summarize its purpose, the important UI sections, and what the user can do there.
If the live page context conflicts with older knowledge base content, trust the live page context for page-specific answers.
${pageAwareQuestion ? "\nThis specific user message is definitely about the page they are currently viewing. Give a direct explanation of the current page based on the scraped page context." : ""}
${pageContextStr}${context ? `\nUse the following knowledge base context as supporting information. If the answer is not in either the current page context or the knowledge base context, say you don't have that information.\n\n--- CONTEXT ---\n${context}\n--- END CONTEXT ---` : "\nNote: No knowledge base content matched this query. Answer from the current page context when possible, otherwise politely say you don't have that information yet."}`;

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
          const messages = [...(data.history ?? []).slice(-10)];
          if (data.pageContext && (data.pageContext.pageName || data.pageContext.pageTitle || data.pageContext.pageSections?.length)) {
            const summaryParts: string[] = [];
            if (data.pageContext.pageName) summaryParts.push(`Current page: ${data.pageContext.pageName}`);
            else if (data.pageContext.pageTitle) summaryParts.push(`Current page: ${data.pageContext.pageTitle}`);
            if (data.pageContext.pageSections?.length) {
              const topSections = data.pageContext.pageSections
                .slice(0, 6)
                .map((section) => `${section.name}: ${section.content}`)
                .join(" | ");
              if (topSections) summaryParts.push(`Visible sections: ${topSections}`);
            }
            if (summaryParts.length > 0) {
              messages.push({
                role: "assistant",
                content: `[Live page context for this turn] ${summaryParts.join(" || ")}`,
              });
            }
          }
          messages.push({ role: "user" as const, content: data.message });
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
