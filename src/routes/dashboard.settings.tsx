import { createFileRoute } from "@tanstack/react-router";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Settings, Shield, User } from "lucide-react";

export const Route = createFileRoute("/dashboard/settings")({
  component: SettingsPage,
});

function SettingsPage() {
  return (
    <div className="p-8 max-w-3xl mx-auto space-y-6">
      <h1 className="font-display text-3xl font-bold flex items-center gap-3">
        <Settings className="h-7 w-7" /> Settings
      </h1>

      <section className="rounded-2xl border border-border bg-gradient-card p-6 space-y-4">
        <h2 className="font-semibold flex items-center gap-2">
          <User className="h-4 w-4 text-primary" /> Profile
        </h2>
        <div className="grid sm:grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="display-name">Display name</Label>
            <Input id="display-name" placeholder="Your name" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" placeholder="you@company.com" />
          </div>
        </div>
        <Button className="bg-gradient-primary text-primary-foreground">Save profile</Button>
      </section>

      <section className="rounded-2xl border border-border bg-gradient-card p-6 space-y-4">
        <h2 className="font-semibold flex items-center gap-2">
          <Shield className="h-4 w-4 text-primary" /> Workspace
        </h2>
        <div className="flex items-center justify-between rounded-lg border border-border p-3">
          <div>
            <div className="text-sm font-medium">Email notifications</div>
            <div className="text-xs text-muted-foreground">Receive alerts when a chatbot captures a lead.</div>
          </div>
          <Switch defaultChecked />
        </div>
        <div className="flex items-center justify-between rounded-lg border border-border p-3">
          <div>
            <div className="text-sm font-medium">Weekly summary</div>
            <div className="text-xs text-muted-foreground">Get a digest of chatbot activity and feedback.</div>
          </div>
          <Switch />
        </div>
      </section>
    </div>
  );
}
