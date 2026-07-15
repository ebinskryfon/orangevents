<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// =========================================================
// POST HANDLER (CHECKOUT API)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    $customer_name   = trim($_POST['customer_name'] ?? '');
    $customer_phone  = trim($_POST['customer_phone'] ?? '');
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $payment_method  = trim($_POST['payment_method'] ?? 'Cash');
    $cart_data_raw   = $_POST['cart_data'] ?? '[]';

    $cart_items = json_decode($cart_data_raw, true);

    if (empty($cart_items)) {
        echo json_encode(['success' => false, 'error' => 'Cart is empty.']);
        exit;
    }

    try {
        $db->beginTransaction();

        // 1. Calculate totals on backend to verify
        $total_amount = 0.00;
        foreach ($cart_items as $item) {
            $total_amount += (float)$item['price'] * (int)$item['quantity'];
        }
        $final_amount = $total_amount - $discount_amount;
        if ($final_amount < 0) $final_amount = 0;

        // 2. Generate sequential Invoice Number: OE-B-YYYYMMDD-XXXX
        $today = date('Ymd');
        $stmt_count = $db->query("SELECT COUNT(*) FROM billing_orders WHERE DATE(created_at) = CURDATE()");
        $today_count = (int)$stmt_count->fetchColumn() + 1;
        $invoice_number = "OE-B-" . $today . "-" . str_pad($today_count, 4, '0', STR_PAD_LEFT);

        // 3. Insert Billing Order
        $stmt_order = $db->prepare(
            "INSERT INTO billing_orders (invoice_number, customer_name, customer_phone, total_amount, discount_amount, final_amount, payment_method)
             VALUES (:invoice, :name, :phone, :total, :discount, :final, :method)"
        );
        $stmt_order->execute([
            'invoice' => $invoice_number,
            'name' => !empty($customer_name) ? $customer_name : null,
            'phone' => !empty($customer_phone) ? $customer_phone : null,
            'total' => $total_amount,
            'discount' => $discount_amount,
            'final' => $final_amount,
            'method' => $payment_method
        ]);

        $order_id = $db->lastInsertId();

        // 4. Insert Billing Order Items
        $stmt_item = $db->prepare(
            "INSERT INTO billing_order_items (order_id, product_id, variant_id, product_name, variant_size, price, quantity, total_price)
             VALUES (:order_id, :prod_id, :var_id, :prod_name, :size, :price, :qty, :total_price)"
        );

        foreach ($cart_items as $item) {
            $prod_id = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $var_id = (isset($item['variant_id']) && $item['variant_id'] > 0) ? (int)$item['variant_id'] : null;
            $name = $item['name'];
            $size = isset($item['size']) ? $item['size'] : null;
            $price = (float)$item['price'];
            $qty = (int)$item['quantity'];
            $total_price = $price * $qty;

            // Handle custom unlisted item references
            if ($prod_id <= 0) {
                // If it is a custom item, we set product_id to a dummy reference or seed a custom row.
                // In our schema, we enforce foreign key constraint to billing_products.
                // To support custom items cleanly under the foreign key constraint, we can either:
                // a) Seed a default dummy product with ID = 0 or 1 named "Custom POS Item", or
                // b) Create a default custom product row if not exists.
                // Let's check if a custom dummy product exists, else create it.
                $stmt_dummy = $db->prepare("SELECT id FROM billing_products WHERE product_name = 'Custom POS Item' LIMIT 1");
                $stmt_dummy->execute();
                $dummy_id = $stmt_dummy->fetchColumn();

                if (!$dummy_id) {
                    // Get first category
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
                'total_price' => $total_price
            ]);
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

// =========================================================
// FETCH DATA FOR RENDERING
// =========================================================
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

$categories = $db->query("SELECT * FROM billing_categories ORDER BY display_order ASC")->fetchAll();
$products = $db->query(
    "SELECT p.*, c.category_name
       FROM billing_products p
       JOIN billing_categories c ON p.category_id = c.id
      WHERE p.is_active = 1
      ORDER BY p.product_name ASC"
)->fetchAll();

$variants = $db->query("SELECT * FROM billing_product_variants ORDER BY id ASC")->fetchAll();

// Group variants by product
$prod_variants = [];
foreach ($variants as $v) {
    $prod_variants[$v['product_id']][] = $v;
}
?>

<style>
    .pos-container {
        display: grid;
        grid-template-columns: 1.4fr 1fr;
        gap: 1.5rem;
        height: calc(100vh - 160px);
    }
    @media (max-width: 1024px) {
        .pos-container {
            grid-template-columns: 1fr;
            height: auto;
        }
    }
    
    /* Left Panel: Catalog */
    .catalog-panel {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        height: 100%;
        overflow: hidden;
    }
    .catalog-filters {
        display: flex;
        gap: 0.5rem;
        overflow-x: auto;
        padding-bottom: 0.25rem;
    }
    .filter-pill {
        padding: 0.5rem 1rem;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        color: var(--text-secondary);
        font-weight: 500;
        cursor: pointer;
        white-space: nowrap;
        transition: var(--transition-fast);
    }
    .filter-pill.active {
        background: var(--accent-color);
        color: white;
        border-color: var(--accent-color);
    }
    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 1rem;
        overflow-y: auto;
        padding-right: 0.25rem;
        flex-grow: 1;
    }
    .prod-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 1rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.75rem;
        cursor: pointer;
        transition: var(--transition-fast);
    }
    .prod-card:hover {
        border-color: var(--border-highlight);
        transform: translateY(-2px);
    }
    .prod-title {
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.3;
        margin-bottom: 0.25rem;
    }
    .prod-price {
        font-weight: 700;
        color: var(--accent-color);
        font-size: 1.1rem;
    }
    
    /* Right Panel: Cart */
    .cart-panel {
        display: flex;
        flex-direction: column;
        height: 100%;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 1.25rem;
        box-shadow: var(--box-shadow);
        overflow: hidden;
    }
    .cart-header {
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 0.75rem;
        margin-bottom: 0.75rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .cart-items-list {
        flex-grow: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        padding-right: 0.25rem;
        margin-bottom: 1rem;
    }
    .cart-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-sm);
        padding: 0.6rem 0.75rem;
    }
    .cart-item-details {
        flex-grow: 1;
    }
    .cart-item-name {
        font-size: 0.9rem;
        font-weight: 600;
    }
    .cart-item-size {
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    .cart-item-price {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--accent-color);
        margin-top: 0.1rem;
    }
    .cart-item-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .qty-btn {
        width: 24px;
        height: 24px;
        border-radius: 4px;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-weight: bold;
    }
    .qty-btn:hover {
        background: var(--accent-color);
        color: white;
    }
    
    /* Summary details */
    .cart-summary {
        border-top: 1px solid var(--border-color);
        padding-top: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.9rem;
        color: var(--text-secondary);
    }
    .summary-total {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-primary);
        border-top: 1px dashed var(--border-color);
        padding-top: 0.5rem;
        margin-top: 0.25rem;
    }
    
    /* Payment mode switcher */
    .payment-options {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        margin: 0.75rem 0;
    }
    .pay-btn {
        padding: 0.6rem;
        text-align: center;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-sm);
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-secondary);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
        transition: var(--transition-fast);
    }
    .pay-btn.active {
        background: rgba(46, 213, 115, 0.12);
        border-color: var(--success);
        color: var(--success);
    }
    
    /* QR code section */
    .qr-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.75rem;
        margin: 0.5rem 0;
    }
