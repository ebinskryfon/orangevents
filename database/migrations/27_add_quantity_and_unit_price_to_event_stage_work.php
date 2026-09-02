<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = get_db_connection();
    
    // Check if quantity column exists in event_stage_work
    $stmt1 = $db->query("SHOW COLUMNS FROM event_stage_work LIKE 'quantity'");
    $has_quantity = $stmt1->fetch();
    
    if (!$has_quantity) {
        $db->exec("ALTER TABLE event_stage_work ADD COLUMN quantity INT DEFAULT 1 AFTER stage_item_id");
        echo "✅ Added quantity column to event_stage_work table.\n";
    }
    
    // Check if unit_price column exists in event_stage_work
    $stmt2 = $db->query("SHOW COLUMNS FROM event_stage_work LIKE 'unit_price'");
    $has_unit_price = $stmt2->fetch();
    
    if (!$has_unit_price) {
        $db->exec("ALTER TABLE event_stage_work ADD COLUMN unit_price DECIMAL(10,2) NULL AFTER quantity");
        echo "✅ Added unit_price column to event_stage_work table.\n";
    }
    
    if ($has_quantity && $has_unit_price) {
        echo "ℹ️ Columns quantity and unit_price already exist in event_stage_work.\n";
    }

} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
