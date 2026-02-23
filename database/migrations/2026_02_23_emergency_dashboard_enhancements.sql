-- Emergency Dashboard Professional Enhancements
-- Database migration for advanced emergency management system

-- Table for emergency progress tracking and updates
CREATE TABLE IF NOT EXISTS `emergency_progress_updates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `emergency_id` INT(11) UNSIGNED NOT NULL,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `user_role` ENUM('tenant', 'security', 'admin', 'super_admin', 'property_manager') NOT NULL,
  `status_update` TEXT NOT NULL,
  `update_type` ENUM('status_change', 'progress_note', 'resolution_update', 'external_contact') DEFAULT 'progress_note',
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`emergency_id`) REFERENCES `emergency_incidents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_emergency_timestamp` (`emergency_id`, `timestamp`),
  INDEX `idx_user_role` (`user_id`, `user_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for emergency assignments tracking
CREATE TABLE IF NOT EXISTS `emergency_assignments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `emergency_id` INT(11) UNSIGNED NOT NULL,
  `personnel_id` INT(11) UNSIGNED NOT NULL,
  `assigned_by` INT(11) UNSIGNED NOT NULL,
  `assignment_type` ENUM('primary', 'support', 'supervisor') DEFAULT 'primary',
  `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `assignment_notes` TEXT,
  `status` ENUM('assigned', 'accepted', 'in_progress', 'completed', 'cancelled') DEFAULT 'assigned',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`emergency_id`) REFERENCES `emergency_incidents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`personnel_id`) REFERENCES `security_personnel`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_active_assignment` (`emergency_id`, `personnel_id`, `status`),
  INDEX `idx_personnel_status` (`personnel_id`, `status`),
  INDEX `idx_emergency_assignment` (`emergency_id`, `assignment_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for emergency contact logs
CREATE TABLE IF NOT EXISTS `emergency_contact_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `emergency_id` INT(11) UNSIGNED NOT NULL,
  `contact_type` ENUM('police', 'fire_department', 'ambulance', 'hospital', 'other_authority') NOT NULL,
  `contacted_by` INT(11) UNSIGNED NOT NULL,
  `contact_number` VARCHAR(20),
  `contact_person` VARCHAR(100),
  `contact_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `response_time` INT(11) NULL, -- seconds
  `notes` TEXT,
  `outcome` ENUM('contacted', 'no_answer', 'dispatched', 'arrived', 'resolved') DEFAULT 'contacted',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`emergency_id`) REFERENCES `emergency_incidents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contacted_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_emergency_contact` (`emergency_id`, `contact_type`),
  INDEX `idx_contact_time` (`contact_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for emergency response metrics
CREATE TABLE IF NOT EXISTS `emergency_response_metrics` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `emergency_id` INT(11) UNSIGNED NOT NULL,
  `first_response_time` INT(11) NULL, -- seconds from reported to first acknowledgment
  `assignment_time` INT(11) NULL, -- seconds from reported to assignment
  `response_time` INT(11) NULL, -- seconds from assignment to arrival
  `resolution_time` INT(11) NULL, -- seconds from reported to resolved
  `total_personnel_assigned` INT(11) DEFAULT 0,
  `external_services_contacted` INT(11) DEFAULT 0,
  `escalation_count` INT(11) DEFAULT 0,
  `tenant_updates_count` INT(11) DEFAULT 0,
  `calculated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`emergency_id`) REFERENCES `emergency_incidents`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_emergency_metrics` (`emergency_id`),
  INDEX `idx_response_time` (`response_time`),
  INDEX `idx_resolution_time` (`resolution_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhanced emergency incidents table with additional tracking fields
ALTER TABLE `emergency_incidents` 
ADD COLUMN `priority_score` INT(11) DEFAULT 0 AFTER `severity_level`,
ADD COLUMN `auto_escalation_triggered` BOOLEAN DEFAULT FALSE AFTER `status`,
ADD COLUMN `last_updated_by` INT(11) UNSIGNED NULL AFTER `auto_escalation_triggered`,
ADD COLUMN `tenant_feedback` TEXT NULL AFTER `last_updated_by`,
ADD COLUMN `tenant_satisfaction_rating` TINYINT(1) NULL CHECK (`tenant_satisfaction_rating` BETWEEN 1 AND 5) AFTER `tenant_feedback`,
ADD COLUMN `is_visible_to_tenant` BOOLEAN DEFAULT TRUE AFTER `tenant_satisfaction_rating`,
ADD FOREIGN KEY (`last_updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Insert sample data for testing
INSERT INTO `emergency_response_metrics` (`emergency_id`, `first_response_time`, `assignment_time`, `response_time`, `resolution_time`, `total_personnel_assigned`)
SELECT id, 
       TIMESTAMPDIFF(SECOND, reported_at, response_started_at) as first_response_time,
       TIMESTAMPDIFF(SECOND, reported_at, response_started_at) as assignment_time,
       TIMESTAMPDIFF(SECOND, response_started_at, resolved_at) as response_time,
       TIMESTAMPDIFF(SECOND, reported_at, resolved_at) as resolution_time,
       1 as total_personnel_assigned
FROM emergency_incidents 
WHERE response_started_at IS NOT NULL AND resolved_at IS NOT NULL
ON DUPLICATE KEY UPDATE emergency_id = emergency_id;

-- Create views for easier dashboard queries
CREATE OR REPLACE VIEW `emergency_dashboard_view` AS
SELECT 
    ei.id,
    ei.incident_type,
    ei.severity_level,
    ei.priority_score,
    ei.location,
    ei.description,
    ei.status,
    ei.reported_at,
    ei.response_started_at,
    ei.resolved_at,
    ei.estate_id,
    e.name as estate_name,
    reporter.first_name as reporter_first,
    reporter.last_name as reporter_last,
    officer.first_name as officer_first,
    officer.last_name as officer_last,
    sp.badge_number,
    erm.first_response_time,
    erm.response_time,
    erm.resolution_time,
    COUNT(ea.id) as assignments_count,
    COUNT(ec.id) as contact_logs_count
FROM emergency_incidents ei
LEFT JOIN estates e ON ei.estate_id = e.id
LEFT JOIN users reporter ON ei.reported_by = reporter.id
LEFT JOIN security_personnel sp ON ei.security_officer_id = sp.id
LEFT JOIN users officer ON sp.user_id = officer.id
LEFT JOIN emergency_response_metrics erm ON ei.id = erm.emergency_id
LEFT JOIN emergency_assignments ea ON ei.id = ea.emergency_id AND ea.status IN ('assigned', 'accepted', 'in_progress')
LEFT JOIN emergency_contact_logs ec ON ei.id = ec.emergency_id
GROUP BY ei.id;

-- Create function to calculate dynamic priority score
DELIMITER //
CREATE FUNCTION calculate_emergency_priority(
    severity_level VARCHAR(20),
    incident_type VARCHAR(50),
    time_elapsed_minutes INT,
    status VARCHAR(20)
) RETURNS INT
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE base_score INT DEFAULT 0;
    DECLARE time_bonus INT DEFAULT 0;
    DECLARE status_modifier INT DEFAULT 0;
    
    -- Base score by severity
    CASE severity_level
        WHEN 'critical' THEN SET base_score = 100;
        WHEN 'high' THEN SET base_score = 75;
        WHEN 'medium' THEN SET base_score = 50;
        WHEN 'low' THEN SET base_score = 25;
    END CASE;
    
    -- Bonus for critical incident types
    CASE incident_type
        WHEN 'medical' THEN SET base_score = base_score + 20;
        WHEN 'fire' THEN SET base_score = base_score + 30;
        WHEN 'security_breach' THEN SET base_score = base_score + 25;
    END CASE;
    
    -- Time-based escalation (increases priority over time)
    IF time_elapsed_minutes > 30 THEN
        SET time_bonus = LEAST(50, time_elapsed_minutes); -- Cap at 50 points
    END IF;
    
    -- Status modifiers
    CASE status
        WHEN 'reported' THEN SET status_modifier = 10;
        WHEN 'in_progress' THEN SET status_modifier = 0;
        WHEN 'resolved' THEN SET status_modifier = -50;
    END CASE;
    
    RETURN base_score + time_bonus + status_modifier;
END//
DELIMITER ;

-- Create trigger to update priority scores automatically
DELIMITER //
CREATE TRIGGER update_emergency_priority 
BEFORE UPDATE ON emergency_incidents
FOR EACH ROW
BEGIN
    DECLARE time_elapsed INT;
    
    SET time_elapsed = TIMESTAMPDIFF(MINUTE, NEW.reported_at, NOW());
    SET NEW.priority_score = calculate_emergency_priority(
        NEW.severity_level, 
        NEW.incident_type, 
        time_elapsed,
        NEW.status
    );
END//
DELIMITER ;

-- Create trigger for new emergencies
DELIMITER //
CREATE TRIGGER set_initial_emergency_priority 
BEFORE INSERT ON emergency_incidents
FOR EACH ROW
BEGIN
    DECLARE time_elapsed INT DEFAULT 0;
    
    SET NEW.priority_score = calculate_emergency_priority(
        NEW.severity_level, 
        NEW.incident_type, 
        time_elapsed,
        NEW.status
    );
END//
DELIMITER ;
