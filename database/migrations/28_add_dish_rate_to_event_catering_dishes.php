<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = get_db_connection();
    
    // Check if dish_rate column exists in event_catering_dishes
    $stmt = $db->query("SHOW COLUMNS FROM event_catering_dishes LIKE 'dish_rate'");
    $has_column = $stmt->fetch();
    
    if (!$has_column) {
        $db->exec("ALTER TABLE event_catering_dishes ADD COLUMN dish_rate DECIMAL(10,2) NULL AFTER plate_count");
        echo "✅ Added dish_rate column to event_catering_dishes table.\n";
    } else {
        echo "ℹ️ Column dish_rate already exists in event_catering_dishes.\n";
    }

} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
