-- ==========================================================
-- EstatePro Accounting & Financial Management System Migration
-- Created: 2026-09-01
-- ==========================================================

-- 1. Modify users role ENUM to include 'accountant'
ALTER TABLE `users`
MODIFY `role` ENUM('super_admin', 'estate_admin', 'property_manager', 'tenant', 'staff', 'security', 'artisan', 'accountant') NOT NULL;

-- 2. Modify user_estates role ENUM to include 'accountant'
ALTER TABLE `user_estates`
MODIFY `role` ENUM('estate_admin', 'property_manager', 'tenant', 'staff', 'security', 'artisan', 'accountant') NOT NULL;

-- 3. Chart of Accounts Table
CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NULL,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
  `parent_id` INT(11) UNSIGNED NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_code` (`code`),
  INDEX `idx_type` (`type`),
  INDEX `idx_estate` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Expense Categories Table
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NULL,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(50) NULL,
  `type` ENUM('operating', 'capital', 'administrative', 'utility', 'maintenance') DEFAULT 'operating',
  `description` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Expenses / Disbursements Table
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_number` VARCHAR(50) UNIQUE NOT NULL,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `property_id` INT(11) UNSIGNED NULL,
  `category_id` INT(11) UNSIGNED NOT NULL,
  `account_id` INT(11) UNSIGNED NULL,
  `vendor_id` INT(11) UNSIGNED NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `tax_amount` DECIMAL(12,2) DEFAULT 0.00,
  `withholding_tax` DECIMAL(12,2) DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('bank_transfer', 'cash', 'card', 'cheque', 'other') DEFAULT 'bank_transfer',
  `payment_status` ENUM('draft', 'pending_approval', 'approved', 'paid', 'rejected') DEFAULT 'pending_approval',
  `expense_date` DATE NOT NULL,
  `due_date` DATE NULL,
  `paid_date` DATETIME NULL,
  `receipt_file` VARCHAR(255) NULL,
  `invoice_reference` VARCHAR(100) NULL,
  `notes` TEXT NULL,
  `recorded_by` INT(11) UNSIGNED NOT NULL,
  `approved_by` INT(11) UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_category` (`category_id`),
  INDEX `idx_payment_status` (`payment_status`),
  INDEX `idx_expense_date` (`expense_date`),
  INDEX `idx_expense_number` (`expense_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Budgets Table
CREATE TABLE IF NOT EXISTS `budgets` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `category_id` INT(11) UNSIGNED NOT NULL,
  `fiscal_year` INT(4) NOT NULL,
  `fiscal_month` INT(2) NULL, -- 0 or NULL for annual budget, 1-12 for monthly
  `budgeted_amount` DECIMAL(12,2) NOT NULL,
  `notes` TEXT NULL,
  `created_by` INT(11) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  UNIQUE KEY `unique_estate_budget` (`estate_id`, `category_id`, `fiscal_year`, `fiscal_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Bank Accounts Table
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `bank_name` VARCHAR(150) NOT NULL,
  `account_number` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `currency` VARCHAR(10) DEFAULT 'NGN',
  `opening_balance` DECIMAL(12,2) DEFAULT 0.00,
  `current_balance` DECIMAL(12,2) DEFAULT 0.00,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  INDEX `idx_estate` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Bank Reconciliations Table
CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `estate_id` INT(11) UNSIGNED NOT NULL,
  `bank_account_id` INT(11) UNSIGNED NOT NULL,
  `payment_id` INT(11) UNSIGNED NULL,
  `expense_id` INT(11) UNSIGNED NULL,
  `transaction_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `transaction_type` ENUM('credit', 'debit') NOT NULL,
  `status` ENUM('reconciled', 'pending', 'discrepancy') DEFAULT 'pending',
  `statement_reference` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `reconciled_by` INT(11) UNSIGNED NULL,
  `reconciled_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reconciled_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_estate` (`estate_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_transaction_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default global expense categories
INSERT IGNORE INTO `expense_categories` (`id`, `estate_id`, `name`, `code`, `type`, `description`) VALUES
(1, NULL, 'Generator Fuel & Diesel', 'OPEX-DSL', 'operating', 'Diesel and fuel procurement for estate backup generators'),
(2, NULL, 'Security Personnel & Surveillance', 'OPEX-SEC', 'operating', 'Security guard salaries, gate operations and CCTV upkeep'),
(3, NULL, 'Facility Cleaning & Waste Management', 'OPEX-CLN', 'operating', 'Janitorial services, trash disposal and sanitation'),
(4, NULL, 'Borehole & Water Treatment', 'OPEX-WTR', 'operating', 'Water pumping, chemical treatment and filter servicing'),
(5, NULL, 'Common Area Electricity & PHCN', 'UTIL-POW', 'utility', 'Power consumption for streetlights, clubhouses and gates'),
(6, NULL, 'Landscaping & Gardening', 'OPEX-GRD', 'operating', 'Lawn mowing, tree pruning and groundskeeping'),
(7, NULL, 'General Infrastructure Repairs', 'CAPEX-REP', 'capital', 'Road, drainage, perimeter fence and gate automation repairs'),
(8, NULL, 'Administrative & Legal Expenses', 'ADM-LGL', 'administrative', 'Estate audit fees, permits, legal and management expenses');

-- Seed default global chart of accounts
INSERT IGNORE INTO `chart_of_accounts` (`id`, `estate_id`, `code`, `name`, `type`, `parent_id`, `description`) VALUES
(1, NULL, '1000', 'Cash & Bank Balances', 'asset', NULL, 'Operating bank accounts and petty cash floats'),
(2, NULL, '1100', 'Accounts Receivable (Rent & Dues)', 'asset', NULL, 'Outstanding amounts due from tenants and occupants'),
(3, NULL, '2000', 'Accounts Payable & Vendor Dues', 'liability', NULL, 'Unpaid vendor invoices and contractor bills'),
(4, NULL, '2100', 'Tenant Caution & Security Deposits', 'liability', NULL, 'Refundable deposit funds held in escrow'),
(5, NULL, '3000', 'Estate Reserve & Accumulated Funds', 'equity', NULL, 'Retained reserves and sinking funds'),
(6, NULL, '4000', 'Rental Income', 'revenue', NULL, 'Rental revenue from managed units'),
(7, NULL, '4100', 'Service Charge & Estate Dues', 'revenue', NULL, 'Levies collected for common facility upkeep'),
(8, NULL, '4200', 'Electricity & Utility Vending', 'revenue', NULL, 'Vended utility power, water and access tokens'),
(9, NULL, '5000', 'Estate Operating Expenses (OpEx)', 'expense', NULL, 'Day to day maintenance, diesel, security and services'),
(10, NULL, '5100', 'Capital Improvement (CapEx)', 'expense', NULL, 'Major property improvements and equipment acquisition');
