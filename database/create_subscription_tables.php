<?php
/**
 * Manual Subscription Tables Creation
 * Run this if the migration script fails
 */

require_once __DIR__ . '/../app/bootstrap.php';

$db = db();

echo "Creating subscription tables manually...\n\n";

// Check if database exists
try {
    $db->execute("USE estate_management");
    echo "✓ Database 'estate_management' selected\n";
} catch (Exception $e) {
    echo "✗ Database 'estate_management' not found. Please create it first.\n";
    exit(1);
}

// Create subscription_plans table
$sql1 = "
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) UNIQUE NOT NULL,
  `description` TEXT,
  `monthly_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `annual_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` ENUM('monthly', 'annual', 'custom') DEFAULT 'monthly',
  `max_units` INT(11) DEFAULT 0,
  `max_users` INT(11) DEFAULT 0,
  `features` JSON,
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
  `sort_order` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_code` (`code`),
  INDEX `idx_status` (`status`),
  INDEX `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $db->execute($sql1);
    echo "✓ Created subscription_plans table\n";
} catch (Exception $e) {
    echo "✗ Error creating subscription_plans: " . $e->getMessage() . "\n";
}

// Create estate_subscriptions table
$sql2 = "
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
";

try {
    $db->execute($sql2);
    echo "✓ Created estate_subscriptions table\n";
} catch (Exception $e) {
    echo "✗ Error creating estate_subscriptions: " . $e->getMessage() . "\n";
}

// Create subscription_payments table
$sql3 = "
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
";

try {
    $db->execute($sql3);
    echo "✓ Created subscription_payments table\n";
} catch (Exception $e) {
    echo "✗ Error creating subscription_payments: " . $e->getMessage() . "\n";
}

// Create subscription_usage_logs table
$sql4 = "
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
";

try {
    $db->execute($sql4);
    echo "✓ Created subscription_usage_logs table\n";
} catch (Exception $e) {
    echo "✗ Error creating subscription_usage_logs: " . $e->getMessage() . "\n";
}

// Create subscription_alerts table
$sql5 = "
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
";

try {
    $db->execute($sql5);
    echo "✓ Created subscription_alerts table\n";
} catch (Exception $e) {
    echo "✗ Error creating subscription_alerts: " . $e->getMessage() . "\n";
}

// Insert default plans
$plans_sql = "
INSERT IGNORE INTO `subscription_plans` (`name`, `code`, `description`, `monthly_price`, `annual_price`, `billing_cycle`, `max_units`, `max_users`, `features`, `sort_order`) VALUES
('Starter Plan', 'STARTER', 'Perfect for small estates and individual property managers', 100000.00, 1000000.00, 'monthly', 50, 10, 
  '{\"core_features\": [\"tenant_portal\", \"basic_reporting\", \"rent_management\", \"maintenance_basic\", \"property_management\", \"tenant_management\"]}',
  1),

('Growth Plan', 'GROWTH', 'Ideal for growing estates with advanced requirements', 200000.00, 2000000.00, 'monthly', 0, 0, 
  '{\"core_features\": [\"all_starter_features\", \"advanced_maintenance\", \"vendor_management\", \"multi_estate\", \"advanced_reporting\", \"communication_hub\", \"document_management\", \"analytics\"], \"pro_features\": [\"priority_support\", \"custom_integrations\", \"api_access\"]}',
  2),

('Enterprise Plan', 'ENTERPRISE', 'Comprehensive solution for large estate portfolios', 0.00, 0.00, 'custom', 0, 0, 
  '{\"core_features\": [\"all_growth_features\", \"white_label\", \"custom_branding\", \"dedicated_support\", \"enterprise_api\", \"custom_workflows\", \"compliance_management\", \"advanced_security\", \"ai_analytics\", \"market_intelligence\"], \"pro_features\": [\"sla_guarantee\", \"consulting\", \"training\", \"custom_development\", \"dedicated_infrastructure\"]}',
  3);
";

try {
    $db->execute($plans_sql);
    echo "✓ Inserted default subscription plans\n";
} catch (Exception $e) {
    echo "✗ Error inserting plans: " . $e->getMessage() . "\n";
}

echo "\n=== Setup Complete ===\n";
echo "You can now access the subscription management system!\n";
echo "1. Subscription Monitoring: /pages/admin/subscription_monitoring.php\n";
echo "2. Plan Management: /pages/admin/subscription_plans.php\n";
echo "3. Estate Assignments: /pages/admin/estate_subscriptions.php\n";
echo "4. Payment Tracking: /pages/admin/subscription_payments.php\n";