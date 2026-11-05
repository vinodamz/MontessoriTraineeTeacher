-- ============================================================================
-- migrate_078_evaluation_rating_codes.sql
--
-- evaluation_cards.rating was created as ENUM('D','P','N'). The rating scheme
-- (rating_config, edited on Admin → Rating scheme) has since gained more
-- codes. The server's sql_mode is non-strict, so a code the ENUM does not
-- list is stored as '' and the form shows a blank. This widens the column to
-- every code in rating_config, plus any non-empty code already stored on an
-- old assessment.
--
-- Existing ENUM members stay in their current order. New codes are appended,
-- because MySQL stores ENUM values by position and reordering them would
-- change old ratings. Rows that are already blank are left blank — this does
-- not guess which code a teacher originally picked.
--
-- A CHAR/VARCHAR column (a database built from schema.sql) is switched to
-- that same ENUM once rating_config or the saved rows have a code.
--
-- Idempotent. The loose sql_mode is only for this ALTER: strict mode can
-- refuse the change while those historical blanks are still in the column.
-- It is put back at the end of this file.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_eval_rating_codes;
DELIMITER //
CREATE PROCEDURE pr_lg_eval_rating_codes()
BEGIN
    DECLARE dtype VARCHAR(64);
    DECLARE coltype TEXT;
    DECLARE updated TEXT;
    DECLARE next_code VARCHAR(8);
    DECLARE list TEXT;
    DECLARE done INT DEFAULT 0;
    DECLARE have_tables INT DEFAULT 0;
    DECLARE have_column INT DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT code FROM (
            SELECT code, MIN(id) AS ord
            FROM rating_config
            WHERE code REGEXP '^[A-Za-z0-9]$'
            GROUP BY code
            UNION ALL
            SELECT rating AS code, MIN(id) + 1000000 AS ord
            FROM evaluation_cards
            WHERE rating REGEXP '^[A-Za-z0-9]$'
            GROUP BY rating
        ) src
        GROUP BY code
        ORDER BY MIN(ord), code;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT COUNT(*) INTO have_tables
    FROM information_schema.tables
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('evaluation_cards', 'rating_config');

    IF have_tables = 2 THEN
        SELECT COUNT(*) INTO have_column
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'evaluation_cards'
          AND COLUMN_NAME = 'rating';
    END IF;

    IF have_column = 1 THEN
        SELECT DATA_TYPE, COLUMN_TYPE INTO dtype, coltype
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'evaluation_cards'
          AND COLUMN_NAME = 'rating';

        SET coltype = TRIM(coltype);
        SET updated = coltype;
        SET list = '';
        SET done = 0;
        SET dtype = LOWER(dtype);

        OPEN cur;
        read_loop: LOOP
            FETCH cur INTO next_code;
            IF done = 1 THEN
                LEAVE read_loop;
            END IF;
            IF dtype = 'enum' THEN
                IF LOCATE(CONCAT('''', next_code, ''''), updated) = 0 THEN
                    SET updated = CONCAT(LEFT(updated, CHAR_LENGTH(updated) - 1), ',''', next_code, ''')');
                END IF;
            ELSEIF list = '' THEN
                SET list = CONCAT('''', next_code, '''');
            ELSE
                SET list = CONCAT(list, ',''', next_code, '''');
            END IF;
        END LOOP;
        CLOSE cur;

        IF dtype = 'enum' AND updated <> coltype THEN
            SET @lg_eval_rating_sql = CONCAT(
                'ALTER TABLE evaluation_cards MODIFY COLUMN rating ', updated, ' NOT NULL'
            );
            PREPARE lg_eval_rating_stmt FROM @lg_eval_rating_sql;
            EXECUTE lg_eval_rating_stmt;
            DEALLOCATE PREPARE lg_eval_rating_stmt;
        ELSEIF dtype IN ('char', 'varchar') AND list <> '' THEN
            SET @lg_eval_rating_sql = CONCAT(
                'ALTER TABLE evaluation_cards MODIFY COLUMN rating ENUM(', list, ') NOT NULL'
            );
            PREPARE lg_eval_rating_stmt FROM @lg_eval_rating_sql;
            EXECUTE lg_eval_rating_stmt;
            DEALLOCATE PREPARE lg_eval_rating_stmt;
        END IF;
    END IF;
END //
DELIMITER ;

CALL pr_lg_eval_rating_codes();
DROP PROCEDURE IF EXISTS pr_lg_eval_rating_codes;

SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
