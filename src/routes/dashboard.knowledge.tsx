import { createFileRoute } from "@tanstack/react-router";
import { BookOpen, Upload, Type, Globe, FileText } from "lucide-react";
import { useState } from "react";

export const Route = createFileRoute("/dashboard/knowledge")({
  component: KnowledgePage,
});

function KnowledgePage() {
  const [tab, setTab] = useState<"file" | "text" | "url">("file");
  return (
    <div className="p-8 max-w-7xl mx-auto">
      <h1 className="font-display text-3xl font-bold mb-1">Knowledge</h1>
      <p className="text-sm text-muted-foreground mb-8">Upload files to use as context for your chatbots. Up to 3 files can be attached per chatbot.</p>

      <div className="rounded-2xl border border-border bg-gradient-card p-6 mb-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-semibold">Add Knowledge</h2>
          <div className="flex items-center gap-1 p-1 rounded-lg bg-surface border border-border">
            {[
              { id: "file", label: "File", icon: Upload },
              { id: "text", label: "Text", icon: Type },
              { id: "url", label: "URL", icon: Globe },
            ].map(({ id, label, icon: Icon }) => (
              <button
                key={id}
                onClick={() => setTab(id as any)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm transition-colors ${
                  tab === id ? "bg-background text-foreground" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                <Icon className="h-3.5 w-3.5" /> {label}
              </button>
            ))}
          </div>
        </div>
        <p className="text-xs text-muted-foreground mb-3">Supported: .pdf, .docx, .pptx, .txt, .md, .log · Max 5MB</p>
        <div className="border border-dashed border-border-strong rounded-xl p-12 text-center bg-surface/30">
          <Upload className="h-6 w-6 text-muted-foreground mx-auto mb-2" />
          <p className="text-sm text-muted-foreground">Drag & drop or click to browse</p>
        </div>
      </div>

      <div className="rounded-2xl border border-border bg-gradient-card p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-semibold flex items-center gap-2"><BookOpen className="h-4 w-4 text-primary" /> Your Knowledge Files</h2>
          <span className="text-xs text-muted-foreground">0 files</span>
        </div>
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <FileText className="h-10 w-10 text-muted-foreground/40 mb-2" />
          <p className="text-sm text-muted-foreground">No files uploaded yet.</p>
        </div>
      </div>
    </div>
  );
}
