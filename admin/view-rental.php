<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: rentals.php'); exit; }

// ── POST handlers ─────────────────────────────────────────────────────────────
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add payment
    if ($action === 'add_payment') {
        $amount  = (float)($_POST['amount'] ?? 0);
        $type    = $_POST['payment_type']   ?? 'partial';
        $method  = $_POST['payment_method'] ?? 'cash';
        $date    = $_POST['payment_date']   ?? date('Y-m-d');
        $ref     = trim($_POST['reference_number'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');

        if ($amount <= 0) { $error = 'Amount must be greater than 0.'; }
        else {
            $db->prepare("INSERT INTO rental_payments
                (order_id, amount, payment_type, payment_method, payment_date, reference_number, notes)
                VALUES (:oid, :amt, :type, :meth, :dt, :ref, :notes)")
            ->execute(['oid'=>$id,'amt'=>$amount,'type'=>$type,'meth'=>$method,'dt'=>$date,'ref'=>$ref,'notes'=>$notes]);

            // Recompute advance_paid & balance_due
            $total_paid = (float)$db->query("SELECT IFNULL(SUM(amount),0) FROM rental_payments WHERE order_id=$id")->fetchColumn();
            $total_amt  = (float)$db->query("SELECT total_amount FROM rental_orders WHERE id=$id")->fetchColumn();
            $db->prepare("UPDATE rental_orders SET advance_paid=:ap, balance_due=:bd WHERE id=:id")
               ->execute(['ap'=>$total_paid,'bd'=>max(0,$total_amt-$total_paid),'id'=>$id]);

            $message = 'Payment recorded!';
        }
    }

    // Delete payment
    if ($action === 'delete_payment') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("DELETE FROM rental_payments WHERE id=:id AND order_id=:oid")->execute(['id'=>$pid,'oid'=>$id]);
            $total_paid = (float)$db->query("SELECT IFNULL(SUM(amount),0) FROM rental_payments WHERE order_id=$id")->fetchColumn();
            $total_amt  = (float)$db->query("SELECT total_amount FROM rental_orders WHERE id=$id")->fetchColumn();
            $db->prepare("UPDATE rental_orders SET advance_paid=:ap, balance_due=:bd WHERE id=:id")
               ->execute(['ap'=>$total_paid,'bd'=>max(0,$total_amt-$total_paid),'id'=>$id]);
            $message = 'Payment deleted.';
        }
    }

    // Update status
    if ($action === 'update_status') {
        $new_status = $_POST['new_status'] ?? '';
        $allowed    = ['draft','active','returned','cancelled'];
        
        if (in_array($new_status, $allowed)) {
            // Get current status before updating
            $current_status = $db->query("SELECT status FROM rental_orders WHERE id=$id")->fetchColumn();
            
            if ($current_status !== $new_status) {
                $return_date = ($new_status === 'returned') ? date('Y-m-d') : null;
                $db->prepare("UPDATE rental_orders SET status=:s, actual_return_date=:rd WHERE id=:id")
                   ->execute(['s'=>$new_status,'rd'=>$return_date,'id'=>$id]);
                
                // Handle Stock adjustments
                $order_items = $db->query("SELECT rental_item_id, quantity FROM rental_order_items WHERE order_id=$id AND rental_item_id IS NOT NULL")->fetchAll();
                
                // If moving from draft to active, decrease stock
                if ($new_status === 'active' && $current_status === 'draft') {
                    $upd = $db->prepare("UPDATE rental_items SET quantity_in_stock = quantity_in_stock - :qty WHERE id = :id");
                    foreach($order_items as $oi) {
                        $upd->execute(['qty' => $oi['quantity'], 'id' => $oi['rental_item_id']]);
                    }
                } 
                // If moving from active/overdue to returned/cancelled, restore stock
                else if (in_array($current_status, ['active', 'overdue']) && in_array($new_status, ['returned', 'cancelled'])) {
                    $upd = $db->prepare("UPDATE rental_items SET quantity_in_stock = quantity_in_stock + :qty WHERE id = :id");
                    foreach($order_items as $oi) {
                        $upd->execute(['qty' => $oi['quantity'], 'id' => $oi['rental_item_id']]);
                    }
                }
                
                $message = 'Status updated to ' . ucfirst($new_status) . '.';
            }
        }
    }
}

