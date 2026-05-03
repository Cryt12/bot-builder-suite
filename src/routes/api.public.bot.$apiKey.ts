import { createFileRoute } from "@tanstack/react-router";
import { supabaseAdmin } from "@/integrations/supabase/client.server";

const cors = { "Access-Control-Allow-Origin": "*" };

export const Route = createFileRoute("/api/public/bot/$apiKey")({
  server: {
    handlers: {
      OPTIONS: async () => new Response(null, { status: 204, headers: cors }),
      GET: async ({ params }) => {
        const { data: bot, error } = await supabaseAdmin
          .from("chatbots")
          .select("name, welcome_message, primary_color, bubble_position, collect_email, is_active")
          .eq("api_key", params.apiKey)
          .single();
        if (error || !bot || !bot.is_active) {
          return new Response(JSON.stringify({ error: "Not found" }), {
            status: 404, headers: { ...cors, "Content-Type": "application/json" },
          });
        }
        return new Response(JSON.stringify(bot), {
          status: 200,
          headers: { ...cors, "Content-Type": "application/json", "Cache-Control": "public, max-age=60" },
        });
      },
    },
  },
});
