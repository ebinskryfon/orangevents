<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = get_db_connection();
    
    // Delete duplicate rows in event_stage_work keeping only the row with the lowest id
    $sql = "DELETE esw1 FROM event_stage_work esw1
            INNER JOIN event_stage_work esw2 
            WHERE esw1.id > esw2.id 
            AND esw1.event_id = esw2.event_id 
            AND esw1.stage_item_id = esw2.stage_item_id";
            
    $affected = $db->exec($sql);
    echo "✅ Successfully cleaned up {$affected} duplicate stage item entries from event_stage_work table.\n";

} catch (PDOException $e) {
    echo "❌ Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
