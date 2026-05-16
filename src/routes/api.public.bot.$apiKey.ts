import { createFileRoute } from "@tanstack/react-router";
import { getLaravelApiBaseFromEnv } from "../lib/server-env";

const cors = { "Access-Control-Allow-Origin": "*" };
const API_BASE = getLaravelApiBaseFromEnv();

export const Route = createFileRoute("/api/public/bot/$apiKey")({
  server: {
    handlers: {
      OPTIONS: async () => new Response(null, { status: 204, headers: cors }),
      GET: async ({ params, request }) => {
        const response = await fetch(`${API_BASE}/public/bot/${params.apiKey}`, {
          headers: {
            Accept: "application/json",
            ...(request.headers.get("origin") ? { Origin: request.headers.get("origin")! } : {}),
            ...(request.headers.get("referer") ? { Referer: request.headers.get("referer")! } : {}),
          },
        });

        const text = await response.text();
        let body = text;

        try {
          const parsed = text ? JSON.parse(text) : null;
          if (parsed && parsed.logo_url) {
            const requestUrl = new URL(request.url);
            parsed.logo_url = `${requestUrl.protocol}//${requestUrl.host}/api/public/logo/${params.apiKey}`;
            body = JSON.stringify(parsed);
          }
        } catch {
          body = text;
        }

        return new Response(body, {
          status: response.status,
          headers: { ...cors, "Content-Type": "application/json", "Cache-Control": "public, max-age=60" },
        });
      },
    },
  },
});

