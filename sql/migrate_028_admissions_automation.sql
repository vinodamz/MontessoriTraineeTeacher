-- ============================================================================
-- migrate_028_admissions_automation.sql
--
-- Foundation for the automated WhatsApp admissions flow (see
-- docs/admissions-automation.md):
--   1. A new 'visited' stage — anchors the 3-day post-visit reminder.
--   2. Timer / activity columns on inquiry_families:
--        visited_at             when you marked the school visit done
--        last_inbound_at        last time the parent messaged (bot updates it)
--        post_visit_reminded_at guard so the 3-day reminder fires once
--        quiet_reminded_at      guard so the 'gone quiet → call' reminder fires once
--   3. Seed per-stage WhatsApp messages (wa_text / wa_template) — only where the
--      admin hasn't already set one, so re-running never clobbers edits.
--
-- Idempotent — guarded on information_schema + INSERT IGNORE + blank-only seeds.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_admissions_automation;
DELIMITER //
CREATE PROCEDURE pr_lg_admissions_automation()
BEGIN
    -- 1. Activity / timer columns on inquiry_families ------------------------
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='visited_at') THEN
        ALTER TABLE inquiry_families ADD COLUMN visited_at DATETIME NULL AFTER status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='last_inbound_at') THEN
        ALTER TABLE inquiry_families ADD COLUMN last_inbound_at DATETIME NULL AFTER visited_at;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='post_visit_reminded_at') THEN
        ALTER TABLE inquiry_families ADD COLUMN post_visit_reminded_at DATETIME NULL AFTER last_inbound_at;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='quiet_reminded_at') THEN
        ALTER TABLE inquiry_families ADD COLUMN quiet_reminded_at DATETIME NULL AFTER post_visit_reminded_at;
    END IF;

    -- 2. The 'visited' stage (between Tour scheduled and Application) ---------
    --    INSERT IGNORE is a no-op if the code already exists. Placed at the end
    --    of the order; reorder it in Pipeline → Stages if you like.
    SET @ord := (SELECT COALESCE(MAX(display_order),0) + 10 FROM crm_stages);
    INSERT IGNORE INTO crm_stages (code, label, display_order, probability, is_open, is_active)
        VALUES ('visited', 'Visited', @ord, 60, 1, 1);
END //
DELIMITER ;
CALL pr_lg_admissions_automation();
DROP PROCEDURE pr_lg_admissions_automation;

-- 3. Seed per-stage WhatsApp messages (blank-only) --------------------------
-- Free-text bodies support {parent_name} {child_name} {school_name} {stage}.
-- Template names are the ones to submit to Meta (used only outside the 24h
-- window); they stay inert until approved.
UPDATE crm_stages SET wa_text = 'Hi {parent_name}! 🌟 Thanks for reaching out to {school_name}. I can share our programmes and fees, or help you book a school visit — what would you like?'
    WHERE code='lead'  AND (wa_text IS NULL OR wa_text='');
UPDATE crm_stages SET wa_template = 'welcome_admissions', wa_template_lang = 'en'
    WHERE code='lead'  AND (wa_template IS NULL OR wa_template='');

UPDATE crm_stages SET wa_text = 'So glad you''re interested, {parent_name}! Would you like to visit and see the classrooms? We can arrange a convenient time for you and {child_name}.'
    WHERE code='new'   AND (wa_text IS NULL OR wa_text='');
UPDATE crm_stages SET wa_template = 'visit_invitation', wa_template_lang = 'en'
    WHERE code='new'   AND (wa_template IS NULL OR wa_template='');

UPDATE crm_stages SET wa_text = 'Wonderful! 🎉 Our team will confirm a date for your visit. We look forward to welcoming you and {child_name} to {school_name}!'
    WHERE code='tour_scheduled' AND (wa_text IS NULL OR wa_text='');

UPDATE crm_stages SET wa_text = 'Hi {parent_name}, it was lovely having you at {school_name}! 😊 Do you have any questions about admission for {child_name}? We''re happy to help with the next steps.'
    WHERE code='visited' AND (wa_text IS NULL OR wa_text='');
UPDATE crm_stages SET wa_template = 'post_visit_followup', wa_template_lang = 'en'
    WHERE code='visited' AND (wa_template IS NULL OR wa_template='');

UPDATE crm_stages SET wa_text = 'Thanks {parent_name}! We''ve noted {child_name}''s application. We''ll guide you through the remaining steps shortly.'
    WHERE code='application_submitted' AND (wa_text IS NULL OR wa_text='');

UPDATE crm_stages SET wa_text = 'Great news, {parent_name}! Here are the next steps to secure {child_name}''s place at {school_name}. Shall I guide you through the form?'
    WHERE code='offered' AND (wa_text IS NULL OR wa_text='');
UPDATE crm_stages SET wa_template = 'admission_next_steps', wa_template_lang = 'en'
    WHERE code='offered' AND (wa_template IS NULL OR wa_template='');

UPDATE crm_stages SET wa_text = 'Welcome to the {school_name} family, {parent_name}! 🎓 We''re thrilled to have {child_name} with us.'
    WHERE code='enrolled' AND (wa_text IS NULL OR wa_text='');
