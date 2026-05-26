<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('llm_provider', 16)->nullable()->after('is_active');
            $table->string('llm_model', 128)->nullable()->after('llm_provider');

            // Add index for faster lookup by provider/model combos
            $table->index(['llm_provider', 'llm_model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropIndex(['llm_provider', 'llm_model']);
            $table->dropColumn(['llm_provider', 'llm_model']);
        });
    }
};
