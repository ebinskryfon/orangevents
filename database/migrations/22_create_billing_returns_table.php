<?php
/**
 * Migration 22: Create billing_returns and billing_return_items tables for POS Return & Exchange Terminal
 */

return [
    'up' => function (PDO $db) {
        echo "Migration 22 — Creating billing_returns and billing_return_items tables...\n";
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS `billing_returns` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `return_number`  VARCHAR(50)   NOT NULL UNIQUE,
                `order_id`       INT           NOT NULL,
                `invoice_number` VARCHAR(50)   NOT NULL,
                `refund_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `refund_method`  VARCHAR(50)   NOT NULL DEFAULT 'Cash',
                `reason`         VARCHAR(255)  DEFAULT NULL,
                `processed_by`   VARCHAR(100)  DEFAULT 'Cashier',
                `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`order_id`) REFERENCES `billing_orders`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS `billing_return_items` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `return_id`     INT           NOT NULL,
                `order_item_id` INT           NOT NULL,
                `product_id`    INT           NOT NULL,
                `variant_id`    INT           DEFAULT NULL,
                `product_name`  VARCHAR(150)  NOT NULL,
                `variant_size`  VARCHAR(50)   DEFAULT NULL,
                `quantity`      INT           NOT NULL DEFAULT 1,
                `unit_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `total_refund`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `created_at`    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`return_id`) REFERENCES `billing_returns`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        echo "Success: Created billing_returns and billing_return_items tables.\n";
    }
];
