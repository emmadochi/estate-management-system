-- Security Management System Migration
-- Adds comprehensive security features to the estate management system

-- ============================================
-- 1. SECURITY PERSONNEL MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS `security_personnel` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `badge_number` VARCHAR(50) UNIQUE,
  `shift_schedule` ENUM('morning', 'afternoon', 'night', 'rotating') DEFAULT 'morning',
  `post_assigned` VARCHAR(255),
  `supervisor_id` INT(11) UNSIGNED NULL,
  `license_number` VARCHAR(100),
  `certifications` TEXT,
  `emergency_contact_name` VARCHAR(255),
  `emergency_contact_phone` VARCHAR(20),
  `date_hired` DATE,
  `status` ENUM('active', 'inactive', 'suspended', 'terminated') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`supervisor_id`) REFERENCES `security_personnel`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_badge_number` (`badge_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. EMERGENCY INCIDENTS TRACKING
-- ============================================

CREATE TABLE IF NOT EXISTS `emergency_incidents` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `incident_type` ENUM('fire', 'medical', 'break_in', 'disturbance', 'accident', 'natural_disaster', 'other') NOT NULL,
  `severity_level` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  `location` VARCHAR(255) NOT NULL,
  `reported_by` INT(11) UNSIGNED NOT NULL,
  `security_officer_id` INT(11) UNSIGNED NULL,
  `description` TEXT NOT NULL,
  `reported_at` DATETIME NOT NULL,
  `response_started_at` DATETIME NULL,
  `resolved_at` DATETIME NULL,
  `resolution_details` TEXT,
  `affected_units` JSON NULL,
  `evacuation_required` BOOLEAN DEFAULT FALSE,
  `police_report_filed` BOOLEAN DEFAULT FALSE,
  `police_report_number` VARCHAR(100),
  `fire_department_notified` BOOLEAN DEFAULT FALSE,
  `ambulance_called` BOOLEAN DEFAULT FALSE,
  `status` ENUM('reported', 'in_progress', 'resolved', 'closed') DEFAULT 'reported',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`security_officer_id`) REFERENCES `security_personnel`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_type` (`incident_type`),
  INDEX `idx_severity` (`severity_level`),
  INDEX `idx_status` (`status`),
  INDEX `idx_reported_at` (`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. ENHANCED VISITOR MANAGEMENT
-- ============================================

-- Add emergency contact and additional fields to visitor logs if not already present
ALTER TABLE `visitor_logs` 
ADD COLUMN IF NOT EXISTS `vehicle_registration` VARCHAR(50),
ADD COLUMN IF NOT EXISTS `driver_license` VARCHAR(50),
ADD COLUMN IF NOT EXISTS `host_name` VARCHAR(255),
ADD COLUMN IF NOT EXISTS `host_phone` VARCHAR(20),
ADD COLUMN IF NOT EXISTS `security_check_required` BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS `security_check_completed_by` INT(11) UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS `security_check_completed_at` DATETIME NULL,
ADD COLUMN IF NOT EXISTS `special_instructions` TEXT,
ADD COLUMN IF NOT EXISTS `emergency_contact_visitor` VARCHAR(255),
ADD COLUMN IF NOT EXISTS `emergency_contact_phone_visitor` VARCHAR(20),
ADD FOREIGN KEY (`security_check_completed_by`) REFERENCES `security_personnel`(`id`) ON DELETE SET NULL;

-- ============================================
-- 4. GATE PASSES MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS `gate_passes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `pass_type` ENUM('single_use', 'daily', 'weekly', 'monthly', 'permanent') NOT NULL,
  `pass_number` VARCHAR(50) UNIQUE NOT NULL,
  `qr_code` VARCHAR(255),
  `valid_from` DATETIME NOT NULL,
  `valid_until` DATETIME NOT NULL,
  `recipient_name` VARCHAR(255) NOT NULL,
  `recipient_phone` VARCHAR(20),
  `recipient_email` VARCHAR(255),
  `vehicle_registration` VARCHAR(50),
  `driver_license` VARCHAR(50),
  `purpose_of_visit` VARCHAR(255),
  `access_areas` JSON NULL,
  `issued_by` INT(11) UNSIGNED NOT NULL,
  `issued_to` INT(11) UNSIGNED NULL,
  `used_count` INT(11) DEFAULT 0,
  `max_uses` INT(11) DEFAULT 1,
  `status` ENUM('active', 'used', 'expired', 'revoked') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`issued_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_pass_number` (`pass_number`),
  INDEX `idx_status` (`status`),
  INDEX `idx_valid_until` (`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. SECURITY PATROL LOGS
