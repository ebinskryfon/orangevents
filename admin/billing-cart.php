<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

function adjust_variant_stock($db, $variant_id, $quantity, $sell_type, $change_type, $order_id = null, $notes = '')
{
    if (!$variant_id)
        return;

    // Fetch variant details
    $stmt = $db->prepare("SELECT id, stock_quantity, allow_loose, loose_units_per_whole FROM billing_product_variants WHERE id = :id");
    $stmt->execute(['id' => $variant_id]);
    $var = $stmt->fetch();

    if (!$var)
        return;

    // Determine stock deduction multiplier based on change_type
    $is_deduction = in_array($change_type, ['sale_whole', 'sale_loose']);
    $multiplier = $is_deduction ? -1 : 1;

    // Calculate stock change in packets (whole units)
    $stock_change = 0.00;
    if ($sell_type === 'loose') {
        $units_per_whole = (float) $var['loose_units_per_whole'];
        if ($units_per_whole <= 0)
            $units_per_whole = 1.00;
        $stock_change = ($quantity / $units_per_whole) * $multiplier;
    } else {
        $stock_change = $quantity * $multiplier;
    }

    // Update variant stock quantity
    $stmt_up = $db->prepare("UPDATE billing_product_variants SET stock_quantity = stock_quantity + :qty WHERE id = :id");
    $stmt_up->execute(['qty' => $stock_change, 'id' => $variant_id]);

    // Fetch updated stock for log
    $stmt_stock = $db->prepare("SELECT stock_quantity FROM billing_product_variants WHERE id = :id");
    $stmt_stock->execute(['id' => $variant_id]);
    $result_stock = (float) $stmt_stock->fetchColumn();

    // Log audit
    $stmt_log = $db->prepare("
        INSERT INTO billing_stock_logs (variant_id, order_id, change_type, quantity_changed, result_stock, notes)
        VALUES (:variant_id, :order_id, :change_type, :quantity_changed, :result_stock, :notes)
    ");
    $stmt_log->execute([
        'variant_id' => $variant_id,
        'order_id' => $order_id,
        'change_type' => $change_type,
        'quantity_changed' => $stock_change,
        'result_stock' => $result_stock,
        'notes' => $notes
    ]);
}

check_admin_auth();

if (!has_permission('billing_create')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Access denied: You do not have the required permission (billing_create).']);
        exit;
    } else {
        header('Location: index.php');
        exit;
    }
}

$db = get_db_connection();

// Check if cash register session is active for current user
$user_id = $_SESSION['admin_id'];
$register_stmt = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
$register_stmt->execute(['user_id' => $user_id]);
$is_register_open = (bool) $register_stmt->fetch();

if (!$is_register_open) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Cash register is closed. Please open the cash register to process sales.']);
        exit;
    } else {
        header("Location: billing.php");
        exit;
    }
}


