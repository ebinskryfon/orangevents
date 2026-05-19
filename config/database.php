<?php
/**
 * Database Configuration and PDO Connection Manager
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'orange_events');

function get_db_connection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In case the database doesn't exist, we can try to connect to server and create database or throw
            try {
                $dsn_no_db = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
                $temp_pdo = new PDO($dsn_no_db, DB_USER, DB_PASS);
                $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                
                // Retry connection now that database is created
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                
                // Import schema.sql if it exists and database was just created
                $schema_file = __DIR__ . '/../database/schema.sql';
                if (file_exists($schema_file)) {
                    $sql = file_get_contents($schema_file);
                    $pdo->exec($sql);
                }
            } catch (PDOException $ex) {
                die("Database connection failed: " . $ex->getMessage());
            }
            // Automated Migration: ensure plate_count column exists for custom dish sizes
            try {
                $check_col = $pdo->query("SHOW COLUMNS FROM `event_catering_dishes` LIKE 'plate_count'")->fetch();
                if (!$check_col) {
                    $pdo->exec("ALTER TABLE `event_catering_dishes` ADD COLUMN `plate_count` INT DEFAULT NULL");
                }
            } catch (PDOException $ex_mig) {
                // Silently bypass if schema hasn't been created yet
            }
        }
    }
    
    return $pdo;
}
