import { useEffect, useState } from "react";
import { getLlmRouting, updateLlmRouting, type LlmRouting } from "@/lib/bots-api";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { AlertTriangle, Check, Cpu, Loader2, Zap } from "lucide-react";
import { toast } from "sonner";

const NONE = "";

/**
 * Chooses which model answers first and which one covers for it.
 *
 * The backup is only used when the main provider fails in a way worth retrying
 * — a network fault, a timeout, a rate limit or a 5xx. A rejected API key is a
 * configuration problem and surfaces instead of being silently papered over.
 */
export function AdminModelRouting() {
  const [routing, setRouting] = useState<LlmRouting | null>(null);
  const [primary, setPrimary] = useState<string>("");
  const [secondary, setSecondary] = useState<string>(NONE);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getLlmRouting()
      .then((data) => {
        setRouting(data);
        setPrimary(data.primary);
        setSecondary(data.secondary);
        setError(null);
      })
      .catch((err) => setError(err.message ?? "Failed to load model routing"))
      .finally(() => setLoading(false));
  }, []);

  const dirty =
    routing !== null && (primary !== routing.primary || secondary !== routing.secondary);

  const save = async () => {
    setSaving(true);
    try {
      const saved = await updateLlmRouting(primary, secondary);
      setRouting((prev) => (prev ? { ...prev, ...saved } : prev));
      setPrimary(saved.primary);
      setSecondary(saved.secondary);
      toast.success("Model routing updated", {
        description: "New messages use this immediately — no restart needed.",
      });
    } catch (err: any) {
      toast.error("Could not save", { description: err?.message ?? "Please try again." });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <section className="rounded-xl border border-border bg-gradient-card p-6">
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" /> Loading model routing…
        </div>
      </section>
    );
  }

  if (error || !routing) {
    return (
      <section className="rounded-xl border border-border bg-gradient-card p-6">
        <p className="text-sm text-destructive">{error ?? "Model routing unavailable."}</p>
      </section>
    );
  }

  const pick = (value: string) => routing.options.find((o) => o.value === value);

  return (
    <section className="rounded-xl border border-border bg-gradient-card p-6 space-y-6">
      <header className="space-y-1">
        <h2 className="font-display text-xl font-bold">Model routing</h2>
        <p className="text-sm text-muted-foreground">
          Pick which model answers first, and which one takes over if it fails.
        </p>
      </header>

      <div className="grid gap-5 md:grid-cols-2">
        <Choice
          title="Main"
          icon={<Zap className="h-4 w-4 text-primary" />}
          hint="Answers every message."
          options={routing.options}
          value={primary}
          onChange={(next) => {
            setPrimary(next);
            // Main and backup cannot be the same provider.
            if (next === secondary) setSecondary(NONE);
          }}
        />

        <Choice
          title="Backup"
          icon={<Cpu className="h-4 w-4 text-primary" />}
          hint="Used only if the main one fails."
          options={routing.options.filter((o) => o.value !== primary)}
          value={secondary}
          onChange={setSecondary}
          allowNone
        />
      </div>

      {pick(primary) && !pick(primary)!.ready && (
        <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          {pick(primary)!.note}. Messages will fall through to the backup until this is set.
        </p>
      )}

      {secondary === NONE && (
        <p className="text-sm text-muted-foreground">
          No backup selected — if the main model fails, the visitor sees an error.
        </p>
      )}

      <div className="flex items-center gap-3">
        <Button onClick={save} disabled={!dirty || saving} className="min-w-32">
          {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save routing"}
        </Button>
        {!dirty && !saving && (
          <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
            <Check className="h-3.5 w-3.5 text-success" /> Saved
          </span>
        )}
      </div>

      <p className="border-t border-border pt-4 text-xs text-muted-foreground">
        Bots that were given their own model on their settings page keep using it and ignore
        this. Everything else follows what you choose here.
      </p>
    </section>
  );
}

type ChoiceProps = {
  title: string;
  icon: React.ReactNode;
  hint: string;
  options: LlmRouting["options"];
  value: string;
  onChange: (value: string) => void;
  allowNone?: boolean;
};

function Choice({ title, icon, hint, options, value, onChange, allowNone }: ChoiceProps) {
  return (
    <div className="space-y-3">
      <div className="space-y-0.5">
        <div className="flex items-center gap-2 font-semibold">
          {icon}
          {title}
        </div>
        <p className="text-xs text-muted-foreground">{hint}</p>
      </div>

      <div className="space-y-2">
        {options.map((option) => (
          <button
            key={option.value}
            type="button"
            onClick={() => onChange(option.value)}
            aria-pressed={value === option.value}
            className={`w-full rounded-lg border p-3 text-left transition-colors ${
              value === option.value
                ? "border-primary bg-primary/10"
                : "border-border hover:border-border-strong"
            }`}
          >
            <div className="flex items-center justify-between gap-2">
              <span className="font-medium">{option.label}</span>
              {!option.ready && (
                <Badge variant="outline" className="text-warning border-warning/40">
                  Not ready
                </Badge>
              )}
            </div>
            <div className="mt-0.5 font-mono text-xs text-muted-foreground">{option.model}</div>
            <div className="text-xs text-muted-foreground">{option.note}</div>
          </button>
        ))}

        {allowNone && (
          <button
            type="button"
            onClick={() => onChange(NONE)}
            aria-pressed={value === NONE}
            className={`w-full rounded-lg border p-3 text-left transition-colors ${
              value === NONE
                ? "border-primary bg-primary/10"
                : "border-border hover:border-border-strong"
            }`}
          >
            <span className="font-medium">No backup</span>
            <div className="text-xs text-muted-foreground">Fail instead of retrying elsewhere</div>
          </button>
        )}
      </div>
    </div>
  );
}
