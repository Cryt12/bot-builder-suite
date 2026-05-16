<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->unsignedInteger('widget_cache_minutes')->default(10)->after('public_rate_limit_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('widget_cache_minutes');
        });
    }
};
