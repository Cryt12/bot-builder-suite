// Lovable AI gateway helpers (server-only)
const GATEWAY = "https://ai.gateway.lovable.dev/v1";

export async function embedTexts(texts: string[]): Promise<number[][]> {
  const key = process.env.LOVABLE_API_KEY;
  if (!key) throw new Error("LOVABLE_API_KEY missing");

  // Gemini embedding: text-embedding-004 (768 dims). The gateway exposes it as google/text-embedding-004.
  const res = await fetch(`${GATEWAY}/embeddings`, {
    method: "POST",
    headers: { Authorization: `Bearer ${key}`, "Content-Type": "application/json" },
    body: JSON.stringify({
      model: "google/text-embedding-004",
      input: texts,
    }),
  });
  if (!res.ok) {
    const t = await res.text();
    throw new Error(`Embeddings failed [${res.status}]: ${t}`);
  }
  const data: any = await res.json();
  return data.data.map((d: any) => d.embedding);
}

export async function chatComplete(args: {
  system: string;
  messages: { role: "user" | "assistant"; content: string }[];
  stream?: boolean;
}): Promise<Response> {
  const key = process.env.LOVABLE_API_KEY;
  if (!key) throw new Error("LOVABLE_API_KEY missing");

  return fetch(`${GATEWAY}/chat/completions`, {
    method: "POST",
    headers: { Authorization: `Bearer ${key}`, "Content-Type": "application/json" },
    body: JSON.stringify({
      model: "google/gemini-3-flash-preview",
      messages: [{ role: "system", content: args.system }, ...args.messages],
      stream: args.stream ?? false,
    }),
  });
}
