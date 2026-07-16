<?php
/**
 * Migration 12: Add barcode column to billing_product_variants
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    // Check if column already exists
    $check = $db->query("SHOW COLUMNS FROM `billing_product_variants` LIKE 'barcode'")->fetch();
    if (!$check) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `barcode` VARCHAR(50) DEFAULT NULL UNIQUE");
        echo "✅ Migration 12 - Column 'barcode' added successfully to 'billing_product_variants'.\n";
    } else {
        echo "ℹ️ Migration 12 - Column 'barcode' already exists.\n";
    }
} catch (PDOException $e) {
    echo "❌ Error in Migration 12: " . $e->getMessage() . "\n";
}
