-- ============================================================================
-- migrate_030_receipt_tokens.sql
--
-- Parent-facing payment receipts (Phase 4 of the UX roadmap).
-- Each fee_payments row gets a random 32-hex receipt_token; /receipt.php?t=…
-- renders a branded receipt with no login — same link-only pattern as the
-- parent admission form. The office copies the link from the child's Fees
-- tab and WhatsApps it to the family.
--
-- Existing payments are backfilled so old receipts are shareable too.
--
-- Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_receipt_tokens;
DELIMITER //
CREATE PROCEDURE pr_lg_receipt_tokens()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_payments'
          AND COLUMN_NAME = 'receipt_token'
    ) THEN
        ALTER TABLE fee_payments
            ADD COLUMN receipt_token CHAR(32) NULL AFTER reference_no,
            ADD UNIQUE KEY uq_fp_receipt (receipt_token);
    END IF;

    -- Backfill any rows without a token (covers both the initial migration
    -- and any row that somehow slipped through later).
    UPDATE fee_payments
    SET    receipt_token = MD5(CONCAT(id, '-', UUID(), '-', RAND()))
    WHERE  receipt_token IS NULL;
END //
DELIMITER ;
CALL pr_lg_receipt_tokens();
DROP PROCEDURE pr_lg_receipt_tokens;
