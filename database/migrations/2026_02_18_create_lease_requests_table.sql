-- Create lease_requests table for tenant lease and renewal requests

CREATE TABLE IF NOT EXISTS `lease_requests` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `tenant_id` INT(11) UNSIGNED NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NULL,
  `type` ENUM('new', 'renewal', 'transfer') DEFAULT 'renewal',
  `status` ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
  `preferred_start_date` DATE NULL,
  `notes` TEXT,
  `admin_notes` TEXT,
  `decided_by` INT(11) UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`decided_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_tenant` (`tenant_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
