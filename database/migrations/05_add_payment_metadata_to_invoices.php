<?php
/**
 * Migration: Add advance and balance payment metadata fields to invoices
 */

return [
    'up' => function($db) {
        // 1. Add columns to invoices table if they don't exist
        $db->exec("ALTER TABLE `invoices` 
            ADD COLUMN `advance_paid_at` DATETIME NULL,
            ADD COLUMN `advance_payment_method` VARCHAR(50) NULL,
            ADD COLUMN `balance_paid_at` DATETIME NULL,
            ADD COLUMN `balance_payment_method` VARCHAR(50) NULL");

        // 2. Backfill existing records
        // For fully paid invoices, set balance paid metadata
        $db->exec("UPDATE `invoices` SET 
            `advance_paid_at` = `created_at`,
            `advance_payment_method` = COALESCE(`payment_method`, 'CASH'),
            `balance_paid_at` = `created_at`,
            `balance_payment_method` = COALESCE(`payment_method`, 'CASH')
            WHERE `status` = 'paid'");

        // For finalized invoices with advance, set advance metadata
        $db->exec("UPDATE `invoices` SET 
            `advance_paid_at` = `created_at`,
            `advance_payment_method` = COALESCE(`payment_method`, 'CASH')
            WHERE `advance_received` > 0 AND `advance_paid_at` IS NULL");
    },
    'down' => function($db) {
        $db->exec("ALTER TABLE `invoices` 
            DROP COLUMN `advance_paid_at`,
            DROP COLUMN `advance_payment_method`,
            DROP COLUMN `balance_paid_at`,
            DROP COLUMN `balance_payment_method`");
    }
];
