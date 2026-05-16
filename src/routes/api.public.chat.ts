import { createFileRoute } from "@tanstack/react-router";
import { getLaravelApiBaseFromEnv } from "../lib/server-env";

const API_BASE = getLaravelApiBaseFromEnv();

function corsHeaders(request: Request) {
  return {
    "Access-Control-Allow-Origin": request.headers.get("origin") ?? "*",
    "Vary": "Origin",
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization, Accept, Origin",
  };
}

const fallbackCors = {
  "Access-Control-Allow-Origin": "*",
  "Vary": "Origin",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization, Accept, Origin",
};

export const Route = createFileRoute("/api/public/chat")({
  server: {
    handlers: {
      OPTIONS: async ({ request }) => new Response(null, { status: 204, headers: corsHeaders(request) }),
      POST: async ({ request }) => {
        try {
          const cors = corsHeaders(request);
          const bodyText = await request.text();
          const response = await fetch(`${API_BASE}/public/chat`, {
            method: "POST",
            headers: {
              "Content-Type": request.headers.get("content-type") || "text/plain;charset=UTF-8",
              Accept: "application/json",
              ...(request.headers.get("origin") ? { Origin: request.headers.get("origin")! } : {}),
              ...(request.headers.get("referer") ? { Referer: request.headers.get("referer")! } : {}),
              ...(request.headers.get("user-agent") ? { "User-Agent": request.headers.get("user-agent")! } : {}),
              ...(request.headers.get("cf-connecting-ip") ? { "CF-Connecting-IP": request.headers.get("cf-connecting-ip")! } : {}),
              ...(request.headers.get("x-forwarded-for") ? { "X-Forwarded-For": request.headers.get("x-forwarded-for")! } : {}),
            },
            body: bodyText,
          });

          return new Response(await response.text(), {
            status: response.status,
            headers: { ...cors, "Content-Type": "application/json" },
          });
        } catch (err: any) {
          console.error("chat error", err);
          const msg = err?.issues ? "Invalid request" : err?.message ?? "Server error";
          return new Response(JSON.stringify({ error: msg }), {
            status: 400, headers: { ...fallbackCors, "Content-Type": "application/json" },
          });
        }
      },
    },
  },
});
