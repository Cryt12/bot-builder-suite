import { createFileRoute } from "@tanstack/react-router";
import { Plug } from "lucide-react";

export const Route = createFileRoute("/dashboard/integrations")({
  component: () => (
    <div className="p-8 max-w-7xl mx-auto">
      <h1 className="font-display text-3xl font-bold mb-8">Integrations</h1>
      <div className="flex flex-col items-center justify-center py-32 text-center">
        <Plug className="h-12 w-12 text-muted-foreground/50 mb-4" />
        <p className="text-lg text-muted-foreground">No integrations connected</p>
        <p className="text-sm text-muted-foreground/70 mt-1">Connect your favourite tools to extend your chatbots.</p>
      </div>
    </div>
  ),
});
