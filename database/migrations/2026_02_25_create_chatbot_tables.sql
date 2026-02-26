-- AI Chatbot Tables for 24/7 Tenant Support
-- Creates necessary tables for chatbot functionality

-- 1. Chatbot conversations table
CREATE TABLE IF NOT EXISTS `chatbot_conversations` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ended_at` TIMESTAMP NULL,
  `status` ENUM('active', 'ended', 'escalated') DEFAULT 'active',
  `context_data` JSON NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  INDEX `idx_tenant_status` (`tenant_id`, `status`),
  INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Chatbot messages table
CREATE TABLE IF NOT EXISTS `chatbot_messages` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT(11) UNSIGNED NOT NULL,
  `sender_type` ENUM('tenant', 'chatbot', 'human_agent') NOT NULL,
  `sender_id` INT(11) UNSIGNED NULL,
  `message_text` TEXT NOT NULL,
  `intent` VARCHAR(100) NULL,
  `confidence_score` DECIMAL(3,2) DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`conversation_id`) REFERENCES `chatbot_conversations`(`id`) ON DELETE CASCADE,
  INDEX `idx_conversation` (`conversation_id`),
  INDEX `idx_sender` (`sender_type`, `sender_id`),
  INDEX `idx_intent` (`intent`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Chatbot intents table - predefined intents and responses
CREATE TABLE IF NOT EXISTS `chatbot_intents` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `intent_name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT,
  `keywords` JSON NOT NULL, -- JSON array of trigger keywords/phrases
  `response_template` TEXT NOT NULL, -- Response template with placeholders
  `requires_authentication` BOOLEAN DEFAULT FALSE,
  `requires_human_intervention` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_intent_name` (`intent_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Chatbot training data table
CREATE TABLE IF NOT EXISTS `chatbot_training_data` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `intent_id` INT(11) UNSIGNED NOT NULL,
  `training_phrase` TEXT NOT NULL, -- Actual phrase a user might type
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`intent_id`) REFERENCES `chatbot_intents`(`id`) ON DELETE CASCADE,
  INDEX `idx_intent_active` (`intent_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Insert default intents for common tenant queries
INSERT INTO `chatbot_intents` (`intent_name`, `description`, `keywords`, `response_template`, `requires_authentication`, `requires_human_intervention`) VALUES
('greeting', 'Greeting and welcome message', '["hello", "hi", "hey", "good morning", "good afternoon", "good evening"]', 'Hello! I\'m the EstatePro AI assistant. I can help you with maintenance requests, lease information, payment queries, and more. How can I assist you today?', FALSE, FALSE),
('goodbye', 'Farewell message', '["bye", "goodbye", "see you", "thanks", "thank you", "ok thanks"]', 'You\'re welcome! Feel free to reach out if you need any assistance. Have a great day!', FALSE, FALSE),
('help', 'Help and available services', '["help", "support", "what can you do", "how can you help", "options", "services"]', 'I can help you with:\n- Checking your lease details\n- Submitting maintenance requests\n- Payment and invoice information\n- Viewing announcements\n- Getting contact information\n- General estate information\n\nWhat would you like to know?', FALSE, FALSE),
('lease_info', 'Lease information query', '["lease", "rent", "contract", "agreement", "due date", "payment date"]', 'I can help you check your lease details. As a tenant, I can access your lease information to provide details about your rent amount, due dates, lease term, and more. Would you like me to look up your lease information?', TRUE, FALSE),
('maintenance_request', 'Creating maintenance requests', '["maintenance", "repair", "fix", "broken", "problem", "issue", "leak", "plumbing", "electric", "electrical", "door", "window", "ac", "air conditioning"]', 'I can help you submit a maintenance request. Would you like to report a maintenance issue? I\'ll need details about the problem, its location, and urgency level.', TRUE, FALSE),
('payment_info', 'Payment and invoice information', '["payment", "invoice", "bill", "amount", "paid", "balance", "outstanding", "receipt"]', 'I can check your payment information including outstanding invoices, payment history, and upcoming due dates. Would you like me to look up your payment details?', TRUE, FALSE),
('contact_support', 'Contacting human support', '["speak to agent", "human", "representative", "manager", "staff", "real person", "escalate", "complex issue"]', 'I understand you need to speak with a human representative. I\'ll connect you with our support team. They will assist you shortly.', FALSE, TRUE),
('estate_info', 'General estate information', '["estate", "location", "rules", "policy", "amenities", "facilities", "gate", "security", "parking", "rules and regulations"]', 'Our estate provides various amenities and follows specific policies for the comfort and safety of all residents. For specific information about estate rules, facilities, or services, please let me know what you\'re looking for.', FALSE, FALSE);