-- BCRF Founding Member 30-minute reservations — phase 1 schema migration
-- Run once in phpMyAdmin only after taking a database backup.
-- Does not alter existing pass statuses, payment references, or dates.
-- Code deployment is a separate coordinated phase.
ALTER TABLE passes
  ADD COLUMN reservation_expires_at DATETIME NULL DEFAULT NULL AFTER payment_reference,
  ADD INDEX idx_pass_reservation (pass_type_id, status, reservation_expires_at);

-- Read-only post-migration verification:
-- SHOW COLUMNS FROM passes LIKE 'reservation_expires_at';
-- SHOW INDEX FROM passes WHERE Key_name = 'idx_pass_reservation';
