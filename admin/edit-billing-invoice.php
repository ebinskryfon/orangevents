<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();
require_permission('billing_update');

$db = get_db_connection();

function adjust_variant_stock($db, $variant_id, $quantity, $sell_type, $change_type, $order_id = null, $notes = '') {
    if (!$variant_id) return;
    
    // Fetch variant details
    $stmt = $db->prepare("SELECT id, stock_quantity, allow_loose, loose_units_per_whole FROM billing_product_variants WHERE id = :id");
    $stmt->execute(['id' => $variant_id]);
    $var = $stmt->fetch();
    
    if (!$var) return;
    
    // Determine stock deduction multiplier based on change_type
    $is_deduction = in_array($change_type, ['sale_whole', 'sale_loose']);
    $multiplier = $is_deduction ? -1 : 1;
    
    // Calculate stock change in packets (whole units)
    $stock_change = 0.00;
    if ($sell_type === 'loose') {
        $units_per_whole = (float)$var['loose_units_per_whole'];
        if ($units_per_whole <= 0) $units_per_whole = 1.00;
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
    $result_stock = (float)$stmt_stock->fetchColumn();
    
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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: billing-invoices.php");
    exit;
}

// Fetch billing order
$stmt_ord = $db->prepare("SELECT * FROM billing_orders WHERE id = :id");
$stmt_ord->execute(['id' => $id]);
$order = $stmt_ord->fetch();

if (!$order) {
    header("Location: billing-invoices.php");
    exit;
}

// Fetch existing order items
$stmt_items = $db->prepare("
    SELECT oi.*, 
           v.allow_loose, 
           v.loose_price, 
           v.loose_units_per_whole, 
           COALESCE(v.price, p.base_price) as whole_price
      FROM billing_order_items oi
      LEFT JOIN billing_product_variants v ON oi.variant_id = v.id
      LEFT JOIN billing_products p ON oi.product_id = p.id
     WHERE oi.order_id = :id 
     ORDER BY oi.id ASC
");
$stmt_items->execute(['id' => $id]);
$items = $stmt_items->fetchAll();

// POST Handlers (Update logic)
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $created_at = trim($_POST['created_at'] ?? '');
    $discount_amount = (float) ($_POST['discount_amount'] ?? 0);
    $cart_data_raw = $_POST['cart_data'] ?? '[]';

    $items_posted = json_decode($cart_data_raw, true);

    if (empty($items_posted)) {
        $error = "Invoice must contain at least one product line item.";
    } elseif (empty($invoice_number)) {
        $error = "Invoice number cannot be empty.";
    } elseif (empty($created_at)) {
        $error = "Invoice date/time is required.";
    } else {
        try {
            $db->beginTransaction();

            // Check invoice number uniqueness
            $stmt_check = $db->prepare("SELECT id FROM billing_orders WHERE invoice_number = :num AND id != :id");
            $stmt_check->execute(['num' => $invoice_number, 'id' => $id]);
            if ($stmt_check->fetch()) {
                throw new Exception("Invoice number '{$invoice_number}' is already in use by another billing record.");
            }

            // Calculate totals
            $total_amount = 0.00;
            foreach ($items_posted as $item) {
                $total_amount += (float) $item['price'] * (int) $item['quantity'];
            }
            $final_amount = $total_amount - $discount_amount;
            if ($final_amount < 0) {
                $final_amount = 0.00;
            }

            // Format date for DB
            $db_created_at = date('Y-m-d H:i:s', strtotime($created_at));

            // Update billing order
            $stmt_up = $db->prepare("
                UPDATE billing_orders
                   SET invoice_number = :invoice_number,
                       customer_name = :customer_name,
                       customer_phone = :customer_phone,
                       customer_address = :customer_address,
                       total_amount = :total_amount,
                       discount_amount = :discount_amount,
                       final_amount = :final_amount,
                       payment_method = :payment_method,
                       created_at = :created_at
                 WHERE id = :id
            ");
            $stmt_up->execute([
                'invoice_number' => $invoice_number,
                'customer_name' => !empty($customer_name) ? $customer_name : null,
                'customer_phone' => !empty($customer_phone) ? $customer_phone : null,
                'customer_address' => !empty($customer_address) ? $customer_address : null,
                'total_amount' => $total_amount,
                'discount_amount' => $discount_amount,
                'final_amount' => $final_amount,
                'payment_method' => $payment_method,
                'created_at' => $db_created_at,
                'id' => $id
            ]);

            // Restore stock for old items first
            $stmt_old_items = $db->prepare("SELECT variant_id, quantity, sell_type FROM billing_order_items WHERE order_id = :id");
            $stmt_old_items->execute(['id' => $id]);
            $old_items = $stmt_old_items->fetchAll();
            foreach ($old_items as $old_item) {
                if ($old_item['variant_id']) {
                    $sell_type = $old_item['sell_type'];
                    $change_type = ($sell_type === 'loose') ? 'refund_loose' : 'refund_whole';
                    adjust_variant_stock($db, $old_item['variant_id'], $old_item['quantity'], $sell_type, $change_type, $id, "Invoice edit - restore old items #{$invoice_number}");
                }
            }

            // Clear old items
            $stmt_del = $db->prepare("DELETE FROM billing_order_items WHERE order_id = :id");
            $stmt_del->execute(['id' => $id]);

            // Re-insert updated items
            $stmt_ins = $db->prepare("
                INSERT INTO billing_order_items (order_id, product_id, variant_id, product_name, variant_size, price, quantity, total_price, sell_type)
                VALUES (:order_id, :prod_id, :var_id, :prod_name, :size, :price, :qty, :total_price, :sell_type)
            ");

            foreach ($items_posted as $item) {
                $prod_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                $var_id = (isset($item['variant_id']) && $item['variant_id'] > 0) ? (int) $item['variant_id'] : null;
                $name = $item['name'];
                $size = isset($item['size']) ? $item['size'] : null;
                $price = (float) $item['price'];
                $qty = (int) $item['quantity'];
                $total_price = $price * $qty;
                $sell_type = isset($item['sell_type']) ? $item['sell_type'] : 'whole';

                // Handle custom / manual items without valid product id
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

                $stmt_ins->execute([
                    'order_id' => $id,
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
                    adjust_variant_stock($db, $var_id, $qty, $sell_type, $change_type, $id, "Invoice edit - save new items #{$invoice_number}");
                }
            }

            $db->commit();
            header("Location: billing-invoice.php?id=" . $id . "&edit_success=1");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch all catalog products and variants to allow adding new items
$all_products = $db->query("
    SELECT p.id as product_id, p.product_name, p.base_price, c.category_name, p.category_id
      FROM billing_products p
      JOIN billing_categories c ON p.category_id = c.id
     WHERE p.is_active = 1
     ORDER BY p.product_name ASC
")->fetchAll();

$all_variants = $db->query("
    SELECT id as variant_id, product_id, size, price, barcode, stock_quantity, allow_loose, loose_price, loose_units_per_whole
      FROM billing_product_variants
     ORDER BY id ASC
")->fetchAll();

// Group variants by product
$variants_by_prod = [];
foreach ($all_variants as $v) {
    $variants_by_prod[$v['product_id']][] = $v;
}

// Format a complete list of selectable items
$selectable_items = [];
foreach ($all_products as $p) {
    $p_id = $p['product_id'];
    if (isset($variants_by_prod[$p_id])) {
        foreach ($variants_by_prod[$p_id] as $v) {
            $price = $v['price'] !== null ? (float) $v['price'] : (float) $p['base_price'];
            $stock = (float) $v['stock_quantity'];

            $selectable_items[] = [
                'product_id' => $p_id,
                'variant_id' => $v['variant_id'],
                'name' => $p['product_name'] . ' (' . $v['size'] . ')',
                'size' => $v['size'],
                'price' => $price,
                'category' => $p['category_name'],
                'barcode' => $v['barcode'],
                'stock' => $stock,
                'allow_loose' => (int) $v['allow_loose'],
                'loose_price' => $v['loose_price'] !== null ? (float) $v['loose_price'] : null,
                'loose_units_per_whole' => (float) $v['loose_units_per_whole']
            ];
        }
    } else {
        $selectable_items[] = [
            'product_id' => $p_id,
            'variant_id' => null,
            'name' => $p['product_name'],
            'size' => null,
            'price' => (float) $p['base_price'],
            'category' => $p['category_name'],
            'barcode' => null,
            'stock' => 0.00,
            'allow_loose' => 0,
            'loose_price' => null,
            'loose_units_per_whole' => 1.00
        ];
    }
}

// Pre-fill existing items for JavaScript JSON payload
$js_items = [];
foreach ($items as $item) {
    $js_items[] = [
        'product_id' => (int) $item['product_id'],
        'variant_id' => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
        'name' => $item['product_name'],
        'size' => $item['variant_size'],
        'price' => (float) $item['price'],
        'quantity' => (int) $item['quantity'],
        'allow_loose' => (int) ($item['allow_loose'] ?? 0),
        'loose_price' => $item['loose_price'] !== null ? (float) $item['loose_price'] : null,
        'loose_units_per_whole' => (float) ($item['loose_units_per_whole'] ?? 1.00),
        'whole_price' => (float) ($item['whole_price'] ?? $item['price']),
        'sell_type' => $item['sell_type'] ?: 'whole'
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Compact Header -->
<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--warning);"></i>
                Edit POS Invoice
            </h1>
            <p style="color: var(--text-secondary); margin: 0.15rem 0 0; font-size: 0.8rem;">
                Update line items, discount, and details for <?= h($order['invoice_number']) ?>
            </p>
        </div>
        <div>
            <a href="billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                <i class="fa-solid fa-arrow-left-long"></i> Back to Invoice
            </a>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div
        style="background-color: var(--danger); color: #ffffff; padding: 0.6rem 1.25rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 0.85rem;">
        <span><i class="fa-solid fa-circle-exclamation"></i> <?= h($error) ?></span>
        <button onclick="this.parentElement.style.display='none'"
            style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<style>
    .edit-container {
        display: grid;
        grid-template-columns: 1.4fr 1fr;
        gap: 1rem;
        align-items: start;
    }

    @media (max-width: 992px) {
        .edit-container {
            grid-template-columns: 1fr;
        }
    }

    .form-section-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 1rem;
        box-shadow: var(--box-shadow);
        margin-bottom: 1rem;
    }

    .qty-edit-btn {
        width: 22px;
        height: 22px;
        border-radius: 4px;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-weight: bold;
        font-size: 0.8rem;
        transition: var(--transition-fast);
    }

    .qty-edit-btn:hover {
        background: var(--accent-color);
        color: white;
        border-color: var(--accent-color);
    }

    .summary-sticky-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 1rem;
        box-shadow: var(--box-shadow);
        position: sticky;
        top: 1rem;
    }

    .billing-summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        font-size: 0.85rem;
        color: var(--text-secondary);
    }

    .billing-summary-total {
        border-top: 1px dashed var(--border-color);
        padding-top: 0.5rem;
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--accent-color);
        margin-top: 0.25rem;
    }

    .table th, .table td {
        padding: 0.5rem 0.6rem !important;
        font-size: 0.82rem;
    }

    /* Autocomplete Search suggestions styling */
    .suggestion-item {
        padding: 0.5rem 0.75rem;
        cursor: pointer;
        transition: background 0.15s ease;
        border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .suggestion-item:last-child {
        border-bottom: none;
    }

    .suggestion-item:hover,
    .suggestion-item.active {
        background: rgba(255, 107, 53, 0.12);
        color: var(--text-primary);
    }

    .suggestion-category {
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: rgba(255, 107, 53, 0.08);
        color: var(--accent-color);
        padding: 0.1rem 0.35rem;
        border-radius: 4px;
        margin-right: 0.5rem;
        font-weight: 600;
        display: inline-block;
    }

    .suggestion-price {
        font-weight: 700;
        color: var(--accent-color);
        font-size: 0.85rem;
    }

    .suggestion-match {
        color: #ffaa66;
        font-weight: 600;
        background: rgba(255, 170, 102, 0.08);
        border-radius: 2px;
        padding: 0 1px;
    }
</style>

<form id="editInvoiceForm" method="POST" style="margin: 0;">
    <input type="hidden" id="cartDataInput" name="cart_data">

    <!-- Invoice/Customer Summary Info Card at Top -->
    <div class="form-section-card"
        style="display: flex; justify-content: space-between; align-items: center; gap: 1.5rem; flex-wrap: wrap; padding: 0.75rem 1rem; margin-bottom: 1rem;">
        <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: center;">
            <!-- Invoice details summary -->
            <div>
                <div
                    style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.15rem;">
                    Invoice Information</div>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.15rem;">
                    <span style="font-weight: 700; color: var(--text-primary); font-size: 1rem;"
                        id="lblInvoiceNumber"><?= h($order['invoice_number']) ?></span>
                    <?php
                    $method = $order['payment_method'];
                    $bg_color = 'rgba(46, 213, 115, 0.12)';
                    $text_color = 'var(--success)';
                    if ($method === 'UPI') {
                        $bg_color = 'rgba(30, 144, 255, 0.12)';
                        $text_color = 'var(--info)';
                    } elseif ($method === 'Card') {
                        $bg_color = 'rgba(255, 107, 53, 0.12)';
                        $text_color = 'var(--accent-color)';
                    }
                    ?>
                    <span class="badge" id="lblPaymentMethod"
                        style="background: <?= $bg_color ?>; color: <?= $text_color ?>; font-weight: 600; font-size: 0.7rem; padding: 0.1rem 0.35rem;">
                        <?= h($method) ?>
                    </span>
                </div>
                <div style="font-size: 0.8rem; color: var(--text-secondary);" id="lblInvoiceDate">
                    <i class="fa-regular fa-calendar" style="margin-right: 0.25rem;"></i>
                    <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                </div>
            </div>

            <!-- Customer summary -->
            <div
                style="border-left: 1px solid var(--border-color); padding-left: 2rem; min-height: 40px; display: flex; flex-direction: column; justify-content: center;">
                <div
                    style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.15rem;">
                    Customer Details</div>
                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem; font-size: 0.9rem;" id="lblCustomerName">
                    <?= h($order['customer_name'] ?: 'Walk-in Client') ?>
                </div>
                <div
                    style="font-size: 0.8rem; color: var(--text-secondary); display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <span id="lblCustomerPhone"><i class="fa-solid fa-phone"
                            style="font-size: 0.7rem; margin-right: 0.25rem;"></i>
                        <?= h($order['customer_phone'] ?: 'N/A') ?></span>
                    <span id="lblCustomerAddress"><i class="fa-solid fa-location-dot"
                            style="font-size: 0.7rem; margin-right: 0.25rem;"></i>
                        <?= h($order['customer_address'] ? (mb_strimwidth($order['customer_address'], 0, 40, '...')) : 'N/A') ?></span>
                </div>
            </div>
        </div>

        <div>
            <button type="button" onclick="openInvoiceDetailsModal()" class="btn btn-secondary"
                style="display: inline-flex; align-items: center; gap: 0.35rem; background: rgba(255, 255, 255, 0.05); border-color: var(--border-color); font-weight: 600; font-size: 0.8rem; height: 32px; padding: 0.35rem 0.75rem;">
                <i class="fa-solid fa-user-gear" style="color: var(--accent-color);"></i> Edit Customer & Info
            </button>
        </div>
    </div>

    <div class="edit-container">
        <!-- Left Side: Order details & Products selection/table -->
        <div>
            <!-- Items & Products Selection Card -->
            <div class="form-section-card">
                <h3
                    style="font-size:0.95rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; margin-bottom:0.75rem; font-weight: 700;">
                    Invoice Line Items
                </h3>

                <!-- Product Add Selector Bar -->
                <div
                    style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1rem; background:rgba(0,0,0,0.12); padding:0.5rem 0.75rem; border-radius:var(--border-radius-md); border:1px solid var(--border-color); position: relative;">
                    <div style="flex-grow: 1; position: relative;">
                        <label class="form-label" style="font-size:0.7rem; margin-bottom:0.15rem; font-weight: 600;">Select Product / Variant</label>
                        <div style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass"
                                style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.8rem;"></i>
                            <input type="text" id="productSearchInput" class="form-control"
                                placeholder="Search by name, size or category..." autocomplete="off"
                                style="padding-left: 2rem; height: 32px; font-size: 0.8rem;" onfocus="showSuggestions()"
                                oninput="filterSearchProducts()">
                        </div>
                        <input type="hidden" id="productSelector" value="">

                        <!-- Suggestions Container -->
                        <div id="productSuggestions"
                            style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-card); border: 1px solid var(--border-highlight); border-radius: var(--border-radius-md); max-height: 250px; overflow-y: auto; z-index: 1000; box-shadow: var(--box-shadow); margin-top: 0.35rem; padding: 0.25rem 0;">
                            <!-- Dynamically loaded -->
                        </div>
                    </div>
                    <div style="display:flex; gap:0.35rem; margin-top:0.95rem; align-items: center;">
                        <button type="button" onclick="addProductToInvoice()" class="btn btn-primary"
                            style="white-space: nowrap; height: 32px; display: flex; align-items: center; gap: 0.25rem; font-size: 0.8rem; padding: 0.25rem 0.65rem;">
                            <i class="fa-solid fa-plus"></i> Add
                        </button>
                        <button type="button" onclick="openCustomItemModal()" class="btn btn-secondary"
                            style="white-space: nowrap; height: 32px; display: flex; align-items: center; gap: 0.25rem; font-size: 0.8rem; padding: 0.25rem 0.65rem;">
                            <i class="fa-solid fa-plus-circle"></i> Custom Item
                        </button>
                    </div>
                </div>

                <!-- Live Items Table -->
                <div class="table-responsive">
                    <table class="table" style="min-width:550px;">
                        <thead>
                            <tr>
                                <th style="width: 45%;">Product Description</th>
                                <th style="width: 20%; text-align: right;">Unit Price (₹)</th>
                                <th style="width: 15%; text-align: center;">Qty</th>
                                <th style="width: 20%; text-align: right;">Total Price (₹)</th>
                                <th style="width: 5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="invoiceItemsTableBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Side: Order Summary Card (Sticky) -->
        <div>
            <div class="summary-sticky-card">
                <h3
                    style="font-size:0.95rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; margin-bottom:0.75rem; font-weight: 700;">
                    Billing Summary
                </h3>

                <div class="billing-summary-row">
                    <span>Total Line Items</span>
                    <span id="summaryTotalItemsCount" style="font-weight: 700; color:var(--text-primary);">0</span>
                </div>

                <div class="billing-summary-row">
                    <span>Subtotal</span>
                    <span id="summarySubtotal" style="font-weight: 600; color:var(--text-primary);">₹0.00</span>
                </div>

                <div class="form-group" style="margin: 0.75rem 0;">
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.25rem; font-weight: 600;">Apply Discount (Flat Rs)</label>
                    <input type="number" min="0" step="0.01" id="discountInput" name="discount_amount"
                        class="form-control" value="<?= (float) $order['discount_amount'] ?>" placeholder="0.00"
                        oninput="recalcTotals()" style="height: 32px; font-size: 0.8rem;">
                </div>

                <div class="billing-summary-row billing-summary-total">
                    <span>Final Payable</span>
                    <span id="summaryPayable">₹0.00</span>
                </div>

                <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                    <button type="button" onclick="submitEditForm()" class="btn btn-success"
                        style="width:100%; height:36px; font-size:0.85rem; font-weight:600; box-shadow: 0 4px 12px rgba(46, 213, 115, 0.2); display: flex; align-items: center; justify-content: center; gap: 0.35rem;">
                        <i class="fa-solid fa-file-shield"></i> Save & Commit Changes
                    </button>
                    <a href="billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary"
                        style="text-align:center; height:32px; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:0.8rem; padding: 0.25rem 0.5rem;">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>


<!-- Modal: Add Custom / Extra Billing Item -->
<div id="customItemModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('customItemModal')">&times;</button>
        <h3 style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-plus-circle" style="color:var(--accent-color);"></i> Add Custom Line Item
        </h3>
        <div class="form-group">
            <label class="form-label">Custom Item Name</label>
            <input type="text" id="customItemName" class="form-control" placeholder="e.g. Extra Decoration/Flowers"
                required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Price (₹)</label>
                <input type="number" min="0" step="0.01" id="customItemPrice" class="form-control" placeholder="500.00"
                    required>
            </div>
            <div class="form-group">
                <label class="form-label">Quantity</label>
                <input type="number" min="1" value="1" id="customItemQty" class="form-control" required>
            </div>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
            <button type="button" onclick="closeModal('customItemModal')" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="addCustomItemToArray()" class="btn btn-primary">Add to Invoice</button>
        </div>
    </div>
</div>

<!-- Modal: Edit Customer & Invoice Details (Popup) -->
<div id="invoiceDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <button class="modal-close" type="button" onclick="closeInvoiceDetailsModal()">&times;</button>
        <h3
            style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-user-gear" style="color: var(--accent-color);"></i> Edit Customer & Invoice Info
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Invoice Number <span style="color:var(--danger);">*</span></label>
                <input type="text" id="inputInvoiceNumber" name="invoice_number" class="form-control"
                    value="<?= h($order['invoice_number']) ?>" required placeholder="OE-B-YYYYMMDD-XXXX">
            </div>
            <div class="form-group">
                <label class="form-label">Invoice Date & Time <span style="color:var(--danger);">*</span></label>
                <input type="datetime-local" id="inputInvoiceDate" name="created_at" class="form-control"
                    value="<?= date('Y-m-d\TH:i', strtotime($order['created_at'])) ?>" required>
            </div>
        </div>

        <div class="form-row" style="margin-top:1.25rem;">
            <div class="form-group">
                <label class="form-label">Customer Name</label>
                <input type="text" id="inputCustomerName" name="customer_name" class="form-control"
                    value="<?= h($order['customer_name'] ?? '') ?>" placeholder="Walk-in Client">
            </div>
            <div class="form-group">
                <label class="form-label">Customer Phone</label>
                <input type="text" id="inputCustomerPhone" name="customer_phone" class="form-control"
                    value="<?= h($order['customer_phone'] ?? '') ?>" placeholder="e.g. 9946731720">
            </div>
        </div>

        <div class="form-group" style="margin-top:1.25rem;">
            <label class="form-label">Customer Address</label>
            <textarea id="inputCustomerAddress" name="customer_address" class="form-control" rows="3"
                placeholder="Address Details..."
                style="resize:vertical;"><?= h($order['customer_address'] ?? '') ?></textarea>
        </div>

        <div class="form-group" style="margin-top:1.25rem; margin-bottom: 1rem;">
            <label class="form-label">Payment Method</label>
            <div style="display:flex; gap:0.75rem;">
                <label
                    style="flex:1; display:flex; align-items:center; gap:0.5rem; padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; background:var(--bg-control);">
                    <input type="radio" name="payment_method" value="Cash" <?= $order['payment_method'] === 'Cash' ? 'checked' : '' ?>>
                    <span style="font-weight:600; color:var(--text-primary);"><i class="fa-solid fa-money-bill-1-wave"
                            style="color:var(--success); margin-right:0.25rem;"></i> Cash</span>
                </label>
                <label
                    style="flex:1; display:flex; align-items:center; gap:0.5rem; padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; background:var(--bg-control);">
                    <input type="radio" name="payment_method" value="UPI" <?= $order['payment_method'] === 'UPI' ? 'checked' : '' ?>>
                    <span style="font-weight:600; color:var(--text-primary);"><i class="fa-solid fa-qrcode"
                            style="color:var(--info); margin-right:0.25rem;"></i> UPI QR</span>
                </label>
                <label
                    style="flex:1; display:flex; align-items:center; gap:0.5rem; padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; background:var(--bg-control);">
                    <input type="radio" name="payment_method" value="Card" <?= $order['payment_method'] === 'Card' ? 'checked' : '' ?>>
                    <span style="font-weight:600; color:var(--text-primary);"><i class="fa-solid fa-credit-card"
                            style="color:var(--accent-color); margin-right:0.25rem;"></i> Card</span>
                </label>
            </div>
        </div>

        <div
            style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
            <button type="button" onclick="applyInvoiceDetails()" class="btn btn-primary"
                style="padding: 0.6rem 1.5rem; font-weight: 600;">
                <i class="fa-solid fa-circle-check"></i> Apply Info
            </button>
        </div>
    </div>
</div>
</form>

<script>
    // State variable containing invoice items
    let invoiceItems = <?= json_encode($js_items) ?>;
    const selectableItems = <?= json_encode($selectable_items) ?>;
    let activeSearchIndex = -1;
    let filteredSearchItems = [];

    document.addEventListener('DOMContentLoaded', () => {
        renderItems();

        const searchInput = document.getElementById('productSearchInput');
        const suggestionsBox = document.getElementById('productSuggestions');

        // Keyboard navigation on suggestions
        searchInput.addEventListener('keydown', (e) => {
            const items = suggestionsBox.querySelectorAll('.suggestion-item');

            if (suggestionsBox.style.display === 'none') {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    showSuggestions();
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeSearchIndex = (activeSearchIndex + 1) % items.length;
                updateActiveSuggestion(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeSearchIndex = (activeSearchIndex - 1 + items.length) % items.length;
                updateActiveSuggestion(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeSearchIndex >= 0 && activeSearchIndex < items.length) {
                    items[activeSearchIndex].click();
                } else if (items.length > 0) {
                    items[0].click();
                }
            } else if (e.key === 'Escape') {
                hideSuggestions();
            }
        });

        // Click outside to close suggestion dropdown
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#productSearchInput') && !e.target.closest('#productSuggestions')) {
                hideSuggestions();
            }
        });
    });

    function showSuggestions() {
        filterSearchProducts();
    }

    function hideSuggestions() {
        document.getElementById('productSuggestions').style.display = 'none';
        activeSearchIndex = -1;
    }

    function filterSearchProducts() {
        const searchInput = document.getElementById('productSearchInput');
        const query = searchInput.value.toLowerCase().trim();
        const suggestionsBox = document.getElementById('productSuggestions');

        // Check for exact barcode scan/match to auto-add immediately
        if (query.length >= 8) {
            const exactMatchIndex = selectableItems.findIndex(item => item.barcode && item.barcode.toLowerCase() === query);
            if (exactMatchIndex !== -1) {
                selectSuggestion(exactMatchIndex, selectableItems[exactMatchIndex].name);
                hideSuggestions();
                return;
            }
        }

        filteredSearchItems = [];
        selectableItems.forEach((item, index) => {
            const matchesName = item.name.toLowerCase().includes(query);
            const matchesCategory = item.category.toLowerCase().includes(query);
            const matchesBarcode = item.barcode ? item.barcode.toLowerCase().includes(query) : false;
            if (matchesName || matchesCategory || matchesBarcode) {
                filteredSearchItems.push({
                    index: index,
                    item: item
                });
            }
        });

        if (filteredSearchItems.length === 0) {
            suggestionsBox.innerHTML = `
            <div style="padding: 0.75rem 1rem; color: var(--text-muted); font-size: 0.85rem; text-align: center;">
                No matching product variants found
            </div>
        `;
            suggestionsBox.style.display = 'block';
            return;
        }

        let html = '';
        filteredSearchItems.forEach((entry, i) => {
            const item = entry.item;
            const displayName = highlightMatch(item.name, query);
            const displayCategory = highlightMatch(item.category, query);
            const displayBarcode = item.barcode ? `<span style="font-size:0.75rem; color:var(--text-muted); margin-left:0.5rem;">[${highlightMatch(item.barcode, query)}]</span>` : '';
            const activeClass = i === activeSearchIndex ? 'active' : '';

            let stockHtml = '';
            if (item.stock !== undefined && item.variant_id !== null) {
                const st = parseFloat(item.stock);
                if (st <= 0) {
                    stockHtml = `<span style="font-size:0.75rem; color:var(--danger); font-weight:normal; margin-left:0.5rem;">(Out of Stock)</span>`;
                } else {
                    stockHtml = `<span style="font-size:0.75rem; color:var(--success); font-weight:normal; margin-left:0.5rem;">(${st.toFixed(2)} available)</span>`;
                }
            }

            html += `
            <div class="suggestion-item ${activeClass}" data-index="${entry.index}" onclick="selectSuggestion(${entry.index}, '${escapeJsString(item.name)}')">
                <div style="display: flex; align-items: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;">
                    <span class="suggestion-category">${displayCategory}</span>
                    <span style="font-weight: 500; font-size: 0.85rem;">${displayName}</span>
                    ${displayBarcode}
                    ${stockHtml}
                </div>
                <div class="suggestion-price">₹${item.price.toFixed(2)}</div>
            </div>
        `;
        });

        suggestionsBox.innerHTML = html;
        suggestionsBox.style.display = 'block';
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const escapedQuery = query.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
        const regex = new RegExp(`(${escapedQuery})`, 'gi');
        return escapeHtml(text).replace(regex, '<span class="suggestion-match">$1</span>');
    }

    function updateActiveSuggestion(items) {
        items.forEach((item, i) => {
            if (i === activeSearchIndex) {
                item.classList.add('active');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active');
            }
        });
    }

    function selectSuggestion(index, name) {
        document.getElementById('productSelector').value = index;
        // Call main handler to add product to array
        addProductToInvoice();
        // Clear inputs and hide suggestions
        document.getElementById('productSearchInput').value = '';
        document.getElementById('productSelector').value = '';
        hideSuggestions();
    }

    function escapeJsString(str) {
        return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    // Render the items inside the table dynamically
    function renderItems() {
        const tbody = document.getElementById('invoiceItemsTableBody');
        if (!tbody) return;

        if (invoiceItems.length === 0) {
            tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                    <i class="fa-solid fa-basket-shopping" style="font-size:2rem; opacity:0.3; margin-bottom:0.75rem; display:block;"></i>
                    No items in this invoice. Please select products to add above.
                </td>
            </tr>
        `;
            document.getElementById('summaryTotalItemsCount').textContent = '0';
            document.getElementById('summarySubtotal').textContent = '₹0.00';
            document.getElementById('summaryPayable').textContent = '₹0.00';
            return;
        }

        let subtotal = 0;
        let totalItemsQty = 0;
        let html = '';

        invoiceItems.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            subtotal += itemTotal;
            totalItemsQty += item.quantity;

            let sellTypeSelectorHtml = '';
            if (parseInt(item.allow_loose) === 1) {
                const loosePriceVal = parseFloat(item.loose_price || 0);
                const wholePriceVal = parseFloat(item.whole_price || 0);
                sellTypeSelectorHtml = `
                    <div style="margin-top: 0.35rem; display: flex; gap: 0.5rem; align-items: center;">
                        <span style="font-size:0.68rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Sell:</span>
                        <label style="font-size:0.72rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.2rem; margin:0;">
                            <input type="radio" name="sell_type_edit_${index}" value="whole" ${item.sell_type === 'whole' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'whole')" style="accent-color:var(--accent-color); cursor:pointer;">
                            Whole (₹${wholePriceVal.toFixed(2)})
                        </label>
                        <label style="font-size:0.72rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.2rem; margin:0;">
                            <input type="radio" name="sell_type_edit_${index}" value="loose" ${item.sell_type === 'loose' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'loose')" style="accent-color:var(--accent-color); cursor:pointer;">
                            Loose (₹${loosePriceVal.toFixed(2)})
                        </label>
                    </div>
                `;
            }

            html += `
            <tr style="vertical-align: middle;">
                <td>
                    <div style="font-weight: 600; color: var(--text-primary);">${escapeHtml(item.name)}</div>
                    ${item.size ? `<span style="font-size: 0.75rem; color: var(--text-muted);">Size: ${escapeHtml(item.size)}</span>` : ''}
                    ${sellTypeSelectorHtml}
                </td>
                <td style="text-align: right;">
                    <input type="number" min="0" step="0.01" class="form-control" style="width: 100px; text-align: right; display: inline-block; padding: 0.25rem 0.5rem;" 
                           value="${item.price.toFixed(2)}" oninput="updateItemPrice(${index}, this.value)">
                </td>
                <td style="text-align: center; white-space: nowrap;">
                    <div style="display: inline-flex; align-items: center; border: 1px solid var(--border-color); border-radius: 4px; overflow: hidden; background: var(--bg-control);">
                        <button type="button" class="qty-edit-btn" onclick="updateItemQty(${index}, -1)">-</button>
                        <span style="width: 32px; text-align: center; font-weight: 600; font-size: 0.85rem;">${item.quantity}</span>
                        <button type="button" class="qty-edit-btn" onclick="updateItemQty(${index}, 1)">+</button>
                    </div>
                </td>
                <td style="text-align: right; font-weight: 700; color: var(--text-primary);">
                    ₹${itemTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                </td>
                <td style="text-align: center;">
                    <button type="button" onclick="removeItem(${index})" style="background: none; border: none; color: var(--danger); cursor: pointer; padding: 4px;" title="Remove Item">
                        <i class="fa-solid fa-trash-can" style="font-size: 0.95rem;"></i>
                    </button>
                </td>
            </tr>
        `;
        });

        tbody.innerHTML = html;
        document.getElementById('summaryTotalItemsCount').textContent = totalItemsQty;

        // Update summary values
        const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
        const finalPayable = Math.max(0, subtotal - discountVal);

        document.getElementById('summarySubtotal').textContent = '₹' + subtotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('summaryPayable').textContent = '₹' + finalPayable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function changeItemSellType(index, type) {
        if (!invoiceItems[index]) return;
        invoiceItems[index].sell_type = type;
        if (type === 'loose') {
            invoiceItems[index].price = parseFloat(invoiceItems[index].loose_price);
        } else {
            invoiceItems[index].price = parseFloat(invoiceItems[index].whole_price);
        }
        renderItems();
    }

    // Modify item price reactively
    function updateItemPrice(index, value) {
        const val = parseFloat(value);
        if (!isNaN(val) && val >= 0) {
            invoiceItems[index].price = val;
            recalcTotals();
        }
    }

    // Modify item qty reactively
    function updateItemQty(index, delta) {
        invoiceItems[index].quantity += delta;
        if (invoiceItems[index].quantity <= 0) {
            invoiceItems.splice(index, 1);
        }
        renderItems();
    }

    // Remove item from array
    function removeItem(index) {
        invoiceItems.splice(index, 1);
        renderItems();
    }

    // Recalculate totals without full table re-render (smooth performance)
    function recalcTotals() {
        let subtotal = 0;
        invoiceItems.forEach(item => {
            subtotal += item.price * item.quantity;
        });

        const discountVal = parseFloat(document.getElementById('discountInput').value) || 0;
        const finalPayable = Math.max(0, subtotal - discountVal);

        // Refresh the row total cells in the DOM
        const tbody = document.getElementById('invoiceItemsTableBody');
        if (tbody) {
            const rows = tbody.querySelectorAll('tr');
            if (rows.length === invoiceItems.length) {
                invoiceItems.forEach((item, index) => {
                    const totalCell = rows[index].querySelector('td:nth-child(4)');
                    if (totalCell) {
                        const itemTotal = item.price * item.quantity;
                        totalCell.textContent = '₹' + itemTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }
                });
            }
        }

        document.getElementById('summarySubtotal').textContent = '₹' + subtotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('summaryPayable').textContent = '₹' + finalPayable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Add catalog product to current invoice
    function addProductToInvoice() {
        const selector = document.getElementById('productSelector');
        const selectedIdx = selector.value;
        if (selectedIdx === "") return;

        const itemTemplate = selectableItems[selectedIdx];

        // Check if product variant already exists in invoice items
        const existingIndex = invoiceItems.findIndex(item =>
            item.product_id === parseInt(itemTemplate.product_id) &&
            item.variant_id === itemTemplate.variant_id
        );

        if (existingIndex !== -1) {
            invoiceItems[existingIndex].quantity += 1;
        } else {
            invoiceItems.push({
                product_id: parseInt(itemTemplate.product_id),
                variant_id: itemTemplate.variant_id,
                name: itemTemplate.name,
                size: itemTemplate.size,
                price: parseFloat(itemTemplate.price),
                quantity: 1,
                allow_loose: parseInt(itemTemplate.allow_loose || 0),
                loose_price: itemTemplate.loose_price !== null ? parseFloat(itemTemplate.loose_price) : null,
                loose_units_per_whole: parseFloat(itemTemplate.loose_units_per_whole || 1.00),
                whole_price: parseFloat(itemTemplate.price),
                sell_type: 'whole'
            });
        }

        // Reset selector
        selector.value = "";
        renderItems();
    }

    // Custom line items modal triggers
    function openCustomItemModal() {
        openModal('customItemModal');
    }

    function addCustomItemToArray() {
        const nameInput = document.getElementById('customItemName');
        const priceInput = document.getElementById('customItemPrice');
        const qtyInput = document.getElementById('customItemQty');

        const name = nameInput.value.trim();
        const price = parseFloat(priceInput.value);
        const qty = parseInt(qtyInput.value);

        if (name === '' || isNaN(price) || price < 0 || isNaN(qty) || qty < 1) {
            alert('Please enter valid custom item details.');
            return;
        }

        invoiceItems.push({
            product_id: 0,
            variant_id: null,
            name: name,
            size: 'Custom',
            price: price,
            quantity: qty
        });

        nameInput.value = '';
        priceInput.value = '';
        qtyInput.value = '1';

        closeModal('customItemModal');
        renderItems();
    }

    // Submit validation & execution
    function submitEditForm() {
        if (invoiceItems.length === 0) {
            alert('Cannot save an invoice with no items. Add at least one product.');
            return;
        }

        // Assign serialized items data to hidden input
        document.getElementById('cartDataInput').value = JSON.stringify(invoiceItems);

        // Submit form
        document.getElementById('editInvoiceForm').submit();
    }

    // Customer/Invoice details popup handlers
    function openInvoiceDetailsModal() {
        openModal('invoiceDetailsModal');
    }

    function closeInvoiceDetailsModal() {
        closeModal('invoiceDetailsModal');
    }

    function applyInvoiceDetails() {
        const invNo = document.getElementById('inputInvoiceNumber').value.trim();
        const invDateRaw = document.getElementById('inputInvoiceDate').value;
        const custName = document.getElementById('inputCustomerName').value.trim();
        const custPhone = document.getElementById('inputCustomerPhone').value.trim();
        const custAddr = document.getElementById('inputCustomerAddress').value.trim();

        let paymentMethod = 'Cash';
        const radios = document.getElementsByName('payment_method');
        for (let r of radios) {
            if (r.checked) {
                paymentMethod = r.value;
                break;
            }
        }

        if (invNo === "") {
            alert("Invoice number cannot be empty.");
            return;
        }
        if (invDateRaw === "") {
            alert("Invoice date & time is required.");
            return;
        }

        // Update summary card values
        document.getElementById('lblInvoiceNumber').textContent = invNo;

        const dateObj = new Date(invDateRaw);
        if (!isNaN(dateObj)) {
            const options = { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
            const formattedDate = dateObj.toLocaleString('en-US', options).replace(/,/g, '');
            document.getElementById('lblInvoiceDate').innerHTML = `<i class="fa-regular fa-calendar" style="margin-right: 0.25rem;"></i> ` + formattedDate;
        }

        const badge = document.getElementById('lblPaymentMethod');
        badge.textContent = paymentMethod;
        if (paymentMethod === 'Cash') {
            badge.style.background = 'rgba(46, 213, 115, 0.12)';
            badge.style.color = 'var(--success)';
        } else if (paymentMethod === 'UPI') {
            badge.style.background = 'rgba(30, 144, 255, 0.12)';
            badge.style.color = 'var(--info)';
        } else {
            badge.style.background = 'rgba(255, 107, 53, 0.12)';
            badge.style.color = 'var(--accent-color)';
        }

        document.getElementById('lblCustomerName').textContent = custName || 'Walk-in Client';
        document.getElementById('lblCustomerPhone').innerHTML = `<i class="fa-solid fa-phone" style="font-size: 0.75rem; margin-right: 0.25rem;"></i> ` + (custPhone || 'N/A');

        const truncatedAddr = custAddr ? (custAddr.length > 40 ? custAddr.substring(0, 40) + '...' : custAddr) : 'N/A';
        document.getElementById('lblCustomerAddress').innerHTML = `<i class="fa-solid fa-location-dot" style="font-size: 0.75rem; margin-right: 0.25rem;"></i> ` + truncatedAddr;

        closeInvoiceDetailsModal();
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>