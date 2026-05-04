import { createFileRoute } from "@tanstack/react-router";
import { Bell } from "lucide-react";

export const Route = createFileRoute("/dashboard/notifications")({
  component: () => (
    <div className="p-8 max-w-7xl mx-auto">
      <h1 className="font-display text-3xl font-bold mb-8 flex items-center gap-3">
        <Bell className="h-7 w-7" /> Notifications
      </h1>
      <div className="flex flex-col items-center justify-center py-32 text-center">
        <Bell className="h-12 w-12 text-muted-foreground/50 mb-4" />
        <p className="text-lg text-muted-foreground">No notifications</p>
        <p className="text-sm text-muted-foreground/70 mt-1">You'll see notifications here when leads are captured or updated.</p>
      </div>
    </div>
  ),
});
