<?php
/**
 * Migration 18: Create cash_register_sessions table for daily opening and closing balance
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `cash_register_sessions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `opened_at` DATETIME NOT NULL,
        `closed_at` DATETIME NULL,
        `opening_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `expected_closing_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `actual_closing_balance` DECIMAL(10,2) NULL,
        `status` ENUM('open', 'closed') DEFAULT 'open',
        `notes` TEXT NULL,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ Table 'cash_register_sessions' created successfully.\n";
} catch (Exception $e) {
    echo "❌ Error in Migration 18: " . $e->getMessage() . "\n";
}
