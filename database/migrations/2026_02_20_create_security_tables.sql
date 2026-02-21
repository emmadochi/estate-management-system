-- Migration: Create Security Management Tables
-- Adds security personnel, gate passes, emergency incidents, and incident reports tables

-- Create security_personnel table (this extends users table with security-specific info)
CREATE TABLE IF NOT EXISTS `security_personnel` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `badge_number` VARCHAR(50) UNIQUE,
  `rank` VARCHAR(100),
  `post_assigned` VARCHAR(255),
  `shift_schedule` ENUM('morning', 'afternoon', 'night', 'flexible') DEFAULT 'morning',
  `contact_emergency` VARCHAR(20),
  `date_hired` DATE,
  `supervisor_id` INT(11) UNSIGNED NULL,
  `status` ENUM('active', 'inactive', 'suspended', 'transferred') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`supervisor_id`) REFERENCES `security_personnel`(`id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_badge` (`badge_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create gate_passes table
CREATE TABLE IF NOT EXISTS `gate_passes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `pass_number` VARCHAR(50) UNIQUE NOT NULL,
  `pass_type` ENUM('single_use', 'daily', 'weekly', 'monthly', 'permanent') NOT NULL,
  `visitor_name` VARCHAR(255) NOT NULL,
  `visitor_phone` VARCHAR(20),
  `visitor_vehicle_details` VARCHAR(255),
  `issued_to` VARCHAR(255), -- Person/unit receiving visitor
  `unit_id` INT(11) UNSIGNED NULL, -- Specific unit if applicable
  `purpose_of_visit` TEXT,
  `valid_from` DATETIME NOT NULL,
  `valid_until` DATETIME NOT NULL,
  `issued_by` INT(11) UNSIGNED NOT NULL,
  `revoked_by` INT(11) UNSIGNED NULL,
  `revoked_at` DATETIME NULL,
  `revoked_reason` TEXT,
  `status` ENUM('active', 'used', 'expired', 'revoked') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`revoked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_pass_number` (`pass_number`),
  INDEX `idx_status` (`status`),
  INDEX `idx_validity` (`valid_from`, `valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create emergency_incidents table
CREATE TABLE IF NOT EXISTS `emergency_incidents` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `incident_number` VARCHAR(50) UNIQUE NOT NULL,
  `type` ENUM('fire', 'medical', 'security_breach', 'theft', 'vandalism', 'natural_disaster', 'other') NOT NULL,
  `severity_level` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  `location` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `reported_by` INT(11) UNSIGNED NOT NULL,
  `assigned_officer` INT(11) UNSIGNED NULL, -- Security officer assigned to handle
  `response_team_contacted` BOOLEAN DEFAULT FALSE,
  `response_team_contacted_at` DATETIME NULL,
  `initial_response_time` DATETIME NULL,
  `resolution_time` DATETIME NULL,
  `status` ENUM('reported', 'in_progress', 'resolved', 'closed', 'escalated') DEFAULT 'reported',
  `resolution_notes` TEXT,
  `evidence_photos` JSON NULL, -- Store paths to evidence photos
  `follow_up_required` BOOLEAN DEFAULT FALSE,
  `follow_up_completed_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`assigned_officer`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_incident_number` (`incident_number`),
  INDEX `idx_type` (`type`),
  INDEX `idx_severity` (`severity_level`),
  INDEX `idx_status` (`status`),
  INDEX `idx_reported_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create incident_reports table
CREATE TABLE IF NOT EXISTS `incident_reports` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `report_number` VARCHAR(50) UNIQUE NOT NULL,
  `incident_id` INT(11) UNSIGNED NULL, -- Link to emergency incident if applicable
  `report_type` ENUM('security', 'safety', 'compliance', 'access_violation', 'property_damage', 'behavioral', 'other') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `reported_by` INT(11) UNSIGNED NOT NULL,
  `witnesses` JSON NULL, -- Store witness information as JSON
  `affected_parties` JSON NULL, -- Store affected parties as JSON
  `evidence_attachments` JSON NULL, -- Store paths to evidence files
  `location` VARCHAR(255),
  `occurred_at` DATETIME NOT NULL,
  `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `assigned_to` INT(11) UNSIGNED NULL, -- Officer assigned to investigate
  `investigation_status` ENUM('pending', 'in_progress', 'completed', 'dismissed') DEFAULT 'pending',
  `investigation_findings` TEXT,
  `recommendations` TEXT,
  `disciplinary_action_taken` TEXT,
  `status` ENUM('draft', 'submitted', 'under_review', 'resolved', 'closed') DEFAULT 'draft',
  `resolved_at` DATETIME NULL,
  `resolved_by` INT(11) UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`incident_id`) REFERENCES `emergency_incidents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_report_number` (`report_number`),
  INDEX `idx_type` (`report_type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_occurred_at` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create patrol_routes table
CREATE TABLE IF NOT EXISTS `patrol_routes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `route_name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `checkpoints` JSON NOT NULL, -- Store patrol checkpoints as JSON
  `frequency` ENUM('hourly', 'every_2_hours', 'every_4_hours', 'daily', 'twice_daily') DEFAULT 'hourly',
  `estimated_duration_minutes` INT(11) DEFAULT 30,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_by` INT(11) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create patrol_logs table
CREATE TABLE IF NOT EXISTS `patrol_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `patrol_route_id` INT(11) UNSIGNED NOT NULL,
  `officer_id` INT(11) UNSIGNED NOT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NULL,
  `status` ENUM('in_progress', 'completed', 'interrupted') DEFAULT 'in_progress',
  `checkpoints_completed` JSON NULL, -- Track which checkpoints were completed
  `findings` TEXT,
  `incidents_found` TEXT,
  `photos` JSON NULL, -- Store patrol photos as JSON
  `completed_by` INT(11) UNSIGNED NULL, -- Officer who completed the patrol
  `completed_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`patrol_route_id`) REFERENCES `patrol_routes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`officer_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_route` (`patrol_route_id`),
  INDEX `idx_officer` (`officer_id`),
  INDEX `idx_start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample security personnel records for existing security users
-- First, we'll create security personnel entries for users with 'security' role
INSERT IGNORE INTO security_personnel (user_id, estate_id, badge_number, post_assigned, created_at)
SELECT 
    u.id,
    COALESCE(ue.estate_id, 1) as estate_id,
    CONCAT('SEC-', LPAD(u.id, 4, '0')) as badge_number,
    'Main Gate' as post_assigned,
    NOW()
FROM users u
LEFT JOIN user_estates ue ON u.id = ue.user_id
WHERE u.role = 'security'
ON DUPLICATE KEY UPDATE updated_at = NOW();