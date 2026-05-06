<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION match_chunks(
    query_embedding vector(768),
    match_chatbot_id uuid,
    match_count int DEFAULT 5
) RETURNS TABLE (id uuid, content text, similarity float)
LANGUAGE sql STABLE AS $$
    SELECT id, content, 1 - (embedding <=> query_embedding) AS similarity
    FROM document_chunks
    WHERE chatbot_id = match_chatbot_id
      AND embedding IS NOT NULL
    ORDER BY embedding <=> query_embedding
    LIMIT match_count;
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION search_chunks(
    query_text text,
    match_chatbot_id uuid,
    match_count int DEFAULT 6
) RETURNS TABLE (id uuid, content text, rank real)
LANGUAGE sql STABLE AS $$
    SELECT id, content, ts_rank(tsv, websearch_to_tsquery('english', query_text)) AS rank
    FROM document_chunks
    WHERE chatbot_id = match_chatbot_id
      AND tsv @@ websearch_to_tsquery('english', query_text)
    ORDER BY rank DESC
    LIMIT match_count;
$$;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS search_chunks(text, uuid, int)');
        DB::statement('DROP FUNCTION IF EXISTS match_chunks(vector, uuid, int)');
    }
};