</style>

<div class="pos-container">
    <!-- Left panel: catalogue -->
    <div class="catalog-panel">
        <div style="display:flex; gap:0.5rem;">
            <input type="text" id="searchBar" class="form-control" placeholder="Search products..." style="flex-grow:1;">
            <button onclick="openModal('customItemModal')" class="btn btn-secondary" style="white-space:nowrap;">
                <i class="fa-solid fa-plus-circle"></i> Custom Item
            </button>
        </div>
        
        <div class="catalog-filters">
            <span class="filter-pill active" onclick="filterCategory(0, this)">All</span>
            <?php foreach ($categories as $cat): ?>
                <span class="filter-pill" onclick="filterCategory(<?= $cat['id'] ?>, this)"><?= h($cat['category_name']) ?></span>
            <?php endforeach; ?>
        </div>

        <div class="product-grid" id="productGrid">
            <?php foreach ($products as $p): ?>
                <?php 
                $has_vars = isset($prod_variants[$p['id']]) && count($prod_variants[$p['id']]) > 0;
                $vars_json = $has_vars ? json_encode($prod_variants[$p['id']]) : '[]';
                ?>
                <div class="prod-card" 
                     data-category="<?= $p['category_id'] ?>" 
                     data-name="<?= htmlspecialchars(strtolower($p['product_name']), ENT_QUOTES) ?>"
                     onclick='handleProductClick(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['product_name']), ENT_QUOTES) ?>, <?= $p['base_price'] ?>, <?= $vars_json ?>)'>
                    
                    <div>
                        <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.7rem; padding:0.15rem 0.4rem; margin-bottom:0.4rem;">
                            <?= h($p['category_name']) ?>
                        </span>
                        <div class="prod-title"><?= h($p['product_name']) ?></div>
                    </div>
                    
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span class="prod-price"><?= format_price($p['base_price']) ?></span>
                        <?php if ($has_vars): ?>
                            <span style="font-size:0.75rem; color:var(--info); font-weight:600;"><i class="fa-solid fa-layer-group"></i> <?= count($prod_variants[$p['id']]) ?> Sizes</span>
                        <?php else: ?>
                            <span style="font-size:0.75rem; color:var(--text-muted);"><i class="fa-solid fa-plus-circle"></i> Add</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right panel: shopping cart -->
    <div class="cart-panel">
        <div class="cart-header">
            <h3 style="font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <i class="fa-solid fa-cart-shopping" style="color:var(--accent-color);"></i> Cart
            </h3>
            <button onclick="clearCart()" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem; color:var(--danger); border-color:rgba(255, 71, 87, 0.2);">
                <i class="fa-solid fa-trash-can"></i> Clear
            </button>
        </div>

        <!-- Cart items list -->
        <div class="cart-items-list" id="cartItemsList">
            <!-- Dynamic JavaScript content -->
            <div style="text-align:center; margin:auto; color:var(--text-muted); font-size:0.9rem;">
                <i class="fa-solid fa-basket-shopping" style="font-size:2.5rem; opacity:0.3; margin-bottom:0.75rem; display:block;"></i>
                Cart is empty
            </div>
        </div>

        <!-- Billing Summary & Checkout Form -->
        <form id="checkoutForm" method="POST" style="margin:0;">
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" id="cartDataInput" name="cart_data">

            <!-- Customer Details -->
            <div class="form-row" style="margin-bottom:0.75rem;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.78rem;">Customer Name</label>
                    <input type="text" id="customerName" name="customer_name" class="form-control" style="padding:0.5rem 0.75rem; font-size:0.85rem;" placeholder="Walk-in Client">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.78rem;">Customer Phone</label>
                    <input type="text" id="customerPhone" name="customer_phone" class="form-control" style="padding:0.5rem 0.75rem; font-size:0.85rem;" placeholder="Phone No.">
                </div>
            </div>

            <!-- Discount Input -->
            <div class="form-group" style="margin-bottom:0.75rem;">
                <label class="form-label" style="font-size:0.78rem;">Discount (Flat Rs)</label>
                <input type="number" min="0" value="0" id="discountInput" name="discount_amount" class="form-control" style="padding:0.5rem 0.75rem; font-size:0.85rem;" placeholder="0.00">
            </div>

            <!-- Summary Calculations -->
            <div class="cart-summary">
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
                    <span id="summaryTotal">₹0.00</span>
                </div>
            </div>

            <!-- Payment mode selection -->
            <div style="margin-top:0.75rem;">
                <label class="form-label" style="font-size:0.78rem; margin-bottom:0.25rem;">Payment Method</label>
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
                </div>
                <input type="hidden" id="paymentMethodInput" name="payment_method" value="Cash">
            </div>

            <!-- UPI Payment Area -->
            <div id="upiPaymentArea" style="display:none;">
                <div class="qr-container">
                    <div style="font-size:0.78rem; color:var(--text-secondary); width:100%; display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                        <span style="white-space:nowrap;">UPI VPA / Phone:</span>
                        <input type="text" id="upiPhoneInput" class="form-control" style="padding:0.25rem 0.5rem; font-size:0.8rem; height:26px;" value="<?= h($default_upi) ?>">
                    </div>
                    <img id="upiQRCodeImage" src="" alt="UPI Payment QR Code" style="background:#fff; border-radius:4px; padding:0.25rem; display:block; width:150px; height:150px;">
                    <div style="font-size:0.7rem; color:var(--text-muted); text-align:center; margin-top:0.25rem;">
                        <i class="fa-solid fa-circle-info"></i> Scan with GPay, PhonePe, Paytm etc.
                    </div>
                </div>
            </div>

            <!-- Submit buttons -->
            <button type="button" onclick="submitCheckout()" class="btn btn-success" style="width:100%; height:48px; margin-top:0.75rem; font-size:1rem; box-shadow:0 4px 12px rgba(46, 213, 115, 0.2);">
                <i class="fa-solid fa-file-invoice-dollar"></i> Generate & Print Receipt
            </button>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: Select Variant (Sizes)
     ============================================================ -->
