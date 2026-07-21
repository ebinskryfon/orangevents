<?php
/**
 * POS Return & Exchange Management & Log Page
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_read');

$db = get_db_connection();

// Search & Filter Query
$search = trim($_GET['search'] ?? '');
$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');

$query_sql = "
    SELECT r.*, o.customer_name, o.customer_phone
      FROM billing_returns r
      JOIN billing_orders o ON o.id = r.order_id
     WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query_sql .= " AND (r.return_number LIKE :search OR r.invoice_number LIKE :search OR o.customer_name LIKE :search OR o.customer_phone LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if (!empty($from_date)) {
    $query_sql .= " AND DATE(r.created_at) >= :from_date";
    $params['from_date'] = $from_date;
}
if (!empty($to_date)) {
    $query_sql .= " AND DATE(r.created_at) <= :to_date";
    $params['to_date'] = $to_date;
}

$query_sql .= " ORDER BY r.id DESC LIMIT 100";

$stmt = $db->prepare($query_sql);
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals Summary
$total_refunds_sum = 0;
foreach ($returns as $r) {
    $total_refunds_sum += (float)$r['refund_amount'];
}
?>

<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-rotate-left" style="color:var(--accent-color);"></i>
            POS Returns & Refunds Log
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            View and manage item returns, cash refunds, store credits, and restocked inventory.
        </p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <button onclick="openPosReturnModal()" class="btn btn-primary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.3rem;">
            <i class="fa-solid fa-plus-circle"></i> Process New Return
        </button>
        <a href="billing.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.3rem;">
            <i class="fa-solid fa-cart-shopping"></i> Go to POS
        </a>
    </div>
</div>

<!-- Search & Filters -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; margin-bottom:1rem; box-shadow:var(--box-shadow);">
    <form method="GET" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; margin:0;">
        <div style="flex:1; min-width:200px;">
            <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem; display:block;">Search (Return # / Invoice # / Client)</label>
            <input type="text" name="search" class="form-control" placeholder="RET-OE-... or Invoice No." value="<?= h($search) ?>" style="height:32px; font-size:0.8rem;">
        </div>
        <div style="width:140px;">
            <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem; display:block;">From Date</label>
            <input type="date" name="from_date" class="form-control" value="<?= h($from_date) ?>" style="height:32px; font-size:0.8rem;">
        </div>
        <div style="width:140px;">
            <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem; display:block;">To Date</label>
            <input type="date" name="to_date" class="form-control" value="<?= h($to_date) ?>" style="height:32px; font-size:0.8rem;">
        </div>
        <div style="display:flex; gap:0.3rem;">
            <button type="submit" class="btn btn-primary" style="height:32px; font-size:0.75rem; padding:0 0.8rem;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="pos-returns.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; padding:0 0.6rem;" title="Reset Filters">
                <i class="fa-solid fa-rotate-right"></i> Reset
            </a>
        </div>
    </form>
</div>

<!-- Summary Card -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:0.75rem; margin-bottom:1rem;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(255, 107, 53, 0.1); color:var(--accent-color); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-rotate-left"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Total Returns</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary);"><?= count($returns) ?> Records</div>
        </div>
    </div>
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(255, 71, 87, 0.1); color:var(--danger); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-indian-rupee-sign"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Total Refunded</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--danger);">₹<?= number_format($total_refunds_sum, 2) ?></div>
        </div>
    </div>
</div>

<!-- Returns Table -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); overflow:hidden; box-shadow:var(--box-shadow);">
    <div class="table-responsive">
        <table class="table" style="width:100%; margin:0; font-size:0.8rem;">
            <thead style="background:var(--bg-control); border-bottom:1px solid var(--border-color);">
                <tr>
                    <th style="padding:0.6rem 0.75rem;">Return #</th>
                    <th style="padding:0.6rem 0.75rem;">Invoice #</th>
                    <th style="padding:0.6rem 0.75rem;">Customer</th>
                    <th style="padding:0.6rem 0.75rem;">Refund Method</th>
                    <th style="padding:0.6rem 0.75rem;">Reason</th>
                    <th style="padding:0.6rem 0.75rem;">Refund Amount</th>
                    <th style="padding:0.6rem 0.75rem;">Date & Time</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:2rem; color:var(--text-muted);">
                            <i class="fa-solid fa-inbox" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>
                            No return transactions recorded.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($returns as $row): ?>
                        <tr style="border-bottom:1px solid var(--border-color);">
                            <td style="padding:0.6rem 0.75rem; font-weight:700; color:var(--accent-color);">
                                <?= h($row['return_number']) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem;">
                                <a href="billing-invoice.php?inv=<?= urlencode($row['invoice_number']) ?>" style="color:var(--text-primary); font-weight:600;">
                                    <?= h($row['invoice_number']) ?>
                                </a>
                            </td>
                            <td style="padding:0.6rem 0.75rem;">
                                <?= h($row['customer_name'] ?? 'Walk-in') ?>
                                <?php if (!empty($row['customer_phone'])): ?>
                                    <br><small style="color:var(--text-muted);"><?= h($row['customer_phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem;">
                                <span class="badge" style="background:rgba(255, 107, 53, 0.1); color:var(--accent-color); padding:2px 8px; border-radius:10px; font-size:0.7rem; font-weight:700;">
                                    <?= h($row['refund_method']) ?>
                                </span>
                            </td>
                            <td style="padding:0.6rem 0.75rem; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= h($row['reason'] ?? 'N/A') ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; font-weight:700; color:var(--danger);">
                                ₹<?= number_format($row['refund_amount'], 2) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; color:var(--text-secondary); font-size:0.75rem;">
                                <?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right;">
                                <a href="return-receipt.php?id=<?= $row['id'] ?>" class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.7rem;" title="View & Print Voucher">
                                    <i class="fa-solid fa-receipt"></i> Voucher
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Render Return Modal Component -->
<?php include_once __DIR__ . '/../includes/return-modal.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
