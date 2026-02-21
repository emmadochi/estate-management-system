-- Add quote/approval/payment fields to maintenance_tickets

ALTER TABLE `maintenance_tickets`
ADD COLUMN `quoted_cost` DECIMAL(12,2) DEFAULT 0.00 AFTER `cost`,
ADD COLUMN `quote_status` ENUM('none','submitted','approved','rejected') DEFAULT 'none' AFTER `quoted_cost`,
ADD COLUMN `quoted_at` DATETIME NULL AFTER `quote_status`,
ADD COLUMN `approved_at` DATETIME NULL AFTER `quoted_at`,
ADD COLUMN `approved_by` INT(11) UNSIGNED NULL AFTER `approved_at`,
ADD COLUMN `paid_status` ENUM('unpaid','paid') DEFAULT 'unpaid' AFTER `approved_by`,
ADD COLUMN `paid_at` DATETIME NULL AFTER `paid_status`;

ALTER TABLE `maintenance_tickets`
ADD CONSTRAINT `fk_maintenance_tickets_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Work log / updates table for maintenance tickets

CREATE TABLE IF NOT EXISTS `maintenance_ticket_updates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `updated_by` INT(11) UNSIGNED NOT NULL,
  `from_status` ENUM('open','assigned','in_progress','resolved','closed','cancelled') NULL,
  `to_status` ENUM('open','assigned','in_progress','resolved','closed','cancelled') NULL,
  `note` TEXT NULL,
  `quoted_cost` DECIMAL(12,2) NULL,
  `actual_cost` DECIMAL(12,2) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_updated_by` (`updated_by`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

