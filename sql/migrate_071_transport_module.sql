-- ============================================================================
-- migrate_071_transport_module.sql
--
-- Transport (daily cab): cabs, routes with an ordered list of children,
-- a daily trip per route + direction, per-child stop status, and private
-- parent tracking links.
--
-- Parents never see a map or the cab's position — only an approximate time
-- and how many stops are ahead of theirs. Stop notes and coordinates are for
-- the driver screen and server-side ETA only. leg_minutes is the travel time
-- from the previous stop, filled from Google Maps when a key is configured.
--
-- Settings:
--   transport_maps_api_key        — Google Maps key (blank = estimate from
--                                   stop order + past trip timings)
--   transport_default_stop_minutes — minutes per stop until there is history
--
-- Idempotent — information_schema guards throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_transport_module;
DELIMITER //
CREATE PROCEDURE pr_lg_transport_module()
BEGIN
    IF (
        SELECT LOCATE('''transport''', COLUMN_TYPE) = 0
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'modules'
    ) THEN
        ALTER TABLE users MODIFY COLUMN modules
            SET('tasks','montessori','students','crm','recruitment','staff',
                'expenses','fees','logbook','inventory','materials','wacrm','n8n',
                'daycare','plans','transport')
            NOT NULL DEFAULT '';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_cabs') THEN
        CREATE TABLE transport_cabs (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(80)  NOT NULL,
            vehicle_no    VARCHAR(40)  NOT NULL DEFAULT '',
            driver_name   VARCHAR(120) NOT NULL DEFAULT '',
            driver_phone  VARCHAR(40)  NOT NULL DEFAULT '',
            capacity      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transport_cabs_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_routes') THEN
        CREATE TABLE transport_routes (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(120) NOT NULL,
            cab_id        INT UNSIGNED NULL,
            direction     ENUM('pickup','drop','both') NOT NULL DEFAULT 'both',
            pickup_time   TIME NULL,
            drop_time     TIME NULL,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transport_routes_active (is_active),
            CONSTRAINT fk_transport_routes_cab FOREIGN KEY (cab_id)
                REFERENCES transport_cabs(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_stops') THEN
        CREATE TABLE transport_stops (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            route_id      INT UNSIGNED NOT NULL,
            student_id    INT UNSIGNED NOT NULL,
            stop_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            stop_note     VARCHAR(255) NOT NULL DEFAULT '',
            lat           DECIMAL(10,7) NULL,
            lng           DECIMAL(10,7) NULL,
            leg_minutes   SMALLINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transport_stops_route_student (route_id, student_id),
            KEY idx_transport_stops_order (route_id, stop_order),
            KEY idx_transport_stops_student (student_id),
            CONSTRAINT fk_transport_stops_route FOREIGN KEY (route_id)
                REFERENCES transport_routes(id) ON DELETE CASCADE,
            CONSTRAINT fk_transport_stops_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trips') THEN
        CREATE TABLE transport_trips (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            route_id            INT UNSIGNED NOT NULL,
            trip_date           DATE NOT NULL,
            direction           ENUM('pickup','drop') NOT NULL,
            status              ENUM('scheduled','running','completed','cancelled')
                                NOT NULL DEFAULT 'scheduled',
            started_at          DATETIME NULL,
            completed_at        DATETIME NULL,
            started_by_user_id  INT UNSIGNED NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transport_trips_route_day (route_id, trip_date, direction),
            KEY idx_transport_trips_date (trip_date, status),
            CONSTRAINT fk_transport_trips_route FOREIGN KEY (route_id)
                REFERENCES transport_routes(id) ON DELETE CASCADE,
            CONSTRAINT fk_transport_trips_user FOREIGN KEY (started_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trip_stops') THEN
        CREATE TABLE transport_trip_stops (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            trip_id           INT UNSIGNED NOT NULL,
            student_id        INT UNSIGNED NOT NULL,
            stop_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status            ENUM('pending','done','absent') NOT NULL DEFAULT 'pending',
            reached_at        DATETIME NULL,
            done_at           DATETIME NULL,
            eta_alert_at      DATETIME NULL,
            reached_alert_at  DATETIME NULL,
            marked_by_user_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transport_trip_stops (trip_id, student_id),
            KEY idx_transport_trip_stops_order (trip_id, stop_order),
            KEY idx_transport_trip_stops_student (student_id),
            CONSTRAINT fk_transport_trip_stops_trip FOREIGN KEY (trip_id)
                REFERENCES transport_trips(id) ON DELETE CASCADE,
            CONSTRAINT fk_transport_trip_stops_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_transport_trip_stops_user FOREIGN KEY (marked_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_parent_links') THEN
        CREATE TABLE transport_parent_links (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id          INT UNSIGNED NOT NULL,
            token               CHAR(64) NOT NULL,
            created_by_user_id  INT UNSIGNED NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_accessed_at    DATETIME NULL,
            revoked_at          DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transport_parent_links_token (token),
            KEY idx_transport_parent_links_student (student_id, revoked_at),
            CONSTRAINT fk_transport_parent_links_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_transport_parent_links_user FOREIGN KEY (created_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings') THEN
        INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
            ('transport_maps_api_key', ''),
            ('transport_default_stop_minutes', '5');
    END IF;
END //
DELIMITER ;
CALL pr_lg_transport_module();
DROP PROCEDURE pr_lg_transport_module;
