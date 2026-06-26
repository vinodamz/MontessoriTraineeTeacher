-- ============================================================================
-- migrate_035_crm_intro_template.sql
--
-- Pre-wires the cold-outreach intro template onto the two stages a contact
-- sourced "from elsewhere" lands in:
--
--   lead  — a number captured via /crm/lead_new.php or imported via
--           /crm/lead_import.php (status='lead'), sitting off the main board.
--   new   — the first pipeline stage, where a lead lands once promoted.
--
-- The template `intro_admission_enquiry` (Meta MARKETING category) introduces
-- the school + city, asks whether the parent is looking for admission, and
-- carries a website button + Yes/No quick-reply buttons. Because a cold number
-- has no open 24h session window, this TEMPLATE — not wa_text — is what
-- actually sends on first contact.
--
-- Language code 'en' matches the template submitted to Meta. If Meta returns
-- the template under en_US instead, update wa_template_lang accordingly (or
-- edit it in /crm/stages.php → the stage's WhatsApp settings).
--
-- Idempotent: only fills wa_template where it's still empty, so a hand-set
-- template on either stage is never clobbered. Safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

UPDATE crm_stages
SET    wa_template      = 'intro_admission_enquiry',
       wa_template_lang = 'en'
WHERE  code IN ('lead', 'new')
  AND  (wa_template IS NULL OR wa_template = '');
