<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();



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

function get_variant_effective_stock($v, $all_variants) {
    return (float)$v['stock_quantity'];
}

function format_variant_stock_badge($v) {
    $stock = (float)$v['stock_quantity'];
    if ($stock <= 0) {
        return '<span class="badge" style="background:rgba(255,71,87,0.08); color:var(--danger); font-size:0.7rem; padding:0.15rem 0.4rem; border:1px solid rgba(255,71,87,0.15); display:inline-block;">Out of Stock</span>';
    }

    if ($v['allow_loose']) {
        $units_per_whole = (float)$v['loose_units_per_whole'];
        $whole_packets = floor($stock);
        $fraction = $stock - $whole_packets;
        // round to prevent floating point imprecision issues (e.g. 0.94999999)
        $loose_units = round($fraction * $units_per_whole);
        
        // If rounding results in loose units equal to units_per_whole, adjust
        if ($loose_units >= $units_per_whole) {
            $whole_packets += 1;
            $loose_units = 0;
        }

        $parts = [];
        if ($whole_packets > 0) {
            $parts[] = $whole_packets . ' Pack' . ($whole_packets > 1 ? 's' : '');
        }
        if ($loose_units > 0) {
            $parts[] = $loose_units . ' Loose';
        }
        
        $label = implode(' + ', $parts);
        if (empty($label)) {
            $label = '0 available';
        } else {
            $label = $label . ' available';
        }
        
        return '<span class="badge" style="background:rgba(46,213,115,0.08); color:var(--success); font-size:0.7rem; padding:0.15rem 0.4rem; border:1px solid rgba(46,213,115,0.15); display:inline-block;" title="' . number_format($stock, 2) . ' Packets">' . $label . '</span>';
    } else {
        return '<span class="badge" style="background:rgba(46,213,115,0.08); color:var(--success); font-size:0.7rem; padding:0.15rem 0.4rem; border:1px solid rgba(46,213,115,0.15); display:inline-block;">' . number_format($stock, 2) . ' available</span>';
    }
}
?>

