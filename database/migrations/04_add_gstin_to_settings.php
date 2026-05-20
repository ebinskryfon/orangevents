<?php
/**
 * Migration: Add default company GSTIN key to settings
 */

return [
    'up' => function($db) {
        $stmt_check = $db->prepare("SELECT COUNT(*) FROM `settings` WHERE `key` = 'company_gstin'");
        $stmt_check->execute();
        if ($stmt_check->fetchColumn() == 0) {
            $db->prepare("INSERT INTO `settings` (`key`, `value`) VALUES ('company_gstin', '32AACCO2938M1Z2')")->execute();
        }
    },
    'down' => function($db) {
        $db->prepare("DELETE FROM `settings` WHERE `key` = 'company_gstin'")->execute();
    }
];
