-- =====================================================================
--  upgrade-2026-10-live-fleet.sql - Live Fleet: every bus on one map,
--  driver phone login by Driver ID + PIN, CCTV slots, TV wall.
--  (includes/livefleet.php, api/driver.php, api/live-fleet.php,
--   admin/live-fleet.php, admin/live-tv.php, admin/live-setup.php)
--
--  Additive and re-runnable: five NEW tables and two settings rows.
--  Nothing that already exists is altered. The old single `livebus`
--  key keeps working - the new engine writes it too, so the passenger
--  Trip Companion and the "bus is near" alerts see the same position.
--
--    php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql
-- =====================================================================
SET NAMES utf8mb4;

-- A driver's phone login: "Driver ID" (e.g. D101) + a 4-8 digit PIN.
-- Kept beside `drivers` instead of altering it.
CREATE TABLE IF NOT EXISTS `fleet_driver_logins` (
  `driver_id`     BIGINT UNSIGNED NOT NULL,
  `login_code`    VARCHAR(20) NOT NULL COMMENT 'what the driver types, e.g. D101',
  `pin_hash`      VARCHAR(255) NOT NULL,
  `failed_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`  DATETIME NULL,
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NOT NULL,
  PRIMARY KEY (`driver_id`),
  UNIQUE KEY `uq_fleet_login_code` (`login_code`),
  CONSTRAINT `fk_fleet_login_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per phone that signed in ("attached device"). The phone keeps
-- the random token; only its SHA-256 is stored. Revoking = setting revoked_at.
CREATE TABLE IF NOT EXISTS `fleet_devices` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64) NOT NULL,
  `label`         VARCHAR(160) NULL COMMENT 'phone model / browser',
  `bus_id`        BIGINT UNSIGNED NULL COMMENT 'last bus this phone drove',
  `last_ip`       VARCHAR(45) NULL,
  `created_at`    DATETIME NOT NULL,
  `last_seen_at`  DATETIME NULL,
  `revoked_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_device_token` (`token_hash`),
  KEY `ix_fleet_device_driver` (`driver_id`),
  CONSTRAINT `fk_fleet_device_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fleet_device_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per run of a bus: started on the phone, ended on the phone (or
-- by cron after hours of silence). The last_* columns are the live position
-- every map reads, so a refresh is one small query, not a history scan.
CREATE TABLE IF NOT EXISTS `fleet_trips` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bus_id`          BIGINT UNSIGNED NOT NULL,
  `driver_id`       BIGINT UNSIGNED NULL,
  `device_id`       BIGINT UNSIGNED NULL,
  `schedule_id`     BIGINT UNSIGNED NULL,
  `route_id`        BIGINT UNSIGNED NULL,
  `status`          ENUM('running','ended') NOT NULL DEFAULT 'running',
  `started_at`      DATETIME NOT NULL,
  `ended_at`        DATETIME NULL,
  `end_reason`      VARCHAR(40) NULL,
  `last_lat`        DECIMAL(10,7) NULL,
  `last_lng`        DECIMAL(10,7) NULL,
  `last_speed_kmh`  DECIMAL(6,1) NULL,
  `last_heading`    SMALLINT NULL,
  `last_accuracy_m` DECIMAL(8,1) NULL,
  `last_battery`    TINYINT UNSIGNED NULL,
  `last_fix_at`     DATETIME NULL COMMENT 'when the phone took the fix',
  `last_ping_at`    DATETIME NULL COMMENT 'when the server received it',
  `last_trail_at`   DATETIME NULL,
  `distance_km`     DECIMAL(9,2) NOT NULL DEFAULT 0.00,
  `points`          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_fleet_trip_status` (`status`,`bus_id`),
  KEY `ix_fleet_trip_bus` (`bus_id`,`started_at`),
  KEY `ix_fleet_trip_device` (`device_id`,`status`),
  CONSTRAINT `fk_fleet_trip_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fleet_trip_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fleet_trip_device` FOREIGN KEY (`device_id`)
    REFERENCES `fleet_devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The trail (breadcrumbs). Thinned on the way in (one point per ~5 s), and
-- cron/fleet-cleanup.php drops points past fleet_keep_days.
CREATE TABLE IF NOT EXISTS `fleet_points` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id`      BIGINT UNSIGNED NOT NULL,
  `lat`          DECIMAL(10,7) NOT NULL,
  `lng`          DECIMAL(10,7) NOT NULL,
  `speed_kmh`    DECIMAL(6,1) NULL,
  `heading`      SMALLINT NULL,
  `accuracy_m`   DECIMAL(8,1) NULL,
  `recorded_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fleet_points_trip` (`trip_id`,`recorded_at`),
  CONSTRAINT `fk_fleet_points_trip` FOREIGN KEY (`trip_id`)
    REFERENCES `fleet_trips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CCTV: any number of camera feeds per bus. kind decides how the browser
-- plays it: hls (.m3u8), mjpeg (multipart stream), snapshot (a JPEG that
-- is re-fetched every second) or embed (the DVR vendor's own web player).
CREATE TABLE IF NOT EXISTS `fleet_cameras` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bus_id`      BIGINT UNSIGNED NOT NULL,
  `name`        VARCHAR(60) NOT NULL,
  `kind`        ENUM('hls','mjpeg','snapshot','embed') NOT NULL DEFAULT 'hls',
  `url`         VARCHAR(500) NOT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL,
  `updated_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fleet_cam_bus` (`bus_id`,`is_active`,`sort_order`),
  CONSTRAINT `fk_fleet_cam_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('fleet_keep_days', '30', 'int',    'liveops', 'Live Fleet: keep the GPS trail of each trip this many days', 0),
('fleet_tv_key',    '',   'string', 'liveops', 'Live Fleet: secret key in the TV wall link (empty = TV needs a staff login)', 0);
