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
$thermal_paper_width = $settings['pos_thermal_paper_width'] ?? '80mm';
$thermal_font_size = $settings['pos_thermal_font_size'] ?? ($thermal_paper_width === '58mm' ? '10px' : '11px');
$thermal_footer_msg = $settings['pos_thermal_footer_msg'] ?? 'Thank you for your business! Please retain this receipt.';

require_once __DIR__ . '/../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    /* Screen styling for thermal receipt element (Hidden on screen) */
    #thermalReceiptContainer {
        display: none;
    }

    /* Print CSS Overrides */
    @media print {
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }

        /* Force page reset */
        html, body {
            background: #ffffff !important;
            color: #000000 !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            height: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
        }

        /* Hide all page content by default to prevent extra blank page overflow */
        body * {
            visibility: hidden !important;
        }

        /* Hide UI controls completely */
        .sidebar,
        .sidebar-toggle-btn,
        .content-header,
        .receipt-action-bar,
        #thermalReceiptContainer,
        #thermalPrintIframe,
        .navbar,
        footer,
        header,
        aside {
            display: none !important;
            visibility: hidden !important;
        }

        /* Make only #invoicePaper and its children visible and positioned at top-left */
        #invoicePaper, #invoicePaper * {
            visibility: visible !important;
        }

        #invoicePaper {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            background: #ffffff !important;
            color: #0f172a !important;
            margin: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            page-break-inside: auto;
        }

        #invoicePaper div, 
        #invoicePaper span, 
        #invoicePaper td, 
        #invoicePaper th, 
        #invoicePaper p,
        #invoicePaper strong {
            color: #0f172a !important;
        }

        /* Keep top header band dark with white text */
        #invoicePaper .invoice-card-header, 
        #invoicePaper .invoice-card-header div, 
        #invoicePaper .invoice-card-header span, 
        #invoicePaper .invoice-card-header h2 {
            background: #1a1a2e !important;
            color: #ffffff !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        #invoicePaper .invoice-card-header img {
            filter: brightness(0) invert(1) !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Subtle gray backgrounds for meta cards and table headers in print */
        #invoicePaper .ri-print-bg {
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        #invoicePaper table {
            width: 100% !important;
            border-collapse: collapse !important;
        }

        #invoicePaper table th {
            background: #f1f5f9 !important;
            color: #0f172a !important;
            border-bottom: 2px solid #cbd5e1 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        #invoicePaper table td {
            border-bottom: 1px solid #e2e8f0 !important;
        }

        #invoicePaper table tr {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
    }
</style>

<!-- Compact Admin Page Header Toolbar -->
<div class="content-header" style="margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0; font-family: 'Outfit', sans-serif;">
                <i class="fa-solid fa-file-invoice" style="color: var(--accent-color);"></i>
                Invoice details <?= h($order['invoice_number']) ?>
            </h1>
            <p style="color: var(--text-secondary); margin: 0.15rem 0 0; font-size: 0.8rem;">
                Created on <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
            </p>
        </div>
        <div class="receipt-action-bar" style="display: flex; gap: 0.45rem; flex-wrap: wrap; align-items: center;">
            <a href="billing-invoices.php" class="btn btn-secondary" style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-arrow-left"></i> Archives
            </a>
            <a href="billing.php" class="btn btn-secondary" style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-calculator"></i> POS Terminal
            </a>
            <?php if (has_permission('billing_update')): ?>
            <a href="edit-billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary" style="background: rgba(255, 165, 2, 0.12); color: var(--warning); border-color: rgba(255, 165, 2, 0.15); height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </a>
            <?php endif; ?>
            <button type="button" onclick="shareWhatsApp()" class="btn btn-success" style="background-color: #25d366; border-color: #25d366; color: #ffffff; height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp
            </button>

            <!-- Thermal Print Button -->
            <button type="button" onclick="printThermal()" class="btn btn-secondary" style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem; background: rgba(30, 144, 255, 0.12); color: var(--info); border-color: rgba(30, 144, 255, 0.2);" title="Print <?= $thermal_paper_width ?> Thermal Receipt">
                <i class="fa-solid fa-receipt"></i> Thermal Print (<?= $thermal_paper_width ?>)
            </button>

            <!-- A4 PDF Download Button -->
            <button type="button" onclick="downloadPDF()" class="btn btn-secondary" style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;" title="Download A4 PDF Preview">
                <i class="fa-solid fa-file-pdf"></i> Download A4 PDF
            </button>

            <!-- Print A4 Button -->
            <button type="button" onclick="printA4()" class="btn btn-primary" style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem; background: var(--accent-gradient);" title="Print Standard A4 Invoice">
                <i class="fa-solid fa-print"></i> Print A4
            </button>
        </div>
    </div>
