-- Enhanced Maintenance Workflow with Tenant Confirmation
-- Professional multi-stage workflow implementation

-- 1. Enhanced maintenance_tickets table with new workflow fields
ALTER TABLE `maintenance_tickets`
MODIFY `status` ENUM('requested', 'assigned', 'accepted', 'in_progress', 'work_completed', 'tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed', 'cancelled') DEFAULT 'requested',
ADD COLUMN `tenant_confirmed` BOOLEAN DEFAULT FALSE AFTER `resolved_at`,
ADD COLUMN `tenant_confirmation_date` DATETIME NULL AFTER `tenant_confirmed`,
ADD COLUMN `tenant_feedback` TEXT NULL AFTER `tenant_confirmation_date`,
ADD COLUMN `work_quality_rating` TINYINT NULL AFTER `tenant_feedback`,
ADD COLUMN `completion_notes` TEXT NULL AFTER `work_quality_rating`,
ADD COLUMN `completion_photo` VARCHAR(255) NULL AFTER `completion_notes`,
ADD COLUMN `expected_completion_date` DATE NULL AFTER `completion_photo`,
ADD COLUMN `actual_completion_date` DATETIME NULL AFTER `expected_completion_date`,
ADD COLUMN `progress_updates` JSON NULL AFTER `actual_completion_date`;

-- 2. Create maintenance_progress_updates table for detailed tracking
CREATE TABLE IF NOT EXISTS `maintenance_progress_updates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `updated_by` INT(11) UNSIGNED NOT NULL,
  `status_from` ENUM('requested', 'assigned', 'accepted', 'in_progress', 'work_completed', 'tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed', 'cancelled') NULL,
  `status_to` ENUM('requested', 'assigned', 'accepted', 'in_progress', 'work_completed', 'tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed', 'cancelled') NOT NULL,
  `notes` TEXT NULL,
  `photos` JSON NULL,
  `progress_percentage` TINYINT DEFAULT 0,
  `estimated_completion` DATE NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_status_change` (`status_from`, `status_to`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create tenant_confirmations table for formal verification
CREATE TABLE IF NOT EXISTS `tenant_confirmations` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `confirmation_status` ENUM('pending', 'confirmed', 'rejected', 'revision_requested') DEFAULT 'pending',
  `quality_rating` TINYINT NULL,
  `feedback` TEXT NULL,
  `confirmation_notes` TEXT NULL,
  `confirmation_photo` VARCHAR(255) NULL,
  `confirmed_by` INT(11) UNSIGNED NULL,
  `confirmed_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_ticket_tenant` (`ticket_id`, `tenant_id`),
  INDEX `idx_status` (`confirmation_status`),
  INDEX `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Add notification preferences for users
ALTER TABLE `users`
ADD COLUMN `maintenance_notifications` BOOLEAN DEFAULT TRUE AFTER `status`,
ADD COLUMN `notification_methods` SET('email', 'sms', 'push') DEFAULT 'email' AFTER `maintenance_notifications`;

-- 5. Create maintenance_notifications table
CREATE TABLE IF NOT EXISTS `maintenance_notifications` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `notification_type` ENUM('status_change', 'assignment', 'completion', 'confirmation_request', 'payment_due', 'overdue') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` BOOLEAN DEFAULT FALSE,
  `read_at` DATETIME NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `maintenance_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_ticket` (`ticket_id`),
  INDEX `idx_type` (`notification_type`),
  INDEX `idx_unread` (`is_read`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;