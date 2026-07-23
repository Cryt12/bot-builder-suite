<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('footer_text', 80)->nullable()->after('cta_url');
            $table->jsonb('footer_logos')->nullable()->after('footer_text');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['footer_text', 'footer_logos']);
        });
    }
};
