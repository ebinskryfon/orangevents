<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// 1. Fetch dashboard statistics
// Total Events
$total_events = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();

// Total Confirmed Bookings
$confirmed_events = $db->query("SELECT COUNT(*) FROM events WHERE status = 'confirmed'")->fetchColumn();

// Pending Draft Invoices
$pending_invoices = $db->query("SELECT COUNT(*) FROM invoices WHERE status = 'draft'")->fetchColumn();

// Total Revenue (Finalized & Paid Invoices)
$total_revenue = $db->query("SELECT SUM(final_total) FROM invoices WHERE status != 'draft'")->fetchColumn() ?? 0.00;

// 2. Setup Calendar Month and Year
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Wrap around for month navigation
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day_of_month);
$day_of_week = date('w', $first_day_of_month); // 0 (Sunday) to 6 (Saturday)
$month_name = date('F', $first_day_of_month);

// Fetch events for this specific month/year
$start_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$end_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-$days_in_month";

$stmt = $db->prepare("SELECT id, title, event_date, event_time, venue, status FROM events WHERE event_date BETWEEN :start_date AND :end_date ORDER BY event_time ASC");
$stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$monthly_events = $stmt->fetchAll();

// Group events by day number
$events_by_day = [];
foreach ($monthly_events as $ev) {
    $day_num = (int)date('d', strtotime($ev['event_date']));
    $events_by_day[$day_num][] = $ev;
}

// 3. Fetch upcoming events (next 5) for the table
$stmt_upcoming = $db->prepare("
    SELECT e.id, e.title, e.client_name, e.client_phone, e.event_date, e.venue, e.status,
           (SELECT COUNT(*) FROM invoices WHERE event_id = e.id) as has_invoice
    FROM events e 
    WHERE e.event_date >= :today 
    ORDER BY e.event_date ASC, e.event_time ASC 
    LIMIT 5
");
$stmt_upcoming->execute(['today' => date('Y-m-d')]);
$upcoming_events = $stmt_upcoming->fetchAll();
?>

<!-- Header Section -->
<div class="content-header">
    <div class="header-title">
        <h1>Dashboard</h1>
        <p>Overview of scheduling, bookings, and catering revenues.</p>
    </div>
    <div class="header-actions">
        <a href="event-form.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> New Booking
        </a>
    </div>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] === 'event_deleted'): ?>
    <div style="background-color: #22c55e; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid fa-circle-check"></i> Booking deleted successfully.</span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'has_invoice'): ?>
    <div style="background-color: #ef4444; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid fa-triangle-exclamation"></i> Cannot delete booking. This event has an active invoice. Please delete the invoice first.</span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'delete_failed'): ?>
    <div style="background-color: #ef4444; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
        <span><i class="fa-solid fa-triangle-exclamation"></i> Failed to delete booking due to a database error.</span>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- Stats Counter Grid -->
<div class="stats-grid">
    <div class="card stat-card">
        <div class="stat-icon"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="stat-info">
            <h3><?= $total_events ?></h3>
            <p>Total Bookings</p>
        </div>
    </div>
    
    <div class="card stat-card green">
        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <h3><?= $confirmed_events ?></h3>
            <p>Confirmed Events</p>
        </div>
    </div>
    
    <div class="card stat-card blue">
        <div class="stat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div class="stat-info">
            <h3><?= $pending_invoices ?></h3>
            <p>Pending Invoices</p>
        </div>
    </div>
    
    <div class="card stat-card purple">
        <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
        <div class="stat-info">
            <h3><?= format_price($total_revenue, false) ?></h3>
            <p>Total Revenue (Rs)</p>
        </div>
    </div>
</div>

