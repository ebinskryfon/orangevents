<?php
/**
 * Customer Profile & Purchase History Dashboard
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_read');

$db = get_db_connection();
$cust_id = (int)($_GET['id'] ?? 0);

if ($cust_id <= 0) {
    header("Location: customers.php");
    exit;
}

// Fetch Customer Profile
$stmt = $db->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $cust_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Customer profile not found.</div></div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch POS Billing Orders for this customer phone
$stmt_pos = $db->prepare("
    SELECT * FROM billing_orders
     WHERE customer_phone = :phone OR customer_name = :name
  ORDER BY id DESC
");
$stmt_pos->execute(['phone' => $customer['phone'], 'name' => $customer['name']]);
$pos_orders = $stmt_pos->fetchAll(PDO::FETCH_ASSOC);

// Fetch Event Catering/Decor Invoices for this customer
$stmt_events = $db->prepare("
    SELECT i.*, e.event_date, e.venue, e.title
      FROM invoices i
 LEFT JOIN events e ON e.id = i.event_id
     WHERE e.client_phone = :phone OR e.client_name = :name
  ORDER BY i.id DESC
");
$stmt_events->execute(['phone' => $customer['phone'], 'name' => $customer['name']]);
$event_invoices = $stmt_events->fetchAll(PDO::FETCH_ASSOC);

// Fetch Rental Orders for this customer
$stmt_rentals = $db->prepare("
    SELECT * FROM rental_orders
     WHERE customer_phone = :phone OR customer_name = :name
  ORDER BY id DESC
");
$stmt_rentals->execute(['phone' => $customer['phone'], 'name' => $customer['name']]);
$rental_orders = $stmt_rentals->fetchAll(PDO::FETCH_ASSOC);

$total_pos_count = count($pos_orders);
$total_pos_spent = 0;
foreach ($pos_orders as $po) {
    $total_pos_spent += (float)$po['final_amount'];
}
$avg_order_value = $total_pos_count > 0 ? ($total_pos_spent / $total_pos_count) : 0;
?>

<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-user-circle" style="color:var(--accent-color);"></i>
            Customer Profile: <?= h($customer['name']) ?>
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            Phone: <?= h($customer['phone']) ?> <?= !empty($customer['city']) ? "• " . h($customer['city']) : "" ?>
        </p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <a href="customers.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.3rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to Directory
        </a>
    </div>
</div>

<!-- Customer Overview & Key Metrics -->
<div style="display:grid; grid-template-columns: 1fr 2fr; gap:1rem; margin-bottom:1.5rem;">
    <!-- Profile Card -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; box-shadow:var(--box-shadow);">
        <h3 style="font-size:0.9rem; border-bottom:1px solid var(--border-color); padding-bottom:0.4rem; margin-bottom:0.75rem; color:var(--text-primary); font-weight:700;">
            <i class="fa-solid fa-id-card" style="color:var(--accent-color);"></i> Contact & Details
        </h3>
        <div style="font-size:0.8rem; display:flex; flex-direction:column; gap:0.45rem;">
            <div>
                <span style="color:var(--text-secondary); display:block; font-size:0.7rem;">Full Name:</span>
                <strong><?= h($customer['name']) ?></strong>
            </div>
            <div>
                <span style="color:var(--text-secondary); display:block; font-size:0.7rem;">Phone Number:</span>
                <strong style="color:var(--accent-color);"><?= h($customer['phone']) ?></strong>
            </div>
            <?php if (!empty($customer['email'])): ?>
            <div>
                <span style="color:var(--text-secondary); display:block; font-size:0.7rem;">Email:</span>
                <span><?= h($customer['email']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($customer['address'])): ?>
            <div>
                <span style="color:var(--text-secondary); display:block; font-size:0.7rem;">Address:</span>
                <span><?= nl2br(h($customer['address'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($customer['gstin'])): ?>
            <div>
                <span style="color:var(--text-secondary); display:block; font-size:0.7rem;">GSTIN:</span>
                <strong><?= h($customer['gstin']) ?></strong>
            </div>
            <?php endif; ?>
            <?php if (!empty($customer['notes'])): ?>
            <div style="border-top:1px dashed var(--border-color); padding-top:0.4rem; margin-top:0.2rem;">
                <span style="color:var(--text-secondary); display:block; font-size:0.7rem;">Notes:</span>
                <em style="color:var(--text-muted); font-size:0.75rem;"><?= h($customer['notes']) ?></em>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Spending & Activity Metrics -->
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem;">
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; display:flex; flex-direction:column; justify-content:center;">
            <div style="font-size:0.7rem; color:var(--text-secondary); margin-bottom:0.2rem;">Total Lifetime Revenue</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--success);">₹<?= number_format($total_pos_spent, 2) ?></div>
        </div>

        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; display:flex; flex-direction:column; justify-content:center;">
            <div style="font-size:0.7rem; color:var(--text-secondary); margin-bottom:0.2rem;">Total POS Orders</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--accent-color);"><?= number_format($total_pos_count) ?> Orders</div>
        </div>

        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; display:flex; flex-direction:column; justify-content:center;">
            <div style="font-size:0.7rem; color:var(--text-secondary); margin-bottom:0.2rem;">Avg. Order Value (AOV)</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--text-primary);">₹<?= number_format($avg_order_value, 2) ?></div>
        </div>

        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; display:flex; flex-direction:column; justify-content:center;">
            <div style="font-size:0.7rem; color:var(--text-secondary); margin-bottom:0.2rem;">Events & Rentals</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--text-primary);"><?= count($event_invoices) + count($rental_orders) ?> Bookings</div>
        </div>
    </div>
</div>

<!-- History Tabs -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); overflow:hidden; box-shadow:var(--box-shadow);">
    <div style="background:var(--bg-control); border-bottom:1px solid var(--border-color); padding:0.5rem 1rem; font-size:0.85rem; font-weight:700; display:flex; align-items:center; gap:0.5rem; color:var(--text-primary);">
        <i class="fa-solid fa-clock-rotate-left" style="color:var(--accent-color);"></i>
        POS Billing Order History (<?= count($pos_orders) ?>)
    </div>

    <div class="table-responsive">
        <table class="table" style="width:100%; margin:0; font-size:0.8rem;">
            <thead style="background:var(--bg-card); border-bottom:1px solid var(--border-color);">
                <tr>
                    <th style="padding:0.6rem 0.75rem;">Invoice #</th>
                    <th style="padding:0.6rem 0.75rem;">Date & Time</th>
                    <th style="padding:0.6rem 0.75rem;">Payment Method</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Final Total</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pos_orders)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:2rem; color:var(--text-muted);">
                            No POS billing history found for this customer.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pos_orders as $po): ?>
                        <tr style="border-bottom:1px solid var(--border-color);">
                            <td style="padding:0.6rem 0.75rem; font-weight:700; color:var(--accent-color);">
                                <a href="billing-invoice.php?id=<?= $po['id'] ?>" style="color:var(--accent-color); text-decoration:none;">
                                    <?= h($po['invoice_number']) ?>
                                </a>
                            </td>
                            <td style="padding:0.6rem 0.75rem; color:var(--text-secondary);">
                                <?= date('d M Y, h:i A', strtotime($po['created_at'])) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem;">
                                <span class="badge" style="background:rgba(255,107,53,0.1); color:var(--accent-color); padding:2px 8px; border-radius:10px; font-weight:700;">
                                    <?= h($po['payment_method']) ?>
                                </span>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right; font-weight:700; color:var(--success);">
                                ₹<?= number_format($po['final_amount'], 2) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right;">
                                <a href="billing-invoice.php?id=<?= $po['id'] ?>" class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.7rem;">
                                    <i class="fa-solid fa-receipt"></i> Invoice
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
