<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();

// 1. Fetch dashboard statistics
$total_events = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
$confirmed_events = $db->query("SELECT COUNT(*) FROM events WHERE status = 'confirmed'")->fetchColumn();
$pending_invoices = $db->query("SELECT COUNT(*) FROM invoices WHERE status = 'draft'")->fetchColumn();
$total_revenue = $db->query("SELECT SUM(final_total) FROM invoices WHERE status != 'draft'")->fetchColumn() ?? 0.00;

// 2. Setup Calendar Month and Year
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day_of_month);
$day_of_week = date('w', $first_day_of_month);
$month_name = date('F', $first_day_of_month);

$start_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$end_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-$days_in_month";

$stmt = $db->prepare("SELECT id, title, event_date, event_time, venue, status FROM events WHERE event_date BETWEEN :start_date AND :end_date ORDER BY event_time ASC");
$stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$monthly_events = $stmt->fetchAll();

$events_by_day = [];
foreach ($monthly_events as $ev) {
    $day_num = (int)date('d', strtotime($ev['event_date']));
    $events_by_day[$day_num][] = $ev;
}

// 3. Fetch upcoming events (next 5)
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

<!-- Compact POS-Style Header -->
<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0; display: flex; justify-content: space-between; align-items: flex-start;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-calendar-check" style="color:var(--accent-color);"></i>
            Event Catering & Stage Decor Manager
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            Overview of client bookings, catering schedules, stage setups, and revenue.
        </p>
    </div>
    <div>
        <a href="event-form.php" class="btn btn-primary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <i class="fa-solid fa-plus"></i> New Event Booking
        </a>
    </div>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] === 'event_deleted'): ?>
    <div class="alert alert-success" style="font-size:0.85rem; padding:0.6rem 1rem; margin-bottom:1rem;">
        <i class="fa-solid fa-circle-check"></i> Booking deleted successfully.
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'has_invoice'): ?>
    <div class="alert alert-danger" style="font-size:0.85rem; padding:0.6rem 1rem; margin-bottom:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i> Cannot delete booking. Active invoice exists.
    </div>
<?php endif; ?>

<!-- Compact POS Stat Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:0.75rem; margin-bottom:1rem;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(255, 107, 53, 0.1); color:var(--accent-color); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Total Bookings</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary);"><?= number_format($total_events) ?> Events</div>
        </div>
    </div>

    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(46, 213, 115, 0.1); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Confirmed Events</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--success);"><?= number_format($confirmed_events) ?> Confirmed</div>
        </div>
    </div>

    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(52, 152, 219, 0.1); color:#3498db; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-file-invoice-dollar"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Draft Invoices</div>
            <div style="font-size:1.1rem; font-weight:800; color:#3498db;"><?= number_format($pending_invoices) ?> Pending</div>
        </div>
    </div>

    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(155, 89, 182, 0.1); color:#9b59b6; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Event Revenue</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary);">₹<?= number_format($total_revenue, 2) ?></div>
        </div>
    </div>
</div>

<!-- Dashboard Grid (Calendar + Upcoming Events) -->
<div class="dashboard-grid" style="display:grid; grid-template-columns: 2fr 1fr; gap:1rem;">
    <!-- Left Column: Booking Calendar -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; box-shadow:var(--box-shadow);">
        <div class="calendar-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
            <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-calendar-days" style="color:var(--accent-color);"></i> Booking Calendar
            </h2>
            <div style="display: flex; gap: 0.35rem; align-items: center;">
                <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>" class="btn btn-secondary" style="height:28px; font-size:0.75rem; padding:0 0.5rem;">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <span style="font-weight: 700; font-size: 0.85rem; min-width: 120px; text-align: center; color:var(--text-primary);">
                    <?= $month_name ?> <?= $year ?>
                </span>
                <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>" class="btn btn-secondary" style="height:28px; font-size:0.75rem; padding:0 0.5rem;">
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
                
                <!-- Blank days -->
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
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:1rem; box-shadow:var(--box-shadow);">
        <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 0.75rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.4rem; display:flex; align-items:center; gap:0.4rem;">
            <i class="fa-solid fa-list-check" style="color:var(--accent-color);"></i> Upcoming Events
        </h2>
        
        <?php if (empty($upcoming_events)): ?>
            <div style="text-align: center; padding: 2rem 0; color: var(--text-secondary); font-size:0.8rem;">
                <i class="fa-regular fa-calendar" style="font-size: 2rem; margin-bottom: 0.5rem; display:block; opacity: 0.4;"></i>
                <p style="margin:0;">No upcoming events scheduled.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                <?php foreach ($upcoming_events as $event): ?>
                    <div style="background:var(--bg-control); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.6rem; display: flex; flex-direction: column; gap: 0.2rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <h4 style="font-size: 0.85rem; font-weight:700; margin:0; color:var(--text-primary);"><?= h($event['title']) ?></h4>
                            <span class="badge" style="background:rgba(255,107,53,0.1); color:var(--accent-color); font-size:0.65rem; padding:1px 6px; border-radius:8px; text-transform:uppercase; font-weight:700;"><?= h($event['status']) ?></span>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            <i class="fa-solid fa-location-dot" style="font-size:0.7rem; color:var(--accent-color);"></i> <?= h($event['venue']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            <i class="fa-solid fa-calendar-day" style="font-size:0.7rem;"></i> <?= format_date($event['event_date']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            <i class="fa-solid fa-user" style="font-size:0.7rem;"></i> <?= h($event['client_name']) ?> (<?= h($event['client_phone']) ?>)
                        </div>
                        <div style="margin-top: 0.35rem; display: flex; gap: 0.3rem; align-items: center;">
                            <a href="event-form.php?id=<?= $event['id'] ?>" class="btn btn-secondary" style="padding: 0.2rem 0.45rem; font-size: 0.7rem;">
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </a>
                            <a href="view-invoice.php?event_id=<?= $event['id'] ?>" class="btn btn-primary" style="padding: 0.2rem 0.45rem; font-size: 0.7rem;">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Menu & Quote
                            </a>
                            <?php if ($event['has_invoice'] > 0): ?>
                                <button type="button" class="btn btn-secondary" style="padding: 0.2rem 0.45rem; font-size: 0.7rem; opacity:0.6;" onclick="alert('Cannot delete booking. This event has an active invoice. Please delete the invoice first.');">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            <?php else: ?>
                                <form action="delete-event.php" method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Are you sure you want to delete this event/booking?');">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 0.2rem 0.45rem; font-size: 0.7rem;">
                                        <i class="fa-solid fa-trash-can"></i>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
