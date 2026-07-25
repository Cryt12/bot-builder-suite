<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaEmbeddings
{
    /** Corpus text being indexed into document_chunks. */
    public const TASK_DOCUMENT = 'document';

    /** A visitor question being matched against the corpus. */
    public const TASK_QUERY = 'query';

    /** Dimension of the pgvector column document_chunks.embedding. */
    public const DIMENSION = 768;

    /**
     * Embed a passage. The task decides which prefix the model sees: nomic-embed-text
     * scores query-to-passage pairs far better when both sides are tagged the way it
     * was trained, so callers must say which side they are on.
     */
    public static function embed(string $text, string $task = self::TASK_QUERY): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $url = rtrim((string) config('models.embeddings.ollama.url'), '/') . '/api/embeddings';
        $model = (string) config('models.embeddings.ollama.model');
        $prompt = self::prefix($task) . mb_substr($text, 0, (int) config('models.embeddings.max_input_chars', 4000));

        try {
            $response = Http::timeout((int) config('models.embeddings.timeout', 30))->post($url, [
                'model' => $model,
                'prompt' => $prompt,
                'keep_alive' => (string) config('models.embeddings.ollama.keep_alive', '30m'),
            ]);

            if (! $response->ok()) {
                Log::warning('Ollama embedding request failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $embedding = $response->json('embedding');

            if (! is_array($embedding) || count($embedding) !== self::DIMENSION) {
                Log::warning('Ollama embedding response had an unexpected dimension.', [
                    'dimension' => is_array($embedding) ? count($embedding) : null,
                    'model' => $model,
                ]);

                return null;
            }

            return array_map(static fn ($value) => (float) $value, $embedding);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Embed a visitor question, reusing a recent vector for the same wording.
     * Chat traffic repeats the same handful of questions constantly, and each
     * cache hit removes one Ollama round trip from the critical path.
     */
    public static function embedQueryCached(string $text): ?array
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));

        if ($normalized === '') {
            return null;
        }

        $minutes = (int) config('models.embeddings.query_cache_minutes', 60);

        if ($minutes <= 0) {
            return self::embed($text, self::TASK_QUERY);
        }

        $key = 'embed:q:' . self::recipe() . ':' . sha1($normalized);

        // Cached separately from the null case: a failed embed must not be memoized.
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $embedding = self::embed($text, self::TASK_QUERY);

        if ($embedding) {
            Cache::put($key, $embedding, now()->addMinutes($minutes));
        }

        return $embedding;
    }

    /**
     * The prefix the given side of the comparison is embedded with.
     */
    public static function prefix(string $task): string
    {
        return (string) config(
            $task === self::TASK_DOCUMENT
                ? 'models.embeddings.document_prefix'
                : 'models.embeddings.query_prefix',
            ''
        );
    }

    /**
     * Identifies how a stored vector was produced. Vectors are only comparable when
     * they came from the same model and the same document prefix, so chunks tagged
     * with a stale recipe are the ones `knowledge:embed --all` needs to rebuild.
     */
    public static function recipe(): string
    {
        $model = (string) config('models.embeddings.ollama.model');

        return $model . '/' . substr(sha1(self::prefix(self::TASK_DOCUMENT)), 0, 8);
    }

    public static function toPgVector(array $embedding): string
    {
        return '[' . implode(',', array_map(static fn ($value) => rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.'), $embedding)) . ']';
    }
}
