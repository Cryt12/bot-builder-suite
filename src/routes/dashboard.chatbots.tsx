import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Bot, Plus, MessageSquare, Database, Loader2 } from "lucide-react";
import { listBots, createBot } from "@/server/bots.functions";
import { toast } from "sonner";

export const Route = createFileRoute("/dashboard/chatbots")({
  component: BotsList,
});

function BotsList() {
  const [bots, setBots] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [creating, setCreating] = useState(false);

  async function refresh() {
    setLoading(true);
    try {
      const { bots } = await listBots();
      setBots(bots);
    } catch (e: any) { toast.error(e.message); }
    finally { setLoading(false); }
  }

  useEffect(() => { refresh(); }, []);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) return;
    setCreating(true);
    try {
      await createBot({ data: { name: name.trim() } });
      toast.success("Bot created");
      setName(""); setOpen(false);
      refresh();
    } catch (e: any) { toast.error(e.message); }
    finally { setCreating(false); }
  }

  return (
    <div className="p-8 max-w-7xl mx-auto">
      <header className="flex items-center justify-between mb-8">
        <div>
          <h1 className="font-display text-3xl font-bold">Chatbots</h1>
          <p className="text-sm text-muted-foreground mt-1">Build, train and deploy AI chatbots.</p>
        </div>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button className="bg-gradient-primary text-primary-foreground hover:opacity-90 shadow-glow">
              <Plus className="h-4 w-4 mr-2" /> New Chatbot
            </Button>
          </DialogTrigger>
          <DialogContent className="bg-gradient-card border-border-strong">
            <DialogHeader>
              <DialogTitle>Create a new chatbot</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleCreate} className="space-y-4 pt-2">
              <div className="space-y-1.5">
                <Label htmlFor="bn">Name</Label>
                <Input id="bn" value={name} onChange={(e) => setName(e.target.value)} placeholder="Acme Support Bot" autoFocus />
              </div>
              <Button type="submit" disabled={creating} className="w-full bg-gradient-primary text-primary-foreground">
                {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : "Create chatbot"}
              </Button>
            </form>
          </DialogContent>
        </Dialog>
      </header>

      {loading ? (
        <div className="flex justify-center py-20"><Loader2 className="h-6 w-6 animate-spin text-muted-foreground" /></div>
      ) : bots.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-border-strong p-16 text-center bg-surface/30">
          <div className="mx-auto h-14 w-14 rounded-2xl bg-gradient-primary shadow-glow flex items-center justify-center mb-4">
            <Bot className="h-7 w-7 text-primary-foreground" />
          </div>
          <h3 className="font-semibold text-lg">No chatbots yet</h3>
          <p className="text-sm text-muted-foreground mt-1 max-w-sm mx-auto">
            Create your first chatbot to start training it on your knowledge base.
          </p>
          <Button onClick={() => setOpen(true)} className="mt-6 bg-gradient-primary text-primary-foreground">
            <Plus className="h-4 w-4 mr-2" /> Create your first chatbot
          </Button>
        </div>
      ) : (
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {bots.map((b) => (
            <Link
              key={b.id}
              to="/dashboard/bot/$id"
              params={{ id: b.id }}
              className="group rounded-xl border border-border bg-gradient-card p-5 hover:border-border-strong hover:shadow-elegant transition-all"
            >
              <div className="flex items-start justify-between">
                <div className="h-10 w-10 rounded-lg flex items-center justify-center" style={{ background: b.primary_color }}>
                  <Bot className="h-5 w-5 text-white" />
                </div>
                <span className={`text-[10px] uppercase font-medium tracking-wider px-2 py-0.5 rounded-full ${b.is_active ? "bg-success/15 text-success" : "bg-muted text-muted-foreground"}`}>
                  {b.is_active ? "Live" : "Off"}
                </span>
              </div>
              <h3 className="mt-4 font-semibold group-hover:text-primary transition-colors">{b.name}</h3>
              <div className="mt-4 flex items-center gap-4 text-xs text-muted-foreground">
                <span className="flex items-center gap-1.5"><Database className="h-3.5 w-3.5" /> Knowledge</span>
                <span className="flex items-center gap-1.5"><MessageSquare className="h-3.5 w-3.5" /> Chats</span>
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
