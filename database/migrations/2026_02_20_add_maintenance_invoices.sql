-- Add maintenance invoice and payment system
-- Creates tables for maintenance invoices and payments

CREATE TABLE IF NOT EXISTS `maintenance_invoices` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(50) UNIQUE NOT NULL,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `vendor_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `description` TEXT,
  `due_date` DATE NOT NULL,
  `status` ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
  `paid_amount` DECIMAL(12,2) DEFAULT 0.00,
  `approved_by` INT(11) UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_vendor` (`vendor_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_payments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_reference` VARCHAR(100) UNIQUE NOT NULL,
  `invoice_id` INT(11) UNSIGNED NOT NULL,
  `vendor_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('cash', 'bank_transfer', 'card', 'other') NOT NULL,
  `transaction_id` VARCHAR(255),
  `status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
  `payment_date` DATETIME NOT NULL,
  `receipt_number` VARCHAR(50),
  `receipt_file` VARCHAR(255),
  `notes` TEXT,
  `paid_by` INT(11) UNSIGNED NULL,
  `confirmed_by_vendor` BOOLEAN DEFAULT FALSE,
  `vendor_confirmation_date` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `maintenance_invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`paid_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_invoice` (`invoice_id`),
  INDEX `idx_vendor` (`vendor_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_payment_date` (`payment_date`),
  INDEX `idx_reference` (`payment_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key to maintenance_tickets for invoice reference
ALTER TABLE `maintenance_tickets`
ADD COLUMN `invoice_id` INT(11) UNSIGNED NULL AFTER `paid_at`,
ADD CONSTRAINT `fk_maintenance_tickets_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `maintenance_invoices`(`id`) ON DELETE SET NULL;