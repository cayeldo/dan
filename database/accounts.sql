-- Run in phpMyAdmin for the existing MySQL/MariaDB database.
USE `cayeldo_dan`;

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `password_hash` VARCHAR(255) DEFAULT NULL,
    `setup_token_hash` CHAR(64) DEFAULT NULL,
    `setup_expires_at` DATETIME DEFAULT NULL,
    `password_created_at` DATETIME DEFAULT NULL,
    `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NULL means the user must create a password using a private setup code.
-- Re-running this seed preserves existing passwords and account data.
INSERT INTO `users` (`username`) VALUES ('don'), ('dan')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);