</div>

<?php if (isset($_GET['edit_success'])): ?>
    <div style="background-color: var(--success); color: #ffffff; padding: 0.65rem 1.25rem; border-radius: var(--border-radius-md); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 0.85rem;">
        <span><i class="fa-solid fa-circle-check"></i> POS Invoice has been updated successfully.</span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- Main A4 Invoice Card (Matching Admin UI Design Sizing) -->
<div class="card" id="invoicePaper" style="padding: 0; overflow: hidden; margin-bottom: 2rem;">
    
    <!-- Top Header Banner Band -->
    <div class="invoice-card-header" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); padding: 1.5rem 1.75rem; color: #ffffff; position: relative; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <img src="../assets/images/logo.png" alt="Logo" style="max-height: 48px; width: auto; filter: brightness(0) invert(1);">
            <div>
                <div style="font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 800; color: #ff6b35; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= h($settings['company_name'] ?? 'Orange Events') ?>
                </div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7); margin-top: 0.1rem;">
                    <?= h($settings['company_subtitle'] ?? 'Premium Catering & Stage Decors') ?>
                </div>
                <div style="font-size: 0.78rem; color: rgba(255,255,255,0.85); margin-top: 0.4rem; line-height: 1.4;">
                    <?= h($settings['company_address'] ?? 'Thumpoly P.O, Alappuzha') ?><br>
                    Phone: <?= h($settings['company_phone'] ?? '9946731720') ?> | Email: <?= h($settings['company_email'] ?? 'orangedecorations@gmail.com') ?>
                    <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                        <br>GSTIN: <?= h($settings['company_gstin']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div style="text-align: right;">
            <div style="font-size: 1.25rem; font-weight: 800; color: #ffffff; letter-spacing: 1px;">INVOICE</div>
            <div style="font-size: 1rem; font-weight: 700; color: #ff6b35; margin-top: 0.15rem;"><?= h($order['invoice_number']) ?></div>
            <div style="font-size: 0.78rem; color: rgba(255,255,255,0.75); margin-top: 0.25rem;">
                Date: <?= date('d-m-Y h:i A', strtotime($order['created_at'])) ?>
            </div>
            <div style="display: inline-block; margin-top: 0.5rem; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.72rem; font-weight: 700; background: rgba(46, 213, 115, 0.15); color: #2ed573; border: 1px solid #2ed573; text-transform: uppercase;">
                PAID / SETTLED
            </div>
            <?php if ($has_returns): ?>
                <div style="display: block; margin-top: 0.3rem; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.7rem; font-weight: 700; background: rgba(255, 71, 87, 0.15); color: #ff4757; border: 1px solid #ff4757;">
                    ITEM RETURNED
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metadata Details Grid Cards -->
    <div style="padding: 1.25rem 1.5rem; background: var(--bg-card); border-bottom: 1px solid var(--border-color);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            
            <!-- Customer Box -->
            <div class="ri-print-bg" style="background: var(--bg-control); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 0.85rem 1rem;">
                <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 0.35rem;">
                    Billed To
                </div>
                <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary);">
                    <?= h($order['customer_name'] ?: 'Walk-in Customer') ?>
                </div>
                <?php if (!empty($order['customer_phone'])): ?>
                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;">
                        <i class="fa-solid fa-phone" style="font-size: 0.68rem; color: var(--text-muted);"></i> <?= h($order['customer_phone']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order['customer_address'])): ?>
                    <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.25rem; line-height: 1.3;">
                        <?= nl2br(h($order['customer_address'])) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payment Info Box -->
            <div class="ri-print-bg" style="background: var(--bg-control); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 0.85rem 1rem;">
                <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 0.35rem;">
                    Payment Information
                </div>
                <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary);">
                    <?php if ($order['payment_method'] === 'Split' || !empty($order['payment_breakdown'])): ?>
                        <span style="color: var(--accent-color);">SPLIT PAYMENT</span>
                        <div style="font-size: 0.78rem; font-weight: normal; color: var(--text-secondary); margin-top: 0.2rem;">
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
                <div style="font-size: 0.78rem; color: var(--success); margin-top: 0.25rem; font-weight: 600;">
                    Status: Fully Settled / Complete
                </div>
            </div>

            <!-- Staff & Counter Box -->
            <div class="ri-print-bg" style="background: var(--bg-control); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 0.85rem 1rem;">
                <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 0.35rem;">
                    Cashier / Staff
                </div>
                <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary);">
                    <?= h(ucfirst($_SESSION['admin_username'] ?? 'Admin')) ?>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-secondary); margin-top: 0.2rem;">
                    POS Terminal 01
                </div>
            </div>

        </div>
    </div>

    <!-- Items Table Section -->
    <div style="padding: 1.25rem 1.5rem;">
        <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 0.75rem;">
            Order Line Items (<?= count($items) ?>)
        </div>

        <div class="table-responsive">
            <table class="table" style="margin: 0; font-size: 0.85rem;">
                <thead>
                    <tr>
                        <th style="padding: 0.6rem 0.75rem; width: 6%; text-align: center;">#</th>
                        <th style="padding: 0.6rem 0.75rem;">Item Description</th>
                        <th style="padding: 0.6rem 0.75rem; width: 16%; text-align: right;">Unit Price</th>
                        <th style="padding: 0.6rem 0.75rem; width: 12%; text-align: center;">Qty</th>
                        <th style="padding: 0.6rem 0.75rem; width: 18%; text-align: right;">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sl = 1; foreach ($items as $item): ?>
                        <tr>
                            <td style="padding: 0.65rem 0.75rem; text-align: center; color: var(--text-muted); font-size: 0.78rem;"><?= $sl++ ?></td>
                            <td style="padding: 0.65rem 0.75rem;">
                                <div style="font-weight: 700; color: var(--text-primary);"><?= h($item['product_name']) ?></div>
                                <?php if (!empty($item['variant_size']) || !empty($item['sell_type'])): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                        <?php if (!empty($item['variant_size'])): ?>
                                            Variant: <?= h($item['variant_size']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($item['sell_type']) && $item['sell_type'] === 'rental'): ?>
                                            (Rental)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; text-align: right; color: var(--text-secondary);">₹<?= number_format($item['price'], 2) ?></td>
                            <td style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 700; color: var(--text-primary);"><?= (float)$item['quantity'] ?></td>
                            <td style="padding: 0.65rem 0.75rem; text-align: right; font-weight: 700; color: var(--text-primary);">₹<?= number_format($item['total_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Financial Summary Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-top: 1.5rem; align-items: flex-start;">
            <div>
                <div class="ri-print-bg" style="background: var(--bg-control); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 0.85rem 1rem; font-size: 0.78rem; color: var(--text-secondary); line-height: 1.5;">
                    <strong style="color: var(--text-primary); font-size: 0.82rem;">Store Terms & Notice:</strong><br>
                    • All sales are final. Goods once sold are covered per store terms.<br>
                    • Retain this receipt for future reference or returns.
                </div>
            </div>

            <div class="ri-print-bg" style="background: var(--bg-control); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1rem 1.25rem;">
                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; font-size: 0.85rem; color: var(--text-secondary);">
                    <span>Subtotal:</span>
                    <span>₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                    <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; font-size: 0.85rem; color: var(--danger);">
                        <span>Discount:</span>
                        <span>-₹<?= number_format($order['discount_amount'], 2) ?></span>
                    </div>
                <?php endif; ?>
                <div style="display: flex; justify-content: space-between; padding-top: 0.6rem; margin-top: 0.4rem; border-top: 1px dashed var(--border-color); font-size: 1.15rem; font-weight: 800; color: var(--accent-color); font-family: 'Outfit', sans-serif;">
                    <span>Net Total:</span>
                    <span>₹<?= number_format($order['final_amount'], 2) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden 80mm/58mm Thermal Receipt HTML for Thermal Printer -->
<div id="thermalReceiptContainer">
    <div class="thermal-receipt">
        <div class="thermal-header">
            <div class="thermal-title"><?= h($settings['company_name'] ?? 'ORANGE EVENTS') ?></div>
            <div class="thermal-sub"><?= h($settings['company_subtitle'] ?? 'Catering & Stage Decors') ?></div>
            <div class="thermal-sub"><?= h($settings['company_address'] ?? 'Thumpoly P.O, Alappuzha') ?></div>
            <div class="thermal-sub">Ph: <?= h($settings['company_phone'] ?? '9946731720') ?></div>
            <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                <div class="thermal-sub">GSTIN: <?= h($settings['company_gstin']) ?></div>
            <?php endif; ?>
        </div>

        <div class="thermal-divider"></div>

        <div>
            <div class="thermal-flex"><span>Invoice:</span> <strong><?= h($order['invoice_number']) ?></strong></div>
            <div class="thermal-flex"><span>Date:</span> <span><?= date('d-m-Y H:i', strtotime($order['created_at'])) ?></span></div>
            <div class="thermal-flex"><span>Customer:</span> <span><?= h($order['customer_name'] ?: 'Walk-in') ?></span></div>
            <?php if (!empty($order['customer_phone'])): ?>
                <div class="thermal-flex"><span>Phone:</span> <span><?= h($order['customer_phone']) ?></span></div>
            <?php endif; ?>
        </div>

        <div class="thermal-divider"></div>

        <table class="thermal-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Item</th>
                    <th style="width: 20%; text-align: center;">Qty</th>
                    <th style="width: 30%; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div><?= h($item['product_name']) ?></div>
                            <?php if (!empty($item['variant_size']) || (!empty($item['sell_type']) && $item['sell_type'] === 'rental')): ?>
                                <div style="font-size: 85%; opacity: 0.85;">
                                    <?php if (!empty($item['variant_size'])): ?>
                                        <?= h($item['variant_size']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($item['sell_type']) && $item['sell_type'] === 'rental'): ?>
                                        (Rental)
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;"><?= (float)$item['quantity'] ?></td>
                        <td style="text-align: right;">₹<?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="thermal-divider"></div>

        <div>
            <div class="thermal-flex"><span>Subtotal:</span> <span>₹<?= number_format($order['total_amount'], 2) ?></span></div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div class="thermal-flex"><span>Discount:</span> <span>-₹<?= number_format($order['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="thermal-flex thermal-total-row">
                <span>NET TOTAL:</span>
                <span>₹<?= number_format($order['final_amount'], 2) ?></span>
            </div>
            <div class="thermal-flex" style="margin-top: 4px; flex-direction: column;">
                <div style="display:flex; justify-content:space-between; width:100%;">
                    <span>Payment Mode:</span>
                    <strong><?= h(strtoupper($order['payment_method'])) ?></strong>
                </div>
                <?php if ($order['payment_method'] === 'Split' || !empty($order['payment_breakdown'])): ?>
                    <div style="font-size: 85%; opacity: 0.85; text-align: right; margin-top: 2px;">
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
                <?php endif; ?>
            </div>
        </div>

        <div class="thermal-divider"></div>

        <div class="thermal-footer">
            <?= nl2br(h($thermal_footer_msg)) ?>
        </div>
    </div>
</div>

<script>
    function printA4() {
        window.print();
    }

    function printThermal() {
        const thermalHTML = document.getElementById('thermalReceiptContainer').innerHTML;
        
        let iframe = document.getElementById('thermalPrintIframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'thermalPrintIframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0px';
            iframe.style.height = '0px';
            iframe.style.border = 'none';
            iframe.style.opacity = '0';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentWindow.document;
        doc.open();
        doc.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Thermal Receipt - <?= h($order['invoice_number']) ?></title>
                <style>
                    @page { size: auto; margin: 0mm; }
                    html, body {
                        margin: 0 !important;
                        padding: 6px 4px !important;
                        background: #ffffff !important;
                        color: #000000 !important;
                        font-family: 'Courier New', Courier, monospace;
                        font-size: <?= $thermal_font_size ?>;
                        width: <?= $thermal_paper_width ?>;
                        max-width: <?= $thermal_paper_width ?>;
                        page-break-before: avoid !important;
                        page-break-after: avoid !important;
                    }
                    * { box-sizing: border-box; }
                    .thermal-receipt {
                        width: 100%;
                        page-break-inside: avoid !important;
                        break-inside: avoid !important;
                    }
                    .thermal-header { text-align: center; margin-bottom: 6px; }
                    .thermal-title { font-size: 14px; font-weight: bold; text-transform: uppercase; line-height: 1.2; }
                    .thermal-sub { font-size: 9px; line-height: 1.3; }
                    .thermal-divider { border-top: 1px dashed #000000; margin: 4px 0; }
                    .thermal-table { width: 100%; border-collapse: collapse; margin: 4px 0; font-size: <?= $thermal_font_size ?>; }
                    .thermal-table th { text-align: left; border-bottom: 1px dashed #000; padding-bottom: 2px; font-weight: bold; }
                    .thermal-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
                    .thermal-table td { padding: 2px 0; vertical-align: top; word-break: break-word; }
                    .thermal-flex { display: flex; justify-content: space-between; gap: 4px; word-break: break-word; }
                    .thermal-total-row { font-size: 13px; font-weight: bold; border-top: 1px dashed #00; border-bottom: 1px dashed #00; padding: 3px 0; margin-top: 3px; }
                    .thermal-footer { text-align: center; margin-top: 8px; font-size: 9px; }
                </style>
            </head>
            <body>
                ${thermalHTML}
            </body>
            </html>
        `);
        doc.close();

        setTimeout(function() {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                console.error("Iframe print error, falling back to popup window", e);
                const printWin = window.open('', '_blank', 'width=420,height=600');
                if (printWin) {
                    printWin.document.write(doc.documentElement.outerHTML);
                    printWin.document.close();
                    printWin.onload = function() {
                        printWin.print();
                        setTimeout(() => printWin.close(), 600);
                    };
                } else {
                    alert("Pop-up blocked. Please allow pop-ups for thermal printing.");
                }
            }
        }, 300);
    }

    function downloadPDF() {
        const element = document.getElementById('invoicePaper');
        const invoiceNo = '<?= h($order['invoice_number']) ?>';
        
        const opt = {
            margin:       [5, 5, 5, 5],
            filename:     `Invoice_${invoiceNo}.pdf`,
            image:        { type: 'jpeg', quality: 1.0 },
            html2canvas:  { 
                scale: 3, 
                useCORS: true, 
                logging: false,
                windowWidth: 1150
            },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        
        html2pdf().from(element).set(opt).save();
    }

    function shareWhatsApp() {
        let customerName = '<?= h(addslashes($order['customer_name'] ?? 'Customer')) ?>';
        if (!customerName || customerName === 'Walk-in Customer') customerName = 'Valued Customer';
        
        const invoiceNo = '<?= h($order['invoice_number']) ?>';
        const amount = '₹<?= number_format($order['final_amount'], 2) ?>';
        const publicUrl = window.location.origin + '/orange-events/view-receipt.php?inv=' + encodeURIComponent(invoiceNo);
        
        let rawPhone = '<?= h($order['customer_phone'] ?? '') ?>';
        let cleanPhone = rawPhone.replace(/[^0-9]/g, '');
        
        if (!cleanPhone) {
            const inputPhone = prompt(`Share E-Receipt ${invoiceNo} via WhatsApp\n\nEnter 10-digit WhatsApp Phone Number:`, '');
            if (inputPhone === null) return;
            cleanPhone = inputPhone.replace(/[^0-9]/g, '');
        }
        
        const purchaseDate = '<?= date('d M Y', strtotime($order['created_at'])) ?>';
        const messageText = `Hello *${customerName}* 👋,\n\nThank you for choosing *Orange Events*! 🌟\nHere is your digital receipt *${invoiceNo}* for *${amount}* issued on *${purchaseDate}*.\n\nView & download your E-Receipt link:\n${publicUrl}\n\nHave a wonderful celebration! 🎉`;
        
        const encodedText = encodeURIComponent(messageText);
        let whatsappUrl = `https://api.whatsapp.com/send?text=${encodedText}`;
        if (cleanPhone.length >= 10) {
            let phone = cleanPhone;
            if (phone.length === 10) {
                phone = '91' + phone;
            }
            whatsappUrl = `https://api.whatsapp.com/send?phone=${phone}&text=${encodedText}`;
        }
        
        window.open(whatsappUrl, '_blank');
    }

    // Auto-trigger print if requested in URL
    document.addEventListener("DOMContentLoaded", function() {
        if (window.location.search.includes('print=1') || window.location.search.includes('thermal=1')) {
            printThermal();
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