<style>
    .pos-container {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
        height: calc(100vh - 80px);
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
        align-content: start;
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
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <div style="position:relative; flex-grow:1;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted);"></i>
                <input type="text" id="searchBar" class="form-control" placeholder="Search products..." style="padding-left:35px; width:100%; height:42px;">
            </div>
            <div style="position:relative; width:220px;">
                <i class="fa-solid fa-barcode" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--accent-color);"></i>
                <input type="text" id="barcodeInput" class="form-control" placeholder="Scan barcode, press Enter..." style="padding-left:35px; width:100%; height:42px; border-color:rgba(255,107,53,0.4);">
            </div>
            <button onclick="openModal('customItemModal')" class="btn btn-secondary" style="white-space:nowrap; height:42px; display:inline-flex; align-items:center; gap:0.35rem;">
                <i class="fa-solid fa-plus-circle"></i> Custom Item
            </button>
        </div>
        <!-- Barcode scan pending confirmation strip -->
        <div id="barcodePendingStrip" style="display:none; margin-top:0.5rem; background:rgba(255,107,53,0.08); border:1px solid rgba(255,107,53,0.3); border-radius:8px; padding:0.6rem 1rem; display:none; align-items:center; gap:1rem; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <i class="fa-solid fa-barcode" style="color:var(--accent-color); font-size:1.2rem;"></i>
                <div>
                    <div id="barcodePendingName" style="font-weight:700; color:var(--text-primary); font-size:0.9rem;"></div>
                    <div id="barcodePendingPrice" style="font-size:0.78rem; color:var(--accent-color); margin-top:0.1rem;"></div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <span style="font-size:0.78rem; color:var(--text-muted);">Press <kbd style="background:var(--bg-control); border:1px solid var(--border-color); border-radius:4px; padding:1px 5px; font-size:0.75rem;">Enter</kbd> to add&nbsp;</span>
                <button id="barcodePendingAdd" class="btn btn-primary" style="height:34px; padding:0 0.9rem; font-size:0.8rem;">
                    <i class="fa-solid fa-cart-plus"></i> Add to Cart
                </button>
                <button id="barcodePendingCancel" class="btn btn-secondary" style="height:34px; padding:0 0.65rem; font-size:0.8rem; color:var(--danger); border-color:rgba(255,71,87,0.25);">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
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
                ?>
                <?php if ($has_vars): ?>
                    <?php foreach ($prod_variants[$p['id']] as $v): 
                        $price = $v['price'] !== null ? (float)$v['price'] : (float)$p['base_price'];
                        $stock = get_variant_effective_stock($v, $variants);
                    ?>
                        <div class="prod-card" 
                             data-category="<?= $p['category_id'] ?>" 
                             data-name="<?= htmlspecialchars(strtolower($p['product_name'] . ' ' . $v['size']), ENT_QUOTES) ?>"
                             data-barcode="<?= htmlspecialchars($v['barcode'] ?? '', ENT_QUOTES) ?>"
                             data-product-id="<?= $p['id'] ?>"
                             data-variant-id="<?= $v['id'] ?>"
                             data-display-name="<?= htmlspecialchars($p['product_name'] . ' (' . $v['size'] . ')', ENT_QUOTES) ?>"
                             data-size="<?= htmlspecialchars($v['size'], ENT_QUOTES) ?>"
                             data-price="<?= $price ?>"
                             data-allow-loose="<?= (int)$v['allow_loose'] ?>"
                             data-loose-price="<?= $v['loose_price'] !== null ? (float)$v['loose_price'] : 'null' ?>"
                             data-loose-units="<?= (float)$v['loose_units_per_whole'] ?>"
                             onclick='addItemToCart(<?= $p['id'] ?>, <?= $v['id'] ?>, <?= htmlspecialchars(json_encode($p['product_name'] . ' (' . $v['size'] . ')'), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($v['size']), ENT_QUOTES) ?>, <?= $price ?>, <?= (int)$v['allow_loose'] ?>, <?= $v['loose_price'] !== null ? (float)$v['loose_price'] : 'null' ?>, <?= (float)$v['loose_units_per_whole'] ?>)'>
                            
                            <?php if (!empty($p['image_path'])): ?>
                                <div style="width:100%; height:90px; border-radius:var(--border-radius-sm); overflow:hidden; border:1px solid var(--border-color); margin-bottom:0.25rem;">
                                    <img src="../<?= h($p['image_path']) ?>" alt="<?= h($p['product_name']) ?>" style="width:100%; height:100%; object-fit:cover;">
                                </div>
                            <?php else: ?>
                                <div style="width:100%; height:90px; border-radius:var(--border-radius-sm); background:var(--bg-control); display:flex; align-items:center; justify-content:center; color:var(--text-muted); border:1px solid var(--border-color); margin-bottom:0.25rem;">
                                    <i class="fa-solid fa-image" style="font-size:1.5rem;"></i>
                                </div>
                            <?php endif; ?>

                            <div style="flex-grow:1; display:flex; flex-direction:column; justify-content:space-between; gap:0.25rem;">
                                <div>
                                    <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.7rem; padding:0.15rem 0.4rem; margin-bottom:0.25rem; display:inline-block;">
                                        <?= h($p['category_name']) ?>
                                    </span>
                                    <div class="prod-title" style="min-height:32px; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;"><?= h($p['product_name']) ?> <span style="color:var(--text-secondary); font-size:0.8rem; font-weight:normal;">(<?= h($v['size']) ?>)</span></div>
                                    <div style="margin-top:0.2rem; margin-bottom:0.25rem;">
                                        <?= format_variant_stock_badge($v) ?>
                                    </div>
                                </div>
                                
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span class="prod-price" style="font-size:1rem;"><?= format_price($price) ?></span>
                                    <span style="font-size:0.7rem; color:var(--text-muted);"><i class="fa-solid fa-plus-circle"></i> Add</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="prod-card" 
                         data-category="<?= $p['category_id'] ?>" 
                         data-name="<?= htmlspecialchars(strtolower($p['product_name']), ENT_QUOTES) ?>"
                         onclick='addItemToCart(<?= $p['id'] ?>, null, <?= htmlspecialchars(json_encode($p['product_name']), ENT_QUOTES) ?>, null, <?= $p['base_price'] ?>)'>
                        
                        <?php if (!empty($p['image_path'])): ?>
                            <div style="width:100%; height:90px; border-radius:var(--border-radius-sm); overflow:hidden; border:1px solid var(--border-color); margin-bottom:0.25rem;">
                                <img src="../<?= h($p['image_path']) ?>" alt="<?= h($p['product_name']) ?>" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                        <?php else: ?>
                            <div style="width:100%; height:90px; border-radius:var(--border-radius-sm); background:var(--bg-control); display:flex; align-items:center; justify-content:center; color:var(--text-muted); border:1px solid var(--border-color); margin-bottom:0.25rem;">
                                <i class="fa-solid fa-image" style="font-size:1.5rem;"></i>
                            </div>
                        <?php endif; ?>

                        <div style="flex-grow:1; display:flex; flex-direction:column; justify-content:space-between; gap:0.25rem;">
                            <div>
                                <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.7rem; padding:0.15rem 0.4rem; margin-bottom:0.25rem; display:inline-block;">
                                    <?= h($p['category_name']) ?>
                                </span>
                                <div class="prod-title" style="min-height:32px; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;"><?= h($p['product_name']) ?></div>
                            </div>
                            
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span class="prod-price" style="font-size:1rem;"><?= format_price($p['base_price']) ?></span>
                                <span style="font-size:0.7rem; color:var(--text-muted);"><i class="fa-solid fa-plus-circle"></i> Add</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    </div>
