<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            // Percentage the logo is scaled to inside the chat bubble.
            $table->unsignedSmallInteger('logo_scale')->default(100)->after('logo_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('logo_scale');
        });
    }
};
