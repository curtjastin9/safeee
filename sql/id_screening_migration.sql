-- =====================================================================
-- SafeTrade · AI-assisted ID screening — Phase 1 migration
-- Target: MariaDB 10.4 (XAMPP). Idempotent: safe to re-run any number of times.
-- No foreign keys on purpose, so deleting a user never gets blocked by these tables.
--
-- Run:  mysql -u root safetrade < sql/id_screening_migration.sql
--   or: phpMyAdmin -> safetrade -> Import (or paste into the SQL tab).
-- =====================================================================

-- 1) One row per screening run (auto decisions, quality-gate retries, admin decisions).
CREATE TABLE IF NOT EXISTS `id_screenings` (
  `id`                 int(11)       NOT NULL AUTO_INCREMENT,
  `user_id`            int(11)       NOT NULL,
  `government_id_id`   int(11)       NOT NULL DEFAULT 0,
  `attempt_no`         int(11)       NOT NULL DEFAULT 1,
  `source`             varchar(10)   NOT NULL DEFAULT 'auto' COMMENT 'auto | admin',
  `outcome`            enum('pass','admin','reject','retry') NOT NULL,
  `risk_level`         enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `authenticity_score` tinyint(3) unsigned DEFAULT NULL COMMENT 'Phase 2: 0-100, NULL = not run',
  `face_distance`      decimal(6,4)  DEFAULT NULL COMMENT 'Phase 3: ID photo vs registered face, NULL = not run',
  `detected_id_type`   varchar(60)   DEFAULT NULL,
  `extracted_json`     longtext      DEFAULT NULL COMMENT 'masked/parsed fields (never the full ID number)',
  `checks_json`        longtext      DEFAULT NULL COMMENT '[{name,result,severity,detail,user_msg}]',
  `reasons`            text          DEFAULT NULL,
  `created_at`         datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_idscreen_user` (`user_id`, `created_at`),
  KEY `idx_idscreen_outcome` (`outcome`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Appeals / "please have an admin look at this" requests.
CREATE TABLE IF NOT EXISTS `id_appeals` (
  `id`           int(11)     NOT NULL AUTO_INCREMENT,
  `user_id`      int(11)     NOT NULL,
  `screening_id` int(11)     DEFAULT NULL,
  `message`      text        NOT NULL,
  `status`       enum('open','resolved') NOT NULL DEFAULT 'open',
  `resolved_by`  int(11)     DEFAULT NULL,
  `resolved_at`  datetime    DEFAULT NULL,
  `created_at`   datetime    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_idappeal_user` (`user_id`, `status`),
  KEY `idx_idappeal_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) government_ids: salted ID-number hash + image hashes (MariaDB supports IF NOT EXISTS here).
ALTER TABLE `government_ids`
  ADD COLUMN IF NOT EXISTS `id_number_hash` char(64) DEFAULT NULL COMMENT 'HMAC-SHA256(salt, type|number); never the plaintext',
  ADD COLUMN IF NOT EXISTS `image_hash`     char(64) DEFAULT NULL COMMENT 'SHA-256 of the raw uploaded front image',
  ADD COLUMN IF NOT EXISTS `image_phash`    char(16) DEFAULT NULL COMMENT '64-bit difference hash (hex) of the front image';

ALTER TABLE `government_ids`
  ADD INDEX IF NOT EXISTS `idx_gid_number_hash` (`id_number_hash`),
  ADD INDEX IF NOT EXISTS `idx_gid_image_hash`  (`image_hash`);

-- 4) users: failed-screening counter (only auto-rejects count; quality retries do not).
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `id_attempts` int(11) NOT NULL DEFAULT 0;
