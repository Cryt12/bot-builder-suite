<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('public_key', 80)->nullable()->after('api_key');
            $table->unsignedInteger('public_rate_limit_per_minute')->default(60)->after('allowed_domains');
        });

        DB::statement("UPDATE chatbots SET public_key = 'pbk_' || encode(gen_random_bytes(24), 'hex') WHERE public_key IS NULL");
        DB::statement('ALTER TABLE chatbots ALTER COLUMN public_key SET NOT NULL');

        Schema::table('chatbots', function (Blueprint $table) {
            $table->unique('public_key');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropUnique(['public_key']);
            $table->dropColumn(['public_key', 'public_rate_limit_per_minute']);
        });
    }
};
