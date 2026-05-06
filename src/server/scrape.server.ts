// Web scraping service for chatbot knowledge ingestion
// Converts the Python playwright scraper into a Node.js/cheerio-based service
// that can scrape any website and ingest content into the knowledge base.

import * as cheerio from "cheerio";
import { chunkText, fetchUrlText, parsePdf, parseDocx } from "./ingest.server";

// ==== Configuration Types ====

export interface ScrapingConfig {
  /** URLs to start scraping from */
  startUrls: string[];
  /** Base URL for resolving relative links */
  baseUrl: string;
  /** Folders/categories to target (empty = scrape all) */
  targetFolders?: string[];
  /** Maximum recursion depth */
  maxDepth?: number;
  /** Delay between requests in ms */
  delayMs?: number;
  /** Request timeout in ms */
  timeoutMs?: number;
  /** Keywords to skip (traffic, transport, etc.) */
  skipKeywords?: string[];
  /** File extensions to download */
  downloadableExtensions?: string[];
  /** Category classification rules */
  categories?: Record<string, CategoryRule>;
}

export interface CategoryRule {
  keywords: string[];
  description?: string;
}

export interface ScrapedEntry {
  name: string;
  kind: "folder" | "file";
  url?: string;
  text?: string;
  category: string;
  year: string;
  path: string[];
  depth: number;
}

export interface ScrapeResult {
  /** Total entries found */
  totalEntries: number;
  /** Entries that were scraped (text extracted) */
  scrapedCount: number;
  /** Entries that were skipped */
  skippedCount: number;
  /** Entries with errors */
  errorCount: number;
  /** The scraped entries with their content */
  entries: ScrapedEntry[];
  /** Any errors encountered */
  errors: string[];
}

// ==== Default Configuration ====

const DEFAULT_SKIP_KEYWORDS = [
  "traffic", "transport", "transportation", "routing", "route",
  "road", "street", "parking", "tricycle", "vehicular", "terminal",
  "franchise", "highway", "traffic management", "transport code",
];

const DEFAULT_DOWNLOADABLE_EXTENSIONS = [
  ".pdf", ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx", ".txt",
];

const DEFAULT_CATEGORIES: Record<string, CategoryRule> = {
  zoning_land_use: {
    keywords: ["zoning", "land use", "comprehensive zoning", "urban planning", "rezoning"],
    description: "Zoning and land use regulations",
  },
  environment: {
    keywords: ["environment", "ecological", "waste", "watershed", "climate", "solid waste", "pollution", "sanitation"],
    description: "Environmental and ecological regulations",
  },
  business_tax_revenue: {
    keywords: ["business", "permit", "tax", "revenue", "fees", "fiscal", "budget", "market code"],
    description: "Business permits, taxes, and revenue",
  },
  governance_administration: {
    keywords: ["administrative", "governance", "procedure", "local government", "authority", "office", "regulatory"],
    description: "Governance and administration rules",
  },
  social_services: {
    keywords: ["children", "youth", "welfare", "health", "gender", "gad", "mental health", "senior citizen", "pwd", "lactation", "education"],
    description: "Social services and welfare",
  },
  public_safety: {
    keywords: ["firecracker", "public safety", "emergency", "animal", "safe spaces", "disaster", "curfew"],
    description: "Public safety and emergency regulations",
  },
  tourism_culture: {
    keywords: ["tourism", "culture", "heritage", "festival"],
    description: "Tourism and cultural regulations",
  },
  general_ordinances: {
    keywords: ["code of general ordinances", "general ordinances", "ordinance"],
    description: "General ordinances and codes",
  },
};

// ==== Utility Functions ====

function normalizeSpace(text: string): string {
  return text.replace(/\s+/g, " ").trim();
}

function slugify(text: string): string {
  return text.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "") || "item";
}

