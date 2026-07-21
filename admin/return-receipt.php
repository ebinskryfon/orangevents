<?php
/**
 * Return Credit Note & Refund Receipt View
 * Thermal receipt layout formatted for 80mm POS printers.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$return_id = (int)($_GET['id'] ?? 0);

if ($return_id <= 0) {
    header("Location: billing.php");
    exit;
}

// Fetch Return Record
$stmt = $db->prepare("
    SELECT r.*, o.customer_name, o.customer_phone, o.created_at AS original_order_date
      FROM billing_returns r
      JOIN billing_orders o ON o.id = r.order_id
     WHERE r.id = :id
     LIMIT 1
");
$stmt->execute(['id' => $return_id]);
$return_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$return_data) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Return record not found.</div></div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch Returned Items
$stmt_items = $db->prepare("SELECT * FROM billing_return_items WHERE return_id = :return_id");
$stmt_items->execute(['return_id' => $return_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.receipt-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 1.5rem 0;
}
.thermal-receipt {
    width: 80mm;
    background: #ffffff;
    color: #000000;
    font-family: 'Courier New', Courier, monospace;
    font-size: 12px;
    padding: 15px;
    border-radius: 4px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    border: 1px solid #ddd;
}
.receipt-header {
    text-align: center;
    border-bottom: 1px dashed #000;
    padding-bottom: 10px;
    margin-bottom: 10px;
}
.receipt-header h2 {
    font-size: 16px;
    font-weight: bold;
    margin: 0 0 4px 0;
    text-transform: uppercase;
}
.receipt-header p {
    margin: 2px 0;
    font-size: 11px;
}
.receipt-badge {
    display: inline-block;
    background: #000;
    color: #fff;
    font-size: 11px;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 3px;
    margin-top: 4px;
    text-transform: uppercase;
}
.receipt-meta {
    border-bottom: 1px dashed #000;
    padding-bottom: 8px;
    margin-bottom: 8px;
    font-size: 11px;
}
.receipt-meta-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2px;
}
.receipt-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 10px;
    font-size: 11px;
}
.receipt-table th {
    border-bottom: 1px solid #000;
    text-align: left;
    padding: 4px 0;
}
.receipt-table td {
    padding: 4px 0;
}
.text-right {
    text-align: right;
}
.receipt-total {
    border-top: 1px dashed #000;
    border-bottom: 1px dashed #000;
    padding: 8px 0;
    margin-bottom: 10px;
    font-weight: bold;
    font-size: 13px;
}
.receipt-footer {
    text-align: center;
    font-size: 10px;
    margin-top: 10px;
}
@media print {
    body * {
        visibility: hidden;
    }
    .receipt-wrapper, .receipt-wrapper * {
        visibility: visible;
    }
    .receipt-wrapper {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0;
    }
    .thermal-receipt {
        box-shadow: none;
        border: none;
        width: 80mm;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<div class="content-header no-print" style="margin-bottom: 1rem; display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1 style="font-size:1.3rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-rotate-left" style="color:var(--accent-color);"></i> Return Voucher / Credit Note
        </h1>
        <p style="color:var(--text-secondary); margin:2px 0 0; font-size:0.75rem;">
            Refund Credit Note #<?= h($return_data['return_number']) ?>
        </p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <button onclick="window.print()" class="btn btn-primary" style="height:32px; font-size:0.75rem;">
            <i class="fa-solid fa-print"></i> Print Voucher
        </button>

        <a href="billing.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to POS
        </a>
    </div>
</div>

<div class="receipt-wrapper">
    <div class="thermal-receipt">
        <div class="receipt-header">
            <h2>ORANGE EVENTS</h2>
            <p>Catering & Event Management</p>
            <div class="receipt-badge">RETURN CREDIT NOTE</div>
        </div>

        <div class="receipt-meta">
            <div class="receipt-meta-row">
                <span>Voucher No:</span>
                <strong><?= h($return_data['return_number']) ?></strong>
            </div>
            <div class="receipt-meta-row">
                <span>Original Invoice:</span>
                <strong><?= h($return_data['invoice_number']) ?></strong>
            </div>
            <div class="receipt-meta-row">
                <span>Date & Time:</span>
                <span><?= date('d/m/Y h:i A', strtotime($return_data['created_at'])) ?></span>
            </div>
            <div class="receipt-meta-row">
                <span>Customer:</span>
                <span><?= h($return_data['customer_name'] ?? 'Walk-in') ?></span>
            </div>
            <div class="receipt-meta-row">
                <span>Processed By:</span>
                <span><?= h($return_data['processed_by']) ?></span>
            </div>
        </div>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= h($item['product_name']) ?>
                        <?php if (!empty($item['variant_size'])): ?>
                            <br><small>(<?= h($item['variant_size']) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?= (int)$item['quantity'] ?></td>
                    <td class="text-right">₹<?= number_format($item['unit_price'], 2) ?></td>
                    <td class="text-right">₹<?= number_format($item['total_refund'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="receipt-total">
            <div class="receipt-meta-row">
                <span>REFUND METHOD:</span>
                <span><?= strtoupper(h($return_data['refund_method'])) ?></span>
            </div>
            <div class="receipt-meta-row" style="font-size: 14px; margin-top:4px;">
                <span>TOTAL REFUNDED:</span>
                <span>₹<?= number_format($return_data['refund_amount'], 2) ?></span>
            </div>
        </div>

        <?php if (!empty($return_data['reason'])): ?>
        <div style="font-size:10px; margin-bottom:10px; border-bottom:1px dashed #000; padding-bottom:6px;">
            <strong>Reason:</strong> <?= h($return_data['reason']) ?>
        </div>
        <?php endif; ?>

        <div class="receipt-footer">
            <p>Return Processed Successfully.</p>
            <p>Thank you for shopping with Orange Events!</p>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', () => {
    // Optionally trigger print dialog on page load
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
