<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\KnowledgeSource;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;

class ChatbotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        return $this->json([
            'bots' => Chatbot::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
        ]);

        $bot = Chatbot::create([
            'user_id' => $user->id,
            'name' => $data['name'],
        ]);

        return $this->json(['bot' => $bot], 201);
    }

    public function show(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        return $this->json(['bot' => $chatbot]);
    }

    public function update(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'welcome_message' => ['sometimes', 'string', 'max:500'],
            'system_prompt' => ['sometimes', 'string', 'max:4000'],
            'primary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bubble_position' => ['sometimes', Rule::in(['right', 'left'])],
            'tone' => ['sometimes', Rule::in(['friendly', 'formal', 'playful', 'concise'])],
            'language' => ['sometimes', 'string', 'max:16'],
            'collect_email' => ['sometimes', 'boolean'],
            'allowed_domains' => ['sometimes', 'array', 'min:1', 'max:20'],
            'allowed_domains.*' => ['required', 'string', 'max:253'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('allowed_domains', $data)) {
            $data['allowed_domains'] = collect($data['allowed_domains'])
                ->map(fn ($domain) => $this->normalizeDomain($domain))
                ->filter()
                ->unique()
                ->values()
                ->all();

            abort_unless(count($data['allowed_domains']) > 0, 422, 'At least one allowed domain is required.');
        }

        $chatbot->update($data);

        return $this->json(['bot' => $chatbot->fresh()]);
    }

    public function destroy(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);
        $chatbot->delete();

        return $this->json(['ok' => true]);
    }

    public function sources(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        return $this->json([
            'sources' => KnowledgeSource::query()
                ->where('chatbot_id', $chatbot->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function ingestText(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'text' => ['required', 'string', 'min:20', 'max:500000'],
        ]);

        return $this->json($this->storeKnowledge($chatbot, 'text', $data['name'], $data['text']));
    }

    public function ingestUrl(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $response = Http::timeout(30)->get($data['url']);
        abort_unless($response->ok(), 422, 'Could not fetch URL.');

        $title = Str::of($response->body())->match('/<title[^>]*>(.*?)<\/title>/is')->trim()->limit(200);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($response->body())));
        abort_unless(strlen($text) >= 20, 422, 'No extractable text found.');

        return $this->json($this->storeKnowledge($chatbot, 'url', (string) ($title ?: $data['url']), $text, $data['url']));
    }

    public function ingestFile(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());

        abort_unless(in_array($extension, ['pdf', 'docx', 'txt', 'md'], true), 422, 'Unsupported file type.');

        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'document';
        $storedName = $safeName . '-' . Str::random(8) . '.' . $extension;
        $storagePath = $file->storeAs("knowledge/{$chatbot->user_id}/{$chatbot->id}", $storedName);
        $absolutePath = Storage::path($storagePath);

        $text = $this->extractFileText($absolutePath, $extension);
        abort_unless(strlen(trim($text)) >= 20, 422, 'No extractable text found.');

        return $this->json($this->storeKnowledge(
            $chatbot,
            'file',
            $file->getClientOriginalName(),
            $text,
            null,
            $storagePath,
            $file->getSize(),
        ));
    }

    public function destroySource(Request $request, KnowledgeSource $source): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        abort_unless($source->user_id === $user->id, 404);
        $source->delete();

        return $this->json(['ok' => true]);
    }

    public function downloadSourceChunks(Request $request, KnowledgeSource $source): Response
    {
        $user = $request->attributes->get('auth_user');

        abort_unless($source->user_id === $user->id, 404);

        $chunks = DocumentChunk::query()
            ->where('source_id', $source->id)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content']);

        $lines = [
            'Source Name: ' . $source->name,
            'Source Type: ' . $source->source_type,
            'Status: ' . $source->status,
            'Chunk Count: ' . $chunks->count(),
        ];

        if ($source->url) {
            $lines[] = 'Source URL: ' . $source->url;
        }

        if ($source->created_at) {
            $lines[] = 'Created At: ' . $source->created_at->toIso8601String();
        }

        $lines[] = '';
        $lines[] = '===== CHUNKS =====';
        $lines[] = '';

        foreach ($chunks as $chunk) {
            $lines[] = '--- Chunk ' . ($chunk->chunk_index + 1) . ' ---';
            $lines[] = $chunk->content;
            $lines[] = '';
        }

        $filename = (Str::slug(pathinfo($source->name, PATHINFO_FILENAME)) ?: 'source-chunks') . '-chunks.txt';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function conversations(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        return $this->json([
            'conversations' => Conversation::query()
                ->where('chatbot_id', $chatbot->id)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(['id', 'created_at', 'visitor_id', 'visitor_email', 'source']),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        abort_unless($conversation->user_id === $user->id, 404);

        return $this->json([
            'messages' => Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->get(['id', 'role', 'content', 'created_at']),
        ]);
    }

    public function analytics(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $since = now()->subDays(30);
        $messages30d = Message::query()
            ->where('user_id', $chatbot->user_id)
            ->where('created_at', '>=', $since)
            ->count();

        $conversations30d = Conversation::query()
            ->where('chatbot_id', $chatbot->id)
            ->where('created_at', '>=', $since)
            ->count();

        $rows = Message::query()
            ->select(DB::raw("to_char(created_at::date, 'YYYY-MM-DD') as date"), DB::raw('count(*) as chats'))
            ->where('user_id', $chatbot->user_id)
            ->where('role', 'user')
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw('created_at::date'))
            ->pluck('chats', 'date');

        $daily = collect(range(29, 0))->map(function ($offset) use ($rows) {
            $date = now()->subDays($offset)->toDateString();

            return ['date' => $date, 'chats' => (int) ($rows[$date] ?? 0)];
        })->values();

        return $this->json(compact('conversations30d', 'messages30d', 'daily'));
    }

    private function authorizeOwner(Request $request, Chatbot $chatbot): void
    {
        $user = $request->attributes->get('auth_user');

        abort_unless($chatbot->user_id === $user->id, 404);
    }

    private function normalizeDomain(string $value): ?string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
        } else {
            $host = parse_url('http://' . $value, PHP_URL_HOST);
        }

        $host = trim((string) $host);

        return $host === '' ? null : $host;
    }

    private function storeKnowledge(
        Chatbot $chatbot,
        string $type,
        string $name,
        string $text,
        ?string $url = null,
        ?string $storagePath = null,
        ?int $sizeBytes = null,
    ): array
    {
        $source = KnowledgeSource::create([
            'chatbot_id' => $chatbot->id,
            'user_id' => $chatbot->user_id,
            'source_type' => $type,
            'name' => $name,
            'url' => $url,
            'storage_path' => $storagePath,
            'size_bytes' => $sizeBytes,
            'status' => 'processing',
        ]);

        try {
            $chunks = $this->chunkText($text);
            abort_if(count($chunks) === 0, 422, 'No extractable text found.');

            foreach ($chunks as $index => $chunk) {
                DocumentChunk::create([
                    'source_id' => $source->id,
                    'chatbot_id' => $chatbot->id,
                    'user_id' => $chatbot->user_id,
                    'content' => $chunk,
                    'chunk_index' => $index,
                ]);
            }

            $source->update(['status' => 'ready', 'chunk_count' => count($chunks)]);

            return ['sourceId' => $source->id, 'chunks' => count($chunks)];
        } catch (\Throwable $e) {
            $source->update(['status' => 'error', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function chunkText(string $text): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text));
        $chunks = [];
        $size = 1000;
        $overlap = 150;
        $offset = 0;

        while ($offset < strlen($clean)) {
            $end = min($offset + $size, strlen($clean));
            $cut = $end;

            if ($end < strlen($clean)) {
                $tail = substr($clean, $offset, $size);
                $lastBoundary = max(strrpos($tail, '. ') ?: 0, strrpos($tail, '! ') ?: 0, strrpos($tail, '? ') ?: 0);
                if ($lastBoundary > $size * 0.5) {
                    $cut = $offset + $lastBoundary + 1;
                }
            }

            $chunk = trim(substr($clean, $offset, $cut - $offset));
            if (strlen($chunk) > 20) {
                $chunks[] = $chunk;
            }

            if ($cut >= strlen($clean)) {
                break;
            }

            $offset = max($cut - $overlap, $offset + 1);
        }

        return $chunks;
    }

    private function extractFileText(string $path, string $extension): string
    {
        return match ($extension) {
            'txt', 'md' => file_get_contents($path) ?: '',
            'pdf' => $this->extractPdfText($path),
            'docx' => $this->extractDocxText($path),
            default => '',
        };
    }

    private function extractPdfText(string $path): string
    {
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Could not extract PDF text.');
        }

        return $process->getOutput();
    }

    private function extractDocxText(string $path): string
    {
        $process = new Process(['unzip', '-p', $path, 'word/document.xml']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Could not extract DOCX text.');
        }

        $xml = $process->getOutput();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return trim(strip_tags($xml));
        }

        $parts = [];
        foreach ($document->getElementsByTagNameNS('*', 't') as $node) {
            $parts[] = $node->textContent;
        }

        return implode(' ', $parts);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        ]);
    }
}
