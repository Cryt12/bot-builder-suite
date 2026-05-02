
-- Enable extensions
create extension if not exists vector;
create extension if not exists pgcrypto;

-- App role enum + user_roles (security best practice)
create type public.app_role as enum ('admin', 'user');

create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  email text,
  display_name text,
  created_at timestamptz not null default now()
);
alter table public.profiles enable row level security;
create policy "profiles self read" on public.profiles for select using (auth.uid() = id);
create policy "profiles self insert" on public.profiles for insert with check (auth.uid() = id);
create policy "profiles self update" on public.profiles for update using (auth.uid() = id);

create table public.user_roles (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references auth.users(id) on delete cascade,
  role app_role not null,
  unique (user_id, role)
);
alter table public.user_roles enable row level security;

create or replace function public.has_role(_user_id uuid, _role app_role)
returns boolean language sql stable security definer set search_path = public as $$
  select exists (select 1 from public.user_roles where user_id = _user_id and role = _role)
$$;

create policy "user_roles self read" on public.user_roles for select using (auth.uid() = user_id);

-- Auto-create profile + default role on signup
create or replace function public.handle_new_user()
returns trigger language plpgsql security definer set search_path = public as $$
begin
  insert into public.profiles (id, email, display_name)
  values (new.id, new.email, coalesce(new.raw_user_meta_data->>'display_name', split_part(new.email,'@',1)));
  insert into public.user_roles (user_id, role) values (new.id, 'user');
  return new;
end;
$$;
drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users for each row execute function public.handle_new_user();

-- Chatbots
create table public.chatbots (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references auth.users(id) on delete cascade,
  name text not null,
  welcome_message text not null default 'Hi! How can I help you today?',
  system_prompt text not null default 'You are a helpful assistant. Answer based only on the provided context. If unsure, say you do not know.',
  primary_color text not null default '#7c5cff',
  bubble_position text not null default 'right',
  tone text not null default 'friendly',
  language text not null default 'auto',
  collect_email boolean not null default false,
  api_key text not null unique default ('cb_' || encode(gen_random_bytes(24), 'hex')),
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
alter table public.chatbots enable row level security;
create policy "chatbots owner all" on public.chatbots for all using (auth.uid() = user_id) with check (auth.uid() = user_id);

-- Knowledge sources
create table public.knowledge_sources (
  id uuid primary key default gen_random_uuid(),
  chatbot_id uuid not null references public.chatbots(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  source_type text not null check (source_type in ('file','url','text')),
  name text not null,
  url text,
  storage_path text,
  size_bytes bigint,
  status text not null default 'pending' check (status in ('pending','processing','ready','error')),
  error_message text,
  chunk_count int not null default 0,
  created_at timestamptz not null default now()
);
alter table public.knowledge_sources enable row level security;
create policy "ks owner all" on public.knowledge_sources for all using (auth.uid() = user_id) with check (auth.uid() = user_id);

-- Document chunks with embeddings (Gemini text-embedding-004 = 768 dims)
create table public.document_chunks (
  id uuid primary key default gen_random_uuid(),
  source_id uuid not null references public.knowledge_sources(id) on delete cascade,
  chatbot_id uuid not null references public.chatbots(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  content text not null,
  chunk_index int not null,
  embedding vector(768),
  created_at timestamptz not null default now()
);
alter table public.document_chunks enable row level security;
create policy "chunks owner read" on public.document_chunks for select using (auth.uid() = user_id);

create index on public.document_chunks using ivfflat (embedding vector_cosine_ops) with (lists = 100);
create index on public.document_chunks (chatbot_id);

-- Vector match function (security definer so public chat can search by chatbot_id)
create or replace function public.match_chunks(
  query_embedding vector(768),
  match_chatbot_id uuid,
  match_count int default 5
) returns table (id uuid, content text, similarity float)
language sql stable security definer set search_path = public as $$
  select id, content, 1 - (embedding <=> query_embedding) as similarity
  from public.document_chunks
  where chatbot_id = match_chatbot_id and embedding is not null
  order by embedding <=> query_embedding
  limit match_count;
$$;

-- Conversations and messages
create table public.conversations (
  id uuid primary key default gen_random_uuid(),
  chatbot_id uuid not null references public.chatbots(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  visitor_id text,
  visitor_email text,
  source text not null default 'widget',
  created_at timestamptz not null default now()
);
alter table public.conversations enable row level security;
create policy "conv owner read" on public.conversations for select using (auth.uid() = user_id);

create table public.messages (
  id uuid primary key default gen_random_uuid(),
  conversation_id uuid not null references public.conversations(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  role text not null check (role in ('user','assistant','system')),
  content text not null,
  created_at timestamptz not null default now()
);
alter table public.messages enable row level security;
create policy "messages owner read" on public.messages for select using (auth.uid() = user_id);
create index on public.messages (conversation_id, created_at);

-- Analytics events
create table public.analytics_events (
  id bigserial primary key,
  chatbot_id uuid not null references public.chatbots(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  event_type text not null,
  metadata jsonb,
  created_at timestamptz not null default now()
);
alter table public.analytics_events enable row level security;
create policy "analytics owner read" on public.analytics_events for select using (auth.uid() = user_id);
create index on public.analytics_events (chatbot_id, created_at);

-- Storage bucket for uploaded files
insert into storage.buckets (id, name, public) values ('knowledge', 'knowledge', false)
  on conflict (id) do nothing;

create policy "knowledge owner upload" on storage.objects for insert
  to authenticated with check (bucket_id = 'knowledge' and auth.uid()::text = (storage.foldername(name))[1]);
create policy "knowledge owner read" on storage.objects for select
  to authenticated using (bucket_id = 'knowledge' and auth.uid()::text = (storage.foldername(name))[1]);
create policy "knowledge owner delete" on storage.objects for delete
  to authenticated using (bucket_id = 'knowledge' and auth.uid()::text = (storage.foldername(name))[1]);

-- Updated_at trigger for chatbots
create or replace function public.set_updated_at() returns trigger language plpgsql as $$
begin new.updated_at = now(); return new; end; $$;
create trigger chatbots_updated_at before update on public.chatbots
  for each row execute function public.set_updated_at();
