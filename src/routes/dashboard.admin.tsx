import { createFileRoute, Link, Navigate } from "@tanstack/react-router";
import { useEffect, useState, type ReactNode } from "react";
import { listAdminUsers } from "@/lib/bots-api";
import { useAuth } from "@/lib/auth-context";
import { Badge } from "@/components/ui/badge";
import { Bot, Shield, Users } from "lucide-react";

type AdminUsersResponse = Awaited<ReturnType<typeof listAdminUsers>>;

export const Route = createFileRoute("/dashboard/admin")({
  component: AdminPage,
});

function AdminPage() {
  const { user } = useAuth();
  const [data, setData] = useState<AdminUsersResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (user?.role !== "admin") return;

    listAdminUsers()
      .then((result) => {
        setData(result);
        setError(null);
      })
      .catch((err) => setError(err.message ?? "Failed to load admin data"))
      .finally(() => setLoading(false));
  }, [user?.role]);

  if (user?.role !== "admin") {
    return <Navigate to="/dashboard" />;
  }

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-8">
      <header className="space-y-2">
        <div className="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-xs text-muted-foreground">
          <Shield className="h-3.5 w-3.5 text-primary" />
          Admin access
        </div>
        <h1 className="font-display text-3xl font-bold">Users & Chatbots</h1>
        <p className="text-sm text-muted-foreground">
          View every account in the workspace and the bots attached to each user.
        </p>
      </header>

      <div className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
        <SummaryCard icon={<Users className="h-5 w-5 text-primary" />} label="Users" value={loading ? "..." : String(data?.summary.users ?? 0)} />
        <SummaryCard icon={<Shield className="h-5 w-5 text-primary" />} label="Admins" value={loading ? "..." : String(data?.summary.admins ?? 0)} />
        <SummaryCard icon={<Bot className="h-5 w-5 text-primary" />} label="Chatbots" value={loading ? "..." : String(data?.summary.chatbots ?? 0)} />
      </div>

      <section className="rounded-2xl border border-border bg-gradient-card p-6">
        {loading ? (
          <p className="text-sm text-muted-foreground">Loading admin data...</p>
        ) : error ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : data && data.users.length > 0 ? (
          <div className="space-y-5">
            {data.users.map((entry) => (
              <div key={entry.id} className="rounded-xl border border-border bg-background/40 p-5">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div>
                    <div className="flex items-center gap-2">
                      <h2 className="text-lg font-semibold">{entry.name || "Unnamed user"}</h2>
                      <Badge className="bg-gradient-primary text-primary-foreground">
                        {entry.role}
                      </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">{entry.email}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {entry.chatbots_count} chatbot{entry.chatbots_count === 1 ? "" : "s"}
                    </p>
                  </div>

                  {entry.chatbots_count > 0 ? (
                    <Link to="/dashboard/chatbots" className="text-sm text-primary hover:underline">
                      Open chatbots page
                    </Link>
                  ) : null}
                </div>

                <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                  {entry.chatbots.length > 0 ? (
                    entry.chatbots.map((bot) => (
                      <div key={bot.id} className="rounded-lg border border-border bg-surface/70 p-4">
                        <div className="flex items-center gap-3">
                          <div
                            className="h-10 w-10 rounded-lg border border-border/60"
                            style={{ background: bot.primary_color || "#00b0f0" }}
                          />
                          <div className="min-w-0">
                            <p className="truncate font-medium">{bot.name}</p>
                            <p className="text-xs text-muted-foreground">
                              {bot.is_active ? "Active" : "Inactive"}
                            </p>
                          </div>
                        </div>
                      </div>
                    ))
                  ) : (
                    <p className="text-sm text-muted-foreground">No chatbots yet.</p>
                  )}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">No users found.</p>
        )}
      </section>
    </div>
  );
}

function SummaryCard({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return (
    <div className="rounded-2xl border border-border bg-gradient-card p-5">
      <div className="flex items-start gap-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-surface">
          {icon}
        </div>
        <div>
          <div className="text-3xl font-bold">{value}</div>
          <div className="mt-0.5 text-sm text-muted-foreground">{label}</div>
        </div>
      </div>
    </div>
  );
}
