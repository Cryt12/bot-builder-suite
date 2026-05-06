import { createContext, useContext, useEffect, useState, ReactNode } from "react";
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

  return (
    <Ctx.Provider
      value={{
        user,
        token,
        loading,
        refreshUser,
        signOut: async () => {
          const savedToken = authStorage.getToken();
          if (savedToken) {
            await laravelRequest("/auth/logout", { method: "POST", token: savedToken }).catch(() => {});
          }
          authStorage.clear();
          setToken(null);
          setUser(null);
        },
      }}
    >
      {children}
    </Ctx.Provider>
  );
}

export const useAuth = () => useContext(Ctx);
