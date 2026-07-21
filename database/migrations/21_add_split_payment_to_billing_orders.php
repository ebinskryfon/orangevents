<?php
/**
 * Migration 21: Add split payment breakdown columns to billing_orders
 */

return [
    'up' => function ($db) {
        // Check if paid_cash column exists
        $stmt = $db->query("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'billing_orders'
              AND COLUMN_NAME  = 'paid_cash'
        ");
        $exists = (int) $stmt->fetchColumn();

        if (!$exists) {
            echo "Migration 21 — Adding split payment columns to billing_orders...\n";

            $db->exec("
                ALTER TABLE `billing_orders`
                ADD COLUMN `paid_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `final_amount`,
                ADD COLUMN `paid_card` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `paid_cash`,
                ADD COLUMN `paid_upi` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `paid_card`,
                ADD COLUMN `payment_breakdown` TEXT DEFAULT NULL AFTER `paid_upi`
            ");

            // Backfill existing single-tender orders so paid_cash/paid_card/paid_upi match payment_method
            $db->exec("
                UPDATE `billing_orders`
                SET paid_cash = CASE WHEN payment_method = 'Cash' THEN final_amount ELSE 0.00 END,
                    paid_upi  = CASE WHEN payment_method = 'UPI'  THEN final_amount ELSE 0.00 END,
                    paid_card = CASE WHEN payment_method = 'Card' THEN final_amount ELSE 0.00 END
                WHERE paid_cash = 0.00 AND paid_upi = 0.00 AND paid_card = 0.00 AND final_amount > 0
            ");

            echo "Successfully added split payment columns and backfilled existing billing order records.\n";
        } else {
            echo "Migration 21 — Split payment columns already exist on billing_orders.\n";
        }
    }
];
