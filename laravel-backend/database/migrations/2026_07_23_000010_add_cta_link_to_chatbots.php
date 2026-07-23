<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('cta_label', 40)->nullable()->after('collect_email');
            $table->string('cta_url', 2048)->nullable()->after('cta_label');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['cta_label', 'cta_url']);
        });
    }
};