<div id="variantSelectModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('variantSelectModal')">&times;</button>
        <h3 id="variantSelTitle" style="margin-bottom:0.5rem;">Select Size</h3>
        <p id="variantSelProduct" style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:1.5rem;"></p>
        <div id="variantButtonsList" style="display:flex; flex-direction:column; gap:0.75rem;">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: Add Custom / Extra Billing Item
     ============================================================ -->
<div id="customItemModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('customItemModal')">&times;</button>
        <h3 style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-plus-circle" style="color:var(--accent-color);"></i> Add Custom Item
        </h3>
        <div class="form-group">
            <label class="form-label">Item Name</label>
            <input type="text" id="customItemName" class="form-control" placeholder="e.g. Special Candle Set" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Price (Rs)</label>
                <input type="number" min="0" step="0.01" id="customItemPrice" class="form-control" placeholder="50.00" required>
            </div>
            <div class="form-group">
                <label class="form-label">Quantity</label>
                <input type="number" min="1" value="1" id="customItemQty" class="form-control" required>
            </div>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
            <button type="button" onclick="closeModal('customItemModal')" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="addCustomItemToCart()" class="btn btn-primary">Add to Cart</button>
        </div>
    </div>
</div>

<script>
    // POS State
    let cart = [];
    let selectedCategoryId = 0;
    let paymentMethod = 'Cash';

    // Elements
    const searchBar = document.getElementById('searchBar');
    const productGrid = document.getElementById('productGrid');
    const cartItemsList = document.getElementById('cartItemsList');
    const discountInput = document.getElementById('discountInput');
    const customerName = document.getElementById('customerName');
    const customerPhone = document.getElementById('customerPhone');
    const summarySubtotal = document.getElementById('summarySubtotal');
    const summaryDiscount = document.getElementById('summaryDiscount');
    const summaryTotal = document.getElementById('summaryTotal');
    const upiPhoneInput = document.getElementById('upiPhoneInput');

    // =========================================================
    // INITIALIZATION & LOCALSTORAGE RESTORATION
    // =========================================================
    window.addEventListener('DOMContentLoaded', () => {
        // Restore cart
        const savedCart = localStorage.getItem('orange_billing_cart');
        if (savedCart) {
            try {
                cart = JSON.parse(savedCart);
            } catch(e) {
                cart = [];
            }
        }
        
        // Restore customer details
        customerName.value = localStorage.getItem('orange_billing_customer_name') || '';
        customerPhone.value = localStorage.getItem('orange_billing_customer_phone') || '';
        discountInput.value = localStorage.getItem('orange_billing_discount') || '0';
        paymentMethod = localStorage.getItem('orange_billing_payment_method') || 'Cash';
        
        if (localStorage.getItem('orange_billing_upi_phone')) {
            upiPhoneInput.value = localStorage.getItem('orange_billing_upi_phone');
        }

        // Live backup event listeners
        customerName.addEventListener('input', () => localStorage.setItem('orange_billing_customer_name', customerName.value));
        customerPhone.addEventListener('input', () => localStorage.setItem('orange_billing_customer_phone', customerPhone.value));
        discountInput.addEventListener('input', () => {
            localStorage.setItem('orange_billing_discount', discountInput.value);
            renderCart();
        });
        upiPhoneInput.addEventListener('input', () => {
            localStorage.setItem('orange_billing_upi_phone', upiPhoneInput.value);
            updateUPIQRCode();
        });

        // Search live filter
        searchBar.addEventListener('input', filterProducts);

        selectPaymentMethod(paymentMethod);
        renderCart();
    });

    // Save cart state
    function saveCartState() {
        localStorage.setItem('orange_billing_cart', JSON.stringify(cart));
    }

    // =========================================================
    // CATALOG & SEARCH FILTER
    // =========================================================
    function filterCategory(catId, element) {
        selectedCategoryId = catId;
        // Toggle active pill
        document.querySelectorAll('.filter-pill').forEach(pill => pill.classList.remove('active'));
        element.classList.add('active');
        filterProducts();
    }

    function filterProducts() {
        const query = searchBar.value.trim().toLowerCase();
        document.querySelectorAll('.prod-card').forEach(card => {
            const cat = parseInt(card.dataset.category);
            const name = card.dataset.name;
            const matchesCat = (selectedCategoryId === 0 || cat === selectedCategoryId);
            const matchesSearch = name.includes(query);

            if (matchesCat && matchesSearch) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // =========================================================
    // ADDING ITEMS & VARIANT HANDLING
    // =========================================================
    function handleProductClick(id, name, basePrice, variantsList) {
        if (variantsList && variantsList.length > 0) {
            // Open size selection modal
            document.getElementById('variantSelTitle').innerText = "Select Size";
            document.getElementById('variantSelProduct').innerText = name;
            
            const btnList = document.getElementById('variantButtonsList');
            btnList.innerHTML = '';
            
            variantsList.forEach(v => {
                const price = v.price !== null ? parseFloat(v.price) : basePrice;
                const button = document.createElement('button');
                button.className = "btn btn-secondary";
                button.style.textAlign = "left";
                button.style.display = "flex";
                button.style.justifyContent = "space-between";
                button.style.padding = "1rem";
                button.innerHTML = `
                    <span style="font-weight:600;"><i class="fa-solid fa-arrows-left-right"></i> ${v.size}</span>
                    <span style="color:var(--accent-color); font-weight:700;">₹${price.toFixed(2)}</span>
                `;
                button.onclick = () => {
                    addItemToCart(id, v.id, name, v.size, price);
                    closeModal('variantSelectModal');
                };
                btnList.appendChild(button);
            });
            
            openModal('variantSelectModal');
        } else {
            // No variants, add directly using base price
            addItemToCart(id, null, name, null, basePrice);
        }
    }

    function addItemToCart(productId, variantId, name, size, price) {
        // Check if item already exists in cart with same product_id and variant_id
        const existingIndex = cart.findIndex(item => 
            item.product_id === productId && 
            item.variant_id === variantId &&
            !item.is_custom
        );

        if (existingIndex !== -1) {
            cart[existingIndex].quantity += 1;
        } else {
            cart.push({
                product_id: productId,
                variant_id: variantId,
                name: name,
                size: size,
                price: price,
                quantity: 1,
                is_custom: false
            });
        }
        
        saveCartState();
        renderCart();
    }

    // =========================================================
    // CUSTOM / EXTRA ITEM HANDLING
    // =========================================================
    function addCustomItemToCart() {
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

        // Add custom item
        cart.push({
            product_id: 0,
            variant_id: null,
            name: name,
            size: 'Custom',
            price: price,
            quantity: qty,
            is_custom: true
        });

        // Clear modal values
        nameInput.value = '';
        priceInput.value = '';
        qtyInput.value = '1';

        closeModal('customItemModal');
        saveCartState();
        renderCart();
    }

    // =========================================================
    // CART OPERATIONS
    // =========================================================
    function updateQty(index, amount) {
        cart[index].quantity += amount;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        }
        saveCartState();
        renderCart();
    }

    function removeItem(index) {
        cart.splice(index, 1);
        saveCartState();
        renderCart();
    }

    function clearCart() {
        if (confirm('Are you sure you want to clear all items in the cart?')) {
            cart = [];
            saveCartState();
            renderCart();
        }
    }

    // =========================================================
    // RENDER CART & UPI QR CODE
    // =========================================================
    function renderCart() {
        cartItemsList.innerHTML = '';
        
        if (cart.length === 0) {
            cartItemsList.innerHTML = `
                <div style="text-align:center; margin:auto; color:var(--text-muted); font-size:0.9rem;">
                    <i class="fa-solid fa-basket-shopping" style="font-size:2.5rem; opacity:0.3; margin-bottom:0.75rem; display:block;"></i>
                    Cart is empty
                </div>
            `;
            summarySubtotal.innerText = '₹0.00';
            summaryDiscount.innerText = '-₹0.00';
            summaryTotal.innerText = '₹0.00';
            updateUPIQRCode();
            return;
        }

        let subtotal = 0.00;
        
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            subtotal += itemTotal;

            const cartRow = document.createElement('div');
            cartRow.className = 'cart-item';
            cartRow.innerHTML = `
                <div class="cart-item-details">
                    <div class="cart-item-name">${item.name}</div>
                    ${item.size ? `<div class="cart-item-size"><i class="fa-solid fa-arrows-left-right"></i> ${item.size}</div>` : ''}
                    <div class="cart-item-price">₹${item.price.toFixed(2)} × ${item.quantity} = ₹${itemTotal.toFixed(2)}</div>
                </div>
                <div class="cart-item-actions">
                    <button type="button" class="qty-btn" onclick="updateQty(${index}, -1)">-</button>
                    <span style="font-weight:600; min-width:18px; text-align:center;">${item.quantity}</span>
                    <button type="button" class="qty-btn" onclick="updateQty(${index}, 1)">+</button>
                    <button type="button" class="qty-btn" onclick="removeItem(${index})" style="background:rgba(255, 71, 87, 0.1); color:var(--danger); border-color:rgba(255,71,87,0.15); margin-left:0.25rem;">
                        <i class="fa-solid fa-trash-can" style="font-size:0.75rem;"></i>
                    </button>
                </div>
            `;
            cartItemsList.appendChild(cartRow);
        });

        // Summaries
        const discountVal = parseFloat(discountInput.value) || 0.00;
        let totalVal = subtotal - discountVal;
        if (totalVal < 0) totalVal = 0.00;

        summarySubtotal.innerText = `₹${subtotal.toFixed(2)}`;
        summaryDiscount.innerText = `-₹${discountVal.toFixed(2)}`;
        summaryTotal.innerText = `₹${totalVal.toFixed(2)}`;

        updateUPIQRCode();
    }

    // =========================================================
    // PAYMENT & UPI QR CODE GENERATION
    // =========================================================
    function selectPaymentMethod(method) {
        paymentMethod = method;
        localStorage.setItem('orange_billing_payment_method', method);
        document.getElementById('paymentMethodInput').value = method;

        // Toggle button active classes
        document.querySelectorAll('.pay-btn').forEach(btn => btn.classList.remove('active'));
        if (method === 'Cash') document.getElementById('payCash').classList.add('active');
        if (method === 'UPI') document.getElementById('payUPI').classList.add('active');
        if (method === 'Card') document.getElementById('payCard').classList.add('active');

        // Show/hide QR code area
        const upiArea = document.getElementById('upiPaymentArea');
        if (method === 'UPI') {
            upiArea.style.display = 'block';
            updateUPIQRCode();
        } else {
            upiArea.style.display = 'none';
        }
    }

    function updateUPIQRCode() {
        if (paymentMethod !== 'UPI') return;
        
        // Calculate total amount
        let subtotal = 0.00;
        cart.forEach(item => subtotal += item.price * item.quantity);
        const discountVal = parseFloat(discountInput.value) || 0.00;
        let totalVal = subtotal - discountVal;
        if (totalVal < 0) totalVal = 0.00;

        let upiId = upiPhoneInput.value.trim();
        if (upiId === '') return;

        // If it is a 10 digit phone number, append @upi
        if (/^\d{10}$/.test(upiId)) {
            upiId = upiId + '@upi';
        }

        const businessName = encodeURIComponent("Orange Events");
        const amount = totalVal.toFixed(2);
        
        // Build standard UPI URL schema
        const upiUrl = `upi://pay?pa=${upiId}&pn=${businessName}&am=${amount}&cu=INR`;
        
        // Generate QR code URL using standard web service
        const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(upiUrl)}`;
        
        document.getElementById('upiQRCodeImage').src = qrCodeUrl;
    }

    // =========================================================
    // ORDER SUBMISSION
    // =========================================================
    function submitCheckout() {
        if (cart.length === 0) {
            alert('Cannot checkout because your cart is empty.');
            return;
        }

        // Prepare post parameters
        const form = document.getElementById('checkoutForm');
        document.getElementById('cartDataInput').value = JSON.stringify(cart);

        // Submit form via fetch to process and get redirected
        const formData = new FormData(form);

        // Add additional field for UPI VPA/phone used in payment
        if (paymentMethod === 'UPI') {
            formData.append('upi_id_used', upiPhoneInput.value.trim());
        }

        fetch('billing.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Clear local storage on success
                localStorage.removeItem('orange_billing_cart');
                localStorage.removeItem('orange_billing_customer_name');
                localStorage.removeItem('orange_billing_customer_phone');
                localStorage.removeItem('orange_billing_discount');
                
                // Redirect to thermal invoice print page
                window.location.href = 'billing-invoice.php?id=' + data.order_id;
            } else {
                alert('Checkout failed: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Checkout failed due to a network error.');
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
