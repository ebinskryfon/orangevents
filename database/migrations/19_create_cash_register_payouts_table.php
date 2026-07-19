<?php
/**
 * Migration 19: Create cash_register_payouts table for miscellaneous drawer expenses
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `cash_register_payouts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `session_id` INT NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `reason` VARCHAR(255) NOT NULL,
        `recipient_name` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`session_id`) REFERENCES `cash_register_sessions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ Table 'cash_register_payouts' created successfully.\n";
} catch (Exception $e) {
    echo "❌ Error in Migration 19: " . $e->getMessage() . "\n";
}
