// Scraping API routes for chatbot knowledge ingestion
// Uses Laravel backend (PostgreSQL helix database) instead of Supabase

import { Router, Request, Response } from "express";
import { scrapeWebsite, scrapeSingleUrl, loadScrapingConfig } from "../scrape.server";
import { chunkText } from "../ingest.server";
import * as fs from "fs/promises";
import * as path from "path";

const router = Router();

// Laravel API base URL
const LARAVEL_API = process.env.LARAVEL_API_URL || "http://localhost:8082/api";

// Scrape request interface
interface ScrapeRequest {
  config?: any;
  configPath?: string;
  chatbotId: string;
  userId: string;
  sourceName?: string;
  apiKey?: string;
}

// Helper to get Laravel API auth headers
async function getAuthHeaders(apiKey?: string): Promise<Record<string, string>> {
  if (apiKey) {
    return {
      "Content-Type": "application/json",
      "Authorization": `Bearer ${apiKey}`,
    };
  }
  return { "Content-Type": "application/json" };
}

/**
 * GET /api/scrape/configs
 * Load all scraping configurations from the config directory
 */
router.get("/configs", async (_req: Request, res: Response) => {
  try {
    const configDir = path.join(__dirname, "../../../config");
    const files = await fs.readdir(configDir);
    const jsonFiles = files.filter((f) => f.endsWith(".json"));

    const configs = [];
    for (const file of jsonFiles) {
      const filePath = path.join(configDir, file);
      const content = await fs.readFile(filePath, "utf-8");
      const config = JSON.parse(content);
      configs.push({
        name: file.replace(".json", ""),
        filePath: filePath,
        config,
      });
    }

    res.json({ success: true, configs });
  } catch (err: any) {
    res.status(500).json({ success: false, error: err.message });
  }
});

/**
 * POST /api/scrape/run
 * Trigger a website scrape and ingest into knowledge base via Laravel
 */
router.post("/run", async (req: Request, res: Response) => {
  try {
    const { config, configPath, chatbotId, userId, sourceName, apiKey }: ScrapeRequest = req.body;

    if (!chatbotId) {
      return res.status(400).json({ success: false, error: "chatbotId is required" });
    }

    // Load config
    let scrapingConfig: any;
    if (configPath) {
      const raw = await fs.readFile(configPath, "utf-8");
      scrapingConfig = JSON.parse(raw);
    } else if (config) {
      scrapingConfig = config;
    } else {
      // Default config
      scrapingConfig = {
        startUrls: ["https://sp.butuan.gov.ph/legislations/"],
        baseUrl: "https://sp.butuan.gov.ph/legislations/",
        targetFolders: ["Ordinances", "Resolutions"],
        maxDepth: 20,
        delayMs: 1500,
        timeoutMs: 60000,
      };
    }

    // Run scraping
    const scrapeResult = await scrapeWebsite(scrapingConfig, (entry) => {
      // Could emit WebSocket events here for progress
    });

    if (scrapeResult.entries.length === 0) {
      return res.status(400).json({
        success: false,
        error: "No entries found during scraping",
        result: scrapeResult,
      });
    }

    // Use Laravel API to ingest if chatbotId provided
    if (chatbotId) {
      const sourceNameFinal = sourceName || `Scraped: ${scrapingConfig.baseUrl}`;
      const headers = await getAuthHeaders(apiKey);

      // Ingest each scraped entry individually via Laravel's ingestText endpoint
      const ingestPromises = scrapeResult.entries.map((entry) =>
        fetch(`${LARAVEL_API}/chatbots/${chatbotId}/sources/text`, {
          method: "POST",
          headers,
          body: JSON.stringify({
            name: entry.name,
            text: entry.text || "",
          }),
        })
      );

      const ingestResponses = await Promise.all(ingestPromises);
      const failedIngests = ingestResponses
        .filter((r) => !r.ok)
        .map((r) => `Failed to ingest: ${r.status}`);

      if (failedIngests.length > 0) {
        console.error(`Laravel ingest errors: ${failedIngests.join(", ")}`);
      }
    }

    res.json({
      success: true,
      result: {
        totalEntries: scrapeResult.totalEntries,
        scrapedCount: scrapeResult.scrapedCount,
        skippedCount: scrapeResult.skippedCount,
        errorCount: scrapeResult.errorCount,
        chunksCreated: scrapeResult.entries.reduce(
          (sum, e) => sum + (e.text ? chunkText(e.text).length : 0),
          0
        ),
        errors: scrapeResult.errors,
      },
    });
  } catch (err: any) {
    res.status(500).json({ success: false, error: err.message });
  }
});

/**
 * POST /api/scrape/single
 * Scrape a single URL (for on-demand chatbot use)
 */
router.post("/single", async (req: Request, res: Response) => {
  try {
    const { url, chatbotId, userId, apiKey } = req.body;

    if (!url) {
      return res.status(400).json({ success: false, error: "URL is required" });
    }

    const result = await scrapeSingleUrl(url);

    if (chatbotId) {
      const headers = await getAuthHeaders(apiKey);

      // Ingest into Laravel knowledge base via ingestText endpoint
      const sourceRes = await fetch(`${LARAVEL_API}/chatbots/${chatbotId}/sources/text`, {
        method: "POST",
        headers,
        body: JSON.stringify({
          name: result.title,
          text: result.text,
        }),
      });

      if (!sourceRes.ok) {
        const errBody = await sourceRes.text();
        console.error(`Laravel API error: ${sourceRes.status} - ${errBody}`);
      }
    }

    res.json({
      success: true,
      result: {
        title: result.title,
        text: result.text,
        chunks: result.chunks,
        category: result.category,
        year: result.year,
      },
    });
  } catch (err: any) {
    res.status(500).json({ success: false, error: err.message });
  }
});

/**
 * GET /api/scrape/status/:sourceId
 * Get the status of a scraping job via Laravel
 */
router.get("/status/:sourceId", async (req: Request, res: Response) => {
  try {
    const { sourceId } = req.params;
    const { apiKey } = req.query;

    const headers = await getAuthHeaders(apiKey as string | undefined);
    const res2 = await fetch(`${LARAVEL_API}/chatbots/${sourceId}/sources`, { headers });

    if (!res2.ok) {
      return res.status(res2.status).json({ success: false, error: "Source not found" });
    }

    const data = await res2.json();
    res.json({ success: true, source: data });
  } catch (err: any) {
    res.status(500).json({ success: false, error: err.message });
  }
});

export default router;
