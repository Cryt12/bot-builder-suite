// Text chunking + URL fetching + file parsing helpers (server-only)
import * as cheerio from "cheerio";

function normalizeSpace(text: string): string {
  return text.replace(/\s+/g, " ").trim();
}

export function chunkText(text: string, opts: { size?: number; overlap?: number } = {}): string[] {
  const size = opts.size ?? 1000;
  const overlap = opts.overlap ?? 150;
  const clean = text
    .replace(/\r\n/g, "\n")
    .replace(/[ \t\f\v]+/g, " ")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
  if (!clean) return [];
  const chunks: string[] = [];
  let i = 0;
  while (i < clean.length) {
    const end = Math.min(i + size, clean.length);
    // try to end at sentence boundary
    let cut = end;
    if (end < clean.length) {
      const tail = clean.slice(i, end);
      const lastDot = Math.max(tail.lastIndexOf(". "), tail.lastIndexOf("! "), tail.lastIndexOf("? "));
      if (lastDot > size * 0.5) cut = i + lastDot + 1;
    }
    chunks.push(clean.slice(i, cut).trim());
    if (cut >= clean.length) break;
    i = Math.max(cut - overlap, i + 1);
  }
  return chunks.filter((c) => c.length > 20);
}

function extractReadableBodyText($: cheerio.CheerioAPI): string {
  const blocks: string[] = [];

  $("body")
    .find("h1,h2,h3,h4,h5,h6,p,li,blockquote,tr")
    .each((_, el) => {
      const tag = (el.tagName || "").toLowerCase();
      let text = "";

      if (tag === "tr") {
        text = $(el)
          .find("th,td")
          .map((__, cell) => normalizeSpace($(cell).text()))
          .get()
          .filter(Boolean)
          .join(" | ");
      } else {
        text = normalizeSpace($(el).text());
      }

      if (text) blocks.push(text);
    });

  if (blocks.length > 0) {
    return blocks.join("\n");
  }

  return normalizeSpace($("body").text());
}

export async function fetchUrlText(url: string): Promise<{ title: string; text: string }> {
  const res = await fetch(url, {
    headers: {
      "User-Agent": "Mozilla/5.0 (compatible; HelixBot/1.0; +https://helix.ai)",
      Accept: "text/html,application/xhtml+xml",
    },
    redirect: "follow",
  });
  if (!res.ok) throw new Error(`Fetch failed: ${res.status}`);
  const html = await res.text();
  const $ = cheerio.load(html);
  $("script, style, noscript, iframe, nav, footer, header").remove();
  const title = ($("title").first().text() || url).trim();
  const text = extractReadableBodyText($);
  return { title, text };
}

export async function parsePdf(buffer: ArrayBuffer): Promise<string> {
  // Use legacy build for Node/Worker compatibility
  const pdfjs = await import("pdfjs-dist/legacy/build/pdf.mjs");
  // @ts-ignore
  pdfjs.GlobalWorkerOptions.workerSrc = "";
  const loadingTask = (pdfjs as any).getDocument({ data: new Uint8Array(buffer), useSystemFonts: true, disableFontFace: true });
  const doc = await loadingTask.promise;
  let out = "";
  for (let p = 1; p <= doc.numPages; p++) {
    const page = await doc.getPage(p);
    const content = await page.getTextContent();
    const pageText = content.items.map((it: any) => ("str" in it ? it.str : "")).join(" ");
    out += pageText + "\n\n";
  }
  return out;
}

export async function parseDocx(buffer: ArrayBuffer): Promise<string> {
  const mammoth = await import("mammoth");
  const result = await mammoth.extractRawText({ buffer: Buffer.from(buffer) });
  return result.value;
}
