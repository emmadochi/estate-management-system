-- Emergency Alerts Table
-- Tracks all emergency alerts from tenants and their response status

CREATE TABLE IF NOT EXISTS `emergency_alerts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_number` VARCHAR(50) UNIQUE NOT NULL,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NOT NULL,
  `alert_type` ENUM('medical', 'fire', 'security_breach', 'theft', 'assault', 'other') NOT NULL,
  `severity_level` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'high',
  `description` TEXT,
  `location` VARCHAR(255),
  `reported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` DATETIME NULL,
  `acknowledged_by` INT(11) UNSIGNED NULL,
  `responded_at` DATETIME NULL,
  `responded_by` INT(11) UNSIGNED NULL,
  `resolved_at` DATETIME NULL,
  `resolution_notes` TEXT,
  `status` ENUM('reported', 'acknowledged', 'responding', 'resolved', 'closed', 'escalated') DEFAULT 'reported',
  `response_time_seconds` INT(11) NULL,
  `resolution_time_seconds` INT(11) NULL,
  `is_silent` BOOLEAN DEFAULT FALSE, -- For non-disruptive alerts
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`acknowledged_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_tenant` (`tenant_id`),
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_reported` (`reported_at`),
  INDEX `idx_alert_number` (`alert_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample emergency alert types into existing announcements table for quick access
INSERT INTO `announcements` (`estate_id`, `title`, `content`, `type`, `priority`, `target_audience`, `send_push`, `status`, `created_by`) 
SELECT 
    e.id,
    'Emergency Alert System Active',
    'The emergency alert system is now active. Tenants can report emergencies using the emergency button on their dashboard.',
    'emergency',
    'urgent',
    'all',
    TRUE,
    'published',
    (SELECT id FROM users WHERE role = 'super_admin' LIMIT 1)
FROM estates e
WHERE NOT EXISTS (
    SELECT 1 FROM announcements 
    WHERE estate_id = e.id 
    AND title = 'Emergency Alert System Active'
    AND type = 'emergency'
);