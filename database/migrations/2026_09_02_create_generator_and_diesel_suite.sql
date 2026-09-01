-- Migration: Generator, Diesel Inventory and Utility Apportionment Suite

CREATE TABLE IF NOT EXISTS `generators` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `capacity_kva` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `fuel_type` ENUM('diesel', 'gas', 'solar_hybrid', 'petrol') NOT NULL DEFAULT 'diesel',
  `avg_consumption_litres_per_hour` DECIMAL(8,2) NOT NULL DEFAULT 25.00,
  `current_run_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `service_interval_hours` DECIMAL(8,2) NOT NULL DEFAULT 250.00,
  `last_service_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `last_service_date` DATE NULL,
  `tank_capacity_litres` DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
  `current_fuel_litres` DECIMAL(10,2) NOT NULL DEFAULT 500.00,
  `status` ENUM('active', 'standby', 'maintenance', 'faulty') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate_status` (`estate_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diesel_purchases` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `generator_id` INT(11) UNSIGNED NULL,
  `purchase_date` DATE NOT NULL,
  `litres` DECIMAL(10,2) NOT NULL,
  `cost_per_litre` DECIMAL(10,2) NOT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL,
  `supplier_name` VARCHAR(200) NOT NULL,
  `delivery_note_ref` VARCHAR(100) NULL,
  `receipt_path` VARCHAR(255) NULL,
  `recorded_by` INT(11) UNSIGNED NOT NULL,
  `expense_id` INT(11) UNSIGNED NULL,
  `status` ENUM('received', 'verified', 'cancelled') NOT NULL DEFAULT 'received',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`generator_id`) REFERENCES `generators`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate_date` (`estate_id`, `purchase_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `generator_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `generator_id` INT(11) UNSIGNED NOT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `duration_hours` DECIMAL(8,2) NOT NULL,
  `run_hours_start` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `run_hours_end` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `fuel_consumed_litres` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duty_operator_name` VARCHAR(150) NOT NULL,
  `logged_by` INT(11) UNSIGNED NOT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`generator_id`) REFERENCES `generators`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`logged_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_estate_gen_time` (`estate_id`, `generator_id`, `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utility_apportionments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `billing_month` VARCHAR(7) NOT NULL, -- Format: YYYY-MM
  `total_litres_used` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_diesel_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_grid_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_billable_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_units_billed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `apportionment_method` ENUM('equal_split_by_unit', 'weighted_by_bedrooms', 'meter_reading') NOT NULL DEFAULT 'equal_split_by_unit',
  `status` ENUM('draft', 'invoiced', 'cancelled') NOT NULL DEFAULT 'draft',
  `created_by` INT(11) UNSIGNED NOT NULL,
  `approved_by` INT(11) UNSIGNED NULL,
  `invoiced_at` DATETIME NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_estate_month` (`estate_id`, `billing_month`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
