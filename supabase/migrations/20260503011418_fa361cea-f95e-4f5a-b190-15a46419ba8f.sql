
alter table public.document_chunks
  add column if not exists tsv tsvector
  generated always as (to_tsvector('english', coalesce(content, ''))) stored;

create index if not exists document_chunks_tsv_idx on public.document_chunks using gin (tsv);

create or replace function public.search_chunks(
  query_text text,
  match_chatbot_id uuid,
  match_count int default 6
) returns table (id uuid, content text, rank real)
language sql stable security definer set search_path = public as $$
  select id, content, ts_rank(tsv, websearch_to_tsquery('english', query_text)) as rank
  from public.document_chunks
  where chatbot_id = match_chatbot_id
    and tsv @@ websearch_to_tsquery('english', query_text)
  order by rank desc
  limit match_count;
$$;

revoke execute on function public.search_chunks(text, uuid, int) from public, anon, authenticated;
