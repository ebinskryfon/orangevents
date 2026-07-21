<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// Permission check
if (!has_permission('billing_read') && !has_permission('user_manage') && $_SESSION['admin_role'] !== 'admin' && $_SESSION['admin_role'] !== 'manager') {
    echo '<div class="alert alert-danger" style="margin:2rem;">Access Denied. You do not have permission to view sales analytics.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// 1. Determine Date Range Filter
$preset = $_GET['preset'] ?? 'this_month';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$today = date('Y-m-d');

if (!empty($start_date) && !empty($end_date)) {
    $preset = 'custom';
} else {
    switch ($preset) {
        case 'today':
            $start_date = $today;
            $end_date = $today;
            break;
        case 'last_7_days':
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $end_date = $today;
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
        case 'last_30_days':
            $start_date = date('Y-m-d', strtotime('-29 days'));
            $end_date = $today;
            break;
        case 'this_year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
        case 'all_time':
            $start_date = '2000-01-01';
            $end_date = '2099-12-31';
            break;
        default:
            $preset = 'this_month';
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
    }
}

$start_timestamp = $start_date . ' 00:00:00';
$end_timestamp = $end_date . ' 23:59:59';

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . $start_date . '_to_' . $end_date . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Source Module', 'Reference / Invoice #', 'Customer Name', 'Customer Phone', 'Payment Method', 'Date & Time', 'Total Amount']);
    
    // Fetch POS Orders
    $stmt = $db->prepare("SELECT 'POS Billing' as source, invoice_number, customer_name, customer_phone, payment_method, created_at, final_amount as amount FROM billing_orders WHERE created_at BETWEEN :start AND :end");
    $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['source'], $row['invoice_number'], $row['customer_name'] ?? 'N/A', $row['customer_phone'] ?? 'N/A', $row['payment_method'], $row['created_at'], $row['amount']]);
    }
    
    // Fetch Event Invoices
    $stmt = $db->prepare("SELECT 'Event Booking' as source, i.invoice_number, e.client_name, e.client_phone, i.payment_method, i.created_at, i.final_total as amount FROM invoices i JOIN events e ON i.event_id = e.id WHERE i.created_at BETWEEN :start AND :end AND i.status != 'draft'");
    $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['source'], $row['invoice_number'], $row['client_name'] ?? 'N/A', $row['client_phone'] ?? 'N/A', $row['payment_method'] ?? 'N/A', $row['created_at'], $row['amount']]);
    }

    // Fetch Rental Orders if table exists
    try {
        $stmt = $db->prepare("SELECT 'Rental Order' as source, order_number as invoice_number, customer_name, customer_phone, payment_method, created_at, net_amount as amount FROM rental_orders WHERE created_at BETWEEN :start AND :end");
        $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [$row['source'], $row['invoice_number'], $row['customer_name'] ?? 'N/A', $row['customer_phone'] ?? 'N/A', $row['payment_method'] ?? 'N/A', $row['created_at'], $row['amount']]);
        }
    } catch (Exception $e) {
        // Table might not exist in some installs
    }
    
    fclose($output);
    exit;
}

// 2. Fetch Aggregate KPI Metrics
// POS Revenue
$stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM billing_orders WHERE created_at BETWEEN :start AND :end");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$pos_stats = $stmt->fetch();
$pos_count = (int)$pos_stats['count'];
$pos_total = (float)$pos_stats['total'];

// Event Revenue
$stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_total), 0) as total FROM invoices WHERE status != 'draft' AND created_at BETWEEN :start AND :end");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$event_stats = $stmt->fetch();
$event_count = (int)$event_stats['count'];
$event_total = (float)$event_stats['total'];

// Rental Revenue
$rental_count = 0;
$rental_total = 0.00;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(net_amount), 0) as total FROM rental_orders WHERE created_at BETWEEN :start AND :end");
    $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
    $rental_stats = $stmt->fetch();
    $rental_count = (int)$rental_stats['count'];
    $rental_total = (float)$rental_stats['total'];
} catch (Exception $e) {
    // Ignore if table missing
}

// POS Returns Total
$returns_total = 0.00;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(refund_amount), 0) as total FROM billing_returns WHERE created_at BETWEEN :start AND :end");
    $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
    $returns_total = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$grand_total = $pos_total + $event_total + $rental_total - $returns_total;
$total_orders = $pos_count + $event_count + $rental_count;
$avg_order_value = $total_orders > 0 ? ($grand_total / $total_orders) : 0.00;

