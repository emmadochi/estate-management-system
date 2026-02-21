-- Add estate scoping to audit logs (Option B).
-- Run this on your database: estate_management

ALTER TABLE `audit_logs`
  ADD COLUMN `estate_id` INT(11) UNSIGNED NULL AFTER `user_id`,
  ADD INDEX `idx_estate` (`estate_id`),
  ADD CONSTRAINT `fk_audit_logs_estate`
    FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE SET NULL;

