<?php
/**
 * Migration 23: Create customers table and backfill existing unique customer records
 */

return [
    'up' => function (PDO $db) {
        echo "Migration 23 — Creating customers table...\n";
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS `customers` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `name`           VARCHAR(150)  NOT NULL,
                `phone`          VARCHAR(20)   NOT NULL UNIQUE,
                `email`          VARCHAR(150)  DEFAULT NULL,
                `address`        TEXT          DEFAULT NULL,
                `city`           VARCHAR(100)  DEFAULT NULL,
                `gstin`          VARCHAR(20)   DEFAULT NULL,
                `total_orders`   INT           NOT NULL DEFAULT 0,
                `total_spent`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `notes`          TEXT          DEFAULT NULL,
                `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        echo "Success: Created customers table.\n";

        // Backfill unique customers from billing_orders
        echo "Migration 23 — Backfilling customer records from billing_orders...\n";
        $stmt = $db->query("
            SELECT customer_name, customer_phone, customer_address, COUNT(*) as order_count, SUM(final_amount) as spent_sum
              FROM billing_orders
             WHERE customer_phone IS NOT NULL AND TRIM(customer_phone) != ''
          GROUP BY customer_phone
        ");
        $pos_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt_ins = $db->prepare("
            INSERT INTO customers (name, phone, address, total_orders, total_spent)
            VALUES (:name, :phone, :address, :total_orders, :total_spent)
            ON DUPLICATE KEY UPDATE
                name = IF(name IS NULL OR name = '', VALUES(name), name),
                address = IF(address IS NULL OR address = '', VALUES(address), address),
                total_orders = total_orders + VALUES(total_orders),
                total_spent = total_spent + VALUES(total_spent)
        ");

        $count = 0;
        foreach ($pos_customers as $cust) {
            $phone = trim($cust['customer_phone']);
            $name = trim($cust['customer_name']);
            if (empty($name)) {
                $name = 'Client (' . $phone . ')';
            }
            $stmt_ins->execute([
                'name'         => $name,
                'phone'        => $phone,
                'address'      => $cust['customer_address'],
                'total_orders' => (int)$cust['order_count'],
                'total_spent'  => (float)$cust['spent_sum']
            ]);
            $count++;
        }

        // Backfill unique customers from events table
        echo "Migration 23 — Backfilling customer records from events...\n";
        $stmt_e = $db->query("
            SELECT client_name, client_phone, COUNT(*) as order_count
              FROM events
             WHERE client_phone IS NOT NULL AND TRIM(client_phone) != ''
          GROUP BY client_phone
        ");
        $event_customers = $stmt_e->fetchAll(PDO::FETCH_ASSOC);

        foreach ($event_customers as $cust) {
            $phone = trim($cust['client_phone']);
            $name = trim($cust['client_name']);
            if (empty($name)) {
                $name = 'Client (' . $phone . ')';
            }
            $stmt_ins->execute([
                'name'         => $name,
                'phone'        => $phone,
                'address'      => null,
                'total_orders' => (int)$cust['order_count'],
                'total_spent'  => 0
            ]);
            $count++;
        }

        echo "Success: Backfilled $count unique customer profiles into database.\n";
    }
];
