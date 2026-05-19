<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// Handle status updates if any
if (isset($_GET['action']) && isset($_GET['id'])) {
    $inv_id = (int)$_GET['id'];
    $act = $_GET['action'];
    
    if ($act === 'pay') {
        $db->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")->execute(['id' => $inv_id]);
    }
}

// Fetch all invoices
$sql = "SELECT i.*, e.title, e.client_name, e.event_date, e.venue 
        FROM invoices i 
        JOIN events e ON i.event_id = e.id 
        ORDER BY i.created_at DESC";
$invoices = $db->query($sql)->fetchAll();
?>

<?php if (isset($_GET['deleted'])): ?>
    <div style="background-color: #ef4444; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid fa-circle-check"></i> Invoice deleted successfully. The event booking status has been reset to draft.</span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<div class="content-header">
    <div class="header-title">
        <h1>Invoice Archives</h1>
        <p>Review draft layouts, finalize bookings, track client payments, and print menu sheets.</p>
    </div>
    <div>
        <a href="event-form.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add New Booking
        </a>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom: 1.5rem;">
        <span><i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent-color); margin-right: 0.5rem;"></i> Active System Invoices</span>
    </h3>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 15%;">Invoice No</th>
                    <th style="width: 25%;">Client / Event</th>
                    <th style="width: 15%;">Event Date</th>
                    <th style="width: 15%;">Subtotal</th>
                    <th style="width: 15%;">Status</th>
                    <th style="width: 15%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            No invoices generated yet. Create a booking to compile a draft invoice.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-primary);"><?= h($inv['invoice_number']) ?></td>
                            <td>
                                <div style="font-weight: 600;"><?= h($inv['client_name']) ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= h($inv['title']) ?></div>
                            </td>
                            <td>
                                <div><?= format_date($inv['event_date']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= h($inv['venue']) ?></div>
                            </td>
                            <td style="font-weight: 600; color: var(--accent-color);"><?= format_price($inv['final_total']) ?></td>
                            <td>
                                <span class="badge badge-<?= h($inv['status']) ?>"><?= h($inv['status']) ?></span>
                            </td>
                            <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                <a href="view-invoice.php?event_id=<?= $inv['event_id'] ?>" class="btn btn-primary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: var(--accent-gradient);">
                                    <i class="fa-solid fa-eye"></i> View Menu & Quote
                                </a>
                                <?php if ($inv['status'] === 'draft'): ?>
                                    <a href="event-form.php?id=<?= $inv['event_id'] ?>" class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </a>
                                <?php elseif ($inv['status'] === 'finalized'): ?>
                                    <a href="?action=pay&id=<?= $inv['id'] ?>" class="btn btn-success" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> Mark Paid
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
