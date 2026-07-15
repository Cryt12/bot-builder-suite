import { cloudflare } from "@cloudflare/vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import viteReact from "@vitejs/plugin-react";
import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import { defineConfig, loadEnv } from "vite";
import tsconfigPaths from "vite-tsconfig-paths";
import { getLaravelOriginFromEnv } from "./src/lib/server-env";

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const laravelOrigin = getLaravelOriginFromEnv(env);
  return {
    plugins: [
      tanstackStart(),
      viteReact(),
      tsconfigPaths(),
      tailwindcss(),
      ...(command === "build" ? [cloudflare()] : []),
    ],
    build: {
      sourcemap: false,
    },
    server: {
      allowedHosts: ["helix.dostcaraga.ph"],
      proxy: {
        "/api": {
          target: laravelOrigin,
          changeOrigin: true,
        },
        "/storage": {
          target: laravelOrigin,
          changeOrigin: true,
        },
      },
    },
  };
});
