import { useEffect, useRef } from "react";
import helixLogo from "../../helix logo.png";
import { onScrollFrame } from "@/lib/scroll-driver";

const clamp = (v: number, min: number, max: number) => Math.min(max, Math.max(min, v));
const frac = (v: number) => v - Math.floor(v);
const TAU = Math.PI * 2;

/** Whole twists visible down the strand at any moment. */
const TURNS = 2;
/** Extra rotation of the ladder over one full page scroll. */
const TWIST = 1.2;
/** Spans the logo beads travel down the strand over one full page scroll. */
const FLOW = 1.6;
/** Vertical samples per strand. Only builds path geometry, so this stays cheap. */
const SAMPLES = 320;
/** Rungs (base pairs) spanning the two strands. */
const RUNGS = 10;
/** Logo marks riding the strands. */
const BEADS = 4;
/** Overall opacity multiplier. */
const BRIGHT = 1.4;
/**
 * Depth slices the strands are bucketed into. Every segment in a slice shares one
 * stroke call, which is what keeps this to ~12 draws a frame instead of ~640.
 */
const BANDS = 12;
/** Largest bead we ever draw; the logo is pre-scaled to this once. */
const BEAD_MAX = 36;

/** --primary and --primary-glow resolved to sRGB; canvas oklch support is uneven. */
const STRAND = "33, 182, 239";
const GLOW = "99, 209, 242";

/**
 * A DNA double helix rendered on a canvas behind the landing page.
 *
 * Two continuous strands wind around a shared vertical axis 180° apart, joined by
 * rungs that stretch to full width where the strands are furthest apart and pinch
 * to nothing where they cross. Segments are bucketed into depth slices and drawn
 * far-to-near, so the front strand genuinely occludes the back one at crossings.
 *
 * Scroll twists the ladder and flows the logo beads along it; scrolling back up
 * unwinds it exactly, since every value derives from scroll progress rather than
 * accumulated motion.
 */
