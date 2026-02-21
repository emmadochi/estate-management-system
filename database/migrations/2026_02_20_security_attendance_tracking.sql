-- Create security attendance tracking table
CREATE TABLE IF NOT EXISTS security_attendance (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_personnel_id INT(11) UNSIGNED NOT NULL,
    estate_id INT(11) UNSIGNED,
    date DATE NOT NULL,
    shift_type VARCHAR(20) DEFAULT 'morning',
    clock_in_time DATETIME NULL,
    clock_out_time DATETIME NULL,
    clock_in_location VARCHAR(255) NULL,
    clock_out_location VARCHAR(255) NULL,
    status ENUM('present', 'absent', 'late', 'early_departure', 'leave') DEFAULT 'absent',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (security_personnel_id) REFERENCES security_personnel(id) ON DELETE CASCADE,
    FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE SET NULL,
    INDEX idx_security_personnel_date (security_personnel_id, date),
    INDEX idx_estate_date (estate_id, date)
);

-- Add attendance status column to security personnel table if not exists
SET @column_exists = (SELECT COUNT(*) 
                      FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_NAME = 'security_personnel' 
                      AND COLUMN_NAME = 'attendance_status' 
                      AND TABLE_SCHEMA = DATABASE());

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE security_personnel ADD COLUMN attendance_status VARCHAR(20) DEFAULT \'active\'', 
              'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;