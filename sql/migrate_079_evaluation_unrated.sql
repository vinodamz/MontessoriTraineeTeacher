-- ============================================================================
-- migrate_079_evaluation_unrated.sql
--
-- A saved assessment keeps every indicator that was on the form. When the
-- teacher does not choose a developmental level, evaluation_cards.rating
-- is NULL. The downloaded report lists that indicator and leaves the level
-- empty.
--
-- migrate_078 widens the rating column and leaves it nullable when it has
-- to change the type. A database where that column already lists every
-- code is not altered by 078, so it can still be NOT NULL. This drops
-- NOT NULL without changing the type. NULL is not a rating code. Real
-- codes, and historical '' values from the old too-narrow ENUM, stay as
-- they are.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_eval_rating_nullable;
DELIMITER //
CREATE PROCEDURE pr_lg_eval_rating_nullable()
BEGIN
    DECLARE coltype TEXT;
    DECLARE nullable VARCHAR(3);
    DECLARE have_column INT DEFAULT 0;

    SELECT COUNT(*) INTO have_column
    FROM information_schema.columns
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'evaluation_cards'
      AND COLUMN_NAME = 'rating';

    IF have_column = 1 THEN
        SELECT COLUMN_TYPE, IS_NULLABLE INTO coltype, nullable
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'evaluation_cards'
          AND COLUMN_NAME = 'rating';

        IF nullable = 'NO' THEN
            SET @lg_eval_null_sql = CONCAT(
                'ALTER TABLE evaluation_cards MODIFY COLUMN rating ', coltype, ' NULL'
            );
            PREPARE lg_eval_null_stmt FROM @lg_eval_null_sql;
            EXECUTE lg_eval_null_stmt;
            DEALLOCATE PREPARE lg_eval_null_stmt;
        END IF;
    END IF;
END //
DELIMITER ;

CALL pr_lg_eval_rating_nullable();
DROP PROCEDURE IF EXISTS pr_lg_eval_rating_nullable;
