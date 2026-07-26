<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();
require_permission('billing_read');

$db = get_db_connection();
$id = (int) ($_GET['id'] ?? $_GET['order_id'] ?? 0);
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

// Fetch order items with Category Name
$stmt_items = $db->prepare("
    SELECT i.*, p.category_id, c.category_name 
    FROM billing_order_items i
    LEFT JOIN billing_products p ON i.product_id = p.id
    LEFT JOIN billing_categories c ON p.category_id = c.id
    WHERE i.order_id = :id
    ORDER BY i.id ASC
");
$stmt_items->execute(['id' => $id]);
$items = $stmt_items->fetchAll();

// Fetch any returns associated with this order
$stmt_returns = $db->prepare("SELECT * FROM billing_returns WHERE order_id = :id ORDER BY id DESC");
$stmt_returns->execute(['id' => $id]);
$returns_list = $stmt_returns->fetchAll();

$total_returned_amount = 0;
foreach ($returns_list as $ret) {
    $total_returned_amount += (float) $ret['refund_amount'];
}
$has_returns = ($total_returned_amount > 0);
$net_invoice_amount = max(0, (float) $order['final_amount'] - $total_returned_amount);

// Calculate amount in words
$amount_in_words = convert_number_to_words($net_invoice_amount);
if (!empty($amount_in_words)) {
    $amount_in_words = ucwords(trim($amount_in_words)) . ' only';
} else {
    $amount_in_words = 'Zero only';
}

// Fetch settings
$settings_res = $db->query("SELECT * FROM settings")->fetchAll();
$settings = [];
foreach ($settings_res as $row) {
    $settings[$row['key']] = $row['value'];
}
$raw_terms = $settings['invoice_terms'] ?? 'Thanks for choosing {company_name}! Total items: {total_items}';
$formatted_terms = str_replace(
    ['{company_name}', '{total_items}'],
    [$settings['company_name'] ?? 'Aedan Gardens', count($items)],
    $raw_terms
);

// Fetch customer email and GSTIN if available
$customer_email = '';
$customer_gstin = '';
if (!empty($order['customer_phone'])) {
    $stmt_cust = $db->prepare("SELECT email, gstin FROM customers WHERE phone = :phone LIMIT 1");
    $stmt_cust->execute(['phone' => $order['customer_phone']]);
    $cust = $stmt_cust->fetch();
    if ($cust) {
        if (!empty($cust['email']))
            $customer_email = $cust['email'];
        if (!empty($cust['gstin']))
            $customer_gstin = $cust['gstin'];
    }
}
$thermal_paper_width = $settings['pos_thermal_paper_width'] ?? '80mm';
$thermal_font_size = $settings['pos_thermal_font_size'] ?? ($thermal_paper_width === '58mm' ? '10px' : '11px');
$thermal_footer_msg = $settings['pos_thermal_footer_msg'] ?? 'Thank you for your business! Please retain this receipt.';
$upi_qr_url = generate_upi_qr_code_url($net_invoice_amount, $order['invoice_number']);

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
        html,
        body {
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
        #invoicePaper,
        #invoicePaper * {
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
<div class="content-header"
    style="margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
    <div
        style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h1
                style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0; font-family: 'Outfit', sans-serif;">
                <i class="fa-solid fa-file-invoice" style="color: var(--accent-color);"></i>
                Invoice details <?= h($order['invoice_number']) ?>
            </h1>
            <p style="color: var(--text-secondary); margin: 0.15rem 0 0; font-size: 0.8rem;">
                Created on <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
            </p>
        </div>
        <div class="receipt-action-bar" style="display: flex; gap: 0.45rem; flex-wrap: wrap; align-items: center;">
            <a href="billing-invoices.php" class="btn btn-secondary"
                style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-arrow-left"></i> Archives
            </a>
            <a href="billing.php" class="btn btn-secondary"
                style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-calculator"></i> POS Terminal
            </a>
            <?php if (has_permission('billing_update')): ?>
                <a href="edit-billing-invoice.php?id=<?= $order['id'] ?>" class="btn btn-secondary"
                    style="background: rgba(255, 165, 2, 0.12); color: var(--warning); border-color: rgba(255, 165, 2, 0.15); height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <i class="fa-solid fa-pen-to-square"></i> Edit
                </a>
            <?php endif; ?>
            <button type="button" onclick="shareWhatsApp()" class="btn btn-success"
                style="background-color: #25d366; border-color: #25d366; color: #ffffff; height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp
            </button>

            <!-- Thermal Print Button -->
            <button type="button" onclick="printThermal()" class="btn btn-secondary"
                style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem; background: rgba(30, 144, 255, 0.12); color: var(--info); border-color: rgba(30, 144, 255, 0.2);"
                title="Print <?= $thermal_paper_width ?> Thermal Receipt">
                <i class="fa-solid fa-receipt"></i> Thermal Print (<?= $thermal_paper_width ?>)
            </button>

            <!-- A4 PDF Download Button -->
            <button type="button" onclick="downloadPDF()" class="btn btn-secondary"
                style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;"
                title="Download A4 PDF Preview">
                <i class="fa-solid fa-file-pdf"></i> Save A4 PDF
            </button>

            <!-- Standalone A4 Print Button (Separate Page) -->
            <a href="billing-invoice-print.php?id=<?= $order['id'] ?>" target="_blank" class="btn btn-primary"
                style="height: 34px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem; background: var(--accent-gradient);"
                title="Open & Print Invoice in Separate Page">
                <i class="fa-solid fa-up-right-from-square"></i> Open / Print A4 Page
            </a>
        </div>
    </div>
</div>

<?php if (isset($_GET['edit_success'])): ?>
    <div
        style="background-color: var(--success); color: #ffffff; padding: 0.65rem 1.25rem; border-radius: var(--border-radius-md); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 0.85rem;">
        <span><i class="fa-solid fa-circle-check"></i> POS Invoice has been updated successfully.</span>
        <button onclick="this.parentElement.style.display='none'"
            style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- Main A4 Invoice Card (Matching Reference Tax Invoice Template) -->
<div class="card" id="invoicePaper"
    style="padding: 24px 28px; background: #ffffff; color: #000000; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 4px 15px rgba(0,0,0,0.05); font-family: 'Inter', sans-serif;">

    <!-- 1. Header Row -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <img src="../assets/images/logo.png" alt="Logo" style="max-height: 46px; width: auto; object-fit: contain;"
                onerror="this.style.display='none'">
            <div>
                <div
                    style="font-size: 17px; font-weight: 800; color: #2b7b38; letter-spacing: -0.3px; line-height: 1.15;">
                    <?= h($settings['company_name'] ?? 'Aedan Gardens') ?>
                </div>
                <div style="font-size: 10.5px; font-weight: 600; color: #333333; margin-top: 1px;">
                    <?= h($settings['company_subtitle'] ?? '(Plant Nursery & Garden Center)') ?>
                </div>
                <div style="font-size: 10px; color: #111111; margin-top: 3px; line-height: 1.35;">
                    <?= nl2br(h($settings['company_address'] ?? "Thumpoly P.O Alappuzha\nAlappuzha")) ?><br>
                    Email: <?= h($settings['company_email'] ?? 'aedangardens04@gmail.com') ?><br>
                    State: <?= h($settings['company_state'] ?? '32-Kerala') ?>
                    <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                        <br>GSTIN: <?= h($settings['company_gstin']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="text-align: right;">
            <div style="font-size: 15px; font-weight: 800; color: #000000; margin-bottom: 3px;">Tax Invoice</div>
            <div style="font-size: 10.5px; font-weight: 600; color: #111111; margin-top: 2px;">Invoice No:
                <?= h($order['invoice_number']) ?></div>
            <div style="font-size: 10.5px; font-weight: 600; color: #111111; margin-top: 2px;">Date:
                <?= date('d-m-Y', strtotime($order['created_at'])) ?></div>
            <div style="font-size: 10.5px; font-weight: 600; color: #111111; margin-top: 2px;">Place of Supply:
                <?= h($settings['place_of_supply'] ?? '32 Kerala') ?></div>
        </div>
    </div>

    <!-- 2. Bill To Box -->
    <div style="margin-bottom: 14px; font-size: 10.5px; color: #000000; line-height: 1.35;">
        <div style="font-size: 11.5px; font-weight: 800; margin-bottom: 2px;">Bill To:</div>
        <div style="font-weight: 700; text-transform: uppercase;">
            <?= h($order['customer_name'] ?: 'WALK-IN CUSTOMER') ?></div>
        <?php if (!empty($order['customer_address'])): ?>
            <div><?= nl2br(h($order['customer_address'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($order['customer_phone'])): ?>
            <div>Contact No: <?= h($order['customer_phone']) ?></div>
        <?php endif; ?>
        <?php if (!empty($customer_email)): ?>
            <div>Email: <?= h($customer_email) ?></div>
        <?php endif; ?>
        <?php if (!empty($customer_gstin)): ?>
            <div>GSTIN: <?= h($customer_gstin) ?></div>
        <?php endif; ?>
        <div>State: <?= h($settings['company_state'] ?? '32-Kerala') ?></div>
    </div>

    <!-- 3. Line Items Table -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 14px;">
        <thead>
            <tr>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: center; width: 6%;">
                    SI No</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: left; width: 30%;">
                    Item Name</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: left; width: 16%;">
                    Category</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: center; width: 12%;">
                    Size</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: center; width: 9%;">
                    Quantity</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: center; width: 7%;">
                    Unit</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: right; width: 10%;">
                    Price/Unit</th>
                <th
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; background: #ffffff; text-align: right; width: 10%;">
                    Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php $sl = 1;
            foreach ($items as $item): ?>
                <tr>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; text-align: center;">
                        <?= $sl++ ?></td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px;">
                        <strong><?= h($item['product_name']) ?></strong></td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px;">
                        <?= h($item['category_name'] ?: 'General') ?></td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; text-align: center;">
                        <?= h($item['variant_size'] ?: 'Regular') ?></td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; text-align: center;">
                        <?= (float) $item['quantity'] ?></td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; text-align: center;">Nos</td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; text-align: right;">
                        ₹<?= number_format($item['price'], 2) ?></td>
                    <td style="border: 1px solid #000000; padding: 5px 7px; font-size: 11px; text-align: right;">
                        ₹<?= number_format($item['total_price'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="6" style="border: 1px solid #000000;"></td>
                <td
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11.5px; font-weight: 800; text-align: right;">
                    Total</td>
                <td
                    style="border: 1px solid #000000; padding: 5px 7px; font-size: 11.5px; font-weight: 800; text-align: right;">
                    ₹<?= number_format($order['total_amount'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- 4. Middle Grid -->
    <div
        style="display: grid; grid-template-columns: 1.35fr 1fr; gap: 16px; align-items: stretch; margin-bottom: 16px;">
        <div
            style="border: 1px solid #000000; background: #ffffff; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden;">
            <div style="padding: 8px 10px;">
                <div
                    style="font-weight: 800; font-size: 11.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin-bottom: 5px;">
                    Invoice Amount in Words</div>
                <div style="font-size: 10.5px; color: #000000; line-height: 1.35;"><?= h($amount_in_words) ?></div>
            </div>

            <div style="border-top: 1px solid #000000; padding: 8px 10px;">
                <div
                    style="font-weight: 800; font-size: 11.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin-bottom: 5px;">
                    Description</div>
                <div style="font-size: 10.5px; color: #000000; line-height: 1.35;">
                    <?= h($settings['invoice_description'] ?? 'Healthy plants with care instructions. Returns accepted within 7 days for damaged plants only.') ?>
                </div>
            </div>
        </div>

        <div style="border: 1px solid #000000; background: #ffffff;">
            <div
                style="display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px;">
                <span>Sub Total</span>
                <span>₹<?= number_format($order['total_amount'], 2) ?></span>
            </div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div
                    style="display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px; color: #dc2626;">
                    <span>Discount</span>
                    <span>-₹<?= number_format($order['discount_amount'], 2) ?></span>
                </div>
            <?php endif; ?>
            <div
                style="display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px solid #000000; font-size: 12.5px; font-weight: 800;">
                <span>Total</span>
                <span>₹<?= number_format($net_invoice_amount, 2) ?></span>
            </div>
            <div
                style="display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px;">
                <span>Received</span>
                <span>₹<?= number_format($net_invoice_amount, 2) ?></span>
            </div>
            <div
                style="display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px;">
                <span>Balance</span>
                <span>₹0.00</span>
            </div>
            <div
                style="display: flex; justify-content: space-between; padding: 5px 10px; font-size: 12px; font-weight: 700; padding-top: 6px;">
                <span>Payment Method:</span>
                <span><?= h(strtoupper($order['payment_method'])) ?></span>
            </div>
        </div>
    </div>

    <!-- 5. Terms and Conditions -->
    <div
        style="background-color: #f2f2f2; padding: 5px 10px; font-weight: 800; font-size: 12px; color: #000000; margin-bottom: 6px; border-radius: 2px;">
        Terms and Conditions
    </div>
    <div style="font-size: 11.5px; color: #000000; margin-bottom: 16px;">
        <?= nl2br(h($formatted_terms)) ?>
    </div>

    <!-- 6. Footer Section -->
    <div style="display: grid; grid-template-columns: 1.1fr 1.3fr 1fr; gap: 14px; align-items: flex-end; margin-top: 10px;">
        <!-- Left Column: UPI Scan Box -->
        <div style="border: 1.5px dashed #000000; padding: 8px; text-align: center;">
            <div style="font-size: 11px; font-weight: 800; margin-bottom: 6px; text-transform: uppercase;">Scan to Pay with UPI</div>
            <?php if (!empty($upi_qr_url)): ?>
                <img src="<?= h($upi_qr_url) ?>" alt="Scan to pay" style="width: 105px; height: 105px; object-fit: contain; display: block; margin: 0 auto 4px auto;">
            <?php endif; ?>
            <div style="font-size: 9.5px; font-weight: 700; line-height: 1.3;">
                UPI ID: <?= h($settings['company_upi_id'] ?? '8590594735@okbizaxis') ?><br>
                Payee: <?= h(strtoupper($settings['company_name'] ?? 'AEDAN GARDENS')) ?>
            </div>
        </div>

        <!-- Middle Column: Bank Details Box -->
        <div style="font-size: 11.5px; line-height: 1.45;">
            <div style="background-color: #f2f2f2; padding: 4px 8px; font-weight: 800; font-size: 11.5px; margin-bottom: 6px;">Company's Bank Details:</div>
            <div style="display: flex; gap: 8px; font-size: 11px;">
                <div style="width: 55px; font-weight: 600; color: #333;">Bank:</div>
                <div style="font-weight: 700; color: #000;"><?= h($settings['company_bank_name'] ?? 'STATE BANK OF INDIA') ?></div>
            </div>
            <div style="display: flex; gap: 8px; font-size: 11px;">
                <div style="width: 55px; font-weight: 600; color: #333;">Acc No.:</div>
                <div style="font-weight: 700; color: #000;"><?= h($settings['company_bank_acc'] ?? '40598127711') ?></div>
            </div>
            <div style="display: flex; gap: 8px; font-size: 11px;">
                <div style="width: 55px; font-weight: 600; color: #333;">IFSC:</div>
                <div style="font-weight: 700; color: #000;"><?= h($settings['company_bank_ifsc'] ?? 'SBIN0000807') ?></div>
            </div>
            <div style="display: flex; gap: 8px; font-size: 11px;">
                <div style="width: 55px; font-weight: 600; color: #333;">Name:</div>
                <div style="font-weight: 700; color: #000;"><?= h($settings['company_bank_holder'] ?? 'AEDAN GARDENS') ?></div>
            </div>
        </div>

        <!-- Right Column (Opposite Side): Signatory Box & Unbordered Invoice Barcode -->
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <div style="border: 1px solid #000000; height: 75px; display: flex; flex-direction: column; justify-content: space-between; padding: 8px; text-align: center; font-size: 11px; font-weight: 700;">
                <div>For, <?= h($settings['company_name'] ?? 'Aedan Gardens') ?></div>
                <div>Authorized Signatory</div>
            </div>

            <!-- Invoice Barcode (Short High-Density Numeric Code 128) -->
            <div style="display: flex; justify-content: center; width: 100%; height: 42px; margin-top: 6px;">
                <?= generate_barcode_svg(get_numeric_invoice_barcode($order['invoice_number'], $order['id']), 42, 2.2) ?>
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
            <div class="thermal-flex"><span>Date:</span>
                <span><?= date('d-m-Y H:i', strtotime($order['created_at'])) ?></span></div>
            <div class="thermal-flex"><span>Customer:</span> <span><?= h($order['customer_name'] ?: 'Walk-in') ?></span>
            </div>
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
                        <td style="text-align: center;"><?= (float) $item['quantity'] ?></td>
                        <td style="text-align: right;">₹<?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="thermal-divider"></div>

        <div>
            <div class="thermal-flex"><span>Subtotal:</span>
                <span>₹<?= number_format($order['total_amount'], 2) ?></span></div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div class="thermal-flex"><span>Discount:</span>
                    <span>-₹<?= number_format($order['discount_amount'], 2) ?></span></div>
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
                                    if ((float) $a > 0)
                                        $bd_parts[] = h($m) . ': ₹' . number_format((float) $a, 2);
                                }
                            }
                        }
                        if (empty($bd_parts)) {
                            if ($order['paid_cash'] > 0)
                                $bd_parts[] = 'Cash: ₹' . number_format($order['paid_cash'], 2);
                            if ($order['paid_upi'] > 0)
                                $bd_parts[] = 'UPI: ₹' . number_format($order['paid_upi'], 2);
                            if ($order['paid_card'] > 0)
                                $bd_parts[] = 'Card: ₹' . number_format($order['paid_card'], 2);
                        }
                        echo implode(' • ', $bd_parts);
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($upi_qr_url)): ?>
            <div class="thermal-divider"></div>
            <div style="text-align: center; margin: 6px 0;">
                <div style="font-weight: bold; font-size: 10px; margin-bottom: 3px; text-transform: uppercase;">SCAN &amp;
                    PAY VIA UPI</div>
                <img src="<?= h($upi_qr_url) ?>" alt="UPI Payment QR"
                    style="width: 110px; height: 110px; display: block; margin: 0 auto; border: 1px solid #000000; padding: 2px; background: #ffffff;">
                <div style="font-size: 9px; margin-top: 3px; font-family: monospace; font-weight: bold;">
                    <?= h($settings['company_upi_id']) ?></div>
                <div style="font-size: 9px;">Amount: ₹<?= number_format($net_invoice_amount, 2) ?></div>
            </div>
        <?php endif; ?>

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

        const doPrint = function () {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                console.error("Iframe print error, falling back to popup window", e);
                const printWin = window.open('', '_blank', 'width=420,height=600');
                if (printWin) {
                    printWin.document.write(doc.documentElement.outerHTML);
                    printWin.document.close();
                    printWin.onload = function () {
                        printWin.print();
                        setTimeout(() => printWin.close(), 600);
                    };
                } else {
                    alert("Pop-up blocked. Please allow pop-ups for thermal printing.");
                }
            }
        };

        const qrImg = iframe.contentWindow.document.querySelector('img');
        if (qrImg && !qrImg.complete) {
            qrImg.onload = function () { setTimeout(doPrint, 150); };
            qrImg.onerror = function () { doPrint(); };
            setTimeout(doPrint, 1500); // Fallback timeout
        } else {
            setTimeout(doPrint, 300);
        }
    }

    function downloadPDF() {
        const element = document.getElementById('invoicePaper');
        const invoiceNo = '<?= h($order['invoice_number']) ?>';

        const opt = {
            margin: [5, 5, 5, 5],
            filename: `Invoice_${invoiceNo}.pdf`,
            image: { type: 'jpeg', quality: 1.0 },
            html2canvas: {
                scale: 3,
                useCORS: true,
                logging: false,
                windowWidth: 1150
            },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().from(element).set(opt).save();
    }

    function shareWhatsApp() {
        let customerName = '<?= h(addslashes($order['customer_name'] ?? 'Customer')) ?>';
        if (!customerName || customerName === 'Walk-in Customer') customerName = 'Valued Customer';

        const invoiceNo = '<?= h($order['invoice_number']) ?>';
        const amount = '₹<?= number_format($order['final_amount'], 2) ?>';
        const basePath = window.location.pathname.includes('/admin/')
            ? window.location.pathname.substring(0, window.location.pathname.indexOf('/admin/'))
            : window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        const publicUrl = window.location.origin + basePath + '/view-receipt.php?inv=' + encodeURIComponent(invoiceNo);

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
    document.addEventListener("DOMContentLoaded", function () {
        if (window.location.search.includes('print=1') || window.location.search.includes('thermal=1')) {
            printThermal();
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>