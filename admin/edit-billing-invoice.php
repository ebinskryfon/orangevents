<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();

$db = get_db_connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
$stmt_items = $db->prepare("SELECT * FROM billing_order_items WHERE order_id = :id ORDER BY id ASC");
$stmt_items->execute(['id' => $id]);
$items = $stmt_items->fetchAll();

// POST Handlers (Update logic)
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number   = trim($_POST['invoice_number'] ?? '');
    $customer_name    = trim($_POST['customer_name'] ?? '');
    $customer_phone   = trim($_POST['customer_phone'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $payment_method   = trim($_POST['payment_method'] ?? 'Cash');
    $created_at       = trim($_POST['created_at'] ?? '');
    $discount_amount  = (float)($_POST['discount_amount'] ?? 0);
    $cart_data_raw    = $_POST['cart_data'] ?? '[]';

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
                $total_amount += (float)$item['price'] * (int)$item['quantity'];
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

            // Clear old items
            $stmt_del = $db->prepare("DELETE FROM billing_order_items WHERE order_id = :id");
            $stmt_del->execute(['id' => $id]);

            // Re-insert updated items
            $stmt_ins = $db->prepare("
                INSERT INTO billing_order_items (order_id, product_id, variant_id, product_name, variant_size, price, quantity, total_price)
                VALUES (:order_id, :prod_id, :var_id, :prod_name, :size, :price, :qty, :total_price)
            ");

            foreach ($items_posted as $item) {
                $prod_id = isset($item['product_id']) ? (int)$item['product_id'] : 0;
                $var_id = (isset($item['variant_id']) && $item['variant_id'] > 0) ? (int)$item['variant_id'] : null;
                $name = $item['name'];
                $size = isset($item['size']) ? $item['size'] : null;
                $price = (float)$item['price'];
                $qty = (int)$item['quantity'];
                $total_price = $price * $qty;

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
                    'total_price' => $total_price
                ]);
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
    SELECT id as variant_id, product_id, size, price
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
            $price = $v['price'] !== null ? (float)$v['price'] : (float)$p['base_price'];
            $selectable_items[] = [
                'product_id' => $p_id,
                'variant_id' => $v['variant_id'],
                'name' => $p['product_name'] . ' (' . $v['size'] . ')',
                'size' => $v['size'],
                'price' => $price,
                'category' => $p['category_name']
            ];
        }
    } else {
        $selectable_items[] = [
            'product_id' => $p_id,
            'variant_id' => null,
            'name' => $p['product_name'],
            'size' => null,
            'price' => (float)$p['base_price'],
            'category' => $p['category_name']
        ];
    }
}

// Pre-fill existing items for JavaScript JSON payload
$js_items = [];
foreach ($items as $item) {
    $js_items[] = [
        'product_id' => (int)$item['product_id'],
        'variant_id' => $item['variant_id'] !== null ? (int)$item['variant_id'] : null,
        'name' => $item['product_name'],
        'size' => $item['variant_size'],
        'price' => (float)$item['price'],
        'quantity' => (int)$item['quantity']
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <div class="header-title">
        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
            <a href="billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.85rem;">
                <i class="fa-solid fa-arrow-left-long"></i> Back to Invoice
            </a>
        </div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; margin-top:0.25rem;">
            <i class="fa-solid fa-pen-to-square" style="color:var(--warning);"></i>
            Edit POS Invoice
        </h1>
        <p style="color:var(--text-muted); margin-top:0.25rem;">
            Modify details, update line items, change dates, and recalculate financials for <?= h($order['invoice_number']) ?>.
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div style="background-color: var(--danger); color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid fa-circle-exclamation"></i> <?= h($error) ?></span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<style>
    .edit-container {
        display: grid;
        grid-template-columns: 1.4fr 1fr;
        gap: 1.5rem;
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
        border-radius: var(--border-radius-lg);
        padding: 1.5rem;
        box-shadow: var(--box-shadow);
        margin-bottom: 1.5rem;
    }
    .qty-edit-btn {
        width: 24px;
        height: 24px;
        border-radius: 4px;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-weight: bold;
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
        border-radius: var(--border-radius-lg);
        padding: 1.5rem;
        box-shadow: var(--box-shadow);
        position: sticky;
        top: 2rem;
    }
    .billing-summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
        color: var(--text-secondary);
    }
    .billing-summary-total {
        border-top: 1px dashed var(--border-color);
        padding-top: 0.75rem;
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--accent-color);
        margin-top: 0.25rem;
    }
</style>

