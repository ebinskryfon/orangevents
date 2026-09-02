<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Allow admin auth or public token/inv query
$is_admin = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);

$db = get_db_connection();

$id = (int) ($_GET['id'] ?? $_GET['order_id'] ?? 0);
$inv_number = trim($_GET['invoice_no'] ?? $_GET['inv'] ?? '');

$order = null;
if ($id > 0) {
    $stmt_order = $db->prepare("SELECT * FROM billing_orders WHERE id = :id");
    $stmt_order->execute(['id' => $id]);
    $order = $stmt_order->fetch();
} elseif ($inv_number !== '') {
    $stmt_order = $db->prepare("SELECT * FROM billing_orders WHERE invoice_number = :inv");
    $stmt_order->execute(['inv' => $inv_number]);
    $order = $stmt_order->fetch();
}

if (!$order) {
    die("<div style='font-family:sans-serif; text-align:center; padding:3rem; color:#dc2626;'>
            <h2>Invoice Not Found</h2>
            <p>The requested invoice could not be found.</p>
         </div>");
}

$id = (int) $order['id'];

// Fetch order items with Category Name
$stmt_items = $db->prepare("
    SELECT i.*, p.category_id, c.category_name 
    FROM billing_order_items i
    LEFT JOIN billing_products p ON i.product_id = p.id
    LEFT JOIN billing_categories c ON p.category_id = c.id
    WHERE i.order_id = :id
    ORDER BY c.display_order ASC, c.id ASC, i.id ASC
");
$stmt_items->execute(['id' => $id]);
$items = $stmt_items->fetchAll();

// Fetch returns if any
$stmt_returns = $db->prepare("SELECT * FROM billing_returns WHERE order_id = :id ORDER BY id DESC");
$stmt_returns->execute(['id' => $id]);
$returns_list = $stmt_returns->fetchAll();

$total_returned_amount = 0;
foreach ($returns_list as $ret) {
    $total_returned_amount += (float) $ret['refund_amount'];
}
$net_invoice_amount = max(0, (float) $order['final_amount'] - $total_returned_amount);

// Fetch store settings
$settings_res = $db->query("SELECT * FROM settings")->fetchAll();
$settings = [];
foreach ($settings_res as $row) {
    $settings[$row['key']] = $row['value'];
}

$company_name = $settings['company_name'] ?? 'Aedan Gardens';
$company_subtitle = $settings['company_subtitle'] ?? '(Plant Nursery & Garden Center)';
$company_address = $settings['company_address'] ?? "Thumpoly P.O Alappuzha\nAlappuzha";
$company_email = $settings['company_email'] ?? 'aedangardens04@gmail.com';
$company_state = $settings['company_state'] ?? '32-Kerala';
$place_of_supply = $settings['place_of_supply'] ?? '32 Kerala';

$bank_name = $settings['company_bank_name'] ?? 'STATE BANK OF INDIA';
$bank_acc = $settings['company_bank_acc'] ?? '40598127711';
$bank_ifsc = $settings['company_bank_ifsc'] ?? 'SBIN0000807';
$bank_holder = $settings['company_bank_holder'] ?? strtoupper($company_name);

$upi_id = $settings['company_upi_id'] ?? '8590594735@okbizaxis';
$upi_qr_url = generate_upi_qr_code_url($net_invoice_amount, $order['invoice_number']);

$invoice_desc = $settings['invoice_description'] ?? 'Healthy plants with care instructions. Returns accepted within 7 days for damaged plants only.';
$raw_terms = $settings['invoice_terms'] ?? 'Thanks for choosing {company_name}! Total items: {total_items}';
$formatted_terms = str_replace(
    ['{company_name}', '{total_items}'],
    [$company_name, count($items)],
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

// Convert amount to words
$amount_in_words = convert_number_to_words($net_invoice_amount);
if (!empty($amount_in_words)) {
    $amount_in_words = ucwords(trim($amount_in_words)) . ' only';
} else {
    $amount_in_words = 'Zero only';
}

$auto_print = isset($_GET['print']) || isset($_GET['auto_print']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= h($order['invoice_number']) ?></title>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        :root {
            --primary-color: #2b7b38;
            --text-dark: #000000;
            --border-color: #000000;
            --bg-gray: #f2f2f2;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #e2e8f0;
            color: #000000;
            font-size: 13px;
            line-height: 1.35;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Screen Control Bar */
        .no-print-bar {
            background: #1e293b;
            color: #ffffff;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .no-print-bar .title {
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .no-print-bar .btn-group {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            background: #ff6b35;
            color: #ffffff;
            border: none;
            padding: 0.45rem 0.9rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-action:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-action.btn-secondary {
            background: #475569;
        }

        .btn-action.btn-success {
            background: #25d366;
        }

        /* Invoice Container Page */
        .invoice-page-wrapper {
            max-width: 800px;
            margin: 20px auto;
            background: #ffffff;
            padding: 24px 28px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        /* Header Section */
        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .inv-brand {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .inv-brand img {
            max-height: 46px;
            width: auto;
            object-fit: contain;
        }

        .company-name {
            font-size: 17px;
            font-weight: 800;
            color: #2b7b38;
            letter-spacing: -0.3px;
            line-height: 1.15;
        }

        .company-sub {
            font-size: 10.5px;
            font-weight: 600;
            color: #333333;
            margin-top: 1px;
        }

        .company-details {
            font-size: 10px;
            color: #111111;
            margin-top: 3px;
            line-height: 1.35;
        }

        .inv-meta {
            text-align: right;
        }

        .inv-title {
            font-size: 15px;
            font-weight: 800;
            color: #000000;
            text-transform: capitalize;
            margin-bottom: 3px;
        }

        .inv-meta-row {
            font-size: 10.5px;
            font-weight: 600;
            color: #111111;
            margin-top: 2px;
        }

        /* Customer Section */
        .bill-to-box {
            margin-bottom: 14px;
            font-size: 10.5px;
            color: #000000;
            line-height: 1.35;
        }

        .bill-to-title {
            font-size: 11.5px;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .customer-name {
            font-weight: 700;
            text-transform: uppercase;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #000000;
            padding: 6px 8px;
            font-size: 11.5px;
        }

        .items-table th {
            background-color: #ffffff;
            font-weight: 700;
            text-align: left;
        }

        .items-table th.center,
        .items-table td.center {
            text-align: center;
        }

        .items-table th.right,
        .items-table td.right {
            text-align: right;
        }

        .total-row-cell {
            font-weight: 800;
            font-size: 12px;
        }

        /* Two Column Layout below table */
        .middle-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 16px;
            align-items: stretch;
            margin-bottom: 16px;
        }

        .left-stacked {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .bordered-box {
            border: 1px solid #000000;
            padding: 8px 10px;
            background: #ffffff;
        }

        .box-title {
            font-weight: 800;
            font-size: 12px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }

        .box-content {
            font-size: 11.5px;
            color: #000000;
            line-height: 1.35;
        }

        /* Summary Box Right */
        .summary-box {
            border: 1px solid #000000;
            background: #ffffff;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
        }

        .summary-row.total {
            font-weight: 800;
            border-bottom: 1px solid #000000;
            font-size: 12.5px;
        }

        .summary-row.payment-method {
            border-bottom: none;
            font-weight: 700;
            padding-top: 6px;
        }

        /* Terms & Conditions Header Banner */
        .terms-banner {
            background-color: #f2f2f2;
            padding: 5px 10px;
            font-weight: 800;
            font-size: 12px;
            color: #000000;
            margin-bottom: 6px;
            border-radius: 2px;
        }

        .terms-text {
            font-size: 11.5px;
            color: #000000;
            margin-bottom: 16px;
        }

        /* Footer Section */
        .footer-grid {
            display: grid;
            grid-template-columns: 1.1fr 1.3fr 1fr;
            gap: 14px;
            align-items: flex-end;
            margin-top: 10px;
        }

        /* Scan UPI Box */
        .upi-box {
            border: 1.5px dashed #000000;
            padding: 8px;
            text-align: center;
        }

        .upi-box-title {
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .upi-qr-img {
            width: 105px;
            height: 105px;
            object-fit: contain;
            display: block;
            margin: 0 auto 4px auto;
        }

        .upi-text {
            font-size: 9.5px;
            font-weight: 700;
            line-height: 1.3;
        }

        /* Bank Details Box */
        .bank-details-box {
            font-size: 11.5px;
            line-height: 1.45;
        }

        .bank-header-banner {
            background-color: #f2f2f2;
            padding: 4px 8px;
            font-weight: 800;
            font-size: 11.5px;
            margin-bottom: 6px;
        }

        .bank-row {
            display: flex;
            gap: 8px;
            font-size: 11px;
        }

        .bank-label {
            width: 55px;
            font-weight: 600;
            color: #333;
        }

        .bank-val {
            font-weight: 700;
            color: #000;
        }

        /* Signatory Box */
        .signatory-box {
            border: 1px solid #000000;
            height: 85px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 8px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
        }

        /* Print Media Styles */
        @media print {
            @page {
                size: A4 portrait;
                margin: 6mm 8mm;
            }

            body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .no-print-bar {
                display: none !important;
            }

            .invoice-page-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }

            .terms-banner,
            .bank-header-banner {
                background-color: #f2f2f2 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>

<body>

    <!-- Top Action Bar (Hidden when printing) -->
    <div class="no-print-bar">
        <div class="title">
            <i class="fa-solid fa-file-invoice" style="color:#ff6b35;"></i>
            Tax Invoice <?= h($order['invoice_number']) ?>
        </div>
        <div class="btn-group">
            <?php if ($is_admin): ?>
                <a href="billing-invoice.php?id=<?= $order['id'] ?>" class="btn-action btn-secondary">
                    <i class="fa-solid fa-arrow-left"></i> Back to Invoice
                </a>
            <?php endif; ?>
            <button onclick="shareWhatsApp()" class="btn-action btn-success">
                <i class="fa-brands fa-whatsapp"></i> Share WhatsApp
            </button>
            <button type="button" id="toggleQrBtn" onclick="toggleQRCode()" class="btn-action btn-secondary">
                <i class="fa-solid fa-qrcode"></i> <span id="qrBtnText">Hide Payment QR</span>
            </button>
            <button onclick="downloadPDF()" class="btn-action btn-secondary">
                <i class="fa-solid fa-file-pdf"></i> Save PDF
            </button>
            <button onclick="window.print()" class="btn-action">
                <i class="fa-solid fa-print"></i> Print Invoice (A4)
            </button>
        </div>
    </div>

    <!-- Printable Invoice Page Area -->
    <div class="invoice-page-wrapper" id="invoicePaper">

        <!-- 1. Header Row -->
        <div class="inv-header">
            <div class="inv-brand">
                <img src="../assets/images/logo.png" alt="Logo" onerror="this.style.display='none'">
                <div>
                    <div class="company-name"><?= h($company_name) ?></div>
                    <div class="company-sub"><?= h($company_subtitle) ?></div>
                    <div class="company-details">
                        <?= nl2br(h($company_address)) ?><br>
                        Email: <?= h($company_email) ?><br>
                        State: <?= h($company_state) ?>
                        <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                            <br>GSTIN: <?= h($settings['company_gstin']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="inv-meta">
                <div class="inv-title">Tax Invoice</div>
                <div class="inv-meta-row">Invoice No: <?= h($order['invoice_number']) ?></div>
                <div class="inv-meta-row">Date & Time: <?= format_datetime($order['created_at']) ?></div>
                <div class="inv-meta-row">Place of Supply: <?= h($place_of_supply) ?></div>
            </div>
        </div>

        <!-- 2. Bill To Box -->
        <div class="bill-to-box">
            <div class="bill-to-title">Bill To:</div>
            <div class="customer-name"><?= h($order['customer_name'] ?: 'WALK-IN CUSTOMER') ?></div>
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
            <div>State: <?= h($company_state) ?></div>
        </div>

        <!-- 3. Line Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="center" style="width: 6%;">SI No</th>
                    <th style="width: 30%;">Item Name</th>
                    <th style="width: 16%;">Category</th>
                    <th class="center" style="width: 12%;">Size</th>
                    <th class="center" style="width: 9%;">Quantity</th>
                    <th class="center" style="width: 7%;">Unit</th>
                    <th class="right" style="width: 10%;">Price/Unit</th>
                    <th class="right" style="width: 10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $sl = 1;
                foreach ($items as $item): ?>
                    <tr>
                        <td class="center"><?= $sl++ ?></td>
                        <td><strong><?= h($item['product_name']) ?></strong></td>
                        <td><?= h($item['category_name'] ?: 'General') ?></td>
                        <td class="center"><?= h($item['variant_size'] ?: 'Regular') ?></td>
                        <td class="center"><?= (float) $item['quantity'] ?></td>
                        <td class="center">Nos</td>
                        <td class="right">₹<?= number_format($item['price'], 2) ?></td>
                        <td class="right">₹<?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Table Footer Total Row -->
                <tr>
                    <td colspan="6"></td>
                    <td class="right total-row-cell">Total</td>
                    <td class="right total-row-cell">₹<?= number_format($order['total_amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- 4. Middle Grid: Single Combined Left Box & Right Financial Summary Box -->
        <div class="middle-grid">
            <div class="bordered-box"
                style="display: flex; flex-direction: column; justify-content: space-between; padding: 0; overflow: hidden;">
                <div style="padding: 8px 10px;">
                    <div class="box-title">Invoice Amount in Words</div>
                    <div class="box-content"><?= h($amount_in_words) ?></div>
                </div>

                <div style="border-top: 1px solid #000000; padding: 8px 10px;">
                    <div class="box-title">Description</div>
                    <div class="box-content"><?= h($invoice_desc) ?></div>
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-row">
                    <span>Sub Total</span>
                    <span>₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                    <div class="summary-row" style="color: #dc2626;">
                        <span>Discount</span>
                        <span>-₹<?= number_format($order['discount_amount'], 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="summary-row total">
                    <span>Total</span>
                    <span>₹<?= number_format($net_invoice_amount, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Received</span>
                    <span>₹<?= number_format($net_invoice_amount, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Balance</span>
                    <span>₹0.00</span>
                </div>
                <div class="summary-row payment-method">
                    <span>Payment Method:</span>
                    <span><?= h(strtoupper($order['payment_method'])) ?></span>
                </div>
            </div>
        </div>

        <!-- 5. Terms and Conditions -->
        <div class="terms-banner">Terms and Conditions</div>
        <div class="terms-text">
            <?= nl2br(h($formatted_terms)) ?>
        </div>

        <!-- 6. Footer Section: UPI QR + Bank Details + Signature & Barcode (Opposite Right Corner) -->
        <div class="footer-grid">
            <!-- Left Column: UPI Scan Box -->
            <div class="upi-box">
                <div class="upi-box-title">Scan to Pay with UPI</div>
                <?php if (!empty($upi_qr_url)): ?>
                    <img src="<?= h($upi_qr_url) ?>" alt="Scan to pay" class="upi-qr-img">
                <?php endif; ?>
                <div class="upi-text">
                    UPI ID: <?= h($upi_id) ?><br>
                    Payee: <?= h(strtoupper($company_name)) ?>
                </div>
            </div>

            <!-- Middle Column: Bank Details -->
            <div class="bank-details-box">
                <div class="bank-header-banner">Company's Bank Details:</div>
                <div class="bank-row">
                    <div class="bank-label">Bank:</div>
                    <div class="bank-val"><?= h($bank_name) ?></div>
                </div>
                <div class="bank-row">
                    <div class="bank-label">Acc No.:</div>
                    <div class="bank-val"><?= h($bank_acc) ?></div>
                </div>
                <div class="bank-row">
                    <div class="bank-label">IFSC:</div>
                    <div class="bank-val"><?= h($bank_ifsc) ?></div>
                </div>
                <div class="bank-row">
                    <div class="bank-label">Name:</div>
                    <div class="bank-val"><?= h($bank_holder) ?></div>
                </div>
            </div>

            <!-- Right Column (Opposite Side): Signatory Box & Unbordered Invoice Barcode -->
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div class="signatory-box">
                    <div>For, <?= h($company_name) ?></div>
                    <div>Authorized Signatory</div>
                </div>

                <!-- Invoice Barcode (Short High-Density Numeric Code 128) -->
                <div style="display: flex; justify-content: center; width: 100%; height: 42px; margin-top: 6px;">
                    <?= generate_barcode_svg(get_numeric_invoice_barcode($order['invoice_number'], $order['id']), 42, 2.2) ?>
                </div>
            </div>
        </div>

    </div>

    <script>
        function toggleQRCode() {
            const upiBox = document.querySelector('.upi-box');
            const btnText = document.getElementById('qrBtnText');
            if (!upiBox) return;

            if (upiBox.style.display === 'none') {
                upiBox.style.display = 'block';
                if (btnText) btnText.innerText = 'Hide Payment QR';
                localStorage.setItem('orange_invoice_qr_disabled', '0');
            } else {
                upiBox.style.display = 'none';
                if (btnText) btnText.innerText = 'Show Payment QR';
                localStorage.setItem('orange_invoice_qr_disabled', '1');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('orange_invoice_qr_disabled') === '1') {
                const upiBox = document.querySelector('.upi-box');
                const btnText = document.getElementById('qrBtnText');
                if (upiBox) upiBox.style.display = 'none';
                if (btnText) btnText.innerText = 'Show Payment QR';
            }
        });

        function downloadPDF() {
            const element = document.getElementById('invoicePaper');
            const invoiceNo = '<?= h($order['invoice_number']) ?>';

            const opt = {
                margin: [4, 4, 4, 4],
                filename: `Invoice_${invoiceNo}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().from(element).set(opt).save();
        }

        function shareWhatsApp() {
            let customerName = '<?= h(addslashes($order['customer_name'] ?? 'Customer')) ?>';
            if (!customerName || customerName === 'WALK-IN CUSTOMER') customerName = 'Valued Customer';

            const invoiceNo = '<?= h($order['invoice_number']) ?>';
            const amount = '₹<?= number_format($order['final_amount'], 2) ?>';
            const publicUrl = window.location.href;

            let rawPhone = '<?= h($order['customer_phone'] ?? '') ?>';
            let cleanPhone = rawPhone.replace(/[^0-9]/g, '');

            if (!cleanPhone) {
                const inputPhone = prompt(`Share E-Invoice ${invoiceNo} via WhatsApp\n\nEnter 10-digit Phone Number:`, '');
                if (inputPhone === null) return;
                cleanPhone = inputPhone.replace(/[^0-9]/g, '');
            }

            const messageText = `Hello *${customerName}* 👋,\n\nThank you for shopping at *<?= h(addslashes($company_name)) ?>*!\nHere is your Tax Invoice *${invoiceNo}* for *${amount}*.\n\nView Tax Invoice Link:\n${publicUrl}\n\nHave a great day!`;

            const encodedText = encodeURIComponent(messageText);
            let whatsappUrl = `https://api.whatsapp.com/send?text=${encodedText}`;
            if (cleanPhone.length >= 10) {
                let phone = cleanPhone.length === 10 ? '91' + cleanPhone : cleanPhone;
                whatsappUrl = `https://api.whatsapp.com/send?phone=${phone}&text=${encodedText}`;
            }

            window.open(whatsappUrl, '_blank');
        }

        <?php if ($auto_print): ?>
            window.addEventListener('load', function () {
                setTimeout(function () {
                    window.print();
                }, 400);
            });
        <?php endif; ?>
    </script>
</body>

</html>