<?php

namespace App\Jobs;

use App\Models\DocumentChunk;
use App\Models\KnowledgeSource;
use App\Support\OllamaEmbeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Embeds a knowledge source's chunks off the request cycle.
 *
 * Ingestion used to embed every chunk inline, so uploading a 200-chunk PDF meant
 * 200 sequential Ollama calls with the browser hanging on the response. The rows
 * are now written immediately and vectorised here; the source stays in
 * "processing" (and therefore out of retrieval) until this finishes.
 */
class EmbedKnowledgeSource implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Large sources legitimately take a while; well above the per-chunk timeout. */
    public int $timeout = 3600;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $sourceId,
        /** Rebuild vectors that already match the current recipe, not just missing ones. */
        public bool $force = false,
    ) {
    }

    public function handle(): void
    {
        $source = KnowledgeSource::find($this->sourceId);

        if (! $source) {
            return;
        }

        $recipe = OllamaEmbeddings::recipe();
        $ids = $this->staleChunkIds($source->id, $recipe);

        if ($ids->isEmpty()) {
            $this->markReady($source);

            return;
        }

        $batchSize = max(1, (int) config('models.embeddings.batch_size', 25));
        $embedded = 0;
        $failed = 0;

        foreach ($ids->chunk($batchSize) as $group) {
            $chunks = DocumentChunk::query()->whereIn('id', $group->all())->get();

            foreach ($chunks as $chunk) {
                $vector = OllamaEmbeddings::embed((string) $chunk->content, OllamaEmbeddings::TASK_DOCUMENT);

                if (! $vector) {
                    $failed++;

                    continue;
                }

                $chunk->forceFill([
                    'embedding' => OllamaEmbeddings::toPgVector($vector),
                    'embedding_recipe' => $recipe,
                ])->save();

                $embedded++;
            }
        }

        // Nothing embedded at all points at Ollama being down or the model missing,
        // which is worth surfacing in the UI rather than leaving a silently empty source.
        if ($embedded === 0 && $failed > 0) {
            $this->markFailed($source, "Could not embed any of the {$failed} chunk(s). Check that the embedding model is available.");

            return;
        }

        if ($failed > 0) {
            Log::warning('Some chunks could not be embedded.', [
                'source_id' => $source->id,
                'embedded' => $embedded,
                'failed' => $failed,
            ]);
        }

        $this->markReady($source);
    }

    public function failed(\Throwable $e): void
    {
        $source = KnowledgeSource::find($this->sourceId);

        $source?->update([
            'status' => 'error',
            'error_message' => Str::limit($e->getMessage(), 1000),
        ]);
    }

    /**
     * Chunks whose vector is missing, or was built by a superseded model/prefix pair.
     */
    private function staleChunkIds(string $sourceId, string $recipe): \Illuminate\Support\Collection
    {
        $query = DocumentChunk::query()
            ->where('source_id', $sourceId)
            ->orderBy('chunk_index');

        if (! $this->force) {
            $query->where(function ($q) use ($recipe) {
                $q->whereNull('embedding')
                    ->orWhereNull('embedding_recipe')
                    ->orWhere('embedding_recipe', '!=', $recipe);
            });
        }

        return $query->pluck('id');
    }

    private function markReady(KnowledgeSource $source): void
    {
        $source->update([
            'status' => 'ready',
            'error_message' => null,
            'chunk_count' => DocumentChunk::query()->where('source_id', $source->id)->count(),
        ]);
    }

    private function markFailed(KnowledgeSource $source, string $message): void
    {
        $source->update([
            'status' => 'error',
            'error_message' => Str::limit($message, 1000),
        ]);
    }
}
