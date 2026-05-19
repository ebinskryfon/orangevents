<?php
/**
 * CLI Migration Runner Script
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = get_db_connection();
} catch (Exception $e) {
    echo "Error: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Orange Events Database Migration Runner ===\n";

// 1. Create migrations tracking table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `migration` VARCHAR(255) NOT NULL UNIQUE,
        `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    echo "Error: Failed to initialize migrations table: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Scan migration folder
$migrations_dir = __DIR__ . '/../database/migrations';
if (!is_dir($migrations_dir)) {
    mkdir($migrations_dir, 0755, true);
}

$files = glob($migrations_dir . '/*.php');
sort($files); // Run in sorted order

// 3. Fetch executed migrations
$stmt = $db->query("SELECT migration FROM migrations");
$executed = $stmt->fetchAll(PDO::FETCH_COLUMN);

$executed_count = 0;

foreach ($files as $file) {
    $filename = basename($file);
    
    if (in_array($filename, $executed)) {
        continue; // Already executed
    }
    
    echo "Running migration: $filename...\n";
    
    // Include migration definition
    $migration = require $file;
    
    if (!isset($migration['up'])) {
        echo "Error: Migration $filename does not have an 'up' key definition.\n";
        continue;
    }
    
    try {
        // Execute migration SQL or callable
        if (is_callable($migration['up'])) {
            $migration['up']($db);
        } else {
            $db->exec($migration['up']);
        }
        
        // Record migration as executed
        $log_stmt = $db->prepare("INSERT INTO migrations (migration) VALUES (:migration)");
        $log_stmt->execute(['migration' => $filename]);
        
        echo "Success: Migration $filename completed.\n";
        $executed_count++;
    } catch (Exception $e) {
        echo "Error: Failed to execute migration $filename: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($executed_count === 0) {
    echo "No pending migrations found. Database is up to date!\n";
} else {
    echo "Migration phase complete. Executed $executed_count migrations successfully.\n";
}
exit(0);
