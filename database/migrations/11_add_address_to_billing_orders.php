<?php
/**
 * Migration 11: Add customer_address to billing_orders
 * Safe to run even if the column already exists.
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

// Check if the column already exists before adding it
$stmt = $db->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'billing_orders'
      AND COLUMN_NAME  = 'customer_address'
");
$stmt->execute();
$exists = (bool) $stmt->fetchColumn();

echo "Migration 11 — Add customer_address to billing_orders\n";
echo "======================================================\n";

if ($exists) {
    echo "ℹ️  Column 'customer_address' already exists — skipping.\n";
} else {
    try {
        $db->exec("
            ALTER TABLE `billing_orders`
                ADD COLUMN `customer_address` TEXT DEFAULT NULL
                AFTER `customer_phone`
        ");
        echo "✅ Column 'customer_address' added successfully.\n";
    } catch (PDOException $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}
