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
            $table->jsonb('allowed_domains')->nullable()->after('api_key');
        });

        DB::table('chatbots')->whereNull('allowed_domains')->update([
            'allowed_domains' => json_encode(['localhost', '127.0.0.1']),
        ]);
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('allowed_domains');
        });
    }
};
