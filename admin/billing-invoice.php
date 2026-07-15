<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();

$db = get_db_connection();
$id = (int)($_GET['id'] ?? 0);
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

// Fetch settings
$settings_res = $db->query("SELECT * FROM settings")->fetchAll();
$settings = [];
foreach ($settings_res as $row) {
    $settings[$row['key']] = $row['value'];
}

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
        }

        .receipt-wrapper {
            padding: 0 !important;
            display: block !important;
        }

        .thermal-receipt {
            width: 80mm !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            margin: 0 !important;
            padding: 5px !important;
            font-size: 11px; /* Slightly smaller for roll margin safety */
        }
        
        @page {
            size: auto;
            margin: 0;
        }
    }
</style>

<!-- Control Bar -->
<div class="receipt-control-bar">
    <div style="display:flex; align-items:center; gap:0.75rem;">
        <a href="billing.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> New Sale / POS
        </a>
        <span style="color:var(--text-secondary); font-weight:600;"><?= h($order['invoice_number']) ?></span>
    </div>
    <div>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa-solid fa-print"></i> Print Receipt
        </button>
    </div>
</div>

<!-- Receipt Center Wrapper -->
<div class="receipt-wrapper">
    <div class="thermal-receipt">
        <!-- Header -->
        <div class="receipt-header">
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
                            <?php if (!empty($item['variant_size'])): ?>
                                <div style="font-size:9px; color:#555555;">Size: <?= h($item['variant_size']) ?></div>
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
                <span><?= h(strtoupper($order['payment_method'])) ?></span>
            </div>
        </div>

        <div class="receipt-divider"></div>

        <!-- Footer -->
        <div class="receipt-footer">
            <div style="font-weight:bold; margin-bottom:4px;">Thank you for shopping with us!</div>
            <div>Have a great celebration!</div>
            <div>--- Visit Us Again ---</div>
        </div>
    </div>
</div>

<script>
    // Auto trigger print dialog on page load
    window.addEventListener('load', () => {
        setTimeout(() => {
            window.print();
        }, 300);
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
