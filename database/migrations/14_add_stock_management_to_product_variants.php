<?php
/**
 * Migration 14: Add stock quantity and parent link fields for inventory conversion
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

try {
    // Add stock_quantity column
    $check_stock = $db->query("SHOW COLUMNS FROM `billing_product_variants` LIKE 'stock_quantity'")->fetch();
    if (!$check_stock) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `stock_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        echo "✅ Column 'stock_quantity' added to 'billing_product_variants'.\n";
    }
    
    // Add stock_linked_to_variant_id column
    $check_link = $db->query("SHOW COLUMNS FROM `billing_product_variants` LIKE 'stock_linked_to_variant_id'")->fetch();
    if (!$check_link) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `stock_linked_to_variant_id` INT DEFAULT NULL");
        $db->exec("ALTER TABLE `billing_product_variants` ADD CONSTRAINT `fk_stock_link` FOREIGN KEY (`stock_linked_to_variant_id`) REFERENCES `billing_product_variants`(`id`) ON DELETE SET NULL");
        echo "✅ Column 'stock_linked_to_variant_id' added to 'billing_product_variants'.\n";
    }
    
    // Add units_per_parent column
    $check_units = $db->query("SHOW COLUMNS FROM `billing_product_variants` LIKE 'units_per_parent'")->fetch();
    if (!$check_units) {
        $db->exec("ALTER TABLE `billing_product_variants` ADD COLUMN `units_per_parent` DECIMAL(10,2) NOT NULL DEFAULT 1.00");
        echo "✅ Column 'units_per_parent' added to 'billing_product_variants'.\n";
    }
} catch (Exception $e) {
    echo "❌ Error in Migration 14: " . $e->getMessage() . "\n";
}
