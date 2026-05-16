import { createFileRoute } from "@tanstack/react-router";
import { getLaravelApiBaseFromEnv } from "../lib/server-env";

const API_BASE = getLaravelApiBaseFromEnv();

function passHeaders(request: Request) {
  return {
    Accept: "application/json",
    ...(request.headers.get("origin") ? { Origin: request.headers.get("origin")! } : {}),
    ...(request.headers.get("referer") ? { Referer: request.headers.get("referer")! } : {}),
  };
}

export const Route = createFileRoute("/api/public/logo/$apiKey")({
  server: {
    handlers: {
      GET: async ({ params, request }) => {
        const imageResponse = await fetch(`${API_BASE}/public/logo/${params.apiKey}`, {
          headers: passHeaders(request),
        });

        if (!imageResponse.ok) {
          return new Response("Not found", { status: imageResponse.status });
        }

        const headers = new Headers({
          "Content-Type": imageResponse.headers.get("Content-Type") || "application/octet-stream",
          "Cache-Control": imageResponse.headers.get("Cache-Control") || "public, max-age=300",
        });

        const contentLength = imageResponse.headers.get("Content-Length");
        if (contentLength) {
          headers.set("Content-Length", contentLength);
        }

        return new Response(imageResponse.body, {
          status: 200,
          headers,
        });
      },
    },
  },
});
