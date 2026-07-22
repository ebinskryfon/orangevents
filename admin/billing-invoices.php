<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();
require_permission('billing_read');

$db = get_db_connection();

function adjust_variant_stock($db, $variant_id, $quantity, $sell_type, $change_type, $order_id = null, $notes = '') {
    if (!$variant_id) return;
    
    // Fetch variant details
    $stmt = $db->prepare("SELECT id, stock_quantity, allow_loose, loose_units_per_whole FROM billing_product_variants WHERE id = :id");
    $stmt->execute(['id' => $variant_id]);
    $var = $stmt->fetch();
    
    if (!$var) return;
    
    // Determine stock deduction multiplier based on change_type
    $is_deduction = in_array($change_type, ['sale_whole', 'sale_loose']);
    $multiplier = $is_deduction ? -1 : 1;
    
    // Calculate stock change in packets (whole units)
    $stock_change = 0.00;
    if ($sell_type === 'loose') {
        $units_per_whole = (float)$var['loose_units_per_whole'];
        if ($units_per_whole <= 0) $units_per_whole = 1.00;
        $stock_change = ($quantity / $units_per_whole) * $multiplier;
    } else {
        $stock_change = $quantity * $multiplier;
    }
    
    // Update variant stock quantity
    $stmt_up = $db->prepare("UPDATE billing_product_variants SET stock_quantity = stock_quantity + :qty WHERE id = :id");
    $stmt_up->execute(['qty' => $stock_change, 'id' => $variant_id]);
    
    // Fetch updated stock for log
    $stmt_stock = $db->prepare("SELECT stock_quantity FROM billing_product_variants WHERE id = :id");
    $stmt_stock->execute(['id' => $variant_id]);
    $result_stock = (float)$stmt_stock->fetchColumn();
    
    // Log audit
    $stmt_log = $db->prepare("
        INSERT INTO billing_stock_logs (variant_id, order_id, change_type, quantity_changed, result_stock, notes)
        VALUES (:variant_id, :order_id, :change_type, :quantity_changed, :result_stock, :notes)
    ");
    $stmt_log->execute([
        'variant_id' => $variant_id,
        'order_id' => $order_id,
        'change_type' => $change_type,
        'quantity_changed' => $stock_change,
        'result_stock' => $result_stock,
        'notes' => $notes
    ]);
}

// Handle deletion
$msg = '';
$msg_type = 'success';
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (!has_permission('billing_delete')) {
        $msg = 'Access denied: You do not have the required permission (billing_delete) to delete invoices.';
        $msg_type = 'danger';
    } else {
        $order_id = (int) $_GET['id'];
        try {
        // Fetch order details for notification
        $stmt = $db->prepare("SELECT invoice_number FROM billing_orders WHERE id = :id");
        $stmt->execute(['id' => $order_id]);
        $inv_no = $stmt->fetchColumn();

        if ($inv_no) {
            // Restore stock for all variant items in the deleted order
            $stmt_old_items = $db->prepare("SELECT variant_id, quantity, sell_type FROM billing_order_items WHERE order_id = :id");
            $stmt_old_items->execute(['id' => $order_id]);
            $old_items = $stmt_old_items->fetchAll();
            foreach ($old_items as $old_item) {
                if ($old_item['variant_id']) {
                    $sell_type = $old_item['sell_type'];
                    $change_type = ($sell_type === 'loose') ? 'refund_loose' : 'refund_whole';
                    adjust_variant_stock($db, $old_item['variant_id'], $old_item['quantity'], $sell_type, $change_type, $order_id, "Order deletion #{$inv_no}");
                }
            }

            $db->prepare("DELETE FROM billing_orders WHERE id = :id")->execute(['id' => $order_id]);
            $msg = "Invoice $inv_no has been deleted successfully.";
        }
    } catch (PDOException $e) {
        $msg = 'Error deleting invoice: ' . $e->getMessage();
        $msg_type = 'danger';
    }
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

$total_count = (int) $stats['total_count'];
$total_sales = (float) $stats['sales'];
$total_discount = (float) $stats['discount'];
$avg_order = $total_count > 0 ? ($total_sales / $total_count) : 0;

// Fetch all orders
$orders = $db->query("SELECT * FROM billing_orders ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($msg)): ?>
    <div
        style="background-color: <?= $msg_type === 'success' ? '#2ed573' : '#ff4757' ?>; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= h($msg) ?></span>
        <button onclick="this.parentElement.style.display='none'"
            style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- Compact Header -->
<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-receipt" style="color: var(--accent-color);"></i>
            POS Invoice Archives
        </h1>
        <p style="color: var(--text-secondary); margin: 0.15rem 0 0; font-size: 0.75rem;">Manage sales history, view printable thermal receipts, track payment methods, and monitor sales revenue.</p>
    </div>
    <div>
        <a href="billing.php" class="btn btn-primary" style="height: 34px; padding: 0 0.75rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.35rem;">
            <i class="fa-solid fa-calculator"></i> POS Terminal
        </a>
    </div>
</div>

<!-- Stats Grid -->
<div
    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
    <!-- Total Sales -->
    <div class="card" style="display: flex; align-items: center; gap: 0.75rem; padding: 1rem;">
        <div
            style="width: 40px; height: 40px; border-radius: 8px; background: rgba(46, 213, 115, 0.1); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fa-solid fa-chart-line"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.75rem; font-weight: 500; margin-bottom: 0.15rem; margin-top: 0;">Total
                POS Sales</p>
            <h3 id="statTotalSales" style="font-size: 1.25rem; margin: 0; font-family: 'Outfit', sans-serif;">
                ₹<?= number_format($total_sales, 2) ?></h3>
        </div>
    </div>

    <!-- Total Orders -->
    <div class="card" style="display: flex; align-items: center; gap: 0.75rem; padding: 1rem;">
        <div
            style="width: 40px; height: 40px; border-radius: 8px; background: rgba(255, 107, 53, 0.1); color: var(--accent-color); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.75rem; font-weight: 500; margin-bottom: 0.15rem; margin-top: 0;">Total
                Invoices</p>
            <h3 id="statTotalCount" style="font-size: 1.25rem; margin: 0; font-family: 'Outfit', sans-serif;">
                <?= $total_count ?></h3>
        </div>
    </div>

    <!-- Avg Order Value -->
    <div class="card" style="display: flex; align-items: center; gap: 0.75rem; padding: 1rem;">
        <div
            style="width: 40px; height: 40px; border-radius: 8px; background: rgba(30, 144, 255, 0.1); color: var(--info); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fa-solid fa-scale-balanced"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.75rem; font-weight: 500; margin-bottom: 0.15rem; margin-top: 0;">
                Average Ticket</p>
            <h3 id="statAvgTicket" style="font-size: 1.25rem; margin: 0; font-family: 'Outfit', sans-serif;">
                ₹<?= number_format($avg_order, 2) ?></h3>
        </div>
    </div>

    <!-- Total Discounts -->
    <div class="card" style="display: flex; align-items: center; gap: 0.75rem; padding: 1rem;">
        <div
            style="width: 40px; height: 40px; border-radius: 8px; background: rgba(255, 71, 87, 0.1); color: var(--danger); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fa-solid fa-tags"></i>
        </div>
        <div>
            <p style="color: var(--text-secondary); font-size: 0.75rem; font-weight: 500; margin-bottom: 0.15rem; margin-top: 0;">Total
                Discounts</p>
            <h3 id="statTotalDiscount" style="font-size: 1.25rem; margin: 0; font-family: 'Outfit', sans-serif;">
                ₹<?= number_format($total_discount, 2) ?></h3>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="padding: 1rem; margin-bottom: 1rem;">
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between;">
        <div style="display: flex; flex-wrap: wrap; flex-grow: 1; gap: 0.5rem; min-width: 280px; align-items: center;">
            <div style="position: relative; flex-grow: 2; min-width: 200px;">
                <i class="fa-solid fa-magnifying-glass"
                    style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.8rem;"></i>
                <input type="text" id="invoiceSearch" class="form-control"
                    placeholder="Search by Invoice No, Customer Name or Phone..."
                    style="padding-left: 2.2rem; width: 100%; height: 34px; font-size: 0.8rem; padding-top: 0.25rem; padding-bottom: 0.25rem;" onkeyup="filterInvoices()">
            </div>
            <select id="paymentFilter" class="form-control" style="width: 130px; cursor: pointer; flex-grow: 0; height: 34px; font-size: 0.8rem; padding: 0.25rem 0.5rem;"
                onchange="filterInvoices()">
                <option value="">All Payments</option>
                <option value="Cash">Cash</option>
                <option value="UPI">UPI</option>
                <option value="Card">Card</option>
            </select>
            <div style="display: flex; align-items: center; gap: 0.25rem; flex-grow: 0;">
                <span style="font-size: 0.75rem; color: var(--text-muted); white-space: nowrap;">From:</span>
                <input type="date" id="dateFrom" class="form-control" value="<?= date('Y-m-d') ?>"
                    style="width: 125px; cursor: pointer; padding: 0.25rem 0.5rem; height: 34px; font-size: 0.8rem;" onchange="filterInvoices()">
            </div>
            <div style="display: flex; align-items: center; gap: 0.25rem; flex-grow: 0;">
                <span style="font-size: 0.75rem; color: var(--text-muted); white-space: nowrap;">To:</span>
                <input type="date" id="dateTo" class="form-control" value="<?= date('Y-m-d') ?>"
                    style="width: 125px; cursor: pointer; padding: 0.25rem 0.5rem; height: 34px; font-size: 0.8rem;" onchange="filterInvoices()">
            </div>
            <button type="button" onclick="setTodayFilter()" class="btn btn-secondary"
                style="height: 34px; padding: 0 0.65rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;" title="Filter Today's Invoices">
                <i class="fa-solid fa-calendar-day"></i> Today
            </button>
            <button type="button" onclick="clearDateFilters()" class="btn btn-secondary"
                style="height: 34px; padding: 0 0.65rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;" title="Show All Historical Invoices">
                <i class="fa-solid fa-globe"></i> All Time
            </button>
        </div>
        <div style="color: var(--text-muted); font-size: 0.8rem;">
            Showing <strong id="visibleCount" style="color: var(--text-primary);"><?= count($orders) ?></strong> of
            <strong><?= count($orders) ?></strong> records
        </div>
    </div>
</div>

<!-- Invoices Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" id="invoicesTable" style="margin: 0; font-size: 0.8rem;">
            <thead>
                <tr>
                    <th style="padding: 0.5rem 0.75rem; width: 14%;">Invoice No</th>
                    <th style="padding: 0.5rem 0.75rem; width: 18%;">Date & Time</th>
                    <th style="padding: 0.5rem 0.75rem; width: 25%;">Customer Details</th>
                    <th style="padding: 0.5rem 0.75rem; width: 15%;">Total Amount</th>
                    <th style="padding: 0.5rem 0.75rem; width: 10%;">Method</th>
                    <th style="padding: 0.5rem 0.75rem; text-align: right; width: 18%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr class="no-data-row">
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            <i class="fa-solid fa-folder-open"
                                style="font-size: 2.2rem; color: var(--text-muted); margin-bottom: 0.75rem; display: block; opacity: 0.3;"></i>
                            No sales invoices found. Launch the POS terminal to execute checkout.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $ord): ?>
                        <tr class="invoice-row" data-invoice="<?= h(strtolower($ord['invoice_number'])) ?>"
                            data-name="<?= h(strtolower($ord['customer_name'] ?? '')) ?>"
                            data-phone="<?= h(strtolower($ord['customer_phone'] ?? '')) ?>"
                            data-method="<?= h(strtolower($ord['payment_method'])) ?>"
                            data-date="<?= date('Y-m-d', strtotime($ord['created_at'])) ?>"
                            data-amount="<?= (float) $ord['final_amount'] ?>"
                            data-discount="<?= (float) $ord['discount_amount'] ?>"
                            style="transition: background 0.15s;">
                            <td style="padding: 0.5rem 0.75rem; font-weight: 700; color: var(--text-primary);"><?= h($ord['invoice_number']) ?></td>
                            <td style="padding: 0.5rem 0.75rem; color: var(--text-secondary);"><?= date('d M Y, h:i A', strtotime($ord['created_at'])) ?></td>
                            <td style="padding: 0.5rem 0.75rem;">
                                <?php if (!empty($ord['customer_name']) || !empty($ord['customer_phone'])): ?>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= h($ord['customer_name'] ?: 'N/A') ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><i class="fa-solid fa-phone" style="font-size: 0.65rem;"></i> <?= h($ord['customer_phone'] ?: 'N/A') ?></div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-style: italic;">Walk-in Customer</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.5rem 0.75rem;">
                                <div style="font-weight: 700; color: var(--accent-color);">₹<?= number_format($ord['final_amount'], 2) ?></div>
                                <?php if ($ord['discount_amount'] > 0): ?>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.15rem;">
                                        Sub: ₹<?= number_format($ord['total_amount'], 2) ?><br>
                                        Disc: -₹<?= number_format($ord['discount_amount'], 2) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.5rem 0.75rem;">
                                <?php
                                $m_bg = 'rgba(255, 107, 53, 0.12)';
                                $m_color = 'var(--accent-color)';
                                if ($ord['payment_method'] === 'Cash') {
                                    $m_bg = 'rgba(46, 213, 115, 0.12)';
                                    $m_color = 'var(--success)';
                                } else if ($ord['payment_method'] === 'UPI') {
                                    $m_bg = 'rgba(30, 144, 255, 0.12)';
                                    $m_color = 'var(--info)';
                                } else if ($ord['payment_method'] === 'Split') {
                                    $m_bg = 'rgba(155, 89, 182, 0.15)';
                                    $m_color = '#9b59b6';
                                }
                                ?>
                                <span class="badge"
                                    style="background: <?= $m_bg ?>; color: <?= $m_color ?>; font-weight: 600; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;">
                                    <?= h($ord['payment_method']) ?>
                                </span>
                            </td>
                            <td style="padding: 0.5rem 0.75rem; text-align: right;">
                                <div style="display: flex; gap: 0.3rem; justify-content: flex-end;">
                                    <a href="billing-invoice.php?id=<?= $ord['id'] ?>" class="btn btn-secondary"
                                        style="padding: 0.3rem 0.45rem; font-size: 0.7rem; height: 28px; display: inline-flex; align-items: center; gap: 0.25rem;" title="View Thermal Receipt">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <button type="button" onclick="shareWhatsAppInvoice('<?= $ord['id'] ?>', '<?= h(addslashes($ord['invoice_number'])) ?>', '<?= h(addslashes($ord['customer_name'] ?? '')) ?>', '<?= h(addslashes($ord['customer_phone'] ?? '')) ?>', <?= (float)$ord['final_amount'] ?>, '<?= date('d M Y', strtotime($ord['created_at'])) ?>')" class="btn btn-success"
                                        style="padding: 0.3rem 0.45rem; font-size: 0.7rem; height: 28px; display: inline-flex; align-items: center; gap: 0.25rem; background: #25d366; border-color: #25d366; color: #ffffff;" title="Share Invoice on WhatsApp">
                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                    </button>
                                    <?php if (has_permission('billing_update')): ?>
                                    <a href="edit-billing-invoice.php?id=<?= $ord['id'] ?>" class="btn btn-secondary"
                                        style="padding: 0.3rem 0.45rem; font-size: 0.7rem; height: 28px; display: inline-flex; align-items: center; gap: 0.25rem; background: rgba(255, 165, 2, 0.12); color: var(--warning); border-color: rgba(255, 165, 2, 0.15);"
                                        title="Edit Invoice">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </a>
                                    <?php endif; ?>
                                    <a href="billing-invoice.php?id=<?= $ord['id'] ?>&print=1" class="btn btn-primary"
                                        style="padding: 0.3rem 0.45rem; font-size: 0.7rem; height: 28px; display: inline-flex; align-items: center; gap: 0.25rem; background: var(--accent-gradient);"
                                        title="Print Receipt">
                                        <i class="fa-solid fa-print"></i> Print
                                    </a>
                                    <a href="?action=delete&id=<?= $ord['id'] ?>" class="btn btn-danger"
                                        style="padding: 0.3rem 0.45rem; font-size: 0.7rem; height: 28px; display: inline-flex; align-items: center; gap: 0.25rem; background: rgba(255, 71, 87, 0.1); border-color: rgba(255, 71, 87, 0.15); color: var(--danger);"
                                        onclick="return confirm('Are you sure you want to delete invoice <?= $ord['invoice_number'] ?>? This action is permanent.');"
                                        title="Delete Invoice">
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
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;

        const rows = document.querySelectorAll('.invoice-row');

        let visibleCount = 0;
        let totalSales = 0;
        let totalDiscount = 0;

        // Filter Table Rows
        rows.forEach(row => {
            const inv = row.getAttribute('data-invoice') || '';
            const name = row.getAttribute('data-name') || '';
            const phone = row.getAttribute('data-phone') || '';
            const method = row.getAttribute('data-method') || '';
            const date = row.getAttribute('data-date') || '';

            const matchesSearch = !searchVal || inv.includes(searchVal) || name.includes(searchVal) || phone.includes(searchVal);
            const matchesPayment = !paymentVal || method === paymentVal;

            let matchesDate = true;
            if (dateFrom && date < dateFrom) {
                matchesDate = false;
            }
            if (dateTo && date > dateTo) {
                matchesDate = false;
            }

            if (matchesSearch && matchesPayment && matchesDate) {
                row.style.display = '';
                visibleCount++;
                totalSales += parseFloat(row.getAttribute('data-amount') || 0);
                totalDiscount += parseFloat(row.getAttribute('data-discount') || 0);
            } else {
                row.style.display = 'none';
            }
        });

        // Update visible count in toolbar
        document.getElementById('visibleCount').textContent = visibleCount;

        // Update stats grid values reactively
        const avgTicket = visibleCount > 0 ? (totalSales / visibleCount) : 0;

        document.getElementById('statTotalSales').textContent = '₹' + totalSales.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('statTotalCount').textContent = visibleCount;
        document.getElementById('statAvgTicket').textContent = '₹' + avgTicket.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('statTotalDiscount').textContent = '₹' + totalDiscount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function setTodayFilter() {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('dateFrom').value = today;
        document.getElementById('dateTo').value = today;
        filterInvoices();
    }

    function clearDateFilters() {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        filterInvoices();
    }

    function shareWhatsAppInvoice(id, invNo, customerName, customerPhone, finalAmount, purchaseDate) {
        let name = customerName.trim();
        if (!name || name === 'Walk-in Customer') name = 'Valued Customer';
        
        const amount = '₹' + parseFloat(finalAmount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const basePath = window.location.pathname.includes('/admin/') 
            ? window.location.pathname.substring(0, window.location.pathname.indexOf('/admin/')) 
            : window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        const receiptUrl = window.location.origin + basePath + '/view-receipt.php?inv=' + encodeURIComponent(invNo);
        const dateStr = purchaseDate ? ` issued on *${purchaseDate}*` : '';
        
        let targetPhone = customerPhone ? customerPhone.replace(/[^0-9]/g, '') : '';
        
        if (!targetPhone) {
            const inputPhone = prompt(`Share E-Receipt ${invNo} via WhatsApp\n\nEnter 10-digit WhatsApp Phone Number:`, '');
            if (inputPhone === null) return;
            targetPhone = inputPhone.replace(/[^0-9]/g, '');
        }
        
        const messageText = `Hello *${name}* 👋,\n\nThank you for choosing *Orange Events*! 🌟\nHere is your digital receipt *${invNo}* for *${amount}*${dateStr}.\n\nView & download your E-Receipt link:\n${receiptUrl}\n\nHave a wonderful celebration! 🎉`;
        
        const encodedText = encodeURIComponent(messageText);
        let whatsappUrl = `https://api.whatsapp.com/send?text=${encodedText}`;
        
        if (targetPhone.length >= 10) {
            if (targetPhone.length === 10) {
                targetPhone = '91' + targetPhone;
            }
            whatsappUrl = `https://api.whatsapp.com/send?phone=${targetPhone}&text=${encodedText}`;
        }
        
        window.open(whatsappUrl, '_blank');
    }

    document.addEventListener('DOMContentLoaded', function() {
        filterInvoices();
    });
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>