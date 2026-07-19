<?php
/**
 * Create roles, permissions, role_permissions and user_roles tables.
 * Seed default roles (admin, manager, cashier) and billing CRUD permissions,
 * and link existing users to their corresponding roles.
 */
return [
    'up' => function ($db) {
        // 1. Create roles table
        $db->exec("CREATE TABLE IF NOT EXISTS `roles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `role_name` VARCHAR(50) NOT NULL UNIQUE,
            `description` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 2. Create permissions table
        $db->exec("CREATE TABLE IF NOT EXISTS `permissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `permission_key` VARCHAR(100) NOT NULL UNIQUE,
            `description` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3. Create role_permissions mapping table
        $db->exec("CREATE TABLE IF NOT EXISTS `role_permissions` (
            `role_id` INT NOT NULL,
            `permission_id` INT NOT NULL,
            PRIMARY KEY (`role_id`, `permission_id`),
            FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Create user_roles mapping table
        $db->exec("CREATE TABLE IF NOT EXISTS `user_roles` (
            `user_id` INT NOT NULL,
            `role_id` INT NOT NULL,
            PRIMARY KEY (`user_id`, `role_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 5. Seed Default Roles
        $roles = [
            ['role_name' => 'admin', 'description' => 'System Administrator with full access rights.'],
            ['role_name' => 'manager', 'description' => 'Store Manager with catalog editing and sales reporting privileges.'],
            ['role_name' => 'cashier', 'description' => 'Store cashier with basic order creation and history viewing rights.']
        ];
        
        $role_ids = [];
        $stmt = $db->prepare("INSERT IGNORE INTO roles (role_name, description) VALUES (:role_name, :description)");
        foreach ($roles as $r) {
            $stmt->execute($r);
            // Fetch back id
            $id_stmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
            $id_stmt->execute([$r['role_name']]);
            $role_ids[$r['role_name']] = (int)$id_stmt->fetchColumn();
        }

        // 6. Seed Permissions
        $permissions = [
            ['key' => 'billing_create', 'desc' => 'Create new sales/orders in Billing and Barcode Billing modules.'],
            ['key' => 'billing_read', 'desc' => 'View sales history, cash register sessions, and billing reports.'],
            ['key' => 'billing_update', 'desc' => 'Update product information, modify prices, and edit billing categories.'],
            ['key' => 'billing_delete', 'desc' => 'Delete billing orders, void items, and delete products/categories.'],
            ['key' => 'user_manage', 'desc' => 'Manage system users, assign roles, and handle security permissions.'],
            ['key' => 'settings_manage', 'desc' => 'Modify business settings, printer config, and application parameters.']
        ];

        $perm_ids = [];
        $p_stmt = $db->prepare("INSERT IGNORE INTO permissions (permission_key, description) VALUES (:key, :desc)");
        foreach ($permissions as $p) {
            $p_stmt->execute(['key' => $p['key'], 'desc' => $p['desc']]);
            $id_stmt = $db->prepare("SELECT id FROM permissions WHERE permission_key = ?");
            $id_stmt->execute([$p['key']]);
            $perm_ids[$p['key']] = (int)$id_stmt->fetchColumn();
        }

        // 7. Seed Role Permissions
        $rp_stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        
        // admin: all permissions
        if (isset($role_ids['admin'])) {
            foreach ($perm_ids as $key => $pid) {
                $rp_stmt->execute([$role_ids['admin'], $pid]);
            }
        }
        
        // manager: billing_create, billing_read, billing_update, settings_manage
        if (isset($role_ids['manager'])) {
            $mgr_perms = ['billing_create', 'billing_read', 'billing_update', 'settings_manage'];
            foreach ($mgr_perms as $key) {
                if (isset($perm_ids[$key])) {
                    $rp_stmt->execute([$role_ids['manager'], $perm_ids[$key]]);
                }
            }
        }

        // cashier: billing_create, billing_read
        if (isset($role_ids['cashier'])) {
            $cashier_perms = ['billing_create', 'billing_read'];
            foreach ($cashier_perms as $key) {
                if (isset($perm_ids[$key])) {
                    $rp_stmt->execute([$role_ids['cashier'], $perm_ids[$key]]);
                }
            }
        }

        // 8. Migrate existing users to roles mapping table
        $users = $db->query("SELECT id, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $ur_stmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        foreach ($users as $u) {
            if ($u['role'] === 'admin') {
                if (isset($role_ids['admin'])) {
                    $ur_stmt->execute([$u['id'], $role_ids['admin']]);
                }
            } else {
                if (isset($role_ids['cashier'])) {
                    $ur_stmt->execute([$u['id'], $role_ids['cashier']]);
                }
            }
        }
    },
    'down' => function ($db) {
        $db->exec("DROP TABLE IF EXISTS `user_roles`");
        $db->exec("DROP TABLE IF EXISTS `role_permissions`");
        $db->exec("DROP TABLE IF EXISTS `permissions`");
        $db->exec("DROP TABLE IF EXISTS `roles`");
    }
];
