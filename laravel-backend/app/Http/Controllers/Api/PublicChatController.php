<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\KnowledgeSource;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicChatController extends Controller
{
    /**
     * Serve the embeddable widget bundle consumed by third-party sites.
     */
    public function widget(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->buildWidget(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ] + $this->corsHeaders($request));
    }

    /**
     * Handle a public chat turn coming from the widget embed.
     */
    public function chat(Request $request): JsonResponse
    {
        if (! Chatbot::supportsPublicKey()) {
            return $this->json($request, [
                'error' => 'Public chat is temporarily unavailable until the latest database migration is applied.',
            ], 503);
        }

        $data = $this->validateChatPayload($request);

        $bot = $this->findActiveBotByKeys($data['publicKey'] ?? null, $data['apiKey'] ?? null);

        if (! $bot) {
            return $this->json($request, ['error' => 'Invalid or inactive public key'], 404);
        }

        if (! $this->isAllowedDomain($request, $bot)) {
            return $this->json($request, ['error' => 'This domain is not allowed for this chatbot.'], 403);
        }

        if ($limitResponse = $this->enforceRateLimit($request, $bot, $data['visitorId'] ?? null)) {
            return $limitResponse;
        }

        return $this->handleChat($request, $bot, $data, 'widget');
    }

    /**
     * Validate and normalize the public chat payload coming from the widget.
     */
    public function validateChatPayload(Request $request, bool $requirePublicKey = true): array
    {
        $data = validator($this->requestData($request), [
            'publicKey' => ['nullable', 'string'],
            'apiKey' => ['nullable', 'string'],
            'message' => ['required', 'string', 'max:4000'],
            'conversationId' => ['nullable', 'uuid'],
            'visitorId' => ['nullable', 'string', 'max:64'],
            'visitorEmail' => ['nullable', 'email', 'max:200'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
            'pageContext' => ['nullable', 'array'],
            'pageContext.pageTitle' => ['nullable', 'string', 'max:500'],
            'pageContext.pageName' => ['nullable', 'string', 'max:500'],
            'pageContext.pageUrl' => ['nullable', 'string', 'max:2000'],
            'pageContext.pageContent' => ['nullable', 'string', 'max:16000'],
            'pageContext.scrapedAt' => ['nullable', 'string', 'max:100'],
            'pageContext.pageSections' => ['nullable', 'array', 'max:20'],
            'pageContext.pageSections.*.name' => ['required_with:pageContext.pageSections', 'string', 'max:200'],
            'pageContext.pageSections.*.content' => ['required_with:pageContext.pageSections', 'string', 'max:4000'],
        ])->validate();

        if ($requirePublicKey) {
            abort_unless(! empty($data['publicKey']) || ! empty($data['apiKey']), 422, 'A public embed key is required.');
        }

        return $data;
    }

    /**
     * Persist a public conversation turn, enrich it with retrieval context, and ask the model for a reply.
     */
    public function handleChat(Request $request, Chatbot $bot, array $data, string $source = 'widget'): JsonResponse
    {
        $conversation = $this->resolveConversation($bot, $data, $source);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $bot->user_id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $pageContext = $data['pageContext'] ?? null;
        $pageContextText = $this->buildPageContextText($pageContext);
        $knowledgeContext = $this->buildKnowledgeContext(
            $bot,
            $this->buildRetrievalQuery($data['message'], $data['history'] ?? []),
            $data['message']
        );

        $reply = $this->generateReply(
            $this->buildSystemPrompt($bot, $pageContextText, $knowledgeContext),
            $this->buildChatMessages($data, $pageContext),
            $bot
        );

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $bot->user_id,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        AnalyticsEvent::create([
            'chatbot_id' => $bot->id,
            'user_id' => $bot->user_id,
            'event_type' => 'message',
            'metadata' => ['length' => strlen($data['message']), 'model' => config('services.ollama.model')],
        ]);

        return $this->json($request, [
            'reply' => $reply,
            'conversationId' => $conversation->id,
            'bot' => [
                'name' => $bot->name,
                'primaryColor' => $bot->primary_color,
                'collectEmail' => $bot->collect_email,
            ],
        ]);
    }

    /**
     * Return the public widget configuration for a chatbot.
     */
    public function bot(Request $request, string $apiKey): JsonResponse
    {
        if (! Chatbot::supportsPublicKey()) {
            return $this->json($request, [
                'error' => 'Public embed configuration is temporarily unavailable until the latest database migration is applied.',
            ], 503);
        }

        $bot = $this->findActiveBotByKeys($apiKey, $apiKey);

        if (! $bot) {
            return $this->json($request, ['error' => 'Not found'], 404);
        }

        if (! $this->isAllowedDomain($request, $bot)) {
            return $this->json($request, ['error' => 'This domain is not allowed for this chatbot.'], 403);
        }

        return $this->json($request, [
            'name' => $bot->name,
            'welcome_message' => $bot->welcome_message,
            'primary_color' => $bot->primary_color,
            'logo_url' => $this->resolveLogoUrl($apiKey, $bot->logo_path),
            'bubble_position' => $bot->bubble_position,
            'collect_email' => $bot->collect_email,
            'widget_cache_minutes' => Chatbot::supportsWidgetCacheMinutes()
                ? (int) ($bot->widget_cache_minutes ?? 10)
                : 10,
            'public_key' => $bot->public_key,
            'is_active' => $bot->is_active,
        ]);
    }

    /**
     * Stream the public logo asset for a chatbot when the requesting domain is allowed.
     */
    public function logo(Request $request, string $apiKey): Response
    {
        if (! Chatbot::supportsPublicKey()) {
            return response('Not found', 404);
        }

        $bot = $this->findActiveBotByKeys($apiKey, $apiKey);

        if (! $bot || ! $this->isAllowedDomain($request, $bot)) {
            return response('Not found', 404);
        }

        $path = trim((string) $bot->logo_path);
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return response('Not found', 404);
        }

        $mimeType = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';

        return response(Storage::disk('public')->get($path), 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Rate-limit by both IP and visitor id so embeds cannot be spammed from a single browser or network.
     */
    private function enforceRateLimit(Request $request, Chatbot $bot, ?string $visitorId = null): ?JsonResponse
    {
        if (! Chatbot::supportsPublicRateLimit()) {
            return null;
        }

        $limit = max(0, (int) ($bot->public_rate_limit_per_minute ?? 60));
        if ($limit === 0) {
            return null;
        }

        $ip = $this->resolveClientIp($request);
        $keys = [sprintf('public-chat:ip:%s:%s', $bot->id, $ip)];

        $normalizedVisitorId = $this->normalizeVisitorId($visitorId);
        if ($normalizedVisitorId) {
            $keys[] = sprintf('public-chat:visitor:%s:%s', $bot->id, $normalizedVisitorId);
        }

        foreach ($keys as $key) {
            if (! RateLimiter::tooManyAttempts($key, $limit)) {
                continue;
            }

            $retryAfter = RateLimiter::availableIn($key);

            return $this->json($request, [
                'error' => 'Rate limit reached for this chatbot. Please try again shortly.',
                'retryAfter' => $retryAfter,
            ], 429);
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }

        return null;
    }

    private function isAllowedDomain(Request $request, Chatbot $bot): bool
    {
        $allowed = collect($bot->allowed_domains ?? [])
            ->map(fn ($domain) => $this->normalizeDomain((string) $domain))
            ->filter()
            ->unique()
            ->values();

        if ($allowed->isEmpty()) {
            return false;
        }

        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $host = $origin ? $this->normalizeDomain($origin) : null;

        if (! $host) {
            return $allowed->contains('localhost') || $allowed->contains('127.0.0.1');
        }

        return $allowed->contains($host);
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

    private function resolveClientIp(Request $request): string
    {
        $forwarded = $request->headers->get('CF-Connecting-IP')
            ?: $request->headers->get('X-Forwarded-For')
            ?: $request->ip()
            ?: $request->getClientIp()
            ?: 'unknown';

        return trim(explode(',', $forwarded)[0]) ?: 'unknown';
    }

    private function normalizeVisitorId(?string $visitorId): ?string
    {
        $value = trim((string) $visitorId);
        if ($value === '') {
            return null;
        }

        return substr(preg_replace('/[^a-zA-Z0-9:_-]/', '', $value) ?: '', 0, 64) ?: null;
    }

    private function requestData(Request $request): array
    {
        $data = $request->json()->all();
        if (! empty($data)) {
            return $data;
        }

        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveLogoUrl(string $apiKey, ?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        return '/api/public/logo/' . $apiKey;
    }

    /**
     * Find an active chatbot using either its public embed key or the legacy API key.
     */
    private function findActiveBotByKeys(?string $publicKey, ?string $apiKey): ?Chatbot
    {
        return Chatbot::query()
            ->where(function ($query) use ($publicKey, $apiKey) {
                if (! empty($publicKey)) {
                    $query->orWhere('public_key', $publicKey);
                }

                if (! empty($apiKey)) {
                    $query->orWhere('api_key', $apiKey);
                }
            })
            ->where('is_active', true)
            ->first();
    }

    /**
     * Reuse an existing public conversation when possible so the widget keeps the same thread.
     */
    private function resolveConversation(Chatbot $bot, array $data, string $source): Conversation
    {
        $conversationSource = trim($source) !== '' ? $source : 'widget';

        $conversation = isset($data['conversationId'])
            ? Conversation::query()
                ->where('id', $data['conversationId'])
                ->where('chatbot_id', $bot->id)
                ->first()
            : null;

        return $conversation ?? Conversation::create([
            'chatbot_id' => $bot->id,
            'user_id' => $bot->user_id,
            'visitor_id' => $data['visitorId'] ?? 'visitor_' . Str::random(16),
            'visitor_email' => $data['visitorEmail'] ?? null,
            'source' => $conversationSource,
        ]);
    }

    /**
     * Build the system prompt by merging the bot prompt with page-aware and retrieval-aware instructions.
     */
    private function buildSystemPrompt(Chatbot $bot, string $pageContextText, string $knowledgeContext): string
    {
        $instructions = [
            $bot->system_prompt,
            'Use the KNOWLEDGE BASE CONTEXT as the primary source of truth for facts from uploaded files, pasted text, and imported URLs.',
            'Use the CURRENT PAGE CONTEXT when the user is clearly asking about the page they are viewing, its visible content, or actions they can take on it.',
            'Do not ignore relevant knowledge base context just because live page context is present.',
            "The user may refer to the live page indirectly using phrases like 'this page', 'this webpage', 'this screen', 'here', 'the current page', or 'where I am now'.",
            'When that happens, answer using the CURRENT PAGE CONTEXT first, then supplement with the knowledge base if it helps.',
            'If CURRENT PAGE CONTEXT is present, never say that you cannot see the page or that you do not know what page the user means.',
            'If the user asks to explain the current page, describe its purpose, important visible sections, and what the user can do there.',
        ];

        if ($pageContextText !== '') {
            $instructions[] = "CURRENT PAGE CONTEXT:
{$pageContextText}";
        }

        $instructions[] = $knowledgeContext !== ''
            ? "KNOWLEDGE BASE CONTEXT:
{$knowledgeContext}"
            : 'No knowledge base context matched strongly enough. Answer from the current page context when available, otherwise say when you do not know.';

        return trim(implode("

", $instructions));
    }

    /**
     * Keep the model history short, but inject a compact live-page summary when page context exists.
     */
    private function buildChatMessages(array $data, ?array $pageContext): array
    {
        $messages = collect($data['history'] ?? [])
            ->take(-10)
            ->map(fn ($message) => ['role' => $message['role'], 'content' => $message['content']]);

        $pageSummary = $this->buildPageTurnSummary($pageContext);
        if ($pageSummary !== null) {
            $messages->push($pageSummary);
        }

        return $messages
            ->push(['role' => 'user', 'content' => $data['message']])
            ->values()
            ->all();
    }

    /**
     * Add a lightweight assistant note so the model sees what page the user is currently on.
     */
    private function buildPageTurnSummary(?array $pageContext): ?array
    {
        if (! is_array($pageContext)) {
            return null;
        }

        $summary = [];

        if (! empty($pageContext['pageName'])) {
            $summary[] = 'Current page: ' . $pageContext['pageName'];
        } elseif (! empty($pageContext['pageTitle'])) {
            $summary[] = 'Current page: ' . $pageContext['pageTitle'];
        }

        if (! empty($pageContext['pageSections']) && is_array($pageContext['pageSections'])) {
            $sections = collect($pageContext['pageSections'])
                ->take(6)
                ->map(fn ($section) => ($section['name'] ?? 'Section') . ': ' . ($section['content'] ?? ''))
                ->implode(' | ');

            if ($sections !== '') {
                $summary[] = 'Visible sections: ' . $sections;
            }
        }

        if ($summary === []) {
            return null;
        }

        return [
            'role' => 'assistant',
            'content' => '[Live page context for this turn] ' . implode(' || ', $summary),
        ];
    }

    private function buildRetrievalQuery(string $message, array $history = []): string
    {
        $parts = [];

        $messageTerms = $this->extractKnowledgeTerms($message);
        if ($messageTerms !== []) {
            $parts[] = implode(' ', $messageTerms);
        } else {
            $parts[] = trim($message);
        }

        $recentUserTurns = collect($history)
            ->filter(fn ($item) => ($item['role'] ?? null) === 'user')
            ->pluck('content')
            ->filter(fn ($content) => is_string($content) && trim($content) !== '')
            ->take(-2)
            ->values()
            ->all();

        foreach ($recentUserTurns as $turn) {
            $turn = implode(' ', $this->extractKnowledgeTerms((string) $turn)) ?: trim((string) $turn);
            if ($turn !== '' && ! in_array($turn, $parts, true)) {
                $parts[] = $turn;
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Format the strongest retrieved knowledge chunks into a compact model-facing context block.
     */
    private function buildKnowledgeContext(Chatbot $bot, string $retrievalQuery, string $message): string
    {
        $matches = $this->retrieveKnowledgeMatches($bot, $retrievalQuery, $message);

        if (count($matches) === 0) {
            return '';
        }

        return collect($matches)
            ->map(function (object $match, int $index) {
                $sourceName = trim((string) ($match->source_name ?? 'Untitled source'));
                $sourceType = trim((string) ($match->source_type ?? 'unknown'));
                $chunkIndex = (int) ($match->chunk_index ?? 0) + 1;
                $content = trim((string) ($match->content ?? ''));

                return sprintf(
                    "[Source %d | %s | %s | chunk %d]\n%s",
                    $index + 1,
                    $sourceType,
                    $sourceName,
                    $chunkIndex,
                    $content
                );
            })
            ->implode("\n\n---\n\n");
    }

    /**
     * Try ranked full-text retrieval first, then fall back to coarse term matching when recall is weak.
     */
    private function retrieveKnowledgeMatches(Chatbot $bot, string $retrievalQuery, string $message): array
    {
        $termQuery = implode(' ', $this->extractKnowledgeTerms($message));
        $queries = array_values(array_unique(array_filter([
            trim($retrievalQuery),
            trim($termQuery),
            trim($message),
        ])));

        $results = [];

        foreach ($queries as $query) {
            $rows = DocumentChunk::query()
                ->select([
                    'document_chunks.id',
                    'document_chunks.content',
                    'document_chunks.chunk_index',
                    'knowledge_sources.name as source_name',
                    'knowledge_sources.source_type as source_type',
                    \DB::raw("ts_rank_cd(document_chunks.tsv, websearch_to_tsquery('english', ?)) as rank"),
                ])
                ->addBinding($query, 'select')
                ->join('knowledge_sources', 'knowledge_sources.id', '=', 'document_chunks.source_id')
                ->where('document_chunks.chatbot_id', $bot->id)
                ->where('knowledge_sources.status', 'ready')
                ->whereRaw("document_chunks.tsv @@ websearch_to_tsquery('english', ?)", [$query])
                ->orderByDesc('rank')
                ->limit(6)
                ->get();

            foreach ($rows as $row) {
                $results[$row->id] = $row;
            }
        }

        if (count($results) < 4) {
            $fallbackRows = $this->fallbackKnowledgeMatches($bot, $message);

            foreach ($fallbackRows as $row) {
                $results[$row->id] = $row;
            }
        }

        return collect($results)
            ->sortByDesc(fn ($row) => (float) ($row->rank ?? 0))
            ->take(4)
            ->values()
            ->all();
    }

    private function fallbackKnowledgeMatches(Chatbot $bot, string $message): array
    {
        $terms = collect($this->extractKnowledgeTerms($message))
            ->take(6)
            ->values();

        if ($terms->isEmpty()) {
            return [];
        }

        $query = DocumentChunk::query()
            ->select([
                'document_chunks.id',
                'document_chunks.content',
                'document_chunks.chunk_index',
                'knowledge_sources.name as source_name',
                'knowledge_sources.source_type as source_type',
                \DB::raw('0 as rank'),
            ])
            ->join('knowledge_sources', 'knowledge_sources.id', '=', 'document_chunks.source_id')
            ->where('document_chunks.chatbot_id', $bot->id)
            ->where('knowledge_sources.status', 'ready');

        $scoreParts = [];
        $bindings = [];

        $query->where(function ($where) use ($terms) {
            foreach ($terms as $index => $term) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $where->{$method}('document_chunks.content', 'ILIKE', '%' . $term . '%');
            }
        });

        foreach ($terms as $term) {
            $scoreParts[] = "CASE WHEN document_chunks.content ILIKE ? THEN 1 ELSE 0 END";
            $bindings[] = '%' . $term . '%';
        }

        if ($scoreParts !== []) {
            $query->selectRaw('(' . implode(' + ', $scoreParts) . ') as term_matches', $bindings);
        }

        return $query
            ->orderByDesc('term_matches')
            ->orderByDesc('knowledge_sources.created_at')
            ->orderBy('document_chunks.chunk_index')
            ->limit(4)
            ->get()
            ->all();
    }

    private function extractKnowledgeTerms(string $text): array
    {
        $stopwords = [
            'a', 'an', 'and', 'are', 'about', 'can', 'could', 'does', 'for', 'from', 'have',
            'how', 'i', 'into', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'please', 'should',
            'tell', 'that', 'the', 'their', 'them', 'there', 'this', 'to', 'us', 'was', 'what',
            'when', 'where', 'which', 'who', 'why', 'with', 'would', 'you', 'your',
        ];

        return collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($text)) ?: [])
            ->map(fn ($term) => trim((string) $term))
            ->filter(fn ($term) => mb_strlen($term) >= 3)
            ->reject(fn ($term) => in_array($term, $stopwords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Delegate to the appropriate LLM provider (Ollama or OpenRouter) and return the assistant's reply.
     */
    private function generateReply(string $system, array $messages, Chatbot $bot): string
    {
        $provider = $bot->llm_provider ?: config('models.llm.default_provider');
        $model    = $bot->llm_model ?: config("models.llm.{$provider}.model");

        return match ($provider) {
            'openrouter' => $this->generateOpenRouter($model, $system, $messages),
            default      => $this->generateOllama($model, $system, $messages),
        };
    }

    /**
     * Call the local Ollama instance.
     */
    private function generateOllama(string $model, string $system, array $messages): string
    {
        $url = rtrim(config('models.llm.ollama.url'), '/') . '/api/chat';

        $response = Http::timeout(120)->post($url, [
            'model'    => $model,
            'stream'   => false,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $system]],
                $messages,
            ),
        ]);

        if (! $response->ok()) {
            throw new \RuntimeException('Ollama failed: ' . $response->body());
        }

        return $response->json('message.content') ?: 'Sorry, I had trouble responding.';
    }

    /**
     * Call OpenRouter's API.
     */
    private function generateOpenRouter(string $model, string $system, array $messages): string
    {
        $apiKey = config('models.llm.openrouter.api_key') ?: config('services.openrouter.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OpenRouter API key is not configured.');
        }

        $url = rtrim(config('models.llm.openrouter.url'), '/') . '/chat/completions';

        $response = Http::timeout(120)
            ->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => config('app.url', 'http://localhost'),
            ])
            ->post($url, [
                'model'    => $model,
                'stream'   => false,
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $system]],
                    $messages,
                ),
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException('OpenRouter failed: ' . $response->body());
        }

        return $response->json('choices.0.message.content') ?: 'Sorry, I had trouble responding.';
    }

    /**
     * Return the self-contained public widget script that is embedded on external websites.
     */
    private function buildWidget(): string
    {
        $script = <<<'JS'
(function(){
  var s = document.currentScript;
  var publicKey = s && (s.getAttribute('data-public-key') || s.getAttribute('data-api-key'));
  if (!publicKey) { console.error('[Helix] data-public-key required'); return; }
  var explicitOrigin = s && (s.getAttribute('data-origin') || s.getAttribute('data-helix-origin'));
  var explicitCacheMinutes = s && (s.getAttribute('data-cache-minutes') || s.getAttribute('data-session-cache-minutes') || s.getAttribute('data-conversation-cache-minutes'));
  var ORIGIN = explicitOrigin || (s && s.src ? new URL(s.src, window.location.href).origin : window.location.origin);
  while (ORIGIN.length > 1 && ORIGIN.charAt(ORIGIN.length - 1) === '/') ORIGIN = ORIGIN.slice(0, -1);
  var STORE_KEY = 'helix_visitor_' + publicKey;
  var SESSION_STORE_KEY = STORE_KEY + '_session';
  var visitorId = localStorage.getItem(STORE_KEY);
  if (!visitorId) { visitorId = 'v_' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem(STORE_KEY, visitorId); }
  var DEFAULT_CACHE_MINUTES = 10;
  var EMBED_CACHE_MINUTES = parseCacheMinutes(explicitCacheMinutes);

  var closeTimer = null;
  var state = { open: false, closing: false, conversationId: null, messages: [], sending: false, bot: null, pageContext: null, lastPageSignature: '', pageReadLabel: 'Scanning page...', draftMessage: '', draftEmail: '', activeField: null, headerCompact: false, panelAnimatedIn: false };
  restoreSession();

  fetch(ORIGIN + '/api/public/bot/' + publicKey).then(function(r){return r.json();}).then(function(b){
    if (b && b.logo_url && !/^https?:\/\//i.test(b.logo_url)) {
      b.logo_url = ORIGIN + (b.logo_url.charAt(0) === '/' ? b.logo_url : '/' + b.logo_url);
    }
    if (b && b.name) {
      state.bot = b;
      if (resolveCacheMinutes() === 0) clearSession();
      else persistSession();
      render();
    }
  }).catch(function(){});

  var root = document.createElement('div');
  root.id = 'helix-widget-root';
  document.body.appendChild(root);

  var style = document.createElement('style');
  style.textContent = [
    '#helix-widget-root *{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
    '.helix-bubble{position:fixed;bottom:20px;z-index:2147483646;width:60px;height:60px;border-radius:20px;border:1px solid rgba(255,255,255,0.18);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 18px 44px rgba(15,23,42,0.28);transition:transform .24s ease,box-shadow .24s ease,border-radius .24s ease;overflow:hidden;padding:0;backdrop-filter:blur(18px)}',
    '.helix-bubble:hover{transform:translateY(-2px) scale(1.03)}',
    '.helix-bubble.is-active{transform:scale(.96);border-radius:18px;box-shadow:0 24px 48px rgba(15,23,42,0.32)}',
    '.helix-bubble img{width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit;background:transparent}',
    '.helix-bubble svg{width:26px;height:26px}',
    '.helix-panel{position:fixed;bottom:96px;z-index:2147483647;width:390px;max-width:calc(100vw - 24px);height:620px;max-height:calc(100vh - 124px);background:#f8fafc;border-radius:28px;box-shadow:0 34px 80px rgba(15,23,42,0.30);display:flex;flex-direction:column;overflow:hidden;font-size:14px;color:#0f172a;border:1px solid rgba(148,163,184,0.18);transform-origin:calc(100% - 28px) calc(100% - 20px)}',
    '.helix-panel[data-side="right"]{right:20px}',
    '.helix-panel[data-side="left"]{left:20px}',
    '.helix-panel.is-opening{animation:helixPanelIn .34s cubic-bezier(.22,1,.36,1) both}',
    '.helix-panel.is-closing{pointer-events:none;animation:helixPanelOut .24s cubic-bezier(.4,0,1,1) both}',
    '.helix-panel.is-compact .helix-header{padding:8px 12px 5px;gap:4px}',
    '.helix-panel.is-compact .helix-header-copy,.helix-panel.is-compact .helix-page-status{opacity:0;max-height:0;transform:translateY(-12px);pointer-events:none;margin:0;padding-top:0;padding-bottom:0;overflow:hidden}',
    '.helix-panel.is-compact .helix-brand{align-items:center}',
    '.helix-panel.is-compact .helix-brand-logo{width:40px;height:40px;border-radius:11px}',
    '.helix-panel.is-compact .helix-brand-copy{padding-top:0}',
    '.helix-panel.is-compact .helix-brand-label{margin-bottom:1px;font-size:12px}',
    '.helix-panel.is-compact .helix-brand-name{font-size:20px}',
    '.helix-header{position:relative;padding:10px 12px 12px;color:#fff;display:flex;flex-direction:column;gap:8px;overflow:hidden;transition:padding .22s ease,gap .22s ease}',
    '.helix-header::before{content:"";position:absolute;inset:-18% auto auto -16%;width:180px;height:180px;border-radius:999px;background:rgba(255,255,255,0.20);filter:blur(10px)}',
    '.helix-header::after{content:"";position:absolute;right:-56px;top:22px;width:180px;height:180px;border-radius:999px;background:rgba(255,255,255,0.14);filter:blur(24px)}',
    '.helix-header-top,.helix-header-copy,.helix-page-status,.helix-header-actions{position:relative;z-index:1}',
    '.helix-header-top{display:flex;align-items:center;justify-content:space-between;gap:8px}',
    '.helix-brand{display:flex;align-items:center;gap:8px;min-width:0;flex:1}',
    '.helix-brand-logo{width:36px;height:36px;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.12);box-shadow:inset 0 0 0 1px rgba(255,255,255,0.12);flex-shrink:0}',
    '.helix-brand-logo img{width:100%;height:100%;object-fit:cover;display:block}',
    '.helix-brand-fallback{font-size:14px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}',
    '.helix-brand-copy{min-width:0;padding-top:0;display:flex;flex-direction:column;justify-content:center}',
    '.helix-brand-label{font-size:9px;letter-spacing:.09em;text-transform:uppercase;opacity:.72;margin-bottom:1px;line-height:1.1}',
    '.helix-brand-name{font-size:16px;font-weight:700;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}',
    '.helix-close{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.12);border:none;color:#fff;cursor:pointer;font-size:22px;font-weight:400;line-height:1;padding:0 0 2px 0;border-radius:999px;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.14);backdrop-filter:blur(6px);flex-shrink:0;transition:background .18s ease,transform .18s ease}',
    '.helix-close:hover{background:rgba(255,255,255,0.18);transform:translateY(-1px)}',
    '.helix-header-copy{display:flex;flex-direction:column;gap:6px;max-height:220px;opacity:1;transform:translateY(0);transform-origin:top left;transition:opacity .24s ease,transform .24s ease,max-height .28s ease}',
    '.helix-eyebrow{font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;opacity:.78}',
    '.helix-headline{font-size:24px;line-height:1.02;font-weight:800;letter-spacing:-0.03em;max-width:240px}',
    '.helix-subcopy{font-size:13px;line-height:1.55;max-width:250px;color:rgba(255,255,255,0.86)}',
    '.helix-page-status{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;max-width:100%;padding:10px 14px;border-radius:16px;background:rgba(255,255,255,0.92);color:#334155;font-size:12px;font-weight:600;box-shadow:0 12px 24px rgba(15,23,42,0.12);max-height:56px;opacity:1;transform:translateY(0);transition:opacity .24s ease,transform .24s ease,max-height .28s ease,padding .24s ease,margin .24s ease}',
    '.helix-page-status::before{content:"";width:8px;height:8px;border-radius:999px;background:currentColor;opacity:.65;flex-shrink:0}',
    '.helix-shell{flex:1;display:flex;flex-direction:column;min-height:0;background:linear-gradient(180deg,rgba(248,250,252,0.92) 0%,#ffffff 22%,#ffffff 100%)}',
    '.helix-body{flex:1;overflow-y:auto;padding:16px 16px 12px;background:transparent;display:flex;flex-direction:column;gap:10px}',
    '.helix-msg{max-width:86%;padding:13px 16px;border-radius:18px;line-height:1.6;word-wrap:break-word;text-align:left;font-size:13.5px}',
    '.helix-msg.bot{background:rgba(255,255,255,0.98);border:1px solid rgba(226,232,240,0.95);align-self:flex-start;border-top-left-radius:8px;box-shadow:0 12px 30px rgba(15,23,42,0.06)}',
    '.helix-msg.user{color:#fff;align-self:flex-end;border-top-right-radius:8px;box-shadow:0 12px 30px rgba(15,23,42,0.14)}',
    '.helix-msg p{margin:0 0 0.6em;line-height:1.65}',
    '.helix-msg p:last-child{margin-bottom:0}',
    '.helix-msg ul{margin:0.4em 0 0.75em 1.1em;padding:0;list-style:disc}',
    '.helix-msg ul li{margin:0.25em 0;line-height:1.5}',
    '.helix-msg strong{font-weight:700;color:#1e293b}',
    '.helix-msg a{color:#3b82f6;text-decoration:underline;text-underline-offset:2px}',
    '.helix-msg code{background:#f1f5f9;padding:0.15em 0.4em;border-radius:4px;font-size:0.9em;font-family:monospace}',
    '.helix-msg .helix-num{color:#3b82f6;font-weight:700;margin-right:0.3em}',
    '.helix-typing{display:flex;gap:4px;padding:14px}',
    '.helix-typing-wrapper{transition:opacity 0.25s ease,transform 0.25s ease}',
    '.helix-typing span{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:helixBounce 1.2s infinite}',
    '.helix-typing span:nth-child(2){animation-delay:.15s}.helix-typing span:nth-child(3){animation-delay:.3s}',
    '@keyframes helixBounce{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-4px)}}',
    '@keyframes helixPanelIn{0%{opacity:0;transform:translateY(24px) scale(.94)}100%{opacity:1;transform:translateY(0) scale(1)}}',
    '@keyframes helixPanelOut{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(18px) scale(.96)}}',
    '.helix-composer{padding:0 14px 14px;background:transparent}',
    '.helix-composer-card{border-radius:22px;background:rgba(255,255,255,0.96);border:1px solid rgba(226,232,240,0.95);box-shadow:0 18px 36px rgba(15,23,42,0.10);overflow:hidden}',
    '.helix-email{padding:12px 12px 0;background:transparent;border-top:none}',
    '.helix-email input{width:100%;border:1px solid #e2e8f0;border-radius:14px;padding:11px 13px;font-size:13px;outline:none;background:#fff;color:#0f172a;transition:border-color .2s ease,box-shadow .2s ease}',
    '.helix-email input:focus{border-color:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,0.12)}',
    '.helix-form{display:flex;gap:10px;padding:12px;background:transparent}',
    '.helix-input{flex:1;border:1px solid #e2e8f0;border-radius:16px;padding:12px 14px;font-size:14px;outline:none;color:#0f172a;background:#fff;transition:border-color .2s ease,box-shadow .2s ease}',
    '.helix-input:focus{border-color:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,0.12)}',
    '.helix-send{border:none;color:#fff;border-radius:16px;padding:0 18px;cursor:pointer;font-weight:700;min-width:88px;box-shadow:0 14px 24px rgba(15,23,42,0.16);transition:transform .2s ease,box-shadow .2s ease}',
    '.helix-send:hover{transform:translateY(-1px);box-shadow:0 18px 28px rgba(15,23,42,0.18)}',
    '.helix-send:disabled{opacity:.5;cursor:not-allowed}',
    '.helix-foot{text-align:center;padding:0 0 14px;font-size:11px;color:#94a3b8;background:transparent;border-top:none}',
    '.helix-foot a{color:#64748b;text-decoration:none}',
    ' (max-width:640px){.helix-panel{bottom:86px;width:min(390px,calc(100vw - 16px));max-width:calc(100vw - 16px);height:min(620px,calc(100vh - 100px));border-radius:24px}.helix-header{padding:9px 11px 11px}.helix-headline{font-size:22px;max-width:100%}.helix-brand-name{max-width:170px}.helix-composer{padding:0 10px 10px}.helix-body{padding:14px 12px 10px}.helix-page-status{max-width:100%}.helix-panel.is-compact .helix-header{padding:8px 10px 9px}}',
  ].join('');
  root.appendChild(style);

  function openWidget(){
    if (closeTimer) {
      clearTimeout(closeTimer);
      closeTimer = null;
    }
    if (state.open && !state.closing) return;
    state.closing = false;
    state.headerCompact = false;
    state.panelAnimatedIn = false;
    state.open = true;
    if (state.messages.length === 0) state.messages.push({ role: 'assistant', content: state.bot.welcome_message });
    persistSession();
    render();
  }

  function closeWidget(){
    if ((!state.open && !state.closing) || closeTimer) return;
    state.closing = true;
    render();
    closeTimer = setTimeout(function(){
      state.open = false;
      state.closing = false;
      closeTimer = null;
      persistSession();
      render();
    }, 240);
  }

  function normalizeText(value, maxLen){
    var text = String(value || '').replace(/\s+/g, ' ').trim();
    if (!maxLen) return text;
    return text.length > maxLen ? text.substring(0, maxLen) : text;
  }

  function dedupeTexts(items, limit){
    var seen = {};
    var out = [];
    for (var i = 0; i < items.length; i++) {
      var value = normalizeText(items[i], 240);
      var key = value.toLowerCase();
      if (!value || seen[key]) continue;
      seen[key] = true;
      out.push(value);
      if (out.length >= limit) break;
    }
    return out;
  }

  function collectTexts(selectors, limit){
    var values = [];
    for (var i = 0; i < selectors.length; i++) {
      var nodes = document.querySelectorAll(selectors[i]);
      for (var j = 0; j < nodes.length; j++) {
        var el = nodes[j];
        if (!el || (el.closest && el.closest('#helix-widget-root'))) continue;
        var text = normalizeText(el.innerText || el.textContent || '', 240);
        if (text) values.push(text);
      }
    }
    return dedupeTexts(values, limit);
  }

  function extractMainText(){
    try {
      var source = document.querySelector('main, [role="main"], .main-content, .content, #root main') || document.body;
      if (!source) return '';
      var clone = source.cloneNode(true);
      var remove = clone.querySelectorAll ? clone.querySelectorAll('script,style,noscript,iframe,svg,canvas,#helix-widget-root') : [];
      for (var i = 0; i < remove.length; i++) remove[i].remove();
      return normalizeText(clone.innerText || clone.textContent || '', 12000);
    } catch (e) {
      return '';
    }
  }

  function buildSections(){
    var sections = [];
    var h1 = normalizeText((document.querySelector('h1') || {}).innerText || '', 180);
    var subtitle = normalizeText((document.querySelector('h2, p') || {}).innerText || '', 280);
    var navItems = collectTexts(['aside a', 'nav a', '[role="navigation"] a', 'aside button'], 12);
    var headings = collectTexts(['main h1', 'main h2', 'main h3', '[role="main"] h1', '[role="main"] h2', '[role="main"] h3'], 12);
    var buttons = collectTexts(['main button', '[role="main"] button', 'main [role="button"]'], 10);
    var listItems = collectTexts(['main li', '[role="main"] li', 'table tr'], 12);
    var cards = collectTexts(['main article', 'main section', 'main [class*="card"]', '[role="main"] [class*="card"]'], 8);

    if (h1) sections.push({ name: 'Primary heading', content: h1 });
    if (subtitle && subtitle !== h1) sections.push({ name: 'Page summary', content: subtitle });
    if (navItems.length) sections.push({ name: 'Navigation', content: navItems.join(' | ') });
    if (headings.length) sections.push({ name: 'Visible sections', content: headings.join(' | ') });
    if (buttons.length) sections.push({ name: 'Actions', content: buttons.join(' | ') });
    if (listItems.length) sections.push({ name: 'Rows and items', content: listItems.join(' | ') });
    if (cards.length) sections.push({ name: 'Cards and panels', content: cards.join(' | ') });

    return sections.slice(0, 12);
  }

  function collectPageLinks(){
    try{
      var seen = {};
      var links = [];
      var nodes = document.querySelectorAll ? document.querySelectorAll('a[href]') : [];
      for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        if (!el || !el.href || (el.closest && el.closest('#helix-widget-root'))) continue;
        var href = String(el.getAttribute('href') || '').trim();
        if (!href || href.indexOf('#') === 0 || href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) continue;
        var label = normalizeText(el.innerText || el.textContent || '', 80);
        if (!label || label.length < 2) continue;
        var key = label.toLowerCase();
        if (seen[key]) continue;
        seen[key] = true;
        links.push({ label: label, url: el.href });
        if (links.length >= 40) break;
      }
      return links;
    }catch(e){ return []; }
  }

  function getPageContext(){
    try{
      var pageName = normalizeText((document.querySelector('h1') || {}).innerText || document.title || '', 180);
      var pageSections = buildSections();
      var bodyText = extractMainText();
      return {
        pageTitle: normalizeText(document.title || '', 300),
        pageName: pageName,
        pageUrl: window.location.href,
        pageLinks: collectPageLinks(),
        pageSections: pageSections,
        pageContent: bodyText,
        scrapedAt: new Date().toISOString()
      };
    }catch(e){ return { pageTitle:'', pageName:'', pageUrl:window.location.href, pageLinks:[], pageContent:'', pageSections:[], scrapedAt:new Date().toISOString() }; }
  }

  function buildPageSignature(ctx){
    return JSON.stringify([
      ctx.pageUrl || '',
      ctx.pageTitle || '',
      ctx.pageName || '',
      ((ctx.pageLinks || []).map(function(link){ return [link.label || '', link.url || ''].join('='); }).join('|')).substring(0, 600),
      (ctx.pageContent || '').substring(0, 1600)
    ]);
  }

  function refreshPageContext(force){
    var next = getPageContext();
    var signature = buildPageSignature(next);
    if (!force && signature === state.lastPageSignature) return;
    state.pageContext = next;
    state.lastPageSignature = signature;
    state.pageReadLabel = next.pageName ? 'Reading: ' + next.pageName : 'Reading current page';
    if (state.open) {
      var statusEl = root.querySelector('.helix-page-status');
      if (statusEl) statusEl.textContent = state.pageReadLabel || 'Reading current page';
      else render();
    }
  }

  var pageRefreshTimer = null;
  function schedulePageContextRefresh(delay){
    if (pageRefreshTimer) clearTimeout(pageRefreshTimer);
    pageRefreshTimer = setTimeout(function(){ refreshPageContext(false); }, typeof delay === 'number' ? delay : 400);
  }

  function watchPageChanges(){
    refreshPageContext(true);

    var originalPushState = history.pushState;
    var originalReplaceState = history.replaceState;

    history.pushState = function(){
      var result = originalPushState.apply(history, arguments);
      schedulePageContextRefresh(250);
      return result;
    };

    history.replaceState = function(){
      var result = originalReplaceState.apply(history, arguments);
      schedulePageContextRefresh(250);
      return result;
    };

    window.addEventListener('popstate', function(){ schedulePageContextRefresh(250); });
    window.addEventListener('hashchange', function(){ schedulePageContextRefresh(250); });
    window.addEventListener('load', function(){ schedulePageContextRefresh(250); });

    if (window.MutationObserver && document.body) {
      var observer = new MutationObserver(function(mutations){
        for (var i = 0; i < mutations.length; i++) {
          var target = mutations[i].target;
          if (target && target.nodeType === 1 && target.closest && target.closest('#helix-widget-root')) continue;
          if (target && target.nodeType === 3 && target.parentElement && target.parentElement.closest && target.parentElement.closest('#helix-widget-root')) continue;
          schedulePageContextRefresh(700);
          return;
        }
      });
      observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }
  }

  function parseCacheMinutes(value){
    var num = parseInt(String(value == null ? '' : value), 10);
    if (!isFinite(num) || isNaN(num)) return null;
    return Math.max(0, Math.min(10080, num));
  }

  function resolveCacheMinutes(){
    var botMinutes = state.bot && typeof state.bot.widget_cache_minutes !== 'undefined'
      ? parseCacheMinutes(state.bot.widget_cache_minutes)
      : null;
    if (EMBED_CACHE_MINUTES !== null) return EMBED_CACHE_MINUTES;
    if (botMinutes !== null) return botMinutes;
    return DEFAULT_CACHE_MINUTES;
  }

  function clearSession(){
    localStorage.removeItem(SESSION_STORE_KEY);
    state.conversationId = null;
    state.messages = [];
    state.draftMessage = '';
  }

  function persistSession(){
    var cacheMinutes = resolveCacheMinutes();
    if (!cacheMinutes) {
      localStorage.removeItem(SESSION_STORE_KEY);
      return;
    }
    var expiresAt = Date.now() + (cacheMinutes * 60 * 1000);
    localStorage.setItem(SESSION_STORE_KEY, JSON.stringify({
      conversationId: state.conversationId,
      messages: state.messages,
      draftMessage: state.draftMessage,
      draftEmail: state.draftEmail,
      open: !!(state.open || state.closing),
      expiresAt: expiresAt
    }));
  }

  function restoreSession(){
    var raw = localStorage.getItem(SESSION_STORE_KEY);
    if (!raw) return;
    try {
      var saved = JSON.parse(raw);
      if (!saved || !saved.expiresAt || saved.expiresAt <= Date.now()) {
        localStorage.removeItem(SESSION_STORE_KEY);
        return;
      }
      state.conversationId = saved.conversationId || null;
      state.messages = Array.isArray(saved.messages) ? saved.messages : [];
      state.draftMessage = typeof saved.draftMessage === 'string' ? saved.draftMessage : '';
      state.draftEmail = typeof saved.draftEmail === 'string' ? saved.draftEmail : '';
      state.open = !!saved.open;
      state.closing = false;
    } catch (e) {
      localStorage.removeItem(SESSION_STORE_KEY);
    }
  }

  function getStoredEmail(){
    return localStorage.getItem(STORE_KEY + '_email') || '';
  }

  function getDraftOrStoredEmail(){
    return (state.draftEmail || getStoredEmail() || '').trim();
  }

  function isValidEmail(email){
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
  }

  function requiresVisitorEmail(){
    return !!(state.bot && state.bot.collect_email);
  }

  function shouldHideEmailField(){
    var email = getDraftOrStoredEmail();
    return requiresVisitorEmail()
      && isValidEmail(email)
      && (!!String(state.draftMessage || '').trim() || state.messages.length > 0);
  }

  function canSendMessage(message, email){
    return !state.sending
      && !!String(message || '').trim()
      && (!requiresVisitorEmail() || isValidEmail(email));
  }

  function persistVisitorEmail(email){
    var normalizedEmail = String(email || '').trim();
    if (!requiresVisitorEmail()) {
      state.draftEmail = normalizedEmail;
      persistSession();
      return;
    }
    localStorage.setItem(STORE_KEY + '_email', normalizedEmail);
    state.draftEmail = normalizedEmail;
    persistSession();
  }

  function clamp(value, min, max){
    return Math.max(min, Math.min(max, value));
  }

  function hexToRgb(hex){
    var value = String(hex || '').replace('#', '');
    if (!/^[0-9a-fA-F]{6}$/.test(value)) return { r: 124, g: 92, b: 255 };
    return {
      r: parseInt(value.substring(0, 2), 16),
      g: parseInt(value.substring(2, 4), 16),
      b: parseInt(value.substring(4, 6), 16)
    };
  }

  function rgbToHex(rgb){
    function part(value){
      var out = clamp(Math.round(value), 0, 255).toString(16);
      return out.length === 1 ? '0' + out : out;
    }
    return '#' + part(rgb.r) + part(rgb.g) + part(rgb.b);
  }

  function mixHex(hex, target, weight){
    var a = hexToRgb(hex);
    var b = hexToRgb(target);
    var w = clamp(weight, 0, 1);
    return rgbToHex({
      r: a.r + (b.r - a.r) * w,
      g: a.g + (b.g - a.g) * w,
      b: a.b + (b.b - a.b) * w
    });
  }

  function rgba(hex, alpha){
    var rgb = hexToRgb(hex);
    return 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',' + clamp(alpha, 0, 1) + ')';
  }

  function luminance(hex){
    var rgb = hexToRgb(hex);
    return (0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b) / 255;
  }

  function contrastText(hex){
    return luminance(hex) > 0.64 ? '#0f172a' : '#ffffff';
  }

  function deriveTheme(color){
    var primary = /^#[0-9a-fA-F]{6}$/.test(String(color || '')) ? color : '#7c5cff';
    var deep = mixHex(primary, '#0f172a', 0.34);
    var soft = mixHex(primary, '#ffffff', 0.68);
    var mist = mixHex(primary, '#ffffff', 0.86);
    return {
      primary: primary,
      primaryDeep: deep,
      primarySoft: soft,
      primaryMist: mist,
      primaryText: contrastText(primary),
      bubbleGradient: 'linear-gradient(145deg,' + mixHex(primary, '#ffffff', 0.18) + ' 0%,' + deep + ' 100%)',
      headerGradient: 'linear-gradient(160deg,' + mixHex(primary, '#ffffff', 0.28) + ' 0%,' + primary + ' 46%,' + deep + ' 100%)',
      userGradient: 'linear-gradient(135deg,' + primary + ' 0%,' + deep + ' 100%)',
      bodyGlow: 'radial-gradient(circle at top left,' + rgba(soft, 0.52) + ' 0%,rgba(255,255,255,0) 48%),linear-gradient(180deg,' + mist + ' 0%,#ffffff 24%,#ffffff 100%)'
    };
  }

  function updateCompactHeader(panel, scrolled){
    if (!panel) return;
    state.headerCompact = !!scrolled;
    if (state.headerCompact) panel.classList.add('is-compact');
    else panel.classList.remove('is-compact');
  }

  function syncComposerState(panel){
    if (!panel) return;
    var emailInput = panel.querySelector('#helix-email');
    var messageInput = panel.querySelector('#helix-input');
    var sendBtn = panel.querySelector('#helix-send');
    var email = emailInput ? emailInput.value.trim() : getDraftOrStoredEmail();
    var message = messageInput ? messageInput.value : state.draftMessage;
    if (messageInput) messageInput.disabled = !!state.sending;
    if (sendBtn) sendBtn.disabled = !canSendMessage(message, email);
  }

  function send(msg){
    var visitorEmail = getDraftOrStoredEmail();
    if (!canSendMessage(msg, visitorEmail)) return;
    state.sending = true;
    state.messages.push({ role: 'user', content: msg });
    state.draftMessage = '';
    persistSession();
    render();
    var history = state.messages.slice(-10).map(function(m){return {role:m.role,content:m.content};});
    if (!state.pageContext) refreshPageContext(true);
    var pageCtx = state.pageContext || getPageContext();
    fetch(ORIGIN + '/api/public/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body: JSON.stringify({ publicKey: publicKey, message: msg, conversationId: state.conversationId, visitorId: visitorId, visitorEmail: visitorEmail, history: history.slice(0, -1), pageContext: pageCtx })
    }).then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j};});}).then(function(res){
      state.sending = false;
      if (!res.ok) { state.messages.push({ role:'assistant', content: res.j.error || 'Sorry, something went wrong.' }); }
      else {
        state.conversationId = res.j.conversationId;
        state.messages.push({ role:'assistant', content: res.j.reply });
      }
      persistSession();
      render();
    }).catch(function(){
      state.sending = false;
      state.messages.push({ role:'assistant', content:'Network error. Please try again.' });
      persistSession();
      render();
    });
  }

  function render(){
    if (!state.bot) return;
    var color = state.bot.primary_color || '#7c5cff';
    var theme = deriveTheme(color);
    var pos = state.bot.bubble_position === 'left' ? 'left:20px' : 'right:20px';
    var logoMarkup = state.bot.logo_url
      ? '<div class="helix-brand-logo"><img src="' + escapeHtml(state.bot.logo_url) + '" alt="' + escapeHtml((state.bot.name || 'Bot') + ' logo') + '"/></div>'
      : '<div class="helix-brand-logo"><span class="helix-brand-fallback">' + escapeHtml((state.bot.name || 'B').charAt(0)) + '</span></div>';
    var collectEmail = requiresVisitorEmail();
    var hideEmailField = shouldHideEmailField();
    var emailSection = collectEmail && !hideEmailField
      ? '<div class="helix-email"><input id="helix-email" type="email" placeholder="Your email" autocomplete="email" inputmode="email" required/></div>'
      : '';
    root.innerHTML = '';
    root.appendChild(style);

    var bubble = document.createElement('button');
    bubble.className = 'helix-bubble' + ((state.open || state.closing) ? ' is-active' : '');
    bubble.setAttribute('style', pos + ';background:' + (state.bot.logo_url ? 'transparent' : theme.bubbleGradient) + ';color:' + theme.primaryText);
    bubble.setAttribute('aria-label', 'Open chat');
    bubble.innerHTML = (state.open || state.closing)
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 6L18 18M6 18L18 6"/></svg>'
      : (state.bot.logo_url
          ? '<img src="' + escapeHtml(state.bot.logo_url) + '" alt="' + escapeHtml((state.bot.name || 'Bot') + ' logo') + '"/>'
          : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>');
    bubble.onclick = function(){ if (state.open && !state.closing) closeWidget(); else openWidget(); };
    root.appendChild(bubble);

    if (!state.open && !state.closing) return;

    var panel = document.createElement('div');
    panel.className = 'helix-panel' + (state.closing ? ' is-closing' : '') + (!state.closing && !state.panelAnimatedIn ? ' is-opening' : '') + (state.headerCompact ? ' is-compact' : '');
    panel.setAttribute('style', pos);
    panel.setAttribute('data-side', state.bot.bubble_position === 'left' ? 'left' : 'right');
    panel.innerHTML =
      '<div class="helix-header" style="background:' + theme.headerGradient + '">' +
        '<div class="helix-header-top">' +
          '<div class="helix-brand">' +
            logoMarkup +
            '<div class="helix-brand-copy"><div class="helix-brand-label">AI assistant</div><div class="helix-brand-name">' + escapeHtml(state.bot.name) + '</div></div>' +
          '</div>' +
          '<button class="helix-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="helix-header-copy">' +
          '<div class="helix-eyebrow">Ask anything</div>' +
          '<div class="helix-headline">' + escapeHtml(state.bot.welcome_message || 'Hi! How can I help you today?') + '</div>' +
        '</div>' +
        '<div class="helix-page-status">' + escapeHtml(state.pageReadLabel || 'Reading current page') + '</div>' +
      '</div>' +
      '<div class="helix-shell" style="background:' + theme.bodyGlow + '">' +
        '<div class="helix-body" id="helix-body"></div>' +
        '<div class="helix-composer"><div class="helix-composer-card">' +
          emailSection +
          '<form class="helix-form" id="helix-form"><input class="helix-input" id="helix-input" placeholder="Ask anything..." autocomplete="off"/><button class="helix-send" id="helix-send" type="submit" style="background:' + theme.userGradient + ';color:' + theme.primaryText + '">Send</button></form>' +
        '</div></div>' +
        '<div class="helix-foot">Powered by <a href="' + ORIGIN + '">Helix</a></div>' +
      '</div>';
    root.appendChild(panel);
    if (!state.closing && !state.panelAnimatedIn) {
      state.panelAnimatedIn = true;
    }

    var body = panel.querySelector('#helix-body');
    state.messages.forEach(function(m){
      var div = document.createElement('div');
      div.className = 'helix-msg ' + (m.role === 'user' ? 'user' : 'bot');
      if (m.role === 'user') div.style.background = theme.userGradient;
      if (m.role === 'user') div.style.color = theme.primaryText;
      if (m.role === 'user') div.textContent = m.content;
      else div.innerHTML = renderMessageHtml(m.content);
      body.appendChild(div);
    });
    if (state.sending) {
      var t = document.createElement('div');
      t.className = 'helix-msg bot helix-typing-wrapper';
      t.innerHTML = '<div class="helix-typing"><span></span><span></span><span></span></div>';
      t.style.padding = '0';
      body.appendChild(t);
    }
    body.scrollTop = body.scrollHeight;
    body.onscroll = function(){
      updateCompactHeader(panel, body.scrollTop > 24);
    };

    panel.querySelector('.helix-close').onclick = function(){ closeWidget(); };
    var emailInput = panel.querySelector('#helix-email');
    var messageInput = panel.querySelector('#helix-input');
    messageInput.value = state.draftMessage || '';
    messageInput.oninput = function(){ state.draftMessage = messageInput.value; persistSession(); };
    messageInput.onfocus = function(){ state.activeField = 'message'; };
    messageInput.onblur = function(){ if (state.activeField === 'message') state.activeField = null; };
    if (emailInput) {
      emailInput.value = getDraftOrStoredEmail();
      emailInput.oninput = function(){ state.draftEmail = emailInput.value; persistSession(); syncComposerState(panel); };
      emailInput.onfocus = function(){ state.activeField = 'email'; };
      emailInput.onblur = function(){ if (state.activeField === 'email') state.activeField = null; };
    }
    messageInput.disabled = !!state.sending;
    panel.querySelector('#helix-form').onsubmit = function(e){
      e.preventDefault();
      var v = messageInput.value;
      var email = emailInput ? emailInput.value.trim() : getDraftOrStoredEmail();
      if (collectEmail && !isValidEmail(email)) {
        if (emailInput) emailInput.focus();
        syncComposerState(panel);
        return false;
      }
      if (collectEmail) persistVisitorEmail(email);
      messageInput.value = '';
      syncComposerState(panel);
      send(v);
      return false;
    };
    messageInput.oninput = function(){ state.draftMessage = messageInput.value; persistSession(); syncComposerState(panel); };
    persistSession();
    syncComposerState(panel);
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]); }); }
  function normalizeMessageText(content){
    return String(content || '')
      .replace(/\r\n?/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }
  function escapeRegExp(s){ return String(s || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function createAnchorHtml(url, label){
    return '<a href="' + escapeHtml(url) + '">' + label + '</a>';
  }
  function applyAutoLinkPlaceholders(text, placeholders){
    var links = (state.pageContext && state.pageContext.pageLinks) || [];
    if (!links.length || !text) return text;

    var candidates = [];
    var seen = {};
    for (var i = 0; i < links.length; i++) {
      var link = links[i] || {};
      var label = String(link.label || '').trim();
      var url = String(link.url || '').trim();
      if (!label || !url || label.length < 2) continue;
      var key = label.toLowerCase();
      if (seen[key]) continue;
      seen[key] = true;
      candidates.push({ label: label, url: url });
    }

    candidates.sort(function(a, b){ return b.label.length - a.label.length; });

    for (var j = 0; j < candidates.length; j++) {
      var item = candidates[j];
      var pattern = new RegExp('(^|[^A-Za-z0-9])(' + escapeRegExp(item.label) + ')(?=[^A-Za-z0-9]|$)', 'gi');
      text = text.replace(pattern, function(match, prefix, labelText){
        var placeholder = '\x00HTML' + placeholders.length + '\x00';
        placeholders.push(createAnchorHtml(item.url, labelText));
        return prefix + placeholder;
      });
    }

    return text;
  }
  function formatInlineMarkdown(s){
    var text = String(s || '');
    var htmlPlaceholders = [];

    text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function(_, label, url){
      var placeholder = '\x00HTML' + htmlPlaceholders.length + '\x00';
      htmlPlaceholders.push(createAnchorHtml(url, label));
      return placeholder;
    });
    text = text.replace(/https?:\/\/[^\s<>"')\]]+/g, function(url){
      var clean = url.replace(/[.,;:!?)\]]+$/, '');
      var placeholder = '\x00HTML' + htmlPlaceholders.length + '\x00';
      htmlPlaceholders.push(createAnchorHtml(clean, clean));
      return placeholder + url.slice(clean.length);
    });
    text = applyAutoLinkPlaceholders(text, htmlPlaceholders);

    var out = '';
    var index = 0;

    while (index < text.length) {
      var start = text.indexOf('**', index);
      if (start === -1) {
        out += text.slice(index).replace(/\*/g, '');
        break;
      }

      out += text.slice(index, start).replace(/\*/g, '');

      var end = text.indexOf('**', start + 2);
      if (end === -1) {
        out += text.slice(start + 2).replace(/\*/g, '');
        break;
      }

      var inner = text.slice(start + 2, end);
      out += inner ? '<strong>' + inner + '</strong>' : '';
      index = end + 2;
    }

    out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
    out = out.replace(/\x00HTML(\d+)\x00/g, function(_, idx){ return htmlPlaceholders[parseInt(idx, 10)] || ''; });

    return out;
  }

  function renderTableAsBullets(rows){
    if (rows.length < 2) return '';
    function splitCells(row){
      return row.replace(/^\||\|$/g, '').split('|').map(function(c){ return c.trim(); });
    }
    var headers = splitCells(rows[0]);
    var html = '<ul>';
    for (var r = 2; r < rows.length; r++) {
      var cells = splitCells(rows[r]);
      var parts = [];
      for (var c = 0; c < headers.length; c++) {
        var label = headers[c] ? '<strong>' + escapeHtml(headers[c]) + '</strong>' : '';
        var value = cells[c] || '';
        if (label && value) parts.push(label + ': ' + formatInlineMarkdown(value));
        else if (value) parts.push(formatInlineMarkdown(value));
      }
      html += '<li>' + parts.join(' &mdash; ') + '</li>';
    }
    html += '</ul>';
    return html;
  }
  function renderMessageHtml(content){
    var text = normalizeMessageText(content);
    var lines = text.split('\n');
    var out = '';
    var i = 0;
    while (i < lines.length) {
      var raw = lines[i];
      var t = raw.trim();
      if (!t) { i++; continue; }

      // Code blocks (```)
      if (t.indexOf('```') === 0) {
        var lang = t.slice(3).trim();
        var codeLines = [];
        i++;
        while (i < lines.length && lines[i].trim().indexOf('```') !== 0) {
          codeLines.push(lines[i]);
          i++;
        }
        i++;
        var langAttr = lang ? ' data-lang="' + escapeHtml(lang) + '"' : '';
        out += '<pre' + langAttr + '><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>';
        continue;
      }

      // Headings (# ## ###)
      var hMatch = t.match(/^(#{1,3})\s+(.+)/);
      if (hMatch) {
        var level = hMatch[1].length;
        out += '<h' + level + '>' + formatInlineMarkdown(hMatch[2]) + '</h' + level + '>';
        i++; continue;
      }

      // Blockquotes (>)
      if (t[0] === '>' && (t[1] === ' ' || t.length === 1)) {
        var qLines = [];
        while (i < lines.length && lines[i].trim()[0] === '>') {
          qLines.push(lines[i].trim().replace(/^>\s?/, ''));
          i++;
        }
        out += '<blockquote><p>' + formatInlineMarkdown(qLines.join('\n')) + '</p></blockquote>';
        continue;
      }

      // Horizontal rules (---, ***, ___)
      if (t.match(/^(-{3,}|\*{3,}|_{3,})$/)) {
        out += '<hr>';
        i++; continue;
      }

      // Tables (| col | col | with separator row)
      if (t.indexOf('|') !== -1 && i + 1 < lines.length) {
        var sep = lines[i + 1].trim();
        if (sep.match(/^\|?[\s:-]+\|[\s|:-]+\|?$/)) {
          var tableRows = [t, sep];
          i += 2;
          while (i < lines.length && lines[i].trim().indexOf('|') !== -1) {
            tableRows.push(lines[i].trim());
            i++;
          }
          out += renderTableAsBullets(tableRows);
          continue;
        }
      }

      // Ordered lists
      if (t.match(/^\d+\.\s/)) {
        out += '<ol>';
        while (i < lines.length && lines[i].trim().match(/^\d+\.\s/)) {
          var oItem = lines[i].trim().replace(/^\d+\.\s*/, '');
          out += '<li>' + formatInlineMarkdown(oItem) + '</li>';
          i++;
        }
        out += '</ol>';
        continue;
      }

      // Unordered lists
      if (t.match(/^[-*]\s/)) {
        out += '<ul>';
        while (i < lines.length && lines[i].trim().match(/^[-*]\s/)) {
          var uItem = lines[i].trim().replace(/^[-*]\s*/, '');
          out += '<li>' + formatInlineMarkdown(uItem) + '</li>';
          i++;
        }
        out += '</ul>';
        continue;
      }

      out += '<p>' + formatInlineMarkdown(t) + '</p>';
      i++;
    }
    return out || '<p></p>';
  }
  watchPageChanges();
})();
JS;

        return $script;
    }

    /**
     * Serialize browser-collected page context into a plain-text block for the model prompt.
     */
    private function buildPageContextText($pageContext): string
    {
        if (! is_array($pageContext)) {
            return '';
        }

        $lines = [];

        if (! empty($pageContext['pageTitle'])) {
            $lines[] = 'Page Title: ' . $pageContext['pageTitle'];
        }
        if (! empty($pageContext['pageName'])) {
            $lines[] = 'Current Page Name: ' . $pageContext['pageName'];
        }
        if (! empty($pageContext['pageUrl'])) {
            $lines[] = 'Page URL: ' . $pageContext['pageUrl'];
        }
        if (! empty($pageContext['scrapedAt'])) {
            $lines[] = 'Scraped At: ' . $pageContext['scrapedAt'];
        }
        if (! empty($pageContext['pageSections']) && is_array($pageContext['pageSections'])) {
            $lines[] = 'Structured Page Sections:';
            foreach ($pageContext['pageSections'] as $section) {
                $name = $section['name'] ?? 'Section';
                $content = $section['content'] ?? '';
                if ($content !== '') {
                    $lines[] = '- ' . $name . ': ' . $content;
                }
            }
        }
        if (! empty($pageContext['pageLinks']) && is_array($pageContext['pageLinks'])) {
            $lines[] = 'Visible Page Links:';
            foreach (array_slice($pageContext['pageLinks'], 0, 25) as $link) {
                $label = trim((string) ($link['label'] ?? ''));
                $url = trim((string) ($link['url'] ?? ''));
                if ($label !== '' && $url !== '') {
                    $lines[] = '- ' . $label . ': ' . $url;
                }
            }
        }
        if (! empty($pageContext['pageContent'])) {
            $lines[] = 'Page Content: ' . $pageContext['pageContent'];
        }

        return implode("\n", $lines);
    }

    private function json(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders($this->corsHeaders($request));
    }

    private function corsHeaders(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => $request->headers->get('Origin') ?: '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Vary' => 'Origin',
        ];
    }
}
