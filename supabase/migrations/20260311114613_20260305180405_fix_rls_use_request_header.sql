
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
