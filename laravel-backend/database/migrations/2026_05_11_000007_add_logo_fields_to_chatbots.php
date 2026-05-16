<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('logo_path', 512)->nullable()->after('primary_color');
            $table->string('logo_original_name', 255)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_original_name']);
        });
    }
};
