import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { Bot, Loader2, MessageSquare, Users, BarChart3 } from "lucide-react";
import { getDashboardAnalytics } from "@/lib/bots-api";
import { toast } from "sonner";

export const Route = createFileRoute("/dashboard/analytics")({
  component: DashboardAnalytics,
});

function DashboardAnalytics() {
  const [data, setData] = useState<null | {
    messagesThisMonth: number;
    sessionsThisMonth: number;
    avgMessagesPerSession: number;
    daily: Array<{ date: string; messages: number }>;
    perBot: Array<{
      id: string;
      name: string;
      primary_color: string | null;
      is_active: boolean;
      messages: number;
      sessions: number;
      avg_messages_per_session: number;
    }>;
  }>(null);

  useEffect(() => {
    getDashboardAnalytics()
      .then(setData)
      .catch((e: any) => toast.error(e.message));
  }, []);

  if (!data) {
    return (
      <div className="p-8 max-w-7xl mx-auto">
        <h1 className="font-display text-3xl font-bold mb-8">Analytics</h1>
        <div className="flex justify-center py-20">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      </div>
    );
  }

  const daily = data.daily ?? [];
  const perBot = data.perBot ?? [];
  const maxMessages = Math.max(1, ...daily.map((entry) => entry.messages));
  const hasActivity = data.messagesThisMonth > 0 || data.sessionsThisMonth > 0 || daily.some((entry) => entry.messages > 0);

  if (!hasActivity && perBot.length === 0) {
    return (
      <div className="p-8 max-w-7xl mx-auto">
        <h1 className="font-display text-3xl font-bold mb-8">Analytics</h1>
        <div className="flex flex-col items-center justify-center py-32 text-center">
          <BarChart3 className="h-12 w-12 text-muted-foreground/50 mb-4" />
          <p className="text-lg text-muted-foreground">No analytics yet</p>
          <p className="text-sm text-muted-foreground/70 mt-1">
            Analytics will appear here once your chatbots receive messages.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-8">
      <div>
        <h1 className="font-display text-3xl font-bold">Analytics</h1>
        <p className="text-sm text-muted-foreground mt-1">
          Track overall usage and compare performance across your chatbots.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <MetricCard
          label="Messages This Month"
          value={data.messagesThisMonth}
          icon={<MessageSquare className="h-4 w-4 text-primary" />}
        />
        <MetricCard
          label="Sessions This Month"
          value={data.sessionsThisMonth}
          icon={<Users className="h-4 w-4 text-cyan-400" />}
        />
        <MetricCard
          label="Avg Messages / Session"
          value={data.avgMessagesPerSession}
          icon={<BarChart3 className="h-4 w-4 text-primary" />}
        />
      </div>

      <section className="rounded-2xl border border-border bg-gradient-card p-6">
        <div className="flex items-center justify-between gap-1 mb-6">
          <div>
            <h2 className="font-semibold text-lg">Message Volume (Last 30 Days)</h2>
            <p className="text-sm text-muted-foreground">User messages across all chatbots.</p>
          </div>
        </div>

        <div className="h-[320px] flex items-end gap-3">
          {daily.map((entry) => (
            <div key={entry.date} className="flex-1 h-full flex flex-col justify-end gap-2 group">
              <div className="flex-1 flex items-end">
                <div
                  className="w-full rounded-t-xl border border-primary/20 bg-gradient-to-b from-primary to-cyan-500 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)] transition-opacity group-hover:opacity-90"
                  style={{ height: `${(entry.messages / maxMessages) * 100}%`, minHeight: entry.messages > 0 ? 10 : 0 }}
                >
                  <div className="opacity-0 group-hover:opacity-100 -translate-y-7 text-center text-[11px] text-foreground">
                    {entry.messages}
                  </div>
                </div>
              </div>
              <div className="text-[11px] text-muted-foreground text-center">
                {entry.date.slice(5)}
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-2xl border border-border bg-gradient-card p-6">
        <div className="mb-5">
          <h2 className="font-semibold text-lg">Per-Chatbot Breakdown (This Month)</h2>
          <p className="text-sm text-muted-foreground">Messages, sessions, and average engagement per bot.</p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-muted-foreground">
                <th className="text-left font-medium py-3">Chatbot</th>
                <th className="text-right font-medium py-3">Messages</th>
                <th className="text-right font-medium py-3">Sessions</th>
                <th className="text-right font-medium py-3">Avg / Session</th>
              </tr>
            </thead>
            <tbody>
              {perBot.map((bot) => (
                <tr key={bot.id} className="border-b border-border/60 last:border-b-0">
                  <td className="py-4">
                    <Link
                      to="/dashboard/bot/$id"
                      params={{ id: bot.id }}
                      search={{ tab: "analytics" }}
                      className="flex items-center gap-3 hover:text-primary transition-colors"
                    >
                      <div
                        className="h-10 w-10 rounded-xl flex items-center justify-center shrink-0"
                        style={{ background: bot.primary_color || "#00b0f0" }}
                      >
                        <Bot className="h-4 w-4 text-white" />
                      </div>
                      <div>
                        <div className="font-medium text-foreground">{bot.name}</div>
                        <div className="text-xs text-muted-foreground">
                          {bot.is_active ? "Active" : "Inactive"}
                        </div>
                      </div>
                    </Link>
                  </td>
                  <td className="py-4 text-right font-medium">{bot.messages}</td>
                  <td className="py-4 text-right font-medium">{bot.sessions}</td>
                  <td className="py-4 text-right font-medium">{bot.avg_messages_per_session}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function MetricCard({
  label,
  value,
  icon,
}: {
  label: string;
  value: number;
  icon: React.ReactNode;
}) {
  return (
    <div className="rounded-2xl border border-border bg-gradient-card p-6">
      <div className="flex items-start justify-between gap-3">
        <div className="text-sm text-muted-foreground">{label}</div>
        <div>{icon}</div>
      </div>
      <div className="font-display text-5xl font-bold mt-4">{value}</div>
    </div>
  );
}
