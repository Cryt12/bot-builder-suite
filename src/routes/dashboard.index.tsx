import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Bot, MessageSquare, ArrowUpRight, Plus } from "lucide-react";
import { listBots } from "@/server/bots.functions";

export const Route = createFileRoute("/dashboard/")({
  component: Overview,
});

function Overview() {
  const [count, setCount] = useState(0);
  useEffect(() => {
    listBots().then((r) => setCount(r.bots.length)).catch(() => {});
  }, []);

  return (
    <div className="p-8 max-w-7xl mx-auto">
      <header className="flex items-center justify-between mb-8">
        <h1 className="font-display text-3xl font-bold">Overview</h1>
        <Link to="/dashboard/chatbots">
          <Button className="bg-gradient-primary text-primary-foreground hover:opacity-90 shadow-glow">
            <Plus className="h-4 w-4 mr-2" /> New Chatbot
          </Button>
        </Link>
      </header>

      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
        <StatCard icon={<Bot className="h-5 w-5 text-primary" />} value={count} suffix="/ 1" label="Chatbots" tint="primary" />
        <StatCard icon={<MessageSquare className="h-5 w-5 text-info" />} value={0} suffix="/ 50" label="Messages this month" tint="info" />
        <StatCard icon={<ArrowUpRight className="h-5 w-5 text-primary" />} value="Free" label="Current plan" tint="primary" big />
      </div>

      <h2 className="text-sm font-medium text-muted-foreground mb-3">Quick Actions</h2>
      <div className="grid sm:grid-cols-2 gap-4">
        <QuickAction to="/dashboard/chatbots" icon={<Bot className="h-4 w-4 text-primary" />} label="Manage Chatbots" />
        <QuickAction to="/dashboard/settings" icon={<ArrowUpRight className="h-4 w-4 text-info" />} label="Subscription & Billing" />
      </div>
    </div>
  );
}

function StatCard({ icon, value, suffix, label, big }: any) {
  return (
    <div className="rounded-2xl border border-border bg-gradient-card p-5 hover:border-border-strong transition-colors">
      <div className="flex items-start gap-4">
        <div className="h-10 w-10 rounded-lg bg-surface flex items-center justify-center shrink-0">{icon}</div>
        <div>
          <div className="flex items-baseline gap-2">
            <span className={`font-bold ${big ? "text-2xl" : "text-3xl"}`}>{value}</span>
            {suffix && <span className="text-sm text-muted-foreground">{suffix}</span>}
          </div>
          <div className="text-sm text-muted-foreground mt-0.5">{label}</div>
        </div>
      </div>
    </div>
  );
}

function QuickAction({ to, icon, label }: any) {
  return (
    <Link
      to={to}
      className="group flex items-center justify-between rounded-2xl border border-border bg-gradient-card p-5 hover:border-border-strong transition-colors"
    >
      <div className="flex items-center gap-3">
        <div className="h-9 w-9 rounded-lg bg-surface flex items-center justify-center">{icon}</div>
        <span className="font-medium">{label}</span>
      </div>
      <ArrowUpRight className="h-4 w-4 text-muted-foreground group-hover:text-foreground transition-colors" />
    </Link>
  );
}
