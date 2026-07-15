<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();

$db = get_db_connection();

// Handle deletion
$msg = '';
$msg_type = 'success';
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $order_id = (int)$_GET['id'];
    try {
        // Fetch order details for notification
        $stmt = $db->prepare("SELECT invoice_number FROM billing_orders WHERE id = :id");
        $stmt->execute(['id' => $order_id]);
        $inv_no = $stmt->fetchColumn();

        if ($inv_no) {
            $db->prepare("DELETE FROM billing_orders WHERE id = :id")->execute(['id' => $order_id]);
            $msg = "Invoice $inv_no has been deleted successfully.";
        }
    } catch (PDOException $e) {
        $msg = 'Error deleting invoice: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// Fetch stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(total_amount), 0) as subtotal,
        COALESCE(SUM(discount_amount), 0) as discount,
        COALESCE(SUM(final_amount), 0) as sales
    FROM billing_orders
")->fetch();

$total_count = (int)$stats['total_count'];
$total_sales = (float)$stats['sales'];
$total_discount = (float)$stats['discount'];
$avg_order = $total_count > 0 ? ($total_sales / $total_count) : 0;

// Fetch all orders
$orders = $db->query("SELECT * FROM billing_orders ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($msg)): ?>
    <div style="background-color: <?= $msg_type === 'success' ? '#2ed573' : '#ff4757' ?>; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> <?= h($msg) ?></span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<div class="content-header">
    <div class="header-title">
        <h1>POS Invoice Archives</h1>
        <p>Manage sales history, view printable thermal receipts, track payment methods, and monitor sales revenue.</p>
    </div>
    <div>
        <a href="billing.php" class="btn btn-primary">
            <i class="fa-solid fa-calculator"></i> POS Terminal
        </a>
    </div>
</div>

<!-- Stats Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
    <!-- Total Sales -->
    <div class="card" style="display: flex; align-items: center; gap: 1.25rem; padding: 1.5rem;">
        <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(46, 213, 115, 0.1); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
            <i class="fa-solid fa-chart-line"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; margin-bottom: 0.25rem;">Total POS Sales</p>
            <h3 style="font-size: 1.5rem; margin: 0; font-family: 'Outfit', sans-serif;">₹<?= number_format($total_sales, 2) ?></h3>
        </div>
    </div>
    
    <!-- Total Orders -->
    <div class="card" style="display: flex; align-items: center; gap: 1.25rem; padding: 1.5rem;">
        <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(255, 107, 53, 0.1); color: var(--accent-color); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; margin-bottom: 0.25rem;">Total Invoices</p>
            <h3 style="font-size: 1.5rem; margin: 0; font-family: 'Outfit', sans-serif;"><?= $total_count ?></h3>
        </div>
    </div>

    <!-- Avg Order Value -->
    <div class="card" style="display: flex; align-items: center; gap: 1.25rem; padding: 1.5rem;">
        <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(30, 144, 255, 0.1); color: var(--info); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
            <i class="fa-solid fa-scale-balanced"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; margin-bottom: 0.25rem;">Average Ticket</p>
            <h3 style="font-size: 1.5rem; margin: 0; font-family: 'Outfit', sans-serif;">₹<?= number_format($avg_order, 2) ?></h3>
        </div>
    </div>

    <!-- Total Discounts -->
    <div class="card" style="display: flex; align-items: center; gap: 1.25rem; padding: 1.5rem;">
        <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(255, 71, 87, 0.1); color: var(--danger); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
            <i class="fa-solid fa-tags"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; margin-bottom: 0.25rem;">Total Discounts</p>
            <h3 style="font-size: 1.5rem; margin: 0; font-family: 'Outfit', sans-serif;">₹<?= number_format($total_discount, 2) ?></h3>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="padding: 1.25rem; margin-bottom: 1.5rem;">
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between;">
        <div style="display: flex; flex-grow: 1; gap: 0.75rem; min-width: 280px;">
            <div style="position: relative; flex-grow: 1;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                <input type="text" id="invoiceSearch" class="form-control" placeholder="Search by Invoice No, Customer Name or Phone..." style="padding-left: 2.75rem; width: 100%;" onkeyup="filterInvoices()">
            </div>
            <select id="paymentFilter" class="form-control" style="width: 160px; cursor: pointer;" onchange="filterInvoices()">
                <option value="">All Payments</option>
                <option value="Cash">Cash</option>
                <option value="UPI">UPI</option>
                <option value="Card">Card</option>
            </select>
        </div>
        <div style="color: var(--text-muted); font-size: 0.9rem;">
            Showing <strong id="visibleCount" style="color: var(--text-primary);"><?= count($orders) ?></strong> of <strong><?= count($orders) ?></strong> records
        </div>
    </div>
</div>

<!-- Invoices Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table" id="invoicesTable">
            <thead>
                <tr>
                    <th style="width: 15%;">Invoice No</th>
                    <th style="width: 20%;">Date & Time</th>
                    <th style="width: 25%;">Customer Details</th>
                    <th style="width: 15%;">Total Amount</th>
                    <th style="width: 12%;">Method</th>
                    <th style="text-align: right; width: 13%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr class="no-data-row">
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 4rem 0;">
                            <i class="fa-solid fa-folder-open" style="font-size: 2.5rem; color: var(--text-muted); margin-bottom: 1rem; display: block;"></i>
                            No sales invoices found. Launch the POS terminal to execute checkout.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $ord): ?>
                        <tr class="invoice-row" data-invoice="<?= h(strtolower($ord['invoice_number'])) ?>" data-name="<?= h(strtolower($ord['customer_name'] ?? '')) ?>" data-phone="<?= h(strtolower($ord['customer_phone'] ?? '')) ?>" data-method="<?= h(strtolower($ord['payment_method'])) ?>">
                            <td style="font-weight: 700; color: var(--text-primary);"><?= h($ord['invoice_number']) ?></td>
                            <td style="color: var(--text-secondary);"><?= date('d M Y, h:i A', strtotime($ord['created_at'])) ?></td>
                            <td>
                                <?php if (!empty($ord['customer_name']) || !empty($ord['customer_phone'])): ?>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= h($ord['customer_name'] ?: 'N/A') ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><i class="fa-solid fa-phone" style="font-size: 0.7rem;"></i> <?= h($ord['customer_phone'] ?: 'N/A') ?></div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-style: italic;">Walk-in Customer</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: var(--accent-color);">₹<?= number_format($ord['final_amount'], 2) ?></div>
                                <?php if ($ord['discount_amount'] > 0): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                                        Subtotal: ₹<?= number_format($ord['total_amount'], 2) ?><br>
                                        Disc: -₹<?= number_format($ord['discount_amount'], 2) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background: <?= $ord['payment_method'] === 'Cash' ? 'rgba(46, 213, 115, 0.12)' : ($ord['payment_method'] === 'UPI' ? 'rgba(30, 144, 255, 0.12)' : 'rgba(255, 107, 53, 0.12)') ?>; color: <?= $ord['payment_method'] === 'Cash' ? 'var(--success)' : ($ord['payment_method'] === 'UPI' ? 'var(--info)' : 'var(--accent-color)') ?>; font-weight: 600;">
                                    <?= h($ord['payment_method']) ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                    <a href="billing-invoice.php?id=<?= $ord['id'] ?>" class="btn btn-secondary" style="padding: 0.4rem 0.6rem; font-size: 0.8rem;" title="View Thermal Receipt">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <a href="billing-invoice.php?id=<?= $ord['id'] ?>&print=1" class="btn btn-primary" style="padding: 0.4rem 0.6rem; font-size: 0.8rem; background: var(--accent-gradient);" title="Print Receipt">
                                        <i class="fa-solid fa-print"></i> Print
                                    </a>
                                    <a href="?action=delete&id=<?= $ord['id'] ?>" class="btn btn-danger" style="padding: 0.4rem 0.6rem; font-size: 0.8rem; background: rgba(255, 71, 87, 0.1); border-color: rgba(255, 71, 87, 0.15); color: var(--danger);" onclick="return confirm('Are you sure you want to delete invoice <?= $ord['invoice_number'] ?>? This action is permanent.');" title="Delete Invoice">
                                        <i class="fa-solid fa-trash"></i>
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

<script>
function filterInvoices() {
    const searchVal = document.getElementById('invoiceSearch').value.toLowerCase().trim();
    const paymentVal = document.getElementById('paymentFilter').value.toLowerCase().trim();
    
    const rows = document.querySelectorAll('.invoice-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const inv = row.getAttribute('data-invoice') || '';
        const name = row.getAttribute('data-name') || '';
        const phone = row.getAttribute('data-phone') || '';
        const method = row.getAttribute('data-method') || '';
        
        const matchesSearch = !searchVal || inv.includes(searchVal) || name.includes(searchVal) || phone.includes(searchVal);
        const matchesPayment = !paymentVal || method === paymentVal;
        
        if (matchesSearch && matchesPayment) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    document.getElementById('visibleCount').textContent = visibleCount;
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
