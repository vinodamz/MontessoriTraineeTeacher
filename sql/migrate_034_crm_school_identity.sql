-- ============================================================================
-- migrate_034_crm_school_identity.sql
--
-- Two-pronged fix so cold WhatsApp templates (which only know {{school_name}})
-- actually identify the sender to a parent who's never heard from us:
--
--   1. New app_settings key 'crm_school_name'. crm_wa_defaults() substitutes
--      this for {school_name} in every WA send — instead of the bare app_name()
--      "Little Graduates", the parent sees the long-form identity AND city.
--      Admin edits the value at /admin.php → WhatsApp school name.
--
--   2. Seed crm_stages.intro_text (introduced in migration 033) on every
--      stage that's still empty so even today's sends — before anyone has
--      configured per-stage intros — get a "Hi, this is Little Graduates
--      Playschool in Kaloor, Kochi" line prepended on the FIRST send to a
--      family. The stage intro tracker (crm_stage_intros_sent) makes sure it
--      only fires once per family per stage.
--
-- Idempotent: INSERT IGNORE on app_settings + UPDATE … WHERE intro_text IS
-- NULL OR intro_text = '' so admin-customised stages aren't clobbered.
-- ============================================================================

SET NAMES utf8mb4;

-- Long-form identity used everywhere {school_name} substitutes.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('crm_school_name', 'The Little Graduates Playschool, Kaloor, Kochi');

-- Seed intro_text on every currently-empty stage. Each line stands alone
-- (the template that follows it carries the rest of the message), so the
-- intro just establishes WHO is writing and from WHERE.
UPDATE crm_stages
SET    intro_text =
       'Hi {parent_name}, this is the admissions team at The Little Graduates Playschool in Kaloor, Kochi 🌿 — '
     . 'a quick note from us right after this:'
WHERE  (intro_text IS NULL OR intro_text = '');