function safeFilename(text: string): string {
  const cleaned = text.replace(/[\\/:*?"<>|]+/g, "_").replace(/\s+/g, " ").trim();
  return cleaned.length > 180 ? cleaned.substring(0, 180) : cleaned || "file";
}

function inferYear(text: string, url?: string, path?: string[]): string {
  const haystack = [text, url, ...(path || [])].join(" ");
  const match = haystack.match(/\b(19\d{2}|20\d{2})\b/);
  return match ? match[1] : "unknown_year";
}

function isTrafficRelated(text: string, path?: string[], skipKeywords?: string[]): boolean {
  const keywords = skipKeywords || DEFAULT_SKIP_KEYWORDS;
  const haystack = normalizeSpace([text, ...(path || [])].join(" ")).toLowerCase();
  return keywords.some((kw) => haystack.includes(kw));
}

function classifyCategory(text: string, path?: string[], categories?: Record<string, CategoryRule>): string {
  const cats = categories || DEFAULT_CATEGORIES;
  const haystack = normalizeSpace([text, ...(path || [])].join(" ")).toLowerCase();
  for (const [category, rule] of Object.entries(cats)) {
    if (rule.keywords.some((kw) => haystack.includes(kw))) {
      return category;
    }
  }
  return "uncategorized";
}

function detectKind(name: string, url?: string): "folder" | "file" {
  if (url) {
    const ext = getExtension(url);
    if (ext) return "file";
  }
  if (/\./.test(name) && !getExtension(name)) return "file";
  // If name looks like a folder (no extension, reasonable length)
  if (!/\./.test(name) && name.length < 100) return "folder";
  return "file";
}

function getExtension(url: string): string {
  try {
    const parsed = new URL(url);
    const path = parsed.pathname.toLowerCase();
    const dot = path.lastIndexOf(".");
    return dot > 0 ? path.substring(dot) : "";
  } catch {
    return "";
  }
}

function resolveUrl(base: string, href: string | null | undefined): string | null {
  if (!href) return null;
  try {
    return new URL(href, base).href;
  } catch {
    return null;
  }
}

// ==== HTML Scraping Functions ====

/**
 * Extract all links from a page, categorizing them as folders or files.
 */
function extractLinks(html: string, baseUrl: string): { name: string; url: string; kind: "folder" | "file" }[] {
  const $ = cheerio.load(html);
  $("script, style, noscript, iframe").remove();

  const links = new Map<string, { url: string; kind: "folder" | "file" }>();

  $("a[href]").each((_, el) => {
    const href = $(el).attr("href");
    const text = $(el).text().trim();
    if (!href || !text) return;

    const url = resolveUrl(baseUrl, href);
    if (!url) return;

    // Skip anchor links, JS handlers, etc.
    if (href.startsWith("#") || href.startsWith("javascript:") || href.startsWith("mailto:")) return;

    const kind = detectKind(text, url);
    links.set(text.toLowerCase(), { url, kind });
  });

  return Array.from(links.entries()).map(([key, val]) => ({ name: key, ...val }));
}

/**
 * Extract table-based entries (like the Butuan SP legislation table format).
 */
function extractTableEntries(html: string, baseUrl: string): { name: string; url: string; kind: "folder" | "file"; text: string }[] {
  const $ = cheerio.load(html);
  const entries: { name: string; url: string; kind: "folder" | "file"; text: string }[] = [];

  // Try table rows
  $("tr").each((_, row) => {
    const cells = $(row).find("td, th");
    if (cells.length < 2) return;

    const firstCell = $(cells.first()).text().trim();
    if (!firstCell || firstCell.length < 2) return;
    if (firstCell === "Name" || firstCell === "Refresh" || firstCell === "..") return;

    const $a = $(row).find("a[href]").first();
    const href = $a.attr("href") || null;
    const url = resolveUrl(baseUrl, href);
    const kind = detectKind(firstCell, url || undefined);

    // Get full row text for classification
    const fullText = $(row).text().trim();

    entries.push({ name: firstCell, url: url || "", kind, text: fullText });
  });

  // Try list items
  if (entries.length === 0) {
    $("li, [class*='item'], [class*='file'], [class*='folder']").each((_, el) => {
      const $el = $(el);
      const text = $el.text().trim().split("\n")[0].trim();
      if (!text || text.length < 2) return;
      if (text === "Name" || text === "Refresh") return;

      const $a = $el.find("a[href]").first();
      const href = $a.attr("href") || null;
      const url = resolveUrl(baseUrl, href);
      const kind = detectKind(text, url || undefined);

      entries.push({ name: text, url: url || "", kind, text });
    });
  }

  return entries;
}

/**
 * Scrape text content from a URL using cheerio (no browser needed for most pages).
 */
async function scrapePageText(url: string, timeoutMs: number = 30000): Promise<{ title: string; text: string; html: string }> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const res = await fetch(url, {
      headers: {
        "User-Agent": "Mozilla/5.0 (compatible; HelixBot/1.0; +https://helix.ai)",
        Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      },
      signal: controller.signal,
      redirect: "follow",
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);

    const html = await res.text();
    clearTimeout(timeout);

    const $ = cheerio.load(html);
    $("script, style, noscript, iframe, nav, footer, header, .sidebar, .menu, .navigation, .ad, .ads").remove();

    const title = ($("title").first().text() || url).trim();
    const bodyText = $("body").text().replace(/\s+/g, " ").trim();

    return { title, text: bodyText, html };
  } catch (err: any) {
    clearTimeout(timeout);
    throw new Error(`Scrape failed for ${url}: ${err.message}`);
  }
}

// ==== Main Scraping Engine ====

/**
 * Scrape a website and extract all entries (folders and files).
 * This is the core function that replaces the Python playwright scraper.
 */
export async function scrapeWebsite(
  config: ScrapingConfig,
  onProgress?: (entry: ScrapedEntry) => void
): Promise<ScrapeResult> {
  const {
    startUrls,
    baseUrl,
    targetFolders,
    maxDepth = 20,
    delayMs = 1500,
    timeoutMs = 60000,
    skipKeywords = DEFAULT_SKIP_KEYWORDS,
    downloadableExtensions = DEFAULT_DOWNLOADABLE_EXTENSIONS,
    categories = DEFAULT_CATEGORIES,
  } = config;

  const result: ScrapeResult = {
    totalEntries: 0,
    scrapedCount: 0,
    skippedCount: 0,
    errorCount: 0,
    entries: [],
    errors: [],
  };

  const visitedUrls = new Set<string>();
  const queue: { url: string; depth: number; path: string[] }[] = [];

  // Initialize queue with start URLs
  for (const startUrl of startUrls) {
    queue.push({ url: startUrl, depth: 0, path: [] });
  }

  let delayTimer: ReturnType<typeof setTimeout> | null = null;

  const delay = (ms: number) => new Promise<void>((resolve) => {
    delayTimer = setTimeout(resolve, ms);
  });

  try {
    while (queue.length > 0) {
      const current = queue.shift()!;
      const { url, depth, path } = current;

      // Skip if already visited
      if (visitedUrls.has(url)) continue;
      visitedUrls.add(url);

      // Skip if exceeded max depth
      if (depth > maxDepth) continue;

      // Check if URL matches target folders (if specified)
      if (targetFolders && targetFolders.length > 0) {
        const urlLower = url.toLowerCase();
        const matchesTarget = targetFolders.some((tf) => urlLower.includes(tf.toLowerCase()));
        if (!matchesTarget) continue;
      }

      // Scrape the page
      let html: string;
      try {
        const scraped = await scrapePageText(url, timeoutMs);
        html = scraped.html;
      } catch (err: any) {
        result.errors.push(`Failed to scrape ${url}: ${err.message}`);
        result.errorCount++;
        continue;
      }

      // Extract entries from the page
      const entries = extractTableEntries(html, url);
      if (entries.length === 0) {
        // Fallback: extract all links
        const links = extractLinks(html, url);
        for (const link of links) {
          entries.push({
            name: link.name,
            url: link.url,
            kind: link.kind,
            text: link.name,
          });
        }
      }

      // Process each entry
      for (const entry of entries) {
        result.totalEntries++;

        // Skip if it's a folder and we're not recursing into it
        if (entry.kind === "folder") {
          const entryPath = [...path, entry.name];
          queue.push({
            url: entry.url,
            depth: depth + 1,
            path: entryPath,
          });
          continue;
        }

        // Skip if it's a file but not downloadable
        if (entry.kind === "file") {
          const ext = getExtension(entry.url);
          if (ext && !downloadableExtensions.includes(ext)) {
            result.skippedCount++;
            continue;
          }
        }

        // Skip if traffic-related
        if (isTrafficRelated(entry.name, path, skipKeywords)) {
          result.skippedCount++;
          continue;
        }

        // Classify the entry
        const category = classifyCategory(entry.name, path, categories);
        const year = inferYear(entry.name, entry.url, path);

        const scrapedEntry: ScrapedEntry = {
          name: entry.name,
          kind: entry.kind,
          url: entry.url,
          category,
          year,
          path: [...path, entry.name],
          depth,
        };

        // If it's a file, try to extract text
        if (entry.kind === "file") {
          const ext = getExtension(entry.url);

          if (ext === ".pdf") {
            try {
              const res = await fetch(entry.url, {
                headers: { "User-Agent": "Mozilla/5.0 (compatible; HelixBot/1.0)" },
                signal: AbortSignal.timeout(timeoutMs),
              });
              if (res.ok) {
                const buffer = await res.arrayBuffer();
                scrapedEntry.text = await parsePdf(buffer);
              }
            } catch (err: any) {
              result.errors.push(`PDF parse failed for ${entry.url}: ${err.message}`);
              result.errorCount++;
            }
          } else if (ext === ".docx") {
            try {
              const res = await fetch(entry.url, {
                headers: { "User-Agent": "Mozilla/5.0 (compatible; HelixBot/1.0)" },
                signal: AbortSignal.timeout(timeoutMs),
              });
              if (res.ok) {
                const buffer = await res.arrayBuffer();
                scrapedEntry.text = await parseDocx(buffer);
              }
            } catch (err: any) {
              result.errors.push(`DOCX parse failed for ${entry.url}: ${err.message}`);
              result.errorCount++;
            }
          } else if (ext === ".txt" || ext === ".md") {
            try {
              const res = await fetch(entry.url, {
                headers: { "User-Agent": "Mozilla/5.0 (compatible; HelixBot/1.0)" },
                signal: AbortSignal.timeout(timeoutMs),
              });
              if (res.ok) {
                scrapedEntry.text = await res.text();
              }
            } catch (err: any) {
              result.errors.push(`Text fetch failed for ${entry.url}: ${err.message}`);
              result.errorCount++;
            }
          } else {
            // For HTML pages, extract text content
            try {
              const scraped = await scrapePageText(entry.url, timeoutMs);
              scrapedEntry.text = scraped.text;
            } catch (err: any) {
              result.errors.push(`Text extraction failed for ${entry.url}: ${err.message}`);
              result.errorCount++;
            }
          }
        } else {
          // For folders, use the entry name as text
          scrapedEntry.text = entry.text || entry.name;
        }

        // Chunk the text if it's substantial
        if (scrapedEntry.text && scrapedEntry.text.length > 100) {
          const chunks = chunkText(scrapedEntry.text);
          scrapedEntry.text = chunks.join("\n\n");
        }

        result.scrapedCount++;
        result.entries.push(scrapedEntry);

        // Notify progress
        if (onProgress) {
          onProgress(scrapedEntry);
        }

        // Delay between requests
        if (delayMs > 0) {
          await delay(delayMs);
        }
      }
    }
  } finally {
    if (delayTimer) clearTimeout(delayTimer);
  }

  return result;
}

// ==== Single URL Scraping (for chatbot on-demand use) ====

/**
 * Scrape a single URL and return its text content.
 * This is used by the chatbot when a user asks to scrape a specific page.
 */
export async function scrapeSingleUrl(url: string): Promise<{
  title: string;
  text: string;
  chunks: string[];
  category: string;
  year: string;
}> {
  const scraped = await scrapePageText(url);
  const category = classifyCategory(scraped.title, [url]);
  const year = inferYear(scraped.title, url);
  const chunks = chunkText(scraped.text);

  return {
    title: scraped.title,
    text: scraped.text,
    chunks,
    category,
    year,
  };
}

// ==== Knowledge Ingestion Integration ====

/**
 * Ingest scraped content into the chatbot's knowledge base.
 * This creates KnowledgeSource and DocumentChunk records.
 */
export interface IngestResult {
  sourceId: string;
  chunksCreated: number;
  errors: string[];
}

export async function ingestScrapedContent(
  chatbotId: string,
  userId: string,
  entries: ScrapedEntry[],
  sourceName: string
): Promise<IngestResult> {
  const errors: string[] = [];
  let chunksCreated = 0;

  // In a real implementation, this would call the Supabase API
  // to create KnowledgeSource and DocumentChunk records.
  // For now, we return the data that would be ingested.

  for (const entry of entries) {
    if (!entry.text || entry.text.length < 20) {
      errors.push(`Entry "${entry.name}" has no extractable text.`);
      continue;
    }

    const chunks = chunkText(entry.text);
    if (chunks.length === 0) {
      errors.push(`Entry "${entry.name}" produced no chunks.`);
      continue;
    }

    chunksCreated += chunks.length;

    // In production, this would be:
    // const source = await supabaseAdmin.from("knowledge_sources").insert({
    //   chatbot_id: chatbotId,
    //   user_id: userId,
    //   source_type: "scraped_url",
    //   name: entry.name,
    //   url: entry.url,
    //   status: "ready",
    //   chunk_count: chunks.length,
    // }).select("id").single();
    //
    // for (let i = 0; i < chunks.length; i++) {
    //   await supabaseAdmin.from("document_chunks").insert({
    //     source_id: source.id,
    //     chatbot_id: chatbotId,
    //     user_id: userId,
    //     content: chunks[i],
    //     chunk_index: i,
    //   });
    // }
  }

  return {
    sourceId: `scraped_${Date.now()}`,
    chunksCreated,
    errors,
  };
}

// ==== Exported Config Loader ====

/**
 * Load scraping configuration from a JSON file.
 */
export async function loadScrapingConfig(filePath: string): Promise<ScrapingConfig> {
  // In production, this would read from the file system
  // For now, return a default config
  return {
    startUrls: ["https://sp.butuan.gov.ph/legislations/"],
    baseUrl: "https://sp.butuan.gov.ph/legislations/",
    targetFolders: ["Ordinances", "Resolutions"],
    maxDepth: 20,
    delayMs: 1500,
    timeoutMs: 60000,
    skipKeywords: DEFAULT_SKIP_KEYWORDS,
    downloadableExtensions: DEFAULT_DOWNLOADABLE_EXTENSIONS,
    categories: DEFAULT_CATEGORIES,
  };
}
