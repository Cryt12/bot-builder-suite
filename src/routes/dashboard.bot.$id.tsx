import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import {
  ArrowLeft, Bot, Check, Code2, Copy, Database, FileText, Globe, Loader2,
  MessageSquare, Plus, Send, Settings, Trash2, Upload, BarChart3, Sparkles, Download,
  X,
} from "lucide-react";
import {
  getBot, updateBot, deleteBot, listSources, deleteSource,
  ingestUrl, ingestRawText, ingestFile, downloadSourceChunks,
  listConversations, getMessages, getAnalytics,
} from "@/lib/bots-api";
import { getLaravelOrigin } from "@/lib/laravel-api";
import { toast } from "sonner";

const search = z.object({ tab: z.enum(["knowledge", "playground", "embed", "settings", "analytics", "history"]).optional().default("knowledge") });

export const Route = createFileRoute("/dashboard/bot/$id")({
  validateSearch: search,
  component: BotDetail,
});

function BotDetail() {
  const { id } = Route.useParams();
  const { tab } = Route.useSearch();
  const navigate = useNavigate();
  const [bot, setBot] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  async function refresh() {
    try { const r = await getBot({ data: { id } }); setBot(r.bot); }
    catch (e: any) { toast.error(e.message); }
    finally { setLoading(false); }
  }

  useEffect(() => { refresh(); }, [id]);

  if (loading) return <div className="flex justify-center py-20"><Loader2 className="h-6 w-6 animate-spin text-muted-foreground" /></div>;
  if (!bot) return <div className="p-8">Bot not found.</div>;

  return (
    <div className="max-w-6xl mx-auto p-8">
      <Link to="/dashboard" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-4">
        <ArrowLeft className="h-4 w-4" /> All bots
      </Link>
      <header className="flex items-start justify-between mb-8">
        <div className="flex items-start gap-4">
          <div className="h-12 w-12 rounded-xl flex items-center justify-center shadow-glow" style={{ background: bot.primary_color }}>
            <Bot className="h-6 w-6 text-white" />
          </div>
          <div>
            <h1 className="font-display text-2xl font-bold">{bot.name}</h1>
            <p className="text-sm text-muted-foreground mt-0.5 font-mono">{bot.api_key}</p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs text-muted-foreground">Active</span>
          <Switch
            checked={bot.is_active}
            onCheckedChange={async (v) => {
              await updateBot({ data: { id, is_active: v } });
              setBot({ ...bot, is_active: v });
              toast.success(v ? "Bot activated" : "Bot deactivated");
            }}
          />
        </div>
      </header>

      <Tabs value={tab} onValueChange={(v) => navigate({ to: "/dashboard/bot/$id", params: { id }, search: { tab: v as any } })}>
        <TabsList className="bg-surface border border-border">
          <TabsTrigger value="knowledge"><Database className="h-3.5 w-3.5 mr-1.5" /> Knowledge</TabsTrigger>
          <TabsTrigger value="playground"><Sparkles className="h-3.5 w-3.5 mr-1.5" /> Playground</TabsTrigger>
          <TabsTrigger value="embed"><Code2 className="h-3.5 w-3.5 mr-1.5" /> Embed</TabsTrigger>
          <TabsTrigger value="analytics"><BarChart3 className="h-3.5 w-3.5 mr-1.5" /> Analytics</TabsTrigger>
          <TabsTrigger value="history"><MessageSquare className="h-3.5 w-3.5 mr-1.5" /> History</TabsTrigger>
          <TabsTrigger value="settings"><Settings className="h-3.5 w-3.5 mr-1.5" /> Settings</TabsTrigger>
        </TabsList>

        <TabsContent value="knowledge" className="mt-6"><KnowledgeTab botId={id} /></TabsContent>
        <TabsContent value="playground" className="mt-6"><PlaygroundTab bot={bot} /></TabsContent>
        <TabsContent value="embed" className="mt-6"><EmbedTab bot={bot} onChange={refresh} /></TabsContent>
        <TabsContent value="analytics" className="mt-6"><AnalyticsTab botId={id} /></TabsContent>
        <TabsContent value="history" className="mt-6"><HistoryTab botId={id} /></TabsContent>
        <TabsContent value="settings" className="mt-6"><SettingsTab bot={bot} onChange={refresh} /></TabsContent>
      </Tabs>
    </div>
  );
}

