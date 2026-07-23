<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\KnowledgeSource;
use App\Models\Message;
use App\Support\OllamaEmbeddings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;

class ChatbotController extends Controller
{
    /** Nesting levels of a JSON document that may still be split into separate records. */
    private const JSON_SPLIT_MAX_DEPTH = 3;

    /** Nesting levels flattened inside a single record before falling back to raw JSON. */
    private const JSON_MAX_DEPTH = 8;

    private const JSON_MAX_SEGMENTS = 5000;

    private const JSON_MAX_LINES_PER_SEGMENT = 400;

    /** Footer logos a bot may show next to its footer text. */
    private const MAX_FOOTER_LOGOS = 3;

    public function dashboardAnalytics(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $bots = $this->visibleBotsQuery($request)
            ->orderBy('name')
            ->get(['id', 'name', 'primary_color', 'is_active']);

        if ($bots->isEmpty()) {
            return $this->json([
                'messagesThisMonth' => 0,
                'sessionsThisMonth' => 0,
                'avgMessagesPerSession' => 0,
                'daily' => collect(range(29, 0))->map(fn (int $offset) => [
                    'date' => now()->subDays($offset)->toDateString(),
                    'messages' => 0,
                ])->values(),
                'perBot' => [],
            ]);
        }

        $botIds = $bots->pluck('id');
        $monthStart = now()->startOfMonth();
        $since30Days = now()->subDays(30);

        $sessionsByBot = Conversation::query()
            ->select('chatbot_id', DB::raw('count(*) as sessions'))
            ->whereIn('chatbot_id', $botIds)
            ->where('created_at', '>=', $monthStart)
            ->groupBy('chatbot_id')
            ->pluck('sessions', 'chatbot_id');

        $messagesByBot = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->select('conversations.chatbot_id', DB::raw('count(messages.id) as messages'))
            ->whereIn('conversations.chatbot_id', $botIds)
            ->where('messages.role', 'user')
            ->where('messages.created_at', '>=', $monthStart)
            ->groupBy('conversations.chatbot_id')
            ->pluck('messages', 'conversations.chatbot_id');

        $dailyRows = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->select(DB::raw("to_char(messages.created_at::date, 'YYYY-MM-DD') as date"), DB::raw('count(messages.id) as messages'))
            ->whereIn('conversations.chatbot_id', $botIds)
            ->where('messages.role', 'user')
            ->where('messages.created_at', '>=', $since30Days)
            ->groupBy(DB::raw('messages.created_at::date'))
            ->pluck('messages', 'date');

        $daily = collect(range(29, 0))->map(function (int $offset) use ($dailyRows) {
            $date = now()->subDays($offset)->toDateString();

            return [
                'date' => $date,
                'messages' => (int) ($dailyRows[$date] ?? 0),
            ];
        })->values();

        $perBot = $bots->map(function (Chatbot $bot) use ($sessionsByBot, $messagesByBot) {
            $sessions = (int) ($sessionsByBot[$bot->id] ?? 0);
            $messages = (int) ($messagesByBot[$bot->id] ?? 0);

            return [
                'id' => $bot->id,
                'name' => $bot->name,
                'primary_color' => $bot->primary_color,
                'is_active' => (bool) $bot->is_active,
                'messages' => $messages,
                'sessions' => $sessions,
                'avg_messages_per_session' => $sessions > 0 ? round($messages / $sessions, 1) : 0,
            ];
        })->sortByDesc('messages')->values();

        $messagesThisMonth = (int) $perBot->sum('messages');
        $sessionsThisMonth = (int) $perBot->sum('sessions');
        $avgMessagesPerSession = $sessionsThisMonth > 0 ? round($messagesThisMonth / $sessionsThisMonth, 1) : 0;

        return $this->json([
            'messagesThisMonth' => $messagesThisMonth,
            'sessionsThisMonth' => $sessionsThisMonth,
            'avgMessagesPerSession' => $avgMessagesPerSession,
            'daily' => $daily,
            'perBot' => $perBot,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->json([
            'bots' => $this->visibleBotsQuery($request)
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Chatbot $bot) => $this->serializeBot($request, $bot)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
        ]);

        $bot = Chatbot::create([
            'user_id'       => $user->id,
            'name'          => $data['name'],
            'llm_provider'  => config('models.llm.default_provider'),
            'llm_model'     => config('models.llm.' . config('models.llm.default_provider') . '.model'),
        ]);

        return $this->json(['bot' => $this->serializeBot($request, $bot)], 201);
    }

    public function show(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        return $this->json(['bot' => $this->serializeBot($request, $chatbot)]);
    }

    public function playgroundChat(Request $request, Chatbot $chatbot, PublicChatController $publicChatController): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $data = $publicChatController->validateChatPayload($request, false);

        return $publicChatController->handleChat($request, $chatbot, $data, 'playground');
    }

