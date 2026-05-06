<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('chatbots', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('welcome_message')->default('Hi! How can I help you today?');
            $table->text('system_prompt')->default('You are a helpful assistant. Answer based only on the provided context. If unsure, say you do not know.');
            $table->string('primary_color', 7)->default('#7c5cff');
            $table->enum('bubble_position', ['right', 'left'])->default('right');
            $table->enum('tone', ['friendly', 'formal', 'playful', 'concise'])->default('friendly');
            $table->string('language', 16)->default('auto');
            $table->boolean('collect_email')->default(false);
            $table->string('api_key', 67)->unique()->default(DB::raw("'cb_' || encode(gen_random_bytes(24), 'hex')"));
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('source_type', ['file', 'url', 'text']);
            $table->string('name', 255);
            $table->text('url')->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->enum('status', ['pending', 'processing', 'ready', 'error'])->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['chatbot_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('source_id')->constrained('knowledge_sources')->cascadeOnDelete();
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->longText('content');
            $table->unsignedInteger('chunk_index');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['source_id', 'chunk_index']);
            $table->index('chatbot_id');
            $table->index(['user_id', 'created_at']);
        });

        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(768)');
        DB::statement("ALTER TABLE document_chunks ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(content, ''))) STORED");
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
        DB::statement('CREATE INDEX document_chunks_tsv_idx ON document_chunks USING gin (tsv)');

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64)->nullable();
            $table->string('visitor_email', 200)->nullable();
            $table->string('source', 32)->default('widget');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['chatbot_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->longText('content');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['chatbot_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('chatbots');
    }
};
