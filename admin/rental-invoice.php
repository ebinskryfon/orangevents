<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();

$db = get_db_connection();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: rentals.php'); exit; }

$order = $db->prepare("SELECT * FROM rental_orders WHERE id = :id");
$order->execute(['id' => $id]);
$order = $order->fetch();
if (!$order) { header('Location: rentals.php'); exit; }

$items = $db->prepare("SELECT * FROM rental_order_items WHERE order_id = :id ORDER BY id ASC");
$items->execute(['id' => $id]);
$items = $items->fetchAll();

$payments = $db->prepare("SELECT * FROM rental_payments WHERE order_id = :id ORDER BY payment_date ASC");
$payments->execute(['id' => $id]);
$payments = $payments->fetchAll();

$total_paid   = array_sum(array_column($payments, 'amount'));
$balance_due  = max(0, $order['total_amount'] - $total_paid);
$settings     = get_settings();

$method_labels = ['cash'=>'Cash','upi'=>'UPI','bank_transfer'=>'Bank Transfer','cheque'=>'Cheque','other'=>'Other'];
$type_labels   = ['advance'=>'Advance','partial'=>'Partial','balance'=>'Balance','refund'=>'Refund'];

require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Screen: control bar ───────────────────────────── */
.ri-control-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 1rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

/* ── A4 Invoice Paper ──────────────────────────────── */
.ri-paper {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto 2rem;
    background: #ffffff;
    color: #1a1a2e;
    font-family: 'Segoe UI', 'Inter', Arial, sans-serif;
    font-size: 11pt;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 8px 40px rgba(0,0,0,0.35);
    display: flex;
    flex-direction: column;
}

