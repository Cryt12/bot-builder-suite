import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from "react";
import { authStorage, laravelRequest, type LaravelUser } from "@/lib/laravel-api";

interface AuthCtx {
  user: LaravelUser | null;
  token: string | null;
  loading: boolean;
  refreshUser: () => Promise<void>;
  signOut: () => Promise<void>;
}

const Ctx = createContext<AuthCtx>({
  user: null,
  token: null,
  loading: true,
  refreshUser: async () => {},
  signOut: async () => {},
});

const IDLE_LOGOUT_MS = 20 * 60 * 1000;
const ACTIVITY_EVENTS = ["mousemove", "mousedown", "keydown", "touchstart", "scroll", "wheel"] as const;

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<LaravelUser | null>(null);
  const [token, setToken] = useState<string | null>(() => authStorage.getToken());
  const [loading, setLoading] = useState(true);

  async function refreshUser() {
    const savedToken = authStorage.getToken();

    if (!savedToken) {
      setUser(null);
      setToken(null);
      setLoading(false);
      return;
    }

    try {
      const data = await laravelRequest<{ user: LaravelUser }>("/auth/me", { token: savedToken });
      setToken(savedToken);
      setUser(data.user);
    } catch {
      authStorage.clear();
      setToken(null);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    refreshUser();
  }, []);

  const signOut = useCallback(async () => {
    const savedToken = authStorage.getToken();
    authStorage.clear();
    setToken(null);
    setUser(null);

    if (savedToken) {
      await laravelRequest("/auth/logout", { method: "POST", token: savedToken }).catch(() => {});
    }
  }, []);

  useEffect(() => {
    if (!token || !user) return;

    let timeoutId = window.setTimeout(() => {
      void signOut();
    }, IDLE_LOGOUT_MS);

    const resetIdleTimer = () => {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => {
        void signOut();
      }, IDLE_LOGOUT_MS);
    };

    ACTIVITY_EVENTS.forEach((eventName) => {
      window.addEventListener(eventName, resetIdleTimer, { passive: true });
    });

    return () => {
      window.clearTimeout(timeoutId);
      ACTIVITY_EVENTS.forEach((eventName) => {
        window.removeEventListener(eventName, resetIdleTimer);
      });
    };
  }, [signOut, token, user]);

  return (
    <Ctx.Provider
      value={{
        user,
        token,
        loading,
        refreshUser,
        signOut,
      }}
    >
      {children}
    </Ctx.Provider>
  );
}

export const useAuth = () => useContext(Ctx);
