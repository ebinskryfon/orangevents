<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// ── Auto-mark overdue orders ──────────────────────────────────────────────────
$db->exec("UPDATE rental_orders
              SET status = 'overdue'
            WHERE status = 'active'
              AND rental_end_date < CURDATE()");

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($filter_status) {
    $where[] = "ro.status = :status";
    $params['status'] = $filter_status;
}
if ($filter_search) {
    $where[] = "(ro.client_name LIKE :q OR ro.client_phone LIKE :q OR ro.order_number LIKE :q OR ro.event_name LIKE :q)";
    $params['q'] = "%$filter_search%";
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query("SELECT
    COUNT(*)                                                           AS total,
    SUM(status = 'active')                                            AS active,
    SUM(status = 'overdue')                                           AS overdue,
    SUM(status = 'returned')                                          AS returned,
    SUM(status = 'draft')                                             AS draft,
    SUM(total_amount)                                                 AS revenue,
    SUM(advance_paid)                                                 AS collected,
    SUM(balance_due)                                                  AS outstanding
FROM rental_orders")->fetch();

// ── Orders list ───────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT ro.*, e.title as linked_event_title,
           COUNT(roi.id) AS item_count
      FROM rental_orders ro
      LEFT JOIN rental_order_items roi ON roi.order_id = ro.id
      LEFT JOIN events e ON ro.event_id = e.id
      $sql_where
     GROUP BY ro.id
     ORDER BY ro.created_at DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// ── Status meta (badge + label) ───────────────────────────────────────────────
$status_meta = [
    'draft' => ['badge' => 'badge-draft', 'label' => 'Draft', 'icon' => 'fa-pen'],
    'active' => ['badge' => 'badge-confirmed', 'label' => 'Active', 'icon' => 'fa-circle-play'],
    'overdue' => ['badge' => 'badge-cancelled', 'label' => 'Overdue', 'icon' => 'fa-clock'],
    'returned' => ['badge' => 'badge-paid', 'label' => 'Returned', 'icon' => 'fa-circle-check'],
    'cancelled' => ['badge' => 'badge-cancelled', 'label' => 'Cancelled', 'icon' => 'fa-ban'],
];
?>

<div class="content-header">
    <div class="header-title">
        <h1>Rental Orders</h1>
        <p>Track all rental bookings, advance payments, and item returns.</p>
    </div>
    <a href="rental-form.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> New Rental Order
    </a>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom:2rem;">
    <div class="card stat-card">
        <div class="stat-icon"><i class="fa-solid fa-file-contract"></i></div>
        <div class="stat-info">
            <h3><?= $stats['total'] ?></h3>
            <p>Total Orders</p>
        </div>
    </div>
    <div class="card stat-card blue">
        <div class="stat-icon"><i class="fa-solid fa-circle-play"></i></div>
        <div class="stat-info">
            <h3><?= $stats['active'] ?></h3>
            <p>Active Rentals</p>
        </div>
    </div>
    <div class="card stat-card" style="--stat-c:#ffa502;">
        <div class="stat-icon" style="background:rgba(255,165,2,0.15);color:#ffa502;"><i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-info">
            <h3><?= $stats['overdue'] ?></h3>
            <p>Overdue</p>
        </div>
    </div>
    <div class="card stat-card green">
        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <h3><?= $stats['returned'] ?></h3>
            <p>Returned</p>
        </div>
    </div>
    <div class="card stat-card purple">
        <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
        <div class="stat-info">
            <h3><?= format_price($stats['revenue'] ?? 0, false) ?></h3>
            <p>Total Revenue</p>
        </div>
    </div>
    <div class="card stat-card blue">
        <div class="stat-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div class="stat-info">
            <h3><?= format_price($stats['outstanding'] ?? 0, false) ?></h3>
            <p>Outstanding Balance</p>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="margin-bottom:1.5rem;padding:1rem 1.5rem;">
    <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <input type="text" name="q" class="form-control" style="flex:1;min-width:200px;padding:0.6rem 1rem;"
            placeholder="Search by name, phone, order #, event…" value="<?= h($filter_search) ?>">
        <select name="status" class="form-control" style="width:160px;padding:0.6rem 1rem;">
            <option value="">All Statuses</option>
            <?php foreach ($status_meta as $val => $m): ?>
                <option value="<?= $val ?>" <?= $filter_status === $val ? 'selected' : '' ?>><?= $m['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:0.6rem 1.2rem;">
            <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <?php if ($filter_status || $filter_search): ?>
            <a href="rentals.php" class="btn btn-secondary" style="padding:0.6rem 1.2rem;">
                <i class="fa-solid fa-xmark"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Client</th>
                    <th>Event / Occasion</th>
                    <th style="text-align:center;">Period</th>
                    <th style="text-align:center;">Items</th>
                    <th style="text-align:right;">Total</th>
                    <th style="text-align:right;">Paid</th>
                    <th style="text-align:right;">Balance</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:3rem;color:var(--text-muted);">
                            <i class="fa-solid fa-box-open"
                                style="font-size:2.5rem;display:block;margin-bottom:0.75rem;opacity:0.4;"></i>
                            No rental orders found. <a href="rental-form.php" style="color:var(--accent-color);">Create the
                                first one →</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $o):
                        $meta = $status_meta[$o['status']] ?? $status_meta['draft'];
                        ?>
                        <tr>
                            <td>
                                <a href="view-rental.php?id=<?= $o['id'] ?>" style="font-weight:700;color:var(--accent-color);">
                                    <?= h($o['order_number']) ?>
                                </a>
                            </td>
                            <td>
                                <div style="font-weight:600;color:var(--text-primary);"><?= h($o['client_name']) ?></div>
                                <div style="font-size:0.8rem;color:var(--text-muted);"><?= h($o['client_phone']) ?></div>
                            </td>
                            <td style="color:var(--text-secondary);font-size:0.875rem;">
                                <?= h($o['event_name'] ?: '—') ?>
                                <?php if ($o['event_id']): ?>
                                    <br><a href="event-form.php?id=<?= $o['event_id'] ?>&module=event"
                                        style="font-size: 0.75rem; color: var(--accent-color);"><i class="fa-solid fa-link"></i>
                                        Linked Event</a>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <div style="font-size:0.8rem;white-space:nowrap;">
                                    <?= format_date($o['rental_start_date']) ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--text-muted);">to
                                    <?= format_date($o['rental_end_date']) ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--text-muted);"><?= $o['num_days'] ?>
                                    day<?= $o['num_days'] != 1 ? 's' : '' ?></div>
                            </td>
                            <td style="text-align:center;">
                                <span
                                    style="display:inline-block;padding:0.2rem 0.6rem;border-radius:50px;background:rgba(255,107,53,0.12);font-weight:600;font-size:0.82rem;">
                                    <?= $o['item_count'] ?>
                                </span>
                            </td>
                            <td style="text-align:right;font-weight:700;"><?= format_price($o['total_amount']) ?></td>
                            <td style="text-align:right;color:var(--success);font-weight:600;">
                                <?= format_price($o['advance_paid']) ?>
                            </td>
                            <td
                                style="text-align:right;<?= $o['balance_due'] > 0 ? 'color:var(--danger);font-weight:700;' : 'color:var(--text-muted);' ?>">
                                <?= format_price($o['balance_due']) ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge <?= $meta['badge'] ?>">
                                    <i class="fa-solid <?= $meta['icon'] ?>"
                                        style="margin-right:0.25rem;"></i><?= $meta['label'] ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:0.3rem;">
                                    <a href="view-rental.php?id=<?= $o['id'] ?>" class="btn btn-secondary"
                                        style="padding:0.3rem 0.6rem;font-size:0.75rem;" title="View Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <a href="rental-form.php?id=<?= $o['id'] ?>" class="btn btn-secondary"
                                        style="padding:0.3rem 0.6rem;font-size:0.75rem;" title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
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