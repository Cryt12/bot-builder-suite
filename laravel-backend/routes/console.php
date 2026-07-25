<?php

use App\Jobs\EmbedKnowledgeSource;
use App\Models\DocumentChunk;
use App\Models\KnowledgeSource;
use App\Support\OllamaEmbeddings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Rebuilds document vectors. Chunks record the model + task prefix they were
| embedded with, so a plain run only touches what is missing or stale and can be
| re-run safely after an interruption. Use --all after deliberately changing the
| embedding model or a prefix, since that invalidates every existing vector.
*/
Artisan::command('knowledge:embed {--all : Rebuild every vector, not just missing or stale ones} {--chatbot= : Limit to one chatbot id} {--source= : Limit to one source id} {--sync : Embed inline instead of dispatching to the queue}', function () {
    $force = (bool) $this->option('all');
    $recipe = OllamaEmbeddings::recipe();

    $sources = KnowledgeSource::query()
        ->when($this->option('chatbot'), fn ($q, $id) => $q->where('chatbot_id', $id))
        ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
        ->orderBy('created_at')
        ->get();

    if ($sources->isEmpty()) {
        $this->warn('No knowledge sources matched.');

        return 0;
    }

    $pending = DocumentChunk::query()
        ->whereIn('source_id', $sources->pluck('id'))
        ->when(! $force, fn ($q) => $q->where(fn ($inner) => $inner
            ->whereNull('embedding')
            ->orWhereNull('embedding_recipe')
            ->orWhere('embedding_recipe', '!=', $recipe)))
        ->count();

    if ($pending === 0) {
        $this->info("Everything is already embedded with recipe {$recipe}.");

        return 0;
    }

    $this->info("Recipe: {$recipe}");
    $this->info("{$pending} chunk(s) across {$sources->count()} source(s) to embed.");

    if ($this->option('sync')) {
        $bar = $this->output->createProgressBar($sources->count());
        $bar->start();

        foreach ($sources as $source) {
            (new EmbedKnowledgeSource($source->id, $force))->handle();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Done.');

        return 0;
    }

    foreach ($sources as $source) {
        EmbedKnowledgeSource::dispatch($source->id, $force);
    }

    $this->info("Dispatched {$sources->count()} job(s). Ensure a queue worker is running (helix-queue.service).");

    return 0;
})->purpose('Embed document chunks whose vectors are missing or built with a superseded model/prefix');

Artisan::command('knowledge:embed-missing', function () {
    $this->warn('knowledge:embed-missing is deprecated; running knowledge:embed instead.');

    return $this->call('knowledge:embed');
})->purpose('Deprecated alias for knowledge:embed');