</div>

<!-- Floating Cart Button -->
<div id="floatingCartBtn" onclick="openCartModal()" style="position: fixed; bottom: 2.5rem; right: 2.5rem; background: var(--accent-color); color: #fff; border-radius: 50px; padding: 0.85rem 1.75rem; display: flex; align-items: center; gap: 1rem; cursor: pointer; box-shadow: 0 8px 30px rgba(255, 107, 53, 0.4); z-index: 999; font-weight: 600; transition: transform var(--transition-fast); border: 2px solid rgba(255,255,255,0.1); display: none;">
    <i class="fa-solid fa-cart-shopping" style="font-size: 1.25rem;"></i>
    <span>Cart: <span id="floatingCartCount">0</span> items</span>
    <span style="background: rgba(255,255,255,0.25); padding: 0.15rem 0.5rem; border-radius: 20px; font-size: 0.85rem; font-weight: 700;" id="floatingCartTotal">₹0.00</span>
</div>

<!-- ============================================================
     MODAL: Cart Preview / Selected Items
     ============================================================ -->
<div id="cartPreviewModal" class="modal">
    <div class="modal-content" style="max-width: 620px;">
        <button class="modal-close" onclick="closeModal('cartPreviewModal')">&times;</button>
        <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <span style="display:flex; align-items:center; gap:0.5rem;">
                <i class="fa-solid fa-cart-shopping" style="color:var(--accent-color);"></i> Selected Items
            </span>
            <button onclick="clearCart()" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem; color:var(--danger); border-color:rgba(255, 71, 87, 0.2);">
                <i class="fa-solid fa-trash-can"></i> Clear Cart
            </button>
        </h3>
        
        <div id="modalCartItemsList" style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 350px; overflow-y: auto; margin-bottom: 1.5rem; padding-right: 0.25rem;">
            <!-- Dynamic items list -->
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--border-color); padding-top: 1rem; margin-bottom: 1.5rem;">
            <span style="font-weight: 600; color: var(--text-secondary);">Subtotal:</span>
            <span id="modalCartSubtotal" style="font-size: 1.3rem; font-weight: 800; color: var(--accent-color);">₹0.00</span>
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
            <button type="button" onclick="closeModal('cartPreviewModal')" class="btn btn-secondary">Continue Shopping</button>
            <button type="button" onclick="goToCheckout()" class="btn btn-success" style="box-shadow: 0 4px 12px rgba(46, 213, 115, 0.2); font-weight:600;">
                <i class="fa-solid fa-circle-check"></i> Proceed to Checkout
            </button>
        </div>
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
<div id="barcodeToastContainer" style="position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px;"></div>

