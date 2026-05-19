<?php
/**
 * Migration: Add balance_received column to invoices
 */

return [
    'up' => function($db) {
        // Add balance_received column if it doesn't exist
        $db->exec("ALTER TABLE `invoices` ADD COLUMN `balance_received` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    },
    'down' => function($db) {
        $db->exec("ALTER TABLE `invoices` DROP COLUMN `balance_received`");
    }
];
