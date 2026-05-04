import { createFileRoute } from "@tanstack/react-router";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { MessageSquareHeart } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

export const Route = createFileRoute("/dashboard/feedback")({
  component: FeedbackPage,
});

function FeedbackPage() {
  const [text, setText] = useState("");
  return (
    <div className="p-8 max-w-3xl mx-auto">
      <h1 className="font-display text-3xl font-bold mb-1 flex items-center gap-3">
        <MessageSquareHeart className="h-7 w-7" /> Feedback
      </h1>
      <p className="text-sm text-muted-foreground mb-8">We'd love to hear how we can improve Tuqlas.</p>
      <div className="rounded-2xl border border-border bg-gradient-card p-6 space-y-4">
        <Textarea
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="What would make Tuqlas better for you?"
          rows={6}
        />
        <Button
          className="bg-gradient-primary text-primary-foreground"
          onClick={() => { if (text.trim()) { toast.success("Thanks for your feedback!"); setText(""); } }}
        >
          Send feedback
        </Button>
      </div>
    </div>
  );
}