<!-- Dashboard Grid (Calendar + Upcoming Events) -->
<div class="dashboard-grid">
    <!-- Left Column: Booking Calendar -->
    <div class="card" style="padding: 1.5rem;">
        <div class="calendar-header">
            <h2><i class="fa-solid fa-calendar-days" style="color: var(--accent-color); margin-right: 0.5rem;"></i> Booking Calendar</h2>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>" class="btn btn-secondary" style="padding: 0.5rem 0.85rem;">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <span style="font-weight: 600; font-size: 1.1rem; min-width: 150px; text-align: center;">
                    <?= $month_name ?> <?= $year ?>
                </span>
                <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>" class="btn btn-secondary" style="padding: 0.5rem 0.85rem;">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>
        </div>
        
        <div class="calendar-widget">
            <div class="calendar-grid">
                <!-- Day Names -->
                <div class="day-name">Sun</div>
                <div class="day-name">Mon</div>
                <div class="day-name">Tue</div>
                <div class="day-name">Wed</div>
                <div class="day-name">Thu</div>
                <div class="day-name">Fri</div>
                <div class="day-name">Sat</div>
                
                <!-- Blank days before first day of month -->
                <?php for ($i = 0; $i < $day_of_week; $i++): ?>
                    <div class="calendar-day empty"></div>
                <?php endfor; ?>
                
                <!-- Days of the month -->
                <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
                    <?php 
                    $is_today = ($d == (int)date('d') && $month == (int)date('m') && $year == (int)date('Y'));
                    $cell_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($d, 2, '0', STR_PAD_LEFT);
                    ?>
                    <div class="calendar-day <?= $is_today ? 'today' : '' ?>" onclick="location.href='event-form.php?date=<?= $cell_date ?>'">
                        <div class="day-number"><?= $d ?></div>
                        <div class="day-events">
                            <?php if (isset($events_by_day[$d])): ?>
                                <?php foreach ($events_by_day[$d] as $ev): ?>
                                    <div class="event-bubble <?= h($ev['status']) ?>" title="<?= h($ev['title']) ?> at <?= h($ev['venue']) ?>">
                                        <?= h(substr($ev['title'], 0, 10)) ?>...
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Quick Upcoming Bookings List -->
    <div class="card">
        <h2 class="card-title" style="margin-bottom: 1.5rem;">
            <span><i class="fa-solid fa-list-check" style="color: var(--accent-color); margin-right: 0.5rem;"></i> Upcoming Events</span>
        </h2>
        
        <?php if (empty($upcoming_events)): ?>
            <div style="text-align: center; padding: 3rem 0; color: var(--text-secondary);">
                <i class="fa-regular fa-calendar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.4;"></i>
                <p>No upcoming events scheduled.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php foreach ($upcoming_events as $event): ?>
                    <div style="background-color: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1rem; display: flex; flex-direction: column; gap: 0.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <h4 style="font-size: 1rem;"><?= h($event['title']) ?></h4>
                            <span class="badge badge-<?= h($event['status']) ?>"><?= h($event['status']) ?></span>
                        </div>
                        <p style="font-size: 0.8rem; color: var(--text-secondary);">
                            <i class="fa-solid fa-location-dot" style="margin-right: 0.25rem;"></i> <?= h($event['venue']) ?>
                        </p>
                        <p style="font-size: 0.8rem; color: var(--text-secondary);">
                            <i class="fa-solid fa-calendar-day" style="margin-right: 0.25rem;"></i> <?= format_date($event['event_date']) ?>
                        </p>
                        <p style="font-size: 0.8rem; color: var(--text-secondary);">
                            <i class="fa-solid fa-user" style="margin-right: 0.25rem;"></i> <?= h($event['client_name']) ?> (<?= h($event['client_phone']) ?>)
                        </p>
                        <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center;">
                            <a href="event-form.php?id=<?= $event['id'] ?>" class="btn btn-secondary" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;">
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </a>
                            <a href="view-invoice.php?event_id=<?= $event['id'] ?>" class="btn btn-primary" style="padding: 0.3rem 0.6rem; font-size: 0.75rem; background: var(--accent-gradient);">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Menu & Quote
                            </a>
                            <?php if ($event['has_invoice'] > 0): ?>
                                <button type="button" class="btn btn-danger" style="padding: 0.3rem 0.6rem; font-size: 0.75rem; background-color: #ef4444; border-color: #ef4444; opacity: 0.6;" onclick="alert('Cannot delete booking. This event has an active invoice. Please delete the invoice first.');">
                                    <i class="fa-solid fa-trash-can"></i> Delete
                                </button>
                            <?php else: ?>
                                <form action="delete-event.php" method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Are you sure you want to delete this event/booking? This action cannot be undone.');">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 0.3rem 0.6rem; font-size: 0.75rem; background-color: #ef4444; border-color: #ef4444;">
                                        <i class="fa-solid fa-trash-can"></i> Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
