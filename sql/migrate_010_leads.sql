-- ============================================================================
-- migrate_010_leads.sql
--
-- Lead capture: adds a 'lead' stage in front of the existing admissions
-- pipeline, plus a structured campaigns table and a priority dimension.
-- A lead is just an inquiry_family in the earliest possible state — minimal
-- contact data, no children/parents/touchpoints yet. Once contacted and
-- qualified, the status moves from 'lead' to 'new' and it joins the rest of
-- the pipeline.
--
-- Changes:
--   • inquiry_families.status ENUM gains 'lead' at the front.
--   • inquiry_families gains: priority, campaign_id (FK), ip_hash (for the
--     public form's rate-limiter).
--   • New table crm_campaigns + a few starter rows.
--
-- Idempotent — information_schema guards.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_leads;
DELIMITER //
CREATE PROCEDURE pr_lg_leads()
BEGIN
    -- 1. crm_campaigns — structured campaign list. Lead+inquiry rows FK here.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_campaigns') THEN
        CREATE TABLE crm_campaigns (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(120) NOT NULL,
            channel    ENUM('walk_in','referral','website','instagram','facebook',
                            'google','whatsapp','event','other')
                       NOT NULL DEFAULT 'other',
            cost       DECIMAL(10,2) NULL,
            active     TINYINT(1)    NOT NULL DEFAULT 1,
            notes      TEXT          NULL,
            created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_camp_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO crm_campaigns (name, channel, active) VALUES
            ('Walk-in',           'walk_in',  1),
            ('Word of mouth',     'referral', 1),
            ('Website form',      'website',  1),
            ('Instagram',         'instagram',1);
    END IF;

    -- 2. inquiry_families.status — extend ENUM with 'lead' at the front.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families'
          AND COLUMN_NAME='status' AND COLUMN_TYPE NOT LIKE '%''lead''%'
    ) THEN
        ALTER TABLE inquiry_families
            MODIFY status
            ENUM('lead','new','tour_scheduled','application_submitted',
                 'offered','enrolled','waitlisted','lost')
            NOT NULL DEFAULT 'new';
    END IF;

    -- 3. priority (per-lead urgency).
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families'
                     AND COLUMN_NAME='priority') THEN
        ALTER TABLE inquiry_families
            ADD COLUMN priority ENUM('low','normal','high','urgent')
                NOT NULL DEFAULT 'normal' AFTER probability,
            ADD KEY idx_inq_priority (priority);
    END IF;

    -- 4. campaign_id (FK to crm_campaigns).
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families'
                     AND COLUMN_NAME='campaign_id') THEN
        ALTER TABLE inquiry_families
            ADD COLUMN campaign_id INT UNSIGNED NULL AFTER source,
            ADD KEY idx_inq_campaign (campaign_id),
            ADD CONSTRAINT fk_inq_campaign FOREIGN KEY (campaign_id)
                REFERENCES crm_campaigns(id) ON DELETE SET NULL;
    END IF;

    -- 5. ip_hash for public-form rate-limiting (sha256 of submitter IP).
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families'
                     AND COLUMN_NAME='ip_hash') THEN
        ALTER TABLE inquiry_families
            ADD COLUMN ip_hash VARCHAR(64) NULL AFTER notes,
            ADD KEY idx_inq_ip_recent (ip_hash, created_at);
    END IF;
END //
DELIMITER ;
CALL pr_lg_leads();
DROP PROCEDURE pr_lg_leads;