/* ── Header band ───────────────────────────────────── */
.ri-header {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
    padding: 28px 32px 22px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: relative;
    overflow: hidden;
}
.ri-header::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #ff6b35, #ffa500, #ff6b35);
}
.ri-brand-name {
    font-size: 22pt;
    font-weight: 800;
    color: #ff6b35;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.ri-brand-sub {
    font-size: 8.5pt;
    color: rgba(255,255,255,0.6);
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.ri-brand-contact {
    font-size: 8pt;
    color: rgba(255,255,255,0.5);
    margin-top: 6px;
    line-height: 1.6;
}
.ri-inv-label {
    text-align: right;
}
.ri-inv-label h2 {
    font-size: 18pt;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 4px;
    text-transform: uppercase;
    letter-spacing: 2px;
}
.ri-inv-label .ri-inv-num {
    font-size: 10pt;
    color: #ff6b35;
    font-weight: 700;
}
.ri-inv-label .ri-inv-date {
    font-size: 8pt;
    color: rgba(255,255,255,0.5);
    margin-top: 4px;
}

/* ── Status chip ───────────────────────────────────── */
.ri-status-chip {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 50px;
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 6px;
}
.ri-status-active   { background: rgba(59,130,246,0.2); color: #3b82f6; border: 1px solid #3b82f6; }
.ri-status-returned { background: rgba(34,197,94,0.2);  color: #22c55e; border: 1px solid #22c55e; }
.ri-status-overdue  { background: rgba(255,165,2,0.2);  color: #ffa502; border: 1px solid #ffa502; }
.ri-status-draft    { background: rgba(100,116,139,0.2);color: #64748b; border: 1px solid #64748b; }
.ri-status-cancelled{ background: rgba(239,68,68,0.2);  color: #ef4444; border: 1px solid #ef4444; }

/* ── Client & period row ───────────────────────────── */
.ri-meta-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0;
    border-bottom: 2px solid #ff6b35;
}
.ri-meta-cell {
    padding: 14px 20px;
    border-right: 1px solid #e5e7eb;
}
.ri-meta-cell:last-child { border-right: none; }
.ri-meta-label {
    font-size: 7pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #9ca3af;
    margin-bottom: 4px;
}
.ri-meta-value {
    font-size: 10.5pt;
    font-weight: 700;
    color: #1a1a2e;
}
.ri-meta-sub {
    font-size: 8pt;
    color: #6b7280;
    margin-top: 2px;
}

/* ── Items table ───────────────────────────────────── */
.ri-body { padding: 20px 28px; flex: 1; }

.ri-section-title {
    font-size: 8.5pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #ff6b35;
    border-bottom: 1.5px solid #ff6b35;
    padding-bottom: 5px;
    margin: 0 0 10px;
}

.ri-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5pt;
    margin-bottom: 18px;
}
.ri-table thead tr {
    background: #1a1a2e;
    color: #ffffff;
}
.ri-table thead th {
    padding: 8px 12px;
    font-size: 8pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.ri-table tbody tr:nth-child(even) { background: #f9fafb; }
.ri-table tbody td {
    padding: 8px 12px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.ri-table tfoot td {
    padding: 7px 12px;
    font-size: 9pt;
}
.ri-table tfoot tr.ri-total-row td {
    border-top: 2px solid #ff6b35;
    font-size: 11pt;
    font-weight: 800;
    color: #ff6b35;
}

/* ── Summary + Payments two-col ───────────────────── */
.ri-bottom-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 20px;
    margin-top: 16px;
}
.ri-payment-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.5pt;
}
.ri-payment-table th {
    font-size: 7.5pt;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: #6b7280;
    border-bottom: 1px solid #e5e7eb;
    padding: 5px 8px;
}
.ri-payment-table td {
    padding: 5px 8px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
}

/* ── Financial summary box ─────────────────────────── */
.ri-summary-box {
    background: #1a1a2e;
    border-radius: 8px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    color: #ffffff;
}
.ri-summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 9pt;
    color: rgba(255,255,255,0.7);
}
.ri-summary-row.ri-total {
    border-top: 1px dashed rgba(255,255,255,0.2);
    padding-top: 8px;
    font-size: 12pt;
    font-weight: 800;
    color: #ff6b35;
}
.ri-summary-row.ri-paid { color: #4ade80; }
.ri-summary-row.ri-balance { color: #f87171; font-weight: 700; }

/* ── Footer bar ────────────────────────────────────── */
.ri-footer {
    background: #f9fafb;
    border-top: 2px solid #ff6b35;
    padding: 14px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 8pt;
    color: #6b7280;
}
.ri-footer-note {
    font-style: italic;
    color: #9ca3af;
    font-size: 7.5pt;
}
.ri-footer-sig {
    text-align: right;
}
.ri-footer-sig div:first-child {
    border-top: 1px solid #d1d5db;
    padding-top: 4px;
    margin-top: 20px;
    font-weight: 700;
    color: #374151;
    font-size: 8pt;
}

/* ── Print styles ──────────────────────────────────── */
@media print {
    @page { size: A4 portrait; margin: 0; }
    body, html { background: #fff !important; height: auto !important; }
    .app-container, .main-content { 
        display: block !important; 
        height: auto !important; 
        min-height: auto !important; 
    }
    
    /* Hide screen-only elements safely */
    .sidebar { display: none !important; }
    .ri-control-bar { display: none !important; }
    
    /* Reset main content */
    .main-content { margin-left: 0 !important; padding: 0 !important; overflow: visible !important; }
    .ri-print-wrapper { display: block !important; overflow: visible !important; }
    
    /* Ensure the paper takes up the full space for printing */
    .ri-paper {
        width: 100% !important;
        max-width: 100% !important;
        min-height: auto !important;
        margin: 0 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        display: block !important;
        overflow: visible !important;
    }
}
</style>

<!-- Control Bar (screen only) -->
<div class="ri-control-bar">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <a href="view-rental.php?id=<?= $id ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to Order
        </a>
        <span style="color:var(--text-secondary);font-weight:600;"><?= h($order['order_number']) ?></span>
    </div>
    <div style="display:flex;gap:0.5rem;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa-solid fa-print"></i> Print / Save PDF
        </button>
    </div>
    
    <div style="display:flex; gap:1.25rem; align-items:center; flex-wrap:wrap; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-color); width: 100%;">
        <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Display Options:</span>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-status" checked onchange="toggleElement('.ri-status-chip', this.checked)"> Status Chip
        </label>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-client" checked onchange="toggleElement('.meta-client', this.checked)"> Client Details
        </label>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-event" checked onchange="toggleElement('.meta-event', this.checked)"> Event Info
        </label>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-return" checked onchange="toggleElement('.meta-return', this.checked)"> Return / Notes
        </label>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-payments" checked onchange="toggleElement('.payment-history-block', this.checked)"> Payment History
        </label>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-bank" checked onchange="toggleElement('.bank-details-block', this.checked)"> Bank Details
        </label>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size: 0.9rem; cursor:pointer; color: var(--text-color);">
            <input type="checkbox" id="toggle-sig" checked onchange="toggleElement('.ri-footer-sig', this.checked)"> Signatory
        </label>
    </div>
</div>

<script>
function toggleElement(selector, isVisible) {
    const el = document.querySelector(selector);
    if(el) {
        el.style.display = isVisible ? '' : 'none';
    }
    
    // Dynamic grid adjustment for meta row
    if (selector.startsWith('.meta-')) {
        const row = document.querySelector('.ri-meta-row');
        if(row) {
            const visibleCells = Array.from(row.querySelectorAll('.ri-meta-cell')).filter(c => c.style.display !== 'none');
            if (visibleCells.length === 0) {
                row.style.borderBottom = 'none';
                row.style.display = 'none';
            } else {
                row.style.borderBottom = '2px solid #ff6b35';
                row.style.display = 'grid';
                row.style.gridTemplateColumns = `repeat(${visibleCells.length}, 1fr)`;
            }
        }
    }
    
    // Dynamic grid adjustment for bottom area (payments vs summary)
    if (selector === '.payment-history-block') {
        const bottomGrid = document.querySelector('.ri-bottom-grid');
        if(bottomGrid) {
            if (isVisible) {
                bottomGrid.style.gridTemplateColumns = '1.2fr 1fr';
            } else {
                bottomGrid.style.gridTemplateColumns = '1fr';
                bottomGrid.style.maxWidth = '400px';
                bottomGrid.style.marginLeft = 'auto';
            }
        }
    }
}
</script>

<!-- A4 Paper -->
<div class="ri-print-wrapper">
<div class="ri-paper">

    <!-- ── HEADER ─────────────────────────────────────── -->
    <div class="ri-header">
        <div>
            <div class="ri-brand-name"><?= h(get_setting('company_name', 'Orange Events')) ?></div>
            <div class="ri-brand-sub"><?= h(get_setting('company_subtitle', 'Premium Catering & Stage Decors')) ?></div>
            <div class="ri-brand-contact">
                <?= h(get_setting('company_address', 'Thumpoly P.O, Alappuzha')) ?><br>
                Mob: <?= h(get_setting('company_phone', '9946731720')) ?><br>
                <?= h(get_setting('company_email', '')) ?>
            </div>
        </div>
        <div class="ri-inv-label">
            <h2>Rental Invoice</h2>
            <div class="ri-inv-num"><?= h($order['order_number']) ?></div>
            <div class="ri-inv-date">Issued: <?= format_date($order['created_at']) ?></div>
            <?php
            $sc = ['draft'=>'draft','active'=>'active','overdue'=>'overdue','returned'=>'returned','cancelled'=>'cancelled'];
            $s  = $sc[$order['status']] ?? 'draft';
            ?>
            <div class="ri-status-chip ri-status-<?= $s ?>"><?= ucfirst($s) ?></div>
        </div>
    </div>

    <!-- ── META ROW ───────────────────────────────────── -->
    <div class="ri-meta-row">
        <div class="ri-meta-cell meta-client">
            <div class="ri-meta-label">Billed To</div>
            <div class="ri-meta-value"><?= h($order['client_name']) ?></div>
            <div class="ri-meta-sub"><?= h($order['client_phone']) ?></div>
            <?php if ($order['client_email']): ?>
            <div class="ri-meta-sub"><?= h($order['client_email']) ?></div>
            <?php endif; ?>
            <?php if ($order['client_address']): ?>
            <div class="ri-meta-sub" style="margin-top:3px;"><?= nl2br(h($order['client_address'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="ri-meta-cell meta-event">
            <div class="ri-meta-label">Event / Occasion</div>
            <div class="ri-meta-value"><?= h($order['event_name'] ?: '—') ?></div>
            <div class="ri-meta-sub" style="margin-top:6px;">
                <strong>Rental Period</strong><br>
                <?= format_date($order['rental_start_date']) ?> &nbsp;→&nbsp; <?= format_date($order['rental_end_date']) ?>
            </div>
            <div class="ri-meta-sub"><?= $order['num_days'] ?> day<?= $order['num_days'] != 1 ? 's' : '' ?></div>
        </div>
        <div class="ri-meta-cell meta-return">
            <div class="ri-meta-label">Return Date</div>
            <div class="ri-meta-value">
                <?= $order['actual_return_date'] ? format_date($order['actual_return_date']) : '—' ?>
            </div>
            <?php if ($order['notes']): ?>
            <div class="ri-meta-label" style="margin-top:10px;">Notes</div>
            <div class="ri-meta-sub"><?= nl2br(h($order['notes'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── BODY ───────────────────────────────────────── -->
    <div class="ri-body">

        <!-- Items Table -->
        <div class="ri-section-title">Items Rented</div>
        <table class="ri-table">
            <thead>
                <tr>
                    <th style="width:5%;text-align:center;">#</th>
                    <th style="width:40%;">Item / Description</th>
                    <th style="width:16%;text-align:center;">Daily Rate</th>
                    <th style="width:12%;text-align:center;">Qty</th>
                    <th style="width:12%;text-align:center;">Days</th>
                    <th style="width:15%;text-align:right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $it): ?>
                <tr>
                    <td style="text-align:center;color:#9ca3af;"><?= $i + 1 ?></td>
                    <td style="font-weight:600;"><?= h($it['item_name']) ?></td>
                    <td style="text-align:center;"><?= format_price($it['daily_rate']) ?></td>
                    <td style="text-align:center;"><?= $it['quantity'] ?></td>
                    <td style="text-align:center;color:#6b7280;"><?= $it['num_days'] ?></td>
                    <td style="text-align:right;font-weight:700;"><?= format_price($it['subtotal']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php if ($order['discount'] > 0): ?>
                <tr>
                    <td colspan="5" style="text-align:right;color:#6b7280;">Subtotal</td>
                    <td style="text-align:right;font-weight:600;"><?= format_price($order['subtotal']) ?></td>
                </tr>
                <tr>
                    <td colspan="5" style="text-align:right;color:#22c55e;">Discount</td>
                    <td style="text-align:right;color:#22c55e;font-weight:600;">- <?= format_price($order['discount']) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="ri-total-row">
                    <td colspan="5" style="text-align:right;">Total Amount</td>
                    <td style="text-align:right;"><?= format_price($order['total_amount']) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Bottom: Payment History + Financial Summary -->
        <div class="ri-bottom-grid">

            <!-- Payment History -->
            <div class="payment-history-block">
                <div class="ri-section-title">Payment History</div>
                <?php if (empty($payments)): ?>
                    <p style="color:#9ca3af;font-size:8.5pt;font-style:italic;">No payments recorded.</p>
                <?php else: ?>
                <table class="ri-payment-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th style="text-align:right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= format_date($p['payment_date']) ?></td>
                            <td style="font-weight:600;text-transform:capitalize;"><?= $type_labels[$p['payment_type']] ?? $p['payment_type'] ?></td>
                            <td><?= $method_labels[$p['payment_method']] ?? $p['payment_method'] ?></td>
                            <td style="color:#9ca3af;"><?= h($p['reference_number'] ?: '—') ?></td>
                            <td style="text-align:right;font-weight:700;color:#16a34a;">+ <?= format_price($p['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Financial Summary -->
            <div>
                <div class="ri-section-title">Balance Summary</div>
                <div class="ri-summary-box">
                    <div class="ri-summary-row">
                        <span>Subtotal</span>
                        <span><?= format_price($order['subtotal']) ?></span>
                    </div>
                    <?php if ($order['discount'] > 0): ?>
                    <div class="ri-summary-row" style="color:#4ade80;">
                        <span>Discount</span>
                        <span>- <?= format_price($order['discount']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="ri-summary-row ri-total">
                        <span>Grand Total</span>
                        <span><?= format_price($order['total_amount']) ?></span>
                    </div>
                    <div class="ri-summary-row ri-paid">
                        <span>Total Paid</span>
                        <span><?= format_price($total_paid) ?></span>
                    </div>
                    <?php $pct = $order['total_amount'] > 0 ? min(100, round($total_paid / $order['total_amount'] * 100)) : 0; ?>
                    <!-- Progress bar -->
                    <div style="background:rgba(255,255,255,0.1);border-radius:50px;height:6px;overflow:hidden;margin:2px 0;">
                        <div style="width:<?= $pct ?>%;background:<?= $balance_due > 0 ? '#f97316' : '#22c55e' ?>;height:100%;border-radius:50px;"></div>
                    </div>
                    <div style="font-size:7.5pt;color:rgba(255,255,255,0.45);text-align:right;"><?= $pct ?>% paid</div>
                    <div class="ri-summary-row ri-balance" style="<?= $balance_due <= 0 ? 'color:#4ade80;' : '' ?>">
                        <span><?= $balance_due <= 0 ? '✓ Fully Paid' : 'Balance Due' ?></span>
                        <span><?= format_price($balance_due) ?></span>
                    </div>
                </div>

                <!-- Bank Details -->
                <?php if (get_setting('company_bank_name')): ?>
                <div class="bank-details-block" style="margin-top:14px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:8pt;">
                    <div style="font-weight:700;color:#374151;margin-bottom:5px;font-size:7.5pt;text-transform:uppercase;letter-spacing:0.5px;">Bank Details</div>
                    <div style="line-height:1.7;color:#6b7280;">
                        <?= h(get_setting('company_bank_name')) ?><br>
                        A/C: <?= h(get_setting('company_bank_acc')) ?><br>
                        IFSC: <?= h(get_setting('company_bank_ifsc')) ?><br>
                        Name: <?= h(get_setting('company_bank_holder')) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── FOOTER ──────────────────────────────────────── -->
    <div class="ri-footer">
        <div>
            <div style="font-weight:600;color:#374151;margin-bottom:3px;">Thank you for choosing <?= h(get_setting('company_name', 'Orange Events')) ?>!</div>
            <div class="ri-footer-note">Items must be returned in good condition. Damage charges may apply.</div>
            <div class="ri-footer-note">This is a computer-generated rental invoice and does not require a physical signature.</div>
        </div>
        <div class="ri-footer-sig">
            <div></div>
            <div>Authorised Signatory</div>
            <div style="color:#9ca3af;font-size:7.5pt;"><?= h(get_setting('company_name', 'Orange Events')) ?></div>
        </div>
    </div>

</div><!-- .ri-paper -->
</div><!-- .ri-print-wrapper -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
