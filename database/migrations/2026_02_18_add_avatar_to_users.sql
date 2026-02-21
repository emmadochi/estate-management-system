-- Add avatar/profile image column to users table

ALTER TABLE `users` 
ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `phone`,
ADD INDEX `idx_avatar` (`avatar`);
