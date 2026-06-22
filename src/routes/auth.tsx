import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { z } from "zod";
import { authStorage, laravelRequest, type AuthResponse } from "@/lib/laravel-api";
import { useAuth } from "@/lib/auth-context";
import { AppLogo } from "@/components/app-logo";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";

const GOOGLE_STATE_KEY = "helix_google_oauth_state";

const searchSchema = z.object({
  mode: z.enum(["signin", "signup"]).optional().default("signin"),
  code: z.string().optional(),
  state: z.string().optional(),
  error: z.string().optional(),
});

export const Route = createFileRoute("/auth")({
  validateSearch: searchSchema,
  component: AuthPage,
  head: () => ({ meta: [{ title: "Sign in — Helix" }] }),
});

function getGoogleRedirectUri() {
  return import.meta.env.VITE_GOOGLE_REDIRECT_URI || `${window.location.origin}/auth`;
}

function makeState() {
  const bytes = new Uint32Array(4);
  window.crypto.getRandomValues(bytes);
  return Array.from(bytes, (value) => value.toString(16)).join("");
}

function AuthPage() {
  const { mode: initialMode, code, state, error } = Route.useSearch();
  const [mode, setMode] = useState<"signin" | "signup">(initialMode);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [name, setName] = useState("");
  const [loading, setLoading] = useState(false);
  const [googleLoading, setGoogleLoading] = useState(false);
  const navigate = useNavigate();
  const { user, refreshUser } = useAuth();

  useEffect(() => {
    setMode(initialMode);
  }, [initialMode]);

  useEffect(() => {
    if (user) navigate({ to: "/dashboard" });
  }, [user, navigate]);

  useEffect(() => {
    if (error) {
      toast.error("Google sign-in was cancelled.");
      navigate({ to: "/auth", search: { mode } });
      return;
    }

    if (!code) return;

    async function finishGoogleAuth() {
      const savedState = localStorage.getItem(GOOGLE_STATE_KEY);

      if (!state || !savedState || state !== savedState) {
        localStorage.removeItem(GOOGLE_STATE_KEY);
        toast.error("Google sign-in could not be verified. Please try again.");
        navigate({ to: "/auth", search: { mode } });
        return;
      }

      setGoogleLoading(true);

      try {
        const data = await laravelRequest<AuthResponse>("/auth/google", {
          method: "POST",
          body: JSON.stringify({ code, redirect_uri: getGoogleRedirectUri() }),
        });

        authStorage.setToken(data.token);
        localStorage.removeItem(GOOGLE_STATE_KEY);
        await refreshUser();
        toast.success(mode === "signup" ? "Account created! Welcome." : "Welcome back.");
        navigate({ to: "/dashboard" });
      } catch (err: any) {
        localStorage.removeItem(GOOGLE_STATE_KEY);
        toast.error(err.message ?? "Google sign-in failed.");
        navigate({ to: "/auth", search: { mode } });
      } finally {
        setGoogleLoading(false);
      }
    }

    void finishGoogleAuth();
  }, [code, error, mode, navigate, refreshUser, state]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      if (mode === "signup") {
        const data = await laravelRequest<AuthResponse>("/auth/register", {
          method: "POST",
          body: JSON.stringify({ name: name || email.split("@")[0], email, password }),
        });
        authStorage.setToken(data.token);
        await refreshUser();
        toast.success("Account created! Welcome.");
        navigate({ to: "/dashboard" });
      } else {
        const data = await laravelRequest<AuthResponse>("/auth/login", {
          method: "POST",
          body: JSON.stringify({ email, password }),
        });
        authStorage.setToken(data.token);
        await refreshUser();
        toast.success("Welcome back.");
        navigate({ to: "/dashboard" });
      }
    } catch (err: any) {
      toast.error(err.message ?? "Something went wrong");
    } finally {
      setLoading(false);
    }
  }

  function handleGoogleAuth() {
    const clientId = import.meta.env.VITE_GOOGLE_CLIENT_ID;

    if (!clientId) {
      toast.error("Google sign-in is not configured.");
      return;
    }

    const nextState = makeState();
    localStorage.setItem(GOOGLE_STATE_KEY, nextState);
    setGoogleLoading(true);

    const params = new URLSearchParams({
      client_id: clientId,
      redirect_uri: getGoogleRedirectUri(),
      response_type: "code",
      scope: "openid email profile",
      state: nextState,
      prompt: "select_account",
    });

    window.location.assign(`https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`);
  }

  const busy = loading || googleLoading;

  return (
    <div className="min-h-screen flex items-center justify-center bg-background bg-gradient-hero p-6">
      <div className="w-full max-w-md">
        <Link to="/" className="flex items-center justify-center gap-2 font-display font-bold text-lg mb-8">
          <AppLogo imageClassName="h-9 w-9 shadow-glow" />
        </Link>

        <div className="rounded-2xl border border-border-strong bg-gradient-card p-8 shadow-elegant">
          <h1 className="font-display text-2xl font-bold">
            {mode === "signup" ? "Create your account" : "Welcome back"}
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            {mode === "signup" ? "Start training your first bot in minutes." : "Sign in to your dashboard."}
          </p>

          <div className="mt-6 space-y-4">
            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={handleGoogleAuth}
              className="h-11 w-full border-border-strong bg-background/40 text-foreground hover:bg-accent"
            >
              {googleLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <span className="text-base font-semibold">G</span>}
              {mode === "signup" ? "Sign up with Google" : "Sign in with Google"}
            </Button>

            <div className="flex items-center gap-3 text-xs text-muted-foreground">
              <div className="h-px flex-1 bg-border" />
              <span>or</span>
              <div className="h-px flex-1 bg-border" />
            </div>
          </div>

          <form onSubmit={handleSubmit} className="mt-4 space-y-4">
            {mode === "signup" && (
              <div className="space-y-1.5">
                <Label htmlFor="name">Name</Label>
                <Input id="name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Jane Doe" />
              </div>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@company.com" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="password">Password</Label>
              <Input id="password" type="password" required minLength={6} value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" />
            </div>
            <Button type="submit" disabled={busy} className="w-full bg-gradient-primary text-primary-foreground hover:opacity-90 shadow-glow h-11">
              {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : mode === "signup" ? "Create account" : "Sign in"}
            </Button>
          </form>

          <p className="mt-6 text-center text-sm text-muted-foreground">
            {mode === "signup" ? "Already have an account?" : "New to Helix?"}{" "}
            <button
              onClick={() => setMode(mode === "signup" ? "signin" : "signup")}
              className="text-primary hover:underline font-medium"
            >
              {mode === "signup" ? "Sign in" : "Create account"}
            </button>
          </p>
        </div>

        <p className="mt-6 text-center text-xs text-muted-foreground">
          By continuing you agree to our terms and privacy policy.
        </p>
      </div>
    </div>
  );
}
