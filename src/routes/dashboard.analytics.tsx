import { createFileRoute } from "@tanstack/react-router";
import { BarChart3 } from "lucide-react";

export const Route = createFileRoute("/dashboard/analytics")({
  component: () => (
    <div className="p-8 max-w-7xl mx-auto">
      <h1 className="font-display text-3xl font-bold mb-8">Analytics</h1>
      <div className="flex flex-col items-center justify-center py-32 text-center">
        <BarChart3 className="h-12 w-12 text-muted-foreground/50 mb-4" />
        <p className="text-lg text-muted-foreground">No analytics yet</p>
        <p className="text-sm text-muted-foreground/70 mt-1">Analytics will appear here once your chatbots receive messages.</p>
      </div>
    </div>
  ),
});
