<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\KnowledgeSource;
use App\Models\Message;
use App\Support\OllamaEmbeddings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicChatController extends Controller
{
    /** How many suggested questions the widget shows on its empty state. */
    private const FAQ_LIMIT = 5;

    private const FAQ_CACHE_MINUTES = 10;

    private const FAQ_WINDOW_DAYS = 180;

    private const FAQ_MESSAGE_SCAN_LIMIT = 3000;

    private const FAQ_CHUNK_SCAN_LIMIT = 200;

    /** Distinct visitors that must have asked something before it is shown to others. */
    private const FAQ_MIN_VISITORS = 2;

    private const FAQ_MIN_LENGTH = 8;

    private const FAQ_MAX_LENGTH = 90;

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
            'message' => ['required', 'string', 'max:12000'],
            'conversationId' => ['nullable', 'uuid'],
            'visitorId' => ['nullable', 'string', 'max:64'],
            'visitorEmail' => ['nullable', 'email', 'max:200'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:12000'],
            'pageContext' => ['nullable', 'array'],
            'pageContext.pageTitle' => ['nullable', 'string', 'max:500'],
            'pageContext.pageName' => ['nullable', 'string', 'max:500'],
            'pageContext.pageUrl' => ['nullable', 'string', 'max:2000'],
            'pageContext.pageContent' => ['nullable', 'string', 'max:50000'],
            'pageContext.scrapedAt' => ['nullable', 'string', 'max:100'],
            'pageContext.pageSections' => ['nullable', 'array', 'max:20'],
            'pageContext.pageSections.*.name' => ['required_with:pageContext.pageSections', 'string', 'max:200'],
            'pageContext.pageSections.*.content' => ['required_with:pageContext.pageSections', 'string', 'max:4000'],
            'pageContext.pageOutline' => ['nullable', 'array', 'max:120'],
            'pageContext.pageOutline.*' => ['nullable', 'string', 'max:700'],
            'formContext' => ['nullable', 'array'],
            'formContext.forms' => ['nullable', 'array', 'max:5'],
            'formContext.forms.*.id' => ['nullable', 'string', 'max:120'],
            'formContext.forms.*.selector' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.label' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.submitSelector' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.fields' => ['nullable', 'array', 'max:40'],
            'formContext.forms.*.fields.*.id' => ['nullable', 'string', 'max:120'],
            'formContext.forms.*.fields.*.selector' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.fields.*.tag' => ['nullable', 'string', 'max:20'],
            'formContext.forms.*.fields.*.type' => ['nullable', 'string', 'max:40'],
            'formContext.forms.*.fields.*.role' => ['nullable', 'string', 'max:60'],
            'formContext.forms.*.fields.*.name' => ['nullable', 'string', 'max:200'],
            'formContext.forms.*.fields.*.label' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.fields.*.ariaLabel' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.fields.*.placeholder' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.fields.*.required' => ['nullable', 'boolean'],
            'formContext.forms.*.fields.*.disabled' => ['nullable', 'boolean'],
            'formContext.forms.*.fields.*.readOnly' => ['nullable', 'boolean'],
            'formContext.forms.*.fields.*.contentEditable' => ['nullable', 'boolean'],
            'formContext.forms.*.fields.*.value' => ['nullable', 'string', 'max:1000'],
            'formContext.forms.*.fields.*.checked' => ['nullable', 'boolean'],
            'formContext.forms.*.fields.*.options' => ['nullable', 'array', 'max:80'],
            'formContext.forms.*.fields.*.options.*.value' => ['nullable', 'string', 'max:300'],
            'formContext.forms.*.fields.*.options.*.label' => ['nullable', 'string', 'max:300'],
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

        $agentResponse = $this->buildFormAgentResponse($bot, $data, $pageContext);

        $reply = $agentResponse['reply'] ?? $this->generateReply(
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
            'agentAction' => $agentResponse['agentAction'] ?? null,
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
            'logo_scale' => Chatbot::supportsLogoScale() ? max(50, min(200, (int) ($bot->logo_scale ?? 100))) : 100,
            'bubble_position' => $bot->bubble_position,
            'collect_email' => $bot->collect_email,
            'widget_cache_minutes' => Chatbot::supportsWidgetCacheMinutes()
                ? (int) ($bot->widget_cache_minutes ?? 10)
                : 10,
            'public_key' => $bot->public_key,
            'is_active' => $bot->is_active,
            'faqs' => $this->frequentlyAskedQuestions($bot),
            'cta' => $this->ctaLink($bot),
            'footer' => $this->footerBranding($bot),
        ]);
    }

    /**
     * Custom footer text and co-brand logos. Null means the widget keeps its default credit.
     */
    private function footerBranding(Chatbot $bot): ?array
    {
        if (! Chatbot::supportsFooterBranding()) {
            return null;
        }

        $text = trim((string) $bot->footer_text);
        $logos = is_array($bot->footer_logos) ? $bot->footer_logos : [];
        $origin = rtrim((string) config('app.url', ''), '/');
        $publicKey = trim((string) $bot->public_key);

        $urls = collect($logos)
            ->filter(fn ($logo) => is_array($logo) && trim((string) ($logo['path'] ?? '')) !== '')
            ->take(3)
            ->values()
            ->map(fn (array $logo, int $position) => [
                'url' => $origin . '/api/public/footer-logo/' . $publicKey . '/' . $position,
                'scale' => max(50, min(200, (int) ($logo['scale'] ?? 100) ?: 100)),
            ])
            ->all();

        if ($text === '' && $urls === []) {
            return null;
        }

        return [
            'text' => $text !== '' ? Str::limit($text, 80, '') : '',
            'logos' => $urls,
        ];
    }

    /**
     * The widget's call-to-action link, re-checked here so only http(s) ever reaches a page.
     */
    private function ctaLink(Chatbot $bot): ?array
    {
        if (! Chatbot::supportsCtaLink()) {
            return null;
        }

        $url = trim((string) $bot->cta_url);
        $label = trim((string) $bot->cta_label);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return [
            'label' => $label !== '' ? Str::limit($label, 40, '') : 'Visit website',
            'url' => $url,
        ];
    }

    /**
     * Suggested questions for the widget's empty state, mined from what visitors actually ask.
     * Falls back to question-shaped lines in the knowledge base while a bot has little history.
     */
    private function frequentlyAskedQuestions(Chatbot $bot, int $limit = self::FAQ_LIMIT): array
    {
        try {
            return Cache::remember(
                'helix_bot_faqs_' . $bot->id . '_' . $limit,
                now()->addMinutes(self::FAQ_CACHE_MINUTES),
                function () use ($bot, $limit) {
                    $questions = $this->askedQuestionSuggestions($bot, $limit);

                    if (count($questions) < $limit) {
                        foreach ($this->knowledgeQuestionSuggestions($bot, $limit) as $question) {
                            if (count($questions) >= $limit) {
                                break;
                            }

                            if (! $this->isDuplicateQuestion($question, $questions)) {
                                $questions[] = $question;
                            }
                        }
                    }

                    return array_values($questions);
                },
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Cluster past visitor messages by their significant terms and return the most-asked phrasings.
     * A cluster is only surfaced once separate visitors have asked it, so a one-off message
     * (which may contain personal details) is never echoed back to other visitors.
     */
    private function askedQuestionSuggestions(Chatbot $bot, int $limit): array
    {
        $rows = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.chatbot_id', $bot->id)
            ->whereIn('messages.role', ['user', 'assistant'])
            ->where('messages.created_at', '>=', now()->subDays(self::FAQ_WINDOW_DAYS))
            ->orderBy('messages.conversation_id')
            ->orderBy('messages.created_at')
            ->orderBy('messages.id')
            ->limit(self::FAQ_MESSAGE_SCAN_LIMIT)
            ->get([
                'messages.role',
                'messages.content',
                'messages.created_at',
                'messages.conversation_id',
                'conversations.visitor_id as visitor_id',
            ]);

        $clusters = [];
        $pending = null;

        foreach ($rows as $row) {
            if ($row->role === 'assistant') {
                // The reply that followed the question decides whether it is worth suggesting.
                if ($pending !== null && $pending['conversation_id'] === $row->conversation_id) {
                    if ($this->looksAnswered((string) $row->content)) {
                        $clusters[$pending['key']]['answered']++;
                    }

                    $pending = null;
                }

                continue;
            }

            $pending = null;
            $text = $this->normalizeQuestionText((string) $row->content);

            if (! $this->isSuggestableQuestion($text)) {
                continue;
            }

            if (count($this->extractKnowledgeTerms($text)) < 2) {
                continue;
            }

            $key = $this->questionSignature($text);
            $visitor = (string) ($row->visitor_id ?: $row->conversation_id);

            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'asks' => 0,
                    'answered' => 0,
                    'visitors' => [],
                    'variants' => [],
                    'last_asked' => 0,
                ];
            }

            $clusters[$key]['asks']++;
            $clusters[$key]['visitors'][$visitor] = true;
            $clusters[$key]['variants'][$text] = ($clusters[$key]['variants'][$text] ?? 0) + 1;
            $clusters[$key]['last_asked'] = max(
                $clusters[$key]['last_asked'],
                $row->created_at ? strtotime((string) $row->created_at) : 0,
            );

            $pending = ['key' => $key, 'conversation_id' => $row->conversation_id];
        }

        $clusters = array_filter($clusters, static function (array $cluster) {
            // Genuinely frequent: separate visitors asked it, and the bot could actually answer it.
            return count($cluster['visitors']) >= self::FAQ_MIN_VISITORS && $cluster['answered'] > 0;
        });

        uasort($clusters, static function (array $a, array $b) {
            return [count($b['visitors']), $b['asks'], $b['answered'], $b['last_asked']]
                <=> [count($a['visitors']), $a['asks'], $a['answered'], $a['last_asked']];
        });

        $questions = [];

        foreach ($clusters as $cluster) {
            if (count($questions) >= $limit) {
                break;
            }

            $question = $this->presentQuestion($this->pickQuestionVariant($cluster['variants']));

            if ($question !== '' && ! $this->isDuplicateQuestion($question, $questions)) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * A reply counts as answered when the bot gave real content rather than a "no idea" fallback.
     */
    private function looksAnswered(string $reply): bool
    {
        $reply = $this->normalizeQuestionText($reply);

        if (mb_strlen($reply) < 60) {
            return false;
        }

        return ! preg_match(
            '/\b(i (?:do not|don\'t) (?:have|know)|i(?:\'m| am) not sure|no information|not available in|could ?n\'t find|cannot find|unable to (?:find|locate)|wala|hindi ko)\b/iu',
            $reply,
        );
    }

    /**
     * Sorted, lightly stemmed key terms — the identity of a question, so "traffic ordinance"
     * and "what are the traffic ordinances?" land in the same cluster and count as one FAQ.
     */
    private function questionSignature(string $text): string
    {
        $terms = array_map(fn (string $term) => $this->stemTerm($term), $this->extractKnowledgeTerms($text));
        $terms = array_values(array_unique($terms));
        sort($terms);

        return implode(' ', array_slice($terms, 0, 8));
    }

    private function stemTerm(string $term): string
    {
        $term = mb_strtolower($term);
        $length = mb_strlen($term);

        if ($length > 4 && str_ends_with($term, 'ies')) {
            return mb_substr($term, 0, -3) . 'y';
        }

        if ($length > 3 && ! str_ends_with($term, 'ss') && str_ends_with($term, 's')) {
            return mb_substr($term, 0, -1);
        }

        return $term;
    }

    /**
     * Question-shaped lines sitting in the bot's own knowledge (JSON "question:" fields,
     * FAQ headings in imported pages, and so on).
     */
    private function knowledgeQuestionSuggestions(Chatbot $bot, int $limit): array
    {
        $questions = [];

        // Pass 1: explicit FAQ fields ("question: ..."), e.g. an uploaded FAQ JSON.
        // Pass 2: plain question lines, but only inside sources the owner named as an FAQ.
        foreach (['field', 'faq_source'] as $pass) {
            if (count($questions) >= $limit) {
                break;
            }

            $chunks = DocumentChunk::query()
                ->join('knowledge_sources', 'knowledge_sources.id', '=', 'document_chunks.source_id')
                ->where('document_chunks.chatbot_id', $bot->id)
                ->where('knowledge_sources.status', 'ready')
                ->when(
                    $pass === 'field',
                    fn ($query) => $query->where('document_chunks.content', 'ILIKE', '%question:%'),
                    fn ($query) => $query
                        ->where('knowledge_sources.name', 'ILIKE', '%faq%')
                        ->where('document_chunks.content', 'ILIKE', '%?%'),
                )
                // Newest sources first, so a freshly uploaded FAQ is never crowded out by an older corpus.
                ->orderByDesc('knowledge_sources.created_at')
                ->orderBy('document_chunks.chunk_index')
                ->limit(self::FAQ_CHUNK_SCAN_LIMIT)
                ->pluck('document_chunks.content');

            foreach ($chunks as $chunk) {
                foreach (preg_split('/\R/u', (string) $chunk) ?: [] as $line) {
                    if (count($questions) >= $limit) {
                        return $questions;
                    }

                    $line = $this->normalizeQuestionText($line);

                    if (preg_match('/^(?:question|q|faq)\s*[:\-]\s*(.+)$/iu', $line, $matches)) {
                        $line = $this->normalizeQuestionText($matches[1]);
                    } elseif ($pass === 'field' || ! str_ends_with($line, '?')) {
                        continue;
                    }

                    if (! $this->isSuggestableQuestion($line) || count($this->extractKnowledgeTerms($line)) < 2) {
                        continue;
                    }

                    $question = $this->presentQuestion($line);

                    if ($question !== '' && ! $this->isDuplicateQuestion($question, $questions)) {
                        $questions[] = $question;
                    }
                }
            }
        }

        return $questions;
    }

    private function pickQuestionVariant(array $variants): string
    {
        $best = '';
        $bestScore = [-1, 0, 0];

        foreach ($variants as $text => $count) {
            $text = (string) $text;
            // Most-used phrasing first, then an actual question, then the tightest wording.
            $score = [$count, str_ends_with($text, '?') ? 1 : 0, -mb_strlen($text)];

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $text;
            }
        }

        return $best;
    }

    private function normalizeQuestionText(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Keep short, self-contained questions and drop anything that looks like chatter,
     * a pasted blob, or personal contact details.
     */
    private function isSuggestableQuestion(string $text): bool
    {
        $length = mb_strlen($text);

        if ($length < self::FAQ_MIN_LENGTH || $length > self::FAQ_MAX_LENGTH) {
            return false;
        }

        if (preg_match('/[<>{}]|https?:\/\/|\S+@\S+\.\S+|\d{7,}/u', $text)) {
            return false;
        }

        // At least half the characters should be letters or spaces.
        $letters = preg_match_all('/[\pL\s]/u', $text);

        return $letters !== false && $letters >= $length / 2;
    }

    private function presentQuestion(string $text): string
    {
        $text = $this->normalizeQuestionText($text);

        // "Hello! Do you have ..." reads badly on a chip — keep just the question.
        $text = $this->normalizeQuestionText(
            preg_replace('/^(?:hi|hello|hey|good (?:morning|afternoon|evening)|greetings)\b[\s,!.:;-]*/iu', '', $text) ?? $text,
        );

        if ($text === '' || mb_strlen($text) < self::FAQ_MIN_LENGTH) {
            return '';
        }

        if (mb_strlen($text) > self::FAQ_MAX_LENGTH) {
            return '';
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private function isDuplicateQuestion(string $question, array $existing): bool
    {
        $key = $this->questionSignature($question);

        foreach ($existing as $other) {
            if ($key !== '' && $key === $this->questionSignature($other)) {
                return true;
            }
        }

        return in_array($question, $existing, true);
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
     * Footer logos are streamed through the API rather than linked at their /storage/*.png path:
     * an extensionless URL is not treated as a hotlinked image by CDNs sitting in front of us,
     * which would otherwise 403 the request because the embedding site is a different domain.
     */
    public function footerLogo(Request $request, string $apiKey, string $index): Response
    {
        if (! Chatbot::supportsPublicKey() || ! Chatbot::supportsFooterBranding()) {
            return response('Not found', 404);
        }

        $bot = $this->findActiveBotByKeys($apiKey, $apiKey);

        if (! $bot || ! $this->isAllowedDomain($request, $bot)) {
            return response('Not found', 404);
        }

        $logos = array_values(array_filter(
            is_array($bot->footer_logos) ? $bot->footer_logos : [],
            static fn ($logo) => is_array($logo) && trim((string) ($logo['path'] ?? '')) !== '',
        ));

        $position = (int) $index;
        $path = trim((string) ($logos[$position]['path'] ?? ''));

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
     * Return a structured browser action only when the user clearly asks Helix to fill a visible form.
     */
    private function buildFormAgentResponse(Chatbot $bot, array $data, ?array $pageContext): ?array
    {
        $message = trim((string) ($data['message'] ?? ''));
        $forms = $data['formContext']['forms'] ?? [];

        if (! is_array($forms) || count($forms) === 0) {
            return null;
        }

        if (! $this->shouldUseFormAgent($data)) {
            return null;
        }

        $formContext = [
            'page' => [
                'title' => $pageContext['pageTitle'] ?? null,
                'name' => $pageContext['pageName'] ?? null,
                'url' => $pageContext['pageUrl'] ?? null,
            ],
            'forms' => collect($forms)->take(5)->values()->all(),
        ];

        $system = implode("\n\n", [
            'You are Helix form assistant. Return only valid JSON and no markdown.',
            'Your job is to map the user request to visible, non-sensitive form fields supplied in FORM CONTEXT.',
            'Never fill credit card, payment, OTP, captcha, token, government ID, or hidden fields. Password fields may be filled only when the user explicitly provides a password or asks to create/sign in to an account.',
            'Use only field ids/selectors that appear in FORM CONTEXT. Do not invent selectors.',
            'Fields can be native inputs, textareas, selects, contenteditable areas, or custom dropdown/combobox controls. For dropdowns, use a value that exactly matches one visible option label or value when options are supplied.',
            'Do not plan values for disabled or read-only fields unless the same plan first selects another field that clearly enables them; otherwise ask for the prerequisite selection.',
            'Use RECENT CHAT to resolve short follow-up answers. For example, if the assistant asked for an email and the user replies with an email address, fill the email field.',
            'If the user gives one value and exactly one likely empty field is still needed, fill that field. If required values are missing, ask for them in reply and list them in missing.',
            'The browser will never submit directly. If the user explicitly asks to submit after filling, set submit to true so the browser can ask for confirmation first.',
            'Schema: {"type":"fill_form"|"ask"|"no_action","reply":"short user-facing reply","formId":"form id or null","fields":[{"fieldId":"field id","selector":"field selector","value":"string value","checked":true|false|null}],"missing":["field or value needed"],"submit":true|false}',
        ]);

        $recentChat = collect($data['history'] ?? [])
            ->take(-8)
            ->map(fn ($turn) => strtoupper((string) ($turn['role'] ?? 'message')) . ': ' . trim((string) ($turn['content'] ?? '')))
            ->filter(fn ($turn) => trim($turn) !== '')
            ->implode("\n");

        $messages = [[
            'role' => 'user',
            'content' => "RECENT CHAT:\n{$recentChat}\n\nUSER REQUEST:\n{$message}\n\nFORM CONTEXT:\n" . json_encode($formContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]];

        try {
            $raw = $this->generateReply($system, $messages, $bot);
            $plan = $this->decodeJsonObject($raw);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }

        if (! is_array($plan)) {
            return null;
        }

        $type = $plan['type'] ?? 'no_action';
        if ($type === 'no_action') {
            return [
                'reply' => $this->describeDetectedForms($forms),
            ];
        }

        $reply = trim((string) ($plan['reply'] ?? ''));
        if ($reply === '') {
            $reply = $type === 'ask'
                ? 'I need a little more information before I can fill that form.'
                : 'I can fill that form for you. Please review the fields after I update them.';
        }

        if ($type === 'ask') {
            return ['reply' => $reply];
        }

        $allowedFields = collect($forms)
            ->flatMap(fn ($form) => is_array($form['fields'] ?? null) ? $form['fields'] : [])
            ->mapWithKeys(fn ($field) => [($field['id'] ?? '') => $field])
            ->filter(fn ($field, $id) => is_string($id) && $id !== '')
            ->all();

        $allowedFieldsBySelector = collect($forms)
            ->flatMap(fn ($form) => is_array($form['fields'] ?? null) ? $form['fields'] : [])
            ->mapWithKeys(fn ($field) => [($field['selector'] ?? '') => $field])
            ->filter(fn ($field, $selector) => is_string($selector) && $selector !== '')
            ->all();

        $allowedFieldsByAlias = collect($forms)
            ->flatMap(fn ($form) => is_array($form['fields'] ?? null) ? $form['fields'] : [])
            ->flatMap(function ($field) {
                return collect([$field['label'] ?? null, $field['placeholder'] ?? null, $field['name'] ?? null])
                    ->filter(fn ($alias) => is_string($alias) && trim($alias) !== '')
                    ->mapWithKeys(fn ($alias) => [$this->normalizeFormFieldAlias($alias) => $field]);
            })
            ->filter(fn ($field, $alias) => is_string($alias) && $alias !== '')
            ->all();

        $fields = collect($plan['fields'] ?? [])
            ->filter(fn ($field) => is_array($field))
            ->map(function (array $field) use ($allowedFields, $allowedFieldsBySelector, $allowedFieldsByAlias) {
                $fieldId = trim((string) ($field['fieldId'] ?? $field['id'] ?? ''));
                $selectorFromPlan = trim((string) ($field['selector'] ?? ''));
                $fieldAlias = $this->normalizeFormFieldAlias($fieldId);
                $selectorAlias = $this->normalizeFormFieldAlias($selectorFromPlan);
                $known = ($fieldId !== '' ? ($allowedFields[$fieldId] ?? null) : null)
                    ?: ($selectorFromPlan !== '' ? ($allowedFieldsBySelector[$selectorFromPlan] ?? null) : null)
                    ?: ($fieldAlias !== '' ? ($allowedFieldsByAlias[$fieldAlias] ?? null) : null)
                    ?: ($selectorAlias !== '' ? ($allowedFieldsByAlias[$selectorAlias] ?? null) : null);

                if (! is_array($known)) {
                    return null;
                }

                $fieldId = trim((string) ($known['id'] ?? $fieldId));
                $selector = trim((string) ($known['selector'] ?? $selectorFromPlan));
                if ($selector === '') {
                    return null;
                }

                $value = $field['value'] ?? null;
                $checked = array_key_exists('checked', $field) ? (bool) $field['checked'] : null;

                return [
                    'fieldId' => $fieldId,
                    'selector' => $selector,
                    'value' => is_scalar($value) ? substr((string) $value, 0, 1000) : null,
                    'checked' => $checked,
                ];
            })
            ->filter()
            ->take(30)
            ->values()
            ->all();

        $formId = trim((string) ($plan['formId'] ?? ''));
        $selectedForm = collect($forms)->first(fn ($form) => ($form['id'] ?? null) === $formId) ?? $forms[0] ?? [];

        return [
            'reply' => $reply,
            'agentAction' => [
                'type' => 'fill_form',
                'formId' => $formId ?: ($selectedForm['id'] ?? null),
                'formSelector' => $selectedForm['selector'] ?? null,
                'submitSelector' => $selectedForm['submitSelector'] ?? null,
                'fields' => $fields,
                'missing' => collect($plan['missing'] ?? [])
                    ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
                    ->map(fn ($item) => substr(trim((string) $item), 0, 200))
                    ->take(12)
                    ->values()
                    ->all(),
                'submit' => (bool) ($plan['submit'] ?? false),
            ],
        ];
    }

    private function normalizeFormFieldAlias(string $value): string
    {
        $alias = mb_strtolower(trim($value));
        $alias = preg_replace('/[^\pL\pN]+/u', ' ', $alias) ?? $alias;

        return trim(preg_replace('/\s+/u', ' ', $alias) ?? $alias);
    }

    private function describeDetectedForms(array $forms): string
    {
        $form = $forms[0] ?? [];
        $label = trim((string) ($form['label'] ?? 'this form'));
        $fields = collect($form['fields'] ?? [])
            ->map(fn ($field) => trim((string) (($field['label'] ?? '') ?: ($field['placeholder'] ?? '') ?: ($field['name'] ?? ''))))
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();

        if ($fields === []) {
            return "I found {$label}. Tell me what to type into the fields, and I will fill them for you.";
        }

        return "I found {$label} with these fields: " . implode(', ', $fields) . '. Tell me the values to type, and I will fill them for you.';
    }

    private function shouldUseFormAgent(array $data): bool
    {
        $message = trim((string) ($data['message'] ?? ''));

        if ($this->looksLikeFormAgentRequest($message)) {
            return true;
        }

        return $this->looksLikeFieldValue($message)
            && $this->recentAssistantAskedForFormValues($data['history'] ?? []);
    }

    private function looksLikeFormAgentRequest(string $message): bool
    {
        $text = mb_strtolower($message);
        $hasFormTarget = (bool) preg_match('/\b(form|field|fields|application|signup|sign up|contact|checkout|registration|create account|email|password|name|organization|company|phone)\b/u', $text);

        return ((bool) preg_match('/\b(fill|complete|populate|answer)\b/u', $text) && $hasFormTarget)
            || ((bool) preg_match('/\b(type|enter|put|write|input|select|choose|check|uncheck)\b/u', $text) && $hasFormTarget)
            || (bool) preg_match('/\b(submit|send)\s+(?:the\s+)?(?:form|application|registration|signup)\b/u', $text)
            || (bool) preg_match('/\b(sign up|signup|register|create account)\b/u', $text);
    }

    private function looksLikeFieldValue(string $message): bool
    {
        $text = trim($message);
        if ($text === '' || mb_strlen($text) > 500) {
            return false;
        }

        return filter_var($text, FILTER_VALIDATE_EMAIL) !== false
            || preg_match('/^\+?[0-9][0-9\s().-]{5,}$/', $text) === 1
            || preg_match("/^[\\pL][\\pL\\pM'.-]+(?:\\s+[\\pL][\\pL\\pM'.-]+)+$/u", $text) === 1;
    }

    private function recentAssistantAskedForFormValues(array $history): bool
    {
        return collect($history)
            ->take(-3)
            ->contains(function ($turn) {
                if (($turn['role'] ?? null) !== 'assistant') {
                    return false;
                }

                $content = mb_strtolower(trim((string) ($turn['content'] ?? '')));

                return $content !== ''
                    && preg_match('/\b(found|fill|type|values?|field|fields?|form)\b/u', $content) === 1
                    && preg_match('/\b(tell me|i need|provide|what should|values? to type|missing)\b/u', $content) === 1;
            });
    }

    private function decodeJsonObject(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
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
            'If the user asks a counting question (how many, total, count, number of), count explicitly from the context you can see and do not estimate.',
            'If the exact count cannot be verified from the provided context, say so instead of guessing.',
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

        foreach ($this->retrieveSemanticKnowledgeMatches($bot, $retrievalQuery ?: $message) as $row) {
            $row->rank = 1000 + (float) ($row->similarity ?? 0);
            $results[$row->id] = $row;
        }

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
                ->limit(8)
                ->get();

            foreach ($rows as $row) {
                if (! isset($results[$row->id])) {
                    $results[$row->id] = $row;
                }
            }
        }

        if (count($results) < 4) {
            $fallbackRows = $this->fallbackKnowledgeMatches($bot, $message);

            foreach ($fallbackRows as $row) {
                if (! isset($results[$row->id])) {
                    $results[$row->id] = $row;
                }
            }
        }

        return collect($results)
            ->sortByDesc(fn ($row) => (float) ($row->rank ?? 0))
            ->take(8)
            ->values()
            ->all();
    }

    private function retrieveSemanticKnowledgeMatches(Chatbot $bot, string $query): array
    {
        $embedding = OllamaEmbeddings::embed($query);

        if (! $embedding) {
            return [];
        }

        $vector = OllamaEmbeddings::toPgVector($embedding);

        try {
            $rows = \DB::select("
SELECT
    document_chunks.id,
    document_chunks.content,
    document_chunks.chunk_index,
    knowledge_sources.name AS source_name,
    knowledge_sources.source_type AS source_type,
    1 - (document_chunks.embedding <=> CAST(? AS vector)) AS similarity
FROM document_chunks
JOIN knowledge_sources ON knowledge_sources.id = document_chunks.source_id
WHERE document_chunks.chatbot_id = ?
  AND knowledge_sources.status = ?
  AND document_chunks.embedding IS NOT NULL
ORDER BY document_chunks.embedding <=> CAST(? AS vector)
LIMIT 8
", [$vector, $bot->id, "ready", $vector]);

            $minSimilarity = (float) config("models.embeddings.min_similarity", 0.35);

            return array_values(array_filter($rows, static fn ($row) => (float) ($row->similarity ?? 0) >= $minSimilarity));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
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
            ->limit(8)
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
  var FOOTER_LOGO_BASE_PX = 16;
  var EMBED_CACHE_MINUTES = parseCacheMinutes(explicitCacheMinutes);

  var closeTimer = null;
  var state = { open: false, closing: false, conversationId: null, messages: [], sending: false, bot: null, pageContext: null, formContext: null, pendingFormSubmit: null, lastPageSignature: '', pageReadLabel: 'Scanning page...', draftMessage: '', draftEmail: '', activeField: null, headerCompact: false, headerProgress: 0, headerTargetProgress: 0, headerFrame: null, ctaMini: false, panelAnimatedIn: false };
  restoreSession();
  state.ctaMini = shouldMiniCta();

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
    '.helix-bubble img{width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit;background:transparent;transform:scale(var(--helix-logo-scale,1));transform-origin:center;transition:transform .18s ease}',
    '.helix-bubble svg{width:26px;height:26px}',
    '.helix-panel{position:fixed;bottom:96px;z-index:2147483647;width:390px;max-width:calc(100vw - 24px);height:620px;max-height:calc(100vh - 124px);background:#f8fafc;border-radius:28px;box-shadow:0 34px 80px rgba(15,23,42,0.30);display:flex;flex-direction:column;overflow:hidden;font-size:14px;color:#0f172a;border:1px solid rgba(148,163,184,0.18);transform-origin:calc(100% - 28px) calc(100% - 20px)}',
    '.helix-panel[data-side="right"]{right:20px}',
    '.helix-panel[data-side="left"]{left:20px}',
    '.helix-panel.is-opening{animation:helixPanelIn .34s cubic-bezier(.22,1,.36,1) both}',
    '.helix-panel.is-closing{pointer-events:none;animation:helixPanelOut .24s cubic-bezier(.4,0,1,1) both}',
    '.helix-panel.is-compact .helix-header-copy,.helix-panel.is-compact .helix-page-status{pointer-events:none}',
    '.helix-header{position:relative;padding:var(--helix-header-padding,10px 12px 12px);color:#fff;display:flex;flex-direction:column;gap:var(--helix-header-gap,8px);overflow:hidden;will-change:padding}',
    '.helix-header::before{content:"";position:absolute;inset:-18% auto auto -16%;width:180px;height:180px;border-radius:999px;background:rgba(255,255,255,0.20);filter:blur(10px)}',
    '.helix-header::after{content:"";position:absolute;right:-56px;top:22px;width:180px;height:180px;border-radius:999px;background:rgba(255,255,255,0.14);filter:blur(24px)}',
    '.helix-header-top,.helix-header-copy,.helix-page-status,.helix-header-actions{position:relative;z-index:1}',
    '.helix-header-top{display:flex;align-items:center;justify-content:space-between;gap:8px}',
    '.helix-brand{display:flex;align-items:center;gap:8px;min-width:0;flex:1}',
    '.helix-brand-logo{width:var(--helix-logo-size,36px);height:var(--helix-logo-size,36px);border-radius:var(--helix-logo-radius,12px);overflow:hidden;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.12);box-shadow:inset 0 0 0 1px rgba(255,255,255,0.12);flex-shrink:0;will-change:width,height}',
    '.helix-brand-logo img{width:100%;height:100%;object-fit:cover;display:block}',
    '.helix-brand-fallback{font-size:14px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}',
    '.helix-brand-copy{min-width:0;padding-top:0;display:flex;flex-direction:column;justify-content:center}',
    '.helix-brand-label{font-size:var(--helix-brand-label-size,9px);letter-spacing:.09em;text-transform:uppercase;opacity:.72;margin-bottom:1px;line-height:1.1}',
    '.helix-brand-name{font-size:var(--helix-brand-name-size,16px);font-weight:700;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}',
    '.helix-close{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.12);border:none;color:#fff;cursor:pointer;font-size:22px;font-weight:400;line-height:1;padding:0 0 2px 0;border-radius:999px;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.14);backdrop-filter:blur(6px);flex-shrink:0;transition:background .18s ease,transform .18s ease}',
    '.helix-close:hover{background:rgba(255,255,255,0.18);transform:translateY(-1px)}',
    '.helix-header-copy{display:flex;flex-direction:column;gap:6px;max-height:var(--helix-header-copy-height,220px);opacity:var(--helix-header-copy-opacity,1);transform:translateY(var(--helix-header-copy-y,0));transform-origin:top left;overflow:hidden;will-change:opacity,transform,max-height}',
    '.helix-eyebrow{font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;opacity:.78}',
    '.helix-headline{font-size:var(--helix-headline-size,24px);line-height:1.14;font-weight:800;letter-spacing:-0.03em;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.helix-subcopy{font-size:13px;line-height:1.55;max-width:250px;color:rgba(255,255,255,0.86)}',
    '.helix-page-status{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;max-width:100%;padding:var(--helix-status-padding,10px 14px);border-radius:16px;background:rgba(255,255,255,0.92);color:#334155;font-size:12px;font-weight:600;box-shadow:0 12px 24px rgba(15,23,42,0.12);max-height:var(--helix-status-height,56px);opacity:var(--helix-status-opacity,1);transform:translateY(var(--helix-status-y,0));overflow:hidden;will-change:opacity,transform,max-height}',
    '.helix-page-status::before{content:"";width:8px;height:8px;border-radius:999px;background:currentColor;opacity:.65;flex-shrink:0}',
    '.helix-shell{flex:1;display:flex;flex-direction:column;min-height:0;background:linear-gradient(180deg,rgba(248,250,252,0.92) 0%,#ffffff 22%,#ffffff 100%)}',
    '.helix-body{flex:1;overflow-y:auto;padding:16px 16px 12px;background:transparent;display:flex;flex-direction:column;gap:10px}',
    '.helix-msg{max-width:86%;padding:13px 16px;border-radius:18px;line-height:1.6;word-wrap:break-word;text-align:left;font-size:13.5px}',
    '.helix-msg.bot{background:rgba(255,255,255,0.98);border:1px solid rgba(226,232,240,0.95);align-self:flex-start;border-top-left-radius:8px;box-shadow:0 12px 30px rgba(15,23,42,0.06)}',
    '.helix-msg.user{color:#fff;align-self:flex-end;border-top-right-radius:8px;box-shadow:0 12px 30px rgba(15,23,42,0.14)}',
    '.helix-msg p{margin:0 0 0.6em;line-height:1.65}',
    '.helix-msg p:last-child{margin-bottom:0}',
    '.helix-msg ul,.helix-msg ol{margin:0.4em 0 0.75em;padding-left:1.25em;list-style-position:inside}',
    '.helix-msg ul li{margin:0.25em 0;line-height:1.5}',
    '.helix-msg strong{font-weight:700;color:#1e293b}',
    '.helix-msg a{color:#3b82f6;text-decoration:underline;text-underline-offset:2px}',
    '.helix-msg code{background:#f1f5f9;padding:0.15em 0.4em;border-radius:4px;font-size:0.9em;font-family:monospace}',
    '.helix-msg .helix-num{color:#3b82f6;font-weight:700;margin-right:0.3em}',
    '.helix-faqs{display:flex;flex-direction:column;gap:7px;align-self:stretch;margin-top:2px}',
    '.helix-faqs-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b;padding-left:2px}',
    '.helix-faq-chip{display:block;width:100%;max-width:100%;margin:0;text-align:left;padding:11px 14px;border-radius:16px;border:1px solid var(--helix-faq-border,rgba(226,232,240,0.95)) !important;background:var(--helix-faq-bg,rgba(255,255,255,0.98)) !important;color:var(--helix-faq-color,#0f172a) !important;font:inherit;font-size:13px;line-height:1.45;letter-spacing:normal;text-transform:none;white-space:normal;word-break:break-word;overflow-wrap:anywhere;-webkit-appearance:none;appearance:none;cursor:pointer;box-shadow:0 8px 20px rgba(15,23,42,0.05);transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}',
    '.helix-faq-chip:hover,.helix-faq-chip:focus-visible{background:var(--helix-faq-hover-bg,#eef2ff) !important;border-color:var(--helix-faq-hover-border,rgba(124,92,255,0.45)) !important;color:var(--helix-faq-hover-color,#0f172a) !important;transform:translateY(-1px);box-shadow:0 12px 26px rgba(15,23,42,0.10)}',
    '.helix-faq-chip:focus-visible{outline:2px solid var(--helix-faq-hover-border,rgba(124,92,255,0.45));outline-offset:2px}',
    '.helix-faq-chip:active{background:var(--helix-faq-active-bg,#e0e7ff) !important;color:var(--helix-faq-active-color,#0f172a) !important;transform:translateY(0);box-shadow:0 6px 16px rgba(15,23,42,0.08)}',
    '.helix-faq-chip:disabled{opacity:.6;cursor:default;transform:none}',
    '.helix-typing{display:flex;gap:4px;padding:14px}',
    '.helix-typing-wrapper{transition:opacity 0.25s ease,transform 0.25s ease}',
    '.helix-typing span{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:helixBounce 1.2s infinite}',
    '.helix-typing span:nth-child(2){animation-delay:.15s}.helix-typing span:nth-child(3){animation-delay:.3s}',
    '@keyframes helixBounce{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-4px)}}',
    '@keyframes helixPanelIn{0%{opacity:0;transform:translateY(24px) scale(.94)}100%{opacity:1;transform:translateY(0) scale(1)}}',
    '@keyframes helixPanelOut{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(18px) scale(.96)}}',
    '.helix-cta{padding:0 14px 8px;background:transparent;transition:padding .3s cubic-bezier(.22,1,.36,1)}',
    '.helix-cta.is-mini{padding:0 14px 6px}',
    '.helix-cta-link{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;max-width:100%;margin:0;padding:11px 16px;border-radius:16px;border:1px solid var(--helix-cta-border,rgba(124,92,255,0.35)) !important;background:var(--helix-cta-bg,#ffffff) !important;color:var(--helix-cta-color,#0f172a) !important;font:inherit;font-size:13px;font-weight:700;line-height:1.35;letter-spacing:normal;text-transform:none;text-decoration:none !important;white-space:normal;word-break:break-word;overflow-wrap:anywhere;cursor:pointer;box-shadow:0 10px 24px rgba(15,23,42,0.08);transition:max-width .3s cubic-bezier(.22,1,.36,1),padding .3s cubic-bezier(.22,1,.36,1),font-size .3s cubic-bezier(.22,1,.36,1),box-shadow .3s ease,transform .16s ease,background .16s ease,color .16s ease}',
    '.helix-cta-link.is-mini{padding:6px 12px;font-size:11.5px;gap:6px;border-radius:12px;box-shadow:0 5px 14px rgba(15,23,42,0.06)}',
    '.helix-cta-link.is-mini span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.helix-cta-link.is-mini svg{width:13px;height:13px}',
    '.helix-cta-link:hover,.helix-cta-link:focus-visible{background:var(--helix-cta-hover-bg,#eef2ff) !important;color:var(--helix-cta-hover-color,#0f172a) !important;transform:translateY(-1px);box-shadow:0 14px 30px rgba(15,23,42,0.12)}',
    '.helix-cta-link:focus-visible{outline:2px solid var(--helix-cta-border,rgba(124,92,255,0.35));outline-offset:2px}',
    '.helix-cta-link:active{transform:translateY(0)}',
    '.helix-cta-link svg{width:15px;height:15px;flex-shrink:0}',
    '.helix-composer{padding:0 14px 14px;background:transparent}',
    '.helix-composer-card{border-radius:22px;background:rgba(255,255,255,0.96);border:1px solid rgba(226,232,240,0.95);box-shadow:0 18px 36px rgba(15,23,42,0.10);overflow:hidden}',
    '.helix-email{padding:12px 12px 0;background:transparent;border-top:none}',
    '.helix-email input{width:100%;border:1px solid #e2e8f0;border-radius:14px;padding:11px 13px;font-size:13px;outline:none;background:#fff;color:#0f172a;transition:border-color .2s ease,box-shadow .2s ease}',
    '.helix-email input:focus{border-color:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,0.12)}',
    '.helix-form{display:flex;align-items:flex-end;gap:10px;padding:12px;background:transparent}',
    '.helix-input{flex:1;border:1px solid #e2e8f0;border-radius:16px;padding:12px 14px;font-size:14px;line-height:20px;min-height:46px;height:46px;max-height:76px;resize:none;overflow-y:auto;outline:none;color:#0f172a;background:#fff;transition:height .2s ease,border-color .2s ease,box-shadow .2s ease}',
    '.helix-input.is-expanded{height:76px}',
    '.helix-input:focus{border-color:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,0.12)}',
    '.helix-send{border:none;color:#fff;border-radius:16px;padding:0 18px;cursor:pointer;font-weight:700;min-width:88px;height:46px;align-self:flex-end;box-shadow:0 14px 24px rgba(15,23,42,0.16);transition:transform .2s ease,box-shadow .2s ease}',
    '.helix-send:hover{transform:translateY(-1px);box-shadow:0 18px 28px rgba(15,23,42,0.18)}',
    '.helix-send:disabled{opacity:.5;cursor:not-allowed}',
    '.helix-foot{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:6px 10px;text-align:center;padding:0 14px 14px;font-size:11px;color:#94a3b8;background:transparent;border-top:none}',
    '.helix-foot-logos{display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center}',
    '.helix-foot-logos img{height:16px !important;width:auto !important;max-width:80px !important;min-width:0 !important;min-height:0 !important;max-height:16px !important;display:inline-block !important;opacity:1 !important;visibility:visible !important;position:static !important;margin:0 !important;padding:0 !important;border:0 !important;background:transparent !important;box-shadow:none !important;filter:none !important;transform:none !important;clip-path:none !important;object-fit:contain;border-radius:3px}',
    '.helix-foot a{color:#64748b;text-decoration:none}',
    ' (max-width:640px){.helix-panel{bottom:86px;width:min(390px,calc(100vw - 16px));max-width:calc(100vw - 16px);height:min(620px,calc(100vh - 100px));border-radius:24px}.helix-header{padding:var(--helix-header-padding,9px 11px 11px)}.helix-headline{max-width:100%}.helix-brand-name{max-width:170px}.helix-composer{padding:0 10px 10px}.helix-body{padding:14px 12px 10px}.helix-page-status{max-width:100%}}',
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
    state.headerProgress = 0;
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
        var text = normalizeText(el.innerText || el.textContent || '', 1000);
        if (text) values.push(text);
      }
    }
    return dedupeTexts(values, limit);
  }

  function extractMainText(){
    try {
      var source = document.body;
      if (!source) return '';
      var clone = source.cloneNode(true);
      var remove = clone.querySelectorAll ? clone.querySelectorAll('script,style,noscript,iframe,svg,canvas,#helix-widget-root') : [];
      for (var i = 0; i < remove.length; i++) remove[i].remove();
      return normalizeText(clone.innerText || clone.textContent || '', 48000);
    } catch (e) {
      return '';
    }
  }

  function pushUnique(out, seen, value, limit){
    var clean = normalizeText(value, limit || 650);
    if (!clean) return;
    var key = clean.toLowerCase();
    if (seen[key]) return;
    seen[key] = true;
    out.push(clean);
  }

  function summarizeFormFieldForReading(el){
    var tag = String(el.tagName || '').toLowerCase();
    var type = String(el.getAttribute('type') || el.type || tag).toLowerCase();
    if (isSensitiveField(el)) return '';
    var parts = [];
    var label = labelForField(el) || normalizeText(el.getAttribute('placeholder') || el.name || '', 160);
    parts.push('Field' + (label ? ': ' + label : ''));
    parts.push('type: ' + type);
    if (el.required) parts.push('required');
    var placeholder = normalizeText(el.getAttribute('placeholder') || '', 160);
    if (placeholder && placeholder !== label) parts.push('placeholder: ' + placeholder);
    if (tag === 'select') {
      var options = collectFieldOptions(el).map(function(option){ return option.label || option.value; }).filter(Boolean).slice(0, 12);
      if (options.length) parts.push('options: ' + options.join(', '));
    }
    return parts.join(' | ');
  }

  function buildPageOutline(){
    try {
      var out = [];
      var seen = {};
      var selectors = [
        'h1','h2','h3','h4','p','li','summary','blockquote',
        'label','input','textarea','select','button','[role="button"]','a[href]'
      ].join(',');
      var nodes = document.body ? document.body.querySelectorAll(selectors) : [];
      for (var i = 0; i < nodes.length && out.length < 120; i++) {
        var el = nodes[i];
        if (!el || (el.closest && el.closest('#helix-widget-root')) || !isVisibleElement(el)) continue;
        var tag = String(el.tagName || '').toLowerCase();
        var role = String(el.getAttribute('role') || '').toLowerCase();
        var text = '';

        if (tag === 'input' || tag === 'textarea' || tag === 'select') {
          text = summarizeFormFieldForReading(el);
        } else if (tag === 'button' || role === 'button') {
          text = 'Button: ' + normalizeText(el.innerText || el.textContent || el.getAttribute('aria-label') || '', 220);
        } else if (tag === 'a') {
          text = 'Link: ' + normalizeText(el.innerText || el.textContent || '', 220);
        } else if (/^h[1-4]$/.test(tag)) {
          text = tag.toUpperCase() + ': ' + normalizeText(el.innerText || el.textContent || '', 260);
        } else if (tag === 'label') {
          text = 'Label: ' + normalizeText(el.innerText || el.textContent || '', 220);
        } else {
          text = normalizeText(el.innerText || el.textContent || '', 420);
        }

        pushUnique(out, seen, text, 650);
      }
      return out;
    } catch (e) {
      return [];
    }
  }

  function buildSections(){
    var sections = [];
    function pushSection(name, content){
      var clean = normalizeText(content, 3800);
      if (clean) sections.push({ name: name, content: clean });
    }
    var h1 = normalizeText((document.querySelector('h1') || {}).innerText || '', 180);
    var subtitle = normalizeText((document.querySelector('h2, p') || {}).innerText || '', 280);
    var navItems = collectTexts(['aside a', 'nav a', '[role="navigation"] a', 'aside button'], 12);
    var headings = collectTexts(['main h1', 'main h2', 'main h3', '[role="main"] h1', '[role="main"] h2', '[role="main"] h3'], 12);
    var buttons = collectTexts(['main button', '[role="main"] button', 'main [role="button"]'], 10);
    var listItems = collectTexts(['main li', '[role="main"] li', 'table tr'], 50);
    var cards = collectTexts(['main article', 'main section', 'main [class*="card"]', '[role="main"] [class*="card"]'], 8);

    if (h1) pushSection('Primary heading', h1);
    if (subtitle && subtitle !== h1) pushSection('Page summary', subtitle);
    if (navItems.length) pushSection('Navigation', navItems.join(' | '));
    if (headings.length) pushSection('Visible sections', headings.join(' | '));
    if (buttons.length) pushSection('Actions', buttons.join(' | '));
    if (listItems.length) pushSection('Rows and items', listItems.join(' | '));
    if (cards.length) pushSection('Cards and panels', cards.join(' | '));

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

  function cssEscapeValue(value){
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
    return String(value).replace(/[^a-zA-Z0-9_-]/g, function(ch){ return '\\\\' + ch; });
  }

  function isVisibleElement(el){
    if (!el || (el.closest && el.closest('#helix-widget-root'))) return false;
    var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
    if (style && (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0')) return false;
    var rect = el.getBoundingClientRect ? el.getBoundingClientRect() : null;
    return !rect || (rect.width > 0 && rect.height > 0);
  }

  function ensureAgentId(el, prefix){
    var attr = 'data-helix-agent-id';
    var id = el.getAttribute(attr);
    if (!id) {
      id = prefix + '_' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-4);
      el.setAttribute(attr, id);
    }
    return id;
  }

  function agentSelector(id){
    return '[data-helix-agent-id="' + cssEscapeValue(id) + '"]';
  }

  function textFromLabelElement(el){
    return normalizeText((el && (el.innerText || el.textContent)) || '', 180);
  }

  function labelForField(el){
    var aria = normalizeText(el.getAttribute('aria-label') || '', 180);
    if (aria) return aria;
    var labelledBy = el.getAttribute('aria-labelledby');
    if (labelledBy) {
      var parts = labelledBy.split(/\s+/).map(function(id){ return textFromLabelElement(document.getElementById(id)); }).filter(Boolean);
      if (parts.length) return normalizeText(parts.join(' '), 180);
    }
    if (el.id) {
      var label = document.querySelector('label[for="' + cssEscapeValue(el.id) + '"]');
      var labelText = textFromLabelElement(label);
      if (labelText) return labelText;
    }
    var wrappingLabel = el.closest ? el.closest('label') : null;
    var wrappingText = textFromLabelElement(wrappingLabel);
    if (wrappingText) return wrappingText;
    var parent = el.parentElement;
    if (parent) {
      var nearby = parent.querySelector('label');
      var nearbyText = textFromLabelElement(nearby);
      if (nearbyText) return nearbyText;
    }
    return normalizeText(el.getAttribute('name') || el.getAttribute('placeholder') || '', 180);
  }

  function isSearchField(el){
    var type = String(el.getAttribute('type') || el.type || '').toLowerCase();
    var nameId = [el.name, el.id].join(' ').toLowerCase();
    var joined = [type, nameId, el.getAttribute('role'), el.getAttribute('aria-label'), el.getAttribute('placeholder'), labelForField(el)].join(' ').toLowerCase();
    return type === 'search'
      || /\b(search|query|filter)\b/i.test(joined)
      || (/^(?:q|query|search)$/i.test(String(el.name || '').trim()) && /\b(search|query|filter)\b/i.test(joined))
      || (/^(?:q|query|search)$/i.test(String(el.id || '').trim()) && /\b(search|query|filter)\b/i.test(joined));
  }

  function isSensitiveField(el){
    var type = String(el.getAttribute('type') || el.type || '').toLowerCase();
    var joined = [type, el.name, el.id, el.getAttribute('autocomplete'), el.getAttribute('placeholder'), labelForField(el)].join(' ').toLowerCase();
    if ((type === 'button' || type === 'submit') && isCustomChoiceControl(el)) return false;
    if (['hidden', 'file', 'image', 'reset', 'button', 'submit'].indexOf(type) !== -1) return true;
    return /(credit|card|cc-|cvv|cvc|otp|one[-\s]?time|token|captcha|secret|ssn|social security|government id|passport|bank|routing)/i.test(joined);
  }

  function collectFieldOptions(el){
    var options = [];
    var seen = {};
    function pushOption(value, label){
      var cleanValue = normalizeText(value, 300);
      var cleanLabel = normalizeText(label, 300);
      var key = (cleanValue || cleanLabel).toLowerCase();
      if (!key || seen[key]) return;
      seen[key] = true;
      options.push({ value: cleanValue || cleanLabel, label: cleanLabel || cleanValue });
    }

    if (el.tagName && el.tagName.toLowerCase() === 'select') {
      for (var i = 0; i < el.options.length; i++) {
        pushOption(el.options[i].value, el.options[i].text);
      }
    }

    var owns = el.getAttribute && (el.getAttribute('aria-controls') || el.getAttribute('aria-owns'));
    if (owns) {
      owns.split(/\s+/).forEach(function(id){
        var list = document.getElementById(id);
        if (!list) return;
        var nodes = list.querySelectorAll('[role="option"],[role="menuitem"],li,button,[data-value]');
        for (var i = 0; i < nodes.length && options.length < 40; i++) {
          var option = nodes[i];
          if (!isVisibleElement(option)) continue;
          pushOption(option.getAttribute('data-value') || option.getAttribute('value') || option.textContent, option.innerText || option.textContent);
        }
      });
    }

    return options.slice(0, 40);
  }

  function isCustomChoiceControl(el){
    var role = String(el.getAttribute('role') || '').toLowerCase();
    var popup = String(el.getAttribute('aria-haspopup') || '').toLowerCase();
    return role === 'combobox' || role === 'listbox' || popup === 'listbox' || popup === 'menu' || popup === 'true';
  }

  function fieldCurrentValue(el, tag, type){
    if (type === 'checkbox' || type === 'radio') return normalizeText(el.value || '', 300);
    if (tag === 'select' || 'value' in el) return normalizeText(el.value || '', 500);
    if (el.isContentEditable) return normalizeText(el.innerText || el.textContent || '', 500);
    return normalizeText(el.getAttribute('aria-valuetext') || el.getAttribute('data-value') || el.innerText || el.textContent || '', 500);
  }

  function serializeFormField(el){
    if (!isVisibleElement(el) || isSensitiveField(el) || isSearchField(el)) return null;
    var tag = String(el.tagName || '').toLowerCase();
    var role = String(el.getAttribute('role') || '').toLowerCase();
    var type = String(el.getAttribute('type') || el.type || (isCustomChoiceControl(el) ? 'select' : tag)).toLowerCase();
    var editable = !!el.isContentEditable;
    if ((type === 'button' || type === 'submit') && !isCustomChoiceControl(el) && !editable) return null;
    var fieldId = ensureAgentId(el, 'field');
    return {
      id: fieldId,
      selector: agentSelector(fieldId),
      tag: tag,
      type: type,
      role: role,
      name: normalizeText(el.getAttribute('name') || '', 200),
      label: labelForField(el),
      ariaLabel: normalizeText(el.getAttribute('aria-label') || '', 200),
      placeholder: normalizeText(el.getAttribute('placeholder') || '', 200),
      required: !!(el.required || el.getAttribute('aria-required') === 'true'),
      disabled: !!(el.disabled || el.getAttribute('aria-disabled') === 'true'),
      readOnly: !!(el.readOnly || el.getAttribute('aria-readonly') === 'true'),
      contentEditable: editable,
      value: fieldCurrentValue(el, tag, type),
      checked: !!(el.checked || el.getAttribute('aria-checked') === 'true'),
      options: collectFieldOptions(el)
    };
  }

  function buildFormRecord(container, controls, fallbackLabel){
    if (!container || !isVisibleElement(container)) return null;
    var fields = [];
    for (var i = 0; i < controls.length && fields.length < 40; i++) {
      var field = serializeFormField(controls[i]);
      if (field) fields.push(field);
    }
    if (!fields.length) return null;

    var formId = ensureAgentId(container, 'form');
    var submit = container.querySelector ? container.querySelector('button[type="submit"],input[type="submit"],button:not([type])') : null;
    var submitSelector = null;
    if (submit && isVisibleElement(submit)) {
      submitSelector = agentSelector(ensureAgentId(submit, 'submit'));
    }

    return {
      id: formId,
      selector: agentSelector(formId),
      label: normalizeText(container.getAttribute('aria-label') || container.getAttribute('name') || textFromLabelElement(container.querySelector && container.querySelector('legend,h1,h2,h3')) || fallbackLabel || document.title || 'Form', 300),
      submitSelector: submitSelector,
      fields: fields
    };
  }

  function collectFormContext(){
    try {
      var forms = [];
      var used = [];
      var formNodes = Array.prototype.slice.call(document.querySelectorAll('form'));
      for (var i = 0; i < formNodes.length && forms.length < 5; i++) {
        var form = formNodes[i];
        var controls = Array.prototype.slice.call(form.querySelectorAll('input,textarea,select,[contenteditable="true"],[role="textbox"],[role="combobox"],[role="listbox"],button[aria-haspopup]'));
        var record = buildFormRecord(form, controls, 'Form');
        if (!record) continue;
        forms.push(record);
        for (var u = 0; u < controls.length; u++) used.push(controls[u]);
      }

      if (forms.length < 5) {
        var looseControls = Array.prototype.slice.call(document.querySelectorAll('input,textarea,select,[contenteditable="true"],[role="textbox"],[role="combobox"],[role="listbox"],button[aria-haspopup]'))
          .filter(function(el){ return used.indexOf(el) === -1 && !(el.closest && el.closest('#helix-widget-root')); });
        if (looseControls.length) {
          var container = null;
          for (var c = 0; c < looseControls.length; c++) {
            var candidate = looseControls[c].closest && looseControls[c].closest('main,section,article,[role="main"],[class*="form"],[class*="signup"],[class*="login"],[class*="auth"],body');
            if (candidate && isVisibleElement(candidate)) { container = candidate; break; }
          }
          var record = buildFormRecord(container || document.body, looseControls, 'Visible fields');
          if (record) forms.push(record);
        }
      }

      return { forms: forms.slice(0, 5), scannedAt: new Date().toISOString() };
    } catch (e) {
      return { forms: [], scannedAt: new Date().toISOString() };
    }
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
        pageOutline: buildPageOutline(),
        pageContent: bodyText,
        scrapedAt: new Date().toISOString()
      };
    }catch(e){ return { pageTitle:'', pageName:'', pageUrl:window.location.href, pageLinks:[], pageContent:'', pageSections:[], pageOutline:[], scrapedAt:new Date().toISOString() }; }
  }

  function buildPageSignature(ctx){
    return JSON.stringify([
      ctx.pageUrl || '',
      ctx.pageTitle || '',
      ctx.pageName || '',
      ((ctx.pageLinks || []).map(function(link){ return [link.label || '', link.url || ''].join('='); }).join('|')).substring(0, 600),
      ((ctx.pageOutline || []).join('|')).substring(0, 2200),
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

    window.addEventListener('resize', function(){
      var openPanel = root.querySelector('.helix-panel');
      if (openPanel) fitHeadline(openPanel);
    });
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

  function srgbChannel(value){
    var v = value / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  }

  function relativeLuminance(hex){
    var c = hexToRgb(hex);
    return (0.2126 * srgbChannel(c.r)) + (0.7152 * srgbChannel(c.g)) + (0.0722 * srgbChannel(c.b));
  }

  function contrastRatio(a, b){
    var la = relativeLuminance(a);
    var lb = relativeLuminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }

  /**
   * Use the tinted tone when it is legible on the given background, otherwise fall back
   * to plain black or white — a bot themed near-white must not get pale-on-pale text.
   */
  function readableText(background, preferred){
    if (contrastRatio(preferred, background) >= 4.5) return preferred;
    return contrastRatio('#0f172a', background) >= contrastRatio('#ffffff', background) ? '#0f172a' : '#ffffff';
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

  function px(value){
    return (Math.round(value * 100) / 100) + 'px';
  }

  function setPanelVar(panel, name, value){
    panel.style.setProperty(name, value);
  }

  function nextFrame(fn){
    return window.requestAnimationFrame ? window.requestAnimationFrame(fn) : setTimeout(fn, 16);
  }

  function cancelFrame(handle){
    if (handle == null) return;
    if (window.cancelAnimationFrame) window.cancelAnimationFrame(handle);
    else clearTimeout(handle);
  }

  /**
   * Scroll sets a target; the header eases toward it frame by frame so the collapse
   * never snaps or stutters, however coarse the scroll events are.
   */
  function updateCompactHeader(panel, scrollTop){
    if (!panel) return;
    var body = panel.querySelector('#helix-body');
    var maxScroll = body ? Math.max(0, body.scrollHeight - body.clientHeight) : 0;
    var collapseDistance = Math.max(72, Math.min(120, maxScroll || 120));
    state.headerTargetProgress = maxScroll <= 2 ? 0 : clamp(Number(scrollTop || 0) / collapseDistance, 0, 1);
    animateHeader(panel);
  }

  function animateHeader(panel){
    if (state.headerFrame != null) return;
    var tick = function(){
      state.headerFrame = null;
      if (!panel || (panel.isConnected === false)) return;
      var target = state.headerTargetProgress;
      var next = state.headerProgress + ((target - state.headerProgress) * 0.2);
      if (Math.abs(target - next) < 0.002) next = target;
      applyHeaderProgress(panel, next);
      if (next !== target) state.headerFrame = nextFrame(tick);
    };
    state.headerFrame = nextFrame(tick);
  }

  function applyHeaderProgress(panel, value){
    if (!panel) return;
    state.headerProgress = value;
    // Smoothstep so the ends of the collapse ease instead of arriving abruptly.
    var progress = value * value * (3 - (2 * value));
    state.headerCompact = value >= 0.995;
    if (state.headerCompact) panel.classList.add('is-compact');
    else panel.classList.remove('is-compact');

    setPanelVar(panel, '--helix-header-padding', px(10 - (2 * progress)) + ' 12px ' + px(12 - (7 * progress)));
    setPanelVar(panel, '--helix-header-gap', px(8 - (4 * progress)));
    setPanelVar(panel, '--helix-logo-size', px(36 + (4 * progress)));
    setPanelVar(panel, '--helix-logo-radius', px(12 - progress));
    setPanelVar(panel, '--helix-brand-label-size', px(9 + (3 * progress)));
    setPanelVar(panel, '--helix-brand-name-size', px(16 + (4 * progress)));
    setPanelVar(panel, '--helix-header-copy-opacity', String(clamp(1 - (progress * 1.15), 0, 1)));
    setPanelVar(panel, '--helix-header-copy-height', px(220 * (1 - progress)));
    setPanelVar(panel, '--helix-header-copy-y', px(-12 * progress));
    setPanelVar(panel, '--helix-status-opacity', String(clamp(1 - (progress * 1.2), 0, 1)));
    setPanelVar(panel, '--helix-status-height', px(56 * (1 - progress)));
    setPanelVar(panel, '--helix-status-padding', px(10 * (1 - progress)) + ' 14px');
    setPanelVar(panel, '--helix-status-y', px(-12 * progress));
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

  function setNativeValue(el, value){
    var proto = HTMLInputElement.prototype;
    if (el instanceof HTMLTextAreaElement) proto = HTMLTextAreaElement.prototype;
    else if (el instanceof HTMLSelectElement) proto = HTMLSelectElement.prototype;
    var descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) descriptor.set.call(el, value);
    else el.value = value;
  }

  function dispatchFieldEvents(el){
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.dispatchEvent(new Event('blur', { bubbles: true }));
  }

  function normalizeComparable(value){
    return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function findMatchingRadio(el, desired){
    var form = el.form || (el.closest ? el.closest('form') : null);
    var name = el.name;
    var radios = name && form ? form.querySelectorAll('input[type="radio"][name="' + cssEscapeValue(name) + '"]') : [el];
    var target = normalizeComparable(desired);
    for (var i = 0; i < radios.length; i++) {
      var radio = radios[i];
      var label = labelForField(radio);
      if (normalizeComparable(radio.value) === target || normalizeComparable(label) === target) return radio;
    }
    return el;
  }

  function chooseOpenOption(value){
    var wanted = normalizeComparable(value);
    if (!wanted) return false;
    var nodes = document.querySelectorAll('[role="option"],[role="menuitem"],[cmdk-item],li,button,[data-value]');
    for (var i = 0; i < nodes.length; i++) {
      var option = nodes[i];
      if (!option || (option.closest && option.closest('#helix-widget-root')) || !isVisibleElement(option)) continue;
      var label = normalizeComparable(option.innerText || option.textContent || option.getAttribute('aria-label') || '');
      var optionValue = normalizeComparable(option.getAttribute('data-value') || option.getAttribute('value') || '');
      if (label === wanted || optionValue === wanted || (label && label.indexOf(wanted) !== -1)) {
        option.click();
        return true;
      }
    }
    return false;
  }

  function fillCustomChoiceControl(el, value){
    el.focus && el.focus();
    el.click && el.click();
    window.setTimeout(function(){ chooseOpenOption(value); dispatchFieldEvents(el); }, 80);
    return true;
  }

  function fillContentEditable(el, value){
    el.focus && el.focus();
    el.textContent = value;
    dispatchFieldEvents(el);
    return true;
  }

  function fillOneField(field){
    if (!field || !field.selector) return false;
    var el = document.querySelector(field.selector);
    if (!el || !isVisibleElement(el) || el.disabled || el.readOnly || el.getAttribute('aria-disabled') === 'true' || el.getAttribute('aria-readonly') === 'true' || isSensitiveField(el)) return false;
    var tag = String(el.tagName || '').toLowerCase();
    var role = String(el.getAttribute('role') || '').toLowerCase();
    var type = String(el.getAttribute('type') || el.type || (isCustomChoiceControl(el) ? 'select' : tag)).toLowerCase();
    var value = field.value == null ? '' : String(field.value);

    if (el.isContentEditable || role === 'textbox') {
      return fillContentEditable(el, value);
    }

    if (isCustomChoiceControl(el) && tag !== 'select') {
      return fillCustomChoiceControl(el, value);
    }

    if (type === 'checkbox') {
      el.checked = field.checked === null || typeof field.checked === 'undefined' ? ['true', 'yes', 'on', '1'].indexOf(normalizeComparable(value)) !== -1 : !!field.checked;
      dispatchFieldEvents(el);
      return true;
    }

    if (type === 'radio') {
      var radio = findMatchingRadio(el, value || el.value);
      radio.checked = true;
      dispatchFieldEvents(radio);
      return true;
    }

    if (tag === 'select') {
      var wanted = normalizeComparable(value);
      var matched = false;
      for (var i = 0; i < el.options.length; i++) {
        var option = el.options[i];
        if (normalizeComparable(option.value) === wanted || normalizeComparable(option.text) === wanted) {
          el.selectedIndex = i;
          setNativeValue(el, option.value);
          matched = true;
          break;
        }
      }
      if (!matched) setNativeValue(el, value);
      dispatchFieldEvents(el);
      return true;
    }

    setNativeValue(el, value);
    dispatchFieldEvents(el);
    return true;
  }

  function applyAgentAction(action){
    if (!action || action.type !== 'fill_form') return null;
    var filled = 0;
    var fields = Array.isArray(action.fields) ? action.fields : [];
    for (var i = 0; i < fields.length; i++) {
      if (fillOneField(fields[i])) filled++;
    }

    var missing = Array.isArray(action.missing) ? action.missing.filter(Boolean) : [];
    if (action.submit) {
      state.pendingFormSubmit = {
        formSelector: action.formSelector || null,
        submitSelector: action.submitSelector || null
      };
    }

    if (missing.length) {
      return { message: 'I filled ' + filled + ' field' + (filled === 1 ? '' : 's') + '. I still need: ' + missing.join(', ') + '.' };
    }
    if (filled > 0 && action.submit) {
      return { message: 'I filled ' + filled + ' field' + (filled === 1 ? '' : 's') + '. Review the form, then tell me "submit" if you want me to submit it.' };
    }
    if (filled > 0) {
      return { message: 'I filled ' + filled + ' field' + (filled === 1 ? '' : 's') + '. Review the form before submitting.' };
    }
    return { message: 'I found the form, but I could not safely fill any fields.' };
  }

  function submitPendingForm(){
    var pending = state.pendingFormSubmit || {};
    var button = pending.submitSelector ? document.querySelector(pending.submitSelector) : null;
    var form = pending.formSelector ? document.querySelector(pending.formSelector) : null;
    if (!form && button && button.form) form = button.form;
    if (button && isVisibleElement(button) && !button.disabled) {
      button.click();
      return true;
    }
    if (form && typeof form.requestSubmit === 'function') {
      form.requestSubmit();
      return true;
    }
    return false;
  }

  function handlePendingFormConfirmation(msg){
    if (!state.pendingFormSubmit) return false;
    var text = normalizeComparable(msg);
    var confirms = /^(yes|y|submit|send|go ahead|confirm|do it|okay|ok|proceed)(\b|$)/.test(text);
    var cancels = /^(no|n|cancel|stop|never mind|nevermind)(\b|$)/.test(text);
    if (!confirms && !cancels) return false;

    state.messages.push({ role: 'user', content: msg });
    state.draftMessage = '';
    if (confirms) {
      var submitted = submitPendingForm();
      state.messages.push({ role: 'assistant', content: submitted ? 'Submitted the form.' : 'I could not submit the form from here. Please use the page submit button.' });
    } else {
      state.messages.push({ role: 'assistant', content: 'I will not submit it. You can still edit the form manually.' });
    }
    state.pendingFormSubmit = null;
    persistSession();
    render();
    return true;
  }

  function compactHistoryMessage(message){
    var role = message && message.role === 'assistant' ? 'assistant' : 'user';
    var content = normalizeText((message && message.content) || '', 3500);
    return content ? { role: role, content: content } : null;
  }

  function send(msg){
    var visitorEmail = getDraftOrStoredEmail();
    if (!canSendMessage(msg, visitorEmail)) return;
    if (handlePendingFormConfirmation(msg)) return;
    state.sending = true;
    state.messages.push({ role: 'user', content: msg });
    state.draftMessage = '';
    persistSession();
    render();
    var history = state.messages.slice(-9, -1).map(compactHistoryMessage).filter(Boolean);
    if (!state.pageContext) refreshPageContext(true);
    var pageCtx = state.pageContext || getPageContext();
    var formCtx = collectFormContext();
    state.formContext = formCtx;
    fetch(ORIGIN + '/api/public/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'text/plain;charset=UTF-8',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ publicKey: publicKey, message: msg, conversationId: state.conversationId, visitorId: visitorId, visitorEmail: visitorEmail, history: history, pageContext: pageCtx, formContext: formCtx })
    }).then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j};});}).then(function(res){
      state.sending = false;
      if (!res.ok) { state.messages.push({ role:'assistant', content: res.j.error || res.j.message || 'Sorry, something went wrong.' }); }
      else {
        state.conversationId = res.j.conversationId;
        var actionResult = res.j.agentAction ? applyAgentAction(res.j.agentAction) : null;
        state.messages.push({ role:'assistant', content: actionResult && actionResult.message ? actionResult.message : res.j.reply });
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

  function shouldMiniCta(){
    var answers = 0;
    for (var i = 0; i < state.messages.length; i++) {
      if (state.messages[i] && state.messages[i].role === 'assistant' && ++answers > 1) return true;
    }
    return false;
  }

  /**
   * The welcome line is kept to a single row; shrink the type until it fits rather than
   * wrapping, with a readable floor (past that the CSS ellipsis takes over).
   */
  function fitHeadline(panel){
    var el = panel && panel.querySelector('.helix-headline');
    if (!el) return;
    var max = 24, min = 14;
    setPanelVar(panel, '--helix-headline-size', px(max));
    var available = el.clientWidth;
    if (!available || el.scrollWidth <= available) return;
    var size = clamp((available / el.scrollWidth) * max, min, max);
    setPanelVar(panel, '--helix-headline-size', px(size));
    // Glyph widths do not scale perfectly linearly, so nudge down if it still overflows.
    for (var i = 0; i < 8 && size > min && el.scrollWidth > el.clientWidth + 1; i++) {
      size = Math.max(min, size - 0.5);
      setPanelVar(panel, '--helix-headline-size', px(size));
    }
  }

  /**
   * Footer credit: the bot's own text and up to three co-brand logos when configured,
   * otherwise the default Helix credit.
   */
  /** Bubble logo zoom, as a CSS scale factor clamped to the range the dashboard offers. */
  function logoScale(){
    var pct = Number(state.bot && state.bot.logo_scale);
    if (!isFinite(pct) || pct <= 0) pct = 100;
    return (Math.max(50, Math.min(200, pct)) / 100).toFixed(2);
  }

  function buildFooterMarkup(){
    var footer = state.bot && state.bot.footer;
    var text = footer ? String(footer.text || '').trim() : '';
    var logos = (footer && footer.logos) || [];
    var safeLogos = [];

    for (var i = 0; i < logos.length && safeLogos.length < 3; i++) {
      var entry = logos[i] || '';
      var url = String(typeof entry === 'string' ? entry : (entry.url || '')).trim();
      if (!/^https?:\/\//i.test(url)) continue;
      var pct = Number(typeof entry === 'string' ? 100 : entry.scale);
      if (!isFinite(pct) || pct <= 0) pct = 100;
      // Height rather than transform, so a resized logo still occupies its real space in the row.
      var height = Math.round(FOOTER_LOGO_BASE_PX * (Math.max(50, Math.min(200, pct)) / 100));
      safeLogos.push({ url: url, height: height });
    }

    if (!text && !safeLogos.length) {
      return '<div class="helix-foot">Powered by <a href="' + ORIGIN + '">Helix</a></div>';
    }

    var markup = '<div class="helix-foot">';
    if (text) markup += '<span>' + escapeHtml(text) + '</span>';
    if (safeLogos.length) {
      markup += '<span class="helix-foot-logos">';
      for (var j = 0; j < safeLogos.length; j++) {
        markup += '<img src="' + escapeHtml(safeLogos[j].url) + '" alt="" style="height:' + safeLogos[j].height + 'px !important;max-height:' + safeLogos[j].height + 'px !important"/>';
      }
      markup += '</span>';
    }

    return markup + '</div>';
  }

  function safeCta(){
    var cta = state.bot && state.bot.cta;
    if (!cta) return null;
    var url = String(cta.url || '').trim();
    // Never render anything but a plain http(s) destination.
    if (!/^https?:\/\//i.test(url)) return null;
    return { url: url, label: String(cta.label || '').trim() || 'Visit website' };
  }

  function suggestedFaqs(){
    var list = (state.bot && state.bot.faqs) || [];
    if (!list.length) return [];
    for (var i = 0; i < state.messages.length; i++) {
      if (state.messages[i] && state.messages[i].role === 'user') return [];
    }
    var out = [];
    for (var j = 0; j < list.length && out.length < 5; j++) {
      var q = String(list[j] || '').trim();
      if (q) out.push(q);
    }
    return out;
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
    var footerMarkup = buildFooterMarkup();
    var cta = safeCta();
    var ctaMiniClass = state.ctaMini ? ' is-mini' : '';
    var ctaMarkup = cta
      ? '<div class="helix-cta' + ctaMiniClass + '"><a class="helix-cta-link' + ctaMiniClass + '" href="' + escapeHtml(cta.url) + '" target="_blank" rel="noopener noreferrer nofollow">' +
          '<span>' + escapeHtml(cta.label) + '</span>' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"/></svg>' +
        '</a></div>'
      : '';
    root.innerHTML = '';
    root.appendChild(style);

    var bubble = document.createElement('button');
    bubble.className = 'helix-bubble' + ((state.open || state.closing) ? ' is-active' : '');
    bubble.setAttribute('style', pos + ';background:' + (state.bot.logo_url ? 'transparent' : theme.bubbleGradient) + ';color:' + theme.primaryText + ';--helix-logo-scale:' + logoScale());
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
        ctaMarkup +
        '<div class="helix-composer"><div class="helix-composer-card">' +
          emailSection +
          '<form class="helix-form" id="helix-form"><textarea class="helix-input" id="helix-input" placeholder="Ask anything..." autocomplete="off" rows="1"></textarea><button class="helix-send" id="helix-send" type="submit" style="background:' + theme.userGradient + ';color:' + theme.primaryText + '">Send</button></form>' +
        '</div></div>' +
        footerMarkup +
      '</div>';
    root.appendChild(panel);
    if (!state.closing && !state.panelAnimatedIn) {
      state.panelAnimatedIn = true;
    }

    // Suggestion chips tint toward the bot's own colour instead of inheriting the host site's buttons.
    setPanelVar(panel, '--helix-faq-bg', 'rgba(255,255,255,0.98)');
    setPanelVar(panel, '--helix-faq-border', rgba(theme.primary, 0.18));
    setPanelVar(panel, '--helix-faq-color', '#0f172a');
    setPanelVar(panel, '--helix-faq-hover-bg', theme.primaryMist);
    setPanelVar(panel, '--helix-faq-hover-border', rgba(theme.primary, 0.45));
    setPanelVar(panel, '--helix-faq-hover-color', readableText(theme.primaryMist, theme.primaryDeep));
    setPanelVar(panel, '--helix-faq-active-bg', theme.primarySoft);
    setPanelVar(panel, '--helix-faq-active-color', readableText(theme.primarySoft, mixHex(theme.primary, '#0f172a', 0.6)));

    // render() rebuilds the panel, so the collapse is applied one frame after insertion —
    // the element needs a starting frame in its old size for the transition to run at all.
    var ctaLinkEl = panel.querySelector('.helix-cta-link');
    if (ctaLinkEl) {
      var wantsMiniCta = shouldMiniCta();
      if (state.ctaMini !== wantsMiniCta) {
        nextFrame(function(){
          if (ctaLinkEl.isConnected === false) return;
          var ctaWrapEl = panel.querySelector('.helix-cta');
          if (wantsMiniCta) {
            ctaLinkEl.classList.add('is-mini');
            if (ctaWrapEl) ctaWrapEl.classList.add('is-mini');
          } else {
            ctaLinkEl.classList.remove('is-mini');
            if (ctaWrapEl) ctaWrapEl.classList.remove('is-mini');
          }
          state.ctaMini = wantsMiniCta;
        });
      }
    }

    // Outlined rather than filled, so the link does not compete with the Send button.
    setPanelVar(panel, '--helix-cta-bg', '#ffffff');
    setPanelVar(panel, '--helix-cta-border', rgba(theme.primary, 0.35));
    setPanelVar(panel, '--helix-cta-color', readableText('#ffffff', theme.primaryDeep));
    setPanelVar(panel, '--helix-cta-hover-bg', theme.primaryMist);
    setPanelVar(panel, '--helix-cta-hover-color', readableText(theme.primaryMist, theme.primaryDeep));

    fitHeadline(panel);

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
    var faqs = suggestedFaqs();
    if (faqs.length && !state.sending) {
      var faqWrap = document.createElement('div');
      faqWrap.className = 'helix-faqs';
      var faqLabel = document.createElement('div');
      faqLabel.className = 'helix-faqs-label';
      faqLabel.textContent = 'Frequently asked';
      faqWrap.appendChild(faqLabel);
      faqs.forEach(function(question){
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'helix-faq-chip';
        chip.textContent = question;
        chip.onclick = function(){
          if (state.sending) return;
          var emailField = panel.querySelector('#helix-email');
          var chipEmail = emailField ? emailField.value.trim() : getDraftOrStoredEmail();
          if (collectEmail && !isValidEmail(chipEmail)) {
            if (emailField) emailField.focus();
            syncComposerState(panel);
            return;
          }
          if (collectEmail) persistVisitorEmail(chipEmail);
          send(question);
        };
        faqWrap.appendChild(chip);
      });
      body.appendChild(faqWrap);
    }
    if (state.sending) {
      var t = document.createElement('div');
      t.className = 'helix-msg bot helix-typing-wrapper';
      t.innerHTML = '<div class="helix-typing"><span></span><span></span><span></span></div>';
      t.style.padding = '0';
      body.appendChild(t);
    }
    body.scrollTop = body.scrollHeight;
    body.onscroll = function(){ updateCompactHeader(panel, body.scrollTop); };
    cancelFrame(state.headerFrame);
    state.headerFrame = null;
    // Match the new panel to the current scroll position before easing takes over.
    state.headerTargetProgress = state.headerProgress;
    applyHeaderProgress(panel, state.headerProgress);
    updateCompactHeader(panel, body.scrollTop);

    panel.querySelector('.helix-close').onclick = function(){ closeWidget(); };
    var emailInput = panel.querySelector('#helix-email');
    var messageInput = panel.querySelector('#helix-input');
    function syncMessageInputHeight(){
      if (!messageInput) return;
      if ((messageInput.value || '').indexOf('\n') !== -1) messageInput.classList.add('is-expanded');
      else messageInput.classList.remove('is-expanded');
    }
    messageInput.value = state.draftMessage || '';
    syncMessageInputHeight();
    messageInput.onkeydown = function(e){
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        var form = panel.querySelector('#helix-form');
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      }
    };
    messageInput.oninput = function(){ state.draftMessage = messageInput.value; syncMessageInputHeight(); persistSession(); };
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
      syncMessageInputHeight();
      syncComposerState(panel);
      send(v);
      return false;
    };
    messageInput.oninput = function(){ state.draftMessage = messageInput.value; syncMessageInputHeight(); persistSession(); syncComposerState(panel); };
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
        if (! empty($pageContext['pageOutline']) && is_array($pageContext['pageOutline'])) {
            $lines[] = 'Top-to-Bottom Page Reading Order:';
            foreach (array_slice($pageContext['pageOutline'], 0, 100) as $item) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $lines[] = '- ' . $text;
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