// =========================================================
// POST HANDLER (CHECKOUT API)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $discount_amount = (float) ($_POST['discount_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $cart_data_raw = $_POST['cart_data'] ?? '[]';

    $cart_items = json_decode($cart_data_raw, true);

    if (empty($cart_items)) {
        echo json_encode(['success' => false, 'error' => 'Cart is empty.']);
        exit;
    }

    try {
        $db->beginTransaction();

        $total_amount = 0.00;
        foreach ($cart_items as $item) {
            $total_amount += (float) $item['price'] * (int) $item['quantity'];
        }
        $final_amount = $total_amount - $discount_amount;
        if ($final_amount < 0)
            $final_amount = 0;

        $paid_cash = 0.00;
        $paid_card = 0.00;
        $paid_upi  = 0.00;
        $payment_breakdown = null;

        if ($payment_method === 'Split') {
            $paid_cash = max(0, (float) ($_POST['paid_cash'] ?? 0));
            $paid_upi  = max(0, (float) ($_POST['paid_upi'] ?? 0));
            $paid_card = max(0, (float) ($_POST['paid_card'] ?? 0));
            $sum_paid  = $paid_cash + $paid_upi + $paid_card;

            if (abs($sum_paid - $final_amount) > 0.05) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => "Split payment total (₹" . number_format($sum_paid, 2) . ") does not match payable total (₹" . number_format($final_amount, 2) . ")."]);
                exit;
            }
            $breakdown_arr = [];
            if ($paid_cash > 0) $breakdown_arr['Cash'] = $paid_cash;
            if ($paid_upi > 0)  $breakdown_arr['UPI']  = $paid_upi;
            if ($paid_card > 0) $breakdown_arr['Card'] = $paid_card;
            $payment_breakdown = json_encode($breakdown_arr);
        } else if ($payment_method === 'UPI') {
            $paid_upi = $final_amount;
        } else if ($payment_method === 'Card') {
            $paid_card = $final_amount;
        } else {
            $payment_method = 'Cash';
            $paid_cash = $final_amount;
        }

        $today = date('Ymd');
        $stmt_count = $db->query("SELECT COUNT(*) FROM billing_orders WHERE DATE(created_at) = CURDATE()");
        $today_count = (int) $stmt_count->fetchColumn() + 1;
        $invoice_number = "OE-B-" . $today . "-" . str_pad($today_count, 4, '0', STR_PAD_LEFT);

        $stmt_order = $db->prepare(
            "INSERT INTO billing_orders (invoice_number, customer_name, customer_phone, customer_address, total_amount, discount_amount, final_amount, payment_method, paid_cash, paid_card, paid_upi, payment_breakdown)
             VALUES (:invoice, :name, :phone, :address, :total, :discount, :final, :method, :paid_cash, :paid_card, :paid_upi, :breakdown)"
        );
        $stmt_order->execute([
            'invoice'   => $invoice_number,
            'name'      => !empty($customer_name) ? $customer_name : null,
            'phone'     => !empty($customer_phone) ? $customer_phone : null,
            'address'   => !empty($customer_address) ? $customer_address : null,
            'total'     => $total_amount,
            'discount'  => $discount_amount,
            'final'     => $final_amount,
            'method'    => $payment_method,
            'paid_cash' => $paid_cash,
            'paid_card' => $paid_card,
            'paid_upi'  => $paid_upi,
            'breakdown' => $payment_breakdown
        ]);

        $order_id = $db->lastInsertId();

        // Sync customer profile to customers table
        if (!empty($customer_phone)) {
            try {
                $stmt_cust = $db->prepare("
                    INSERT INTO customers (name, phone, address, total_orders, total_spent)
                    VALUES (:name, :phone, :address, 1, :final_amount)
                    ON DUPLICATE KEY UPDATE
                        name = IF(VALUES(name) != '', VALUES(name), name),
                        address = IF(VALUES(address) != '', VALUES(address), address),
                        total_orders = total_orders + 1,
                        total_spent = total_spent + VALUES(total_spent)
                ");
                $stmt_cust->execute([
                    'name'         => !empty($customer_name) ? $customer_name : 'Walk-in Client',
                    'phone'        => $customer_phone,
                    'address'      => !empty($customer_address) ? $customer_address : null,
                    'final_amount' => $final_amount
                ]);
            } catch (Exception $e) {
                // Ignore non-critical customer sync errors
            }
        }

        $stmt_item = $db->prepare(
            "INSERT INTO billing_order_items (order_id, product_id, variant_id, product_name, variant_size, price, quantity, total_price, sell_type)
             VALUES (:order_id, :prod_id, :var_id, :prod_name, :size, :price, :qty, :total_price, :sell_type)"
        );

        foreach ($cart_items as $item) {
            $prod_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $var_id = (isset($item['variant_id']) && $item['variant_id'] > 0) ? (int) $item['variant_id'] : null;
            $name = $item['name'];
            $size = isset($item['size']) ? $item['size'] : null;
            $price = (float) $item['price'];
            $qty = (int) $item['quantity'];
            $total_price = $price * $qty;
            $sell_type = isset($item['sell_type']) ? $item['sell_type'] : 'whole';

            if ($prod_id <= 0) {
                $stmt_dummy = $db->prepare("SELECT id FROM billing_products WHERE product_name = 'Custom POS Item' LIMIT 1");
                $stmt_dummy->execute();
                $dummy_id = $stmt_dummy->fetchColumn();

                if (!$dummy_id) {
                    $first_cat = $db->query("SELECT id FROM billing_categories LIMIT 1")->fetchColumn();
                    $stmt_ins_dummy = $db->prepare(
                        "INSERT INTO billing_products (category_id, product_name, description, base_price, is_active)
                         VALUES (:cat, 'Custom POS Item', 'Custom item added on checkout', 0.00, 0)"
                    );
                    $stmt_ins_dummy->execute(['cat' => $first_cat]);
                    $dummy_id = $db->lastInsertId();
                }
                $prod_id = $dummy_id;
            }

            $stmt_item->execute([
                'order_id' => $order_id,
                'prod_id' => $prod_id,
                'var_id' => $var_id,
                'prod_name' => $name,
                'size' => $size,
                'price' => $price,
                'qty' => $qty,
                'total_price' => $total_price,
                'sell_type' => $sell_type
            ]);

            if ($var_id) {
                $change_type = ($sell_type === 'loose') ? 'sale_loose' : 'sale_whole';
                adjust_variant_stock($db, $var_id, $qty, $sell_type, $change_type, $order_id, "Order checkout #{$invoice_number}");
            }
        }

        $db->commit();

        echo json_encode(['success' => true, 'order_id' => $order_id]);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

// Fetch Settings for default UPI Phone VPA
$settings_res = $db->query("SELECT * FROM settings")->fetchAll();
$settings = [];
foreach ($settings_res as $row) {
    $settings[$row['key']] = $row['value'];
}
$company_phone = $settings['company_phone'] ?? '9946731720';
$phone_parts = explode('|', $company_phone);
$primary_phone = trim($phone_parts[0]);
$clean_phone = preg_replace('/[^0-9]/', '', $primary_phone);
if (strlen($clean_phone) > 10) {
    $clean_phone = substr($clean_phone, 0, 10);
}
if (empty($clean_phone)) {
    $clean_phone = '9946731720';
}
$default_upi = $clean_phone . '@upi';
?>

<!-- Title & Back Link -->
<div class="content-header" style="margin-bottom: 0.75rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0; display: flex; justify-content: space-between; align-items: flex-start;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-cart-shopping" style="color:var(--accent-color);"></i>
            Checkout & Billing
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            Finalize order details, apply discount, and choose payment method.
        </p>
    </div>
    <div>
        <a href="billing.php" id="backToBillingLink" class="btn btn-secondary"
            style="padding:0.25rem 0.6rem; font-size:0.75rem; height:28px; display:inline-flex; align-items:center; gap:0.3rem; margin:0;">
            <i class="fa-solid fa-arrow-left-long"></i> Add More Items
        </a>
    </div>
</div>

<style>

    .checkout-container {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 0.75rem;
        align-items: start;
        height: auto;
        overflow: visible;
    }

    @media (max-width: 992px) {
        .checkout-container {
            grid-template-columns: 1fr;
            height: auto;
            overflow: visible;
        }
    }

    .cart-summary-table {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 0.75rem;
        box-shadow: var(--box-shadow);
        display: flex;
        flex-direction: column;
        height: fit-content;
    }

    .checkout-right-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 0.75rem;
        box-shadow: var(--box-shadow);
        display: flex;
        flex-direction: column;
        height: auto;
        overflow: visible;
    }

    .payment-options {
        display: flex;
        gap: 0.4rem;
        margin-top: 0.3rem;
    }

    .pay-btn {
        flex: 1;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.4rem;
        text-align: center;
        cursor: pointer;
        transition: var(--transition-fast);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.2rem;
        font-weight: 600;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .pay-btn:hover {
        border-color: var(--border-highlight);
        color: var(--text-primary);
    }

    .pay-btn.active {
        background: rgba(255, 107, 53, 0.08);
        border-color: var(--accent-color);
        color: var(--accent-color);
    }

    .pay-btn i {
        font-size: 1rem;
    }

    .qr-container {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.5rem;
        margin-top: 0.5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .summary-box {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.5rem;
        margin-bottom: 0.25rem;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.25rem;
        font-size: 0.8rem;
    }

    .summary-row:last-child {
        margin-bottom: 0;
    }

    .summary-total {
        border-top: 1px dashed var(--border-color);
        padding-top: 0.25rem;
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .pos-hotkey-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.35rem 0.75rem;
        margin-top: 0.5rem;
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .pos-hotkey-bar kbd {
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        border-bottom: 2px solid var(--border-highlight);
        border-radius: 4px;
        padding: 0.1rem 0.35rem;
        font-family: monospace;
        font-weight: 700;
        color: var(--accent-color);
        font-size: 0.7rem;
    }

    .pos-hotkey-item {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
</style>

<div class="checkout-container">
    <!-- Left column: Items in Cart -->
    <div class="cart-summary-table">
        <h3
            style="font-size:0.95rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; margin-bottom:0.5rem; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <span style="display:flex; align-items:center; gap:0.4rem;">
                Review Selected Items
                <span id="cartItemCountBadge" style="background:rgba(255, 107, 53, 0.1); color:var(--accent-color); font-size:0.7rem; font-weight:700; padding:0.1rem 0.45rem; border-radius:20px; letter-spacing:0.03em;">0 items</span>
            </span>
            <button onclick="clearCartAndGoBack()" class="btn btn-secondary"
                style="padding:0.2rem 0.4rem; font-size:0.7rem; color:var(--danger); border-color:rgba(255, 71, 87, 0.2);">
                <i class="fa-solid fa-trash-can"></i> Clear Cart
            </button>
        </h3>

        <div id="cartItemsList" style="max-height: 480px; overflow-y: auto; display:flex; flex-direction:column; gap:0.4rem; margin-bottom:0.5rem; padding-right:0.25rem;">
            <!-- Rendered dynamically -->
        </div>

        <div style="text-align:right; flex-shrink:0; border-top:1px solid var(--border-color); padding-top:0.5rem;">
            <a href="billing.php" id="changeItemsLink" class="btn btn-secondary" style="font-size:0.75rem; padding: 0.25rem 0.5rem;">
                <i class="fa-solid fa-plus"></i> Add/Change Items
            </a>
        </div>
    </div>

    <!-- Right column: Customer details, discount, payment & checkout -->
    <div class="checkout-right-card">
        <h3
            style="font-size:0.95rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; margin-bottom:0.5rem; flex-shrink:0;">
            Order & Payment Details
        </h3>

        <form id="checkoutForm" method="POST" style="margin:0; display:flex; flex-direction:column; gap:0.5rem;">
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" id="cartDataInput" name="cart_data">

            <!-- Customer Live Profile Badge -->
            <div id="customerBadgeContainer" style="display:none; margin-bottom:0.25rem;"></div>

            <!-- Customer Details Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; flex-shrink:0;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Customer Phone</label>
                    <input type="text" id="customerPhone" name="customer_phone" class="form-control"
                        placeholder="Phone No." style="height:30px; font-size:0.8rem; padding:0.25rem 0.5rem;" oninput="handleCustomerPhoneAutoFetch()">
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Customer Name</label>
                    <input type="text" id="customerName" name="customer_name" class="form-control"
                        placeholder="Walk-in Client" style="height:30px; font-size:0.8rem; padding:0.25rem 0.5rem;">
                </div>
            </div>

            <div class="form-group" style="margin:0; flex-shrink:0;">
                <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Customer Address</label>
                <textarea id="customerAddress" name="customer_address" class="form-control"
                    placeholder="Customer Address..." rows="1" style="resize:vertical; font-size:0.8rem; padding:0.25rem 0.5rem; min-height:28px;"></textarea>
            </div>

            <!-- Discount Input -->
            <div class="form-group" style="margin:0; flex-shrink:0;">
                <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Discount (Flat Rs)</label>
                <input type="number" min="0" value="0" id="discountInput" name="discount_amount" class="form-control"
                    placeholder="0.00" style="height:30px; font-size:0.8rem; padding:0.25rem 0.5rem;">
            </div>

            <!-- Summary Box -->
            <div class="summary-box" style="margin:0.25rem 0 0 0; flex-shrink:0;">
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="summarySubtotal">₹0.00</span>
                </div>
                <div class="summary-row">
                    <span>Discount</span>
                    <span id="summaryDiscount" style="color:var(--danger);">-₹0.00</span>
                </div>
                <div class="summary-row summary-total">
                    <span>Payable Total</span>
                    <span id="summaryTotal" style="color:var(--accent-color);">₹0.00</span>
                </div>
            </div>

            <!-- Payment mode selection -->
            <div style="margin:0; flex-shrink:0;">
                <label class="form-label" style="font-size:0.75rem; margin-bottom:0.15rem; display:block;">Payment Method</label>
                <div class="payment-options">
                    <div class="pay-btn active" id="payCash" onclick="selectPaymentMethod('Cash')">
                        <i class="fa-solid fa-money-bill-1-wave"></i>
                        <span>Cash</span>
                    </div>
                    <div class="pay-btn" id="payUPI" onclick="selectPaymentMethod('UPI')">
                        <i class="fa-solid fa-qrcode"></i>
                        <span>UPI QR</span>
                    </div>
                    <div class="pay-btn" id="payCard" onclick="selectPaymentMethod('Card')">
                        <i class="fa-solid fa-credit-card"></i>
                        <span>Card</span>
                    </div>
                    <div class="pay-btn" id="paySplit" onclick="selectPaymentMethod('Split')">
                        <i class="fa-solid fa-sliders"></i>
                        <span>Split</span>
                    </div>
                </div>
                <input type="hidden" id="paymentMethodInput" name="payment_method" value="Cash">
            </div>

            <!-- UPI Payment Area -->
            <div id="upiPaymentArea" style="display:none; margin:0; flex-shrink:0;">
                <div class="qr-container">
                    <div
                        style="font-size:0.75rem; color:var(--text-secondary); width:100%; display:flex; align-items:center; gap:0.4rem; margin-bottom:0.25rem;">
                        <span style="white-space:nowrap;">UPI VPA:</span>
                        <input type="text" id="upiPhoneInput" class="form-control"
                            style="padding:0.2rem 0.4rem; font-size:0.75rem; height:24px;"
                            value="<?= h($default_upi) ?>">
                    </div>
                    <img id="upiQRCodeImage" src="" alt="UPI Payment QR Code"
                        style="background:#fff; border-radius:4px; padding:0.25rem; display:block; width:120px; height:120px;">
                    <div style="font-size:0.65rem; color:var(--text-muted); text-align:center; margin-top:0.25rem;">
                        <i class="fa-solid fa-circle-info"></i> Scan with any UPI app.
                    </div>
                </div>
            </div>

            <!-- Split Payment Area (Phase 4) -->
            <div id="splitPaymentArea" style="display:none; margin:0; flex-shrink:0;">
                <div class="qr-container" style="align-items:stretch; padding:0.5rem 0.6rem;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-primary); margin-bottom:0.4rem; display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fa-solid fa-sliders" style="color:var(--accent-color);"></i> Multi-Tender Breakdown</span>
                        <span id="splitStatusBadge" class="badge" style="font-size:0.65rem; padding:2px 6px; background:rgba(255,71,87,0.15); color:var(--danger); border-radius:10px;">Unbalanced</span>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:0.4rem; margin-bottom:0.4rem;">
                        <div>
                            <label style="font-size:0.65rem; color:var(--text-muted); display:block; margin-bottom:0.15rem;">Cash (₹)</label>
                            <input type="number" step="0.01" min="0" value="0.00" id="splitCashInput" name="paid_cash" class="form-control" style="height:26px; font-size:0.75rem; padding:0.2rem 0.35rem;" oninput="updateSplitTotals()">
                        </div>
                        <div>
                            <label style="font-size:0.65rem; color:var(--text-muted); display:block; margin-bottom:0.15rem;">UPI (₹)</label>
                            <input type="number" step="0.01" min="0" value="0.00" id="splitUPIInput" name="paid_upi" class="form-control" style="height:26px; font-size:0.75rem; padding:0.2rem 0.35rem;" oninput="updateSplitTotals()">
                        </div>
                        <div>
                            <label style="font-size:0.65rem; color:var(--text-muted); display:block; margin-bottom:0.15rem;">Card (₹)</label>
                            <input type="number" step="0.01" min="0" value="0.00" id="splitCardInput" name="paid_card" class="form-control" style="height:26px; font-size:0.75rem; padding:0.2rem 0.35rem;" oninput="updateSplitTotals()">
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.7rem; padding:0.3rem 0.45rem; background:var(--bg-control); border-radius:4px; border:1px solid var(--border-color);">
                        <span>Allocated: <strong id="splitAllocatedTotal" style="color:var(--text-primary);">₹0.00</strong></span>
                        <span>Remaining: <strong id="splitRemainingBalance" style="color:var(--danger);">₹0.00</strong></span>
                    </div>
                </div>
            </div>

            <!-- Submit button -->
            <button type="button" onclick="submitCheckout()" class="btn btn-success"
                style="width:100%; height:38px; margin-top:0.4rem; font-size:0.85rem; font-weight:700; box-shadow:0 4px 12px rgba(46, 213, 115, 0.15); flex-shrink:0; display:flex; align-items:center; justify-content:center; gap:0.3rem;">
                <i class="fa-solid fa-file-invoice-dollar"></i> Generate & Print Receipt
            </button>
        </form>
    </div>
</div>

<!-- POS Keyboard Shortcut Legend Bar -->
<div class="pos-hotkey-bar">
    <span style="font-weight: 700; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem;">
        <i class="fa-solid fa-keyboard" style="color: var(--accent-color);"></i> Shortcuts:
    </span>
    <span class="pos-hotkey-item"><kbd>F2</kbd> Customer Field</span>
    <span class="pos-hotkey-item"><kbd>F7</kbd> Return Terminal</span>
    <span class="pos-hotkey-item"><kbd>F8</kbd> Discount Field</span>
    <span class="pos-hotkey-item"><kbd>F9</kbd> Cash & Print</span>
    <span class="pos-hotkey-item"><kbd>F10</kbd> Complete Order</span>
    <span class="pos-hotkey-item"><kbd>ESC</kbd> Back to POS</span>
</div>

<?php include_once __DIR__ . '/../includes/return-modal.php'; ?>

<script>
    let cart = [];
    let selectedPaymentMethod = 'Cash';
    let customerAutoFetchTimer = null;

    function handleCustomerPhoneAutoFetch() {
        clearTimeout(customerAutoFetchTimer);
        const phoneInput = document.getElementById('customerPhone');
        const badgeBox = document.getElementById('customerBadgeContainer');
        if (!phoneInput) return;

        const val = phoneInput.value.trim();
        if (val.length < 3) {
            if (badgeBox) badgeBox.style.display = 'none';
            return;
        }

        customerAutoFetchTimer = setTimeout(() => {
            const apiPath = window.location.pathname.includes('/admin/') ? '../api/search-customer.php' : 'api/search-customer.php';
            fetch(`${apiPath}?phone=${encodeURIComponent(val)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.found && data.customer) {
                        const cust = data.customer;
                        document.getElementById('customerName').value = cust.name;
                        if (cust.address) document.getElementById('customerAddress').value = cust.address;

                        if (badgeBox) {
                            badgeBox.className = 'badge';
                            badgeBox.style.display = 'block';
                            badgeBox.style.background = 'rgba(46, 213, 115, 0.12)';
                            badgeBox.style.color = '#2ed573';
                            badgeBox.style.border = '1px solid rgba(46, 213, 115, 0.3)';
                            badgeBox.style.padding = '0.35rem 0.6rem';
                            badgeBox.style.borderRadius = '6px';
                            badgeBox.style.fontSize = '0.72rem';
                            badgeBox.style.fontWeight = '600';
                            badgeBox.innerHTML = `
                                <i class="fa-solid fa-user-check"></i> Returning Client: <strong>${escapeHtml(cust.name)}</strong> 
                                • 🛍️ ${cust.total_orders} Orders 
                                • 💰 Spent ₹${cust.total_spent.toFixed(2)}
                            `;
                        }
                    } else if (badgeBox) {
                        badgeBox.style.display = 'block';
                        badgeBox.style.background = 'rgba(255, 107, 53, 0.1)';
                        badgeBox.style.color = 'var(--accent-color)';
                        badgeBox.style.border = '1px solid rgba(255, 107, 53, 0.2)';
                        badgeBox.style.padding = '0.3rem 0.5rem';
                        badgeBox.style.borderRadius = '6px';
                        badgeBox.style.fontSize = '0.7rem';
                        badgeBox.innerHTML = `<i class="fa-solid fa-sparkles"></i> New Client Profile (Auto-saved on checkout)`;
                    }
                })
                .catch(() => {});
        }, 300);
    }

    // Initialize Cart from LocalStorage
    function loadCart() {
        const saved = localStorage.getItem('orange_billing_cart');
        if (saved) {
            try {
                cart = json_parse_or_empty(saved);
            } catch (e) {
                cart = [];
            }
        }
        renderCartItems();
        updateSummary();
    }

    function json_parse_or_empty(str) {
        try {
            return JSON.parse(str);
        } catch (e) {
            return [];
        }
    }

    function saveCart() {
        localStorage.setItem('orange_billing_cart', JSON.stringify(cart));
    }

    // Render Items inside Cart Summary
    function renderCartItems() {
        const container = document.getElementById('cartItemsList');
        if (!container) return;

        if (cart.length === 0) {
            container.innerHTML = `
            <div style="text-align:center; padding:2rem 1rem; color:var(--text-muted); display:flex; flex-direction:column; align-items:center; gap:0.5rem;">
                <i class="fa-solid fa-basket-shopping" style="font-size:2.2rem; opacity:0.25;"></i>
                <span style="font-size:0.75rem;">Your cart is empty. Please <a href="billing.php" id="emptyCartBackLink" style="color:var(--accent-color); font-weight:600;">go back</a> and add products.</span>
            </div>
        `;
            return;
        }

        let html = '';
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;

            let sellTypeSelectorHtml = '';
            if (parseInt(item.allow_loose) === 1) {
                const loosePriceVal = parseFloat(item.loose_price || 0);
                const wholePriceVal = parseFloat(item.whole_price || 0);
                sellTypeSelectorHtml = `
                    <div style="margin-top: 0.1rem; display: flex; gap: 0.4rem; align-items: center;">
                        <span style="font-size:0.65rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.03em;">Type:</span>
                        <label style="font-size:0.65rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.15rem; margin:0;">
                            <input type="radio" name="sell_type_checkout_${index}" value="whole" ${item.sell_type === 'whole' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'whole')" style="accent-color:var(--accent-color); cursor:pointer; transform: scale(0.85);">
                            Pack (₹${wholePriceVal.toFixed(2)})
                        </label>
                        <label style="font-size:0.65rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.15rem; margin:0;">
                            <input type="radio" name="sell_type_checkout_${index}" value="loose" ${item.sell_type === 'loose' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'loose')" style="accent-color:var(--accent-color); cursor:pointer; transform: scale(0.85);">
                            Loose (₹${loosePriceVal.toFixed(2)})
                        </label>
                    </div>
                `;
            }

            html += `
            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem; background:rgba(255,255,255,0.015); border:1px solid var(--border-color); border-radius:var(--border-radius-md); padding:0.3rem 0.5rem;">
                <div style="flex-grow:1; min-width:0;">
                    <div style="font-weight:600; font-size:0.8rem; color:var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                    <div style="display:flex; gap:0.35rem; align-items:center; margin-top:0.1rem;">
                        ${item.size ? `<span style="font-size:0.65rem; color:var(--text-muted);">Size: ${escapeHtml(item.size)}</span>` : ''}
                        <span style="font-size:0.7rem; color:var(--accent-color); font-weight:600;">₹${parseFloat(item.price).toFixed(2)}</span>
                    </div>
                    ${sellTypeSelectorHtml}
                </div>
                
                <div style="display:flex; align-items:center; gap:0.35rem; flex-shrink:0;">
                    <div style="display:flex; align-items:center; border:1px solid var(--border-color); border-radius:4px; overflow:hidden;">
                        <button type="button" onclick="updateQty(${index}, -1)" style="border:none; background:var(--bg-control); color:var(--text-primary); width:20px; height:20px; font-weight:bold; cursor:pointer; font-size:0.75rem; display:flex; align-items:center; justify-content:center;">-</button>
                        <span style="width:24px; text-align:center; font-weight:600; font-size:0.75rem; color:var(--text-primary);">${item.quantity}</span>
                        <button type="button" onclick="updateQty(${index}, 1)" style="border:none; background:var(--bg-control); color:var(--text-primary); width:20px; height:20px; font-weight:bold; cursor:pointer; font-size:0.75rem; display:flex; align-items:center; justify-content:center;">+</button>
                    </div>
                    
                    <div style="font-weight:700; font-size:0.8rem; width:60px; text-align:right; color:var(--text-primary);">₹${itemTotal.toFixed(2)}</div>
                    
                    <button type="button" onclick="removeItem(${index})" style="background:rgba(255, 71, 87, 0.08); border:none; color:var(--danger); cursor:pointer; padding:4px; width:20px; height:20px; border-radius:4px; display:flex; align-items:center; justify-content:center;" title="Remove Item">
                        <i class="fa-solid fa-trash-can" style="font-size:0.65rem;"></i>
                    </button>
                </div>
            </div>
        `;
        });
        container.innerHTML = html;
    }

    function changeItemSellType(index, type) {
        if (!cart[index]) return;
        cart[index].sell_type = type;
        if (type === 'loose') {
            cart[index].price = parseFloat(cart[index].loose_price);
        } else {
            cart[index].price = parseFloat(cart[index].whole_price);
        }
        saveCart();
        renderCartItems();
        updateSummary();
    }

    function updateQty(index, delta) {
        cart[index].quantity += delta;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        }
        saveCart();
        renderCartItems();
        updateSummary();
    }

    function removeItem(index) {
        cart.splice(index, 1);
        saveCart();
        renderCartItems();
        updateSummary();
    }

    function clearCartAndGoBack() {
        if (confirm('Are you sure you want to clear your cart?')) {
            cart = [];
            saveCart();
            const sourcePage = localStorage.getItem('orange_billing_source') || 'billing.php';
            window.location.href = sourcePage;
        }
    }

    // Calculate Summary Totals
    function updateSummary() {
        let subtotal = 0;
        let totalItems = 0;
        cart.forEach(item => {
            subtotal += item.price * item.quantity;
            totalItems += item.quantity;
        });

        const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
        const finalTotal = Math.max(0, subtotal - discountVal);

        document.getElementById('summarySubtotal').innerText = '₹' + subtotal.toFixed(2);
        document.getElementById('summaryDiscount').innerText = '-₹' + discountVal.toFixed(2);
        document.getElementById('summaryTotal').innerText = '₹' + finalTotal.toFixed(2);

        const badge = document.getElementById('cartItemCountBadge');
        if (badge) badge.innerText = `${totalItems} item${totalItems !== 1 ? 's' : ''}`;

        if (selectedPaymentMethod === 'UPI') {
            generateUPICode(finalTotal);
        } else if (selectedPaymentMethod === 'Split') {
            updateSplitTotals();
        }
    }

    function updateSplitTotals() {
        let subtotal = 0;
        cart.forEach(item => { subtotal += item.price * item.quantity; });
        const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
        const finalTotal = Math.max(0, subtotal - discountVal);

        const cashVal = parseFloat(document.getElementById('splitCashInput').value) || 0;
        const upiVal = parseFloat(document.getElementById('splitUPIInput').value) || 0;
        const cardVal = parseFloat(document.getElementById('splitCardInput').value) || 0;

        const allocated = cashVal + upiVal + cardVal;
        const remaining = finalTotal - allocated;

        document.getElementById('splitAllocatedTotal').innerText = '₹' + allocated.toFixed(2);
        
        const remEl = document.getElementById('splitRemainingBalance');
        const badge = document.getElementById('splitStatusBadge');

        if (Math.abs(remaining) < 0.01) {
            remEl.innerText = '₹0.00';
            remEl.style.color = 'var(--success)';
            badge.innerText = 'Balanced';
            badge.style.background = 'rgba(46, 213, 115, 0.15)';
            badge.style.color = 'var(--success)';
        } else if (remaining > 0) {
            remEl.innerText = '₹' + remaining.toFixed(2);
            remEl.style.color = 'var(--danger)';
            badge.innerText = 'Needs ₹' + remaining.toFixed(2);
            badge.style.background = 'rgba(255, 71, 87, 0.15)';
            badge.style.color = 'var(--danger)';
        } else {
            remEl.innerText = '-₹' + Math.abs(remaining).toFixed(2);
            remEl.style.color = 'var(--warning)';
            badge.innerText = 'Overpaid ₹' + Math.abs(remaining).toFixed(2);
            badge.style.background = 'rgba(255, 170, 0, 0.15)';
            badge.style.color = 'var(--warning)';
        }
    }

    // Discount input change listener
    document.getElementById('discountInput').addEventListener('input', function () {
        localStorage.setItem('orange_billing_discount', this.value);
        updateSummary();
    });

    // Payment Method Switcher
    function selectPaymentMethod(method) {
        selectedPaymentMethod = method;
        document.getElementById('paymentMethodInput').value = method;
        localStorage.setItem('orange_billing_payment_method', method);

        document.querySelectorAll('.pay-btn').forEach(btn => btn.classList.remove('active'));

        const upiArea = document.getElementById('upiPaymentArea');
        const splitArea = document.getElementById('splitPaymentArea');

        upiArea.style.display = 'none';
        splitArea.style.display = 'none';

        if (method === 'Cash') {
            document.getElementById('payCash').classList.add('active');
        } else if (method === 'UPI') {
            document.getElementById('payUPI').classList.add('active');
            upiArea.style.display = 'block';
            updateSummary(); // trigger QR generation
        } else if (method === 'Card') {
            document.getElementById('payCard').classList.add('active');
        } else if (method === 'Split') {
            document.getElementById('paySplit').classList.add('active');
            splitArea.style.display = 'block';

            let subtotal = 0;
            cart.forEach(item => { subtotal += item.price * item.quantity; });
            const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
            const finalTotal = Math.max(0, subtotal - discountVal);

            const cashInput = document.getElementById('splitCashInput');
            const upiInput = document.getElementById('splitUPIInput');
            const cardInput = document.getElementById('splitCardInput');

            if ((parseFloat(cashInput.value) || 0) === 0 && (parseFloat(upiInput.value) || 0) === 0 && (parseFloat(cardInput.value) || 0) === 0) {
                cashInput.value = finalTotal.toFixed(2);
            }
            updateSplitTotals();
        }
    }

    // UPI Dynamic QR Code Generation
    function generateUPICode(amount) {
        const upiPhoneInput = document.getElementById('upiPhoneInput').value.trim();
        if (!upiPhoneInput) return;

        const companyName = "Orange Events";
        const upiUrl = `upi://pay?pa=${encodeURIComponent(upiPhoneInput)}&pn=${encodeURIComponent(companyName)}&am=${amount.toFixed(2)}&cu=INR&tn=Invoice%20Payment`;

        const qrImg = document.getElementById('upiQRCodeImage');
        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=${encodeURIComponent(upiUrl)}`;
    }

    // Recalculate QR Code when UPI input phone changes
    document.getElementById('upiPhoneInput').addEventListener('input', function () {
        localStorage.setItem('orange_billing_upi_phone', this.value);
        let subtotal = 0;
        cart.forEach(item => { subtotal += item.price * item.quantity; });
        const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
        const finalTotal = Math.max(0, subtotal - discountVal);
        generateUPICode(finalTotal);
    });

    // Checkout Submission
    function submitCheckout() {
        if (cart.length === 0) {
            alert('Cart is empty.');
            return;
        }

        if (selectedPaymentMethod === 'Split') {
            let subtotal = 0;
            cart.forEach(item => { subtotal += item.price * item.quantity; });
            const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
            const finalTotal = Math.max(0, subtotal - discountVal);

            const cashVal = parseFloat(document.getElementById('splitCashInput').value) || 0;
            const upiVal = parseFloat(document.getElementById('splitUPIInput').value) || 0;
            const cardVal = parseFloat(document.getElementById('splitCardInput').value) || 0;

            const allocated = cashVal + upiVal + cardVal;
            if (Math.abs(finalTotal - allocated) > 0.01) {
                alert('Split payment total (₹' + allocated.toFixed(2) + ') must equal Payable Total (₹' + finalTotal.toFixed(2) + ').');
                return;
            }
        }

        const customerName = document.getElementById('customerName').value.trim();
        const customerPhone = document.getElementById('customerPhone').value.trim();

        document.getElementById('cartDataInput').value = JSON.stringify(cart);

        const formData = new FormData(document.getElementById('checkoutForm'));

        fetch('billing-cart.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    localStorage.removeItem('orange_billing_customer_name');
                    localStorage.removeItem('orange_billing_customer_phone');
                    localStorage.removeItem('orange_billing_cart');
                    localStorage.removeItem('orange_billing_discount');
                    window.location.href = 'billing-invoice.php?id=' + data.order_id + '&print=1';
                } else {
                    alert('Checkout failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error processing checkout request.');
            });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Load cart on page ready
    document.addEventListener('DOMContentLoaded', () => {
        const customerName = document.getElementById('customerName');
        const customerPhone = document.getElementById('customerPhone');
        const discountInput = document.getElementById('discountInput');
        const upiPhoneInput = document.getElementById('upiPhoneInput');

        // Always start with fresh empty customer fields for new billing sessions
        if (customerName) customerName.value = '';
        if (customerPhone) customerPhone.value = '';
        const badgeBox = document.getElementById('customerBadgeContainer');
        if (badgeBox) badgeBox.style.display = 'none';

        discountInput.value = localStorage.getItem('orange_billing_discount') || '0';

        const savedPayment = localStorage.getItem('orange_billing_payment_method') || 'Cash';
        selectPaymentMethod(savedPayment);

        const savedUpiPhone = localStorage.getItem('orange_billing_upi_phone');
        if (savedUpiPhone && upiPhoneInput) {
            upiPhoneInput.value = savedUpiPhone;
        }

        // Apply dynamic navigation source page back links
        const sourcePage = localStorage.getItem('orange_billing_source') || 'billing.php';
        const backToBillingLink = document.getElementById('backToBillingLink');
        if (backToBillingLink) backToBillingLink.href = sourcePage;
        const changeItemsLink = document.getElementById('changeItemsLink');
        if (changeItemsLink) changeItemsLink.href = sourcePage;
        const emptyCartBackLink = document.getElementById('emptyCartBackLink');
        if (emptyCartBackLink) emptyCartBackLink.href = sourcePage;

        // Keyboard Shortcuts Handler (Phase 1)
        document.addEventListener('keydown', (e) => {
            // F2: Focus Customer Name
            if (e.key === 'F2') {
                e.preventDefault();
                if (customerName) {
                    customerName.focus();
                    customerName.select();
                }
                return;
            }

            // F8: Focus Discount input
            if (e.key === 'F8') {
                e.preventDefault();
                if (discountInput) {
                    discountInput.focus();
                    discountInput.select();
                }
                return;
            }

            // F9: Quick Cash & Print
            if (e.key === 'F9') {
                e.preventDefault();
                selectPaymentMethod('Cash');
                submitCheckout();
                return;
            }

            // F10 or Ctrl+Enter: Submit checkout with current payment method
            if (e.key === 'F10' || (e.ctrlKey && e.key === 'Enter')) {
                e.preventDefault();
                submitCheckout();
                return;
            }

            // ESC: Return back to POS terminal page
            if (e.key === 'Escape') {
                const active = document.activeElement;
                if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
                    active.blur();
                } else {
                    const sourcePage = localStorage.getItem('orange_billing_source') || 'billing.php';
                    window.location.href = sourcePage;
                }
                return;
            }
        });

        loadCart();
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>