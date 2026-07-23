<?php
/**
 * POS Return & Exchange API Handler
 * Handles invoice lookup and processing of item returns/exchanges.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

session_start();

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized session.']);
    exit;
}

$db = get_db_connection();
$user_id = $_SESSION['admin_id'];
$username = $_SESSION['admin_username'] ?? 'Cashier';

$raw_input = file_get_contents('php://input');
$json_data = !empty($raw_input) ? json_decode($raw_input, true) : [];
if (!is_array($json_data)) {
    $json_data = [];
}

$action = $_REQUEST['action'] ?? ($json_data['action'] ?? '');

try {
    if ($action === 'lookup') {
        $query = trim($_REQUEST['query'] ?? '');
        if (empty($query)) {
            echo json_encode(['success' => false, 'error' => 'Please enter an invoice number or phone number.']);
            exit;
        }

        // Search by invoice number or phone number
        $stmt = $db->prepare("
            SELECT id, invoice_number, customer_name, customer_phone, total_amount, discount_amount, final_amount, payment_method, created_at
              FROM billing_orders
             WHERE invoice_number = :inv OR customer_phone = :phone
          ORDER BY id DESC
             LIMIT 1
        ");
        $stmt->execute(['inv' => $query, 'phone' => $query]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            // Try fuzzy match on invoice number
            $stmt2 = $db->prepare("
                SELECT id, invoice_number, customer_name, customer_phone, total_amount, discount_amount, final_amount, payment_method, created_at
                  FROM billing_orders
                 WHERE invoice_number LIKE :q
              ORDER BY id DESC
                 LIMIT 1
            ");
            $stmt2->execute(['q' => '%' . $query . '%']);
            $order = $stmt2->fetch(PDO::FETCH_ASSOC);
        }

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'No order found matching: ' . $query]);
            exit;
        }

        // Fetch order items with previously returned quantities
        $stmt_items = $db->prepare("
            SELECT oi.id AS order_item_id,
                   oi.product_id,
                   oi.variant_id,
                   oi.product_name,
                   oi.variant_size,
                   oi.price,
                   oi.quantity AS purchased_qty,
                   COALESCE(SUM(ri.quantity), 0) AS returned_qty
              FROM billing_order_items oi
         LEFT JOIN billing_return_items ri ON ri.order_item_id = oi.id
             WHERE oi.order_id = :order_id
          GROUP BY oi.id
        ");
        $stmt_items->execute(['order_id' => $order['id']]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $formatted_items = [];
        foreach ($items as $item) {
            $purchased = (int)$item['purchased_qty'];
            $returned = (int)$item['returned_qty'];
            $available_for_return = max(0, $purchased - $returned);

            $formatted_items[] = [
                'order_item_id'        => (int)$item['order_item_id'],
                'product_id'           => (int)$item['product_id'],
                'variant_id'           => $item['variant_id'] ? (int)$item['variant_id'] : null,
                'product_name'         => $item['product_name'],
                'variant_size'         => $item['variant_size'],
                'price'                => (float)$item['price'],
                'purchased_qty'        => $purchased,
                'returned_qty'         => $returned,
                'available_for_return' => $available_for_return
            ];
        }

        echo json_encode([
            'success' => true,
            'order'   => [
                'id'              => (int)$order['id'],
                'invoice_number'  => $order['invoice_number'],
                'customer_name'   => $order['customer_name'] ?? 'Walk-in Client',
                'customer_phone'  => $order['customer_phone'] ?? 'N/A',
                'total_amount'    => (float)$order['total_amount'],
                'discount_amount' => (float)$order['discount_amount'],
                'final_amount'    => (float)$order['final_amount'],
                'payment_method'  => $order['payment_method'],
                'created_at'      => date('d M Y, h:i A', strtotime($order['created_at']))
            ],
            'items'   => $formatted_items
        ]);
        exit;

    } elseif ($action === 'process') {
        $data = !empty($json_data) ? $json_data : $_POST;

        $order_id = (int)($data['order_id'] ?? 0);
        $refund_method = trim($data['refund_method'] ?? 'Cash');
        $reason = trim($data['reason'] ?? 'Customer Return');
        $return_items = $data['items'] ?? [];

        if ($order_id <= 0 || empty($return_items)) {
            echo json_encode(['success' => false, 'error' => 'Invalid return submission payload.']);
            exit;
        }

        // Validate order existence and fetch financial details
        $stmt_ord = $db->prepare("SELECT id, invoice_number, total_amount, discount_amount, final_amount FROM billing_orders WHERE id = :id LIMIT 1");
        $stmt_ord->execute(['id' => $order_id]);
        $order = $stmt_ord->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        // Fetch existing refunds on this order to calculate maximum refundable balance
        $stmt_prev_ret = $db->prepare("SELECT COALESCE(SUM(refund_amount), 0) FROM billing_returns WHERE order_id = :order_id");
        $stmt_prev_ret->execute(['order_id' => $order_id]);
        $already_refunded = (float)$stmt_prev_ret->fetchColumn();

        $max_refundable = max(0, (float)$order['final_amount'] - $already_refunded);

        if ($max_refundable <= 0) {
            echo json_encode(['success' => false, 'error' => 'This order has already been fully refunded.']);
            exit;
        }

        $db->beginTransaction();

        $total_refund = 0;
        $processed_items = [];

        // Determine discount ratio (if flat discount was applied to the order)
        $order_total = (float)$order['total_amount'];
        $order_final = (float)$order['final_amount'];
        $discount_ratio = ($order_total > 0) ? ($order_final / $order_total) : 1.0;

        foreach ($return_items as $item) {
            $order_item_id = (int)($item['order_item_id'] ?? 0);
            $qty_to_return = (int)($item['quantity'] ?? 0);

            if ($qty_to_return <= 0) {
                continue;
            }

            // Verify item details, sell_type, and available quantity
            $stmt_item = $db->prepare("
                SELECT oi.id, oi.product_id, oi.variant_id, oi.product_name, oi.variant_size, oi.price, oi.quantity AS purchased_qty, oi.sell_type,
                       COALESCE(SUM(ri.quantity), 0) AS returned_qty
                  FROM billing_order_items oi
             LEFT JOIN billing_return_items ri ON ri.order_item_id = oi.id
                 WHERE oi.id = :item_id AND oi.order_id = :order_id
              GROUP BY oi.id
            ");
            $stmt_item->execute(['item_id' => $order_item_id, 'order_id' => $order_id]);
            $db_item = $stmt_item->fetch(PDO::FETCH_ASSOC);

            if (!$db_item) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => "Item ID $order_item_id not found in order."]);
                exit;
            }

            $available = max(0, (int)$db_item['purchased_qty'] - (int)$db_item['returned_qty']);
            if ($qty_to_return > $available) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => "Cannot return $qty_to_return units of {$db_item['product_name']}. Only $available units available for return."]);
                exit;
            }

            $unit_price = (float)$db_item['price'];
            $sell_type = !empty($db_item['sell_type']) ? $db_item['sell_type'] : 'whole';
            
            // Adjust unit refund for any order-level discount
            $discounted_unit_price = round($unit_price * $discount_ratio, 2);
            $item_refund = round($qty_to_return * $discounted_unit_price, 2);
            $total_refund += $item_refund;

            $processed_items[] = [
                'order_item_id' => $order_item_id,
                'product_id'    => (int)$db_item['product_id'],
                'variant_id'    => $db_item['variant_id'] ? (int)$db_item['variant_id'] : null,
                'product_name'  => $db_item['product_name'],
                'variant_size'  => $db_item['variant_size'],
                'sell_type'     => $sell_type,
                'quantity'      => $qty_to_return,
                'unit_price'    => $unit_price,
                'total_refund'  => $item_refund
            ];
        }

        if (empty($processed_items) || $total_refund <= 0) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'No valid items selected for return.']);
            exit;
        }

        // Cap total refund to remaining maximum refundable balance
        if ($total_refund > $max_refundable) {
            $total_refund = $max_refundable;
        }

        // Generate return number: RET-OE-YYYYMMDD-XXXX
        $date_str = date('Ymd');
        $return_number = 'RET-OE-' . $date_str . '-' . strtoupper(substr(uniqid(), -4));

        // Insert into billing_returns
        $stmt_ret = $db->prepare("
            INSERT INTO billing_returns (return_number, order_id, invoice_number, refund_amount, refund_method, reason, processed_by)
            VALUES (:return_number, :order_id, :invoice_number, :refund_amount, :refund_method, :reason, :processed_by)
        ");
        $stmt_ret->execute([
            'return_number'  => $return_number,
            'order_id'       => $order_id,
            'invoice_number' => $order['invoice_number'],
            'refund_amount'  => $total_refund,
            'refund_method'  => $refund_method,
            'reason'         => $reason,
            'processed_by'   => $username
        ]);
        $return_id = (int)$db->lastInsertId();

        // Insert return items & restock inventory
        $stmt_ret_item = $db->prepare("
            INSERT INTO billing_return_items (return_id, order_item_id, product_id, variant_id, product_name, variant_size, quantity, unit_price, total_refund)
            VALUES (:return_id, :order_item_id, :product_id, :variant_id, :product_name, :variant_size, :quantity, :unit_price, :total_refund)
        ");

        $stmt_var = $db->prepare("SELECT stock_quantity, loose_units_per_whole FROM billing_product_variants WHERE id = :id");
        $stmt_restock = $db->prepare("UPDATE billing_product_variants SET stock_quantity = stock_quantity + :qty WHERE id = :variant_id");
        $stmt_stock_log = $db->prepare("
            INSERT INTO billing_stock_logs (variant_id, order_id, change_type, quantity_changed, result_stock, notes)
            VALUES (:variant_id, :order_id, :change_type, :quantity_changed, :result_stock, :notes)
        ");

        foreach ($processed_items as $p_item) {
            $stmt_ret_item->execute([
                'return_id'     => $return_id,
                'order_item_id' => $p_item['order_item_id'],
                'product_id'    => $p_item['product_id'],
                'variant_id'    => $p_item['variant_id'],
                'product_name'  => $p_item['product_name'],
                'variant_size'  => $p_item['variant_size'],
                'quantity'      => $p_item['quantity'],
                'unit_price'    => $p_item['unit_price'],
                'total_refund'  => $p_item['total_refund']
            ]);

            if ($p_item['variant_id']) {
                $var_id = $p_item['variant_id'];
                $sell_type = $p_item['sell_type'];
                
                $stmt_var->execute(['id' => $var_id]);
                $var_data = $stmt_var->fetch(PDO::FETCH_ASSOC);

                if ($var_data) {
                    $stock_change = 0.00;
                    if ($sell_type === 'loose') {
                        $units_per_whole = (float)($var_data['loose_units_per_whole'] ?? 1);
                        if ($units_per_whole <= 0) $units_per_whole = 1.00;
                        $stock_change = $p_item['quantity'] / $units_per_whole;
                    } else {
                        $stock_change = (float)$p_item['quantity'];
                    }

                    $stmt_restock->execute([
                        'qty'        => $stock_change,
                        'variant_id' => $var_id
                    ]);

                    $new_stock = (float)($var_data['stock_quantity'] + $stock_change);
                    $change_type = ($sell_type === 'loose') ? 'return_loose' : 'return_whole';

                    $stmt_stock_log->execute([
                        'variant_id'       => $var_id,
                        'order_id'         => $order_id,
                        'change_type'      => $change_type,
                        'quantity_changed' => $stock_change,
                        'result_stock'     => $new_stock,
                        'notes'            => "POS Return #{$return_number} for Order #{$order['invoice_number']}"
                    ]);
                }
            }
        }

        // If Cash refund, automatically record register payout if open
        if ($refund_method === 'Cash') {
            $stmt_reg = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
            $stmt_reg->execute(['user_id' => $user_id]);
            $open_session = $stmt_reg->fetch(PDO::FETCH_ASSOC);

            if ($open_session) {
                $stmt_payout = $db->prepare("
                    INSERT INTO cash_register_payouts (session_id, amount, reason, recipient_name)
                    VALUES (:session_id, :amount, :reason, :recipient_name)
                ");
                $stmt_payout->execute([
                    'session_id'     => $open_session['id'],
                    'amount'         => $total_refund,
                    'reason'         => "POS Return Refund ($return_number)",
                    'recipient_name' => "Customer Return ({$order['invoice_number']})"
                ]);
            }
        }

        $db->commit();

        echo json_encode([
            'success'       => true,
            'return_id'     => $return_id,
            'return_number' => $return_number,
            'refund_amount' => $total_refund,
            'refund_method' => $refund_method,
            'message'       => "Return process complete! Total refund of ₹" . number_format($total_refund, 2) . " processed."
        ]);
        exit;

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action parameter.']);
        exit;
    }

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
