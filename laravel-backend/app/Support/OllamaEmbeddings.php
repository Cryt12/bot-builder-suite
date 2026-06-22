<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaEmbeddings
{
    public static function embed(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $url = rtrim((string) config('models.embeddings.ollama.url'), '/') . '/api/embeddings';
        $model = (string) config('models.embeddings.ollama.model');

        try {
            $response = Http::timeout((int) config('models.embeddings.timeout', 30))->post($url, [
                'model' => $model,
                'prompt' => mb_substr($text, 0, (int) config('models.embeddings.max_input_chars', 4000)),
            ]);

            if (! $response->ok()) {
                Log::warning('Ollama embedding request failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $embedding = $response->json('embedding');

            if (! is_array($embedding) || count($embedding) !== 768) {
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

    public static function toPgVector(array $embedding): string
    {
        return '[' . implode(',', array_map(static fn ($value) => rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.'), $embedding)) . ']';
    }
}
