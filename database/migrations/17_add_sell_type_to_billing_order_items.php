<?php
/**
 * Migration 17: Add sell_type column to billing_order_items table
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    $cols = $db->query("SHOW COLUMNS FROM `billing_order_items`")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('sell_type', $cols)) {
        $db->exec("ALTER TABLE `billing_order_items` ADD COLUMN `sell_type` VARCHAR(10) NOT NULL DEFAULT 'whole'");
        echo "✅ Column 'sell_type' added to 'billing_order_items'.\n";
    }

} catch (Exception $e) {
    echo "❌ Error in Migration 17: " . $e->getMessage() . "\n";
}
