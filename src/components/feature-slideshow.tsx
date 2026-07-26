import { useCallback, useEffect, useRef, useState } from "react";
import { Code2, Database, MessageSquare, Shield, Upload, Zap } from "lucide-react";
import { onScrollFrame, type ScrollFrame } from "@/lib/scroll-driver";

const FEATURES = [
  { icon: Upload, title: "Multi-source ingestion", desc: "PDF, DOCX, TXT files, raw text, or any public URL. We chunk and embed automatically." },
  { icon: Database, title: "Vector search", desc: "Semantic search via Postgres + pgvector. Fast, accurate, and yours to own." },
  { icon: MessageSquare, title: "Embeddable widget", desc: "One <script> tag. Lives on your site, talks to your backend, looks like your brand." },
  { icon: Code2, title: "API-first", desc: "Every bot gets a unique API key. Build custom integrations beyond the widget." },
  { icon: Shield, title: "Per-tenant isolation", desc: "Row-level security on every table. Your knowledge never bleeds into another bot." },
  { icon: Zap, title: "Usage analytics", desc: "Track chats, top queries, and conversation history from a single dashboard." },
];

/** How much vertical scroll to spend per pixel of horizontal card travel. */
const PIN_PACE = 1.2;

const clamp = (v: number, min: number, max: number) => Math.min(max, Math.max(min, v));

/**
 * Features section that pins to the viewport while the cards slide across
 * horizontally, driven entirely by scroll position. Falls back to a swipeable
 * snap rail on small screens and to a plain grid under reduced motion.
 */
