
-- Fix mutable search path on set_updated_at
create or replace function public.set_updated_at()
returns trigger language plpgsql set search_path = public as $$
begin new.updated_at = now(); return new; end; $$;

-- Lock down execute on SECURITY DEFINER functions
revoke execute on function public.has_role(uuid, app_role) from public, anon, authenticated;
revoke execute on function public.match_chunks(vector, uuid, int) from public, anon, authenticated;
revoke execute on function public.handle_new_user() from public, anon, authenticated;
-- Note: handle_new_user is invoked by trigger context (postgres role), not directly callable.
-- match_chunks and has_role will be invoked from server functions via service role.
