-- Quality Assurance Tables for Maintenance Workflow

-- 1. QA Checklist Items
CREATE TABLE IF NOT EXISTS `maintenance_qa_checklist` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `vendor_id` INT(11) UNSIGNED NOT NULL,
  `item_description` VARCHAR(255) NOT NULL,
  `is_critical` BOOLEAN DEFAULT FALSE,
  `completed` BOOLEAN DEFAULT FALSE,
  `completed_at` DATETIME NULL,
  `completed_by` INT(11) UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_vendor` (`vendor_id`),
  INDEX `idx_critical` (`is_critical`),
  INDEX `idx_completed` (`completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Quality Issues Tracking
CREATE TABLE IF NOT EXISTS `maintenance_quality_issues` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `issue_description` TEXT NOT NULL,
  `severity` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  `flagged_by` INT(11) UNSIGNED NOT NULL,
  `flagged_at` DATETIME NOT NULL,
  `assigned_to` INT(11) UNSIGNED NULL,
  `status` ENUM('pending', 'in_review', 'resolved', 'dismissed') DEFAULT 'pending',
  `resolution_notes` TEXT NULL,
  `resolved_at` DATETIME NULL,
  `resolved_by` INT(11) UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`flagged_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_severity` (`severity`),
  INDEX `idx_flagged` (`flagged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Vendor Performance Tracking
CREATE TABLE IF NOT EXISTS `vendor_performance_metrics` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` INT(11) UNSIGNED NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `total_jobs` INT(11) DEFAULT 0,
  `completed_jobs` INT(11) DEFAULT 0,
  `completion_rate` DECIMAL(5,2) DEFAULT 0.00,
  `avg_quality_rating` DECIMAL(3,2) DEFAULT 0.00,
  `quality_score` DECIMAL(5,2) DEFAULT 0.00,
  `high_rating_jobs` INT(11) DEFAULT 0,
  `medium_rating_jobs` INT(11) DEFAULT 0,
  `low_rating_jobs` INT(11) DEFAULT 0,
  `avg_completion_delay` DECIMAL(5,2) DEFAULT 0.00,
  `performance_rating` ENUM('Excellent', 'Good', 'Satisfactory', 'Needs Improvement', 'Poor') DEFAULT 'Satisfactory',
  `notes` TEXT NULL,
  `calculated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_vendor_period` (`vendor_id`, `period_start`, `period_end`),
  INDEX `idx_vendor` (`vendor_id`),
  INDEX `idx_period` (`period_start`, `period_end`),
  INDEX `idx_performance` (`performance_rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;