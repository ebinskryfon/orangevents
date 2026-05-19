<?php
/**
 * Migration: Add advance_received and payment_method columns to invoices table
 */

return [
    'up' => function($db) {
        // Safe check to prevent execution errors if the columns already exist
        $check_advance = $db->query("SHOW COLUMNS FROM `invoices` LIKE 'advance_received'")->fetch();
        if (!$check_advance) {
            $db->exec("ALTER TABLE `invoices` ADD COLUMN `advance_received` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
        
        $check_payment = $db->query("SHOW COLUMNS FROM `invoices` LIKE 'payment_method'")->fetch();
        if (!$check_payment) {
            $db->exec("ALTER TABLE `invoices` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL");
        }
    },
    'down' => function($db) {
        $db->exec("ALTER TABLE `invoices` DROP COLUMN `advance_received`");
        $db->exec("ALTER TABLE `invoices` DROP COLUMN `payment_method`");
    }
];
