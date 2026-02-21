-- Estate Management System Database Schema
-- Created for Phase 4 Development
-- Compatible with MySQL/MariaDB (XAMPP)

-- ============================================
-- 1. USERS & AUTHENTICATION
-- ============================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20),
  `role` ENUM('super_admin', 'estate_admin', 'property_manager', 'tenant', 'staff', 'security') NOT NULL,
  `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
  `email_verified_at` DATETIME NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. ESTATES
-- ============================================

CREATE TABLE IF NOT EXISTS `estates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(50) UNIQUE,
  `address` TEXT,
  `city` VARCHAR(100),
  `state` VARCHAR(100),
  `country` VARCHAR(100) DEFAULT 'Nigeria',
  `postal_code` VARCHAR(20),
  `phone` VARCHAR(20),
  `email` VARCHAR(255),
  `logo` VARCHAR(255),
  `description` TEXT,
  `total_units` INT(11) DEFAULT 0,
  `occupied_units` INT(11) DEFAULT 0,
  `status` ENUM('active', 'inactive', 'under_construction') DEFAULT 'active',
  `created_by` INT(11) UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_code` (`code`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-Estate relationship (for multi-estate support)
-- Note: must be created AFTER `estates` to satisfy FK creation order.
CREATE TABLE IF NOT EXISTS `user_estates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `role` ENUM('estate_admin', 'property_manager', 'tenant', 'staff', 'security') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_user_estate` (`user_id`, `estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. PROPERTIES & UNITS
-- ============================================

CREATE TABLE IF NOT EXISTS `properties` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('block', 'building', 'house', 'commercial', 'other') NOT NULL,
  `address` VARCHAR(255),
  `total_units` INT(11) DEFAULT 0,
  `occupied_units` INT(11) DEFAULT 0,
  `status` ENUM('active', 'inactive', 'under_maintenance') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `units` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `property_id` INT(11) UNSIGNED NOT NULL,
  `unit_number` VARCHAR(50) NOT NULL,
  `unit_type` ENUM('apartment', 'flat', 'duplex', 'penthouse', 'shop', 'office', 'warehouse', 'other') NOT NULL,
  `bedrooms` INT(2) DEFAULT 0,
  `bathrooms` INT(2) DEFAULT 0,
  `square_feet` DECIMAL(10,2),
  `rent_amount` DECIMAL(12,2) DEFAULT 0.00,
  `service_charge` DECIMAL(12,2) DEFAULT 0.00,
  `status` ENUM('vacant', 'occupied', 'reserved', 'under_maintenance') DEFAULT 'vacant',
  `owner_type` ENUM('owner', 'tenant') DEFAULT 'tenant',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_property` (`property_id`),
  INDEX `idx_status` (`status`),
  UNIQUE KEY `unique_unit` (`estate_id`, `property_id`, `unit_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. TENANTS & LEASES