    public function update(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $payload = $request->all();
        if (array_key_exists('llm_model', $payload) && ! is_string($payload['llm_model'])) {
            unset($payload['llm_model']);
        }

        $data = Validator::make($payload, [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'welcome_message' => ['sometimes', 'string', 'max:500'],
            'system_prompt' => ['sometimes', 'string', 'max:4000'],
            'primary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bubble_position' => ['sometimes', Rule::in(['right', 'left'])],
            'tone' => ['sometimes', Rule::in(['friendly', 'formal', 'playful', 'concise'])],
            'language' => ['sometimes', 'string', 'max:16'],
            'collect_email' => ['sometimes', 'boolean'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cta_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:80'],
            'logo_scale' => ['sometimes', 'integer', 'min:50', 'max:200'],
            'footer_logo_scales' => ['sometimes', 'array', 'max:' . self::MAX_FOOTER_LOGOS],
            'footer_logo_scales.*' => ['integer', 'min:50', 'max:200'],
            'allowed_domains' => ['sometimes', 'array', 'min:1', 'max:20'],
            'allowed_domains.*' => ['required', 'string', 'max:253'],
            'public_rate_limit_per_minute' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'widget_cache_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'regenerate_public_key' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'llm_provider' => ['sometimes', Rule::in(['ollama', 'openrouter'])],
            'llm_model' => ['sometimes', 'string', 'max:128'],
        ])->validate();

        // Auto-resolve default model when provider changes
        if (array_key_exists('llm_provider', $data) && empty($data['llm_model'])) {
            $provider = $data['llm_provider'];
            $default  = config("models.llm.{$provider}.model") ?: config("services.{$provider}.model");
            if ($default) {
                $data['llm_model'] = $default;
            }
        }

        if (! Chatbot::supportsPublicRateLimit()) {
            unset($data['public_rate_limit_per_minute']);
        }

        if (! Chatbot::supportsWidgetCacheMinutes()) {
            unset($data['widget_cache_minutes']);
        }

        if (! Chatbot::supportsLogoScale()) {
            unset($data['logo_scale']);
        }

        if (Chatbot::supportsFooterBranding() && array_key_exists('footer_logo_scales', $data)) {
            $scales = array_values((array) $data['footer_logo_scales']);
            $logos = $this->footerLogoList($chatbot);

            foreach ($logos as $position => $logo) {
                $logos[$position]['scale'] = $this->normalizeLogoScale($scales[$position] ?? ($logo['scale'] ?? null));
            }

            $data['footer_logos'] = array_values($logos);
        }

        unset($data['footer_logo_scales']);

        if (! Chatbot::supportsFooterBranding()) {
            unset($data['footer_text'], $data['footer_logos']);
        } elseif (array_key_exists('footer_text', $data)) {
            $footerText = trim((string) $data['footer_text']);
            $data['footer_text'] = $footerText === '' ? null : $footerText;
        }

        if (Chatbot::supportsCtaLink()) {
            $data = $this->normalizeCtaLink($data, $chatbot);
        } else {
            unset($data['cta_label'], $data['cta_url']);
        }

        if (! empty($data['regenerate_public_key']) && Chatbot::supportsPublicKey()) {
            $data['public_key'] = 'pbk_' . Str::random(48);
        }

        unset($data['regenerate_public_key']);

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

        return $this->json(['bot' => $this->serializeBot($request, $chatbot->fresh())]);
    }

    public function destroy(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);
        $this->deleteChatbotLogoFile($chatbot);
        $chatbot->delete();

        return $this->json(['ok' => true]);
    }

