<?php
/**
 * Migration 07: Create rental_categories and rental_items tables
 * Run once to set up the rentals management feature.
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

$queries = [
    // 1. Rental categories (e.g., Wedding Accessories, Baby Items)
    "CREATE TABLE IF NOT EXISTS `rental_categories` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `category_name` VARCHAR(100) NOT NULL UNIQUE,
        `icon`          VARCHAR(50)  DEFAULT 'fa-box-open',
        `display_order` INT          DEFAULT 0,
        `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. Rental items catalogue
    "CREATE TABLE IF NOT EXISTS `rental_items` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `category_id`     INT           NOT NULL,
        `item_name`       VARCHAR(150)  NOT NULL,
        `description`     TEXT,
        `daily_rate`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `quantity_in_stock` INT         NOT NULL DEFAULT 1,
        `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
        `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`category_id`) REFERENCES `rental_categories`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. Seed default categories
    "INSERT IGNORE INTO `rental_categories` (`category_name`, `icon`, `display_order`) VALUES
        ('Wedding Accessories',   'fa-rings-wedding',    1),
        ('Baby & Child Items',    'fa-baby-carriage',    2),
        ('Decor & Floral',        'fa-seedling',         3),
        ('Furniture & Fixtures',  'fa-couch',            4),
        ('Audio & Lighting',      'fa-lightbulb',        5)",

    // 4. Seed default rental items
    "INSERT IGNORE INTO `rental_items` (`category_id`, `item_name`, `description`, `daily_rate`, `quantity_in_stock`) VALUES
        (1, 'Marriage Garland (Mala)',       'Traditional flower garland set for bride and groom.',          500.00,  5),
        (1, 'Bridal Bouquet',               'Fresh flower bouquet for the bride.',                         300.00,  5),
        (1, 'Mantharakodi Petti',           'Decorated gift box for wedding trousseau exchange.',          1500.00, 3),
        (1, 'Nilavilakku (Oil Lamp)',        'Traditional brass oil lamp for ceremonies.',                  400.00,  4),
        (1, 'Arangu (Ceremonial Tray Set)', 'Set of 5 decorated trays for wedding rituals.',               800.00,  3),
        (2, 'Baby Crib / Cradle',           'Decorated wooden cradle for naming/baptism ceremonies.',     1200.00, 2),
        (2, 'Baby Shower Arch',             'Flower arch with balloon cluster for baby showers.',          700.00,  2),
        (2, 'High Chair',                   'Decorated high chair for first rice-feeding ceremony.',       350.00,  3),
        (3, 'Stage Flower Wall Backdrop',   '8×6 ft fresh/artificial flower wall for stage backdrop.',    3500.00, 2),
        (3, 'Floral Arch (Gate Decor)',     'Full flower arch for entrance gate decoration.',             2000.00, 3),
        (3, 'Table Centrepiece Set (10)',   'Set of 10 floral centrepieces for dining tables.',           1800.00, 2),
        (4, 'Sofa Set (3-piece)',           'Royal sofa set for couple seating on stage.',                2500.00, 2),
        (4, 'Wooden Podium',               'Elegant podium for speeches.',                                600.00,  2),
        (5, 'Fairy Light String (50m)',     '50-metre warm-white LED fairy lights.',                       500.00,  6)",
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

echo "<pre>\n";
echo "Migration 07 — Rental Management\n";
echo str_repeat('=', 50) . "\n";
echo "Statements executed: $successes\n";
if ($errors) {
    echo "Errors:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
} else {
    echo "✅  All done — tables created and seed data inserted.\n";
}
echo "</pre>\n";