-- ============================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NOT NULL,
  `emergency_contact_name` VARCHAR(255),
  `emergency_contact_phone` VARCHAR(20),
  `occupation` VARCHAR(255),
  `company` VARCHAR(255),
  `id_type` ENUM('national_id', 'drivers_license', 'passport', 'voters_card', 'other'),
  `id_number` VARCHAR(100),
  `id_document` VARCHAR(255),
  `status` ENUM('active', 'inactive', 'moved_out') DEFAULT 'active',
  `moved_in_date` DATE,
  `moved_out_date` DATE NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE RESTRICT,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leases` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NOT NULL,
  `lease_number` VARCHAR(50) UNIQUE,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `rent_amount` DECIMAL(12,2) NOT NULL,
  `service_charge` DECIMAL(12,2) DEFAULT 0.00,
  `deposit` DECIMAL(12,2) DEFAULT 0.00,
  `payment_frequency` ENUM('monthly', 'quarterly', 'yearly', 'custom') DEFAULT 'monthly',
  `status` ENUM('draft', 'active', 'expired', 'terminated', 'renewed') DEFAULT 'draft',
  `agreement_document` VARCHAR(255),
  `allocation_letter` VARCHAR(255),
  `notes` TEXT,
  `created_by` INT(11) UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE RESTRICT,
  INDEX `idx_tenant` (`tenant_id`),
  INDEX `idx_unit` (`unit_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. INVOICES & PAYMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(50) UNIQUE NOT NULL,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `lease_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `type` ENUM('rent', 'service_charge', 'other', 'deposit') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `due_date` DATE NOT NULL,
  `status` ENUM('pending', 'paid', 'overdue', 'partial', 'cancelled') DEFAULT 'pending',
  `paid_amount` DECIMAL(12,2) DEFAULT 0.00,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lease_id`) REFERENCES `leases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_tenant` (`tenant_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_due_date` (`due_date`),
  INDEX `idx_invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_reference` VARCHAR(100) UNIQUE NOT NULL,
  `invoice_id` INT(11) UNSIGNED NOT NULL,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('cash', 'bank_transfer', 'card', 'paystack', 'flutterwave', 'wallet', 'other') NOT NULL,
  `payment_provider` VARCHAR(50),
  `transaction_id` VARCHAR(255),
  `status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
  `payment_date` DATETIME NOT NULL,
  `receipt_number` VARCHAR(50),
  `receipt_file` VARCHAR(255),
  `notes` TEXT,
  `created_by` INT(11) UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_invoice` (`invoice_id`),
  INDEX `idx_tenant` (`tenant_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_payment_date` (`payment_date`),
  INDEX `idx_reference` (`payment_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. MAINTENANCE & FACILITY MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS `vendors` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NULL,
  `name` VARCHAR(255) NOT NULL,
  `company` VARCHAR(255),
  `phone` VARCHAR(20),
  `email` VARCHAR(255),
  `specialization` ENUM('plumbing', 'electrical', 'carpentry', 'painting', 'security', 'cleaning', 'landscaping', 'other') NOT NULL,
  `rating` DECIMAL(3,2) DEFAULT 0.00,
  `total_jobs` INT(11) DEFAULT 0,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `address` TEXT,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_specialization` (`specialization`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: must be created AFTER `vendors` to satisfy FK creation order.
CREATE TABLE IF NOT EXISTS `maintenance_tickets` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(50) UNIQUE NOT NULL,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `category` ENUM('electrical', 'plumbing', 'water', 'security', 'gate', 'environmental', 'safety', 'other') NOT NULL,
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('open', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled') DEFAULT 'open',
  `assigned_to` INT(11) UNSIGNED NULL,
  `vendor_id` INT(11) UNSIGNED NULL,
  `cost` DECIMAL(12,2) DEFAULT 0.00,
  `resolved_at` DATETIME NULL,
  `resolved_by` INT(11) UNSIGNED NULL,
  `resolution_notes` TEXT,
  `before_photo` VARCHAR(255),
  `after_photo` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE SET NULL,
  INDEX `idx_tenant` (`tenant_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_category` (`category`),
  INDEX `idx_ticket_number` (`ticket_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. COMMUNICATION & ANNOUNCEMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `type` ENUM('general', 'maintenance', 'payment', 'security', 'emergency', 'event') DEFAULT 'general',
  `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
  `target_audience` ENUM('all', 'tenants', 'staff', 'specific_units') DEFAULT 'all',
  `target_units` JSON NULL,
  `send_email` BOOLEAN DEFAULT FALSE,
  `send_sms` BOOLEAN DEFAULT FALSE,
  `send_push` BOOLEAN DEFAULT TRUE,
  `published_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `status` ENUM('draft', 'published', 'archived') DEFAULT 'draft',
  `created_by` INT(11) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_published` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_reads` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `announcement_id` INT(11) UNSIGNED NOT NULL,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_read` (`announcement_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. STAFF & SECURITY MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS `visitor_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `unit_id` INT(11) UNSIGNED NOT NULL,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `visitor_name` VARCHAR(255) NOT NULL,
  `visitor_phone` VARCHAR(20),
  `visitor_email` VARCHAR(255),
  `purpose` VARCHAR(255),
  `entry_time` DATETIME NOT NULL,
  `exit_time` DATETIME NULL,
  `gate_pass_number` VARCHAR(50),
  `qr_code` VARCHAR(255),
  `status` ENUM('pending', 'checked_in', 'checked_out', 'expired') DEFAULT 'pending',
  `logged_by` INT(11) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`logged_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_entry_time` (`entry_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_attendance` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `check_in_time` DATETIME NOT NULL,
  `check_out_time` DATETIME NULL,
  `status` ENUM('present', 'absent', 'late', 'early_leave') DEFAULT 'present',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_date` (`check_in_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. DOCUMENT MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS `documents` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NULL,
  `related_type` ENUM('estate', 'property', 'unit', 'tenant', 'lease', 'invoice', 'payment', 'maintenance', 'announcement') NOT NULL,
  `related_id` INT(11) UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(50),
  `file_size` INT(11),
  `category` ENUM('agreement', 'allocation_letter', 'invoice', 'receipt', 'id_document', 'maintenance', 'announcement', 'other') NOT NULL,
  `description` TEXT,
  `uploaded_by` INT(11) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_related` (`related_type`, `related_id`),
  INDEX `idx_estate` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. WALLET SYSTEM (Advanced Feature)
-- ============================================

CREATE TABLE IF NOT EXISTS `wallets` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `balance` DECIMAL(12,2) DEFAULT 0.00,
  `status` ENUM('active', 'frozen', 'closed') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_tenant_wallet` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `wallet_id` INT(11) UNSIGNED NOT NULL,
  `type` ENUM('credit', 'debit') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `balance_after` DECIMAL(12,2) NOT NULL,
  `reference` VARCHAR(100),
  `description` VARCHAR(255),
  `related_type` ENUM('payment', 'top_up', 'refund', 'invoice_payment', 'other') NOT NULL,
  `related_id` INT(11) UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
  INDEX `idx_wallet` (`wallet_id`),
  INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. AUDIT LOGS
-- ============================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED NULL,
  `estate_id` INT(11) UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `model` VARCHAR(50) NOT NULL,
  `model_id` INT(11) UNSIGNED NULL,
  `changes` JSON NULL,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_model` (`model`, `model_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. SETTINGS & CONFIGURATION
-- ============================================

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NULL,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT,
  `type` ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_setting` (`estate_id`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INITIAL DATA / SEED DATA
-- ============================================

-- Insert default Super Admin user (password: admin123 - should be hashed in production)
-- Password hash for 'admin123' using bcrypt: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
INSERT INTO `users` (`email`, `password`, `first_name`, `last_name`, `role`, `status`, `email_verified_at`) 
VALUES ('admin@estatepro.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super', 'Admin', 'super_admin', 'active', NOW())
ON DUPLICATE KEY UPDATE `email`=`email`;

-- ============================================
-- END OF SCHEMA
-- ============================================
