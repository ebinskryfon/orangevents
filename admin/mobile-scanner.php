<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_create');

$db = get_db_connection();
$user_id = $_SESSION['admin_id'];

// Check register session
$register_stmt = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
$register_stmt->execute(['user_id' => $user_id]);
$is_register_open = (bool) $register_stmt->fetch();

$cart_token = $_GET['cart_token'] ?? '';
?>

<style>
    .mobile-scanner-wrapper {
        max-width: 500px;
        margin: 0 auto;
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .mobile-header-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .mobile-scanner-box {
        background: #000;
        border-radius: 14px;
        overflow: hidden;
        position: relative;
        box-shadow: 0 8px 24px rgba(0,0,0,0.4);
    }

    .recent-scanned-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .flash-success {
        animation: flashGreen 0.6s ease-out;
    }

    @keyframes flashGreen {
        0% { background: rgba(46, 213, 115, 0.4); }
        100% { background: transparent; }
    }
</style>

<div class="mobile-scanner-wrapper">
    <!-- Header -->
    <div class="mobile-header-card">
        <div>
            <h2 style="font-size: 1.05rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 0.4rem; color: var(--text-primary);">
                <i class="fa-solid fa-mobile-screen-button" style="color: var(--accent-color);"></i>
                Mobile POS Scanner
            </h2>
            <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.15rem;" id="sessionTokenText">
                Pairing Token: <span style="font-family: monospace; font-weight: 700; color: var(--accent-color);"><?php echo htmlspecialchars($cart_token ?: 'Auto-Connected'); ?></span>
            </div>
        </div>
        <span class="badge" id="syncedCountBadge" style="background: rgba(255, 107, 53, 0.15); color: var(--accent-color); font-size: 0.8rem; font-weight: 800; padding: 0.3rem 0.6rem; border-radius: 20px;">
            0 Items in PC Cart
        </span>
    </div>

    <?php if (!$is_register_open): ?>
        <div class="alert alert-warning" style="font-size: 0.85rem; text-align: center;">
            <i class="fa-solid fa-lock"></i> Register is currently closed. Please open cash register on PC terminal first.
        </div>
    <?php endif; ?>

    <!-- Camera Widget Container -->
    <div id="mobileCameraScannerWidget"></div>

    <!-- Manual Barcode Input Card -->
    <div class="recent-scanned-card">
        <div style="font-size: 0.78rem; font-weight: 700; color: var(--text-secondary); display: flex; align-items: center; justify-content: space-between;">
            <span><i class="fa-solid fa-keyboard" style="color: var(--accent-color);"></i> Manual Barcode Entry</span>
            <span style="font-weight: 400; font-size: 0.7rem; color: var(--text-muted);">For damaged codes</span>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <input type="text" id="manualBarcodeInput" class="form-control form-control-sm" placeholder="Type barcode number..." style="font-size: 0.85rem; font-weight: 700;">
            <button type="button" id="manualAddBtn" class="btn btn-primary btn-sm" style="background: var(--accent-color); border: none; padding: 0 0.8rem; font-weight: 700;">
                Add
            </button>
        </div>
    </div>

    <!-- Last Scanned Feedback Card -->
    <div class="recent-scanned-card" id="lastScannedCard">
        <div style="font-size: 0.78rem; font-weight: 700; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem;">
            <i class="fa-solid fa-circle-info" style="color: var(--accent-color);"></i> Last Scanned Feedback
        </div>
        <div id="lastScannedBody" style="font-size: 0.82rem; color: var(--text-muted); font-style: italic; text-align: center; padding: 0.35rem 0;">
            Scan a product barcode or QR code with phone camera...
        </div>
    </div>

    <!-- Cart Summary List -->
    <div class="recent-scanned-card">
        <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; justify-content: space-between;">
            <span><i class="fa-solid fa-cart-flatbed" style="color: var(--accent-color);"></i> Synced Active Cart</span>
            <span id="mobileCartSubtotal" style="color: var(--accent-color); font-weight: 800; font-size: 0.9rem;">₹0.00</span>
        </div>
        <div id="mobileCartItemsList" style="display: flex; flex-direction: column; gap: 0.35rem; max-height: 220px; overflow-y: auto;">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<script>
    const currentCartToken = <?php echo json_encode($cart_token); ?>;
    let localVersionHash = '';

    document.addEventListener('DOMContentLoaded', () => {
        // Initialize camera widget
        if (window.OrangeCameraUtils) {
            window.OrangeCameraUtils.initPosCameraScanner('mobileCameraScannerWidget', (scannedCode) => {
                sendBarcodeToApi(scannedCode);
            });
        }

        // Manual barcode input handler
        const manualInput = document.getElementById('manualBarcodeInput');
        const manualBtn = document.getElementById('manualAddBtn');

        function submitManual() {
            const val = manualInput.value.trim();
            if (val) {
                sendBarcodeToApi(val);
                manualInput.value = '';
            }
        }

        manualBtn.addEventListener('click', submitManual);
        manualInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') submitManual();
        });

        // Start polling active cart from DB
        fetchActiveCartState();
        setInterval(fetchActiveCartState, 1500);
    });

    async function sendBarcodeToApi(barcodeStr) {
        const cleaned = window.OrangeCameraUtils ? window.OrangeCameraUtils.cleanBarcode(barcodeStr) : barcodeStr.trim();
        if (!cleaned) return;

        const bodyData = new URLSearchParams();
        bodyData.append('action', 'add_item');
        bodyData.append('barcode', cleaned);
        bodyData.append('added_by', 'mobile_camera');
        if (currentCartToken) bodyData.append('cart_token', currentCartToken);

        const card = document.getElementById('lastScannedCard');
        const body = document.getElementById('lastScannedBody');

        try {
            const res = await fetch('../api/pos-cart-sync.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: bodyData
            });
            const data = await res.json();

            if (data.success) {
                if (window.OrangeCameraUtils) {
                    window.OrangeCameraUtils.playBeepSound('success');
                    window.OrangeCameraUtils.triggerHaptic(90);
                }
                card.classList.remove('flash-success');
                void card.offsetWidth; // trigger reflow
                card.classList.add('flash-success');

                body.innerHTML = `
                    <div style="color: #2ed573; font-weight: 700; font-size: 0.9rem;">
                        <i class="fa-solid fa-circle-check"></i> Scanned & Synced to PC!
                    </div>
                    <div style="font-weight: 800; color: var(--text-primary); font-size: 0.95rem;">${escapeHtml(data.added_item.product_name)} (${escapeHtml(data.added_item.size)})</div>
                    <div style="color: var(--accent-color); font-weight: 700;">Price: ₹${parseFloat(data.added_item.price).toFixed(2)}</div>
                `;
                fetchActiveCartState();
            } else {
                if (window.OrangeCameraUtils) window.OrangeCameraUtils.playBeepSound('error');
                body.innerHTML = `
                    <div style="color: #ff4757; font-weight: 700;">
                        <i class="fa-solid fa-circle-xmark"></i> Scan Failed: ${escapeHtml(data.error || 'Product not found')}
                    </div>
                `;
            }
        } catch (e) {
            console.error('API send error:', e);
            body.innerHTML = `<div style="color: #ff4757;">Network connection error. Check server status.</div>`;
        }
    }

    async function fetchActiveCartState() {
        let url = `../api/pos-cart-sync.php?action=get_cart&version_hash=${encodeURIComponent(localVersionHash)}`;
        if (currentCartToken) url += `&cart_token=${encodeURIComponent(currentCartToken)}`;

        try {
            const res = await fetch(url);
            const data = await res.json();

            if (!data.success) return;
            if (!data.changed) return; // Cart hasn't changed

            localVersionHash = data.version_hash;
            renderMobileCart(data);
        } catch (e) {}
    }

    function renderMobileCart(data) {
        document.getElementById('syncedCountBadge').textContent = `${data.item_count} Items in PC Cart`;
        document.getElementById('mobileCartSubtotal').textContent = `₹${parseFloat(data.grand_total).toFixed(2)}`;

        const listDiv = document.getElementById('mobileCartItemsList');
        if (!data.items || data.items.length === 0) {
            listDiv.innerHTML = `<div style="text-align: center; color: var(--text-muted); font-size: 0.75rem; font-style: italic; padding: 0.5rem;">Cart is currently empty.</div>`;
            return;
        }

        listDiv.innerHTML = data.items.map(item => `
            <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-control); padding: 0.4rem 0.6rem; border-radius: 6px; font-size: 0.78rem;">
                <div style="display: flex; flex-direction: column;">
                    <span style="font-weight: 700; color: var(--text-primary);">${escapeHtml(item.product_name)}</span>
                    <span style="font-size: 0.68rem; color: var(--text-muted);">${escapeHtml(item.size)} &bull; ₹${item.price.toFixed(2)} x ${item.quantity}</span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.35rem;">
                    ${item.added_by_device === 'mobile_camera' ? '<span class="badge" style="background: rgba(46, 213, 115, 0.15); color: #2ed573; font-size: 0.62rem;">Mobile Scan</span>' : ''}
                    <span style="font-weight: 800; color: var(--accent-color);">₹${item.line_total.toFixed(2)}</span>
                </div>
            </div>
        `).join('');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