export function FeatureSlideshow() {
  const scrollRef = useRef<HTMLDivElement>(null);
  const viewportRef = useRef<HTMLDivElement>(null);
  const trackRef = useRef<HTMLDivElement>(null);
  const metricsRef = useRef({ distance: 0, cardWidth: 0, gap: 0, top: 0, travel: 0 });
  const scheduleRef = useRef<(() => void) | null>(null);
  const [pinned, setPinned] = useState(false);
  const [pinHeight, setPinHeight] = useState(0);
  const [active, setActive] = useState(0);

  useEffect(() => {
    const scroller = scrollRef.current;
    const viewport = viewportRef.current;
    const track = trackRef.current;
    if (!scroller || !viewport || !track) return;

    const wide = window.matchMedia("(min-width: 768px)");
    const still = window.matchMedia("(prefers-reduced-motion: reduce)");
    let enabled = false;
    let lastActive = -1;

    const clearCardStyles = () => {
      for (const card of Array.from(track.children) as HTMLElement[]) {
        card.style.removeProperty("--card-focus");
      }
    };

    const cards = Array.from(track.children) as HTMLElement[];
    const shownFocus = new Float32Array(cards.length).fill(-1);

    const measure = () => {
      enabled = wide.matches && !still.matches;
      setPinned(enabled);

      if (!enabled) {
        metricsRef.current = { distance: 0, cardWidth: 0, gap: 0, top: 0, travel: 0 };
        setPinHeight(0);
        track.style.removeProperty("transform");
        clearCardStyles();
        return;
      }

      const first = track.firstElementChild as HTMLElement | null;
      const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
      const distance = Math.max(0, track.scrollWidth - viewport.clientWidth);
      const height = Math.round(window.innerHeight + distance * PIN_PACE);

      metricsRef.current = {
        distance,
        cardWidth: first?.offsetWidth ?? 0,
        gap,
        // Cached so the per-frame path never reads layout back.
        top: scroller.getBoundingClientRect().top + window.scrollY,
        travel: height - window.innerHeight,
      };
      setPinHeight(height);
    };

    const render = ({ scroll }: ScrollFrame) => {
      if (!enabled) return false;

      const { distance, cardWidth, gap, top, travel } = metricsRef.current;
      const progress = travel > 0 ? clamp((scroll - top) / travel, 0, 1) : 0;
      const offset = -progress * distance;

      track.style.transform = `translate3d(${offset.toFixed(2)}px, 0, 0)`;

      const width = viewport.clientWidth;
      const center = width / 2;
      let best = 0;
      let bestFocus = -1;

      for (let i = 0; i < cards.length; i++) {
        const cardCenter = i * (cardWidth + gap) + cardWidth / 2 + offset;
        const focus = 1 - clamp(Math.abs(cardCenter - center) / (width * 0.6), 0, 1);
        // Skip sub-perceptual updates; each one would dirty style for that card.
        const rounded = Math.round(focus * 200) / 200;
        if (shownFocus[i] !== rounded) {
          shownFocus[i] = rounded;
          cards[i].style.setProperty("--card-focus", rounded.toFixed(3));
        }
        if (focus > bestFocus) {
          bestFocus = focus;
          best = i;
        }
      }

      if (best !== lastActive) {
        lastActive = best;
        setActive(best);
      }
      return false;
    };

    const unsubscribe = onScrollFrame(render);
    const schedule = () => unsubscribe.wake();

    const onResize = () => {
      measure();
      schedule();
    };

    scheduleRef.current = schedule;
    measure();
    schedule();

    window.addEventListener("resize", onResize);
    wide.addEventListener("change", onResize);
    still.addEventListener("change", onResize);

    const ro = new ResizeObserver(onResize);
    ro.observe(track);

    return () => {
      window.removeEventListener("resize", onResize);
      wide.removeEventListener("change", onResize);
      still.removeEventListener("change", onResize);
      ro.disconnect();
      scheduleRef.current = null;
      unsubscribe();
    };
  }, []);

  // The pin height lands a render after measuring, so recompute once it's applied.
  useEffect(() => {
    scheduleRef.current?.();
  }, [pinHeight, pinned]);

  const goTo = useCallback(
    (index: number) => {
      const scroller = scrollRef.current;
      const viewport = viewportRef.current;
      const track = trackRef.current;
      if (!scroller || !viewport || !track) return;

      if (!pinned) {
        (track.children[index] as HTMLElement | undefined)?.scrollIntoView({
          behavior: "smooth",
          block: "nearest",
          inline: "center",
        });
        return;
      }

      const { distance, cardWidth, gap } = metricsRef.current;
      if (!distance) return;

      const wanted = viewport.clientWidth / 2 - (index * (cardWidth + gap) + cardWidth / 2);
      const progress = clamp(-wanted / distance, 0, 1);
      const rect = scroller.getBoundingClientRect();
      const travel = rect.height - window.innerHeight;
      window.scrollTo({ top: rect.top + window.scrollY + progress * travel, behavior: "smooth" });
    },
    [pinned]
  );

  return (
    <section id="features" className="feature-section scroll-mt-24 border-t border-border">
      <div
        ref={scrollRef}
        className="feature-scroll"
        style={pinned && pinHeight ? { height: `${pinHeight}px` } : undefined}
      >
        <div className="feature-stage">
          <div className="mx-auto w-full max-w-7xl px-6">
            <div className="reveal text-center max-w-2xl mx-auto">
              <h2 className="font-display text-4xl font-bold">Everything you need to launch.</h2>
              <p className="mt-4 text-muted-foreground">
                From ingestion to embedding to analytics — Helix handles the entire RAG pipeline.
              </p>
            </div>

            <div ref={viewportRef} className="feature-viewport mt-14">
              <div ref={trackRef} className="feature-track">
                {FEATURES.map((f, i) => (
                  <article key={f.title} className="feature-card" aria-label={f.title}>
                    <div className="feature-card-index">{String(i + 1).padStart(2, "0")}</div>
                    <div className="h-11 w-11 rounded-xl bg-accent flex items-center justify-center mb-5">
                      <f.icon className="h-5 w-5 text-primary" />
                    </div>
                    <h3 className="font-semibold text-lg mb-2">{f.title}</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">{f.desc}</p>
                  </article>
                ))}
              </div>
            </div>

            <div className="feature-dots" role="tablist" aria-label="Feature slides">
              {FEATURES.map((f, i) => (
                <button
                  key={f.title}
                  type="button"
                  role="tab"
                  aria-selected={i === active}
                  aria-label={f.title}
                  className="feature-dot"
                  data-active={i === active || undefined}
                  onClick={() => goTo(i)}
                />
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
