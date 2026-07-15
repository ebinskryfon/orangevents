<?php
/**
 * Migration 11: Add image_path column to billing_products
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    // Check if column already exists
    $check = $db->query("SHOW COLUMNS FROM `billing_products` LIKE 'image_path'")->fetch();
    if (!$check) {
        $db->exec("ALTER TABLE `billing_products` ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL");
        echo "✅ Migration 11 - Column 'image_path' added successfully to 'billing_products'.\n";
    } else {
        echo "ℹ️ Migration 11 - Column 'image_path' already exists.\n";
    }
} catch (PDOException $e) {
    echo "❌ Error in Migration 11: " . $e->getMessage() . "\n";
}
