<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();
require_permission('billing_read');

$db = get_db_connection();
$id = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
if (!$id) {
    header('Location: billing.php');
    exit;
}

// Fetch billing order
$stmt_order = $db->prepare("SELECT * FROM billing_orders WHERE id = :id");
$stmt_order->execute(['id' => $id]);
$order = $stmt_order->fetch();
if (!$order) {
    header('Location: billing.php');
    exit;
}

// Fetch order items
$stmt_items = $db->prepare("SELECT * FROM billing_order_items WHERE order_id = :id ORDER BY id ASC");
$stmt_items->execute(['id' => $id]);
$items = $stmt_items->fetchAll();

// Fetch any returns associated with this order
$stmt_returns = $db->prepare("SELECT * FROM billing_returns WHERE order_id = :id ORDER BY id DESC");
$stmt_returns->execute(['id' => $id]);
$returns_list = $stmt_returns->fetchAll();

$total_returned_amount = 0;
foreach ($returns_list as $ret) {
    $total_returned_amount += (float)$ret['refund_amount'];
}
$has_returns = ($total_returned_amount > 0);
$net_invoice_amount = max(0, (float)$order['final_amount'] - $total_returned_amount);

// Fetch settings
$settings_res = $db->query("SELECT * FROM settings")->fetchAll();
$settings = [];
foreach ($settings_res as $row) {
    $settings[$row['key']] = $row['value'];
}
$is_upi_secure = is_upi_secure_and_valid();
$upi_payment_url = $is_upi_secure ? generate_upi_payment_url($net_invoice_amount, $order['invoice_number']) : false;

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* Control Bar */
    .receipt-control-bar {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 1rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }

    /* Screen Receipt Layout */
    .receipt-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        padding-bottom: 3rem;
    }

    .thermal-receipt {
        width: 80mm;
        min-height: 120mm;
        background: #ffffff;
        color: #000000;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        border-radius: 4px;
        padding: 15px;
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        line-height: 1.4;
    }

    .receipt-header {
        text-align: center;
        margin-bottom: 12px;
    }

    .receipt-logo-title {
        font-size: 16px;
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 2px;
        letter-spacing: 1px;
    }

    .receipt-subtitle {
        font-size: 10px;
        text-transform: uppercase;
        margin-bottom: 6px;
        color: #555555;
    }

    .receipt-contact {
        font-size: 9px;
        line-height: 1.3;
        margin-bottom: 6px;
        color: #333333;
    }

    .receipt-divider {
        border-top: 1px dashed #000000;
        margin: 8px 0;
    }

    .receipt-meta {
        font-size: 10px;
        margin-bottom: 8px;
    }

    .receipt-meta-row {
        display: flex;
        justify-content: space-between;
    }

    .receipt-table {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0;
    }

    .receipt-table th {
        font-weight: bold;
        text-align: left;
        border-bottom: 1px dashed #000000;
        padding-bottom: 4px;
        font-size: 10px;
        text-transform: uppercase;
    }

    .receipt-table td {
        padding: 4px 0;
        vertical-align: top;
    }

    .receipt-summary {
        width: 100%;
        margin-top: 6px;
    }

    .receipt-summary-row {
        display: flex;
        justify-content: space-between;
        padding: 2px 0;
    }

    .receipt-summary-total {
        font-size: 14px;
        font-weight: bold;
        border-top: 1px dashed #000000;
        border-bottom: 1px dashed #000000;
        padding: 6px 0;
        margin-top: 4px;
    }

    .receipt-footer {
        text-align: center;
        margin-top: 15px;
        font-size: 9px;
    }

    /* Toggle Visibility */
    .view-container.view-a4 .receipt-wrapper { display: none !important; }
    .view-container.view-thermal .invoice-paper { display: none !important; }

    /* Segmented Control styling */
    .segmented-control {
        display: flex;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border-color);
        padding: 3px;
        border-radius: 8px;
    }
    .segmented-control button {
        background: transparent;
        color: var(--text-secondary);
        border: none;
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .segmented-control button.active {
        background: var(--accent-gradient);
        color: #ffffff;
    }

    /* ── A4 Invoice Paper ──────────────────────────────── */
    .invoice-paper {
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto 2rem;
        background: #ffffff;
        color: #1a1a2e;
        font-family: 'Segoe UI', 'Inter', Arial, sans-serif;
        font-size: 11pt;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 8px 40px rgba(0,0,0,0.35);
        display: flex;
        flex-direction: column;
        text-align: left;
    }
    
    /* ── Header band ───────────────────────────────────── */
    .ri-header {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
        padding: 28px 32px 22px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        position: relative;
        overflow: hidden;
    }
    .ri-header::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #ff6b35, #ffa500, #ff6b35);
    }
    .ri-brand-name {
        font-size: 22pt;
        font-weight: 800;
        color: #ff6b35;
        letter-spacing: 1px;
        text-transform: uppercase;
        font-family: 'Outfit', sans-serif;
    }
    .ri-brand-sub {
        font-size: 8.5pt;
        color: rgba(255,255,255,0.6);
        margin-top: 3px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .ri-brand-contact {
        font-size: 8.5pt;
        color: rgba(255,255,255,0.60);
        margin-top: 6px;
        line-height: 1.6;
    }
    .ri-inv-label {
        text-align: right;
    }
    .ri-inv-label h2 {
        font-size: 18pt;
        font-weight: 800;
        color: #ffffff;
        margin: 0 0 4px;
        text-transform: uppercase;
        letter-spacing: 2px;
        font-family: 'Outfit', sans-serif;
    }
    .ri-inv-label .ri-inv-num {
        font-size: 10pt;
        color: #ff6b35;
        font-weight: 700;
    }
    .ri-inv-label .ri-inv-date {
        font-size: 8pt;
        color: rgba(255,255,255,0.5);
        margin-top: 4px;
    }
    
    /* ── Status chip ───────────────────────────────────── */
    .ri-status-chip {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 50px;
        font-size: 7.5pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 6px;
    }
    .ri-status-returned { background: rgba(34,197,94,0.2);  color: #22c55e; border: 1px solid #22c55e; }
    
    /* ── Client & period row ───────────────────────────── */
    .ri-meta-row {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 0;
        border-bottom: 2px solid #ff6b35;
    }
    .ri-meta-cell {
        padding: 14px 20px;
        border-right: 1px solid #e5e7eb;
    }
    .ri-meta-cell:last-child { border-right: none; }
    .ri-meta-label {
        font-size: 7.5pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #9ca3af;
        margin-bottom: 4px;
    }
    .ri-meta-value {
        font-size: 10.5pt;
        font-weight: 700;
        color: #1a1a2e;
    }
    .ri-meta-sub {
        font-size: 8.5pt;
        color: #6b7280;
        margin-top: 2px;
    }
    
    /* ── Items table ───────────────────────────────────── */
    .ri-body { padding: 20px 28px; flex: 1; }
    
    .ri-section-title {
        font-size: 8.5pt;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #ff6b35;
        border-bottom: 1.5px solid #ff6b35;
        padding-bottom: 5px;
        margin: 0 0 10px;
        font-family: 'Outfit', sans-serif;
    }
    
    .ri-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9.5pt;
        margin-bottom: 18px;
    }
    .ri-table thead tr {
        background: #1a1a2e;
        color: #ffffff;
    }
    .ri-table thead th {
        padding: 8px 12px;
        font-size: 8pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .ri-table tbody tr:nth-child(even) { background: #f9fafb; }
    .ri-table tbody td {
        padding: 8px 12px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
        color: #333;
    }
    
    /* ── Financial summary box ─────────────────────────── */
    .ri-bottom-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 20px;
        margin-top: 16px;
    }
    .ri-summary-box {
        background: #1a1a2e;
        border-radius: 8px;
        padding: 16px 18px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        color: #ffffff;
    }
    .ri-summary-row {
        display: flex;
        justify-content: space-between;
        font-size: 9pt;
        color: rgba(255,255,255,0.7);
    }
    .ri-summary-row.ri-summary-total {
        border-top: 1px dashed rgba(255,255,255,0.2);
        padding-top: 8px;
        font-size: 12pt;
        font-weight: 800;
        color: #ff6b35;
    }

    /* Print Styles */
    @media print {
        body, html {
            background: #ffffff !important;
            color: #000000 !important;
            height: auto !important;
            min-height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .sidebar, .receipt-control-bar, footer, aside, header {
            display: none !important;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
            min-height: auto !important;
            overflow: visible !important;
        }

        /* Hide non-active print layouts */
        .view-container.view-a4 .receipt-wrapper { display: none !important; }
        .view-container.view-thermal .invoice-paper { display: none !important; }

        /* A4 page configuration */
        .view-container.view-a4 .invoice-paper {
            width: 100% !important;
            max-width: 100% !important;
            min-height: auto !important;
            margin: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            display: block !important;
            overflow: visible !important;
        }
        
        /* Thermal page configuration */
        .view-container.view-thermal .receipt-wrapper {
            padding: 0 !important;
            display: block !important;
        }
        .view-container.view-thermal .thermal-receipt {
            width: 80mm !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            margin: 0 !important;
            padding: 5px !important;
            font-size: 11px !important;
            display: block !important;
        }

        @page {
            size: auto;
            margin: 10mm 0;
        }
    }
</style>

<!-- Control Bar -->
<div class="receipt-control-bar">
    <div style="display:flex; align-items:center; gap:0.75rem;">
        <a href="billing-invoices.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Archives
        </a>
        <a href="billing.php" class="btn btn-secondary">
            <i class="fa-solid fa-calculator"></i> POS Terminal
        </a>
        <span style="color:var(--text-secondary); font-weight:600;"><?= h($order['invoice_number']) ?></span>
    </div>
    
    <!-- View Switch Segmented Control -->
    <div class="segmented-control">
        <button onclick="setView('a4')" id="btnViewA4" class="active">
            <i class="fa-solid fa-file-invoice"></i> A4 Invoice
        </button>
        <button onclick="setView('thermal')" id="btnViewThermal">
            <i class="fa-solid fa-receipt"></i> Thermal Receipt
        </button>
    </div>

    <div style="display:flex; gap:0.5rem;">
        <?php if (has_permission('billing_update')): ?>
        <a href="edit-billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary" style="background: rgba(255, 165, 2, 0.12); color: var(--warning); border-color: rgba(255, 165, 2, 0.15); font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
            <i class="fa-solid fa-pen-to-square"></i> Edit
        </a>
        <?php endif; ?>
        <button onclick="shareWhatsApp()" class="btn btn-success" style="background-color: #25d366; border-color: #25d366; color: #ffffff;">
            <i class="fa-brands fa-whatsapp"></i> Share WhatsApp
        </button>
        <button onclick="downloadPDF()" class="btn btn-secondary">
            <i class="fa-solid fa-file-pdf"></i> Download PDF
        </button>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa-solid fa-print"></i> Print Invoice
        </button>
    </div>
</div>

<?php if (isset($_GET['edit_success'])): ?>
    <div style="background-color: var(--success); color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-top: 1.5rem; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600; width: 100%; max-width: 210mm;">
        <span><i class="fa-solid fa-circle-check"></i> POS Invoice has been updated successfully.</span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- Dual View Container -->
<div class="view-container view-a4" id="viewContainer" style="width: 100%; display: flex; flex-direction: column; align-items: center; margin-top: 1.5rem;">

    <!-- ── A4 Layout ── -->
    <div class="invoice-paper">
        <!-- Header Band -->
        <div class="ri-header">
            <div>
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 8px;">
                    <img src="../assets/images/logo.png" alt="Logo" style="max-height: 52px; width: auto; filter: brightness(0) invert(1);">
                    <div>
                        <div class="ri-brand-name"><?= h($settings['company_name'] ?? 'Orange Events') ?></div>
                        <div class="ri-brand-sub"><?= h($settings['company_subtitle'] ?? 'Premium Catering & Stage Decors') ?></div>
                    </div>
                </div>
                <div class="ri-brand-contact">
                    <?= h($settings['company_address'] ?? 'Thumpoly P.O, Alappuzha') ?><br>
                    Phone: <?= h($settings['company_phone'] ?? '9946731720') ?> | Email: <?= h($settings['company_email'] ?? 'orangedecorations@gmail.com') ?><br>
                    <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                        GSTIN: <?= h($settings['company_gstin']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ri-inv-label">
                <h2>INVOICE</h2>
                <div class="ri-inv-num"><?= h($order['invoice_number']) ?></div>
                <div class="ri-inv-date">Date: <?= date('d-m-Y h:i A', strtotime($order['created_at'])) ?></div>
                <div class="ri-status-chip ri-status-returned">PAID</div>
                <?php if ($has_returns): ?>
                    <div class="ri-status-chip" style="background:rgba(255, 71, 87, 0.15); color:#ff4757; border:1px solid #ff4757; margin-top:4px;">ITEM RETURNED</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Meta Details Row -->
        <div class="ri-meta-row">
            <div class="ri-meta-cell">
                <div class="ri-meta-label">Billed To</div>
                <div class="ri-meta-value"><?= h($order['customer_name'] ?: 'Walk-in Customer') ?></div>
                <?php if (!empty($order['customer_phone'])): ?>
                    <div class="ri-meta-sub">Phone: <?= h($order['customer_phone']) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['customer_address'])): ?>
                    <div class="ri-meta-sub" style="margin-top: 4px; line-height: 1.3;">Address: <?= nl2br(h($order['customer_address'])) ?></div>
                <?php endif; ?>
            </div>
            <div class="ri-meta-cell">
                <div class="ri-meta-label">Payment Information</div>
                <div class="ri-meta-value">
                    <?php if ($order['payment_method'] === 'Split' || !empty($order['payment_breakdown'])): ?>
                        <span style="color:var(--accent-color); font-weight:700;">SPLIT PAYMENT</span>
                        <div style="font-size:0.75rem; font-weight:normal; color:var(--text-muted); margin-top:2px;">
                            <?php
                            $bd_parts = [];
                            if (!empty($order['payment_breakdown'])) {
                                $bd = json_decode($order['payment_breakdown'], true);
                                if (is_array($bd)) {
                                    foreach ($bd as $m => $a) {
                                        if ((float)$a > 0) $bd_parts[] = h($m) . ': ₹' . number_format((float)$a, 2);
                                    }
                                }
                            }
                            if (empty($bd_parts)) {
                                if ($order['paid_cash'] > 0) $bd_parts[] = 'Cash: ₹' . number_format($order['paid_cash'], 2);
                                if ($order['paid_upi'] > 0)  $bd_parts[] = 'UPI: ₹' . number_format($order['paid_upi'], 2);
                                if ($order['paid_card'] > 0) $bd_parts[] = 'Card: ₹' . number_format($order['paid_card'], 2);
                            }
                            echo implode(' • ', $bd_parts);
                            ?>
                        </div>
                    <?php else: ?>
                        <?= h(strtoupper($order['payment_method'])) ?>
                    <?php endif; ?>
                </div>
                <div class="ri-meta-sub">Status: Fully Settled / Complete</div>
            </div>
            <div class="ri-meta-cell">
                <div class="ri-meta-label">Cashier / Staff</div>
                <div class="ri-meta-value"><?= h(ucfirst($_SESSION['admin_username'] ?? 'Admin')) ?></div>
                <div class="ri-meta-sub">POS Terminal 01</div>
            </div>
        </div>

        <!-- Items Body -->
        <div class="ri-body">
            <div class="ri-section-title">Order Items Details</div>
            <table class="ri-table">
                <thead>
                    <tr>
                        <th style="width: 8%; text-align: center;">SL</th>
                        <th style="text-align: left;">Item & Variant Description</th>
                        <th style="width: 15%; text-align: right;">Unit Price</th>
                        <th style="width: 12%; text-align: center;">Qty</th>
                        <th style="width: 18%; text-align: right;">Total Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sl = 1; foreach ($items as $item): ?>
                        <tr>
                            <td style="text-align: center; color: #6b7280;"><?= $sl++ ?></td>
                            <td>
                                <div style="font-weight: 700; color: #1a1a2e;"><?= h($item['product_name']) ?></div>
                                <?php if (!empty($item['variant_size']) || !empty($item['sell_type'])): ?>
                                    <div style="font-size: 8.5pt; color: #6b7280; margin-top: 2px;">
                                        <?php if (!empty($item['variant_size'])): ?>
                                            Size / Variant: <?= h($item['variant_size']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($item['sell_type'])): ?>
                                            <?= !empty($item['variant_size']) ? ' | ' : '' ?>Type: <?= h(ucfirst($item['sell_type'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">₹<?= number_format($item['price'], 2) ?></td>
                            <td style="text-align: center; font-weight: 600;"><?= $item['quantity'] ?></td>
                            <td style="text-align: right; font-weight: 700; color: #1a1a2e;">₹<?= number_format($item['total_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Summary Box & T&C -->
            <div class="ri-bottom-grid">
                <div>
                    <div style="font-size: 8.5pt; color: #6b7280; line-height: 1.6; padding-top: 10px;">
                        <strong>Terms & Conditions:</strong><br>
                        1. This is a computer generated invoice and requires no physical signature.<br>
                        2. Goods once sold are not returnable or exchangeable.<br>
                        3. For support or queries, contact us at <?= h($settings['company_phone'] ?? '9946731720') ?>.
                    </div>
                    <?php if ($is_upi_secure && !empty($upi_payment_url)): ?>
                        <div style="margin-top:10px; display:flex; align-items:center; gap:10px; background:#f8fafc; border:1px solid #e2e8f0; padding:8px; border-radius:6px;">
                            <div id="a4UpiQr" style="width:72px; height:72px; background:#fff; padding:2px; border:1px solid #cbd5e1; border-radius:4px; display:flex; align-items:center; justify-content:center;"></div>
                            <div style="font-size:8pt; color:#334155;">
                                <div style="font-weight:700; color:#0f172a; text-transform:uppercase; display:flex; align-items:center; gap:4px;">
                                    <i class="fa-solid fa-qrcode" style="color:#0284c7;"></i> Scan & Pay via UPI
                                </div>
                                <div style="font-weight:700; color:#ff6b35; margin-top:2px; font-family:monospace;"><?= h($settings['company_upi_id']) ?></div>
                                <div style="color:#64748b; font-size:7.5pt; margin-top:1px;">GPay, PhonePe, Paytm or any UPI app</div>
                                <div style="font-weight:800; color:#16a34a; margin-top:2px;">Payable: ₹<?= number_format($net_invoice_amount, 2) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="ri-summary-box">
                        <div class="ri-summary-row">
                            <span>Subtotal</span>
                            <span>₹<?= number_format($order['total_amount'], 2) ?></span>
                        </div>
                        <?php if ($order['discount_amount'] > 0): ?>
                            <div class="ri-summary-row" style="color: #ff6b35;">
                                <span>Discount</span>
                                <span>-₹<?= number_format($order['discount_amount'], 2) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="ri-summary-row ri-summary-total">
                            <span>Grand Total</span>
                            <span>₹<?= number_format($order['final_amount'], 2) ?></span>
                        </div>
                        <?php if ($has_returns): ?>
                            <div class="ri-summary-row" style="color: #ff4757; border-top:1px dashed #e5e7eb; padding-top:4px; margin-top:4px;">
                                <span>Returned Total</span>
                                <span>-₹<?= number_format($total_returned_amount, 2) ?></span>
                            </div>
                            <div class="ri-summary-row" style="font-weight:800; color: #10b981; font-size:1.05rem;">
                                <span>Net Paid Balance</span>
                                <span>₹<?= number_format($net_invoice_amount, 2) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Thermal Layout ── -->
    <div class="receipt-wrapper">
        <div class="thermal-receipt">
            <!-- Header -->
            <div class="receipt-header">
                <div style="text-align: center; margin-bottom: 8px;">
                    <img src="../assets/images/logo.png" alt="Orange Events Logo" style="max-height: 45px; width: auto; filter: grayscale(100%); display: inline-block;">
                </div>
                <div class="receipt-logo-title"><?= h($settings['company_name'] ?? 'Orange Events') ?></div>
                <div class="receipt-subtitle"><?= h($settings['company_subtitle'] ?? 'Premium Catering & Stage Decors') ?></div>
                <div class="receipt-contact">
                    <?= h($settings['company_address'] ?? 'Thumpoly P.O, Alappuzha') ?><br>
                    Phone: <?= h($settings['company_phone'] ?? '9946731720') ?><br>
                    <?= h($settings['company_email'] ?? 'orangedecorations@gmail.com') ?><br>
                    <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                        GSTIN: <?= h($settings['company_gstin']) ?><br>
                    <?php endif; ?>
                </div>
            </div>

            <div class="receipt-divider"></div>

            <!-- Meta Information -->
            <div class="receipt-meta">
                <div class="receipt-meta-row">
                    <span>INVOICE:</span>
                    <strong><?= h($order['invoice_number']) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>DATE:</span>
                    <span><?= date('d-m-Y H:i', strtotime($order['created_at'])) ?></span>
                </div>
                <div class="receipt-meta-row">
                    <span>CASHIER:</span>
                    <span><?= h(ucfirst($_SESSION['admin_username'] ?? 'Admin')) ?></span>
                </div>
                <?php if (!empty($order['customer_name'])): ?>
                    <div class="receipt-meta-row" style="margin-top:4px;">
                        <span>CUSTOMER:</span>
                        <span><?= h($order['customer_name']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order['customer_phone'])): ?>
                    <div class="receipt-meta-row">
                        <span>PHONE:</span>
                        <span><?= h($order['customer_phone']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order['customer_address'])): ?>
                    <div class="receipt-meta-row" style="margin-top:2px;">
                        <span>ADDRESS:</span>
                        <span style="text-align:right; max-width:65%;"><?= h(preg_replace('/\s+/', ' ', $order['customer_address'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="receipt-divider"></div>

            <!-- Items Table -->
            <table class="receipt-table">
                <thead>
                    <tr>
                        <th style="width:55%;">ITEM</th>
                        <th style="width:20%; text-align:center;">QTY</th>
                        <th style="width:25%; text-align:right;">PRICE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <div><?= h($item['product_name']) ?></div>
                                <?php if (!empty($item['variant_size']) || !empty($item['sell_type'])): ?>
                                    <div style="font-size:9px; color:#555555;">
                                        <?php if (!empty($item['variant_size'])): ?>
                                            Size: <?= h($item['variant_size']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($item['sell_type'])): ?>
                                            <?= !empty($item['variant_size']) ? ' | ' : '' ?>Type: <?= h(ucfirst($item['sell_type'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;"><?= $item['quantity'] ?></td>
                            <td style="text-align:right;">₹<?= number_format($item['total_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="receipt-divider"></div>

            <!-- Summary -->
            <div class="receipt-summary">
                <div class="receipt-summary-row">
                    <span>Subtotal:</span>
                    <span>₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                    <div class="receipt-summary-row">
                        <span>Discount:</span>
                        <span>-₹<?= number_format($order['discount_amount'], 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="receipt-summary-row receipt-summary-total">
                    <span>PAYABLE TOTAL:</span>
                    <span>₹<?= number_format($order['final_amount'], 2) ?></span>
                </div>
                <div class="receipt-summary-row" style="margin-top:6px; font-weight:bold;">
                    <span>Payment Mode:</span>
                    <span>
                        <?php if ($order['payment_method'] === 'Split' || !empty($order['payment_breakdown'])): ?>
                            SPLIT
                        <?php else: ?>
                            <?= h(strtoupper($order['payment_method'])) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($order['payment_method'] === 'Split' || !empty($order['payment_breakdown'])): ?>
                    <?php
                    $bd_parts = [];
                    if (!empty($order['payment_breakdown'])) {
                        $bd = json_decode($order['payment_breakdown'], true);
                        if (is_array($bd)) {
                            foreach ($bd as $m => $a) {
                                if ((float)$a > 0) $bd_parts[$m] = (float)$a;
                            }
                        }
                    }
                    if (empty($bd_parts)) {
                        if ($order['paid_cash'] > 0) $bd_parts['Cash'] = $order['paid_cash'];
                        if ($order['paid_upi'] > 0)  $bd_parts['UPI']  = $order['paid_upi'];
                        if ($order['paid_card'] > 0) $bd_parts['Card'] = $order['paid_card'];
                    }
                    foreach ($bd_parts as $m => $a):
                    ?>
                        <div class="receipt-summary-row" style="font-size:0.75rem; color:#555;">
                            <span style="padding-left:8px;">- <?= h($m) ?>:</span>
                            <span>₹<?= number_format($a, 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($is_upi_secure && !empty($upi_payment_url)): ?>
                <div style="text-align:center; margin:8px 0;">
                    <div style="font-weight:bold; font-size:10px; text-transform:uppercase; letter-spacing:0.5px;">Scan to Pay via UPI</div>
                    <div style="display:flex; justify-content:center; margin:4px 0;">
                        <div id="thermalUpiQr" style="padding:4px; background:#fff; border:1px solid #000; display:inline-block;"></div>
                    </div>
                    <div style="font-size:9px; font-weight:bold; font-family:monospace;"><?= h($settings['company_upi_id']) ?></div>
                    <div style="font-size:9px; font-weight:bold;">Payable: ₹<?= number_format($net_invoice_amount, 2) ?></div>
                </div>
            <?php endif; ?>

            <div class="receipt-divider"></div>

            <!-- Footer -->
            <div class="receipt-footer">
                <div style="font-weight:bold; margin-bottom:4px;">Thank you for shopping with us!</div>
                <div>Have a great celebration!</div>
                <div>--- Visit Us Again ---</div>
            </div>
        </div>
    </div>

</div>

<!-- html2pdf & QRCode JS libraries from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<?php if ($is_upi_secure && !empty($upi_payment_url)): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const upiUrl = <?= json_encode($upi_payment_url) ?>;
    
    const a4El = document.getElementById("a4UpiQr");
    if (a4El) {
        new QRCode(a4El, {
            text: upiUrl,
            width: 68,
            height: 68,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    }

    const thermalEl = document.getElementById("thermalUpiQr");
    if (thermalEl) {
        new QRCode(thermalEl, {
            text: upiUrl,
            width: 80,
            height: 80,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    }
});
</script>
<?php endif; ?>

<script>
    let activeView = 'a4';

    // Auto trigger print dialog on page load if print=1 is in URL query
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('print') === '1') {
        window.addEventListener('load', () => {
            setTimeout(() => {
                window.print();
            }, 300);
        });
    }

    // Toggle view handler
    function setView(view) {
        activeView = view;
        const container = document.getElementById('viewContainer');
        const btnA4 = document.getElementById('btnViewA4');
        const btnThermal = document.getElementById('btnViewThermal');
        
        // Remove existing print class states
        document.body.classList.remove('view-a4', 'view-thermal');
        document.body.classList.add('view-' + view);
        
        if (view === 'a4') {
            container.classList.remove('view-thermal');
            container.classList.add('view-a4');
            btnA4.classList.add('active');
            btnThermal.classList.remove('active');
        } else {
            container.classList.remove('view-a4');
            container.classList.add('view-thermal');
            btnThermal.classList.add('active');
            btnA4.classList.remove('active');
        }
    }

    // Set default view on load
    document.addEventListener("DOMContentLoaded", () => {
        setView('a4');
    });

    function downloadPDF() {
        const selector = activeView === 'a4' ? '.invoice-paper' : '.thermal-receipt';
        const element = document.querySelector(selector);
        const invoiceNo = '<?= $order['invoice_number'] ?>';
        
        const opt = activeView === 'a4' ? {
            margin:       0,
            filename:     `Invoice_${invoiceNo}.pdf`,
            image:        { type: 'jpeg', quality: 1.0 },
            html2canvas:  { 
                scale: 3, 
                useCORS: true, 
                logging: false,
                windowWidth: 1150 // Forces desktop view scale to prevent clipping on mobile screen render
            },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        } : {
            margin:       0,
            filename:     `Receipt_${invoiceNo}.pdf`,
            image:        { type: 'jpeg', quality: 1.0 },
            html2canvas:  { 
                scale: 3, 
                useCORS: true, 
                logging: false,
                windowWidth: 480
            },
            jsPDF:        { unit: 'mm', format: [80, 240], orientation: 'portrait' }
        };
        
        html2pdf().from(element).set(opt).save();
    }

    function shareWhatsApp() {
        const customerName = '<?= h($order['customer_name'] ?? 'Customer') ?>';
        const invoiceNo = '<?= h($order['invoice_number']) ?>';
        const amount = '₹<?= number_format($order['final_amount'], 2) ?>';
        // Construct the public URL dynamically based on the current domain/origin
        const publicUrl = window.location.origin + '/orange-events/view-receipt.php?id=<?= $order['id'] ?>';
        
        const messageText = `Hello *${customerName}*,\n\nThank you for choosing *Orange Events*! 🌟\nHere is your invoice *${invoiceNo}* for a total of *${amount}*.\n\nYou can view and download your invoice using this link:\n${publicUrl}\n\nHave a wonderful celebration! 🎉`;
        
        const encodedText = encodeURIComponent(messageText);
        const rawPhone = '<?= h($order['customer_phone'] ?? '') ?>';
        const cleanPhone = rawPhone.replace(/[^0-9]/g, '');
        
        let whatsappUrl = `https://api.whatsapp.com/send?text=${encodedText}`;
        if (cleanPhone.length >= 10) {
            let phone = cleanPhone;
            if (phone.length === 10) {
                // Default to India country code 91 if it's 10 digits
                phone = '91' + phone;
            }
            whatsappUrl = `https://api.whatsapp.com/send?phone=${phone}&text=${encodedText}`;
        }
        
        window.open(whatsappUrl, '_blank');
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