// ---------- Knowledge ----------
function KnowledgeTab({ botId }: { botId: string }) {
  const [sources, setSources] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [url, setUrl] = useState("");
  const [busy, setBusy] = useState(false);
  const [text, setText] = useState("");
  const [textName, setTextName] = useState("");
  const fileRef = useRef<HTMLInputElement>(null);

  async function refresh() {
    setLoading(true);
    try { setSources((await listSources({ data: { chatbotId: botId } })).sources ?? []); }
    catch (e: any) { toast.error(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { refresh(); }, [botId]);

  async function handleUrl(e: React.FormEvent) {
    e.preventDefault();
    if (!url.trim()) return;
    setBusy(true);
    try { await ingestUrl({ data: { chatbotId: botId, url: url.trim() } }); toast.success("URL imported"); setUrl(""); refresh(); }
    catch (e: any) { toast.error(e.message); }
    finally { setBusy(false); }
  }

  async function handleText(e: React.FormEvent) {
    e.preventDefault();
    if (!text.trim() || !textName.trim()) return;
    setBusy(true);
    try {
      await ingestRawText({ data: { chatbotId: botId, name: textName.trim(), text: text.trim() } });
      toast.success("Text added"); setText(""); setTextName(""); refresh();
    } catch (e: any) { toast.error(e.message); }
    finally { setBusy(false); }
  }

  async function handleFiles(files: FileList | null) {
    if (!files) return;
    setBusy(true);
    try {
      for (const f of Array.from(files)) {
        const ext = f.name.split(".").pop()?.toLowerCase();
        const supported = ext === "pdf" || ext === "docx" || ext === "txt" || ext === "md";
        if (!supported) { toast.error(`${f.name}: unsupported type`); continue; }
        if (f.size > 20 * 1024 * 1024) { toast.error(`${f.name}: file too large (>20MB)`); continue; }
        const result = await ingestFile({ data: { chatbotId: botId, file: f } });
        const count = result.chunks === 1 ? "1 chunk" : `${result.chunks} chunks`;
        toast.success(`Imported ${f.name} (${count})`);
      }
      refresh();
    } catch (e: any) { toast.error(e.message); }
    finally { setBusy(false); if (fileRef.current) fileRef.current.value = ""; }
  }

  async function remove(id: string) {
    if (!confirm("Delete this source and its chunks?")) return;
    try { await deleteSource({ data: { id } }); refresh(); }
    catch (e: any) { toast.error(e.message); }
  }

  async function handleDownload(source: any) {
    try {
      await downloadSourceChunks({ data: { id: source.id, name: source.name } });
      toast.success(`Downloaded chunks for ${source.name}`);
    } catch (e: any) {
      toast.error(e.message);
    }
  }

  return (
    <div className="grid lg:grid-cols-2 gap-6">
      <div className="space-y-4">
        <div className="rounded-xl border border-border bg-gradient-card p-5">
          <div className="flex items-center gap-2 mb-3"><Upload className="h-4 w-4 text-primary" /><h3 className="font-semibold">Upload files</h3></div>
          <p className="text-xs text-muted-foreground mb-3">PDF, DOCX, TXT, MD. Max 20MB each.</p>
          <input ref={fileRef} type="file" multiple accept=".pdf,.docx,.txt,.md" onChange={(e) => handleFiles(e.target.files)} className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-accent file:px-3 file:py-2 file:text-sm file:font-medium file:text-accent-foreground hover:file:bg-accent/80 file:cursor-pointer" disabled={busy} />
        </div>

        <form onSubmit={handleUrl} className="rounded-xl border border-border bg-gradient-card p-5">
          <div className="flex items-center gap-2 mb-3"><Globe className="h-4 w-4 text-primary" /><h3 className="font-semibold">Import from URL</h3></div>
          <div className="flex gap-2">
            <Input type="url" placeholder="https://docs.example.com/getting-started" value={url} onChange={(e) => setUrl(e.target.value)} disabled={busy} />
            <Button type="submit" disabled={busy} className="bg-gradient-primary text-primary-foreground">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : "Add"}</Button>
          </div>
        </form>

        <form onSubmit={handleText} className="rounded-xl border border-border bg-gradient-card p-5">
          <div className="flex items-center gap-2 mb-3"><FileText className="h-4 w-4 text-primary" /><h3 className="font-semibold">Paste text</h3></div>
          <div className="space-y-2">
            <Input placeholder="Title (e.g. FAQs)" value={textName} onChange={(e) => setTextName(e.target.value)} disabled={busy} />
            <Textarea placeholder="Paste your content here…" rows={5} value={text} onChange={(e) => setText(e.target.value)} disabled={busy} />
            <Button type="submit" disabled={busy} className="bg-gradient-primary text-primary-foreground w-full">
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : "Add to knowledge base"}
            </Button>
          </div>
        </form>
      </div>

      <div className="rounded-xl border border-border bg-gradient-card p-5">
        <h3 className="font-semibold mb-3">Sources ({sources.length})</h3>
        {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : sources.length === 0 ? (
          <p className="text-sm text-muted-foreground py-8 text-center">No sources yet. Add one to start training.</p>
        ) : (
          <ul className="space-y-2 max-h-[600px] overflow-auto">
            {sources.map((s) => (
              <li key={s.id} className="flex items-center gap-3 rounded-lg border border-border bg-surface p-3">
                <div className="h-8 w-8 rounded-md bg-accent flex items-center justify-center shrink-0">
                  {s.source_type === "url" ? <Globe className="h-4 w-4 text-primary" /> : s.source_type === "file" ? <FileText className="h-4 w-4 text-primary" /> : <FileText className="h-4 w-4 text-primary" />}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium truncate">{s.name}</div>
                  <div className="text-xs text-muted-foreground flex items-center gap-2">
                    {s.status === "ready" ? <span className="text-success">{s.chunk_count} chunks</span>
                      : s.status === "error" ? <span className="text-destructive">Error: {s.error_message}</span>
                      : <span className="flex items-center gap-1"><Loader2 className="h-3 w-3 animate-spin" /> {s.status}</span>}
                  </div>
                </div>
                <div className="flex items-center gap-1">
                  <button
                    onClick={() => handleDownload(s)}
                    disabled={s.status !== "ready" || !s.chunk_count}
                    className="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-accent/60 disabled:opacity-40 disabled:cursor-not-allowed"
                    title="Download source chunks"
                  >
                    <Download className="h-3.5 w-3.5" />
                  </button>
                  <button onClick={() => remove(s.id)} className="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-destructive/10">
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

// ---------- Playground ----------
function PlaygroundTab({ bot }: { bot: any }) {
  const [messages, setMessages] = useState<{ role: "user" | "assistant"; content: string }[]>([
    { role: "assistant", content: bot.welcome_message },
  ]);
  const [input, setInput] = useState("");
  const [sending, setSending] = useState(false);
  const [convId, setConvId] = useState<string | undefined>();
  const bodyRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [messages, sending]);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    if (!input.trim() || sending) return;
    const msg = input.trim();
    setInput(""); setSending(true);
    setMessages((m) => [...m, { role: "user", content: msg }]);
    try {
      const r = await fetch("/api/public/chat", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          apiKey: bot.api_key, message: msg, conversationId: convId,
          history: messages.slice(-10),
        }),
      });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error);
      setConvId(j.conversationId);
      setMessages((m) => [...m, { role: "assistant", content: j.reply }]);
    } catch (e: any) {
      setMessages((m) => [...m, { role: "assistant", content: "⚠ " + e.message }]);
    } finally { setSending(false); }
  }

  return (
    <div className="rounded-xl border border-border bg-gradient-card overflow-hidden flex flex-col h-[640px] max-w-3xl mx-auto">
      <div className="px-5 py-3 border-b border-border flex items-center gap-2">
        <Sparkles className="h-4 w-4 text-primary" />
        <span className="font-semibold text-sm">Test your bot</span>
      </div>
      <div ref={bodyRef} className="flex-1 overflow-y-auto p-5 space-y-3 bg-surface/30">
        {messages.map((m, i) => (
          <div key={i} className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap ${m.role === "user" ? "ml-auto rounded-tr-sm text-primary-foreground" : "rounded-tl-sm bg-surface border border-border"}`}
               style={m.role === "user" ? { background: bot.primary_color } : {}}>
            {m.content}
          </div>
        ))}
        {sending && (
          <div className="bg-surface border border-border rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%] flex gap-1.5">
            <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground animate-bounce" />
            <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground animate-bounce [animation-delay:0.15s]" />
            <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground animate-bounce [animation-delay:0.3s]" />
          </div>
        )}
      </div>
      <form onSubmit={send} className="border-t border-border p-3 flex gap-2">
        <Input value={input} onChange={(e) => setInput(e.target.value)} placeholder="Ask anything…" disabled={sending} />
        <Button type="submit" disabled={sending || !input.trim()} className="bg-gradient-primary text-primary-foreground">
          {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
        </Button>
      </form>
    </div>
  );
}

// ---------- Embed ----------
function normalizeDomain(value: string) {
  const trimmed = value.trim().toLowerCase();
  if (!trimmed) return "";

  try {
    const url = trimmed.includes("://") ? new URL(trimmed) : new URL(`http://${trimmed}`);
    return url.hostname;
  } catch {
    return trimmed.split("/")[0].split(":")[0];
  }
}

function EmbedTab({ bot, onChange }: { bot: any; onChange: () => void }) {
  const [copied, setCopied] = useState<string | null>(null);
  const [domains, setDomains] = useState<string[]>(bot.allowed_domains ?? []);
  const [domainInput, setDomainInput] = useState("");
  const [savingDomains, setSavingDomains] = useState(false);
  const widgetOrigin = getLaravelOrigin() || "http://127.0.0.1:8082";
  const snippet = `<script src="${widgetOrigin}/api/public/widget.js" data-api-key="${bot.api_key}" defer></script>`;
  const apiCurl = `curl -X POST ${widgetOrigin}/api/public/chat \\
  -H "Content-Type: application/json" \\
  -d '{"apiKey":"${bot.api_key}","message":"Hello"}'`;

  useEffect(() => {
    setDomains(bot.allowed_domains ?? []);
    setDomainInput("");
  }, [bot.id, bot.allowed_domains]);

  function copy(text: string, k: string) {
    navigator.clipboard.writeText(text);
    setCopied(k); setTimeout(() => setCopied(null), 1500);
    toast.success("Copied to clipboard");
  }

  function addDomain() {
    const domain = normalizeDomain(domainInput);
    if (!domain) return;
    setDomains((current) => current.includes(domain) ? current : [...current, domain]);
    setDomainInput("");
  }

  function removeDomain(domain: string) {
    setDomains((current) => current.filter((d) => d !== domain));
  }

  async function saveDomains() {
    const pending = normalizeDomain(domainInput);
    const nextDomains = pending && !domains.includes(pending)
      ? [...domains, pending]
      : domains;

    if (nextDomains.length === 0) {
      toast.error("At least one domain is required");
      return;
    }

    setSavingDomains(true);
    try {
      const response = await updateBot({ data: { id: bot.id, allowed_domains: nextDomains } });
      setDomains(response.bot.allowed_domains ?? nextDomains);
      setDomainInput("");
      toast.success("Allowed domains saved");
      onChange();
    } catch (e: any) {
      toast.error(e.message);
    } finally {
      setSavingDomains(false);
    }
  }

  return (
    <div className="space-y-6 max-w-3xl">
      <div className="rounded-xl border border-border bg-gradient-card p-6">
        <h3 className="font-semibold mb-1 flex items-center gap-2"><Globe className="h-4 w-4 text-primary" /> Allowed domains <span className="text-destructive">*</span></h3>
        {domains.length === 0 && <p className="text-sm text-destructive mb-3">At least one domain is required.</p>}
        <div className="flex gap-2">
          <Input
            value={domainInput}
            onChange={(e) => setDomainInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                addDomain();
              }
            }}
            placeholder="example.com or https://example.com/page"
          />
          <Button type="button" variant="outline" onClick={addDomain} className="shrink-0 bg-surface">
            <Plus className="h-4 w-4" />
          </Button>
        </div>
        <p className="text-xs text-muted-foreground mt-2">Press Enter or + to add. Paste a full URL and Helix will extract the domain. e.g. localhost, 127.0.0.1, mysite.com</p>
        {domains.length > 0 && (
          <div className="mt-4 flex flex-wrap gap-2">
            {domains.map((domain) => (
              <span key={domain} className="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-2.5 py-1 text-sm">
                {domain}
                <button type="button" onClick={() => removeDomain(domain)} className="rounded-sm text-muted-foreground hover:text-destructive">
                  <X className="h-3.5 w-3.5" />
                </button>
              </span>
            ))}
          </div>
        )}
        <Button onClick={saveDomains} disabled={savingDomains || domains.length === 0} className="mt-4 bg-gradient-primary text-primary-foreground">
          {savingDomains ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save domains"}
        </Button>
      </div>

      <div className="rounded-xl border border-border bg-gradient-card p-6">
        <h3 className="font-semibold mb-1 flex items-center gap-2"><Code2 className="h-4 w-4 text-primary" /> Embed snippet</h3>
        <p className="text-sm text-muted-foreground mb-4">Paste this into your site's HTML, just before <code className="text-xs bg-surface px-1.5 py-0.5 rounded">&lt;/body&gt;</code>.</p>
        <div className="relative">
          <pre className="rounded-lg border border-border bg-background p-4 text-xs font-mono overflow-x-auto">{snippet}</pre>
          <Button size="sm" variant="outline" onClick={() => copy(snippet, "embed")} className="absolute top-2 right-2 bg-surface">
            {copied === "embed" ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
          </Button>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-gradient-card p-6">
        <h3 className="font-semibold mb-1">API key</h3>
        <p className="text-sm text-muted-foreground mb-4">Use this to call the chat API directly from your own backend.</p>
        <div className="flex items-center gap-2">
          <code className="flex-1 rounded-lg border border-border bg-background p-3 text-sm font-mono">{bot.api_key}</code>
          <Button variant="outline" onClick={() => copy(bot.api_key, "key")} className="bg-surface">
            {copied === "key" ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
          </Button>
        </div>
        <div className="mt-4">
          <div className="text-xs uppercase tracking-wider text-muted-foreground mb-2">Example request</div>
          <div className="relative">
            <pre className="rounded-lg border border-border bg-background p-4 text-xs font-mono overflow-x-auto">{apiCurl}</pre>
            <Button size="sm" variant="outline" onClick={() => copy(apiCurl, "curl")} className="absolute top-2 right-2 bg-surface">
              {copied === "curl" ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Analytics ----------
function AnalyticsTab({ botId }: { botId: string }) {
  const [data, setData] = useState<any>(null);
  useEffect(() => { getAnalytics({ data: { chatbotId: botId } }).then(setData).catch((e) => toast.error(e.message)); }, [botId]);
  if (!data) return <Loader2 className="h-5 w-5 animate-spin" />;
  const daily = data.daily ?? [];
  const max = Math.max(1, ...daily.map((d: any) => d.chats));
  return (
    <div className="space-y-6 max-w-4xl">
      <div className="grid sm:grid-cols-2 gap-4">
        <div className="rounded-xl border border-border bg-gradient-card p-6">
          <div className="text-xs uppercase tracking-wider text-muted-foreground">Conversations (30d)</div>
          <div className="font-display text-4xl font-bold mt-2">{data.conversations30d}</div>
        </div>
        <div className="rounded-xl border border-border bg-gradient-card p-6">
          <div className="text-xs uppercase tracking-wider text-muted-foreground">Messages (30d)</div>
          <div className="font-display text-4xl font-bold mt-2">{data.messages30d}</div>
        </div>
      </div>
      <div className="rounded-xl border border-border bg-gradient-card p-6">
        <div className="text-xs uppercase tracking-wider text-muted-foreground mb-4">Daily chats</div>
        <div className="flex items-end gap-1 h-40">
          {daily.map((d: any) => (
            <div key={d.date} className="flex-1 flex flex-col items-center gap-1 group">
              <div className="w-full bg-gradient-primary rounded-t-sm transition-all relative" style={{ height: `${(d.chats / max) * 100}%`, minHeight: d.chats ? 2 : 0 }}>
                <div className="absolute -top-6 left-1/2 -translate-x-1/2 text-[10px] opacity-0 group-hover:opacity-100 bg-popover px-1.5 py-0.5 rounded">{d.chats}</div>
              </div>
            </div>
          ))}
        </div>
        <div className="flex justify-between text-[10px] text-muted-foreground mt-2">
          <span>{daily[0]?.date}</span>
          <span>{daily[daily.length - 1]?.date}</span>
        </div>
      </div>
    </div>
  );
}

// ---------- History ----------
function HistoryTab({ botId }: { botId: string }) {
  const [convs, setConvs] = useState<any[]>([]);
  const [active, setActive] = useState<string | null>(null);
  const [msgs, setMsgs] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    listConversations({ data: { chatbotId: botId } })
      .then((r) => { const rows = r.conversations ?? []; setConvs(rows); if (rows[0]) setActive(rows[0].id); })
      .catch((e) => toast.error(e.message))
      .finally(() => setLoading(false));
  }, [botId]);

  useEffect(() => { if (active) getMessages({ data: { conversationId: active } }).then((r) => setMsgs(r.messages ?? [])); }, [active]);

  if (loading) return <Loader2 className="h-5 w-5 animate-spin" />;
  if (convs.length === 0) return <div className="rounded-xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">No conversations yet. Once visitors chat with your bot, they'll appear here.</div>;

  return (
    <div className="grid grid-cols-3 gap-4 h-[640px]">
      <div className="col-span-1 rounded-xl border border-border bg-gradient-card overflow-y-auto">
        {convs.map((c) => (
          <button key={c.id} onClick={() => setActive(c.id)} className={`w-full text-left px-4 py-3 border-b border-border hover:bg-accent/40 transition-colors ${active === c.id ? "bg-accent/60" : ""}`}>
            <div className="text-sm font-medium truncate">{c.visitor_email || c.visitor_id || "Anonymous"}</div>
            <div className="text-xs text-muted-foreground mt-0.5">{new Date(c.created_at).toLocaleString()}</div>
          </button>
        ))}
      </div>
      <div className="col-span-2 rounded-xl border border-border bg-gradient-card overflow-y-auto p-5 space-y-3">
        {msgs.map((m) => (
          <div key={m.id} className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap ${m.role === "user" ? "ml-auto rounded-tr-sm bg-gradient-primary text-primary-foreground" : "rounded-tl-sm bg-surface border border-border"}`}>
            {m.content}
          </div>
        ))}
      </div>
    </div>
  );
}

// ---------- Settings ----------
function SettingsTab({ bot, onChange }: { bot: any; onChange: () => void }) {
  const navigate = useNavigate();
  const [form, setForm] = useState({
    name: bot.name,
    welcome_message: bot.welcome_message,
    system_prompt: bot.system_prompt,
    primary_color: bot.primary_color,
    bubble_position: bot.bubble_position,
    tone: bot.tone,
    collect_email: bot.collect_email,
    allowed_domains: bot.allowed_domains ?? [],
  });
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    try { await updateBot({ data: { id: bot.id, ...form } }); toast.success("Saved"); onChange(); }
    catch (e: any) { toast.error(e.message); }
    finally { setSaving(false); }
  }

  async function remove() {
    if (!confirm(`Permanently delete "${bot.name}"? This cannot be undone.`)) return;
    try { await deleteBot({ data: { id: bot.id } }); toast.success("Bot deleted"); navigate({ to: "/dashboard" }); }
    catch (e: any) { toast.error(e.message); }
  }

  return (
    <div className="max-w-2xl space-y-6">
      <div className="rounded-xl border border-border bg-gradient-card p-6 space-y-4">
        <h3 className="font-semibold">General</h3>
        <div className="space-y-1.5"><Label>Bot name</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
        <div className="space-y-1.5"><Label>Welcome message</Label><Input value={form.welcome_message} onChange={(e) => setForm({ ...form, welcome_message: e.target.value })} /></div>
        <div className="space-y-1.5">
          <Label>System prompt</Label>
          <Textarea rows={4} value={form.system_prompt} onChange={(e) => setForm({ ...form, system_prompt: e.target.value })} />
          <p className="text-xs text-muted-foreground">Defines the bot's persona and behavior.</p>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-gradient-card p-6 space-y-4">
        <h3 className="font-semibold">Appearance</h3>
        <div className="grid sm:grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label>Primary color</Label>
            <div className="flex gap-2 items-center">
              <input type="color" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="h-10 w-14 rounded-md border border-border bg-transparent cursor-pointer" />
              <Input value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="font-mono" />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label>Bubble position</Label>
            <Select value={form.bubble_position} onValueChange={(v) => setForm({ ...form, bubble_position: v })}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="right">Bottom right</SelectItem>
                <SelectItem value="left">Bottom left</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label>Tone</Label>
          <Select value={form.tone} onValueChange={(v) => setForm({ ...form, tone: v })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="friendly">Friendly</SelectItem>
              <SelectItem value="formal">Formal</SelectItem>
              <SelectItem value="playful">Playful</SelectItem>
              <SelectItem value="concise">Concise</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="flex items-center justify-between rounded-lg border border-border p-3">
          <div>
            <div className="text-sm font-medium">Collect visitor email</div>
            <div className="text-xs text-muted-foreground">Show an optional email field in the widget for lead capture.</div>
          </div>
          <Switch checked={form.collect_email} onCheckedChange={(v) => setForm({ ...form, collect_email: v })} />
        </div>
      </div>

      <div className="flex items-center justify-between">
        <Button variant="outline" onClick={remove} className="text-destructive hover:bg-destructive/10 border-destructive/30">
          <Trash2 className="h-4 w-4 mr-2" /> Delete bot
        </Button>
        <Button onClick={save} disabled={saving} className="bg-gradient-primary text-primary-foreground shadow-glow">
          {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save changes"}
        </Button>
      </div>
    </div>
  );
}
