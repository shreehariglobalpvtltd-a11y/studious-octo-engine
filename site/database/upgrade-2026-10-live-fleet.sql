-- =====================================================================
--  upgrade-2026-10-live-fleet.sql - Live Fleet: every bus on one map,
--  driver / vehicle phones, NTES-style stop timeline, alerts, passenger
--  share link, CCTV slots, TV wall, Nepali announcements.
--  (includes/livefleet.php, api/driver.php, api/live-fleet.php,
--   admin/live-fleet.php, admin/live-tv.php, admin/live-setup.php)
--
--  Additive and re-runnable: NEW fleet_* tables and settings rows only.
--  Nothing that already exists in the website is altered. A database that
--  ran an earlier copy of this file is brought up to date (the guarded
--  ALTERs at the end only touch fleet_* tables).
--
--    php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql
--    (or phpMyAdmin → Import, or admin/live-install.php → Install)
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

-- One row per phone ("attached device"). kind = driver: signed in with a
-- Driver ID + PIN and drives any bus. kind = vehicle: a phone that lives in
-- ONE bus, paired to its vehicle number with a one-time code — no PIN, it
-- starts tracking by itself. The phone keeps a random token; only its
-- SHA-256 is stored. Revoking = setting revoked_at.
CREATE TABLE IF NOT EXISTS `fleet_devices` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`          ENUM('driver','vehicle') NOT NULL DEFAULT 'driver',
  `driver_id`     BIGINT UNSIGNED NULL COMMENT 'NULL for a vehicle phone',
  `token_hash`    CHAR(64) NOT NULL,
  `label`         VARCHAR(160) NULL COMMENT 'phone model / browser',
  `bus_id`        BIGINT UNSIGNED NULL COMMENT 'vehicle phone: its bus, driver phone: the bus it drove last',
  `last_ip`       VARCHAR(45) NULL,
  `created_at`    DATETIME NOT NULL,
  `last_seen_at`  DATETIME NULL,
  `revoked_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_device_token` (`token_hash`),
  KEY `ix_fleet_device_driver` (`driver_id`),
  KEY `ix_fleet_device_bus` (`bus_id`,`kind`),
  CONSTRAINT `fk_fleet_device_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fleet_device_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicle number → its regular driver. A driver who logs in is put straight
