/*
  # Fix RLS Helpers to Read From Request Headers

  ## Overview
  Updates the app_teacher_id() and app_teacher_role() helper functions to read
  the teacher identity from the PostgREST request header `x-teacher-id` that the
  frontend sends with every request after login. This is the standard pattern for
  custom (non-Supabase-Auth) auth in PostgREST.

  ## Changes
  - app_teacher_id() now reads from request.headers JSON → x-teacher-id
  - app_teacher_role() looks up role from teachers table using app_teacher_id()
  - No RLS policy changes needed — they already call these functions
*/

CREATE OR REPLACE FUNCTION app_teacher_id()
RETURNS uuid
LANGUAGE sql
STABLE
SECURITY DEFINER
AS $$
  SELECT NULLIF(
    COALESCE(
      current_setting('app.teacher_id', true),
      (current_setting('request.headers', true)::json ->> 'x-teacher-id')
    ),
    ''
  )::uuid;
$$;

CREATE OR REPLACE FUNCTION app_teacher_role()
RETURNS text
LANGUAGE sql
STABLE
SECURITY DEFINER
AS $$
  SELECT role FROM teachers
  WHERE id = app_teacher_id()
  LIMIT 1;
$$;
