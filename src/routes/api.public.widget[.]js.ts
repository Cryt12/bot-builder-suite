import { createFileRoute } from "@tanstack/react-router";
import { getLaravelApiBaseFromEnv } from "../lib/server-env";

const API_BASE = getLaravelApiBaseFromEnv();

const cors = { "Access-Control-Allow-Origin": "*" };

export const Route = createFileRoute("/api/public/widget.js")({
  server: {
    handlers: {
      GET: async () => {
        const response = await fetch(`${API_BASE}/public/widget.js`, {
          headers: { Accept: "application/javascript" },
        });

        return new Response(await response.text(), {
          status: response.status,
          headers: {
            "Content-Type": "application/javascript; charset=utf-8",
            "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
            Pragma: "no-cache",
            Expires: "0",
            ...cors,
          },
        });
      },
    },
  },
});
