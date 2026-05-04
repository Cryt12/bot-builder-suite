import { createFileRoute } from "@tanstack/react-router";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Download } from "lucide-react";

export const Route = createFileRoute("/dashboard/leads")({
  component: () => (
    <div className="p-8 max-w-7xl mx-auto">
      <header className="flex items-center justify-between mb-6">
        <div>
          <h1 className="font-display text-3xl font-bold">Leads</h1>
          <p className="text-sm text-muted-foreground mt-1">0 leads captured</p>
        </div>
        <Button variant="outline"><Download className="h-4 w-4 mr-2" /> Export CSV</Button>
      </header>
      <div className="flex gap-3 mb-8">
        <Input placeholder="Search by name, email, or company..." className="flex-1" />
        <select className="rounded-md bg-surface border border-border px-3 py-2 text-sm w-32"><option>all</option></select>
        <select className="rounded-md bg-surface border border-border px-3 py-2 text-sm w-32"><option>all</option></select>
      </div>
      <div className="text-center py-32">
        <p className="text-lg text-muted-foreground">No leads yet</p>
        <p className="text-sm text-muted-foreground/70 mt-1">Leads will appear here once your chatbots capture contact information.</p>
      </div>
    </div>
  ),
});
