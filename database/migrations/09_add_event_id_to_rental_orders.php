<?php
/**
 * Migration 09: Add event_id to rental_orders table
 */

require_once __DIR__ . '/../../config/database.php';
$db = get_db_connection();

$queries = [
    // 1. Add event_id column
    "ALTER TABLE `rental_orders` ADD COLUMN `event_id` INT DEFAULT NULL AFTER `id`",
    
    // 2. Add foreign key constraint
    "ALTER TABLE `rental_orders` ADD CONSTRAINT `fk_rental_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE SET NULL"
];

$errors    = [];
$successes = 0;
foreach ($queries as $sql) {
    try { 
        $db->exec($sql); 
        $successes++; 
    }
    catch (PDOException $e) {
        // Ignore "Duplicate column name" (1060) and "Duplicate key name" (1061)
        // or general Foreign Key existence errors so the script is idempotent
        $code = $e->getCode();
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column name') === false && strpos($msg, 'Duplicate key name') === false && strpos($msg, 'already exists') === false) {
            $errors[] = $msg; 
        } else {
            $successes++; // Treat as success if it already exists
        }
    }
}

echo "<pre>\nMigration 09 — Add event_id to rental_orders\n" . str_repeat('=', 50) . "\n";
echo "Statements executed: $successes\n";
if ($errors) { echo "Errors:\n"; foreach ($errors as $e) { echo "  - $e\n"; } }
else         { echo "✅  All done.\n"; }
echo "</pre>\n";