// 3. Daily Sales Chart Data Breakdown
$stmt = $db->prepare("
    SELECT DATE(created_at) as sale_date, SUM(final_amount) as amount 
    FROM billing_orders 
    WHERE created_at BETWEEN :start AND :end 
    GROUP BY DATE(created_at) 
    ORDER BY sale_date ASC
");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$pos_daily = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $db->prepare("
    SELECT DATE(created_at) as sale_date, SUM(final_total) as amount 
    FROM invoices 
    WHERE status != 'draft' AND created_at BETWEEN :start AND :end 
    GROUP BY DATE(created_at) 
    ORDER BY sale_date ASC
");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$event_daily = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Prepare date sequence for chart
$chart_labels = [];
$chart_pos_data = [];
$chart_event_data = [];
$chart_total_data = [];

$current = strtotime($start_date);
$end_ts = strtotime($end_date);

// Limit points if all_time or range > 90 days
if ($preset === 'all_time' || ($end_ts - $current) > (90 * 86400)) {
    // Monthly grouping
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as m_date, SUM(final_amount) as amount 
        FROM billing_orders WHERE created_at BETWEEN :start AND :end GROUP BY m_date ORDER BY m_date ASC
    ");
    $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
    $pos_monthly = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as m_date, SUM(final_total) as amount 
        FROM invoices WHERE status != 'draft' AND created_at BETWEEN :start AND :end GROUP BY m_date ORDER BY m_date ASC
    ");
    $stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
    $event_monthly = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $all_months = array_unique(array_merge(array_keys($pos_monthly), array_keys($event_monthly)));
    sort($all_months);

    foreach ($all_months as $m) {
        $chart_labels[] = date('M Y', strtotime($m . '-01'));
        $p = (float)($pos_monthly[$m] ?? 0);
        $e = (float)($event_monthly[$m] ?? 0);
        $chart_pos_data[] = $p;
        $chart_event_data[] = $e;
        $chart_total_data[] = $p + $e;
    }
} else {
    // Daily grouping
    while ($current <= $end_ts) {
        $d_str = date('Y-m-d', $current);
        $chart_labels[] = date('d M', $current);
        $p = (float)($pos_daily[$d_str] ?? 0);
        $e = (float)($event_daily[$d_str] ?? 0);
        $chart_pos_data[] = $p;
        $chart_event_data[] = $e;
        $chart_total_data[] = $p + $e;
        $current = strtotime('+1 day', $current);
    }
}

// 4. Payment Method Distribution
$stmt = $db->prepare("
    SELECT payment_method, COUNT(*) as count, SUM(final_amount) as total 
    FROM billing_orders 
    WHERE created_at BETWEEN :start AND :end 
    GROUP BY payment_method
");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$pos_pm = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pm_totals = ['Cash' => 0.00, 'UPI' => 0.00, 'Card' => 0.00, 'Other' => 0.00];
foreach ($pos_pm as $pm) {
    $method = trim($pm['payment_method']);
    if (strripos($method, 'cash') !== false) {
        $pm_totals['Cash'] += (float)$pm['total'];
    } elseif (strripos($method, 'upi') !== false || strripos($method, 'online') !== false || strripos($method, 'gpay') !== false) {
        $pm_totals['UPI'] += (float)$pm['total'];
    } elseif (strripos($method, 'card') !== false) {
        $pm_totals['Card'] += (float)$pm['total'];
    } else {
        $pm_totals['Other'] += (float)$pm['total'];
    }
}

// 5. Top 10 Best-Selling POS Products
$stmt = $db->prepare("
    SELECT 
        boi.product_name,
        bc.category_name,
        SUM(boi.quantity) as total_qty,
        SUM(boi.total_price) as total_revenue,
        AVG(boi.price) as avg_price
    FROM billing_order_items boi
    JOIN billing_orders bo ON boi.order_id = bo.id
    LEFT JOIN billing_products bp ON boi.product_id = bp.id
    LEFT JOIN billing_categories bc ON bp.category_id = bc.id
    WHERE bo.created_at BETWEEN :start AND :end
    GROUP BY boi.product_id, boi.product_name
    ORDER BY total_revenue DESC
    LIMIT 10
");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Revenue by Product Category
$stmt = $db->prepare("
    SELECT 
        COALESCE(bc.category_name, 'Uncategorized') as category_name,
        SUM(boi.total_price) as category_total
    FROM billing_order_items boi
    JOIN billing_orders bo ON boi.order_id = bo.id
    LEFT JOIN billing_products bp ON boi.product_id = bp.id
    LEFT JOIN billing_categories bc ON bp.category_id = bc.id
    WHERE bo.created_at BETWEEN :start AND :end
    GROUP BY bc.id, category_name
    ORDER BY category_total DESC
");
$stmt->execute(['start' => $start_timestamp, 'end' => $end_timestamp]);
$category_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .analytics-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 1.25rem;
        box-shadow: var(--box-shadow);
        transition: transform var(--transition-fast), border-color var(--transition-fast);
    }
    .analytics-card:hover {
        border-color: var(--border-highlight);
    }
    .kpi-title {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }
    .kpi-value {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
    }
    .kpi-subtext {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.35rem;
    }
    .preset-btn {
        padding: 0.35rem 0.75rem;
        font-size: 0.75rem;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        background: var(--bg-btn-secondary);
        color: var(--text-primary);
        cursor: pointer;
        text-decoration: none;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .preset-btn:hover, .preset-btn.active {
        background: var(--accent-color);
        color: #fff;
        border-color: var(--accent-color);
    }
    .chart-container {
        position: relative;
        width: 100%;
        height: 280px;
    }
</style>

<!-- Header & Date Controls -->
<div class="content-header" style="margin-bottom: 1.25rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.5rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-chart-line" style="color:var(--accent-color);"></i>
            Business Analytics & Revenue Intelligence
        </h1>
        <p style="color:var(--text-secondary); margin:0.2rem 0 0; font-size:0.8rem;">
            Real-time sales performance, top catalog items, payment distributions & financial growth tracking.
        </p>
    </div>
    
    <div style="display:flex; gap:0.5rem; align-items:center;">
        <a href="analytics.php?export=csv&preset=<?= urlencode($preset) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="btn btn-secondary" style="height:34px; font-size:0.78rem; display:inline-flex; align-items:center; gap:0.35rem;" title="Download CSV Report">
            <i class="fa-solid fa-file-csv" style="color:#2ed573;"></i> Export CSV
        </a>
        <button onclick="window.print()" class="btn btn-secondary" style="height:34px; font-size:0.78rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- Date Filter Bar -->
<div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-md); padding:0.75rem 1rem; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
    <!-- Preset Pills -->
    <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
        <span style="font-size:0.75rem; font-weight:700; color:var(--text-secondary); margin-right:0.3rem;"><i class="fa-regular fa-calendar"></i> Filter Range:</span>
        <a href="?preset=today" class="preset-btn <?= $preset === 'today' ? 'active' : '' ?>">Today</a>
        <a href="?preset=last_7_days" class="preset-btn <?= $preset === 'last_7_days' ? 'active' : '' ?>">Last 7 Days</a>
        <a href="?preset=this_month" class="preset-btn <?= $preset === 'this_month' ? 'active' : '' ?>">This Month</a>
        <a href="?preset=last_30_days" class="preset-btn <?= $preset === 'last_30_days' ? 'active' : '' ?>">Last 30 Days</a>
        <a href="?preset=this_year" class="preset-btn <?= $preset === 'this_year' ? 'active' : '' ?>">This Year</a>
        <a href="?preset=all_time" class="preset-btn <?= $preset === 'all_time' ? 'active' : '' ?>">All Time</a>
    </div>

    <!-- Custom Date Range Form -->
    <form method="GET" style="display:flex; align-items:center; gap:0.4rem; margin:0;">
        <input type="date" name="start_date" value="<?= h($start_date) ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); padding:0.3rem 0.5rem; border-radius:6px; font-size:0.75rem;">
        <span style="font-size:0.75rem; color:var(--text-muted);">to</span>
        <input type="date" name="end_date" value="<?= h($end_date) ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); padding:0.3rem 0.5rem; border-radius:6px; font-size:0.75rem;">
        <button type="submit" class="btn btn-primary" style="height:28px; font-size:0.75rem; padding:0 0.6rem; border-radius:6px;">Apply</button>
    </form>
</div>

<!-- KPI Cards Overview Row -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <!-- 1. Total Revenue -->
    <div class="analytics-card" style="border-left:4px solid var(--accent-color);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <div class="kpi-title">Total Gross Revenue</div>
                <div class="kpi-value" style="color:var(--accent-color);">₹<?= number_format($grand_total, 2) ?></div>
            </div>
            <div style="width:36px; height:36px; border-radius:8px; background:rgba(255, 107, 53, 0.12); color:var(--accent-color); display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                <i class="fa-solid fa-wallet"></i>
            </div>
        </div>
        <div class="kpi-subtext">Combined across POS, Events & Rentals</div>
    </div>

    <!-- 2. POS Billing Revenue -->
    <div class="analytics-card" style="border-left:4px solid var(--success);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <div class="kpi-title">POS Store Sales</div>
                <div class="kpi-value" style="color:var(--success);">₹<?= number_format($pos_total, 2) ?></div>
            </div>
            <div style="width:36px; height:36px; border-radius:8px; background:rgba(46, 213, 115, 0.12); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                <i class="fa-solid fa-cash-register"></i>
            </div>
        </div>
        <div class="kpi-subtext"><?= number_format($pos_count) ?> over-the-counter orders</div>
    </div>

    <!-- 3. Event Bookings Revenue -->
    <div class="analytics-card" style="border-left:4px solid #3498db;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <div class="kpi-title">Event Invoices</div>
                <div class="kpi-value" style="color:#3498db;">₹<?= number_format($event_total, 2) ?></div>
            </div>
            <div style="width:36px; height:36px; border-radius:8px; background:rgba(52, 152, 219, 0.12); color:#3498db; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
        </div>
        <div class="kpi-subtext"><?= number_format($event_count) ?> finalized event bookings</div>
    </div>

    <!-- 4. Average Ticket / Order Value -->
    <div class="analytics-card" style="border-left:4px solid #9b59b6;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <div class="kpi-title">Average Order Value</div>
                <div class="kpi-value" style="color:#9b59b6;">₹<?= number_format($avg_order_value, 2) ?></div>
            </div>
            <div style="width:36px; height:36px; border-radius:8px; background:rgba(155, 89, 182, 0.12); color:#9b59b6; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                <i class="fa-solid fa-calculator"></i>
            </div>
        </div>
        <div class="kpi-subtext">Across <?= number_format($total_orders) ?> total sales transactions</div>
    </div>
</div>

<!-- Charts Section: Main Revenue Trend + Category & Payment Breakdown -->
<div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.25rem; margin-bottom:1.5rem;">
    <!-- Main Revenue Trend Line/Bar Chart -->
    <div class="analytics-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem;">
            <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-chart-area" style="color:var(--accent-color);"></i> Revenue Growth & Sales Trend
            </h2>
            <span style="font-size:0.75rem; color:var(--text-muted);"><?= h($start_date) ?> to <?= h($end_date) ?></span>
        </div>
        <div class="chart-container">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </div>

    <!-- Payment Methods & Category Breakdown Stack -->
    <div style="display:flex; flex-direction:column; gap:1.25rem;">
        <!-- Payment Methods Donut Chart -->
        <div class="analytics-card" style="flex:1;">
            <h2 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 0.75rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.4rem; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-pie-chart" style="color:var(--success);"></i> Payment Methods Breakdown
            </h2>
            <div style="height:180px; position:relative;">
                <canvas id="paymentMethodChart"></canvas>
            </div>
        </div>

        <!-- Returns & Refunds Card -->
        <div class="analytics-card" style="padding:0.85rem 1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:0.72rem; color:var(--text-secondary); text-transform:uppercase; font-weight:600;">POS Returns & Refunds</div>
                    <div style="font-size:1.1rem; font-weight:800; color:var(--danger);">₹<?= number_format($returns_total, 2) ?></div>
                </div>
                <div style="width:32px; height:32px; border-radius:6px; background:rgba(255,71,87,0.12); color:var(--danger); display:flex; align-items:center; justify-content:center;">
                    <i class="fa-solid fa-rotate-left"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Section: Top Products Table + Category Chart -->
<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap:1.25rem;">
    <!-- Top Selling Products Table -->
    <div class="analytics-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.85rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem;">
            <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-trophy" style="color:#ffa502;"></i> Top Selling Products
            </h2>
            <a href="billing-products.php" style="font-size:0.75rem; color:var(--accent-color); text-decoration:none;">View Catalog &rarr;</a>
        </div>

        <?php if (empty($top_products)): ?>
            <div style="text-align:center; padding:2rem 0; color:var(--text-secondary); font-size:0.85rem;">
                No product sales recorded in this time range.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem; text-align:left;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border-color); color:var(--text-secondary); font-size:0.72rem; text-transform:uppercase;">
                            <th style="padding:0.5rem;">Product Name</th>
                            <th style="padding:0.5rem;">Category</th>
                            <th style="padding:0.5rem; text-align:center;">Qty Sold</th>
                            <th style="padding:0.5rem; text-align:right;">Avg Unit Price</th>
                            <th style="padding:0.5rem; text-align:right;">Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_products as $idx => $prod): ?>
                            <tr style="border-bottom:1px solid var(--border-color);">
                                <td style="padding:0.6rem 0.5rem; font-weight:600; color:var(--text-primary);">
                                    <span style="display:inline-block; width:20px; height:20px; border-radius:50%; background:rgba(255,107,53,0.12); color:var(--accent-color); text-align:center; font-size:0.7rem; line-height:20px; margin-right:0.3rem;">
                                        <?= $idx + 1 ?>
                                    </span>
                                    <?= h($prod['product_name']) ?>
                                </td>
                                <td style="padding:0.6rem 0.5rem; color:var(--text-secondary);"><?= h($prod['category_name'] ?? 'General') ?></td>
                                <td style="padding:0.6rem 0.5rem; text-align:center; font-weight:700; color:var(--text-primary);"><?= number_format($prod['total_qty']) ?></td>
                                <td style="padding:0.6rem 0.5rem; text-align:right; color:var(--text-secondary);">₹<?= number_format($prod['avg_price'], 2) ?></td>
                                <td style="padding:0.6rem 0.5rem; text-align:right; font-weight:800; color:var(--success);">₹<?= number_format($prod['total_revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Category Sales Bar Chart / Summary -->
    <div class="analytics-card">
        <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 0.85rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
            <i class="fa-solid fa-tags" style="color:#1e90ff;"></i> Category Performance
        </h2>
        <div class="chart-container" style="height:230px;">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    const textColor = isDark ? '#a4b0be' : '#57606f';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)';

    // 1. Revenue Trend Line Chart
    const ctxTrend = document.getElementById('revenueTrendChart').getContext('2d');
    new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'POS Sales (₹)',
                    data: <?= json_encode($chart_pos_data) ?>,
                    borderColor: '#2ed573',
                    backgroundColor: 'rgba(46, 213, 115, 0.1)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: 'Event Revenue (₹)',
                    data: <?= json_encode($chart_event_data) ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: 'Total Revenue (₹)',
                    data: <?= json_encode($chart_total_data) ?>,
                    borderColor: '#ff6b35',
                    backgroundColor: 'rgba(255, 107, 53, 0.05)',
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: textColor, font: { family: 'Inter', size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ₹' + context.parsed.y.toLocaleString('en-IN', {minimumFractionDigits:2});
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: textColor, font: { size: 10 } },
                    grid: { color: gridColor }
                },
                y: {
                    ticks: {
                        color: textColor,
                        font: { size: 10 },
                        callback: function(value) { return '₹' + value; }
                    },
                    grid: { color: gridColor }
                }
            }
        }
    });

    // 2. Payment Method Donut Chart
    const ctxPM = document.getElementById('paymentMethodChart').getContext('2d');
    new Chart(ctxPM, {
        type: 'doughnut',
        data: {
            labels: ['Cash', 'UPI / Online', 'Card', 'Other'],
            datasets: [{
                data: [
                    <?= (float)$pm_totals['Cash'] ?>,
                    <?= (float)$pm_totals['UPI'] ?>,
                    <?= (float)$pm_totals['Card'] ?>,
                    <?= (float)$pm_totals['Other'] ?>
                ],
                backgroundColor: ['#2ed573', '#1e90ff', '#9b59b6', '#747d8c'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: textColor, font: { family: 'Inter', size: 10 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const val = context.parsed;
                            return ' ' + context.label + ': ₹' + val.toLocaleString('en-IN', {minimumFractionDigits:2});
                        }
                    }
                }
            },
            cutout: '68%'
        }
    });

    // 3. Category Sales Bar Chart
    const categoryLabels = <?= json_encode(array_column($category_breakdown, 'category_name')) ?>;
    const categoryData = <?= json_encode(array_map('floatval', array_column($category_breakdown, 'category_total'))) ?>;

    const ctxCat = document.getElementById('categoryChart').getContext('2d');
    new Chart(ctxCat, {
        type: 'bar',
        data: {
            labels: categoryLabels,
            datasets: [{
                label: 'Revenue (₹)',
                data: categoryData,
                backgroundColor: '#ff9f43',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    ticks: { color: textColor, font: { size: 10 } },
                    grid: { color: gridColor }
                },
                y: {
                    ticks: { color: textColor, font: { size: 10 } },
                    grid: { display: false }
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
