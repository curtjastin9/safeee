-- =====================================================================
-- SafeTrade · ID screening — Phase 3 migration (ID photo vs registered face)
-- Idempotent (safe to re-run). Run AFTER sql/id_screening_migration.sql.
-- Without it, Phase 3 still works but cannot detect "same face on another account's ID".
-- =====================================================================
ALTER TABLE `government_ids`
  ADD COLUMN IF NOT EXISTS `id_face_descriptor` longtext DEFAULT NULL
  COMMENT '128-float face descriptor of the photo ON the ID (computed in the browser by face-api.js)';
