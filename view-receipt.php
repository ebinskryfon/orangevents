<?php
require_once __DIR__ . '/config/database.php';

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$db = get_db_connection();

$order = null;
$inv_param = trim($_GET['inv'] ?? $_GET['invoice'] ?? $_GET['inv_no'] ?? '');
$id_param = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);

if ($inv_param !== '') {
    $stmt_order = $db->prepare("SELECT * FROM billing_orders WHERE invoice_number = :inv");
    $stmt_order->execute(['inv' => $inv_param]);
    $order = $stmt_order->fetch();
} elseif ($id_param > 0) {
    $stmt_order = $db->prepare("SELECT * FROM billing_orders WHERE id = :id");
    $stmt_order->execute(['id' => $id_param]);
    $order = $stmt_order->fetch();
}

if (!$order) {
    die("<div style='font-family:sans-serif; text-align:center; padding:4rem 1rem; color:#ef4444; background:#f8fafc; min-height:100vh; display:flex; align-items:center; justify-content:center;'>
            <div style='max-width:380px; width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; padding:2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05);'>
                <div style='width:60px; height:60px; background:#fee2e2; color:#ef4444; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1rem auto; font-size:1.5rem;'>
                    <i class=\"fa-solid fa-receipt\"></i>
                </div>
                <h3 style='font-size:1.3rem; color:#0f172a; margin-bottom:0.4rem; font-weight:700;'>Invoice Not Found</h3>
                <p style='color:#64748b; font-size:0.85rem; line-height:1.4;'>The requested digital invoice receipt could not be found or the link has expired.</p>
            </div>
         </div>");
}

$id = (int)$order['id'];

// Fetch order items
$stmt_items = $db->prepare("SELECT * FROM billing_order_items WHERE order_id = :id ORDER BY id ASC");
$stmt_items->execute(['id' => $id]);
$items = $stmt_items->fetchAll();

// Fetch settings
$settings_res = $db->query("SELECT * FROM settings")->fetchAll();
$settings = [];
foreach ($settings_res as $row) {
    $settings[$row['key']] = $row['value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>E-Receipt <?= h($order['invoice_number']) ?> - <?= h($settings['company_name'] ?? 'Orange Events') ?></title>
    <!-- FontAwesome Icon CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        :root {
            --bg-page: #f1f5f9;
            --bg-card: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --accent-orange: #ff5e00;
            --accent-orange-light: rgba(255, 94, 0, 0.08);
            --border-light: #e2e8f0;
            --success-green: #16a34a;
            --success-light: #dcfce7;
            --radius-lg: 20px;
            --radius-md: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        .ereceipt-wrapper {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            padding: 1.25rem 1rem 3rem 1rem;
            box-sizing: border-box;
        }

        /* Card Layout */
        .ereceipt-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        /* Top Brand Header */
        .brand-banner {
            padding: 1.5rem 1.25rem 1.25rem 1.25rem;
            text-align: center;
            border-bottom: 1px dashed var(--border-light);
            background: #ffffff;
        }

        .brand-logo {
            height: 46px;
            width: auto;
            margin-bottom: 0.4rem;
        }

        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }

        .brand-title span {
            color: var(--accent-orange);
        }

        .brand-subtitle {
            font-size: 0.78rem;
            color: var(--text-secondary);
            margin-top: 0.15rem;
            font-weight: 500;
        }

        .brand-contact-info {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            line-height: 1.4;
        }

        /* Hero Total Box */
        .hero-total-box {
            background: linear-gradient(180deg, #fafafa 0%, #f1f5f9 100%);
            padding: 1.35rem 1.25rem;
            text-align: center;
            border-bottom: 1px solid var(--border-light);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: var(--success-light);
            color: var(--success-green);
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.3rem 0.85rem;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.65rem;
            border: 1px solid rgba(22, 163, 74, 0.2);
        }

        .hero-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hero-amount {
            font-family: 'Outfit', sans-serif;
            font-size: 2.3rem;
            font-weight: 800;
            color: var(--accent-orange);
            margin: 0.15rem 0 0.35rem 0;
            line-height: 1;
        }

        .hero-inv-details {
            font-size: 0.82rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        /* Customer & Meta Info Grid */
        .info-section {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-light);
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .info-item-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .info-item-val {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.35;
        }

        .info-item-sub {
            font-size: 0.78rem;
            color: var(--text-secondary);
            margin-top: 0.15rem;
        }

        /* Items Section */
        .items-section {
            padding: 1.25rem;
        }

        .section-heading {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .item-card-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 0.75rem 0;
            border-bottom: 1px dashed var(--border-light);
        }

        .item-card-row:last-child {
            border-bottom: none;
        }

        .item-name-group {
            flex: 1;
            padding-right: 0.75rem;
        }

        .item-title {
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .item-meta-tag {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.2rem;
        }

        .item-qty-calc {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
            font-weight: 500;
        }

        .item-price-total {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            color: var(--text-primary);
            white-space: nowrap;
            text-align: right;
        }

        /* Summary Math Box */
        .summary-box {
            background: #f8fafc;
            border-radius: var(--radius-md);
            padding: 1rem 1.15rem;
            margin-top: 1rem;
            border: 1px solid var(--border-light);
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            font-size: 0.82rem;
            color: var(--text-secondary);
            padding: 0.25rem 0;
        }

        .summary-line.total-line {
            border-top: 1px dashed var(--border-light);
            margin-top: 0.4rem;
            padding-top: 0.6rem;
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text-primary);
        }

        .summary-line.total-line .total-val {
            color: var(--accent-orange);
            font-family: 'Outfit', sans-serif;
        }

        /* Actions Container */
        .actions-container {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            margin-top: 1.25rem;
        }

        .btn-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            height: 48px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .btn-whatsapp {
            background: #25d366;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.25);
        }

        .btn-whatsapp:active {
            transform: scale(0.98);
        }

        .btn-pdf {
            background: var(--text-primary);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.15);
        }

        .btn-print {
            background: #ffffff;
            color: var(--text-primary);
            border: 1px solid var(--border-light);
        }

        .receipt-footer-support {
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .receipt-footer-support a {
            color: var(--accent-orange);
            text-decoration: none;
            font-weight: 700;
        }

        /* Print Override */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .ereceipt-wrapper {
                max-width: 100% !important;
                padding: 0 !important;
            }
            .ereceipt-card {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
            }
            .actions-container, .receipt-footer-support {
                display: none !important;
            }
            @page {
                size: auto;
                margin: 8mm;
            }
        }
    </style>
</head>
<body>

<div class="ereceipt-wrapper">

    <!-- Digital Receipt Card -->
    <div class="ereceipt-card" id="eReceiptContent">
        
        <!-- Brand Header -->
        <div class="brand-banner">
            <img src="assets/images/logo.png" alt="Logo" class="brand-logo">
            <div class="brand-title"><?= h($settings['company_name'] ?? 'Orange Events') ?></div>
            <div class="brand-subtitle"><?= h($settings['company_subtitle'] ?? 'Premium Catering & Stage Decors') ?></div>
            <div class="brand-contact-info">
                <?= h($settings['company_address'] ?? 'Thumpoly P.O, Alappuzha') ?><br>
                Phone: <?= h($settings['company_phone'] ?? '9946731720') ?> | Email: <?= h($settings['company_email'] ?? 'orangedecorations@gmail.com') ?>
                <?php if (!empty($settings['company_gstin'] ?? '')): ?>
                    <br>GSTIN: <?= h($settings['company_gstin']) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Hero Amount & Status Header -->
        <div class="hero-total-box">
            <div class="status-pill">
                <i class="fa-solid fa-circle-check"></i> Payment Settled
            </div>
            <div class="hero-label">Total Amount Paid</div>
            <div class="hero-amount">₹<?= number_format($order['final_amount'], 2) ?></div>
            <div class="hero-inv-details">
                Invoice <strong><?= h($order['invoice_number']) ?></strong> • <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
            </div>
        </div>

        <!-- Metadata Section (Billed To & Payment Method) -->
        <div class="info-section">
            <div class="info-grid">
                <div>
                    <div class="info-item-label">Billed To</div>
                    <div class="info-item-val"><?= h($order['customer_name'] ?: 'Walk-in Customer') ?></div>
                    <?php if (!empty($order['customer_phone'])): ?>
                        <div class="info-item-sub"><i class="fa-solid fa-phone" style="font-size:0.68rem; color:var(--text-muted);"></i> <?= h($order['customer_phone']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_address'])): ?>
                        <div class="info-item-sub" style="margin-top:0.2rem;"><?= nl2br(h($order['customer_address'])) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="info-item-label">Payment Mode</div>
                    <div class="info-item-val">
                        <?php if ($order['payment_method'] === 'Split' || !empty($order['payment_breakdown'])): ?>
                            <span style="color:var(--accent-orange);">SPLIT PAYMENT</span>
                            <div class="info-item-sub">
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
                                echo implode('<br>', $bd_parts);
                                ?>
                            </div>
                        <?php else: ?>
                            <?= h(strtoupper($order['payment_method'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Items Breakdown -->
        <div class="items-section">
            <div class="section-heading">
                <span>Items Purchased</span>
                <span><?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?></span>
            </div>

            <?php foreach ($items as $item): ?>
                <div class="item-card-row">
                    <div class="item-name-group">
                        <div class="item-title"><?= h($item['product_name']) ?></div>
                        <?php if (!empty($item['variant_size']) || (!empty($item['sell_type']) && $item['sell_type'] === 'rental')): ?>
                            <div class="item-meta-tag">
                                <?php if (!empty($item['variant_size'])): ?>
                                    Variant: <?= h($item['variant_size']) ?>
                                <?php endif; ?>
                                <?php if (!empty($item['sell_type']) && $item['sell_type'] === 'rental'): ?>
                                    (Rental)
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="item-qty-calc">
                            <?= (float)$item['quantity'] ?> × ₹<?= number_format($item['price'], 2) ?>
                        </div>
                    </div>
                    <div class="item-price-total">
                        ₹<?= number_format($item['total_price'], 2) ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Financial Summary Box -->
            <div class="summary-box">
                <div class="summary-line">
                    <span>Subtotal</span>
                    <span>₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                    <div class="summary-line" style="color:#ef4444;">
                        <span>Discount Savings</span>
                        <span>-₹<?= number_format($order['discount_amount'], 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="summary-line total-line">
                    <span>Net Paid</span>
                    <span class="total-val">₹<?= number_format($order['final_amount'], 2) ?></span>
                </div>
            </div>
        </div>

    </div>

    <!-- Mobile Action Buttons -->
    <div class="actions-container">
        <button onclick="shareWhatsApp()" class="btn-action btn-whatsapp">
            <i class="fa-brands fa-whatsapp" style="font-size:1.15rem;"></i> Share via WhatsApp
        </button>
        <button onclick="downloadPDF()" class="btn-action btn-pdf">
            <i class="fa-solid fa-file-pdf"></i> Download PDF Receipt
        </button>
        <button onclick="window.print()" class="btn-action btn-print">
            <i class="fa-solid fa-print"></i> Print Receipt
        </button>
    </div>

    <!-- Footer Note -->
    <div class="receipt-footer-support">
        Thank you for choosing <strong><?= h($settings['company_name'] ?? 'Orange Events') ?></strong>!<br>
        Questions? Contact us at <a href="tel:<?= h($settings['company_phone'] ?? '9946731720') ?>"><?= h($settings['company_phone'] ?? '9946731720') ?></a>
    </div>

</div>

<script>
    function downloadPDF() {
        const element = document.getElementById('eReceiptContent');
        const invoiceNo = '<?= h($order['invoice_number']) ?>';
        
        const opt = {
            margin:       [5, 5, 5, 5],
            filename:     `EReceipt_${invoiceNo}.pdf`,
            image:        { type: 'jpeg', quality: 1.0 },
            html2canvas:  { 
                scale: 3, 
                useCORS: true, 
                logging: false,
                windowWidth: 500
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
</script>

</body>
</html>
