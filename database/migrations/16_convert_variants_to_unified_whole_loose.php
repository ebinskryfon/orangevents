<?php
/**
 * Migration 16: Convert variants table to unified whole/loose configuration and create stock logs table
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    $cols = $db->query("SHOW COLUMNS FROM `billing_product_variants`")->fetchAll(PDO::FETCH_COLUMN);
    
    // Drop variant_type if exists
    if (in_array('variant_type', $cols)) {
        $db->exec("ALTER TABLE `billing_product_variants` DROP COLUMN `variant_type`");
        echo "✅ Column 'variant_type' dropped.\n";
    }
    
    // Drop units_per_whole if exists
    if (in_array('units_per_whole', $cols)) {
        $db->exec("ALTER TABLE `billing_product_variants` DROP COLUMN `units_per_whole`");
        echo "✅ Column 'units_per_whole' dropped.\n";
    }
    
    // Add new columns
    if (!in_array('allow_loose', $cols)) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `allow_loose` TINYINT(1) NOT NULL DEFAULT 0");
        echo "✅ Column 'allow_loose' added.\n";
    }
    
    if (!in_array('loose_price', $cols)) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `loose_price` DECIMAL(10,2) DEFAULT NULL");
        echo "✅ Column 'loose_price' added.\n";
    }
    
    if (!in_array('loose_units_per_whole', $cols)) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `loose_units_per_whole` DECIMAL(10,2) NOT NULL DEFAULT 1.00");
        echo "✅ Column 'loose_units_per_whole' added.\n";
    }
    
    // Create billing_stock_logs table
    $db->exec("CREATE TABLE IF NOT EXISTS `billing_stock_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `variant_id` INT NOT NULL,
        `order_id` INT DEFAULT NULL,
        `change_type` VARCHAR(50) NOT NULL, -- 'sale_whole', 'sale_loose', 'refund_whole', 'refund_loose', 'restocked', 'adjustment'
        `quantity_changed` DECIMAL(10,2) NOT NULL,
        `result_stock` DECIMAL(10,2) NOT NULL,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`variant_id`) REFERENCES `billing_product_variants`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ Table 'billing_stock_logs' created successfully.\n";

} catch (Exception $e) {
    echo "❌ Error in Migration 16: " . $e->getMessage() . "\n";
}