    public function uploadLogo(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        if (! Chatbot::supportsLogoUpload()) {
            return $this->json([
                'message' => 'Bot logo upload is not available until the latest database migration is applied.',
            ], 503);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/gif,image/webp'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'logo';
        $storedName = $safeName . '-' . Str::random(8) . '.' . $extension;
        $path = $file->storeAs("bot-logos/{$chatbot->user_id}/{$chatbot->id}", $storedName, 'public');
        $oldPath = trim((string) $chatbot->logo_path);

        try {
            DB::transaction(function () use ($chatbot, $path, $file) {
                $chatbot->update([
                    'logo_path' => $path,
                    'logo_original_name' => $file->getClientOriginalName(),
                ]);
            });
        } catch (\Throwable $e) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        if ($oldPath !== '' && $oldPath !== $path) {
            $this->deletePublicFile($oldPath);
        }

        return $this->json(['bot' => $this->serializeBot($request, $chatbot->fresh())]);
    }

    public function deleteLogo(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        if (! Chatbot::supportsLogoUpload()) {
            return $this->json([
                'message' => 'Bot logo upload is not available until the latest database migration is applied.',
            ], 503);
        }

        $oldPath = trim((string) $chatbot->logo_path);

        DB::transaction(function () use ($chatbot) {
            $chatbot->update([
                'logo_path' => null,
                'logo_original_name' => null,
            ]);
        });

        if ($oldPath !== '') {
            $this->deletePublicFile($oldPath);
        }

        return $this->json(['bot' => $this->serializeBot($request, $chatbot->fresh())]);
    }

    public function uploadFooterLogo(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        if (! Chatbot::supportsFooterBranding()) {
            return $this->json([
                'message' => 'Footer branding is not available until the latest database migration is applied.',
            ], 503);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/svg+xml'],
        ]);

        $logos = $this->footerLogoList($chatbot);

        abort_if(
            count($logos) >= self::MAX_FOOTER_LOGOS,
            422,
            'You can add up to ' . self::MAX_FOOTER_LOGOS . ' footer logos. Remove one first.',
        );

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'footer-logo';
        $storedName = $safeName . '-' . Str::random(8) . '.' . $extension;
        $path = $file->storeAs("bot-logos/{$chatbot->user_id}/{$chatbot->id}/footer", $storedName, 'public');

        $logos[] = [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
        ];

        try {
            $chatbot->update(['footer_logos' => array_values($logos)]);
        } catch (\Throwable $e) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        return $this->json(['bot' => $this->serializeBot($request, $chatbot->fresh())]);
    }

    public function deleteFooterLogo(Request $request, Chatbot $chatbot, string $index): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        if (! Chatbot::supportsFooterBranding()) {
            return $this->json([
                'message' => 'Footer branding is not available until the latest database migration is applied.',
            ], 503);
        }

        $logos = $this->footerLogoList($chatbot);
        $position = (int) $index;

        abort_unless(array_key_exists($position, $logos), 404, 'Footer logo not found.');

        $removed = $logos[$position];
        unset($logos[$position]);

        $chatbot->update(['footer_logos' => array_values($logos)]);
        $this->deletePublicFile((string) ($removed['path'] ?? ''));

