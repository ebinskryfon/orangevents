<?php
/**
 * Migration 25: Create POS Active Carts and Line Items tables
 * Description: Enables real-time database-backed active cart synchronization between PC billing terminals and mobile camera scanners.
 */

return [
    'up' => function (PDO $db) {
        // 1. Active Cart Sessions Table
        $db->exec("CREATE TABLE IF NOT EXISTS `pos_active_carts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cart_token` VARCHAR(64) NOT NULL UNIQUE,
            `register_session_id` INT DEFAULT NULL,
            `cashier_user_id` INT NOT NULL,
            `status` ENUM('active', 'parked', 'completed', 'abandoned') DEFAULT 'active',
            `customer_name` VARCHAR(150) DEFAULT NULL,
            `customer_phone` VARCHAR(30) DEFAULT NULL,
            `customer_address` TEXT DEFAULT NULL,
            `discount_amount` DECIMAL(10, 2) DEFAULT 0.00,
            `notes` TEXT DEFAULT NULL,
            `version_hash` VARCHAR(32) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_token` (`cart_token`),
            INDEX `idx_status` (`status`),
            INDEX `idx_cashier` (`cashier_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 2. Active Cart Line Items Table
        $db->exec("CREATE TABLE IF NOT EXISTS `pos_active_cart_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cart_id` INT NOT NULL,
            `variant_id` INT DEFAULT NULL,
            `product_id` INT DEFAULT NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `size` VARCHAR(100) DEFAULT NULL,
            `price` DECIMAL(10, 2) NOT NULL,
            `sell_type` ENUM('whole', 'loose') DEFAULT 'whole',
            `quantity` DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
            `added_by_device` ENUM('pc', 'mobile_camera', 'manual') DEFAULT 'pc',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`cart_id`) REFERENCES `pos_active_carts`(`id`) ON DELETE CASCADE,
            INDEX `idx_cart_variant` (`cart_id`, `variant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    'down' => function (PDO $db) {
        $db->exec("DROP TABLE IF EXISTS `pos_active_cart_items`");
        $db->exec("DROP TABLE IF EXISTS `pos_active_carts`");
    }
];
