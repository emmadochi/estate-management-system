-- Professional Emergency Alert System Database Enhancements

-- Audible alerts table for real-time sound notifications
CREATE TABLE IF NOT EXISTS `emergency_audible_alerts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` INT(11) UNSIGNED NOT NULL,
  `alert_data` JSON NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `played_for` JSON NULL, -- Track which security personnel have received the alert
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`alert_id`) REFERENCES `emergency_alerts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate_created` (`estate_id`, `created_at`),
  INDEX `idx_alert` (`alert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emergency escalation tracking
CREATE TABLE IF NOT EXISTS `emergency_escalations` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` INT(11) UNSIGNED NOT NULL,
  `escalation_level` ENUM('level_1', 'level_2', 'level_3', 'external') NOT NULL,
  `trigger_time` DATETIME NOT NULL,
  `escalated_at` DATETIME NULL,
  `escalated_to` JSON NULL, -- External contacts or higher authorities
  `reason` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`alert_id`) REFERENCES `emergency_alerts`(`id`) ON DELETE CASCADE,
  INDEX `idx_alert_level` (`alert_id`, `escalation_level`),
  INDEX `idx_trigger_time` (`trigger_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emergency activity log for audit trail
CREATE TABLE IF NOT EXISTS `emergency_activity_log` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` INT(11) UNSIGNED NOT NULL,
  `activity` VARCHAR(100) NOT NULL,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `details` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`alert_id`) REFERENCES `emergency_alerts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_alert_activity` (`alert_id`, `activity`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhanced notifications table with priority support
ALTER TABLE `notifications` 
ADD COLUMN `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal' AFTER `link`,
ADD COLUMN `expires_at` DATETIME NULL AFTER `priority`,
ADD COLUMN `requires_acknowledgment` BOOLEAN DEFAULT FALSE AFTER `expires_at`;

-- Security personnel escalation roles
ALTER TABLE `security_personnel` 
ADD COLUMN `role_level` ENUM('patrol', 'supervisor', 'manager', 'director') DEFAULT 'patrol' AFTER `status`,
ADD COLUMN `on_duty` BOOLEAN DEFAULT FALSE AFTER `role_level`,
ADD COLUMN `last_heartbeat` TIMESTAMP NULL AFTER `on_duty`;

-- Emergency contact groups for external escalation
CREATE TABLE IF NOT EXISTS `emergency_contact_groups` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `group_name` VARCHAR(100) NOT NULL,
  `group_type` ENUM('police', 'fire', 'medical', 'external_security', 'management') NOT NULL,
  `contact_numbers` JSON NOT NULL,
  `email_addresses` JSON NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate_type` (`estate_id`, `group_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emergency response templates
CREATE TABLE IF NOT EXISTS `emergency_response_templates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` VARCHAR(50) NOT NULL,
  `severity_level` ENUM('low', 'medium', 'high', 'critical') NOT NULL,
  `response_procedure` TEXT NOT NULL,
  `contact_list` JSON NOT NULL,
  `equipment_needed` JSON NULL,
  `estimated_response_time` INT(11) NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_type_severity` (`alert_type`, `severity_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default emergency response templates
INSERT INTO `emergency_response_templates` 
(`alert_type`, `severity_level`, `response_procedure`, `contact_list`, `equipment_needed`, `estimated_response_time`) 
VALUES 
('medical', 'critical', '1. Call ambulance immediately\n2. Provide first aid\n3. Clear path for emergency services\n4. Notify estate manager', 
 '["ambulance", "estate_manager"]', '["first_aid_kit", "stretcher", "oxygen"]', 120),
('fire', 'critical', '1. Sound fire alarm\n2. Evacuate area\n3. Call fire department\n4. Use fire extinguisher if safe', 
 '["fire_department", "estate_manager"]', '["fire_extinguisher", "fire_blanket"]', 60),
('security_breach', 'high', '1. Secure area\n2. Notify all security personnel\n3. Contact police\n4. Monitor situation', 
 '["police", "security_team", "estate_manager"]', '["radio", "security_equipment"]', 180);

-- Insert sample emergency contact groups
INSERT INTO `emergency_contact_groups` (`estate_id`, `group_name`, `group_type`, `contact_numbers`, `email_addresses`)
SELECT 
    e.id,
    'Local Police Department',
    'police',
    JSON_ARRAY('112', '080XXXXXXXX'),
    JSON_ARRAY('emergency@police.gov.ng')
FROM estates e
WHERE NOT EXISTS (
    SELECT 1 FROM emergency_contact_groups 
    WHERE estate_id = e.id AND group_type = 'police'
);

INSERT INTO `emergency_contact_groups` (`estate_id`, `group_name`, `group_type`, `contact_numbers`, `email_addresses`)
SELECT 
    e.id,
    'Fire Emergency Services',
    'fire',
    JSON_ARRAY('112', '080XXXXXXXX'),
    JSON_ARRAY('emergency@fire.gov.ng')
FROM estates e
WHERE NOT EXISTS (
    SELECT 1 FROM emergency_contact_groups 
    WHERE estate_id = e.id AND group_type = 'fire'
);