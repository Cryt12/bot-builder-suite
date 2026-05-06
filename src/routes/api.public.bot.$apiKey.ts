import { createFileRoute } from "@tanstack/react-router";

const cors = { "Access-Control-Allow-Origin": "*" };
const API_BASE = process.env.LARAVEL_API_URL ?? "http://127.0.0.1:8082/api";

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

        return new Response(await response.text(), {
          status: response.status,
          headers: { ...cors, "Content-Type": "application/json", "Cache-Control": "public, max-age=60" },
        });
      },
    },
  },
});