<script>
    // POS State
    let cart = [];
    let selectedCategoryId = 0;

    // Audio Feedback & Toasts
    let audioCtx = null;
    function playBeep(freq, duration) {
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
        } catch(e) {
            console.error("Audio API ignored", e);
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

    // Elements
    const searchBar = document.getElementById('searchBar');
    const productGrid = document.getElementById('productGrid');
    const floatingCartBtn = document.getElementById('floatingCartBtn');
    const floatingCartCount = document.getElementById('floatingCartCount');
    const floatingCartTotal = document.getElementById('floatingCartTotal');

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
        
        // Search live filter
        searchBar.addEventListener('input', filterProducts);
              // Barcode scanning listener — two-step flow:
        // Step 1: Enter populates a confirmation strip
        // Step 2: User presses Enter again (or clicks Add button) to confirm add to cart
        const barcodeInput = document.getElementById('barcodeInput');
        const pendingStrip = document.getElementById('barcodePendingStrip');
        const pendingName = document.getElementById('barcodePendingName');
        const pendingPrice = document.getElementById('barcodePendingPrice');
        const pendingAdd = document.getElementById('barcodePendingAdd');
        const pendingCancel = document.getElementById('barcodePendingCancel');

        let pendingCard = null; // Holds the matched card from scan

        function clearPendingBarcode() {
            pendingCard = null;
            barcodeInput.value = '';
            pendingStrip.style.display = 'none';
            pendingName.textContent = '';
            pendingPrice.textContent = '';
        }

        function confirmPendingAdd() {
            if (!pendingCard) return;
            const productId = parseInt(pendingCard.dataset.productId);
            const variantId = parseInt(pendingCard.dataset.variantId);
            const displayName = pendingCard.dataset.displayName;
            const size = pendingCard.dataset.size;
            const price = parseFloat(pendingCard.dataset.price);
            const allowLoose = parseInt(pendingCard.dataset.allowLoose);
            const loosePrice = pendingCard.dataset.loosePrice === 'null' ? null : parseFloat(pendingCard.dataset.loosePrice);
            const looseUnits = parseFloat(pendingCard.dataset.looseUnits);

            addItemToCart(productId, variantId, displayName, size, price, allowLoose, loosePrice, looseUnits);
            playBeep(800, 100);
            showBarcodeToast(`Added: ${displayName}`, 'success');
            clearPendingBarcode();
            barcodeInput.focus();
        }

        if (barcodeInput) {
            barcodeInput.focus();

            // Keep barcode input focused unless user intentionally clicks another control
            document.addEventListener('click', (e) => {
                const clickedEl = e.target;
                const isOtherInput = clickedEl.tagName === 'INPUT' || clickedEl.tagName === 'TEXTAREA' || clickedEl.tagName === 'SELECT';
                // Don't steal focus if clicking the pending confirm/cancel buttons
                if (clickedEl === pendingAdd || clickedEl === pendingCancel || clickedEl.closest('#barcodePendingStrip')) return;
                if (!isOtherInput) barcodeInput.focus();
            });

            barcodeInput.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    clearPendingBarcode();
                    return;
                }

                if (e.key === 'Enter') {
                    e.preventDefault();

                    // Step 2: if a product is already pending, confirm-add it on second Enter
                    if (pendingCard) {
                        confirmPendingAdd();
                        return;
                    }

                    // Step 1: look up barcode and show pending strip
                    const barcodeValue = barcodeInput.value.trim();
                    if (barcodeValue === '') return;

                    const card = document.querySelector(`.prod-card[data-barcode="${barcodeValue}"]`);
                    if (card) {
                        pendingCard = card;
                        const displayName = card.dataset.displayName;
                        const price = parseFloat(card.dataset.price);
                        const allowLoose = parseInt(card.dataset.allowLoose);
                        const loosePrice = card.dataset.loosePrice === 'null' ? null : parseFloat(card.dataset.loosePrice);
                        pendingName.textContent = displayName;
                        pendingPrice.textContent = allowLoose
                            ? `Whole: ₹${price.toFixed(2)}  |  Loose: ₹${loosePrice !== null ? loosePrice.toFixed(2) : '—'} per unit`
                            : `Price: ₹${price.toFixed(2)}`;
                        pendingStrip.style.display = 'flex';
                        playBeep(600, 80);
                        // Clear the raw barcode from input so it's clean
                        barcodeInput.value = '';
                        barcodeInput.placeholder = 'Press Enter to add, or scan next...';
                    } else {
                        playBeep(220, 250);
                        showBarcodeToast(`Barcode not found: ${barcodeValue}`, 'danger');
                        barcodeInput.value = '';
                    }
                }
            });

            // Confirm add button
            if (pendingAdd) {
                pendingAdd.addEventListener('click', () => {
                    confirmPendingAdd();
                    barcodeInput.focus();
                });
            }

            // Cancel pending
            if (pendingCancel) {
                pendingCancel.addEventListener('click', () => {
                    clearPendingBarcode();
                    barcodeInput.placeholder = 'Scan barcode, press Enter...';
                    barcodeInput.focus();
                });
            }
        }
        
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
    // RENDER FLOATING CART & MODAL CART
    // =========================================================
    function renderCart() {
        if (cart.length === 0) {
            floatingCartBtn.style.display = 'none';
            floatingCartCount.innerText = '0';
            floatingCartTotal.innerText = '₹0.00';
            
            // If the modal is visible, we update/close it
            renderModalCart();
            return;
        }

        let totalQty = 0;
        let subtotal = 0.00;
        
        cart.forEach(item => {
            totalQty += item.quantity;
            subtotal += item.price * item.quantity;
        });

        floatingCartBtn.style.display = 'flex';
        floatingCartCount.innerText = totalQty;
        floatingCartTotal.innerText = `₹${subtotal.toFixed(2)}`;

        // Render modal cart list reactively if open
        renderModalCart();
    }

    function openCartModal() {
        renderModalCart();
        openModal('cartPreviewModal');
    }

    function renderModalCart() {
        const listContainer = document.getElementById('modalCartItemsList');
        const subtotalContainer = document.getElementById('modalCartSubtotal');
        if (!listContainer) return;

        if (cart.length === 0) {
            listContainer.innerHTML = `
                <div style="text-align:center; padding:3rem; color:var(--text-muted);">
                    <i class="fa-solid fa-basket-shopping" style="font-size:2.5rem; opacity:0.3; margin-bottom:0.75rem; display:block;"></i>
                    Cart is empty. Add products from the catalog.
                </div>
            `;
            subtotalContainer.innerText = '₹0.00';
            closeModal('cartPreviewModal');
            return;
        }

        let subtotal = 0;
        let html = '';
        
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            subtotal += itemTotal;
            
            let sellTypeSelectorHtml = '';
            if (parseInt(item.allow_loose) === 1) {
                const loosePriceVal = parseFloat(item.loose_price || 0);
                const wholePriceVal = parseFloat(item.whole_price || 0);
                sellTypeSelectorHtml = `
                    <div style="margin-top: 0.4rem; display: flex; gap: 0.75rem; align-items: center;">
                        <span style="font-size:0.7rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.03em;">Sell Type:</span>
                        <label style="font-size:0.75rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.25rem; margin:0;">
                            <input type="radio" name="sell_type_${index}" value="whole" ${item.sell_type === 'whole' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'whole')" style="accent-color:var(--accent-color); cursor:pointer;">
                            Whole Pack (₹${wholePriceVal.toFixed(2)})
                        </label>
                        <label style="font-size:0.75rem; color:var(--text-secondary); cursor:pointer; display:inline-flex; align-items:center; gap:0.25rem; margin:0;">
                            <input type="radio" name="sell_type_${index}" value="loose" ${item.sell_type === 'loose' ? 'checked' : ''} onchange="changeItemSellType(${index}, 'loose')" style="accent-color:var(--accent-color); cursor:pointer;">
                            Loose Item (₹${loosePriceVal.toFixed(2)})
                        </label>
                    </div>
                `;
            }

            html += `
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; background:rgba(255,255,255,0.015); border:1px solid var(--border-color); border-radius:var(--border-radius-sm); padding:0.6rem 0.75rem;">
                    <div style="flex-grow:1;">
                        <div style="font-weight:600; font-size:0.9rem; color:var(--text-primary);">${escapeHtml(item.name)}</div>
                        ${item.size ? `<div style="font-size:0.75rem; color:var(--text-muted);">Size: ${escapeHtml(item.size)}</div>` : ''}
                        <div style="font-size:0.85rem; color:var(--accent-color); font-weight:600; margin-top:0.15rem;">₹${item.price.toFixed(2)}</div>
                        ${sellTypeSelectorHtml}
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <button type="button" class="qty-btn" onclick="updateQty(${index}, -1)">-</button>
                        <span style="font-weight:600; min-width:18px; text-align:center; font-size:0.9rem;">${item.quantity}</span>
                        <button type="button" class="qty-btn" onclick="updateQty(${index}, 1)">+</button>
                        <button type="button" class="qty-btn" onclick="removeItem(${index})" style="background:rgba(255, 71, 87, 0.1); color:var(--danger); border-color:rgba(255,71,87,0.15); margin-left:0.25rem;">
                            <i class="fa-solid fa-trash-can" style="font-size:0.75rem;"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        listContainer.innerHTML = html;
        subtotalContainer.innerText = '₹' + subtotal.toFixed(2);
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

    function goToCheckout() {
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
