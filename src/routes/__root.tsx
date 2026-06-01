import { Outlet, Link, createRootRoute, HeadContent, Scripts } from "@tanstack/react-router";
import { Toaster } from "sonner";
import { AuthProvider } from "@/lib/auth-context";

import appCss from "../styles.css?url";
import helixLogo from "../../helix logo.png";

const chunkLoadRecoveryScript = `
(function () {
  var reloadKey = "helix_chunk_reload";
  var staleChunkPattern = /Loading chunk|ChunkLoadError|Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i;
  var assetPattern = /\/assets\/.*\.(?:js|mjs)(?:\?|$)/i;

  function getReloaded() {
    try {
      return sessionStorage.getItem(reloadKey) === "1";
    } catch {
      return false;
    }
  }

  function setReloaded() {
    try {
      sessionStorage.setItem(reloadKey, "1");
    } catch {}
  }

  function clearReloaded() {
    try {
      sessionStorage.removeItem(reloadKey);
    } catch {}
  }

  function messageFrom(value) {
    return String(
      (value && (value.message || value.reason && value.reason.message || value.error && value.error.message)) ||
        value ||
        ""
    );
  }

  function recover(value) {
    if (!staleChunkPattern.test(messageFrom(value)) || getReloaded()) return;
    setReloaded();
    window.location.reload();
  }

  function recoverScriptAsset(event) {
    if (getReloaded()) return;

    var target = event && event.target;
    var src = target && (target.src || target.href || "");
    var tagName = target && target.tagName;

    if (tagName === "SCRIPT" && assetPattern.test(src)) {
      setReloaded();
      window.location.reload();
    }
  }

  window.addEventListener("load", clearReloaded);
  window.addEventListener("vite:preloadError", function (event) {
    event.preventDefault();
    recover(event);
  });
  window.addEventListener("unhandledrejection", function (event) {
    recover(event.reason || event);
  });
  window.addEventListener("error", function (event) {
    recoverScriptAsset(event);
    recover(event.error || event.message || event);
  }, true);
})();
`.trim();

function NotFoundComponent() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="text-7xl font-bold text-gradient">404</h1>
        <h2 className="mt-4 text-xl font-semibold">Page not found</h2>
        <p className="mt-2 text-sm text-muted-foreground">
          The page you're looking for doesn't exist.
        </p>
        <div className="mt-6">
          <Link
            to="/"
            className="inline-flex items-center justify-center rounded-md bg-gradient-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors"
          >
            Go home
          </Link>
        </div>
      </div>
    </div>
  );
}

export const Route = createRootRoute({
  head: () => ({
    meta: [
      { charSet: "utf-8" },
      { name: "viewport", content: "width=device-width, initial-scale=1" },
      { httpEquiv: "Cache-Control", content: "no-store" },
      { httpEquiv: "Pragma", content: "no-cache" },
      { httpEquiv: "Expires", content: "0" },
      { title: "Helix — AI Chatbots Trained on Your Data" },
      {
        name: "description",
        content:
          "Build, train and deploy AI chatbots from your docs and websites in just seconds. Embed anywhere with one line of code.",
      },
      { name: "author", content: "Helix" },
      { property: "og:title", content: "Helix — AI Chatbots Trained on Your Data" },
      {
        property: "og:description",
        content:
          "Build, train and deploy AI chatbots from your docs and websites in just seconds.",
      },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
    links: [
      { rel: "stylesheet", href: appCss },
      { rel: "icon", type: "image/png", href: helixLogo },
      { rel: "apple-touch-icon", href: helixLogo },
      {
        rel: "preconnect",
        href: "https://fonts.googleapis.com",
      },
      {
        rel: "stylesheet",
        href: "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Geist:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap",
      },
    ],
  }),
  shellComponent: RootShell,
  component: RootComponent,
  notFoundComponent: NotFoundComponent,
});

function RootShell({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="dark">
      <head>
        <script dangerouslySetInnerHTML={{ __html: chunkLoadRecoveryScript }} />
        <HeadContent />
      </head>
      <body>
        {children}
        <Scripts />
      </body>
    </html>
  );
}

function RootComponent() {
  return (
    <AuthProvider>
      <Outlet />
      <Toaster theme="dark" position="top-right" richColors />
    </AuthProvider>
  );
}
