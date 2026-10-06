-- ============================================================================
-- migrate_073_transport_extra_trips.sql
--
-- A route can have more than one pickup or drop on the same day. run_no
-- counts them (1 is the first). Drivers start another run from the app
-- after the earlier one is finished or cancelled.
--
-- Idempotent — information_schema guards throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_transport_extra_trips;
DELIMITER //
CREATE PROCEDURE pr_lg_transport_extra_trips()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trips' AND COLUMN_NAME = 'run_no') THEN
        ALTER TABLE transport_trips
            ADD COLUMN run_no SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER direction;
    END IF;

    -- Add the wider unique key first. InnoDB uses the old key for the
    -- route_id foreign key, so it cannot be dropped until a replacement
    -- index that starts with route_id exists.
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trips'
                     AND INDEX_NAME = 'uq_transport_trips_route_day_run') THEN
        ALTER TABLE transport_trips
            ADD UNIQUE KEY uq_transport_trips_route_day_run (route_id, trip_date, direction, run_no);
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.statistics
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trips'
                 AND INDEX_NAME = 'uq_transport_trips_route_day') THEN
        ALTER TABLE transport_trips DROP INDEX uq_transport_trips_route_day;
    END IF;
END //
DELIMITER ;
CALL pr_lg_transport_extra_trips();
DROP PROCEDURE pr_lg_transport_extra_trips;
