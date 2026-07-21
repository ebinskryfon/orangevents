<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_create');

$db = get_db_connection();

// Check if cash register session is active for current user
$user_id = $_SESSION['admin_id'];
$register_stmt = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
$register_stmt->execute(['user_id' => $user_id]);
$is_register_open = (bool) $register_stmt->fetch();


// Fetch all active product variants that have a barcode
$variants_res = $db->query("
    SELECT v.id AS variant_id, v.product_id, v.size, v.price AS variant_price, v.barcode, v.stock_quantity, v.allow_loose, v.loose_price, v.loose_units_per_whole,
           p.product_name, p.base_price, p.category_id, c.category_name
      FROM billing_product_variants v
      JOIN billing_products p ON v.product_id = p.id
      JOIN billing_categories c ON p.category_id = c.id
     WHERE p.is_active = 1 AND v.barcode IS NOT NULL AND v.barcode != ''
     ORDER BY p.product_name ASC
")->fetchAll();

$barcode_map = [];
foreach ($variants_res as $row) {
    $effective_price = $row['variant_price'] !== null ? (float) $row['variant_price'] : (float) $row['base_price'];
    $barcode_map[$row['barcode']] = [
        'product_id' => (int) $row['product_id'],
        'variant_id' => (int) $row['variant_id'],
        'display_name' => $row['product_name'] . ' (' . $row['size'] . ')',
        'size' => $row['size'],
        'price' => $effective_price,
        'allow_loose' => (int) $row['allow_loose'],
        'loose_price' => $row['loose_price'] !== null ? (float) $row['loose_price'] : null,
        'loose_units' => (float) $row['loose_units_per_whole'],
        'stock' => (float) $row['stock_quantity'],
        'category_name' => $row['category_name']
    ];
}
?>

<style>
    .barcode-billing-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        height: 480px;
        overflow: hidden;
    }

    @media (max-width: 992px) {
        .barcode-billing-container {
            grid-template-columns: 1fr;
            height: auto;
            overflow: visible;
        }
    }

    .scanner-section {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        box-shadow: var(--box-shadow);
        justify-content: flex-start;
        overflow-y: auto;
    }

    .scanner-box {
        border: 2px dashed rgba(255, 107, 53, 0.3);
        background: rgba(255, 107, 53, 0.01);
        border-radius: var(--border-radius-md);
        padding: 0.75rem 0.5rem;
        text-align: center;
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
        transition: var(--transition-fast);
    }

    .scanner-box.active-focus {
        border-color: var(--accent-color);
        background: rgba(255, 107, 53, 0.04);
        box-shadow: 0 0 20px rgba(255, 107, 53, 0.08);
    }

    .scanner-input-wrapper {
        width: 100%;
        max-width: 300px;
        position: relative;
        z-index: 2;
    }

    .scanner-input {
        width: 100%;
        height: 32px;
        padding: 0 0.75rem 0 2rem;
        font-size: 0.85rem;
        font-weight: 700;
        text-align: center;
        letter-spacing: 0.05em;
        background: var(--bg-control);
        border: 2px solid var(--border-color);
        border-radius: var(--border-radius-md);
        color: var(--text-primary);
        outline: none;
        transition: var(--transition-fast);
    }

    .scanner-input:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.15);
    }

    .scanner-icon {
        position: absolute;
        left: 8px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.85rem;
        color: var(--accent-color);
    }

    .feedback-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.5rem 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-height: 70px;
        justify-content: center;
        transition: var(--transition-fast);
    }

    .feedback-title {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .feedback-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.25;
    }

    .feedback-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 0.2rem;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        padding-top: 0.35rem;
    }

    .feedback-price {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--accent-color);
    }

    .cart-section {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 0.75rem 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        box-shadow: var(--box-shadow);
        overflow: hidden;
        height: 100%;
    }

    .cart-list-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 0.5rem;
        flex-shrink: 0;
    }

    .barcode-cart-items {
        flex-grow: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        padding-right: 0.25rem;
    }

    .barcode-cart-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.4rem;
        background: rgba(255, 255, 255, 0.015);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-md);
        padding: 0.3rem 0.5rem;
        transition: var(--transition-fast);
    }

    .barcode-cart-item:hover {
        background: rgba(255, 255, 255, 0.03);
        border-color: var(--border-highlight);
    }

    .qty-btn {
        width: 20px;
        height: 20px;
        border-radius: 4px;
        background: var(--bg-control);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-weight: bold;
        font-size: 0.75rem;
        transition: var(--transition-fast);
    }

    .qty-btn:hover {
        background: var(--accent-color);
        color: white;
        border-color: var(--accent-color);
    }

    .cart-footer {
        border-top: 1px solid var(--border-color);
        padding-top: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .grand-total-box {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(255, 107, 53, 0.05);
        border: 1px solid rgba(255, 107, 53, 0.15);
        border-radius: var(--border-radius-md);
        padding: 0.5rem 0.75rem;
    }

    .grand-total-label {
        font-weight: 700;
        color: var(--text-secondary);
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .grand-total-amount {
        font-size: 1.4rem;
        font-weight: 900;
        color: var(--accent-color);
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

<?php if (!$is_register_open): ?>
    <div
        style="background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: var(--border-radius-md); padding: 2rem 1.5rem; text-align: center; max-width: 440px; margin: 4rem auto; display: flex; flex-direction: column; align-items: center; gap: 1rem; box-shadow: var(--box-shadow);">
        <i class="fa-solid fa-lock" style="font-size: 3rem; color: var(--text-muted); opacity: 0.4;"></i>
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--text-primary); margin: 0;">Register is Closed</h2>
        <p style="color: var(--text-secondary); line-height: 1.4; margin: 0; font-size: 0.85rem;">
            You must open the daily cash register and enter the opening balance float before you can access the Barcode
            Billing terminal and process any transactions.
        </p>
        <a href="register-sessions.php" class="btn btn-primary"
            style="height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0 1.25rem; font-weight: 600; text-decoration: none; font-size: 0.85rem;">
            <i class="fa-solid fa-cash-register"></i> Go to Register Manager
        </a>
    </div>
<?php else: ?>
    <!-- Page Header Info -->
    <div class="content-header"
        style="margin-bottom: 0.75rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0; display:flex; justify-content:space-between; align-items:center;">
        <div class="header-title">
            <h1
                style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
                <i class="fa-solid fa-barcode" style="color:var(--accent-color);"></i>
                Barcode Billing Terminal
            </h1>
            <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
                Supermarket-style billing interface optimized for rapid hand-held barcode scanning.
            </p>
        </div>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <button onclick="openPosReturnModal()" class="btn btn-secondary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
                <i class="fa-solid fa-rotate-left" style="color:var(--accent-color);"></i> Return / Exchange (F7)
            </button>
            <a href="pos-returns.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
                <i class="fa-solid fa-list-check"></i> Returns Log
            </a>
        </div>
    </div>

    <div class="barcode-billing-container">
        <!-- Left panel: Scanner & Info -->
        <div class="scanner-section">
            <!-- Interactive scan box -->
            <div class="scanner-box" id="scannerBox">
                <i class="fa-solid fa-qrcode" style="font-size: 1.25rem; color: var(--accent-color); opacity: 0.8;"></i>
                <h3 style="margin:0; font-size:0.9rem;">Scan Barcode</h3>
                <p style="color:var(--text-muted); font-size:0.7rem; margin:0; max-width:240px;">
                    Point the barcode reader to scan items or enter the barcode manually.
                </p>
                <div class="scanner-input-wrapper">
                    <i class="fa-solid fa-barcode scanner-icon"></i>
                    <input type="text" id="barcodeInput" class="scanner-input" placeholder="Scan Barcode..."
                        autocomplete="off">
                </div>
                <div
                    style="font-size:0.65rem; color:var(--text-muted); display:flex; align-items:center; justify-content:space-between; width:100%; max-width:240px; margin-top:0.25rem;">
                    <div style="display:flex; align-items:center; gap:0.2rem;">
                        <span class="status-dot" id="focusStatusDot"
                            style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#2ed573;"></span>
                        <span id="focusStatusText">Scanner Ready</span>
                    </div>
                    <button type="button" id="muteSoundBtn" onclick="toggleMuteSound()"
                        style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:0.65rem; display:flex; align-items:center; gap:0.2rem; outline:none; padding:2px; transition:color var(--transition-fast);">
                        <i class="fa-solid fa-volume-high" id="muteSoundIcon"></i> <span id="muteSoundText">Sound On</span>
                    </button>
                </div>
            </div>

            <!-- Last scanned item details card -->
            <div class="feedback-card" id="feedbackCard">
                <div class="feedback-title" id="feedbackTitle">
                    <i class="fa-solid fa-magnifying-glass"></i> Last Scanned Product
                </div>
                <div id="feedbackContent"
                    style="display:flex; flex-direction:column; gap:0.35rem; height:100%; justify-content:center;">
                    <div style="text-align:center; color:var(--text-muted); font-style:italic; font-size:0.75rem;">
                        No barcode scanned yet in this session.
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; width: 100%; margin-bottom: 0.4rem;">
                <button onclick="parkCurrentOrder()" class="btn btn-secondary"
                    style="display:flex; align-items:center; justify-content:center; gap:0.25rem; height:30px; font-size:0.75rem; padding:0; color:var(--text-secondary);">
                    <i class="fa-solid fa-pause"></i> Park Cart
                </button>
                <button onclick="openParkedOrdersModal()" class="btn btn-secondary" id="parkedOrdersBtn"
                    style="display:flex; align-items:center; justify-content:center; gap:0.25rem; height:30px; font-size:0.75rem; padding:0; position:relative;">
                    <i class="fa-solid fa-folder-open"></i> Parked <span class="badge" id="parkedCountBadge" style="background:var(--accent-color); color:white; font-size:0.6rem; padding:1px 5px; border-radius:10px; margin-left:2px; display:none;">0</span>
                </button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; width: 100%;">
                <button onclick="openModal('customItemModal')" class="btn btn-secondary"
                    style="display:flex; align-items:center; justify-content:center; gap:0.25rem; height:30px; font-size:0.75rem; padding:0;">
                    <i class="fa-solid fa-plus-circle"></i> Custom Item
                </button>
                <button onclick="clearCart()" class="btn btn-secondary"
                    style="color:var(--danger); border-color:rgba(255, 71, 87, 0.2); display:flex; align-items:center; justify-content:center; gap:0.25rem; height:30px; font-size:0.75rem; padding:0;">
                    <i class="fa-solid fa-trash-can"></i> Clear Cart
                </button>
            </div>
        </div>

        <!-- Right panel: Cart Items -->
        <div class="cart-section">
            <div class="cart-list-header">
                <h3 style="margin:0; font-size:0.95rem; display:flex; align-items:center; gap:0.35rem;">
                    <i class="fa-solid fa-cart-shopping" style="color:var(--accent-color);"></i>
                    Scanned Items List
                </h3>
                <span class="badge" id="cartItemCountBadge"
                    style="background:rgba(255, 107, 53, 0.1); color:var(--accent-color); font-weight:700;">0 items</span>
            </div>

            <div class="barcode-cart-items" id="cartItemsList">
                <!-- Rendered dynamically -->
            </div>

            <div class="cart-footer">
                <div class="grand-total-box">
                    <span class="grand-total-label">
                        <i class="fa-solid fa-indian-rupee-sign"></i> Total Payable
                    </span>
                    <span class="grand-total-amount" id="cartGrandTotal">₹0.00</span>
                </div>
                <button onclick="goToCheckout()" class="btn btn-success"
                    style="width:100%; height:38px; font-size:0.9rem; font-weight:700; box-shadow:0 4px 12px rgba(46, 213, 115, 0.15); display:flex; align-items:center; justify-content:center; gap:0.35rem;">
                    <i class="fa-solid fa-circle-check"></i> Proceed to Checkout
                </button>
            </div>
        </div>
    </div>

    <!-- POS Keyboard Shortcut Legend Bar -->
    <div class="pos-hotkey-bar">
        <span style="font-weight: 700; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem;">
            <i class="fa-solid fa-keyboard" style="color: var(--accent-color);"></i> Shortcuts:
        </span>
        <span class="pos-hotkey-item"><kbd>F2</kbd> Focus Scanner</span>
        <span class="pos-hotkey-item"><kbd>F4</kbd> Custom Item</span>
        <span class="pos-hotkey-item"><kbd>F8</kbd> Clear Cart</span>
        <span class="pos-hotkey-item"><kbd>F9</kbd> Cash Checkout</span>
        <span class="pos-hotkey-item"><kbd>F10</kbd> Proceed Checkout</span>
        <span class="pos-hotkey-item"><kbd>ESC</kbd> Cancel / Focus</span>
    </div>

    <!-- ============================================================
     MODAL: Add Custom / Extra Billing Item
     ============================================================ -->
    <div id="customItemModal" class="modal">
        <div class="modal-content" style="max-width: 480px;">
            <button class="modal-close" onclick="closeModal('customItemModal')">&times;</button>
            <h3 style="margin-bottom:1.5rem;">
                <i class="fa-solid fa-plus-circle" style="color:var(--accent-color);"></i> Add Custom Item
            </h3>
            <div class="form-group">
                <label class="form-label">Item Name</label>
                <input type="text" id="customItemName" class="form-control"
                    placeholder="e.g. Service Charges / Special Setup" required>
            </div>
            <div class="form-row" style="display:flex; gap:1rem; margin-top:1rem;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Price (Rs)</label>
                    <input type="number" min="0" step="0.01" id="customItemPrice" class="form-control" placeholder="0.00"
                        required>
                </div>
                <div class="form-group" style="flex:1;">
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

    <!-- ============================================================
     MODAL: Parked Orders List
     ============================================================ -->
    <div id="parkedOrdersModal" class="modal">
        <div class="modal-content" style="max-width: 580px;">
            <button class="modal-close" onclick="closeModal('parkedOrdersModal')">&times;</button>
            <h3 style="margin-bottom:1.5rem;">
                <i class="fa-solid fa-pause-circle" style="color:var(--accent-color);"></i> Parked Orders
            </h3>
            <div id="parkedOrdersList" style="display:flex; flex-direction:column; gap:0.75rem; max-height:320px; overflow-y:auto; padding-right:0.25rem;">
                <!-- Rendered dynamically -->
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem; border-top:1px solid var(--border-color); padding-top:1rem;">
                <button type="button" onclick="closeModal('parkedOrdersModal')" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <div id="barcodeToastContainer"
        style="position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px;"></div>

    <?php include_once __DIR__ . '/../includes/return-modal.php'; ?>

    <script>
        // Load local barcode dictionary compiled in PHP
        const barcodeMap = <?= json_encode($barcode_map) ?>;

        // POS Cart State
        let cart = [];
        let audioCtx = null;
        let soundMuted = localStorage.getItem('barcode_beep_muted') === 'true';

        function toggleMuteSound() {
            soundMuted = !soundMuted;
            localStorage.setItem('barcode_beep_muted', soundMuted ? 'true' : 'false');
            updateMuteButtonUI();
        }

        function updateMuteButtonUI() {
            const icon = document.getElementById('muteSoundIcon');
            const text = document.getElementById('muteSoundText');
            if (icon && text) {
                if (soundMuted) {
                    icon.className = 'fa-solid fa-volume-xmark';
                    icon.style.color = 'var(--danger)';
                    text.innerText = 'Muted';
                } else {
                    icon.className = 'fa-solid fa-volume-high';
                    icon.style.color = '';
                    text.innerText = 'Sound On';
                }
            }
        }

        // Beep sounds logic
        function playBeep(freq, duration) {
            if (soundMuted) return;
            try {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.frequency.value = freq;
                osc.type = 'sine';
                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                osc.start();
                osc.stop(audioCtx.currentTime + (duration / 1000));
            } catch (e) {
                console.error("Audio Context initialization deferred", e);
            }
        }

        function showBarcodeToast(message, type = 'success') {
            const container = document.getElementById('barcodeToastContainer');
            const toast = document.createElement('div');
            toast.style.background = type === 'success' ? '#2ed573' : '#ff4757';
            toast.style.color = '#ffffff';
            toast.style.padding = '0.75rem 1.25rem';
            toast.style.borderRadius = '8px';
            toast.style.fontSize = '0.9rem';
            toast.style.fontWeight = '600';
            toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
            toast.style.display = 'flex';
            toast.style.alignItems = 'center';
            toast.style.gap = '0.5rem';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(20px)';
            toast.style.transition = 'all 0.3s ease';

            const icon = type === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-triangle-exclamation"></i>';
            toast.innerHTML = `${icon} <span>${message}</span>`;

            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
            }, 10);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => { toast.remove(); }, 300);
            }, 3000);
        }

        // Restore & Save Cart
        function loadCart() {
            const savedCart = localStorage.getItem('orange_billing_cart');
            if (savedCart) {
                try {
                    cart = JSON.parse(savedCart);
                } catch (e) {
                    cart = [];
                }
            }
            renderCart();
        }

        function saveCartState() {
            localStorage.setItem('orange_billing_cart', JSON.stringify(cart));
        }

        // Core Cart Add Function
        function addItemToCart(productId, variantId, name, size, price, allowLoose = 0, loosePrice = null, looseUnits = 1) {
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
                    whole_price: price,
                    quantity: 1,
                    is_custom: false,
                    allow_loose: allowLoose,
                    loose_price: loosePrice,
                    loose_units_per_whole: looseUnits,
                    sell_type: 'whole'
                });
            }

            saveCartState();
            renderCart();
        }

        // Custom Item
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

            cart.push({
                product_id: 0,
                variant_id: null,
                name: name,
                size: 'Custom',
                price: price,
                quantity: qty,
                is_custom: true
            });

            nameInput.value = '';
            priceInput.value = '';
            qtyInput.value = '1';

            closeModal('customItemModal');
            saveCartState();
            renderCart();

            playBeep(800, 100);
            showBarcodeToast(`Added custom item: ${name}`, 'success');

            // Return focus to scanner
            const barcodeInput = document.getElementById('barcodeInput');
            if (barcodeInput) barcodeInput.focus();
        }

        // Cart Operations
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
            if (cart.length === 0) return;
            if (confirm('Are you sure you want to clear all items in the cart?')) {
                cart = [];
                saveCartState();
                renderCart();
                showBarcodeToast('Cart cleared', 'success');
            }
        }

        function changeItemSellType(index, type) {
            if (!cart[index]) return;
            cart[index].sell_type = type;
            if (type === 'loose') {
                cart[index].price = parseFloat(cart[index].loose_price);
            } else {
                cart[index].price = parseFloat(cart[index].whole_price);
            }
            saveCartState();
            renderCart();
        }

        // Render Cart HTML
        function renderCart() {
            const listContainer = document.getElementById('cartItemsList');
            const totalAmountContainer = document.getElementById('cartGrandTotal');
            const itemsCountBadge = document.getElementById('cartItemCountBadge');

            if (cart.length === 0) {
                listContainer.innerHTML = `
                <div style="text-align:center; padding:3rem 1rem; color:var(--text-muted); display:flex; flex-direction:column; align-items:center; gap:0.75rem;">
                    <i class="fa-solid fa-basket-shopping" style="font-size:2.2rem; opacity:0.25;"></i>
                    <span style="font-size:0.75rem;">Terminal cart is empty. Scan barcodes to add items.</span>
                </div>
            `;
                totalAmountContainer.innerText = '₹0.00';
                itemsCountBadge.innerText = '0 items';
                return;
            }

            let subtotal = 0;
            let totalItems = 0;
            let html = '';

            cart.forEach((item, index) => {
                const itemTotal = item.price * item.quantity;
                subtotal += itemTotal;
                totalItems += item.quantity;

                let sellTypeSelectorHtml = '';
                if (parseInt(item.allow_loose) === 1) {
                    const loosePriceVal = parseFloat(item.loose_price || 0);
                    const wholePriceVal = parseFloat(item.whole_price || 0);
                    sellTypeSelectorHtml = `
                    <div style="margin-top: 0.1rem; display: flex; gap: 0.4rem; align-items: center;">
                        <span style="font-size:0.65rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.03em;">Type:</span>
                        <label style="font-size:0.65rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.15rem; margin:0;">
                            <input type="radio" name="sell_type_barcode_${index}" value="whole" ${item.sell_type === 'whole' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'whole')" style="accent-color:var(--accent-color); cursor:pointer; transform: scale(0.85);">
                            Pack (₹${wholePriceVal.toFixed(2)})
                        </label>
                        <label style="font-size:0.65rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.15rem; margin:0;">
                            <input type="radio" name="sell_type_barcode_${index}" value="loose" ${item.sell_type === 'loose' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'loose')" style="accent-color:var(--accent-color); cursor:pointer; transform: scale(0.85);">
                            Loose (₹${loosePriceVal.toFixed(2)})
                        </label>
                    </div>
                `;
                }

                html += `
                <div class="barcode-cart-item">
                    <div style="flex-grow:1; min-width:0;">
                        <div style="font-weight:600; font-size:0.8rem; color:var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                        <div style="display:flex; gap:0.35rem; align-items:center; margin-top:0.1rem;">
                            ${item.size ? `<span style="font-size:0.65rem; color:var(--text-muted);">Size: ${escapeHtml(item.size)}</span>` : ''}
                            <span style="font-size:0.7rem; color:var(--accent-color); font-weight:600;">₹${item.price.toFixed(2)}</span>
                        </div>
                        ${sellTypeSelectorHtml}
                    </div>
                    <div style="display:flex; align-items:center; gap:0.35rem; flex-shrink:0;">
                        <div style="display:flex; align-items:center; border:1px solid var(--border-color); border-radius:4px; overflow:hidden;">
                            <button type="button" class="qty-btn" onclick="updateQty(${index}, -1)">-</button>
                            <span style="font-weight:600; min-width:16px; text-align:center; font-size:0.75rem; color:var(--text-primary);">${item.quantity}</span>
                            <button type="button" class="qty-btn" onclick="updateQty(${index}, 1)">+</button>
                        </div>
                        <div style="font-weight:700; font-size:0.8rem; width:60px; text-align:right; color:var(--text-primary);">₹${itemTotal.toFixed(2)}</div>
                        <button type="button" class="qty-btn" onclick="removeItem(${index})" style="background:rgba(255, 71, 87, 0.08); color:var(--danger); border-color:rgba(255,71,87,0.12); width:20px; height:20px;">
                            <i class="fa-solid fa-trash-can" style="font-size:0.65rem;"></i>
                        </button>
                    </div>
                </div>
            `;
            });

            listContainer.innerHTML = html;
            totalAmountContainer.innerText = '₹' + subtotal.toFixed(2);
            itemsCountBadge.innerText = `${totalItems} item${totalItems > 1 ? 's' : ''}`;
        }

        // Barcode Scanning Input Process
        function processBarcodeValue(barcodeValue) {
            if (!barcodeValue) return;

            const variant = barcodeMap[barcodeValue];
            const feedbackCard = document.getElementById('feedbackCard');
            const feedbackContent = document.getElementById('feedbackContent');

            if (variant) {
                // Add variant item to cart
                addItemToCart(
                    variant.product_id,
                    variant.variant_id,
                    variant.display_name,
                    variant.size,
                    variant.price,
                    variant.allow_loose,
                    variant.loose_price,
                    variant.loose_units
                );

                // Update Last Scanned product info
                feedbackContent.innerHTML = `
                <div style="display:flex; align-items:flex-start; justify-content:space-between; width:100%;">
                    <div>
                        <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.7rem; padding:0.15rem 0.4rem; margin-bottom:0.4rem; display:inline-block;">
                            ${escapeHtml(variant.category_name)}
                        </span>
                        <div class="feedback-name">${escapeHtml(variant.display_name)}</div>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-size:0.7rem; color:var(--text-muted); display:block;">UNIT PRICE</span>
                        <span class="feedback-price">₹${variant.price.toFixed(2)}</span>
                    </div>
                </div>
                <div class="feedback-meta">
                    <div style="font-size:0.8rem; color:var(--text-secondary);">
                        <i class="fa-solid fa-boxes-stacked" style="color:var(--accent-color); margin-right:0.25rem;"></i>
                        Stock: <span style="font-weight:700;">${variant.stock} available</span>
                    </div>
                    <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;">
                        Barcode: ${escapeHtml(barcodeValue)}
                    </div>
                </div>
            `;


                playBeep(800, 100);
                showBarcodeToast(`Added: ${variant.display_name}`, 'success');
            } else {
                // Unrecognized barcode
                playBeep(220, 250);
                showBarcodeToast(`Unregistered Barcode: ${barcodeValue}`, 'danger');

                feedbackContent.innerHTML = `
                <div style="text-align:center; color:var(--danger); font-weight:700;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>
                    Barcode not registered in database: ${escapeHtml(barcodeValue)}
                </div>
            `;
            }

            document.getElementById('barcodeInput').value = '';
        }

        function goToCheckout() {
            if (cart.length === 0) {
                alert('Cart is empty.');
                return;
            }
            window.location.href = 'billing-cart.php';
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

        // =========================================================
        // PARK & RESUME ORDER STATE LOGIC (PHASE 2)
        // =========================================================
        function getParkedOrders() {
            try {
                return JSON.parse(localStorage.getItem('pos_parked_orders')) || [];
            } catch (e) {
                return [];
            }
        }

        function saveParkedOrders(orders) {
            localStorage.setItem('pos_parked_orders', JSON.stringify(orders));
            updateParkedCountBadge();
        }

        function updateParkedCountBadge() {
            const orders = getParkedOrders();
            const badge = document.getElementById('parkedCountBadge');
            if (badge) {
                if (orders.length > 0) {
                    badge.innerText = orders.length;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        function parkCurrentOrder() {
            if (cart.length === 0) {
                showBarcodeToast('Cannot park an empty cart', 'danger');
                return;
            }

            // Get customer details from localStorage if they exist
            const customerName = localStorage.getItem('orange_billing_customer_name') || '';
            const customerPhone = localStorage.getItem('orange_billing_customer_phone') || '';

            // Generate temporary description
            let desc = customerName ? customerName : 'Walk-in Client';
            if (customerPhone) desc += ` (${customerPhone})`;

            // Prompt user for optional name/note
            const note = prompt('Enter a name/note for this parked cart:', desc);
            if (note === null) return; // user cancelled

            const orders = getParkedOrders();
            orders.push({
                id: Date.now(),
                label: note.trim() || `Order #${orders.length + 1}`,
                cart: cart,
                customer_name: customerName,
                customer_phone: customerPhone,
                timestamp: new Date().toLocaleString(),
                total: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0)
            });

            saveParkedOrders(orders);

            // Clear current cart & customer
            cart = [];
            saveCartState();
            renderCart();
            
            localStorage.removeItem('orange_billing_customer_name');
            localStorage.removeItem('orange_billing_customer_phone');
            localStorage.removeItem('orange_billing_discount');

            showBarcodeToast('Order parked successfully', 'success');
            if (typeof playBeep === 'function') playBeep(600, 150);
        }

        function openParkedOrdersModal() {
            renderParkedOrders();
            openModal('parkedOrdersModal');
        }

        function renderParkedOrders() {
            const listEl = document.getElementById('parkedOrdersList');
            if (!listEl) return;

            const orders = getParkedOrders();
            if (orders.length === 0) {
                listEl.innerHTML = `
                    <div style="text-align:center; padding:2rem; color:var(--text-muted);">
                        <i class="fa-solid fa-folder-open" style="font-size:2rem; opacity:0.3; margin-bottom:0.5rem; display:block;"></i>
                        No parked orders found.
                    </div>
                `;
                return;
            }

            let html = '';
            orders.forEach(order => {
                const itemNames = order.cart.map(i => `${i.name} (x${i.quantity})`).join(', ');
                html += `
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; background:rgba(255,255,255,0.015); border:1px solid var(--border-color); border-radius:var(--border-radius-md); padding:0.6rem 0.75rem;">
                        <div style="flex-grow:1; min-width:0;">
                            <div style="font-weight:700; font-size:0.85rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(order.label)}</div>
                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:0.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(itemNames)}</div>
                            <div style="display:flex; gap:0.5rem; font-size:0.65rem; color:var(--text-muted); margin-top:0.2rem;">
                                <span><i class="fa-solid fa-clock"></i> ${escapeHtml(order.timestamp)}</span>
                                <span>•</span>
                                <span style="color:var(--accent-color); font-weight:600;">₹${order.total.toFixed(2)}</span>
                            </div>
                        </div>
                        <div style="display:flex; gap:0.35rem; flex-shrink:0;">
                            <button onclick="resumeParkedOrder(${order.id})" class="btn btn-primary" style="padding:0.25rem 0.5rem; font-size:0.7rem; height:26px; font-weight:600; display:inline-flex; align-items:center; gap:0.2rem;">
                                <i class="fa-solid fa-play"></i> Resume
                            </button>
                            <button onclick="discardParkedOrder(${order.id})" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.7rem; height:26px; color:var(--danger); border-color:rgba(255,71,87,0.2); display:inline-flex; align-items:center; gap:0.2rem;">
                                <i class="fa-solid fa-trash-can"></i> Discard
                            </button>
                        </div>
                    </div>
                `;
            });
            listEl.innerHTML = html;
        }

        function resumeParkedOrder(id) {
            if (cart.length > 0) {
                const proceed = confirm('Resuming this order will overwrite your current cart. Do you want to park the current cart first? Click OK to park current cart first, or CANCEL to replace current cart.');
                if (proceed) {
                    parkCurrentOrder();
                }
            }

            const orders = getParkedOrders();
            const idx = orders.findIndex(o => o.id === id);
            if (idx === -1) return;

            const order = orders[idx];
            cart = order.cart;
            saveCartState();
            renderCart();

            if (order.customer_name) localStorage.setItem('orange_billing_customer_name', order.customer_name);
            if (order.customer_phone) localStorage.setItem('orange_billing_customer_phone', order.customer_phone);

            // Remove from parked
            orders.splice(idx, 1);
            saveParkedOrders(orders);

            closeModal('parkedOrdersModal');
            showBarcodeToast('Order resumed successfully', 'success');
            if (typeof playBeep === 'function') playBeep(800, 100);

            // Return focus to scanner
            const barcodeInput = document.getElementById('barcodeInput');
            if (barcodeInput) barcodeInput.focus();
        }

        function discardParkedOrder(id) {
            if (!confirm('Are you sure you want to discard this parked order?')) return;

            const orders = getParkedOrders();
            const idx = orders.findIndex(o => o.id === id);
            if (idx === -1) return;

            orders.splice(idx, 1);
            saveParkedOrders(orders);
            renderParkedOrders();
            showBarcodeToast('Parked order discarded', 'success');
        }

        // DOM ready setup
        document.addEventListener('DOMContentLoaded', () => {
            // Save current terminal source page to localStorage
            localStorage.setItem('orange_billing_source', 'barcode-billing.php');

            const barcodeInput = document.getElementById('barcodeInput');
            const scannerBox = document.getElementById('scannerBox');
            const focusStatusDot = document.getElementById('focusStatusDot');
            const focusStatusText = document.getElementById('focusStatusText');

            if (barcodeInput) {
                barcodeInput.focus();

                // Focus states styling
                barcodeInput.addEventListener('focus', () => {
                    scannerBox.classList.add('active-focus');
                    focusStatusDot.style.background = '#2ed573';
                    focusStatusText.innerText = 'Scanner Ready & Active';
                });

                barcodeInput.addEventListener('blur', () => {
                    scannerBox.classList.remove('active-focus');
                    focusStatusDot.style.background = '#ff4757';
                    focusStatusText.innerText = 'Tap Scanner Area to Activate';
                });

                // Focus lock listener: keep scanner input focused
                document.addEventListener('click', (e) => {
                    // If a modal is open or user is focusing an input within a modal, let them
                    const active = document.activeElement;
                    if (active && (
                        active.tagName === 'INPUT' ||
                        active.tagName === 'TEXTAREA' ||
                        active.tagName === 'SELECT' ||
                        active.getAttribute('contenteditable') === 'true'
                    )) {
                        return;
                    }
                    barcodeInput.focus();
                });

                // Fast vs slow typing detection (auto-scan support)
                let lastKeyTime = 0;
                let scannerDebounceTimer = null;
                const SCANNER_SPEED_THRESHOLD_MS = 80;
                const SCANNER_DEBOUNCE_MS = 120;

                barcodeInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(scannerDebounceTimer);
                        const val = barcodeInput.value.trim();
                        if (val !== '') processBarcodeValue(val);
                    }
                    lastKeyTime = Date.now();
                });

                barcodeInput.addEventListener('input', () => {
                    clearTimeout(scannerDebounceTimer);
                    const now = Date.now();
                    const timeSinceLast = now - lastKeyTime;

                    if (timeSinceLast < SCANNER_SPEED_THRESHOLD_MS || timeSinceLast === now) {
                        scannerDebounceTimer = setTimeout(() => {
                            const val = barcodeInput.value.trim();
                            if (val.length >= 4) { // Guard for min barcode length
                                processBarcodeValue(val);
                            }
                        }, SCANNER_DEBOUNCE_MS);
                    }
                });

                barcodeInput.addEventListener('paste', () => {
                    clearTimeout(scannerDebounceTimer);
                    scannerDebounceTimer = setTimeout(() => {
                        const val = barcodeInput.value.trim();
                        if (val !== '') processBarcodeValue(val);
                    }, 50);
                });
            }

            // Initialize sound settings button state
            updateMuteButtonUI();

            // Keyboard Shortcuts Handler (Phase 1)
            document.addEventListener('keydown', (e) => {
                const active = document.activeElement;
                const isCustomInput = active && (
                    active.tagName === 'INPUT' ||
                    active.tagName === 'TEXTAREA' ||
                    active.tagName === 'SELECT'
                ) && active.id !== 'barcodeInput';

                // F2 to focus scanner input anytime
                if (e.key === 'F2') {
                    e.preventDefault();
                    if (barcodeInput) {
                        barcodeInput.focus();
                        barcodeInput.select();
                    }
                    return;
                }

                // ESC to close modal or clear & focus scanner
                if (e.key === 'Escape') {
                    const openModalEl = document.querySelector('.modal.active, .modal[style*="display: block"]');
                    if (openModalEl) {
                        e.preventDefault();
                        if (typeof closeModal === 'function' && openModalEl.id) {
                            closeModal(openModalEl.id);
                        }
                        if (barcodeInput) barcodeInput.focus();
                    } else if (barcodeInput) {
                        barcodeInput.value = '';
                        barcodeInput.focus();
                    }
                    return;
                }

                // If editing another form input (like custom item name/price), skip function hotkeys
                if (isCustomInput) return;

                // F4 to open Custom Item modal
                if (e.key === 'F4') {
                    e.preventDefault();
                    openModal('customItemModal');
                    setTimeout(() => {
                        const customName = document.getElementById('customItemName');
                        if (customName) customName.focus();
                    }, 100);
                }

                // F8 to clear cart
                if (e.key === 'F8') {
                    e.preventDefault();
                    clearCart();
                }

                // F9 for Quick Cash Checkout
                if (e.key === 'F9') {
                    e.preventDefault();
                    if (cart.length === 0) {
                        showBarcodeToast('Cart is empty', 'danger');
                        return;
                    }
                    localStorage.setItem('orange_billing_payment_method', 'Cash');
                    goToCheckout();
                }

                // F10 or Ctrl+Enter to proceed to checkout
                if (e.key === 'F10' || (e.ctrlKey && e.key === 'Enter')) {
                    e.preventDefault();
                    goToCheckout();
                }
            });

            loadCart();
        });
    </script>

<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>