-- on "their" bus; a vehicle phone reports this driver's name.
CREATE TABLE IF NOT EXISTS `fleet_vehicle_assign` (
  `bus_id`      BIGINT UNSIGNED NOT NULL,
  `driver_id`   BIGINT UNSIGNED NULL,
  `updated_at`  DATETIME NOT NULL,
  PRIMARY KEY (`bus_id`),
  KEY `ix_fleet_assign_driver` (`driver_id`),
  CONSTRAINT `fk_fleet_assign_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fleet_assign_driver` FOREIGN KEY (`driver_id`)
    REFERENCES `drivers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time codes that pair a phone to a vehicle number (15 minutes, once).
CREATE TABLE IF NOT EXISTS `fleet_pair_codes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bus_id`      BIGINT UNSIGNED NOT NULL,
  `code_hash`   CHAR(64) NOT NULL,
  `expires_at`  DATETIME NOT NULL,
  `used_at`     DATETIME NULL,
  `created_by`  BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_pair_code` (`code_hash`),
  KEY `ix_fleet_pair_bus` (`bus_id`),
  CONSTRAINT `fk_fleet_pair_bus` FOREIGN KEY (`bus_id`)
    REFERENCES `buses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `share_token`     VARCHAR(40) NULL COMMENT 'passenger live link (bus-live.php?t=…)',
  `started_at`      DATETIME NOT NULL,
  `ended_at`        DATETIME NULL,
  `end_reason`      VARCHAR(40) NULL,
  `last_lat`        DECIMAL(10,7) NULL,
  `last_lng`        DECIMAL(10,7) NULL,
  `last_speed_kmh`  DECIMAL(6,1) NULL,
  `avg_speed_kmh`   DECIMAL(6,1) NULL COMMENT 'moving average while moving — the ETA speed',
  `max_speed_kmh`   DECIMAL(6,1) NULL,
  `last_heading`    SMALLINT NULL,
  `last_accuracy_m` DECIMAL(8,1) NULL,
  `last_alt_m`      DECIMAL(7,1) NULL,
  `last_battery`    TINYINT UNSIGNED NULL,
  `last_charging`   TINYINT(1) NULL,
  `last_net`        VARCHAR(12) NULL COMMENT '4g / 3g / 2g / wifi …',
  `last_visible`    TINYINT(1) NULL COMMENT '0 = the driver page was in the background',
  `stopped_since`   DATETIME NULL COMMENT 'standing still since',
  `overspeed_since` DATETIME NULL,
  `last_fix_at`     DATETIME NULL COMMENT 'when the phone took the fix',
  `last_ping_at`    DATETIME NULL COMMENT 'when the server received it',
  `last_trail_at`   DATETIME NULL,
  `distance_km`     DECIMAL(9,2) NOT NULL DEFAULT 0.00,
  `points`          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_trip_share` (`share_token`),
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

-- Like the railways' running status: when the bus really reached and left
-- each stop of its route, beside the timetable time.
CREATE TABLE IF NOT EXISTS `fleet_stop_events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id`      BIGINT UNSIGNED NOT NULL,
  `stop_idx`     SMALLINT UNSIGNED NOT NULL COMMENT 'position in the route line',
  `stop_name`    VARCHAR(191) NOT NULL,
  `sched_at`     DATETIME NULL,
  `arrived_at`   DATETIME NULL,
  `departed_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_stop_event` (`trip_id`,`stop_idx`),
  CONSTRAINT `fk_fleet_stop_trip` FOREIGN KEY (`trip_id`)
    REFERENCES `fleet_trips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Overspeed and long-stop episodes (opened and closed by the GPS), and the
-- driver's one-tap reports from the phone (SOS, breakdown, puncture,
-- police check, meal halt) until the office marks them dealt with.
CREATE TABLE IF NOT EXISTS `fleet_alerts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id`     BIGINT UNSIGNED NOT NULL,
  `bus_id`      BIGINT UNSIGNED NOT NULL,
  `kind`        ENUM('overspeed','stopped','sos','breakdown','puncture','police','meal') NOT NULL,
  `started_at`  DATETIME NOT NULL,
  `ended_at`    DATETIME NULL,
  `peak`        DECIMAL(7,1) NULL COMMENT 'top speed, or minutes stopped',
  `lat`         DECIMAL(10,7) NULL,
  `lng`         DECIMAL(10,7) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fleet_alert_trip` (`trip_id`,`kind`,`ended_at`),
  KEY `ix_fleet_alert_time` (`started_at`),
  CONSTRAINT `fk_fleet_alert_trip` FOREIGN KEY (`trip_id`)
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

-- Towns, villages and Hindu temples along the road (OpenStreetMap), one row
-- per map square of 0.2 degrees (~20 km). Fetched once from Overpass when a
-- bus first drives into the square, then reused — the live map never waits
-- on it. data = JSON list of [kind, lat, lng, name_devanagari, name_english, osm_id].
CREATE TABLE IF NOT EXISTS `fleet_places` (
  `cell`        VARCHAR(16) NOT NULL COMMENT 'floor(lat/0.2):floor(lng/0.2)',
  `status`      ENUM('ok','pending','error') NOT NULL DEFAULT 'pending',
  `fetched_at`  DATETIME NOT NULL,
  `n_items`     INT UNSIGNED NOT NULL DEFAULT 0,
  `data`        MEDIUMTEXT NULL,
  PRIMARY KEY (`cell`),
  KEY `ix_fleet_places_status` (`status`,`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Bring a database that ran an earlier copy of this file up to date.
--  Each block adds one column only when it is missing ('DO 0' otherwise:
--  no result set, because tests/apply-sql.php runs statements with exec()).
-- ---------------------------------------------------------------------
ALTER TABLE `fleet_devices` MODIFY `driver_id` BIGINT UNSIGNED NULL COMMENT 'NULL for a vehicle phone';
ALTER TABLE `fleet_alerts` MODIFY `kind` ENUM('overspeed','stopped','sos','breakdown','puncture','police','meal') NOT NULL;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_devices' AND COLUMN_NAME = 'kind') = 0,
  'ALTER TABLE `fleet_devices` ADD COLUMN `kind` ENUM(''driver'',''vehicle'') NOT NULL DEFAULT ''driver'' AFTER `id`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_trips' AND COLUMN_NAME = 'avg_speed_kmh') = 0,
  'ALTER TABLE `fleet_trips` ADD COLUMN `avg_speed_kmh` DECIMAL(6,1) NULL AFTER `last_speed_kmh`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_trips' AND COLUMN_NAME = 'share_token') = 0,
  'ALTER TABLE `fleet_trips` ADD COLUMN `share_token` VARCHAR(40) NULL AFTER `status`, ADD UNIQUE KEY `uq_fleet_trip_share` (`share_token`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_trips' AND COLUMN_NAME = 'last_charging') = 0,
  'ALTER TABLE `fleet_trips` ADD COLUMN `max_speed_kmh` DECIMAL(6,1) NULL AFTER `avg_speed_kmh`, ADD COLUMN `last_alt_m` DECIMAL(7,1) NULL AFTER `last_accuracy_m`, ADD COLUMN `last_charging` TINYINT(1) NULL AFTER `last_battery`, ADD COLUMN `last_net` VARCHAR(12) NULL AFTER `last_charging`, ADD COLUMN `last_visible` TINYINT(1) NULL AFTER `last_net`, ADD COLUMN `stopped_since` DATETIME NULL AFTER `last_visible`, ADD COLUMN `overspeed_since` DATETIME NULL AFTER `stopped_since`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('fleet_keep_days',      '30', 'int',    'liveops', 'Live Fleet: keep the GPS trail of each trip this many days', 0),
('fleet_tv_key',         '',   'string', 'liveops', 'Live Fleet: secret key in the TV wall link (empty = TV needs a staff login)', 0),
('fleet_overpass_url',   'https://overpass-api.de/api/interpreter', 'string', 'liveops', 'Live Fleet: OpenStreetMap Overpass server for town / temple names on the road', 0),
('fleet_speed_limit',    '80', 'int',    'liveops', 'Live Fleet: overspeed alert above this many km/h', 0),
('fleet_stop_alert_min', '20', 'int',    'liveops', 'Live Fleet: alert when a running bus stands still this many minutes', 0);