// ── Auto-mark overdue ─────────────────────────────────────────────────────────
$db->prepare("UPDATE rental_orders SET status='overdue'
              WHERE id=:id AND status='active' AND rental_end_date < CURDATE()")
   ->execute(['id'=>$id]);

// ── Fetch order + items + payments ────────────────────────────────────────────
$order = $db->prepare("SELECT * FROM rental_orders WHERE id=:id");
$order->execute(['id'=>$id]);
$order = $order->fetch();
if (!$order) { header('Location: rentals.php'); exit; }

$items = $db->prepare("SELECT * FROM rental_order_items WHERE order_id=:id ORDER BY id ASC");
$items->execute(['id'=>$id]);
$items = $items->fetchAll();

$payments = $db->prepare("SELECT * FROM rental_payments WHERE order_id=:id ORDER BY payment_date ASC, id ASC");
$payments->execute(['id'=>$id]);
$payments = $payments->fetchAll();

$total_paid = array_sum(array_column($payments, 'amount'));

// ── Helpers ───────────────────────────────────────────────────────────────────
$status_meta = [
    'draft'     => ['badge'=>'badge-draft',     'label'=>'Draft',     'icon'=>'fa-pen'],
    'active'    => ['badge'=>'badge-confirmed',  'label'=>'Active',    'icon'=>'fa-circle-play'],
    'overdue'   => ['badge'=>'badge-cancelled',  'label'=>'Overdue',   'icon'=>'fa-clock'],
    'returned'  => ['badge'=>'badge-paid',       'label'=>'Returned',  'icon'=>'fa-circle-check'],
    'cancelled' => ['badge'=>'badge-cancelled',  'label'=>'Cancelled', 'icon'=>'fa-ban'],
];
$method_labels = [
    'cash'=>'Cash','upi'=>'UPI','bank_transfer'=>'Bank Transfer','cheque'=>'Cheque','other'=>'Other'
];
$type_labels = [
    'advance'=>'Advance','partial'=>'Partial','balance'=>'Balance','refund'=>'Refund'
];
$meta = $status_meta[$order['status']] ?? $status_meta['draft'];
$flash = urldecode($_GET['msg'] ?? '');
if ($flash) $message = $flash;
?>

<!-- Header -->
<div class="content-header">
    <div class="header-title">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <h1 style="margin:0;"><?= h($order['order_number']) ?></h1>
            <span class="badge <?= $meta['badge'] ?>" style="font-size:0.85rem;padding:0.35rem 0.8rem;">
                <i class="fa-solid <?= $meta['icon'] ?>" style="margin-right:0.3rem;"></i><?= $meta['label'] ?>
            </span>
        </div>
        <p style="margin-top:0.25rem;">
            Created <?= format_date($order['created_at']) ?>
            <?= $order['event_name'] ? ' &bull; ' . h($order['event_name']) : '' ?>
        </p>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <a href="rental-invoice.php?id=<?= $id ?>" class="btn btn-primary">
            <i class="fa-solid fa-file-invoice"></i> Print Invoice
        </a>
        <a href="rental-form.php?id=<?= $id ?>" class="btn btn-secondary">
            <i class="fa-solid fa-pen-to-square"></i> Edit
        </a>
        <a href="rentals.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> All Orders
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($message): ?>
<div style="background:rgba(46,213,115,0.15);color:var(--success);border:1px solid var(--success);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
    <i class="fa-solid fa-circle-check"></i> <?= h($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:rgba(255,71,87,0.15);color:var(--danger);border:1px solid var(--danger);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
    <i class="fa-solid fa-triangle-exclamation"></i> <?= h($error) ?>
</div>
<?php endif; ?>

<!-- Quick Status Changer -->
<div class="card" style="margin-bottom:1.5rem;padding:1rem 1.5rem;">
    <form method="POST" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <input type="hidden" name="action" value="update_status">
        <span style="font-weight:600;color:var(--text-secondary);font-size:0.9rem;">Change Status:</span>
        <?php foreach (['draft'=>'Draft','active'=>'Mark Active','returned'=>'Mark Returned','cancelled'=>'Cancel'] as $val=>$lbl): ?>
            <?php if ($val !== $order['status']): ?>
            <button type="submit" name="new_status" value="<?= $val ?>"
                class="btn <?= $val==='cancelled' ? 'btn-danger' : ($val==='returned' ? 'btn-success' : 'btn-secondary') ?>"
                style="padding:0.4rem 0.9rem;font-size:0.82rem;"
                <?= $val==='cancelled' ? 'onclick="return confirm(\'Cancel this order?\')"' : '' ?>>
                <i class="fa-solid <?= $status_meta[$val]['icon'] ?>"></i> <?= $lbl ?>
            </button>
            <?php endif; ?>
        <?php endforeach; ?>
    </form>
</div>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:1.5rem;align-items:start;">

    <!-- LEFT COLUMN -->
    <div style="display:flex;flex-direction:column;gap:1.5rem;">

        <!-- Client + Event Info -->
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-user" style="color:var(--accent-color);"></i> Client & Event Details
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem 1.5rem;">
                <div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Client Name</p>
                    <p style="font-weight:700;"><?= h($order['client_name']) ?></p>
                </div>
                <div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Phone</p>
                    <p style="font-weight:600;"><a href="tel:<?= h($order['client_phone']) ?>" style="color:var(--accent-color);"><?= h($order['client_phone']) ?></a></p>
                </div>
                <?php if ($order['client_email']): ?>
                <div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Email</p>
                    <p><?= h($order['client_email']) ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Event / Occasion</p>
                    <p style="font-weight:600;"><?= h($order['event_name'] ?: '—') ?></p>
                </div>
                <div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Rental Period</p>
                    <p style="font-weight:600;">
                        <?= format_date($order['rental_start_date']) ?> → <?= format_date($order['rental_end_date']) ?>
                        <span style="color:var(--text-muted);font-weight:400;">(<?= $order['num_days'] ?> day<?= $order['num_days']!=1?'s':'' ?>)</span>
                    </p>
                </div>
                <?php if ($order['actual_return_date']): ?>
                <div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Actual Return</p>
                    <p style="color:var(--success);font-weight:600;"><?= format_date($order['actual_return_date']) ?></p>
                </div>
                <?php endif; ?>
                <?php if ($order['client_address']): ?>
                <div style="grid-column:1/-1;">
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Address</p>
                    <p style="color:var(--text-secondary);"><?= h($order['client_address']) ?></p>
                </div>
                <?php endif; ?>
                <?php if ($order['notes']): ?>
                <div style="grid-column:1/-1;">
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.2rem;">Notes</p>
                    <p style="color:var(--text-secondary);font-size:0.9rem;"><?= h($order['notes']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items Rented -->
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-boxes-stacked" style="color:var(--accent-color);"></i> Items Rented
            </h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="text-align:center;">Rate/Day</th>
                            <th style="text-align:center;">Qty</th>
                            <th style="text-align:center;">Days</th>
                            <th style="text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td style="font-weight:600;"><?= h($it['item_name']) ?></td>
                            <td style="text-align:center;color:var(--accent-color);"><?= format_price($it['daily_rate']) ?></td>
                            <td style="text-align:center;"><?= $it['quantity'] ?></td>
                            <td style="text-align:center;color:var(--text-muted);"><?= $it['num_days'] ?></td>
                            <td style="text-align:right;font-weight:700;"><?= format_price($it['subtotal']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--border-color);">
                            <td colspan="4" style="text-align:right;font-weight:600;color:var(--text-secondary);">Subtotal</td>
                            <td style="text-align:right;font-weight:700;"><?= format_price($order['subtotal']) ?></td>
                        </tr>
                        <?php if ($order['discount'] > 0): ?>
                        <tr>
                            <td colspan="4" style="text-align:right;color:var(--success);">Discount</td>
                            <td style="text-align:right;color:var(--success);">- <?= format_price($order['discount']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="4" style="text-align:right;font-weight:800;font-size:1.05rem;">Total</td>
                            <td style="text-align:right;font-weight:800;font-size:1.1rem;color:var(--accent-color);"><?= format_price($order['total_amount']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Payment Summary + History -->
    <div style="display:flex;flex-direction:column;gap:1.5rem;">

        <!-- Payment Summary Card -->
        <div class="card" style="background:var(--bg-secondary);">
            <h3 style="font-size:1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-receipt" style="color:var(--accent-color);"></i> Payment Summary
            </h3>
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <div style="display:flex;justify-content:space-between;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);">
                    <span style="color:var(--text-secondary);">Order Total</span>
                    <span style="font-weight:700;font-size:1.05rem;"><?= format_price($order['total_amount']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Total Paid</span>
                    <span style="font-weight:700;color:var(--success);"><?= format_price($total_paid) ?></span>
                </div>
                <?php
                $balance = max(0, $order['total_amount'] - $total_paid);
                $pct     = $order['total_amount'] > 0 ? min(100, round($total_paid / $order['total_amount'] * 100)) : 0;
                ?>
                <div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:0.4rem;">
                        <span style="color:var(--text-secondary);">Balance Due</span>
                        <span style="font-weight:800;font-size:1.1rem;color:<?= $balance > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                            <?= format_price($balance) ?>
                        </span>
                    </div>
                    <!-- Progress bar -->
                    <div style="background:var(--border-color);border-radius:50px;height:8px;overflow:hidden;">
                        <div style="width:<?= $pct ?>%;background:var(--success);height:100%;border-radius:50px;transition:width 0.4s ease;"></div>
                    </div>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin-top:0.3rem;text-align:right;"><?= $pct ?>% paid</p>
                </div>
            </div>
            <button onclick="openModal('addPaymentModal')" class="btn btn-primary" style="width:100%;margin-top:1rem;">
                <i class="fa-solid fa-plus"></i> Record Payment
            </button>
        </div>

        <!-- Payment History -->
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-clock-rotate-left" style="color:var(--accent-color);"></i> Payment History
            </h3>
            <?php if (empty($payments)): ?>
                <p style="text-align:center;color:var(--text-muted);padding:1.5rem 0;">No payments recorded yet.</p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:0.6rem;">
                    <?php foreach ($payments as $p):
                        $type_color = ['advance'=>'var(--info)','partial'=>'var(--warning)','balance'=>'var(--success)','refund'=>'var(--danger)'];
                        $tc = $type_color[$p['payment_type']] ?? 'var(--text-secondary)';
                    ?>
                    <div style="background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--border-radius-sm);padding:0.75rem 1rem;display:flex;justify-content:space-between;align-items:start;gap:0.5rem;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem;">
                                <span style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:<?= $tc ?>;background:<?= str_replace(')', ',0.12)', str_replace('var(', 'var(', $tc)) ?>;padding:0.15rem 0.45rem;border-radius:50px;">
                                    <?= $type_labels[$p['payment_type']] ?? $p['payment_type'] ?>
                                </span>
                                <span style="font-size:0.78rem;color:var(--text-muted);"><?= format_date($p['payment_date']) ?></span>
                                <span style="font-size:0.78rem;color:var(--text-muted);">&bull; <?= $method_labels[$p['payment_method']] ?? $p['payment_method'] ?></span>
                            </div>
                            <?php if ($p['reference_number']): ?>
                                <div style="font-size:0.78rem;color:var(--text-muted);">Ref: <?= h($p['reference_number']) ?></div>
                            <?php endif; ?>
                            <?php if ($p['notes']): ?>
                                <div style="font-size:0.78rem;color:var(--text-secondary);"><?= h($p['notes']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                            <span style="font-weight:800;font-size:1rem;color:var(--success);">+ <?= format_price($p['amount']) ?></span>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this payment?');">
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0.2rem;" title="Delete">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div id="addPaymentModal" class="modal">
    <div class="modal-content" style="max-width:480px;">
        <button class="modal-close" onclick="closeModal('addPaymentModal')">&times;</button>
        <h3 style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-hand-holding-dollar" style="color:var(--accent-color);"></i> Record Payment
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_payment">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Amount (Rs) *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" class="form-control"
                           placeholder="<?= max(0, $order['balance_due']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Date *</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Payment Type</label>
                    <select name="payment_type" class="form-control">
                        <option value="advance">Advance</option>
                        <option value="partial" selected>Partial</option>
                        <option value="balance">Balance (Full)</option>
                        <option value="refund">Refund</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Reference / UPI ID / Cheque No.</label>
                <input type="text" name="reference_number" class="form-control" placeholder="Optional">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional note…"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="closeModal('addPaymentModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Payment</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
