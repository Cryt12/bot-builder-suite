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
    public function widget(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->buildWidget(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ] + $this->corsHeaders($request));
    }

    public function chat(Request $request): JsonResponse
    {
        if (! Chatbot::supportsPublicKey()) {
            return $this->json($request, [
                'error' => 'Public chat is temporarily unavailable until the latest database migration is applied.',
            ], 503);
        }

        $data = $this->validateChatPayload($request);

        $bot = Chatbot::query()
            ->where(function ($query) use ($data) {
                if (! empty($data['publicKey'])) {
                    $query->orWhere('public_key', $data['publicKey']);
                }

                if (! empty($data['apiKey'])) {
                    $query->orWhere('api_key', $data['apiKey']);
                }
            })
            ->where('is_active', true)
            ->first();

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

    public function handleChat(Request $request, Chatbot $bot, array $data, string $source = 'widget'): JsonResponse
    {
        $conversationSource = trim($source) !== '' ? $source : 'widget';

        $conversation = isset($data['conversationId'])
            ? Conversation::query()
                ->where('id', $data['conversationId'])
                ->where('chatbot_id', $bot->id)
                ->first()
            : null;

        $conversation ??= Conversation::create([
            'chatbot_id' => $bot->id,
            'user_id' => $bot->user_id,
            'visitor_id' => $data['visitorId'] ?? 'visitor_' . Str::random(16),
            'visitor_email' => $data['visitorEmail'] ?? null,
            'source' => $conversationSource,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $bot->user_id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $retrievalQuery = $this->buildRetrievalQuery($data['message'], $data['history'] ?? []);
        $context = $this->buildKnowledgeContext($bot, $retrievalQuery, $data['message']);

        $pageContext = $data['pageContext'] ?? null;
        $pageContextText = $this->buildPageContextText($pageContext);

        $system = trim($bot->system_prompt . "\n\n"
            . "Use the KNOWLEDGE BASE CONTEXT as the primary source of truth for facts from uploaded files, pasted text, and imported URLs.\n"
            . "Use the CURRENT PAGE CONTEXT when the user is clearly asking about the page they are viewing, its visible content, or actions they can take on it.\n"
            . "Do not ignore relevant knowledge base context just because live page context is present.\n"
            . "The user may refer to the live page indirectly using phrases like 'this page', 'this webpage', 'this screen', 'here', 'the current page', or 'where I am now'.\n"
            . "When that happens, answer using the CURRENT PAGE CONTEXT first, then supplement with the knowledge base if it helps.\n"
            . "If CURRENT PAGE CONTEXT is present, never say that you cannot see the page or that you do not know what page the user means.\n"
            . "If the user asks to explain the current page, describe its purpose, important visible sections, and what the user can do there.\n"
            . ($pageContextText !== '' ? "\nCURRENT PAGE CONTEXT:\n{$pageContextText}\n" : '')
            . "\n"
            . ($context
                ? "KNOWLEDGE BASE CONTEXT:\n{$context}"
                : "No knowledge base context matched strongly enough. Answer from the current page context when available, otherwise say when you do not know."));

        $messages = collect($data['history'] ?? [])
            ->take(-10)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->when($pageContextText !== '', function ($collection) use ($pageContext) {
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

                if (count($summary) > 0) {
                    $collection->push([
                        'role' => 'assistant',
                        'content' => '[Live page context for this turn] ' . implode(' || ', $summary),
                    ]);
                }

                return $collection;
            })
            ->push(['role' => 'user', 'content' => $data['message']])
            ->values()
            ->all();

        $reply = $this->ollama($system, $messages);

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

    public function bot(Request $request, string $apiKey): JsonResponse
    {
        if (! Chatbot::supportsPublicKey()) {
            return $this->json($request, [
                'error' => 'Public embed configuration is temporarily unavailable until the latest database migration is applied.',
            ], 503);
        }

        $bot = Chatbot::query()
            ->where(function ($query) use ($apiKey) {
                $query->where('public_key', $apiKey)
                    ->orWhere('api_key', $apiKey);
            })
            ->where('is_active', true)
            ->first();

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

    public function logo(Request $request, string $apiKey): Response
    {
        if (! Chatbot::supportsPublicKey()) {
            return response('Not found', 404);
        }

        $bot = Chatbot::query()
            ->where(function ($query) use ($apiKey) {
                $query->where('public_key', $apiKey)
                    ->orWhere('api_key', $apiKey);
            })
            ->where('is_active', true)
            ->first();

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

    private function ollama(string $system, array $messages): string
    {
        $response = Http::timeout(120)->post(rtrim(config('services.ollama.url'), '/') . '/api/chat', [
            'model' => config('services.ollama.model'),
            'stream' => false,
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
  var state = { open: false, closing: false, conversationId: null, messages: [], sending: false, bot: null, pageContext: null, lastPageSignature: '', pageReadLabel: 'Scanning page...', draftMessage: '', draftEmail: '', activeField: null };
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
    '.helix-bubble{position:fixed;bottom:20px;z-index:2147483646;width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,0.18);transition:transform .24s ease,box-shadow .24s ease;overflow:hidden;padding:0}',
    '.helix-bubble:hover{transform:scale(1.06)}',
    '.helix-bubble.is-active{transform:scale(.96) rotate(-8deg);box-shadow:0 14px 34px rgba(0,0,0,0.22)}',
    '.helix-bubble img{width:100%;height:100%;object-fit:contain;display:block;border-radius:inherit;background:transparent}',
    '.helix-bubble svg{width:26px;height:26px}',
    '.helix-panel{position:fixed;bottom:90px;z-index:2147483647;width:380px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.25);display:flex;flex-direction:column;overflow:hidden;font-size:14px;color:#0f172a}',
    '.helix-panel[data-side="right"]{right:20px}',
    '.helix-panel[data-side="left"]{left:20px}',
    '.helix-panel.is-closing{display:none}',
    '.helix-header{padding:14px 16px;color:#fff;display:flex;align-items:center;justify-content:space-between;font-weight:600}',
    '.helix-close{background:transparent;border:none;color:#fff;cursor:pointer;font-size:20px;line-height:1;padding:4px 8px;border-radius:6px}',
    '.helix-close:hover{background:rgba(255,255,255,.15)}',
    '.helix-page-status{padding:8px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-size:12px;color:#475569}',
    '.helix-body{flex:1;overflow-y:auto;padding:16px;background:#f8fafc;display:flex;flex-direction:column;gap:8px}',
    '.helix-msg{max-width:85%;padding:12px 16px;border-radius:14px;line-height:1.6;word-wrap:break-word;text-align:left}',
    '.helix-msg.bot{background:#fff;border:1px solid #e2e8f0;align-self:flex-start;border-top-left-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.04)}',
    '.helix-msg.user{color:#fff;align-self:flex-end;border-top-right-radius:4px}',
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
    '.helix-form{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0;background:#fff}',
    '.helix-input{flex:1;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px;outline:none;color:#0f172a}',
    '.helix-input:focus{border-color:#94a3b8}',
    '.helix-send{border:none;color:#fff;border-radius:10px;padding:0 14px;cursor:pointer;font-weight:600}',
    '.helix-send:disabled{opacity:.5;cursor:not-allowed}',
    '.helix-foot{text-align:center;padding:6px;font-size:11px;color:#94a3b8;background:#fff;border-top:1px solid #f1f5f9}',
    '.helix-foot a{color:#64748b;text-decoration:none}',
    '.helix-email{padding:12px;background:#fff;border-top:1px solid #e2e8f0}',
    '.helix-email input{width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none}',
    '@media (max-width:640px){.helix-panel{bottom:88px;width:min(380px,calc(100vw - 20px));max-width:calc(100vw - 20px);height:min(560px,calc(100vh - 108px))}}',
  ].join('');
  root.appendChild(style);

  function openWidget(){
    if (closeTimer) {
      clearTimeout(closeTimer);
      closeTimer = null;
    }
    if (state.open && !state.closing) return;
    state.closing = false;
    state.open = true;
    if (state.messages.length === 0) state.messages.push({ role: 'assistant', content: state.bot.welcome_message });
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
      render();
    }, 200);
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

  function getPageContext(){
    try{
      var pageName = normalizeText((document.querySelector('h1') || {}).innerText || document.title || '', 180);
      var pageSections = buildSections();
      var bodyText = extractMainText();
      return {
        pageTitle: normalizeText(document.title || '', 300),
        pageName: pageName,
        pageUrl: window.location.href,
        pageSections: pageSections,
        pageContent: bodyText,
        scrapedAt: new Date().toISOString()
      };
    }catch(e){ return { pageTitle:'', pageName:'', pageUrl:window.location.href, pageContent:'', pageSections:[], scrapedAt:new Date().toISOString() }; }
  }

  function buildPageSignature(ctx){
    return JSON.stringify([
      ctx.pageUrl || '',
      ctx.pageTitle || '',
      ctx.pageName || '',
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

  function canSendMessage(message, email){
    return !state.sending && !!String(message || '').trim() && isValidEmail(email);
  }

  function persistVisitorEmail(email){
    var normalizedEmail = String(email || '').trim();
    localStorage.setItem(STORE_KEY + '_email', normalizedEmail);
    state.draftEmail = normalizedEmail;
    persistSession();
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
    var pos = state.bot.bubble_position === 'left' ? 'left:20px' : 'right:20px';
    root.innerHTML = '';
    root.appendChild(style);

    var bubble = document.createElement('button');
    bubble.className = 'helix-bubble' + ((state.open || state.closing) ? ' is-active' : '');
    bubble.setAttribute('style', pos + ';background:' + (state.bot.logo_url ? 'transparent' : color));
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
    panel.className = 'helix-panel' + (state.closing ? ' is-closing' : '');
    panel.setAttribute('style', pos);
    panel.setAttribute('data-side', state.bot.bubble_position === 'left' ? 'left' : 'right');
    panel.innerHTML =
      '<div class="helix-header" style="background:' + color + '"><span>' + escapeHtml(state.bot.name) + '</span><button class="helix-close" aria-label="Close">x</button></div>' +
      '<div class="helix-page-status">' + escapeHtml(state.pageReadLabel || 'Reading current page') + '</div>' +
      '<div class="helix-body" id="helix-body"></div>' +
      '<div class="helix-email"><input id="helix-email" type="email" placeholder="Your email" autocomplete="email" inputmode="email" required/></div>' +
      '<form class="helix-form" id="helix-form"><input class="helix-input" id="helix-input" placeholder="Ask anything..." autocomplete="off"/><button class="helix-send" id="helix-send" type="submit" style="background:' + color + '">Send</button></form>' +
      '<div class="helix-foot">Powered by <a href="' + ORIGIN + '" target="_blank" rel="noopener">Helix</a></div>';
    root.appendChild(panel);

    var body = panel.querySelector('#helix-body');
    state.messages.forEach(function(m){
      var div = document.createElement('div');
      div.className = 'helix-msg ' + (m.role === 'user' ? 'user' : 'bot');
      if (m.role === 'user') div.style.background = color;
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
      if (!isValidEmail(email)) {
        if (emailInput) emailInput.focus();
        syncComposerState(panel);
        return false;
      }
      persistVisitorEmail(email);
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
      .replace(/\s+(\d+\.\s+[A-Z])/g, '\n\n$1')
      .replace(/([.!?]["']?)\s+([A-Z][A-Za-z0-9&/()'\- ]{1,60}:)/g, '$1\n\n$2')
      .replace(/\s+(In summary:?\s+)/g, '\n\n$1')
      .replace(/:\s+\*\s+/g, ':\n- ')
      .replace(/:\s+-\s+/g, ':\n- ')
      .replace(/\.\s+\*\s+/g, '.\n- ')
      .replace(/\.\s+-\s+/g, '.\n- ')
      .replace(/\*\s+\*\*/g, '\n- **')
      .replace(/\s-\s(?=[A-Z][A-Za-z0-9&/()'"]{1,60}\s-\s[A-Z])/g, '\n- ')
      .replace(/\s-\s(?=[A-Z][A-Za-z0-9&/()'"]{1,60}:)/g, '\n- ')
      .replace(/([a-z0-9)])\s+-\s+(?=[A-Z*])/g, '$1\n- ')
      .replace(/\n\s*\*\s+/g, '\n- ')
      .replace(/\s+-\s+(?=[A-Z*])/g, '\n- ')
      .replace(/\n{3,}/g, '\n\n');
  }
  function formatInlineMarkdown(s){
    var text = String(s || '');
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
    out = out.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    return out;
  }
  function renderMessageHtml(content){
    var lines = normalizeMessageText(content).split('\n');
    var parts = [];
    var listItems = [];

    function flushList(){
      if (!listItems.length) return;
      parts.push('<ul>' + listItems.map(function(item){ return '<li>' + item + '</li>'; }).join('') + '</ul>');
      listItems = [];
    }

    for (var i = 0; i < lines.length; i++) {
      var raw = lines[i];
      var trimmed = raw.trim();

      if (!trimmed) {
        flushList();
        continue;
      }

      var bulletMatch = trimmed.match(/^[-*]\s+(.*)$/);
      if (bulletMatch) {
        listItems.push(formatInlineMarkdown(escapeHtml(bulletMatch[1])));
        continue;
      }

      var numberedMatch = trimmed.match(/^(\d+)\.\s+(.*)$/);
      if (numberedMatch) {
        flushList();
        parts.push('<p><span class="helix-num">' + numberedMatch[1] + '.</span> ' + formatInlineMarkdown(escapeHtml(numberedMatch[2])) + '</p>');
        continue;
      }

      flushList();
      parts.push('<p>' + formatInlineMarkdown(escapeHtml(trimmed)) + '</p>');
    }

    flushList();
    return parts.join('') || '<p></p>';
  }
  watchPageChanges();
})();
JS;

        return $script;
    }

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