export function HelixBackdrop() {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d", { alpha: true });
    if (!ctx) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    let w = 0;
    let h = 0;
    let dpr = 1;
    let spineGrad: CanvasGradient | null = null;
    let fadeGrad: CanvasGradient | null = null;

    // Strand geometry, reused every frame so the hot loop allocates nothing.
    const sinv = new Float32Array(SAMPLES + 1);
    const cosv = new Float32Array(SAMPLES + 1);
    const ys = new Float32Array(SAMPLES + 1);

    // The logo is a 1920px PNG; resampling it per bead per frame is pure waste,
    // so it gets pre-scaled once into a small offscreen canvas.
    const bead = document.createElement("canvas");
    const beadCtx = bead.getContext("2d");
    let beadReady = false;
    let beadRatio = 1;

    const logo = new Image();
    logo.onload = () => {
      if (!beadCtx) return;
      beadRatio = logo.naturalHeight / logo.naturalWidth || 1;
      bead.width = Math.ceil(BEAD_MAX * dpr);
      bead.height = Math.ceil(BEAD_MAX * beadRatio * dpr);
      beadCtx.clearRect(0, 0, bead.width, bead.height);
      beadCtx.drawImage(logo, 0, 0, bead.width, bead.height);
      beadReady = true;
      kick();
    };
    logo.src = helixLogo;

    const resize = () => {
      // 1.5 is the sweet spot here: the strands are soft gradients, so the extra
      // fill cost of a full 2x buffer buys almost nothing visually.
      dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      w = window.innerWidth;
      h = window.innerHeight;
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
      canvas.style.width = `${w}px`;
      canvas.style.height = `${h}px`;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      for (let i = 0; i <= SAMPLES; i++) ys[i] = (i / SAMPLES) * h;

      spineGrad = ctx.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, Math.max(w, h) * 0.42);
      spineGrad.addColorStop(0, `rgba(${STRAND}, 1)`);
      spineGrad.addColorStop(1, "rgba(0, 0, 0, 0)");

      // The vertical taper used to be folded into every segment's alpha, which
      // forced a unique style per segment. Applied once as a mask instead.
      fadeGrad = ctx.createLinearGradient(0, 0, 0, h);
      for (let i = 0; i <= 12; i++) {
        const u = i / 12;
        fadeGrad.addColorStop(u, `rgba(0, 0, 0, ${(1 - Math.sin(u * Math.PI)).toFixed(4)})`);
      }

      if (logo.complete && logo.naturalWidth && beadCtx) {
        bead.width = Math.ceil(BEAD_MAX * dpr);
        bead.height = Math.ceil(BEAD_MAX * beadRatio * dpr);
        beadCtx.clearRect(0, 0, bead.width, bead.height);
        beadCtx.drawImage(logo, 0, 0, bead.width, bead.height);
        beadReady = true;
      }
    };

    const draw = (progress: number, velocity: number, easedPointer: number) => {
      const radius = Math.min(w * 0.17, 200);
      const shift = progress * TWIST * TAU + easedPointer * 0.35;
      const heat = clamp(Math.abs(velocity) * 0.02, 0, 1);
      const cx = w / 2;

      ctx.clearRect(0, 0, w, h);

      if (spineGrad) {
        ctx.globalAlpha = 0.05 + heat * 0.03;
        ctx.fillStyle = spineGrad;
        ctx.fillRect(0, 0, w, h);
        ctx.globalAlpha = 1;
      }

      // One trig pass for both strands: strand B is the exact mirror of strand A.
      for (let i = 0; i <= SAMPLES; i++) {
        const phase = (i / SAMPLES) * TURNS * TAU + shift;
        sinv[i] = Math.sin(phase);
        cosv[i] = Math.cos(phase);
      }

      // Rungs sit between the strands, so they go down first and dim.
      ctx.lineWidth = 1.4;
      for (let i = 0; i < RUNGS; i++) {
        const u = (i + 0.5) / RUNGS;
        const phase = u * TURNS * TAU + shift;
        const dx = Math.sin(phase) * radius;
        const y = u * h;
        const spread = Math.abs(dx) / radius;
        const alpha = spread * 0.16 * BRIGHT;
        if (alpha < 0.004) continue;
        const grad = ctx.createLinearGradient(cx + dx, y, cx - dx, y);
        grad.addColorStop(0, `rgba(${STRAND}, 0)`);
        grad.addColorStop(0.25, `rgba(${STRAND}, ${alpha.toFixed(3)})`);
        grad.addColorStop(0.75, `rgba(${GLOW}, ${alpha.toFixed(3)})`);
        grad.addColorStop(1, `rgba(${GLOW}, 0)`);
        ctx.strokeStyle = grad;
        ctx.beginPath();
        ctx.moveTo(cx + dx, y);
        ctx.lineTo(cx - dx, y);
        ctx.stroke();
      }

      // Bucket every segment of both strands by depth, then stroke each bucket once.
      const paths: Path2D[] = new Array(BANDS);
      for (let b = 0; b < BANDS; b++) paths[b] = new Path2D();

      for (let i = 1; i <= SAMPLES; i++) {
        const xPrevA = cx + sinv[i - 1] * radius;
        const xCurA = cx + sinv[i] * radius;
        const depthA = (cosv[i - 1] + cosv[i]) * 0.5;

        let band = ((depthA + 1) * 0.5 * BANDS) | 0;
        if (band > BANDS - 1) band = BANDS - 1;
        else if (band < 0) band = 0;
        const pa = paths[band];
        pa.moveTo(xPrevA, ys[i - 1]);
        pa.lineTo(xCurA, ys[i]);

        // Strand B mirrors A across the axis, in both x and depth.
        let bandB = ((1 - depthA) * 0.5 * BANDS) | 0;
        if (bandB > BANDS - 1) bandB = BANDS - 1;
        else if (bandB < 0) bandB = 0;
        const pb = paths[bandB];
        pb.moveTo(cx - (xPrevA - cx), ys[i - 1]);
        pb.lineTo(cx - (xCurA - cx), ys[i]);
      }

      ctx.lineCap = "round";
      for (let b = 0; b < BANDS; b++) {
        const near = (b + 0.5) / BANDS; // 0 = furthest, 1 = nearest
        const alpha = (0.1 + near * 0.3 + heat * 0.1) * BRIGHT;
        ctx.strokeStyle = `rgba(${near > 0.5 ? GLOW : STRAND}, ${alpha.toFixed(3)})`;
        ctx.lineWidth = 1 + near * 2.4;
        ctx.stroke(paths[b]);
      }

      // Logo beads riding the strands, drawn far-to-near.
      if (beadReady) {
        const order: Array<{ x: number; y: number; near: number }> = [];
        for (let s = 0; s < 2; s++) {
          for (let j = 0; j < BEADS; j++) {
            const u = frac((j + s * 0.5) / BEADS + progress * FLOW);
            const phase = u * TURNS * TAU + shift + s * Math.PI;
            order.push({
              x: cx + Math.sin(phase) * radius,
              y: u * h,
              near: (Math.cos(phase) + 1) / 2,
            });
          }
        }
        order.sort((p, q) => p.near - q.near);
        for (const o of order) {
          const alpha = (0.2 + o.near * 0.45) * BRIGHT;
          const size = 20 + o.near * 16;
          ctx.globalAlpha = Math.min(alpha, 1);
          ctx.drawImage(bead, o.x - size / 2, o.y - (size * beadRatio) / 2, size, size * beadRatio);
        }
        ctx.globalAlpha = 1;
      }

      // Taper the top and bottom in one pass instead of per segment.
      if (fadeGrad) {
        ctx.globalCompositeOperation = "destination-out";
        ctx.fillStyle = fadeGrad;
        ctx.fillRect(0, 0, w, h);
        ctx.globalCompositeOperation = "source-over";
      }
    };

    let progress = 0;
    let velocity = 0;
    let pointer = 0;
    let easedPointer = 0;
    let lastScroll = window.scrollY;

    const unsubscribe = onScrollFrame(({ scroll, range }) => {
      const target = clamp(scroll / range, 0, 1);
      velocity += (scroll - lastScroll - velocity) * 0.16;
      lastScroll = scroll;
      progress += (target - progress) * 0.08;
      easedPointer += (pointer - easedPointer) * 0.05;

      draw(progress, velocity, easedPointer);

      return (
        Math.abs(velocity) >= 0.05 ||
        Math.abs(target - progress) >= 0.0002 ||
        Math.abs(pointer - easedPointer) >= 0.002
      );
    });

    const kick = () => unsubscribe.wake();

    const onResize = () => {
      resize();
      kick();
    };

    const onPointerMove = (e: PointerEvent) => {
      pointer = (e.clientX / window.innerWidth) * 2 - 1;
      kick();
    };

    resize();
    kick();

    window.addEventListener("resize", onResize);
    window.addEventListener("pointermove", onPointerMove, { passive: true });

    return () => {
      window.removeEventListener("resize", onResize);
      window.removeEventListener("pointermove", onPointerMove);
      logo.onload = null;
      unsubscribe();
    };
  }, []);

  return <canvas ref={canvasRef} className="helix-backdrop" aria-hidden="true" />;
}
