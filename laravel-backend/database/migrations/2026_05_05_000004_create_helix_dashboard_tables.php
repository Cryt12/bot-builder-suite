<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160)->nullable();
            $table->string('email', 200);
            $table->string('company', 160)->nullable();
            $table->string('phone', 80)->nullable();
            $table->enum('status', ['new', 'contacted', 'qualified', 'closed', 'spam'])->default('new');
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('captured_at')->useCurrent();
            $table->timestampsTz();

            $table->index(['user_id', 'captured_at']);
            $table->index(['chatbot_id', 'captured_at']);
            $table->index(['email']);
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->enum('status', ['new', 'reviewed', 'closed'])->default('new');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('name', 120);
            $table->enum('status', ['connected', 'disabled', 'error'])->default('connected');
            $table->jsonb('settings')->nullable();
            $table->jsonb('credentials')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'provider', 'name']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('leads');
    }
};
