-- ============================================================================
-- migrate_038_crm_stage_cleanup.sql
--
-- Three pipeline-integrity repairs:
--
-- 1. Seed the stages the WhatsApp bot moves leads into. bot_event.php writes
--    status='intro_sent' / 'call_requested' / 'future_intake', but no
--    migration ever created those crm_stages rows — so a bot-touched family
--    got probability 0 and rendered in NO kanban column: it silently vanished
--    from the board, the revenue projection and every follow-up list.
--
-- 2. Merge the duplicate visit stage. schema.sql seeds 'school_visited'
--    (order 50) and every code path uses it; migration 028 separately seeded
--    a stray 'visited' stage (order ~110, after Lost) and put the post-visit
--    WhatsApp text/template THERE — on the stage nothing uses. Move any
--    families and the wa_* config onto school_visited, then drop 'visited'.
--
-- 3. Remove the 034-seeded intro line from closed/parked stages. "A quick
--    note from us right after this:" is a wrong first message to send a
--    Lost or already-Enrolled family. Only clears the exact seeded text, so
--    admin-customised intros are untouched.
--
-- Idempotent throughout (INSERT IGNORE / guarded UPDATEs / DELETE converges).
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Bot destination stages ------------------------------------------------
INSERT IGNORE INTO crm_stages (code, label, display_order, probability, is_open, is_active) VALUES
    ('intro_sent',     'Intro sent',     25, 25, 1, 1),
    ('call_requested', 'Call requested', 35, 40, 1, 1),
    ('future_intake',  'Future intake',  95, 10, 1, 1);

-- 2. visited → school_visited ----------------------------------------------
-- Carry the 028 wa_*/message config over, but only into empty slots.
UPDATE crm_stages sv
JOIN   crm_stages v ON v.code = 'visited'
SET    sv.wa_text          = COALESCE(NULLIF(sv.wa_text, ''),          v.wa_text),
       sv.wa_template      = COALESCE(NULLIF(sv.wa_template, ''),      v.wa_template),
       sv.wa_template_lang = COALESCE(NULLIF(sv.wa_template_lang, ''), v.wa_template_lang)
WHERE  sv.code = 'school_visited';

UPDATE inquiry_families SET status = 'school_visited' WHERE status = 'visited';

DELETE FROM crm_stages WHERE code = 'visited';

-- 3. No auto-intro on closed/parked stages ----------------------------------
UPDATE crm_stages
SET    intro_text = NULL
WHERE  (is_open = 0 OR code IN ('lost', 'enrolled', 'waitlisted', 'future_intake'))
  AND  intro_text = CONCAT(
           'Hi {parent_name}, this is the admissions team at The Little Graduates Playschool in Kaloor, Kochi 🌿 — ',
           'a quick note from us right after this:'
       );
