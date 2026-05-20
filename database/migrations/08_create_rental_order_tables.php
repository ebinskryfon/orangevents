<?php
/**
 * Migration 08: Create rental_orders, rental_order_items, rental_payments tables
 */

require_once __DIR__ . '/../../config/database.php';
$db = get_db_connection();

$queries = [

    // 1. Rental Orders (the booking/contract record)
    "CREATE TABLE IF NOT EXISTS `rental_orders` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `order_number`       VARCHAR(30)    NOT NULL UNIQUE,
        `client_name`        VARCHAR(100)   NOT NULL,
        `client_phone`       VARCHAR(20)    NOT NULL,
        `client_email`       VARCHAR(100)   DEFAULT NULL,
        `client_address`     TEXT           DEFAULT NULL,
        `event_name`         VARCHAR(200)   DEFAULT NULL,
        `rental_start_date`  DATE           NOT NULL,
        `rental_end_date`    DATE           NOT NULL,
        `actual_return_date` DATE           DEFAULT NULL,
        `num_days`           INT            NOT NULL DEFAULT 1,
        `subtotal`           DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        `discount`           DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        `total_amount`       DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        `advance_paid`       DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        `balance_due`        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        `status`             ENUM('draft','active','returned','overdue','cancelled') DEFAULT 'draft',
        `notes`              TEXT           DEFAULT NULL,
        `created_at`         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. Line items for each order (snapshot of item at time of booking)
    "CREATE TABLE IF NOT EXISTS `rental_order_items` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `order_id`       INT           NOT NULL,
        `rental_item_id` INT           DEFAULT NULL,
        `item_name`      VARCHAR(150)  NOT NULL,
        `daily_rate`     DECIMAL(10,2) NOT NULL,
        `quantity`       INT           NOT NULL DEFAULT 1,
        `num_days`       INT           NOT NULL DEFAULT 1,
        `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        FOREIGN KEY (`order_id`) REFERENCES `rental_orders`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. Payment transactions linked to an order
    "CREATE TABLE IF NOT EXISTS `rental_payments` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `order_id`         INT           NOT NULL,
        `amount`           DECIMAL(10,2) NOT NULL,
        `payment_type`     ENUM('advance','partial','balance','refund') DEFAULT 'advance',
        `payment_method`   ENUM('cash','upi','bank_transfer','cheque','other') DEFAULT 'cash',
        `payment_date`     DATE          NOT NULL,
        `reference_number` VARCHAR(100)  DEFAULT NULL,
        `notes`            TEXT          DEFAULT NULL,
        `created_at`       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`order_id`) REFERENCES `rental_orders`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$errors    = [];
$successes = 0;
foreach ($queries as $sql) {
    try { $db->exec($sql); $successes++; }
    catch (PDOException $e) { $errors[] = $e->getMessage(); }
}

echo "<pre>\nMigration 08 — Rental Orders & Payments\n" . str_repeat('=', 50) . "\n";
echo "Statements executed: $successes\n";
if ($errors) { echo "Errors:\n"; foreach ($errors as $e) { echo "  - $e\n"; } }
else         { echo "✅  All done.\n"; }
echo "</pre>\n";
