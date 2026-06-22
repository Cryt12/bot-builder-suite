<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\DocumentChunk;
use App\Support\OllamaEmbeddings;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('knowledge:embed-missing {--limit=200}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $updated = 0;
    $skipped = 0;

    DocumentChunk::query()
        ->whereNull('embedding')
        ->orderBy('created_at')
        ->limit($limit)
        ->get()
        ->each(function (DocumentChunk $chunk) use (&$updated, &$skipped) {
            $embedding = OllamaEmbeddings::embed($chunk->content);

            if (! $embedding) {
                $skipped++;
                return;
            }

            $chunk->embedding = OllamaEmbeddings::toPgVector($embedding);
            $chunk->save();
            $updated++;
        });

    $this->info("Embedded {$updated} chunk(s). Skipped {$skipped} chunk(s).");
})->purpose('Generate embeddings for existing document chunks that do not have vectors');
