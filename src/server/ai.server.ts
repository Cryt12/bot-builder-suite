// Lovable AI gateway helpers (server-only)
const GATEWAY = "https://ai.gateway.lovable.dev/v1";

export async function chatComplete(args: {
  system: string;
  messages: { role: "user" | "assistant"; content: string }[];
  stream?: boolean;
  model?: string;
}): Promise<Response> {
  const key = process.env.LOVABLE_API_KEY;
  if (!key) throw new Error("LOVABLE_API_KEY missing");

  return fetch(`${GATEWAY}/chat/completions`, {
    method: "POST",
    headers: { Authorization: `Bearer ${key}`, "Content-Type": "application/json" },
    body: JSON.stringify({
      model: args.model ?? "google/gemini-3-flash-preview",
      messages: [{ role: "system", content: args.system }, ...args.messages],
      stream: args.stream ?? false,
    }),
  });
}

export async function chatJSON(args: {
  system: string;
  user: string;
}): Promise<string> {
  const res = await chatComplete({
    system: args.system,
    messages: [{ role: "user", content: args.user }],
  });
  if (!res.ok) {
    const t = await res.text();
    throw new Error(`Chat failed [${res.status}]: ${t}`);
  }
  const data: any = await res.json();
  return data.choices?.[0]?.message?.content ?? "";
}
