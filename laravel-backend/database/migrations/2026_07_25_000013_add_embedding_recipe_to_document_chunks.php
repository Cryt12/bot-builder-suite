<?php

use App\Support\OllamaEmbeddings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which model + task prefix produced each stored vector. Vectors built
     * with different recipes are not comparable, so this is what lets the re-embed
     * command find stale rows and resume without redoing finished work.
     */
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->string('embedding_recipe', 64)->nullable()->after('embedding');
        });

        // Rows that already have a vector were produced by the configured model with
        // no task prefix, which is exactly the current recipe -- tag them so the
        // re-embed pass does not needlessly rebuild the entire corpus. If the recipe
        // has genuinely changed, `knowledge:embed --all` is the way to force it.
        DB::table('document_chunks')
            ->whereNotNull('embedding')
            ->update(['embedding_recipe' => OllamaEmbeddings::recipe()]);
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropColumn('embedding_recipe');
        });
    }
};
