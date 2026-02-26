-- Estate Subscription Management System
-- Migration for SaaS billing and subscription features
-- Created: February 26, 2026

-- ============================================
-- 1. SUBSCRIPTION PLANS
-- ============================================

CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) UNIQUE NOT NULL,
  `description` TEXT,
  `monthly_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `annual_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` ENUM('monthly', 'annual', 'custom') DEFAULT 'monthly',
  `max_units` INT(11) DEFAULT 0, -- 0 = unlimited
  `max_users` INT(11) DEFAULT 0,   -- 0 = unlimited
  `features` JSON, -- List of enabled features
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
  `sort_order` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_code` (`code`),
  INDEX `idx_status` (`status`),
  INDEX `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. ESTATE SUBSCRIPTIONS
-- ============================================

CREATE TABLE IF NOT EXISTS `estate_subscriptions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `plan_id` INT(11) UNSIGNED NOT NULL,
  `subscription_number` VARCHAR(50) UNIQUE NOT NULL,
  `status` ENUM('active', 'pending', 'suspended', 'cancelled', 'expired') DEFAULT 'pending',
  `start_date` DATE NOT NULL,
  `end_date` DATE NULL,
  `billing_cycle` ENUM('monthly', 'annual', 'custom') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` VARCHAR(3) DEFAULT 'NGN',
  `next_billing_date` DATE NULL,
  `trial_end_date` DATE NULL,
  `auto_renew` BOOLEAN DEFAULT TRUE,
  `notes` TEXT,
  `created_by` INT(11) UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_plan` (`plan_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_billing_date` (`next_billing_date`),
  INDEX `idx_subscription_number` (`subscription_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. SUBSCRIPTION PAYMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS `subscription_payments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` INT(11) UNSIGNED NOT NULL,
  `payment_reference` VARCHAR(100) UNIQUE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` VARCHAR(3) DEFAULT 'NGN',
  `payment_method` ENUM('bank_transfer', 'card', 'paystack', 'flutterwave', 'wallet', 'other') NOT NULL,
  `payment_provider` VARCHAR(50),
  `transaction_id` VARCHAR(255),
  `status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
  `payment_date` DATETIME NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `receipt_number` VARCHAR(50),
  `receipt_file` VARCHAR(255),
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`subscription_id`) REFERENCES `estate_subscriptions`(`id`) ON DELETE CASCADE,
  INDEX `idx_subscription` (`subscription_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_payment_date` (`payment_date`),
  INDEX `idx_reference` (`payment_reference`),
  INDEX `idx_period` (`period_start`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. SUBSCRIPTION USAGE LOGS
-- ============================================

CREATE TABLE IF NOT EXISTS `subscription_usage_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` INT(11) UNSIGNED NOT NULL,
  `resource_type` ENUM('units', 'users', 'maintenance_tickets', 'invoices', 'payments') NOT NULL,
  `usage_count` INT(11) NOT NULL DEFAULT 0,
  `usage_limit` INT(11) NOT NULL DEFAULT 0,
  `log_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`subscription_id`) REFERENCES `estate_subscriptions`(`id`) ON DELETE CASCADE,
  INDEX `idx_subscription` (`subscription_id`),
  INDEX `idx_resource` (`resource_type`),
  INDEX `idx_date` (`log_date`),
  UNIQUE KEY `unique_log_entry` (`subscription_id`, `resource_type`, `log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. SUBSCRIPTION ALERTS
-- ============================================

CREATE TABLE IF NOT EXISTS `subscription_alerts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `subscription_id` INT(11) UNSIGNED NOT NULL,
  `alert_type` ENUM('payment_due', 'overdue', 'expiring_soon', 'usage_limit', 'plan_upgrade', 'cancellation_warning') NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('pending', 'sent', 'dismissed', 'resolved') DEFAULT 'pending',
  `severity` ENUM('info', 'warning', 'danger') DEFAULT 'info',
  `trigger_date` DATE NOT NULL,
  `resolved_at` DATETIME NULL,
  `resolved_by` INT(11) UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subscription_id`) REFERENCES `estate_subscriptions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_subscription` (`subscription_id`),
  INDEX `idx_alert_type` (`alert_type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_trigger_date` (`trigger_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SEED DATA: Default Subscription Plans
-- ============================================

INSERT INTO `subscription_plans` (`name`, `code`, `description`, `monthly_price`, `annual_price`, `billing_cycle`, `max_units`, `max_users`, `features`, `sort_order`) VALUES
('Starter Plan', 'STARTER', 'Perfect for small estates and individual property managers', 100000.00, 1000000.00, 'monthly', 50, 10, 
  '{\"core_features\": [\"tenant_portal\", \"basic_reporting\", \"rent_management\", \"maintenance_basic\", \"property_management\", \"tenant_management\"]}',
  1),

('Growth Plan', 'GROWTH', 'Ideal for growing estates with advanced requirements', 200000.00, 2000000.00, 'monthly', 0, 0, 
  '{\"core_features\": [\"all_starter_features\", \"advanced_maintenance\", \"vendor_management\", \"multi_estate\", \"advanced_reporting\", \"communication_hub\", \"document_management\", \"analytics\"], \"pro_features\": [\"priority_support\", \"custom_integrations\", \"api_access\"]}',
  2),

('Enterprise Plan', 'ENTERPRISE', 'Comprehensive solution for large estate portfolios', 0.00, 0.00, 'custom', 0, 0, 
  '{\"core_features\": [\"all_growth_features\", \"white_label\", \"custom_branding\", \"dedicated_support\", \"enterprise_api\", \"custom_workflows\", \"compliance_management\", \"advanced_security\", \"ai_analytics\", \"market_intelligence\"], \"pro_features\": [\"sla_guarantee\", \"consulting\", \"training\", \"custom_development\", \"dedicated_infrastructure\"]}',
  3);

-- Update estates to include default subscription tracking (if they don't exist yet)
INSERT INTO `estate_subscriptions` (`estate_id`, `plan_id`, `subscription_number`, `status`, `start_date`, `amount`, `billing_cycle`, `notes`)
SELECT e.id, 
       (SELECT id FROM subscription_plans WHERE code = 'GROWTH' LIMIT 1),
       CONCAT('SUB-', e.id, '-', DATE_FORMAT(NOW(), '%Y%m%d%H%i%s')), 
       'active',
       DATE_SUB(NOW(), INTERVAL 1 MONTH), 
       200000.00,
       'monthly',
       'Pre-configured during migration - subscription management will be added later'
FROM estates e
LEFT JOIN estate_subscriptions es ON e.id = es.estate_id
WHERE es.id IS NULL
AND (SELECT COUNT(*) FROM subscription_plans) > 0;