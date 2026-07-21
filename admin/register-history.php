<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_read');

$db = get_db_connection();

// Get list of users for cashier filter dropdown
$users_stmt = $db->query("
    SELECT DISTINCT u.id, u.username 
      FROM users u 
      JOIN cash_register_sessions s ON u.id = s.user_id 
     ORDER BY u.username ASC
");
$cashiers = $users_stmt->fetchAll();

// Filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$cashier_id = $_GET['cashier_id'] ?? '';
$status = $_GET['status'] ?? '';

// Build Query
$query = "
    SELECT s.*, u.username,
           (SELECT COALESCE(SUM(amount), 0) FROM cash_register_payouts WHERE session_id = s.id) AS total_expenses
      FROM cash_register_sessions s
      JOIN users u ON s.user_id = u.id
     WHERE 1=1
";
$params = [];

if (!empty($start_date)) {
    $query .= " AND DATE(s.opened_at) >= :start_date";
    $params['start_date'] = $start_date;
}

if (!empty($end_date)) {
    $query .= " AND DATE(s.opened_at) <= :end_date";
    $params['end_date'] = $end_date;
}

if (!empty($cashier_id)) {
    $query .= " AND s.user_id = :cashier_id";
    $params['cashier_id'] = $cashier_id;
}

if ($status === 'open') {
    $query .= " AND s.status = 'open'";
} elseif ($status === 'closed') {
    $query .= " AND s.status = 'closed'";
}

$query .= " ORDER BY s.opened_at DESC LIMIT 100";

$stmt = $db->prepare($query);
$stmt->execute($params);
$sessions = $stmt->fetchAll();

// Fetch payouts for these sessions
$session_ids = array_column($sessions, 'id');
$payouts_by_session = [];
if (!empty($session_ids)) {
    $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
    $payouts_stmt = $db->prepare("
        SELECT * 
          FROM cash_register_payouts 
         WHERE session_id IN ($placeholders)
         ORDER BY created_at ASC
    ");
    $payouts_stmt->execute($session_ids);
    $all_payouts = $payouts_stmt->fetchAll();
    foreach ($all_payouts as $p) {
        $payouts_by_session[$p['session_id']][] = $p;
    }
}

// Helper to query tallies for a session
function getSessionTallies($db, $session) {
    $opened_at = $session['opened_at'];
    $closed_at = $session['closed_at']; // can be null if open
    
    // POS Billing Cash
    $stmt = $db->prepare("SELECT COALESCE(SUM(paid_cash), 0) FROM billing_orders WHERE created_at >= ? AND (? IS NULL OR created_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $pos_cash = (float)$stmt->fetchColumn();

    // POS Billing UPI
    $stmt = $db->prepare("SELECT COALESCE(SUM(paid_upi), 0) FROM billing_orders WHERE created_at >= ? AND (? IS NULL OR created_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $pos_upi = (float)$stmt->fetchColumn();

    // POS Billing Card
    $stmt = $db->prepare("SELECT COALESCE(SUM(paid_card), 0) FROM billing_orders WHERE created_at >= ? AND (? IS NULL OR created_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $pos_card = (float)$stmt->fetchColumn();

    // Event Invoices Advance Cash
    $stmt = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method = 'CASH' AND advance_paid_at >= ? AND (? IS NULL OR advance_paid_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $inv_adv_cash = (float)$stmt->fetchColumn();

    // Event Invoices Advance UPI
    $stmt = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method = 'UPI' AND advance_paid_at >= ? AND (? IS NULL OR advance_paid_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $inv_adv_upi = (float)$stmt->fetchColumn();

    // Event Invoices Advance Other
    $stmt = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method NOT IN ('CASH', 'UPI') AND advance_paid_at >= ? AND (? IS NULL OR advance_paid_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $inv_adv_other = (float)$stmt->fetchColumn();

    // Event Invoices Balance Cash
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method = 'CASH' AND balance_paid_at >= ? AND (? IS NULL OR balance_paid_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $inv_bal_cash = (float)$stmt->fetchColumn();

    // Event Invoices Balance UPI
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method = 'UPI' AND balance_paid_at >= ? AND (? IS NULL OR balance_paid_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $inv_bal_upi = (float)$stmt->fetchColumn();

    // Event Invoices Balance Other
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method NOT IN ('CASH', 'UPI') AND balance_paid_at >= ? AND (? IS NULL OR balance_paid_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $inv_bal_other = (float)$stmt->fetchColumn();

    // Rentals Cash
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method = 'cash' AND created_at >= ? AND (? IS NULL OR created_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $rent_cash = (float)$stmt->fetchColumn();

    // Rentals UPI
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method = 'upi' AND created_at >= ? AND (? IS NULL OR created_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $rent_upi = (float)$stmt->fetchColumn();

    // Rentals Other
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method NOT IN ('cash', 'upi') AND created_at >= ? AND (? IS NULL OR created_at <= ?)");
    $stmt->execute([$opened_at, $closed_at, $closed_at]);
    $rent_other = (float)$stmt->fetchColumn();

    $total_cash_collected = $pos_cash + $inv_adv_cash + $inv_bal_cash + $rent_cash;

    return [
        'pos_cash' => $pos_cash, 'pos_upi' => $pos_upi, 'pos_card' => $pos_card,
        'inv_adv_cash' => $inv_adv_cash, 'inv_adv_upi' => $inv_adv_upi, 'inv_adv_other' => $inv_adv_other,
        'inv_bal_cash' => $inv_bal_cash, 'inv_bal_upi' => $inv_bal_upi, 'inv_bal_other' => $inv_bal_other,
        'rent_cash' => $rent_cash, 'rent_upi' => $rent_upi, 'rent_other' => $rent_other,
        'total_cash_collected' => $total_cash_collected
    ];
}
?>

<!-- Compact Header -->
<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--accent-color);"></i>
            Cash Register History & Reports
        </h1>
        <p style="color: var(--text-secondary); margin: 0.15rem 0 0; font-size: 0.75rem;">Review past shift opening floats, drawer payouts, cash collections, and differences</p>
    </div>
</div>

<!-- Filters Panel -->
<div class="card" style="padding: 1rem; margin-bottom: 1rem;">
    <form method="GET" action="" style="margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) 120px; gap: 0.75rem; align-items: end;">
        
        <div>
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem; font-weight: 600;">Cashier / User</label>
            <select name="cashier_id" class="form-control" style="height: 34px; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                <option value="">-- All Cashiers --</option>
                <?php foreach ($cashiers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $cashier_id == $c['id'] ? 'selected' : '' ?>><?= h(ucfirst($c['username'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem; font-weight: 600;">Status</label>
            <select name="status" class="form-control" style="height: 34px; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                <option value="">-- All Statuses --</option>
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Closed</option>
            </select>
        </div>

        <div>
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem; font-weight: 600;">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= h($start_date) ?>" style="height: 34px; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
        </div>

        <div>
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem; font-weight: 600;">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= h($end_date) ?>" style="height: 34px; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
        </div>

        <div style="display: flex; gap: 0.4rem;">
            <button type="submit" class="btn btn-primary" style="height: 34px; flex-grow: 1; font-size: 0.8rem; padding: 0; display: flex; align-items: center; justify-content: center; gap: 0.3rem;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if (!empty($cashier_id) || !empty($status) || !empty($start_date) || !empty($end_date)): ?>
                <a href="register-history.php" class="btn btn-secondary" style="height: 34px; width: 34px; padding: 0; display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Clear Filters">
                    <i class="fa-solid fa-times"></i>
                </a>
            <?php endif; ?>
        </div>

    </form>
</div>

<!-- History Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" style="margin: 0; font-size: 0.8rem;">
            <thead>
                <tr>
                    <th style="padding: 0.5rem 0.75rem; width: 40px;"></th>
                    <th style="padding: 0.5rem 0.75rem; width: 60px;">ID</th>
                    <th style="padding: 0.5rem 0.75rem;">Opened By</th>
                    <th style="padding: 0.5rem 0.75rem;">Opened At</th>
                    <th style="padding: 0.5rem 0.75rem;">Closed At</th>
                    <th style="text-align: right; padding: 0.5rem 0.75rem;">Opening Float</th>
                    <th style="text-align: right; padding: 0.5rem 0.75rem;">Expenses</th>
                    <th style="text-align: right; padding: 0.5rem 0.75rem;">Expected Close</th>
                    <th style="text-align: right; padding: 0.5rem 0.75rem;">Actual Close</th>
                    <th style="text-align: right; padding: 0.5rem 0.75rem;">Difference</th>
                    <th style="text-align: center; padding: 0.5rem 0.75rem;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sessions)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                            <i class="fa-solid fa-clock-rotate-left" style="font-size: 2.5rem; display: block; margin-bottom: 1rem; opacity: 0.3;"></i>
                            No matching cash register sessions found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sessions as $row): 
                        $diff = 0;
                        if ($row['status'] === 'closed') {
                            $diff = (float)$row['actual_closing_balance'] - (float)$row['expected_closing_balance'];
                        }
                        $sess_exp = (float)$row['total_expenses'];
                    ?>
                        <!-- Main Row -->
                        <tr onclick="toggleSessionDetails(<?= $row['id'] ?>)" style="cursor: pointer; transition: background 0.15s;" class="hover-row">
                            <td style="padding: 0.5rem 0.75rem; text-align: center; color: var(--text-muted);">
                                <i class="fa-solid fa-chevron-right" id="chevron-<?= $row['id'] ?>" style="transition: transform 0.2s;"></i>
                            </td>
                            <td style="padding: 0.5rem 0.75rem; color: var(--text-muted);">#<?= $row['id'] ?></td>
                            <td style="font-weight: 600; color: var(--text-primary); padding: 0.5rem 0.75rem;">
                                <?= h(ucfirst($row['username'])) ?>
                            </td>
                            <td style="color: var(--text-secondary); padding: 0.5rem 0.75rem;">
                                <?= date('d M Y - h:i A', strtotime($row['opened_at'])) ?>
                            </td>
                            <td style="color: var(--text-secondary); padding: 0.5rem 0.75rem;">
                                <?= $row['closed_at'] ? date('d M Y - h:i A', strtotime($row['closed_at'])) : '<span style="color: var(--success); font-style: italic; font-weight:600;">Active</span>' ?>
                            </td>
                            <td style="text-align: right; padding: 0.5rem 0.75rem;">₹<?= number_format($row['opening_balance'], 2) ?></td>
                            <td style="text-align: right; color: var(--danger); padding: 0.5rem 0.75rem; font-weight: bold;">
                                ₹<?= number_format($sess_exp, 2) ?>
                            </td>
                            <td style="text-align: right; color: var(--text-secondary); padding: 0.5rem 0.75rem;">
                                <?= $row['status'] === 'closed' ? '₹' . number_format($row['expected_closing_balance'], 2) : '-' ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; padding: 0.5rem 0.75rem;">
                                <?= $row['status'] === 'closed' ? '₹' . number_format($row['actual_closing_balance'], 2) : '-' ?>
                            </td>
                            <td style="text-align: right; font-weight: bold; padding: 0.5rem 0.75rem;">
                                <?php if ($row['status'] === 'closed'): ?>
                                    <?php if ($diff == 0): ?>
                                        <span style="color: var(--success);">₹0.00</span>
                                    <?php elseif ($diff > 0): ?>
                                        <span style="color: var(--info);" title="Surplus">+₹<?= number_format($diff, 2) ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--danger);" title="Shortage">-₹<?= number_format(abs($diff), 2) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; padding: 0.5rem 0.75rem;">
                                <?php if ($row['status'] === 'open'): ?>
                                    <span class="badge" style="background-color: rgba(46, 213, 115, 0.1); color: var(--success); padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                                        Open
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background-color: rgba(255, 255, 255, 0.05); color: var(--text-secondary); padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; border: 1px solid var(--border-color);">
                                        Closed
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Expanded Detail Row -->
                        <?php 
                        $tallies = getSessionTallies($db, $row); 
                        $session_payouts = $payouts_by_session[$row['id']] ?? [];
                        ?>
                        <tr id="details-row-<?= $row['id'] ?>" style="display: none; background: rgba(0, 0, 0, 0.18);">
                            <td colspan="11" style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color);">
                                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 1.5rem;">
                                    
                                    <!-- Left Column: Shift Collections Tally -->
                                    <div>
                                        <h4 style="margin: 0 0 0.5rem; font-size: 0.85rem; font-weight: 700; color: var(--accent-color); display: flex; align-items: center; gap: 0.35rem;">
                                            <i class="fa-solid fa-table"></i> Shift Payment Tally Breakdown
                                        </h4>
                                        <table class="table" style="font-size: 0.75rem; margin: 0; background: rgba(255,255,255,0.01);">
                                            <thead>
                                                <tr style="background: rgba(0,0,0,0.15);">
                                                    <th style="padding: 0.25rem 0.4rem;">Category</th>
                                                    <th style="text-align: right; padding: 0.25rem 0.4rem;">Cash</th>
                                                    <th style="text-align: right; padding: 0.25rem 0.4rem;">UPI</th>
                                                    <th style="text-align: right; padding: 0.25rem 0.4rem;">Card / Other</th>
                                                    <th style="text-align: right; padding: 0.25rem 0.4rem;">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td style="padding: 0.25rem 0.4rem; font-weight:600;">POS Billing</td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['pos_cash'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['pos_upi'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['pos_card'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem; font-weight: bold;">₹<?= number_format($tallies['pos_cash'] + $tallies['pos_upi'] + $tallies['pos_card'], 2) ?></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding: 0.25rem 0.4rem; font-weight:600;">Invoice Advance</td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['inv_adv_cash'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['inv_adv_upi'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['inv_adv_other'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem; font-weight: bold;">₹<?= number_format($tallies['inv_adv_cash'] + $tallies['inv_adv_upi'] + $tallies['inv_adv_other'], 2) ?></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding: 0.25rem 0.4rem; font-weight:600;">Invoice Balance</td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['inv_bal_cash'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['inv_bal_upi'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['inv_bal_other'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem; font-weight: bold;">₹<?= number_format($tallies['inv_bal_cash'] + $tallies['inv_bal_upi'] + $tallies['inv_bal_other'], 2) ?></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding: 0.25rem 0.4rem; font-weight:600;">Rental Payments</td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['rent_cash'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['rent_upi'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem;">₹<?= number_format($tallies['rent_other'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.25rem 0.4rem; font-weight: bold;">₹<?= number_format($tallies['rent_cash'] + $tallies['rent_upi'] + $tallies['rent_other'], 2) ?></td>
                                                </tr>
                                                <tr style="background: rgba(0,0,0,0.15); border-top: 1px dashed var(--border-color);">
                                                    <td style="padding: 0.3rem 0.4rem; font-weight: 700;">Total Collected</td>
                                                    <td style="text-align: right; padding: 0.3rem 0.4rem; font-weight: 700; color: var(--success);">₹<?= number_format($tallies['total_cash_collected'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.3rem 0.4rem; font-weight: 700;">₹<?= number_format($tallies['pos_upi'] + $tallies['inv_adv_upi'] + $tallies['inv_bal_upi'] + $tallies['rent_upi'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.3rem 0.4rem; font-weight: 700;">₹<?= number_format($tallies['pos_card'] + $tallies['inv_adv_other'] + $tallies['inv_bal_other'] + $tallies['rent_other'], 2) ?></td>
                                                    <td style="text-align: right; padding: 0.3rem 0.4rem; font-weight: 800; color: var(--accent-color);">₹<?= number_format($tallies['total_cash_collected'] + ($tallies['pos_upi'] + $tallies['inv_adv_upi'] + $tallies['inv_bal_upi'] + $tallies['rent_upi']) + ($tallies['pos_card'] + $tallies['inv_adv_other'] + $tallies['inv_bal_other'] + $tallies['rent_other']), 2) ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        
                                        <?php if (!empty($row['notes'])): ?>
                                            <div style="margin-top: 0.75rem; padding: 0.5rem; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.75rem;">
                                                <strong style="color: var(--text-primary);">Shift Notes:</strong>
                                                <span style="color: var(--text-secondary);"><?= h($row['notes']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Right Column: Shift Expenses Breakdown -->
                                    <div>
                                        <h4 style="margin: 0 0 0.5rem; font-size: 0.85rem; font-weight: 700; color: var(--danger); display: flex; align-items: center; gap: 0.35rem;">
                                            <i class="fa-solid fa-receipt"></i> Drawer Payouts / Expenses (<?= count($session_payouts) ?>)
                                        </h4>
                                        <div style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.35rem; border: 1px solid var(--border-color); border-radius: 4px; padding: 0.5rem; background: rgba(0,0,0,0.1);">
                                            <?php if (empty($session_payouts)): ?>
                                                <div style="text-align: center; color: var(--text-muted); font-size: 0.75rem; padding: 2rem 0;">
                                                    No expenses recorded during this session.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($session_payouts as $p): ?>
                                                    <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.015); border: 1px solid var(--border-color); border-radius: 4px; padding: 0.35rem 0.5rem; font-size: 0.75rem;">
                                                        <div>
                                                            <strong style="color: var(--text-primary);"><?= h($p['reason']) ?></strong>
                                                            <?php if (!empty($p['recipient_name'])): ?>
                                                                <span style="color: var(--text-muted); font-size: 0.7rem;"> (to: <?= h($p['recipient_name']) ?>)</span>
                                                            <?php endif; ?>
                                                            <div style="font-size: 0.65rem; color: var(--text-muted); margin-top: 0.1rem;"><?= date('h:i A', strtotime($p['created_at'])) ?></div>
                                                        </div>
                                                        <span style="color: var(--danger); font-weight: 700;">-₹<?= number_format($p['amount'], 2) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.hover-row:hover {
    background: rgba(255, 255, 255, 0.02) !important;
}
</style>

<script>
function toggleSessionDetails(id) {
    const detailsRow = document.getElementById('details-row-' + id);
    const chevron = document.getElementById('chevron-' + id);
    
    if (detailsRow) {
        if (detailsRow.style.display === 'none') {
            detailsRow.style.display = 'table-row';
            if (chevron) chevron.style.transform = 'rotate(90deg)';
        } else {
            detailsRow.style.display = 'none';
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
    }
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
