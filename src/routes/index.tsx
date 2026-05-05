import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { ArrowRight, Bot, Code2, Database, MessageSquare, Shield, Sparkles, Upload, Zap } from "lucide-react";

export const Route = createFileRoute("/")({
  component: Landing,
  head: () => ({
    meta: [
      { title: "Helix — Deploy AI chatbots trained on your content" },
      {
        name: "description",
        content:
          "Upload PDFs, docs and URLs. Get a production-ready AI chatbot widget you can embed anywhere with one snippet.",
      },
    ],
  }),
});

function Landing() {
  useEffect(() => {
    const els = document.querySelectorAll<HTMLElement>(".reveal");
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) {
            e.target.classList.add("is-visible");
            io.unobserve(e.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, []);

  return (
    <div className="min-h-screen bg-background bg-gradient-hero">
      {/* Nav */}
      <header className="sticky top-0 z-40 border-b border-border/50 backdrop-blur-xl bg-background/60">
        <div className="mx-auto max-w-7xl px-6 h-16 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 font-display font-bold text-lg">
            <span className="relative inline-flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-primary shadow-glow">
              <Bot className="h-5 w-5 text-primary-foreground" />
            </span>
            <span>Helix</span>
          </Link>
          <nav className="hidden md:flex items-center gap-8 text-sm text-muted-foreground">
            <a href="#features" className="hover:text-foreground transition-colors">Features</a>
            <a href="#how" className="hover:text-foreground transition-colors">How it works</a>
            <a href="#stack" className="hover:text-foreground transition-colors">Stack</a>
          </nav>
          <div className="flex items-center gap-2">
            <Link to="/auth">
              <Button variant="ghost" size="sm">Sign in</Button>
            </Link>
            <Link to="/auth" search={{ mode: "signup" }}>
              <Button size="sm" className="bg-gradient-primary text-primary-foreground hover:opacity-90 shadow-glow">
                Get started
              </Button>
            </Link>
          </div>
        </div>
      </header>

      {/* Hero */}
      <section className="relative overflow-hidden">
        <div className="absolute inset-0 grid-bg opacity-40 [mask-image:radial-gradient(ellipse_at_center,black,transparent_70%)]" />
        <div className="relative mx-auto max-w-7xl px-6 pt-24 pb-32 text-center">
          <div className="inline-flex items-center gap-2 rounded-full border border-border-strong bg-surface/60 px-3 py-1 text-xs text-muted-foreground backdrop-blur">
            <Sparkles className="h-3.5 w-3.5 text-primary" />
            Powered by retrieval-augmented generation
          </div>
          <h1 className="mt-8 font-display text-5xl md:text-7xl font-bold tracking-tight">
            Ship AI chatbots that
            <br />
            <span className="text-gradient">actually know your stuff.</span>
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
            Upload your docs, paste a URL, and embed a smart chatbot on your site in minutes.
            No prompt engineering. No hallucinations. Just answers from your content.
          </p>
          <div className="mt-10 flex items-center justify-center gap-3">
            <Link to="/auth" search={{ mode: "signup" }}>
              <Button size="lg" className="bg-gradient-primary text-primary-foreground hover:opacity-90 shadow-glow h-12 px-6">
                Build your first bot <ArrowRight className="ml-2 h-4 w-4" />
              </Button>
            </Link>
            <a href="#how">
              <Button size="lg" variant="outline" className="h-12 px-6 bg-surface/50">
                See how it works
              </Button>
            </a>
          </div>

          {/* Hero card preview */}
          <div className="mt-20 relative mx-auto max-w-4xl">
            <div className="absolute -inset-4 bg-gradient-primary opacity-20 blur-3xl rounded-3xl" />
            <div className="relative rounded-2xl border border-border-strong bg-gradient-card shadow-elegant overflow-hidden">
              <div className="flex items-center gap-1.5 px-4 py-3 border-b border-border">
                <span className="h-2.5 w-2.5 rounded-full bg-destructive/60" />
                <span className="h-2.5 w-2.5 rounded-full bg-warning/60" />
                <span className="h-2.5 w-2.5 rounded-full bg-success/60" />
                <span className="ml-3 text-xs text-muted-foreground font-mono">yourcompany.com</span>
              </div>
              <div className="grid md:grid-cols-2 gap-0">
                <div className="p-8 text-left space-y-4 border-r border-border">
                  <div className="text-xs uppercase tracking-wider text-muted-foreground">Knowledge base</div>
                  {["product-docs.pdf", "pricing-faq.docx", "https://blog.acme.com"].map((s) => (
                    <div key={s} className="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2.5">
                      <div className="h-8 w-8 rounded-md bg-accent flex items-center justify-center">
                        <Upload className="h-4 w-4 text-primary" />
                      </div>
                      <div className="flex-1 text-sm font-mono">{s}</div>
                      <span className="text-xs text-success">Ready</span>
                    </div>
                  ))}
                </div>
                <div className="p-8 space-y-3">
                  <div className="text-xs uppercase tracking-wider text-muted-foreground text-left">Live chat</div>
                  <div className="rounded-2xl rounded-tl-sm bg-surface px-4 py-3 text-sm text-left max-w-[85%]">
                    Hi! How can I help you today?
                  </div>
                  <div className="rounded-2xl rounded-tr-sm bg-gradient-primary text-primary-foreground px-4 py-3 text-sm text-left max-w-[85%] ml-auto">
                    What's your refund policy?
                  </div>
                  <div className="rounded-2xl rounded-tl-sm bg-surface px-4 py-3 text-sm text-left max-w-[90%]">
                    We offer a 30-day money-back guarantee on all paid plans, no questions asked.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Features */}
      <section id="features" className="scroll-mt-24 mx-auto max-w-7xl px-6 py-24">
        <div className="text-center max-w-2xl mx-auto">
          <h2 className="font-display text-4xl font-bold">Everything you need to launch.</h2>
          <p className="mt-4 text-muted-foreground">
            From ingestion to embedding to analytics — Helix handles the entire RAG pipeline.
          </p>
        </div>

        <div className="mt-16 grid md:grid-cols-3 gap-6">
          {[
            { icon: Upload, title: "Multi-source ingestion", desc: "PDF, DOCX, TXT files, raw text, or any public URL. We chunk and embed automatically." },
            { icon: Database, title: "Vector search", desc: "Semantic search via Postgres + pgvector. Fast, accurate, and yours to own." },
            { icon: MessageSquare, title: "Embeddable widget", desc: "One <script> tag. Lives on your site, talks to your backend, looks like your brand." },
            { icon: Code2, title: "API-first", desc: "Every bot gets a unique API key. Build custom integrations beyond the widget." },
            { icon: Shield, title: "Per-tenant isolation", desc: "Row-level security on every table. Your knowledge never bleeds into another bot." },
            { icon: Zap, title: "Usage analytics", desc: "Track chats, top queries, and conversation history from a single dashboard." },
          ].map((f, i) => (
            <div
              key={f.title}
              className="reveal rounded-xl border border-border bg-gradient-card p-6 hover:border-border-strong hover:-translate-y-1 transition-all duration-300"
              style={{ transitionDelay: `${i * 60}ms` }}
            >
              <div className="h-10 w-10 rounded-lg bg-accent flex items-center justify-center mb-4">
                <f.icon className="h-5 w-5 text-primary" />
              </div>
              <h3 className="font-semibold mb-2">{f.title}</h3>
              <p className="text-sm text-muted-foreground leading-relaxed">{f.desc}</p>
            </div>
          ))}
        </div>
      </section>

      {/* How it works */}
      <section id="how" className="scroll-mt-24 mx-auto max-w-7xl px-6 py-24 border-t border-border">
        <div className="text-center max-w-2xl mx-auto">
          <h2 className="font-display text-4xl font-bold">From upload to embed in 4 steps.</h2>
        </div>
        <div className="mt-16 grid md:grid-cols-4 gap-6">
          {[
            { n: "01", t: "Create a bot", d: "Name it, pick a tone, choose a color. Done." },
            { n: "02", t: "Add knowledge", d: "Drag in files or paste URLs. We process everything in the background." },
            { n: "03", t: "Test & tune", d: "Chat with your bot in the playground. Tweak the prompt and welcome message." },
            { n: "04", t: "Embed", d: "Copy one snippet into your site. The widget appears instantly." },
          ].map((s, i) => (
            <div
              key={s.n}
              className="reveal relative rounded-xl border border-border bg-gradient-card p-6 hover:-translate-y-1 transition-transform duration-300"
              style={{ transitionDelay: `${i * 80}ms` }}
            >
              <div className="font-mono text-xs text-primary mb-3">{s.n}</div>
              <h3 className="font-semibold mb-2">{s.t}</h3>
              <p className="text-sm text-muted-foreground">{s.d}</p>
            </div>
          ))}
        </div>
      </section>

      {/* CTA */}
      <span id="stack" className="block scroll-mt-24" />
      <section className="mx-auto max-w-7xl px-6 py-24">
        <div className="rounded-3xl border border-border-strong bg-gradient-card p-12 md:p-16 text-center relative overflow-hidden">
          <div className="absolute inset-0 bg-gradient-hero opacity-60" />
          <div className="relative">
            <h2 className="font-display text-4xl md:text-5xl font-bold">Ready to ship a smarter bot?</h2>
            <p className="mt-4 text-lg text-muted-foreground">It takes less time than reading this page.</p>
            <Link to="/auth" search={{ mode: "signup" }} className="inline-block mt-8">
              <Button size="lg" className="bg-gradient-primary text-primary-foreground hover:opacity-90 shadow-glow h-12 px-8">
                Start free <ArrowRight className="ml-2 h-4 w-4" />
              </Button>
            </Link>
          </div>
        </div>
      </section>

      <footer className="border-t border-border">
        <div className="mx-auto max-w-7xl px-6 py-8 flex flex-col md:flex-row items-center justify-between gap-4 text-sm text-muted-foreground">
          <div className="flex items-center gap-2">
            <span className="h-6 w-6 rounded-md bg-gradient-primary flex items-center justify-center">
              <Bot className="h-3.5 w-3.5 text-primary-foreground" />
            </span>
            Helix
          </div>
          <div>© {new Date().getFullYear()} Helix. All rights reserved.</div>
        </div>
      </footer>
    </div>
  );
}
