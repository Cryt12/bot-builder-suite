import { createFileRoute } from "@tanstack/react-router";
import { Bot, MessageSquare, FileText, Users, BarChart3, Palette, Search, BadgeCheck } from "lucide-react";

export const Route = createFileRoute("/dashboard/settings")({
  component: SettingsPage,
});

function SettingsPage() {
  return (
    <div className="p-8 max-w-5xl mx-auto space-y-6">
      <h1 className="font-display text-3xl font-bold">Settings</h1>

      <section className="rounded-2xl border border-border bg-gradient-card p-6">
        <div className="flex items-start justify-between mb-2">
          <h2 className="font-semibold">Current Plan</h2>
          <span className="text-xs px-2.5 py-1 rounded-full bg-muted text-muted-foreground border border-border">Free</span>
        </div>
        <p className="text-sm text-muted-foreground">Try Tuqlas with zero commitment</p>
        <p className="mt-3"><span className="text-2xl font-bold">₱0</span> <span className="text-muted-foreground text-sm">forever</span></p>
      </section>

      <section className="rounded-2xl border border-border bg-gradient-card p-6">
        <h2 className="font-semibold mb-1">Usage This Cycle</h2>
        <p className="text-xs text-muted-foreground mb-5">Billing cycle: May 1 — Jun 1, 2026</p>
        <Usage label="Chatbots" value={0} max={1} />
        <Usage label="Messages" value={0} max={50} />
      </section>

      <section className="rounded-2xl border border-border bg-gradient-card p-6">
        <h2 className="font-semibold mb-4">Plan Limits</h2>
        <div className="grid sm:grid-cols-2 gap-y-3 gap-x-8 text-sm">
          <Limit icon={<Bot className="h-4 w-4 text-info" />} text="1 chatbot" />
          <Limit icon={<MessageSquare className="h-4 w-4 text-info" />} text="50 msgs/mo" />
          <Limit icon={<FileText className="h-4 w-4 text-info" />} text="1 files/chatbot" />
          <Limit icon={<FileText className="h-4 w-4 text-info" />} text="5k chars/file" />
          <Limit icon={<Users className="h-4 w-4 text-info" />} text="No lead capture" />
          <Limit icon={<BarChart3 className="h-4 w-4 text-info" />} text="No analytics" />
          <Limit icon={<Palette className="h-4 w-4 text-info" />} text="No custom appearance" />
          <Limit icon={<BadgeCheck className="h-4 w-4 text-info" />} text="Tuqlas branding" />
          <Limit icon={<Search className="h-4 w-4 text-primary" />} text="No vector search" />
        </div>
      </section>
    </div>
  );
}

function Usage({ label, value, max }: { label: string; value: number; max: number }) {
  const pct = Math.min(100, (value / max) * 100);
  return (
    <div className="mb-4 last:mb-0">
      <div className="flex justify-between text-sm mb-1.5">
        <span>{label}</span>
        <span className="font-mono text-muted-foreground">{value} / {max}</span>
      </div>
      <div className="h-1.5 rounded-full bg-surface overflow-hidden">
        <div className="h-full bg-info rounded-full" style={{ width: `${Math.max(pct, 1.5)}%` }} />
      </div>
    </div>
  );
}

function Limit({ icon, text }: any) {
  return <div className="flex items-center gap-2 text-muted-foreground">{icon}<span>{text}</span></div>;
}
