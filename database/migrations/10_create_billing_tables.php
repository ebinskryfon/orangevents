<?php
/**
 * Migration 10: Create billing_categories, billing_products, billing_product_variants, billing_orders, and billing_order_items tables
 * Run once to set up the billing management feature.
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

$queries = [
    // 1. Billing Categories
    "CREATE TABLE IF NOT EXISTS `billing_categories` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `category_name` VARCHAR(100) NOT NULL UNIQUE,
        `display_order` INT          DEFAULT 0,
        `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. Billing Products
    "CREATE TABLE IF NOT EXISTS `billing_products` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `category_id`  INT           NOT NULL,
        `product_name` VARCHAR(150)  NOT NULL,
        `description`  TEXT,
        `base_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
        `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`category_id`) REFERENCES `billing_categories`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. Billing Product Variants (Size & Price override)
    "CREATE TABLE IF NOT EXISTS `billing_product_variants` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT          NOT NULL,
        `size`       VARCHAR(50)  NOT NULL,
        `price`      DECIMAL(10,2) DEFAULT NULL, -- NULL = inherits billing_products.base_price
        `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`product_id`) REFERENCES `billing_products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 4. Billing Orders
    "CREATE TABLE IF NOT EXISTS `billing_orders` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_number` VARCHAR(50)   NOT NULL UNIQUE,
        `customer_name`  VARCHAR(100)  DEFAULT NULL,
        `customer_phone` VARCHAR(20)   DEFAULT NULL,
        `total_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `discount_amount`DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `final_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `payment_method` VARCHAR(20)   NOT NULL DEFAULT 'Cash', -- Cash, UPI, Card
        `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 5. Billing Order Items
    "CREATE TABLE IF NOT EXISTS `billing_order_items` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `order_id`     INT           NOT NULL,
        `product_id`   INT           NOT NULL,
        `variant_id`   INT           DEFAULT NULL,
        `product_name` VARCHAR(150)  NOT NULL,
        `variant_size` VARCHAR(50)   DEFAULT NULL,
        `price`        DECIMAL(10,2) NOT NULL,
        `quantity`     INT           NOT NULL DEFAULT 1,
        `total_price`  DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (`order_id`) REFERENCES `billing_orders`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`product_id`) REFERENCES `billing_products`(`id`) ON DELETE RESTRICT,
        FOREIGN KEY (`variant_id`) REFERENCES `billing_product_variants`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Seed default categories
    "INSERT IGNORE INTO `billing_categories` (`id`, `category_name`, `display_order`) VALUES
        (1, 'Birthday Items', 1),
        (2, 'Event Items', 2)",

    // Seed default products
    "INSERT IGNORE INTO `billing_products` (`id`, `category_id`, `product_name`, `description`, `base_price`) VALUES
        (1, 1, 'Premium Balloon Pack', 'Pack of high-quality latex balloons in assorted colors.', 120.00),
        (2, 1, 'Birthday Party Cap', 'Colorful birthday cone caps for guests.', 15.00),
        (3, 1, 'Party Snow Spray', 'Foam snow spray canister for birthday celebrations.', 50.00),
        (4, 2, 'Stage Spotlight LED', 'High-intensity spotlight for stage illumination.', 850.00),
        (5, 2, 'Party Confetti Cannon', 'Handheld confetti cannon compressed air shooter.', 150.00)",

    // Seed default product variants
    "INSERT IGNORE INTO `billing_product_variants` (`product_id`, `size`, `price`) VALUES
        (1, 'Standard (50 pcs)', NULL), -- Inherits 120.00
        (1, 'Metallic (50 pcs)', 180.00),
        (1, 'Pastel (100 pcs)', 320.00),
        (2, 'Paper Standard', NULL), -- Inherits 15.00
        (2, 'Foil Premium', 25.00),
        (2, 'LED Light-up', 45.00),
        (4, '54 LED Warm White', NULL), -- Inherits 850.00
        (4, '54 LED RGBW Colors', 950.00),
        (5, 'Small (30cm)', 100.00),
        (5, 'Medium (60cm)', NULL), -- Inherits 150.00
        (5, 'Large (100cm)', 250.00)"
];

$errors   = [];
$successes = 0;

foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        $successes++;
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
    }
}

echo "Migration 10 — Billing POS Module\n";
echo "==================================================\n";
echo "Statements executed: $successes\n";
if ($errors) {
    echo "Errors:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
} else {
    echo "✅ All done — tables created and seed data inserted.\n";
}