        return $this->json(['bot' => $this->serializeBot($request, $chatbot->fresh())]);
    }

    private function normalizeLogoScale(mixed $value): int
    {
        $scale = (int) ($value ?? 100);

        return max(50, min(200, $scale ?: 100));
    }

    /**
     * Stored footer logos, tolerating rows written before this feature or hand-edited JSON.
     */
    private function footerLogoList(Chatbot $chatbot): array
    {
        if (! Chatbot::supportsFooterBranding()) {
            return [];
        }

        $logos = $chatbot->footer_logos;

        if (! is_array($logos)) {
            return [];
        }

        return array_values(array_filter(
            $logos,
            static fn ($logo) => is_array($logo) && trim((string) ($logo['path'] ?? '')) !== '',
        ));
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

        $body = $this->sanitizeUtf8($response->body());
        $title = Str::of($body)->match('/<title[^>]*>(.*?)<\/title>/is')->trim()->limit(200);
        $text = $this->normalizeImportedText(strip_tags($body));
        abort_unless(strlen($text) >= 20, 422, 'No extractable text found.');

        return $this->json($this->storeKnowledge(
            $chatbot,
            'url',
            $this->sanitizeUtf8((string) ($title ?: $data['url'])),
            $text,
            $this->sanitizeUtf8($data['url']),
        ));
    }

    public function ingestFile(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorizeOwner($request, $chatbot);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());

        abort_unless(in_array($extension, ['pdf', 'docx', 'txt', 'md', 'json'], true), 422, 'Unsupported file type.');

        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'document';
        $storedName = $safeName . '-' . Str::random(8) . '.' . $extension;
        $storagePath = $file->storeAs("knowledge/{$chatbot->user_id}/{$chatbot->id}", $storedName);
        $absolutePath = Storage::path($storagePath);

        // JSON is split record-by-record so a chunk never straddles two records.
        $segments = $extension === 'json' ? $this->jsonSegments($absolutePath) : null;
        $text = $segments !== null
            ? implode("\n\n", $segments)
            : $this->extractFileText($absolutePath, $extension);
        abort_unless(strlen(trim($text)) >= 20, 422, 'No extractable text found.');

        return $this->json($this->storeKnowledge(
            $chatbot,
            'file',
            $file->getClientOriginalName(),
            $text,
            null,
            $storagePath,
            $file->getSize(),
            $segments,
        ));
    }

    public function destroySource(Request $request, KnowledgeSource $source): JsonResponse
    {
        $this->authorizeUserResource($request, $source->user_id);
        $source->delete();

        return $this->json(['ok' => true]);
    }

    public function downloadSourceChunks(Request $request, KnowledgeSource $source): Response
    {
        $this->authorizeUserResource($request, $source->user_id);

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
        $this->authorizeUserResource($request, $conversation->user_id);

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
        $this->authorizeUserResource($request, $chatbot->user_id);
    }

    private function authorizeUserResource(Request $request, string $ownerId): void
    {
        $user = $request->attributes->get('auth_user');

        abort_unless($user && ($ownerId === $user->id || $this->isAdmin($request)), 404);
    }

    private function visibleBotsQuery(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        return Chatbot::query()
            ->when(! $this->isAdmin($request), fn ($query) => $query->where('user_id', $user->id));
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('auth_user');

        return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
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

    /**
     * Clean up the widget's call-to-action link. Only http(s) is allowed — the widget renders
     * this as an anchor on a third-party page, so a javascript:/data: URL must never be stored.
     */
    private function normalizeCtaLink(array $data, Chatbot $chatbot): array
    {
        if (! array_key_exists('cta_url', $data) && ! array_key_exists('cta_label', $data)) {
            return $data;
        }

        $url = trim((string) ($data['cta_url'] ?? $chatbot->cta_url ?? ''));
        $label = trim((string) ($data['cta_label'] ?? $chatbot->cta_label ?? ''));

        if ($url === '') {
            $data['cta_url'] = null;
            $data['cta_label'] = null;

            return $data;
        }

        if (! preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        abort_unless(
            in_array($scheme, ['http', 'https'], true) && ! empty($parts['host']),
            422,
            'Enter a valid link starting with http:// or https://',
        );

        $data['cta_url'] = $url;
        $data['cta_label'] = $label !== '' ? $label : Str::limit((string) $parts['host'], 37);

        return $data;
    }

    private function storeKnowledge(
        Chatbot $chatbot,
        string $type,
        string $name,
        string $text,
        ?string $url = null,
        ?string $storagePath = null,
        ?int $sizeBytes = null,
        ?array $segments = null,
    ): array
    {
        $name = $this->sanitizeUtf8($name);
        $text = $this->normalizeImportedText($text);
        $url = $url !== null ? $this->sanitizeUtf8($url) : null;
        $storagePath = $storagePath !== null ? $this->sanitizeUtf8($storagePath) : null;

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
            $chunks = $segments !== null ? $this->chunkSegments($segments) : $this->chunkText($text);
            abort_if(count($chunks) === 0, 422, 'No extractable text found.');

            foreach ($chunks as $index => $chunk) {
                $content = $this->sanitizeUtf8($chunk);
                $embedding = OllamaEmbeddings::embed($content);

                DocumentChunk::create([
                    'source_id' => $source->id,
                    'chatbot_id' => $chatbot->id,
                    'user_id' => $chatbot->user_id,
                    'content' => $content,
                    'chunk_index' => $index,
                    'embedding' => $embedding ? OllamaEmbeddings::toPgVector($embedding) : null,
                ]);
            }

            $source->update(['status' => 'ready', 'chunk_count' => count($chunks)]);

            return ['sourceId' => $source->id, 'chunks' => count($chunks)];
        } catch (\Throwable $e) {
            $source->update([
                'status' => 'error',
                'error_message' => Str::limit($this->sanitizeUtf8($e->getMessage()), 1000),
            ]);
            throw $e;
        }
    }

    private function chunkText(string $text): array
    {
        $clean = $this->normalizeImportedText($text);
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

    /**
     * Pack already-segmented records (e.g. JSON entries) into chunks without splitting a record.
     * Records larger than a chunk fall back to plain text chunking.
     */
    private function chunkSegments(array $segments, int $size = 1000): array
    {
        $chunks = [];
        $buffer = '';

        foreach ($segments as $segment) {
            $segment = trim($this->sanitizeUtf8(is_string($segment) ? $segment : (string) json_encode($segment)));

            if ($segment === '') {
                continue;
            }

            if (strlen($segment) > $size) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                foreach ($this->chunkText($segment) as $part) {
                    $chunks[] = $part;
                }

                continue;
            }

            if ($buffer !== '' && strlen($buffer) + strlen($segment) + 2 > $size) {
                $chunks[] = $buffer;
                $buffer = '';
            }

            $buffer = $buffer === '' ? $segment : $buffer . "\n\n" . $segment;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_values(array_filter($chunks, static fn (string $chunk) => strlen($chunk) > 20));
    }

    /**
     * Turn a JSON (or JSON Lines) file into readable "key: value" records, one segment per record.
     */
    private function jsonSegments(string $path): array
    {
        $raw = file_get_contents($path);
        abort_if($raw === false, 422, 'Could not read JSON file.');

        $raw = ltrim($this->sanitizeUtf8($raw), "\u{FEFF}");
        abort_if(trim($raw) === '', 422, 'No extractable text found.');

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $this->decodeJsonLines($raw);
            abort_if($decoded === null, 422, 'Invalid JSON file.');
        }

        $segments = [];
        $this->collectJsonSegments($decoded, '', $segments);

        abort_if($segments === [], 422, 'No extractable text found.');

        return $segments;
    }

    /**
     * Support newline-delimited JSON (one object per line) uploaded with a .json extension.
     */
    private function decodeJsonLines(string $raw): ?array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            $rows[] = $decoded;
        }

        return $rows === [] ? null : $rows;
    }

    private function collectJsonSegments(mixed $value, string $path, array &$segments, int $depth = 0): void
    {
        if (count($segments) >= self::JSON_MAX_SEGMENTS) {
            return;
        }

        if (is_array($value) && $value !== [] && $depth < self::JSON_SPLIT_MAX_DEPTH) {
            if (array_is_list($value)) {
                if ($this->isJsonRecordList($value)) {
                    foreach ($value as $index => $item) {
                        $this->collectJsonSegments($item, $path . '[' . $index . ']', $segments, $depth + 1);
                    }

                    return;
                }
            } else {
                $inline = [];
                $groups = [];

                foreach ($value as $key => $item) {
                    $childPath = $path === '' ? (string) $key : $path . '.' . $key;

                    if ($this->isJsonRecordList($item)) {
                        $groups[$childPath] = $item;
                    } else {
                        $inline[$key] = $item;
                    }
                }

                if ($groups !== []) {
                    if ($inline !== []) {
                        $this->pushJsonSegment($path, $inline, $segments);
                    }

                    foreach ($groups as $childPath => $item) {
                        $this->collectJsonSegments($item, $childPath, $segments, $depth + 1);
                    }

                    return;
                }
            }
        }

        $this->pushJsonSegment($path, $value, $segments);
    }

    /**
     * A list worth splitting into one segment per element (i.e. a list of records, not of scalars).
     */
    private function isJsonRecordList(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item) && $item !== []) {
                return true;
            }
        }

        return false;
    }

    private function pushJsonSegment(string $path, mixed $value, array &$segments): void
    {
        $lines = [];
        $this->flattenJsonValue($value, '', $lines);

        if ($lines === []) {
            return;
        }

        $header = $this->jsonSegmentHeader($path);
        $segments[] = trim(($header !== '' ? $header . "\n" : '') . implode("\n", $lines));
    }

    /**
     * "faqs[2].details" => "[faqs item 3 > details]" so a chunk carries its own context.
     */
    private function jsonSegmentHeader(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $label = preg_replace_callback(
            '/\[(\d+)\]/',
            static fn (array $m) => ' item ' . ((int) $m[1] + 1),
            $path,
        ) ?? $path;

        $label = str_replace(['_', '-', '.'], [' ', ' ', ' > '], $label);
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);

        return $label === '' ? '' : '[' . $label . ']';
    }

    private function flattenJsonValue(mixed $value, string $prefix, array &$lines, int $depth = 0): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (count($lines) >= self::JSON_MAX_LINES_PER_SEGMENT) {
            return;
        }

        if (is_array($value)) {
            if ($depth >= self::JSON_MAX_DEPTH) {
                $this->pushJsonLine($prefix, (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $lines);

                return;
            }

            if (array_is_list($value)) {
                $scalars = array_filter($value, static fn ($item) => ! is_array($item));

                if (count($scalars) === count($value)) {
                    $this->pushJsonLine(
                        $prefix,
                        implode(', ', array_map(fn ($item) => $this->jsonScalarToString($item), $value)),
                        $lines,
                    );

                    return;
                }

                foreach ($value as $index => $item) {
                    $this->flattenJsonValue(
                        $item,
                        $prefix === '' ? 'item ' . ($index + 1) : $prefix . ' ' . ($index + 1),
                        $lines,
                        $depth + 1,
                    );
                }

                return;
            }

            foreach ($value as $key => $item) {
                $label = trim(str_replace(['_', '-'], ' ', (string) $key));
                $this->flattenJsonValue(
                    $item,
                    $prefix === '' ? $label : $prefix . ' > ' . $label,
                    $lines,
                    $depth + 1,
                );
            }

            return;
        }

        $this->pushJsonLine($prefix, $this->jsonScalarToString($value), $lines);
    }

    private function pushJsonLine(string $prefix, string $value, array &$lines): void
    {
        $value = $this->normalizeImportedText($value);

        if ($value === '') {
            return;
        }

        $lines[] = $prefix === '' ? $value : $prefix . ': ' . $value;
    }

    private function jsonScalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    private function sanitizeUtf8(?string $value): string
    {
        $value = (string) ($value ?? '');

        if ($value === '') {
            return '';
        }

        if (preg_match('//u', $value) === 1) {
            return mb_scrub($value, 'UTF-8');
        }

        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && preg_match('//u', $converted) === 1) {
            return mb_scrub($converted, 'UTF-8');
        }

        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && preg_match('//u', $converted) === 1) {
            return mb_scrub($converted, 'UTF-8');
        }

        return mb_scrub(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 'UTF-8');
    }

    private function normalizeImportedText(?string $value): string
    {
        $value = $this->sanitizeUtf8($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function extractFileText(string $path, string $extension): string
    {
        return match ($extension) {
            'txt', 'md' => file_get_contents($path) ?: '',
            'pdf' => $this->extractPdfText($path),
            'docx' => $this->extractDocxText($path),
            'json' => implode("\n\n", $this->jsonSegments($path)),
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

    private function serializeBot(Request $request, Chatbot $bot): array
    {
        $data = $bot->toArray();
        $data['logo_url'] = Chatbot::supportsLogoUpload()
            ? $this->resolvePublicFileUrl($request, $bot->logo_path)
            : null;
        $data['footer_logo_urls'] = collect($this->footerLogoList($bot))
            ->map(fn (array $logo) => [
                'url' => $this->resolvePublicFileUrl($request, (string) $logo['path']),
                'original_name' => $logo['original_name'] ?? null,
                'scale' => $this->normalizeLogoScale($logo['scale'] ?? null),
            ])
            ->all();
        $data['max_footer_logos'] = self::MAX_FOOTER_LOGOS;
        $data['owner'] = $bot->relationLoaded('user') && $bot->user
            ? [
                'id' => $bot->user->id,
                'name' => $bot->user->name,
                'email' => $bot->user->email,
            ]
            : null;

        return $data;
    }

    private function resolvePublicFileUrl(Request $request, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        $relativePath = '/storage/' . ltrim($path, '/');
        $forwardedProto = trim((string) $request->headers->get('X-Forwarded-Proto'));
        $forwardedHost = trim((string) $request->headers->get('X-Forwarded-Host'));
        $forwardedOrigin = $forwardedProto !== '' && $forwardedHost !== ''
            ? rtrim($forwardedProto . '://' . $forwardedHost, '/')
            : '';
        $fallbackOrigin = rtrim($request->getSchemeAndHttpHost(), '/');
        $appOrigin = rtrim((string) config('app.url', ''), '/');

        foreach ([$appOrigin, $forwardedOrigin, $fallbackOrigin] as $origin) {
            if ($origin !== '' && ! preg_match('/^(https?:\/\/)?(?:localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?$/i', $origin)) {
                return rtrim($origin, '/') . $relativePath;
            }
        }

        $origin = $appOrigin !== '' ? $appOrigin : $fallbackOrigin;

        return rtrim($origin, '/') . $relativePath;
    }

    private function deleteChatbotLogoFile(Chatbot $chatbot): void
    {
        $path = trim((string) $chatbot->logo_path);
        $this->deletePublicFile($path);
    }

    private function deletePublicFile(?string $path): void
    {
        $path = trim((string) $path);
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        ]);
    }
}
