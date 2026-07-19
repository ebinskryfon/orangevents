<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_read');

$db = get_db_connection();
$user_id = $_SESSION['admin_id'];
$username = $_SESSION['admin_username'];

$message = '';
$error = '';

// =========================================================
// POST HANDLERS (OPEN / CLOSE REGISTER / ADD EXPENSE)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'open_register') {
            $opening_balance = (float)($_POST['opening_balance'] ?? 0);
            if ($opening_balance < 0) {
                $error = "Opening balance cannot be negative.";
            } else {
                try {
                    // Check if there is already an open session
                    $check = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
                    $check->execute(['user_id' => $user_id]);
                    if ($check->fetch()) {
                        $error = "You already have an open cash register session.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO cash_register_sessions (user_id, opened_at, opening_balance, expected_closing_balance, status)
                            VALUES (:user_id, NOW(), :opening_balance, :expected_closing, 'open')
                        ");
                        $stmt->execute([
                            'user_id' => $user_id,
                            'opening_balance' => $opening_balance,
                            'expected_closing' => $opening_balance
                        ]);
                        $message = "Cash register opened successfully with ₹" . number_format($opening_balance, 2);
                    }
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'close_register') {
            $actual_closing_balance = (float)($_POST['actual_closing_balance'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                // Get current open session
                $stmt_sess = $db->prepare("SELECT * FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
                $stmt_sess->execute(['user_id' => $user_id]);
                $session = $stmt_sess->fetch();
                
                if (!$session) {
                    $error = "No active open register session found.";
                } else {
                    $opened_at = $session['opened_at'];
                    $opening_bal = (float)$session['opening_balance'];
                    
                    // Recompute expected cash balance
                    // 1. POS Cash sales
                    $stmt_pos = $db->prepare("SELECT COALESCE(SUM(final_amount), 0) FROM billing_orders WHERE payment_method = 'Cash' AND created_at >= :opened_at");
                    $stmt_pos->execute(['opened_at' => $opened_at]);
                    $pos_cash = (float)$stmt_pos->fetchColumn();
                    
                    // 2. Invoice Advance Cash
                    $stmt_inv_adv = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method = 'CASH' AND advance_paid_at >= :opened_at");
                    $stmt_inv_adv->execute(['opened_at' => $opened_at]);
                    $inv_adv_cash = (float)$stmt_inv_adv->fetchColumn();
                    
                    // 3. Invoice Balance Cash
                    $stmt_inv_bal = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method = 'CASH' AND balance_paid_at >= :opened_at");
                    $stmt_inv_bal->execute(['opened_at' => $opened_at]);
                    $inv_bal_cash = (float)$stmt_inv_bal->fetchColumn();
                    
                    // 4. Rental Cash payments
                    $stmt_rent = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method = 'cash' AND created_at >= :opened_at");
                    $stmt_rent->execute(['opened_at' => $opened_at]);
                    $rent_cash = (float)$stmt_rent->fetchColumn();
                    
                    $total_cash_collected = $pos_cash + $inv_adv_cash + $inv_bal_cash + $rent_cash;
                    
                    // Fetch total payouts / expenses from drawer for this session
                    $stmt_payouts = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_register_payouts WHERE session_id = :session_id");
                    $stmt_payouts->execute(['session_id' => $session['id']]);
                    $total_payouts = (float)$stmt_payouts->fetchColumn();

                    $expected_closing = $opening_bal + $total_cash_collected - $total_payouts;
                    
                    // Close the session
                    $stmt_close = $db->prepare("
                        UPDATE cash_register_sessions
                           SET closed_at = NOW(),
                               expected_closing_balance = :expected,
                               actual_closing_balance = :actual,
                               status = 'closed',
                               notes = :notes
                         WHERE id = :id
                    ");
                    $stmt_close->execute([
                        'expected' => $expected_closing,
                        'actual' => $actual_closing_balance,
                        'notes' => !empty($notes) ? $notes : null,
                        'id' => $session['id']
                    ]);
                    
                    $diff = $actual_closing_balance - $expected_closing;
                    $diff_str = $diff >= 0 ? "Surplus of ₹" . number_format($diff, 2) : "Shortage of ₹" . number_format(abs($diff), 2);
                    $message = "Register session closed successfully. " . ($diff == 0 ? "Drawer is balanced." : "Reconciliation difference: " . $diff_str);
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'add_payout') {
            $payout_amount = (float)($_POST['payout_amount'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $recipient_name = trim($_POST['recipient_name'] ?? '');
            
            if ($payout_amount <= 0) {
                $error = "Expense amount must be greater than zero.";
            } elseif (empty($reason)) {
                $error = "Please specify the reason for the expense.";
            } else {
                try {
                    // Get current open session
                    $stmt_sess = $db->prepare("SELECT id FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
                    $stmt_sess->execute(['user_id' => $user_id]);
                    $session = $stmt_sess->fetch();
                    
                    if (!$session) {
                        $error = "No active open register session found to record expense.";
                    } else {
                        $stmt_ins = $db->prepare("
                            INSERT INTO cash_register_payouts (session_id, amount, reason, recipient_name)
                            VALUES (:session_id, :amount, :reason, :recipient_name)
                        ");
                        $stmt_ins->execute([
                            'session_id' => $session['id'],
                            'amount' => $payout_amount,
                            'reason' => $reason,
                            'recipient_name' => !empty($recipient_name) ? $recipient_name : null
                        ]);
                        $message = "Drawer expense of ₹" . number_format($payout_amount, 2) . " recorded successfully.";
                    }
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// =========================================================
// FETCH SESSION STATUS & DETAILS
// =========================================================
$stmt_active = $db->prepare("SELECT * FROM cash_register_sessions WHERE status = 'open' AND user_id = :user_id LIMIT 1");
$stmt_active->execute(['user_id' => $user_id]);
$active_session = $stmt_active->fetch();

$pos_cash = 0; $pos_upi = 0; $pos_card = 0;
$inv_adv_cash = 0; $inv_adv_upi = 0; $inv_adv_other = 0;
$inv_bal_cash = 0; $inv_bal_upi = 0; $inv_bal_other = 0;
$rent_cash = 0; $rent_upi = 0; $rent_other = 0;
$total_cash_collected = 0;
$active_payouts = 0;
$expected_cash_in_drawer = 0;
$active_payouts_list = [];

if ($active_session) {
    $opened_at = $active_session['opened_at'];
    $opening_bal = (float)$active_session['opening_balance'];
    
    // Fetch live tallies
    // POS Billing
    $stmt = $db->prepare("SELECT COALESCE(SUM(final_amount), 0) FROM billing_orders WHERE payment_method = 'Cash' AND created_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $pos_cash = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(final_amount), 0) FROM billing_orders WHERE payment_method = 'UPI' AND created_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $pos_upi = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(final_amount), 0) FROM billing_orders WHERE payment_method = 'Card' AND created_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $pos_card = (float)$stmt->fetchColumn();

    // Event Invoices Advance
    $stmt = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method = 'CASH' AND advance_paid_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $inv_adv_cash = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method = 'UPI' AND advance_paid_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $inv_adv_upi = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(advance_received), 0) FROM invoices WHERE advance_payment_method NOT IN ('CASH', 'UPI') AND advance_paid_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $inv_adv_other = (float)$stmt->fetchColumn();

    // Event Invoices Balance
    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method = 'CASH' AND balance_paid_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $inv_bal_cash = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method = 'UPI' AND balance_paid_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $inv_bal_upi = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(balance_received), 0) FROM invoices WHERE balance_payment_method NOT IN ('CASH', 'UPI') AND balance_paid_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $inv_bal_other = (float)$stmt->fetchColumn();

    // Rentals
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method = 'cash' AND created_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $rent_cash = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method = 'upi' AND created_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $rent_upi = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE payment_method NOT IN ('cash', 'upi') AND created_at >= :opened_at");
    $stmt->execute(['opened_at' => $opened_at]);
    $rent_other = (float)$stmt->fetchColumn();

    $total_cash_collected = $pos_cash + $inv_adv_cash + $inv_bal_cash + $rent_cash;
    
    // Fetch active session payouts
    $stmt_p = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_register_payouts WHERE session_id = :session_id");
    $stmt_p->execute(['session_id' => $active_session['id']]);
    $active_payouts = (float)$stmt_p->fetchColumn();
    
    $expected_cash_in_drawer = $opening_bal + $total_cash_collected - $active_payouts;

    // Fetch active session payouts list
    $stmt_p_list = $db->prepare("SELECT * FROM cash_register_payouts WHERE session_id = :session_id ORDER BY created_at DESC");
    $stmt_p_list->execute(['session_id' => $active_session['id']]);
    $active_payouts_list = $stmt_p_list->fetchAll();
}

// Fetch session history for user (including subquery to calculate total payouts/expenses)
$history_stmt = $db->prepare("
    SELECT s.*, u.username,
           (SELECT COALESCE(SUM(amount), 0) FROM cash_register_payouts WHERE session_id = s.id) AS total_expenses
      FROM cash_register_sessions s
      JOIN users u ON s.user_id = u.id
     WHERE s.user_id = :user_id
     ORDER BY s.opened_at DESC
     LIMIT 20
");
$history_stmt->execute(['user_id' => $user_id]);
$sessions_history = $history_stmt->fetchAll();
?>

<!-- Compact Header -->
<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-cash-register" style="color: var(--accent-color);"></i>
            Daily Cash Register
        </h1>
        <p style="color: var(--text-secondary); margin: 0.15rem 0 0; font-size: 0.75rem;">Monitor opening/closing balances, drawer expenses, and reconcile shifts</p>
    </div>
</div>

<?php if ($message): ?>
    <div style="background-color: rgba(46, 213, 115, 0.1); border: 1px solid var(--success); color: var(--success); padding: 0.6rem 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1rem;"></i>
        <span><?= h($message) ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background-color: rgba(255, 71, 87, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 0.6rem 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1rem;"></i>
        <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($active_session): ?>
    <!-- Compact Active Session Layout -->
    
    <!-- Cashier & Time Info Banner -->
    <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 0.5rem 0.75rem; margin-bottom: 1rem; font-size: 0.85rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <span><i class="fa-solid fa-user-tie" style="color: var(--accent-color); margin-right: 3px;"></i> Cashier: <strong><?= h(ucfirst($username)) ?></strong></span>
            <span style="color: var(--border-color);">|</span>
            <span><i class="fa-solid fa-clock" style="color: var(--text-muted); margin-right: 3px;"></i> Opened: <strong><?= date('d-M h:i A', strtotime($active_session['opened_at'])) ?></strong></span>
        </div>
        <span class="badge" style="background-color: rgba(46, 213, 115, 0.12); color: var(--success); font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.75rem; display: flex; align-items: center; gap: 0.35rem;">
            <span style="display:inline-block; width:6px; height:6px; background:var(--success); border-radius:50%; animation: pulse 1.5s infinite;"></span>
            Active Shift
        </span>
    </div>

    <!-- Small Stats Metrics row -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-bottom: 1rem;">
        <div class="card" style="padding: 0.6rem 0.85rem; display: flex; flex-direction: column; gap: 0.15rem;">
            <span style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Opening Float</span>
            <strong style="font-size: 1.2rem; color: var(--text-primary);">₹<?= number_format($opening_bal, 2) ?></strong>
        </div>
        <div class="card" style="padding: 0.6rem 0.85rem; display: flex; flex-direction: column; gap: 0.15rem;">
            <span style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Total Cash Collections</span>
            <strong style="font-size: 1.2rem; color: var(--success);">₹<?= number_format($total_cash_collected, 2) ?></strong>
        </div>
        <div class="card" style="padding: 0.6rem 0.85rem; display: flex; flex-direction: column; gap: 0.15rem;">
            <span style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Drawer Expenses</span>
            <strong style="font-size: 1.2rem; color: var(--danger);">₹<?= number_format($active_payouts, 2) ?></strong>
        </div>
        <div class="card" style="padding: 0.6rem 0.85rem; display: flex; flex-direction: column; gap: 0.15rem; border: 1px solid rgba(255, 107, 53, 0.25); background: rgba(255, 107, 53, 0.02);">
            <span style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Expected Drawer Cash</span>
            <strong style="font-size: 1.2rem; color: var(--accent-color);">₹<?= number_format($expected_cash_in_drawer, 2) ?></strong>
        </div>
    </div>

    <!-- Main Content layout grid -->
    <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; align-items: start; margin-bottom: 2rem;">
        
        <!-- LEFT: Shift Table & Expenses List -->
        <div>
            <!-- Shift Tally Table -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                    <i class="fa-solid fa-chart-pie" style="color: var(--success); font-size: 0.85rem;"></i>
                    Shift Sales & Collection Breakdown
                </h3>
                <div class="table-responsive" style="margin: 0;">
                    <table class="table" style="font-size: 0.8rem; margin: 0;">
                        <thead>
                            <tr>
                                <th style="padding: 0.35rem 0.5rem;">Source</th>
                                <th style="text-align: right; padding: 0.35rem 0.5rem;">Cash</th>
                                <th style="text-align: right; padding: 0.35rem 0.5rem;">UPI</th>
                                <th style="text-align: right; padding: 0.35rem 0.5rem;">Card/Other</th>
                                <th style="text-align: right; font-weight: bold; padding: 0.35rem 0.5rem;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding: 0.35rem 0.5rem;">POS Billing Sales</td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($pos_cash, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($pos_upi, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($pos_card, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: var(--text-primary); padding: 0.35rem 0.5rem;">₹<?= number_format($pos_cash + $pos_upi + $pos_card, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 0.35rem 0.5rem;">Event Invoice (Advance)</td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($inv_adv_cash, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($inv_adv_upi, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($inv_adv_other, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: var(--text-primary); padding: 0.35rem 0.5rem;">₹<?= number_format($inv_adv_cash + $inv_adv_upi + $inv_adv_other, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 0.35rem 0.5rem;">Event Invoice (Balance)</td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($inv_bal_cash, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($inv_bal_upi, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($inv_bal_other, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: var(--text-primary); padding: 0.35rem 0.5rem;">₹<?= number_format($inv_bal_cash + $inv_bal_upi + $inv_bal_other, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 0.35rem 0.5rem;">Rental Payments</td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($rent_cash, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($rent_upi, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem;">₹<?= number_format($rent_other, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: var(--text-primary); padding: 0.35rem 0.5rem;">₹<?= number_format($rent_cash + $rent_upi + $rent_other, 2) ?></td>
                            </tr>
                            <tr style="border-top: 1px dashed var(--border-color); background: rgba(0,0,0,0.05);">
                                <td style="padding: 0.35rem 0.5rem; font-weight: 700; color: var(--text-secondary);">Total Collected</td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem; font-weight: 700;">₹<?= number_format($total_cash_collected, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem; font-weight: 700;">₹<?= number_format($pos_upi + $inv_adv_upi + $inv_bal_upi + $rent_upi, 2) ?></td>
                                <td style="text-align: right; padding: 0.35rem 0.5rem; font-weight: 700;">₹<?= number_format($pos_card + $inv_adv_other + $inv_bal_other + $rent_other, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; padding: 0.35rem 0.5rem; color: var(--accent-color);">₹<?= number_format($total_cash_collected + ($pos_upi + $inv_adv_upi + $inv_bal_upi + $rent_upi) + ($pos_card + $inv_adv_other + $inv_bal_other + $rent_other), 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Shift Expenses List -->
            <?php if (!empty($active_payouts_list)): ?>
                <div class="card" style="padding: 1rem;">
                    <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-list-ol" style="color: var(--danger); font-size: 0.85rem;"></i>
                        Shift Expenses Log
                    </h3>
                    <div style="max-height: 125px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.35rem; padding-right: 0.25rem;">
                        <?php foreach ($active_payouts_list as $payout): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.015); border: 1px solid var(--border-color); border-radius: 4px; padding: 0.35rem 0.5rem; font-size: 0.8rem;">
                                <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 280px;">
                                    <strong style="color: var(--text-primary);"><?= h($payout['reason']) ?></strong>
                                    <?php if (!empty($payout['recipient_name'])): ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem; margin-left: 0.3rem;">(to: <?= h($payout['recipient_name']) ?>)</span>
                                    <?php endif; ?>
                                    <span style="color: var(--text-muted); font-size: 0.7rem; margin-left: 0.4rem;"><?= date('h:i A', strtotime($payout['created_at'])) ?></span>
                                </div>
                                <span style="color: var(--danger); font-weight: 700;">-₹<?= number_format($payout['amount'], 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Compact Forms -->
        <div>
            <!-- Form 1: Quick Drawer Expense Payout -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.6rem; display: flex; align-items: center; gap: 0.4rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.35rem;">
                    <i class="fa-solid fa-minus-circle" style="color: var(--danger); font-size: 0.85rem;"></i>
                    Drawer Payout (Expense)
                </h3>
                <form action="" method="POST" style="margin: 0; display: flex; flex-direction: column; gap: 0.5rem;">
                    <input type="hidden" name="action" value="add_payout">
                    
                    <div style="display: grid; grid-template-columns: 80px 1fr 1fr; gap: 0.4rem;">
                        <input type="number" step="0.01" min="0.01" name="payout_amount" class="form-control" placeholder="₹ Amount" required style="height: 32px; padding: 0.25rem 0.4rem; font-size: 0.8rem; font-weight: 600;">
                        <input type="text" name="reason" class="form-control" placeholder="Reason (Courier)" required style="height: 32px; padding: 0.25rem 0.4rem; font-size: 0.8rem;">
                        <input type="text" name="recipient_name" class="form-control" placeholder="Paid To (Name)" style="height: 32px; padding: 0.25rem 0.4rem; font-size: 0.8rem;">
                    </div>
                    
                    <button type="submit" class="btn btn-secondary" style="height: 30px; font-size: 0.8rem; padding: 0; display: flex; align-items: center; justify-content: center; gap: 0.35rem; color: var(--danger); border-color: rgba(255, 71, 87, 0.2);">
                        <i class="fa-solid fa-hand-holding-dollar"></i> Record Expense
                    </button>
                </form>
            </div>

            <!-- Form 2: Close Shift Reconciliation -->
            <div class="card" style="padding: 1rem;">
                <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.6rem; display: flex; align-items: center; gap: 0.4rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.35rem;">
                    <i class="fa-solid fa-file-contract" style="color: var(--accent-color); font-size: 0.85rem;"></i>
                    Close Shift
                </h3>
                <form action="" method="POST" style="margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                    <input type="hidden" name="action" value="close_register">
                    
                    <div>
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.15rem; font-weight: 600;">Actual Cash Counted (₹)</label>
                        <input type="number" step="0.01" min="0" name="actual_closing_balance" id="actualClosingInput" class="form-control" placeholder="0.00" required style="height: 34px; padding: 0.25rem 0.5rem; font-size: 0.95rem; font-weight: 700;">
                    </div>
                    
                    <div>
                        <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.15rem; font-weight: 600;">Shift Notes (Optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="Any discrepancy reason..." style="height: 32px; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                    </div>

                    <!-- Variance Info -->
                    <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 4px; padding: 0.4rem 0.5rem; display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem;">
                        <span style="font-weight: 600; color: var(--text-secondary);">Variance:</span>
                        <span id="varianceDisplay" style="font-weight: 800; color: var(--text-muted);">₹0.00</span>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="height: 34px; font-size: 0.8rem; background: var(--danger); border-color: var(--danger); box-shadow: none; display: flex; align-items: center; justify-content: center; gap: 0.35rem;" onclick="return confirm('Are you sure you want to CLOSE this session?');">
                        <i class="fa-solid fa-lock"></i> Close Register & End Shift
                    </button>
                </form>
            </div>
        </div>

    </div>

<?php else: ?>
    <!-- Closed Status (Prompt to open register) -->
    <div style="max-width: 440px; margin: 4rem auto 2rem;">
        <div class="card" style="padding: 1.75rem; text-align: center; border: 1px dashed var(--border-color);">
            <i class="fa-solid fa-lock" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem; opacity: 0.4;"></i>
            <h3 style="margin: 0 0 0.5rem; font-size: 1.25rem; color: var(--text-primary);">Register is Closed</h3>
            <p style="color: var(--text-secondary); margin: 0 0 1.25rem; line-height: 1.4; font-size: 0.85rem;">
                The cash register is closed. You must open a new session with an initial cash float before processing transactions.
            </p>
            
            <form action="" method="POST" style="margin: 0; display: flex; flex-direction: column; gap: 0.85rem; text-align: left;">
                <input type="hidden" name="action" value="open_register">
                
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.8rem; margin-bottom: 0.25rem;">Opening Cash Float (₹)</label>
                    <input type="number" step="0.01" min="0" value="0.00" name="opening_balance" class="form-control" required style="font-size: 1.1rem; font-weight: 700; height: 38px; padding: 0.25rem 0.5rem;">
                </div>
                
                <button type="submit" class="btn btn-primary" style="height: 38px; display: flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--success); border-color: var(--success); font-size: 0.85rem; font-weight: 600; box-shadow: none;">
                    <i class="fa-solid fa-unlock"></i> Open Register & Start Shift
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- BOTTOM PANEL: SESSIONS HISTORY -->
<div class="card" style="padding: 0; overflow: hidden; margin-top: 1.5rem;">
    <div style="padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); background: rgba(0,0,0,0.05);">
        <h3 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--accent-color); font-size: 0.85rem;"></i>
            Session History Log (Last 20)
        </h3>
    </div>
    
    <div class="table-responsive">
        <table class="table" style="margin: 0; font-size: 0.8rem;">
            <thead>
                <tr>
                    <th style="padding: 0.4rem 0.5rem;">Opened By</th>
                    <th style="padding: 0.4rem 0.5rem;">Opened At</th>
                    <th style="padding: 0.4rem 0.5rem;">Closed At</th>
                    <th style="text-align: right; padding: 0.4rem 0.5rem;">Opening Float</th>
                    <th style="text-align: right; padding: 0.4rem 0.5rem;">Expenses</th>
                    <th style="text-align: right; padding: 0.4rem 0.5rem;">Expected Close</th>
                    <th style="text-align: right; padding: 0.4rem 0.5rem;">Actual Close</th>
                    <th style="text-align: right; padding: 0.4rem 0.5rem;">Difference</th>
                    <th style="text-align: center; padding: 0.4rem 0.5rem;">Status</th>
                    <th style="padding: 0.4rem 0.5rem;">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sessions_history)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            <i class="fa-solid fa-box-open" style="font-size: 2rem; display: block; margin-bottom: 0.5rem; opacity: 0.3;"></i>
                            No register sessions recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sessions_history as $row): 
                        $diff = 0;
                        if ($row['status'] === 'closed') {
                            $diff = (float)$row['actual_closing_balance'] - (float)$row['expected_closing_balance'];
                        }
                    ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-primary); padding: 0.4rem 0.5rem;">
                                <?= h(ucfirst($row['username'])) ?>
                            </td>
                            <td style="color: var(--text-secondary); padding: 0.4rem 0.5rem;">
                                <?= date('d M - h:i A', strtotime($row['opened_at'])) ?>
                            </td>
                            <td style="color: var(--text-secondary); padding: 0.4rem 0.5rem;">
                                <?= $row['closed_at'] ? date('d M - h:i A', strtotime($row['closed_at'])) : '<span style="color: var(--success); font-style: italic; font-weight:600;">Active</span>' ?>
                            </td>
                            <td style="text-align: right; padding: 0.4rem 0.5rem;">₹<?= number_format($row['opening_balance'], 2) ?></td>
                            <td style="text-align: right; color: var(--danger); padding: 0.4rem 0.5rem;">
                                <?= $row['status'] === 'closed' ? '₹' . number_format($row['total_expenses'], 2) : '₹' . number_format($active_payouts, 2) ?>
                            </td>
                            <td style="text-align: right; color: var(--text-secondary); padding: 0.4rem 0.5rem;">
                                <?= $row['status'] === 'closed' ? '₹' . number_format($row['expected_closing_balance'], 2) : '-' ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; padding: 0.4rem 0.5rem;">
                                <?= $row['status'] === 'closed' ? '₹' . number_format($row['actual_closing_balance'], 2) : '-' ?>
                            </td>
                            <td style="text-align: right; font-weight: bold; padding: 0.4rem 0.5rem;">
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
                            <td style="text-align: center; padding: 0.4rem 0.5rem;">
                                <?php if ($row['status'] === 'open'): ?>
                                    <span class="badge badge-confirmed" style="background-color: rgba(46, 213, 115, 0.1); color: var(--success); padding: 1px 6px; border-radius: 10px; font-size: 0.7rem;">
                                        Open
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background-color: rgba(255, 255, 255, 0.05); color: var(--text-secondary); padding: 1px 6px; border-radius: 10px; font-size: 0.7rem; border: 1px solid var(--border-color);">
                                        Closed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.75rem; color: var(--text-muted); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0.4rem 0.5rem;" title="<?= h($row['notes'] ?? '') ?>">
                                <?= h($row['notes'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.15); opacity: 0.6; }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const actualInput = document.getElementById('actualClosingInput');
    const varianceDisplay = document.getElementById('varianceDisplay');
    
    if (actualInput && varianceDisplay) {
        const expectedVal = <?= (float)($expected_cash_in_drawer ?? 0) ?>;
        
        actualInput.addEventListener('input', () => {
            const actualVal = parseFloat(actualInput.value) || 0;
            const diff = actualVal - expectedVal;
            
            if (diff === 0) {
                varianceDisplay.innerText = '₹0.00';
                varianceDisplay.style.color = 'var(--success)';
            } else if (diff > 0) {
                varianceDisplay.innerText = '+₹' + diff.toFixed(2);
                varianceDisplay.style.color = 'var(--info)';
            } else {
                varianceDisplay.innerText = '-₹' + Math.abs(diff).toFixed(2);
                varianceDisplay.style.color = 'var(--danger)';
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
