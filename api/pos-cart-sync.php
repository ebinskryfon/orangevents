<?php
/**
 * Orange Events - Real-Time POS Active Cart Synchronization API
 * Serves DB-backed cart state for multi-device checkout (PC Terminal + Mobile Camera Scanner)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure user is logged in
if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$db = get_db_connection();
$user_id = $_SESSION['admin_id'];

// Helper to compute cart version hash for quick polling comparison
function compute_cart_version_hash($cart_id, $db) {
    $stmt = $db->prepare("SELECT COUNT(*) as count, IFNULL(SUM(quantity), 0) as total_qty, IFNULL(SUM(price * quantity), 0) as total_amount, MAX(updated_at) as last_updated FROM pos_active_cart_items WHERE cart_id = :cart_id");
    $stmt->execute(['cart_id' => $cart_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return md5($cart_id . '_' . ($row['count'] ?? 0) . '_' . ($row['total_qty'] ?? 0) . '_' . ($row['total_amount'] ?? 0) . '_' . ($row['last_updated'] ?? '0'));
}

// Helper to sanitize clean barcode
function clean_barcode_input($raw) {
    if (!$raw) return '';
    $str = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', trim($raw));
    $str = preg_replace('/^(?:\][A-Za-z][0-9A-Za-z]?\s*)+/', '', $str);
    $str = preg_replace('/(?:\s*\][A-Za-z][0-9A-Za-z]?)+$/', '', $str);
    $str = preg_replace('/\][A-Za-z][0-9A-Za-z]?/', '', $str);
    return trim(preg_replace('/^\]+|\]+$/', '', $str));
}

// Get or create active cart for cashier
function get_or_create_active_cart($db, $user_id, $token_param = null) {
    if ($token_param) {
        $stmt = $db->prepare("SELECT * FROM pos_active_carts WHERE cart_token = :token AND status = 'active' LIMIT 1");
        $stmt->execute(['token' => $token_param]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cart) return $cart;
    }

    // Find active register session for user
    $reg_stmt = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
    $reg_stmt->execute(['user_id' => $user_id]);
    $reg = $reg_stmt->fetch(PDO::FETCH_ASSOC);
    $register_session_id = $reg ? (int)$reg['id'] : null;

    // Check existing active cart for cashier
    $stmt = $db->prepare("SELECT * FROM pos_active_carts WHERE cashier_user_id = :user_id AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['user_id' => $user_id]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cart) {
        $cart_token = 'CART_' . strtoupper(bin2hex(random_bytes(8)));
        $ins = $db->prepare("INSERT INTO pos_active_carts (cart_token, register_session_id, cashier_user_id, status) VALUES (:token, :reg_id, :user_id, 'active')");
        $ins->execute([
            'token' => $cart_token,
            'reg_id' => $register_session_id,
            'user_id' => $user_id
        ]);
        $cart_id = $db->lastInsertId();
        $stmt = $db->prepare("SELECT * FROM pos_active_carts WHERE id = :id");
        $stmt->execute(['id' => $cart_id]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $cart;
}

$action = $_REQUEST['action'] ?? 'get_cart';
$token_input = $_REQUEST['cart_token'] ?? null;

try {
    $cart = get_or_create_active_cart($db, $user_id, $token_input);
    $cart_id = (int)$cart['id'];

    if ($action === 'get_cart') {
        $client_hash = $_REQUEST['version_hash'] ?? '';
        $current_hash = compute_cart_version_hash($cart_id, $db);

        // Quick response if unchanged
        if ($client_hash !== '' && $client_hash === $current_hash) {
            echo json_encode(['success' => true, 'changed' => false, 'version_hash' => $current_hash]);
            exit;
        }

        // Fetch cart line items
        $items_stmt = $db->prepare("
            SELECT i.*, v.barcode, v.allow_loose, v.loose_price, v.loose_units_per_whole, v.stock_quantity
              FROM pos_active_cart_items i
         LEFT JOIN billing_product_variants v ON i.variant_id = v.id
             WHERE i.cart_id = :cart_id
             ORDER BY i.id ASC
        ");
        $items_stmt->execute(['cart_id' => $cart_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $subtotal = 0.00;
        $formatted_items = [];
        foreach ($items as $item) {
            $line_total = (float)$item['price'] * (float)$item['quantity'];
            $subtotal += $line_total;
            $formatted_items[] = [
                'id' => (int)$item['id'],
                'variant_id' => $item['variant_id'] ? (int)$item['variant_id'] : null,
                'product_id' => $item['product_id'] ? (int)$item['product_id'] : null,
                'product_name' => $item['product_name'],
                'size' => $item['size'],
                'price' => (float)$item['price'],
                'sell_type' => $item['sell_type'],
                'quantity' => (float)$item['quantity'],
                'line_total' => $line_total,
                'barcode' => $item['barcode'],
                'allow_loose' => (int)($item['allow_loose'] ?? 0),
                'loose_price' => $item['loose_price'] !== null ? (float)$item['loose_price'] : null,
                'loose_units' => (float)($item['loose_units_per_whole'] ?? 1),
                'stock' => (float)($item['stock_quantity'] ?? 0),
                'added_by_device' => $item['added_by_device']
            ];
        }

        $discount = (float)($cart['discount_amount'] ?? 0);
        $grand_total = max(0, $subtotal - $discount);

        echo json_encode([
            'success' => true,
            'changed' => true,
            'version_hash' => $current_hash,
            'cart_token' => $cart['cart_token'],
            'customer_name' => $cart['customer_name'] ?? '',
            'customer_phone' => $cart['customer_phone'] ?? '',
            'customer_address' => $cart['customer_address'] ?? '',
            'discount_amount' => $discount,
            'subtotal' => $subtotal,
            'grand_total' => $grand_total,
            'items' => $formatted_items,
            'item_count' => count($formatted_items)
        ]);
        exit;

    } elseif ($action === 'add_item') {
        $raw_barcode = $_POST['barcode'] ?? $_GET['barcode'] ?? null;
        $variant_id = isset($_POST['variant_id']) ? (int)$POST['variant_id'] : null;
        $added_by = $_POST['added_by'] ?? 'pc';
        $quantity = (float)($_POST['quantity'] ?? 1);

        $matched_variant = null;

        if ($raw_barcode) {
            $clean_code = clean_barcode_input($raw_barcode);
            
            // Query variant by barcode
            $stmt = $db->prepare("
                SELECT v.id AS variant_id, v.product_id, v.size, v.price AS variant_price, v.barcode,
                       v.allow_loose, v.loose_price, v.loose_units_per_whole, v.stock_quantity,
                       p.product_name, p.base_price
                  FROM billing_product_variants v
                  JOIN billing_products p ON v.product_id = p.id
                 WHERE p.is_active = 1 
                   AND (v.barcode = :raw OR v.barcode = :clean)
                 LIMIT 1
            ");
            $stmt->execute(['raw' => trim($raw_barcode), 'clean' => $clean_code]);
            $matched_variant = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($variant_id) {
            $stmt = $db->prepare("
                SELECT v.id AS variant_id, v.product_id, v.size, v.price AS variant_price, v.barcode,
                       v.allow_loose, v.loose_price, v.loose_units_per_whole, v.stock_quantity,
                       p.product_name, p.base_price
                  FROM billing_product_variants v
                  JOIN billing_products p ON v.product_id = p.id
                 WHERE v.id = :id AND p.is_active = 1
                 LIMIT 1
            ");
            $stmt->execute(['id' => $variant_id]);
            $matched_variant = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$matched_variant) {
            echo json_encode(['success' => false, 'error' => 'Product / Barcode not found in active catalog.']);
            exit;
        }

        $effective_price = $matched_variant['variant_price'] !== null ? (float)$matched_variant['variant_price'] : (float)$matched_variant['base_price'];
        $v_id = (int)$matched_variant['variant_id'];

        // Check if item already exists in current active cart
        $check_stmt = $db->prepare("SELECT id, quantity FROM pos_active_cart_items WHERE cart_id = :cart_id AND variant_id = :v_id AND sell_type = 'whole' LIMIT 1");
        $check_stmt->execute(['cart_id' => $cart_id, 'v_id' => $v_id]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $new_qty = (float)$existing['quantity'] + $quantity;
            $up = $db->prepare("UPDATE pos_active_cart_items SET quantity = :qty, added_by_device = :device WHERE id = :id");
            $up->execute(['qty' => $new_qty, 'device' => $added_by, 'id' => $existing['id']]);
        } else {
            $ins = $db->prepare("
                INSERT INTO pos_active_cart_items (cart_id, variant_id, product_id, product_name, size, price, sell_type, quantity, added_by_device)
                VALUES (:cart_id, :v_id, :p_id, :p_name, :size, :price, 'whole', :qty, :device)
            ");
            $ins->execute([
                'cart_id' => $cart_id,
                'v_id' => $v_id,
                'p_id' => (int)$matched_variant['product_id'],
                'p_name' => $matched_variant['product_name'],
                'size' => $matched_variant['size'],
                'price' => $effective_price,
                'qty' => $quantity,
                'device' => $added_by
            ]);
        }

        // Touch cart updated timestamp
        $db->exec("UPDATE pos_active_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = {$cart_id}");

        $new_hash = compute_cart_version_hash($cart_id, $db);
        echo json_encode([
            'success' => true,
            'message' => 'Item added to active cart.',
            'version_hash' => $new_hash,
            'added_item' => [
                'product_name' => $matched_variant['product_name'],
                'size' => $matched_variant['size'],
                'price' => $effective_price
            ]
        ]);
        exit;

    } elseif ($action === 'add_custom_item') {
        $name = trim($_POST['item_name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 1);

        if (empty($name) || $price <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid custom item details.']);
            exit;
        }

        $ins = $db->prepare("
            INSERT INTO pos_active_cart_items (cart_id, variant_id, product_id, product_name, size, price, sell_type, quantity, added_by_device)
            VALUES (:cart_id, NULL, NULL, :name, 'Custom', :price, 'whole', :qty, 'pc')
        ");
        $ins->execute([
            'cart_id' => $cart_id,
            'name' => $name,
            'price' => $price,
            'qty' => $qty
        ]);

        $db->exec("UPDATE pos_active_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = {$cart_id}");

        echo json_encode(['success' => true, 'version_hash' => compute_cart_version_hash($cart_id, $db)]);
        exit;

    } elseif ($action === 'update_qty') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 1);
        $sell_type = $_POST['sell_type'] ?? 'whole';

        if ($qty <= 0) {
            $del = $db->prepare("DELETE FROM pos_active_cart_items WHERE id = :id AND cart_id = :cart_id");
            $del->execute(['id' => $item_id, 'cart_id' => $cart_id]);
        } else {
            // Check loose unit pricing if sell_type is loose
            $stmt = $db->prepare("
                SELECT i.*, v.loose_price, v.price as v_price, p.base_price
                  FROM pos_active_cart_items i
             LEFT JOIN billing_product_variants v ON i.variant_id = v.id
             LEFT JOIN billing_products p ON i.product_id = p.id
                 WHERE i.id = :id AND i.cart_id = :cart_id
            ");
            $stmt->execute(['id' => $item_id, 'cart_id' => $cart_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $price = (float)$item['price'];
                if ($sell_type === 'loose' && !empty($item['loose_price'])) {
                    $price = (float)$item['loose_price'];
                } elseif ($sell_type === 'whole') {
                    $price = $item['v_price'] !== null ? (float)$item['v_price'] : (float)$item['base_price'];
                }
                $up = $db->prepare("UPDATE pos_active_cart_items SET quantity = :qty, sell_type = :sell_type, price = :price WHERE id = :id");
                $up->execute(['qty' => $qty, 'sell_type' => $sell_type, 'price' => $price, 'id' => $item_id]);
            }
        }

        $db->exec("UPDATE pos_active_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = {$cart_id}");
        echo json_encode(['success' => true, 'version_hash' => compute_cart_version_hash($cart_id, $db)]);
        exit;

    } elseif ($action === 'remove_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $del = $db->prepare("DELETE FROM pos_active_cart_items WHERE id = :id AND cart_id = :cart_id");
        $del->execute(['id' => $item_id, 'cart_id' => $cart_id]);

        $db->exec("UPDATE pos_active_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = {$cart_id}");
        echo json_encode(['success' => true, 'version_hash' => compute_cart_version_hash($cart_id, $db)]);
        exit;

    } elseif ($action === 'clear_cart') {
        $del = $db->prepare("DELETE FROM pos_active_cart_items WHERE cart_id = :cart_id");
        $del->execute(['cart_id' => $cart_id]);

        $up = $db->prepare("UPDATE pos_active_carts SET customer_name = NULL, customer_phone = NULL, customer_address = NULL, discount_amount = 0.00, updated_at = CURRENT_TIMESTAMP WHERE id = :cart_id");
        $up->execute(['cart_id' => $cart_id]);

        echo json_encode(['success' => true, 'version_hash' => compute_cart_version_hash($cart_id, $db)]);
        exit;

    } elseif ($action === 'update_customer') {
        $c_name = trim($_POST['customer_name'] ?? '');
        $c_phone = trim($_POST['customer_phone'] ?? '');
        $c_addr = trim($_POST['customer_address'] ?? '');
        $discount = (float)($_POST['discount_amount'] ?? 0);

        $up = $db->prepare("UPDATE pos_active_carts SET customer_name = :name, customer_phone = :phone, customer_address = :addr, discount_amount = :disc, updated_at = CURRENT_TIMESTAMP WHERE id = :cart_id");
        $up->execute([
            'name' => $c_name,
            'phone' => $c_phone,
            'addr' => $c_addr,
            'disc' => $discount,
            'cart_id' => $cart_id
        ]);

        echo json_encode(['success' => true, 'version_hash' => compute_cart_version_hash($cart_id, $db)]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action requested.']);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
