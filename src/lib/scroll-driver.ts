export type ScrollFrame = {
  /** Current window.scrollY. */
  scroll: number;
  /** Scrollable distance, floored at 1 so callers can divide safely. */
  range: number;
  /** Viewport height. */
  height: number;
};

/**
 * Returning `true` asks for another frame; `false` lets the loop idle until the
 * next scroll, resize, or explicit wake.
 */
type Subscriber = (frame: ScrollFrame) => boolean;

type Handle = {
  (): void;
  /** Restart the loop after it has idled (pointer input, images loading, …). */
  wake: () => void;
};

const subscribers = new Set<Subscriber>();
let raf = 0;
let listening = false;

/**
 * One rAF loop and one scroll listener shared by every scroll-driven effect.
 *
 * Each effect used to run its own loop, which meant several callbacks per frame
 * interleaving reads and writes — a reliable way to force extra style recalcs.
 * Here the scroll position is read exactly once and handed to every subscriber,
 * so all reads happen before any of the writes.
 */
export function onScrollFrame(fn: Subscriber): Handle {
  subscribers.add(fn);
  ensureListening();
  wake();

  const handle = (() => {
    subscribers.delete(fn);
    if (subscribers.size === 0) teardown();
  }) as Handle;
  handle.wake = wake;
  return handle;
}

function tick() {
  raf = 0;
  const frame: ScrollFrame = {
    scroll: window.scrollY,
    range: Math.max(1, document.documentElement.scrollHeight - window.innerHeight),
    height: window.innerHeight,
  };

  let again = false;
  for (const fn of subscribers) {
    // One misbehaving effect must not take the whole page's scrolling with it.
    try {
      if (fn(frame)) again = true;
    } catch {
      again = false;
    }
  }

  if (again) raf = requestAnimationFrame(tick);
}

function wake() {
  if (!raf) raf = requestAnimationFrame(tick);
}

function ensureListening() {
  if (listening) return;
  listening = true;
  window.addEventListener("scroll", wake, { passive: true });
  window.addEventListener("resize", wake);
}

function teardown() {
  if (!listening) return;
  listening = false;
  window.removeEventListener("scroll", wake);
  window.removeEventListener("resize", wake);
  if (raf) cancelAnimationFrame(raf);
  raf = 0;
}
