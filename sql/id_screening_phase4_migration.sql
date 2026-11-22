-- =====================================================================
-- SafeTrade · ID screening — Phase 4 migration (threshold tuning). Idempotent.
-- Run after the Phase 1-3 migrations:  mysql -u root safetrade < sql/id_screening_phase4_migration.sql
-- =====================================================================

-- 1) One row per screening that an admin later judged. A SNAPSHOT of the numbers the system saw
--    plus the admin's verdict. Holds NO personal data (no user id, name, email or ID number), so it
--    survives deleting the account and can be kept for tuning.
CREATE TABLE IF NOT EXISTS `id_tuning_labels` (
  `id`                 int(11)       NOT NULL AUTO_INCREMENT,
  `screening_id`       int(11)       NOT NULL COMMENT 'id_screenings.id the verdict is about (no FK on purpose)',
  `system_outcome`     enum('pass','admin','reject') NOT NULL,
  `risk_level`         enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `was_audit`          tinyint(1)    NOT NULL DEFAULT 0 COMMENT '1 = random audit sample of a would-be pass',
  `face_distance`      decimal(6,4)  DEFAULT NULL,
  `face_reliable`      tinyint(1)    DEFAULT NULL COMMENT '1 = detector score and face size were adequate',
  `authenticity_score` tinyint(3) unsigned DEFAULT NULL,
  `id_type`            varchar(60)   DEFAULT NULL,
  `checks_json`        text          DEFAULT NULL COMMENT '[[name,result],...] only',
  `verdict`            enum('approved','rejected') NOT NULL COMMENT 'what the admin finally decided',
  `verdict_via`        varchar(30)   NOT NULL DEFAULT '' COMMENT 'verify | revoke | reject_retry | reject_delete',
  `decided_by`         int(11)       DEFAULT NULL,
  `decided_at`         datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tuning_screening` (`screening_id`),
  KEY `idx_tuning_outcome` (`system_outcome`, `verdict`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Threshold overrides chosen by an admin on the Tuning page (whitelisted keys only, bounds checked in PHP).
CREATE TABLE IF NOT EXISTS `id_settings` (
  `k`          varchar(40)  NOT NULL,
  `v`          varchar(40)  NOT NULL,
  `updated_by` int(11)      DEFAULT NULL,
  `updated_at` datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
