-- ============================================================================
-- migrate_072_mobile_app_api.sql
--
-- Little Graduates Android app:
--   api_tokens          — one row per signed-in device (bearer token stored
--                         as a SHA-256 hash; the plain token lives only on the
--                         phone)
--   api_login_failures  — wrong-PIN attempts, for lockout (no session in apps)
--   transport_locations — GPS points the driver's phone sends while a trip
--                         is running
--   transport_trips.last_* — latest point, so ETA and the parent map read one
--                         row instead of scanning the history
--
-- Setting:
--   transport_reached_radius_m — distance from a stop that counts as "reached"
--
-- Idempotent — information_schema guards throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_mobile_app_api;
DELIMITER //
CREATE PROCEDURE pr_lg_mobile_app_api()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens') THEN
        CREATE TABLE api_tokens (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       INT UNSIGNED NOT NULL,
            token_hash    CHAR(64)     NOT NULL,
            device_name   VARCHAR(120) NOT NULL DEFAULT '',
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at  DATETIME     NULL,
            revoked_at    DATETIME     NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_tokens_hash (token_hash),
            KEY idx_api_tokens_user (user_id, revoked_at),
            CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_login_failures') THEN
        CREATE TABLE api_login_failures (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       INT UNSIGNED NOT NULL,
            ip            VARCHAR(45)  NOT NULL DEFAULT '',
            failed_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_api_login_failures_user (user_id, failed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_locations') THEN
        CREATE TABLE transport_locations (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            trip_id       INT UNSIGNED NOT NULL,
            lat           DECIMAL(10,7) NOT NULL,
            lng           DECIMAL(10,7) NOT NULL,
            accuracy_m    SMALLINT UNSIGNED NULL,
            speed_mps     DECIMAL(5,2) NULL,
            heading       SMALLINT UNSIGNED NULL,
            recorded_at   DATETIME NOT NULL,
            received_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transport_locations_trip (trip_id, recorded_at),
            CONSTRAINT fk_transport_locations_trip FOREIGN KEY (trip_id)
                REFERENCES transport_trips(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trips' AND COLUMN_NAME = 'last_lat') THEN
        ALTER TABLE transport_trips
            ADD COLUMN last_lat DECIMAL(10,7) NULL,
            ADD COLUMN last_lng DECIMAL(10,7) NULL,
            ADD COLUMN last_location_at DATETIME NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings') THEN
        INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
            ('transport_reached_radius_m', '80');
    END IF;
END //
DELIMITER ;
CALL pr_lg_mobile_app_api();
DROP PROCEDURE pr_lg_mobile_app_api;
