<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = get_db_connection();
    
    // Check if display_order column exists in stage_items
    $stmt = $db->query("SHOW COLUMNS FROM stage_items LIKE 'display_order'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $db->exec("ALTER TABLE stage_items ADD COLUMN display_order INT DEFAULT 0 AFTER description");
        echo "✅ Added display_order column to stage_items table.\n";
    } else {
        echo "ℹ️ display_order column already exists in stage_items table.\n";
    }

} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
