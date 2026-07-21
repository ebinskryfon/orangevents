<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// Handle status updates if any
if (isset($_GET['action']) && isset($_GET['id'])) {
    $inv_id = (int)$_GET['id'];
    $act = $_GET['action'];
    
    if ($act === 'pay') {
        $db->prepare("UPDATE invoices SET status = 'paid', balance_received = final_total - advance_received, balance_paid_at = NOW(), balance_payment_method = COALESCE(payment_method, 'CASH'), payment_method = COALESCE(payment_method, 'CASH') WHERE id = :id")->execute(['id' => $inv_id]);
    }
}

// Fetch all invoices
$sql = "SELECT i.*, e.title, e.client_name, e.event_date, e.venue 
        FROM invoices i 
        JOIN events e ON i.event_id = e.id 
        ORDER BY i.created_at DESC";
$invoices = $db->query($sql)->fetchAll();
?>

<!-- Compact Header -->
<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0; display: flex; justify-content: space-between; align-items: flex-start;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-file-invoice-dollar" style="color:var(--accent-color);"></i>
            Event Invoices Archive
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            Review draft layouts, finalize event bookings, track advances and balances, and print menu quotes.
        </p>
    </div>
    <div>
        <a href="event-form.php" class="btn btn-primary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <i class="fa-solid fa-plus"></i> New Event Booking
        </a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success" style="font-size:0.85rem; padding:0.6rem 1rem; margin-bottom:1rem;">
        <i class="fa-solid fa-circle-check"></i> Invoice deleted successfully. Event booking status reset to draft.
    </div>
<?php endif; ?>

<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); overflow:hidden; box-shadow:var(--box-shadow);">
    <div class="table-responsive">
        <table class="table" style="width:100%; margin:0; font-size:0.8rem;">
            <thead style="background:var(--bg-control); border-bottom:1px solid var(--border-color);">
                <tr>
                    <th style="padding:0.6rem 0.75rem;">Invoice No.</th>
                    <th style="padding:0.6rem 0.75rem;">Client & Event</th>
                    <th style="padding:0.6rem 0.75rem;">Event Date & Venue</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Grand Total</th>
                    <th style="padding:0.6rem 0.75rem; text-align:center;">Status</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:2rem; color:var(--text-muted);">
                            <i class="fa-solid fa-file-excel" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>
                            No invoices generated yet. Create a booking to compile a draft invoice.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr style="border-bottom:1px solid var(--border-color);">
                            <td style="padding:0.6rem 0.75rem; font-weight:700; color:var(--text-primary);">
                                <a href="view-invoice.php?event_id=<?= $inv['event_id'] ?>" style="color:var(--accent-color); text-decoration:none;">
                                    <?= h($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td style="padding:0.6rem 0.75rem;">
                                <div style="font-weight:700; color:var(--text-primary);"><?= h($inv['client_name']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-secondary);"><?= h($inv['title']) ?></div>
                            </td>
                            <td style="padding:0.6rem 0.75rem; color:var(--text-secondary);">
                                <div style="font-weight:600; color:var(--text-primary);"><?= format_date($inv['event_date']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?= h($inv['venue']) ?></div>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right;">
                                <div style="font-weight:800; color:var(--accent-color);">₹<?= number_format($inv['final_total'], 2) ?></div>
                                <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">
                                    Paid: <span style="color:var(--success); font-weight:600;">₹<?= number_format($inv['advance_received'] + $inv['balance_received'], 2) ?></span>
                                    • Due: <span style="color:var(--danger); font-weight:600;">₹<?= number_format(max(0, $inv['final_total'] - ($inv['advance_received'] + $inv['balance_received'])), 2) ?></span>
                                </div>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:center;">
                                <span class="badge" style="background:rgba(255,107,53,0.1); color:var(--accent-color); padding:2px 8px; border-radius:10px; font-weight:700; text-transform:uppercase; font-size:0.68rem;">
                                    <?= h($inv['status']) ?>
                                </span>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right;">
                                <div style="display:inline-flex; gap:0.25rem;">
                                    <a href="view-invoice.php?event_id=<?= $inv['event_id'] ?>" class="btn btn-primary" style="padding:0.2rem 0.45rem; font-size:0.7rem;">
                                        <i class="fa-solid fa-eye"></i> Quote
                                    </a>
                                    <a href="edit-invoice.php?event_id=<?= $inv['event_id'] ?>" class="btn btn-secondary" style="padding:0.2rem 0.45rem; font-size:0.7rem;">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <?php if ($inv['status'] === 'finalized'): ?>
                                        <a href="?action=pay&id=<?= $inv['id'] ?>" class="btn btn-success" style="padding:0.2rem 0.45rem; font-size:0.7rem;">
                                            <i class="fa-solid fa-check"></i> Pay
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