<form id="editInvoiceForm" method="POST" style="margin: 0;">
    <input type="hidden" id="cartDataInput" name="cart_data">

    <div class="edit-container">
        <!-- Left Side: Order details & Products selection/table -->
        <div>
            <!-- Order & Customer Info Card -->
            <div class="form-section-card">
                <h3 style="font-size:1.15rem; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fa-solid fa-circle-info" style="color:var(--accent-color);"></i> Invoice Information
                </h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Invoice Number <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="invoice_number" class="form-control" value="<?= h($order['invoice_number']) ?>" required placeholder="OE-B-YYYYMMDD-XXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice Date & Time <span style="color:var(--danger);">*</span></label>
                        <input type="datetime-local" name="created_at" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($order['created_at'])) ?>" required>
                    </div>
                </div>

                <div class="form-row" style="margin-top:1.25rem;">
                    <div class="form-group">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-control" value="<?= h($order['customer_name'] ?? '') ?>" placeholder="Walk-in Client">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Customer Phone</label>
                        <input type="text" name="customer_phone" class="form-control" value="<?= h($order['customer_phone'] ?? '') ?>" placeholder="e.g. 9946731720">
                    </div>
                </div>

                <div class="form-group" style="margin-top:1.25rem;">
                    <label class="form-label">Customer Address</label>
                    <textarea name="customer_address" class="form-control" rows="2" placeholder="Address Details..." style="resize:vertical;"><?= h($order['customer_address'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="margin-top:1.25rem;">
                    <label class="form-label">Payment Method</label>
                    <div style="display:flex; gap:0.75rem;">
                        <label style="flex:1; display:flex; align-items:center; gap:0.5rem; padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; background:var(--bg-control);">
                            <input type="radio" name="payment_method" value="Cash" <?= $order['payment_method'] === 'Cash' ? 'checked' : '' ?>>
                            <span style="font-weight:600; color:var(--text-primary);"><i class="fa-solid fa-money-bill-1-wave" style="color:var(--success); margin-right:0.25rem;"></i> Cash</span>
                        </label>
                        <label style="flex:1; display:flex; align-items:center; gap:0.5rem; padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; background:var(--bg-control);">
                            <input type="radio" name="payment_method" value="UPI" <?= $order['payment_method'] === 'UPI' ? 'checked' : '' ?>>
                            <span style="font-weight:600; color:var(--text-primary);"><i class="fa-solid fa-qrcode" style="color:var(--info); margin-right:0.25rem;"></i> UPI QR</span>
                        </label>
                        <label style="flex:1; display:flex; align-items:center; gap:0.5rem; padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; background:var(--bg-control);">
                            <input type="radio" name="payment_method" value="Card" <?= $order['payment_method'] === 'Card' ? 'checked' : '' ?>>
                            <span style="font-weight:600; color:var(--text-primary);"><i class="fa-solid fa-credit-card" style="color:var(--accent-color); margin-right:0.25rem;"></i> Card</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Items & Products Selection Card -->
            <div class="form-section-card">
                <h3 style="font-size:1.15rem; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:1.25rem;">
                    Invoice Line Items
                </h3>

                <!-- Product Add Selector Bar -->
                <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem; background:rgba(0,0,0,0.12); padding:1rem; border-radius:var(--border-radius-md); border:1px solid var(--border-color);">
                    <div style="flex-grow: 1;">
                        <label class="form-label" style="font-size:0.75rem; margin-bottom:0.25rem;">Select Product / Variant</label>
                        <select id="productSelector" class="form-control" style="cursor: pointer;">
                            <option value="">-- Choose product variant to add --</option>
                            <?php foreach ($selectable_items as $idx => $item): ?>
                                <option value="<?= $idx ?>"><?= h($item['category']) ?> » <?= h($item['name']) ?> (₹<?= number_format($item['price'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:0.5rem; margin-top:1.1rem;">
                        <button type="button" onclick="addProductToInvoice()" class="btn btn-primary" style="white-space: nowrap;">
                            <i class="fa-solid fa-plus"></i> Add Selected
                        </button>
                        <button type="button" onclick="openCustomItemModal()" class="btn btn-secondary" style="white-space: nowrap;">
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
                <h3 style="font-size:1.15rem; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:1.25rem;">
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

                <div class="form-group" style="margin: 1rem 0;">
                    <label class="form-label" style="font-size:0.85rem;">Apply Discount (Flat Rs)</label>
                    <input type="number" min="0" step="0.01" id="discountInput" name="discount_amount" class="form-control" value="<?= (float)$order['discount_amount'] ?>" placeholder="0.00" oninput="recalcTotals()">
                </div>

                <div class="billing-summary-row billing-summary-total">
                    <span>Final Payable</span>
                    <span id="summaryPayable">₹0.00</span>
                </div>

                <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem;">
                    <button type="button" onclick="submitEditForm()" class="btn btn-success" style="width:100%; height:46px; font-size:0.95rem; font-weight:600; box-shadow: 0 4px 12px rgba(46, 213, 115, 0.2);">
                        <i class="fa-solid fa-file-shield"></i> Save & Commit Changes
                    </button>
                    <a href="billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary" style="text-align:center; height:40px; display:flex; align-items:center; justify-content:center; font-weight:600;">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Modal: Add Custom / Extra Billing Item -->
<div id="customItemModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('customItemModal')">&times;</button>
        <h3 style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-plus-circle" style="color:var(--accent-color);"></i> Add Custom Line Item
        </h3>
        <div class="form-group">
            <label class="form-label">Custom Item Name</label>
            <input type="text" id="customItemName" class="form-control" placeholder="e.g. Extra Decoration/Flowers" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Price (₹)</label>
                <input type="number" min="0" step="0.01" id="customItemPrice" class="form-control" placeholder="500.00" required>
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

<script>
// State variable containing invoice items
let invoiceItems = <?= json_encode($js_items) ?>;
const selectableItems = <?= json_encode($selectable_items) ?>;

document.addEventListener('DOMContentLoaded', () => {
    renderItems();
});

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

        html += `
            <tr style="vertical-align: middle;">
                <td>
                    <div style="font-weight: 600; color: var(--text-primary);">${escapeHtml(item.name)}</div>
                    ${item.size ? `<span style="font-size: 0.75rem; color: var(--text-muted);">Size: ${escapeHtml(item.size)}</span>` : ''}
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
            quantity: 1
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
