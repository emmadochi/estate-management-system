-- Add artisan role to users and link vendors to user accounts

ALTER TABLE `users`
MODIFY `role` ENUM('super_admin', 'estate_admin', 'property_manager', 'tenant', 'staff', 'security', 'artisan') NOT NULL;

ALTER TABLE `vendors`
ADD COLUMN `user_id` INT(11) UNSIGNED NULL AFTER `estate_id`;

ALTER TABLE `vendors`
ADD CONSTRAINT `fk_vendors_user_id` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `vendors`
ADD UNIQUE KEY `unique_vendor_user` (`user_id`);