-- ============================================

CREATE TABLE IF NOT EXISTS `security_patrol_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `security_officer_id` INT(11) UNSIGNED NOT NULL,
  `patrol_route` VARCHAR(255),
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NULL,
  `status` ENUM('in_progress', 'completed', 'interrupted') DEFAULT 'in_progress',
  `location_checkpoints` JSON NULL,
  `incidents_reported` TEXT,
  `photos_evidence` JSON NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`security_officer_id`) REFERENCES `security_personnel`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_officer` (`security_officer_id`),
  INDEX `idx_start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. SECURITY INCIDENT REPORTS
-- ============================================

CREATE TABLE IF NOT EXISTS `security_incident_reports` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `report_number` VARCHAR(50) UNIQUE NOT NULL,
  `incident_id` INT(11) UNSIGNED NULL,
  `reported_by` INT(11) UNSIGNED NOT NULL,
  `security_officer_id` INT(11) UNSIGNED NOT NULL,
  `incident_date` DATE NOT NULL,
  `incident_time` TIME NOT NULL,
  `location` VARCHAR(255) NOT NULL,
  `incident_type` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `witnesses` JSON NULL,
  `evidence_photos` JSON NULL,
  `action_taken` TEXT,
  `recommendations` TEXT,
  `follow_up_required` BOOLEAN DEFAULT FALSE,
  `follow_up_completed` BOOLEAN DEFAULT FALSE,
  `follow_up_completed_at` DATETIME NULL,
  `status` ENUM('draft', 'submitted', 'reviewed', 'closed') DEFAULT 'draft',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`incident_id`) REFERENCES `emergency_incidents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`security_officer_id`) REFERENCES `security_personnel`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_report_number` (`report_number`),
  INDEX `idx_incident_date` (`incident_date`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. SECURITY EQUIPMENT MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS `security_equipment` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `equipment_name` VARCHAR(255) NOT NULL,
  `equipment_type` ENUM('camera', 'alarm', 'intercom', 'access_control', 'lighting', 'other') NOT NULL,
  `serial_number` VARCHAR(100),
  `installation_date` DATE,
  `warranty_expiry` DATE,
  `status` ENUM('operational', 'maintenance', 'out_of_order', 'decommissioned') DEFAULT 'operational',
  `location_installed` VARCHAR(255),
  `assigned_to` INT(11) UNSIGNED NULL,
  `last_maintenance_date` DATE,
  `next_maintenance_date` DATE,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `security_personnel`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_type` (`equipment_type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_serial_number` (`serial_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. SECURITY DUTY SCHEDULES
-- ============================================

CREATE TABLE IF NOT EXISTS `security_duty_schedules` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `officer_id` INT(11) UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `shift_start` TIME NOT NULL,
  `shift_end` TIME NOT NULL,
  `shift_type` ENUM('morning', 'afternoon', 'night', 'overtime') NOT NULL,
  `location_posted` VARCHAR(255),
  `duties_assigned` TEXT,
  `status` ENUM('scheduled', 'completed', 'absent', 'substituted') DEFAULT 'scheduled',
  `actual_start_time` DATETIME NULL,
  `actual_end_time` DATETIME NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`officer_id`) REFERENCES `security_personnel`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_date` (`date`),
  INDEX `idx_officer` (`officer_id`),
  INDEX `idx_status` (`status`),
  UNIQUE KEY `unique_schedule` (`officer_id`, `date`, `shift_start`, `shift_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. UPDATE EXISTING VISITOR_LOGS FOR BETTER SECURITY INTEGRATION
-- ============================================

-- Ensure proper indexing for security-related queries
CREATE INDEX IF NOT EXISTS `idx_visitor_security_fields` ON `visitor_logs` (`gate_pass_number`, `status`, `entry_time`);

-- ============================================
-- 10. INSERT DEFAULT SECURITY-RELATED SETTINGS
-- ============================================

-- Insert default settings for security features if they don't exist
INSERT INTO `settings` (`estate_id`, `key`, `value`, `type`, `description`) 
SELECT NULL, 'security_features_enabled', 'true', 'boolean', 'Enable security management features'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'security_features_enabled');

INSERT INTO `settings` (`estate_id`, `key`, `value`, `type`, `description`) 
SELECT NULL, 'visitor_approval_required', 'false', 'boolean', 'Require approval for visitor access'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'visitor_approval_required');