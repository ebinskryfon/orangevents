<?php
/**
 * Migration: Add plate_count to event_catering_dishes table
 */

return [
    'up' => function($db) {
        // Safe check to prevent execution errors if automated connection check already added it
        $check = $db->query("SHOW COLUMNS FROM `event_catering_dishes` LIKE 'plate_count'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE `event_catering_dishes` ADD COLUMN `plate_count` INT DEFAULT NULL");
        }
    },
    'down' => "ALTER TABLE `event_catering_dishes` DROP COLUMN `plate_count`"
];
