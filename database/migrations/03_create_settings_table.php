<?php
/**
 * Migration: Create settings table and seed default business details
 */

return [
    'up' => function($db) {
        $db->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `key` VARCHAR(100) PRIMARY KEY,
            `value` TEXT DEFAULT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Seed default business info if not exists
        $defaults = [
            'company_name' => 'Orange Decorations',
            'company_subtitle' => 'Premium Catering & Stage Decors',
            'company_address' => 'Thumpoly P.O, Alappuzha',
            'company_email' => 'orangedecorations@gmail.com',
            'company_state' => '32-Kerala',
            'company_phone' => '9946731720 | 9847634728',
            'company_bank_name' => 'STATE BANK OF INDIA',
            'company_bank_acc' => '40590127711',
            'company_bank_ifsc' => 'SBIN0000007',
            'company_bank_holder' => 'ORANGE DECORATIONS'
        ];
        
        $stmt_check = $db->prepare("SELECT COUNT(*) FROM `settings` WHERE `key` = :key");
        $stmt_insert = $db->prepare("INSERT INTO `settings` (`key`, `value`) VALUES (:key, :value)");
        
        foreach ($defaults as $key => $val) {
            $stmt_check->execute(['key' => $key]);
            if ($stmt_check->fetchColumn() == 0) {
                $stmt_insert->execute(['key' => $key, 'value' => $val]);
            }
        }
    },
    'down' => "DROP TABLE IF EXISTS `settings`"
];
