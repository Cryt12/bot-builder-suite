// Scraping Widget - UI component for website scraping
// Provides a widget that allows users to configure and trigger website scraping

import React, { useState, useEffect } from "react";

// ==== Types ====

interface ScrapingConfig {
  startUrls: string[];
  baseUrl: string;
  targetFolders?: string[];
  maxDepth?: number;
  delayMs?: number;
  timeoutMs?: number;
  skipKeywords?: string[];
  downloadableExtensions?: string[];
}

interface ScrapeResult {
  sourceId: string;
  totalEntries: number;
  scrapedCount: number;
  skippedCount: number;
  errorCount: number;
  chunksCreated: number;
  errors: string[];
}

interface AvailableConfig {
  name: string;
  filePath: string;
  config: any;
}

// ==== Component ====

const ScrapeWidget: React.FC<{ chatbotId: string; userId: string }> = ({
  chatbotId,
  userId,
}) => {
  const [activeTab, setActiveTab] = useState<"run" | "configs" | "results">("run");
  const [configs, setConfigs] = useState<AvailableConfig[]>([]);
  const [selectedConfig, setSelectedConfig] = useState<AvailableConfig | null>(null);
  const [customConfig, setCustomConfig] = useState<ScrapingConfig>({
    startUrls: [""],
    baseUrl: "",
    targetFolders: [],
    maxDepth: 20,
    delayMs: 1500,
    timeoutMs: 60000,
    skipKeywords: ["traffic", "transport", "routing", "road", "street", "parking"],
    downloadableExtensions: [".pdf", ".doc", ".docx", ".txt"],
  });
  const [sourceName, setSourceName] = useState("");
  const [isRunning, setIsRunning] = useState(false);
  const [result, setResult] = useState<ScrapeResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Load available configs
  useEffect(() => {
    fetch("/api/scrape/configs")
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          setConfigs(data.configs);
        }
      })
      .catch((err) => console.error("Failed to load configs:", err));
  }, []);

  // Run scrape
  const handleRunScrape = async () => {
    setIsRunning(true);
    setError(null);
    setResult(null);

    try {
      const body: any = {
        chatbotId,
        userId,
        sourceName: sourceName || undefined,
      };

      if (selectedConfig) {
        body.configPath = selectedConfig.filePath;
      } else {
        body.config = customConfig;
      }

      const res = await fetch("/api/scrape/run", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });

      const data = await res.json();
      if (data.success) {
        setResult(data.result);
        setActiveTab("results");
      } else {
        setError(data.error || "Scraping failed");
      }
    } catch (err: any) {
      setError(err.message);
    } finally {
      setIsRunning(false);
    }
  };

  // Run single URL scrape
  const handleSingleScrape = async (url: string) => {
    setIsRunning(true);
    setError(null);

    try {
      const res = await fetch("/api/scrape/single", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url, chatbotId, userId }),
      });

      const data = await res.json();
      if (data.success) {
        setResult({
          sourceId: "single",
          totalEntries: 1,
          scrapedCount: 1,
          skippedCount: 0,
          errorCount: 0,
          chunksCreated: data.result.chunks.length,
          errors: [],
        });
        setActiveTab("results");
      } else {
        setError(data.error);
      }
    } catch (err: any) {
      setError(err.message);
    } finally {
      setIsRunning(false);
    }
  };

  return (
    <div className="scrape-widget">
      {/* Header */}
      <div className="scrape-widget-header">
        <h3>Website Scraper</h3>
        <p>Ingest website content into your chatbot knowledge base</p>
      </div>

      {/* Tabs */}
      <div className="scrape-widget-tabs">
        <button
          className={activeTab === "run" ? "active" : ""}
          onClick={() => setActiveTab("run")}
        >
          Run Scrape
        </button>
        <button
          className={activeTab === "configs" ? "active" : ""}
          onClick={() => setActiveTab("configs")}
        >
          Configs ({configs.length})
        </button>
        <button
          className={activeTab === "results" ? "active" : ""}
          onClick={() => setActiveTab("results")}
        >
          Results
        </button>
      </div>

      {/* Error */}
      {error && (
        <div className="scrape-widget-error">
          <span>{error}</span>
          <button onClick={() => setError(null)}>×</button>
        </div>
      )}

      {/* Run Tab */}
      {activeTab === "run" && (
        <div className="scrape-widget-body">
          {/* Config Selection */}
          <div className="scrape-field">
            <label>Configuration</label>
            <select
              value={selectedConfig?.name || "custom"}
              onChange={(e) => {
                if (e.target.value === "custom") {
                  setSelectedConfig(null);
                } else {
                  const config = configs.find((c) => c.name === e.target.value);
                  setSelectedConfig(config || null);
                }
              }}
            >
              <option value="custom">Custom</option>
              {configs.map((c) => (
                <option key={c.name} value={c.name}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>

          {/* Source Name */}
          <div className="scrape-field">
            <label>Source Name (optional)</label>
            <input
              type="text"
              placeholder="e.g., Butuan Ordinances 2024"
              value={sourceName}
              onChange={(e) => setSourceName(e.target.value)}
            />
          </div>

          {/* Custom Config */}
          {!selectedConfig && (
            <div className="scrape-config-section">
              <h4>Custom Configuration</h4>

              <div className="scrape-field">
                <label>Start URLs (comma-separated)</label>
                <input
                  type="text"
                  value={customConfig.startUrls.join(", ")}
                  onChange={(e) =>
                    setCustomConfig((prev) => ({
                      ...prev,
                      startUrls: e.target.value.split(",").map((u) => u.trim()),
                    }))
                  }
                />
              </div>

              <div className="scrape-field">
                <label>Base URL</label>
                <input
                  type="text"
                  value={customConfig.baseUrl}
                  onChange={(e) =>
                    setCustomConfig((prev) => ({ ...prev, baseUrl: e.target.value }))
                  }
                />
              </div>

              <div className="scrape-field">
                <label>Target Folders (comma-separated, leave empty for all)</label>
                <input
                  type="text"
                  value={(customConfig.targetFolders || []).join(", ")}
                  onChange={(e) =>
                    setCustomConfig((prev) => ({
                      ...prev,
                      targetFolders: e.target.value.split(",").map((f) => f.trim()),
                    }))
                  }
                />
              </div>

              <div className="scrape-field-row">
                <div className="scrape-field">
                  <label>Max Depth</label>
                  <input
                    type="number"
                    value={customConfig.maxDepth}
                    onChange={(e) =>
                      setCustomConfig((prev) => ({
                        ...prev,
                        maxDepth: Number(e.target.value),
                      }))
                    }
                  />
                </div>
                <div className="scrape-field">
                  <label>Delay (ms)</label>
                  <input
                    type="number"
                    value={customConfig.delayMs}
                    onChange={(e) =>
                      setCustomConfig((prev) => ({
                        ...prev,
                        delayMs: Number(e.target.value),
                      }))
                    }
                  />
                </div>
              </div>
            </div>
          )}

          {/* Selected Config Preview */}
          {selectedConfig && (
            <div className="scrape-config-preview">
              <h4>Selected Config: {selectedConfig.name}</h4>
              <pre>{JSON.stringify(selectedConfig.config, null, 2)}</pre>
            </div>
          )}

          {/* Run Button */}
          <button
            className="scrape-run-button"
            onClick={handleRunScrape}
            disabled={isRunning || !customConfig.baseUrl && !selectedConfig}
          >
            {isRunning ? "Scraping..." : "Start Scraping"}
          </button>
        </div>
      )}

      {/* Configs Tab */}
      {activeTab === "configs" && (
        <div className="scrape-widget-body">
          {configs.length === 0 ? (
            <p className="scrape-empty">No saved configurations found.</p>
          ) : (
            <div className="scrape-config-list">
              {configs.map((config) => (
                <div key={config.name} className="scrape-config-card">
                  <h4>{config.name}</h4>
                  <pre>{JSON.stringify(config.config, null, 2)}</pre>
                  <button
                    onClick={() => {
                      setSelectedConfig(config);
                      setActiveTab("run");
                    }}
                  >
                    Use This Config
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Results Tab */}
      {activeTab === "results" && (
        <div className="scrape-widget-body">
          {result ? (
            <div className="scrape-result">
              <div className="scrape-result-stats">
                <div className="stat">
                  <span className="stat-value">{result.totalEntries}</span>
                  <span className="stat-label">Total Entries</span>
                </div>
                <div className="stat success">
                  <span className="stat-value">{result.scrapedCount}</span>
                  <span className="stat-label">Scraped</span>
                </div>
                <div className="stat warning">
                  <span className="stat-value">{result.skippedCount}</span>
                  <span className="stat-label">Skipped</span>
                </div>
                <div className="stat error">
                  <span className="stat-value">{result.errorCount}</span>
                  <span className="stat-label">Errors</span>
                </div>
                <div className="stat info">
                  <span className="stat-value">{result.chunksCreated}</span>
                  <span className="stat-label">Chunks Created</span>
                </div>
              </div>

              {result.errors.length > 0 && (
                <div className="scrape-result-errors">
                  <h4>Errors</h4>
                  <ul>
                    {result.errors.map((err, i) => (
                      <li key={i}>{err}</li>
                    ))}
                  </ul>
                </div>
              )}

              <p className="scrape-result-success">
                Successfully scraped and ingested content into your knowledge base!
              </p>
            </div>
          ) : (
            <p className="scrape-empty">No results yet. Run a scrape to see results here.</p>
          )}
        </div>
      )}

      {/* Inline Styles */}
      <style>{`
        .scrape-widget {
          border: 1px solid #e5e7eb;
          border-radius: 8px;
          overflow: hidden;
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .scrape-widget-header {
          padding: 16px 20px;
          background: #f9fafb;
          border-bottom: 1px solid #e5e7eb;
        }

        .scrape-widget-header h3 {
          margin: 0 0 4px 0;
          font-size: 16px;
          font-weight: 600;
          color: #111827;
        }

        .scrape-widget-header p {
          margin: 0;
          font-size: 13px;
          color: #6b7280;
        }

        .scrape-widget-tabs {
          display: flex;
          border-bottom: 1px solid #e5e7eb;
          background: #f9fafb;
        }

        .scrape-widget-tabs button {
          flex: 1;
          padding: 10px 16px;
          border: none;
          background: none;
          cursor: pointer;
          font-size: 13px;
          font-weight: 500;
          color: #6b7280;
          border-bottom: 2px solid transparent;
          transition: all 0.15s;
        }

        .scrape-widget-tabs button.active {
          color: #2563eb;
          border-bottom-color: #2563eb;
          background: white;
        }

        .scrape-widget-tabs button:hover:not(.active) {
          color: #374151;
          background: #f3f4f6;
        }

        .scrape-widget-body {
          padding: 20px;
        }

        .scrape-widget-error {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 12px 16px;
          margin: 12px 16px;
          background: #fef2f2;
          border: 1px solid #fecaca;
          border-radius: 6px;
          color: #dc2626;
          font-size: 13px;
        }

        .scrape-widget-error button {
          background: none;
          border: none;
          cursor: pointer;
          font-size: 16px;
          color: #dc2626;
          padding: 0 4px;
        }

        .scrape-field {
          margin-bottom: 12px;
        }

        .scrape-field label {
          display: block;
          font-size: 12px;
          font-weight: 500;
          color: #374151;
          margin-bottom: 4px;
        }

        .scrape-field input,
        .scrape-field select {
          width: 100%;
          padding: 8px 12px;
          border: 1px solid #d1d5db;
          border-radius: 6px;
          font-size: 13px;
          transition: border-color 0.15s;
        }

        .scrape-field input:focus,
        .scrape-field select:focus {
          outline: none;
          border-color: #2563eb;
          box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }

        .scrape-field-row {
          display: flex;
          gap: 12px;
        }

        .scrape-field-row .scrape-field {
          flex: 1;
        }

        .scrape-config-section {
          margin-top: 16px;
          padding-top: 16px;
          border-top: 1px solid #e5e7eb;
        }

        .scrape-config-section h4 {
          margin: 0 0 12px 0;
          font-size: 14px;
          color: #111827;
        }

        .scrape-config-preview {
          margin-top: 16px;
          padding: 12px;
          background: #f0fdf4;
          border: 1px solid #bbf7d0;
          border-radius: 6px;
        }

        .scrape-config-preview h4 {
          margin: 0 0 8px 0;
          font-size: 14px;
          color: #166534;
        }

        .scrape-config-preview pre {
          margin: 0;
          font-size: 12px;
          color: #166534;
          max-height: 200px;
          overflow: auto;
        }

        .scrape-run-button {
          width: 100%;
          padding: 12px 16px;
          margin-top: 16px;
          background: #2563eb;
          color: white;
          border: none;
          border-radius: 6px;
          font-size: 14px;
          font-weight: 600;
          cursor: pointer;
          transition: background 0.15s;
        }

        .scrape-run-button:hover:not(:disabled) {
          background: #1d4ed8;
        }

        .scrape-run-button:disabled {
          background: #93c5fd;
          cursor: not-allowed;
        }

        .scrape-config-list {
          display: flex;
          flex-direction: column;
          gap: 12px;
        }

        .scrape-config-card {
          padding: 12px;
          background: #f9fafb;
          border: 1px solid #e5e7eb;
          border-radius: 6px;
        }

        .scrape-config-card h4 {
          margin: 0 0 8px 0;
          font-size: 14px;
          color: #111827;
        }

        .scrape-config-card pre {
          margin: 0 0 8px 0;
          font-size: 12px;
          color: #374151;
          max-height: 150px;
          overflow: auto;
        }

        .scrape-config-card button {
          padding: 6px 12px;
          background: #2563eb;
          color: white;
          border: none;
          border-radius: 4px;
          font-size: 12px;
          cursor: pointer;
        }

        .scrape-result-stats {
          display: flex;
          gap: 12px;
          margin-bottom: 16px;
        }

        .stat {
          flex: 1;
          padding: 12px;
          text-align: center;
          border-radius: 6px;
          background: #f9fafb;
          border: 1px solid #e5e7eb;
        }

        .stat.success {
          background: #f0fdf4;
          border-color: #bbf7d0;
        }

        .stat.warning {
          background: #fffbeb;
          border-color: #fde68a;
        }

        .stat.error {
          background: #fef2f2;
          border-color: #fecaca;
        }

        .stat.info {
          background: #eff6ff;
          border-color: #bfdbfe;
        }

        .stat-value {
          display: block;
          font-size: 24px;
          font-weight: 700;
          color: #111827;
        }

        .stat-label {
          display: block;
          font-size: 11px;
          color: #6b7280;
          margin-top: 2px;
        }

        .scrape-result-errors {
          margin-bottom: 16px;
          padding: 12px;
          background: #fef2f2;
          border: 1px solid #fecaca;
          border-radius: 6px;
        }

        .scrape-result-errors h4 {
          margin: 0 0 8px 0;
          font-size: 13px;
          color: #dc2626;
        }

        .scrape-result-errors ul {
          margin: 0;
          padding-left: 16px;
          font-size: 12px;
          color: #991b1b;
        }

        .scrape-result-success {
          padding: 12px;
          background: #f0fdf4;
          border: 1px solid #bbf7d0;
          border-radius: 6px;
          color: #166534;
          font-size: 13px;
          text-align: center;
        }

        .scrape-empty {
          text-align: center;
          color: #9ca3af;
          font-size: 13px;
          padding: 24px 0;
        }
      `}</style>
    </div>
  );
};

export default ScrapeWidget;
