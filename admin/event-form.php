<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$error = '';
$success_msg = '';

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = ($event_id > 0);

// Default Form values
$event_data = [
    'title' => '',
    'client_name' => '',
    'client_phone' => '',
    'client_email' => '',
    'event_date' => $_GET['date'] ?? date('Y-m-d'),
    'event_time' => '10:00:00',
    'venue' => '',
    'status' => 'draft'
];
$selected_stage_items = []; // [stage_item_id => custom_price]
$catering_data = [
    'per_plate_price' => 250.00,
    'total_plates' => 500,
    'notes' => ''
];
$selected_dishes = []; // [dish_id, ...]
$advance_received = 0.00;
$payment_method = '';

// Load existing event data if editing
if ($is_edit) {
    // 1. Fetch Event Basic Details
    $stmt = $db->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute(['id' => $event_id]);
    $loaded_event = $stmt->fetch();
    if ($loaded_event) {
        $event_data = $loaded_event;
        
        // Fetch Invoice details (advance received & payment method)
        $stmt_inv = $db->prepare("SELECT advance_received, payment_method FROM invoices WHERE event_id = :id");
        $stmt_inv->execute(['id' => $event_id]);
        $loaded_inv = $stmt_inv->fetch();
        if ($loaded_inv) {
            $advance_received = (float)$loaded_inv['advance_received'];
            $payment_method = $loaded_inv['payment_method'] ?? '';
        }
        
        // 2. Fetch Stage Work items
        $stmt_stage = $db->prepare("SELECT stage_item_id, custom_price FROM event_stage_work WHERE event_id = :id");
        $stmt_stage->execute(['id' => $event_id]);
        while ($row = $stmt_stage->fetch()) {
            $selected_stage_items[$row['stage_item_id']] = $row['custom_price'];
        }
        
        // 3. Fetch Catering Details
        $stmt_catering = $db->prepare("SELECT * FROM event_catering WHERE event_id = :id");
        $stmt_catering->execute(['id' => $event_id]);
        $loaded_catering = $stmt_catering->fetch();
        if ($loaded_catering) {
            $catering_data = $loaded_catering;
            
            // 4. Fetch Selected Catering Dishes
            $stmt_dishes = $db->prepare("SELECT dish_id, plate_count FROM event_catering_dishes WHERE event_catering_id = :cat_id");
            $stmt_dishes->execute(['cat_id' => $loaded_catering['id']]);
            $selected_dishes = [];
            while ($row = $stmt_dishes->fetch()) {
                $selected_dishes[$row['dish_id']] = $row['plate_count'];
            }
        }
    } else {
        $is_edit = false;
        $event_id = 0;
        $error = 'Specified event booking not found.';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect Form inputs
    $event_title = trim($_POST['title'] ?? '');
    $client_name = trim($_POST['client_name'] ?? '');
    $client_phone = trim($_POST['client_phone'] ?? '');
    $client_email = trim($_POST['client_email'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $event_time = $_POST['event_time'] ?? '';
    $venue = trim($_POST['venue'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    
    // Billing / Payment fields
    $advance_received = (float)($_POST['advance_received'] ?? 0.00);
    $payment_method = trim($_POST['payment_method'] ?? '');
    
    // Catering fields
    $per_plate_price = (float)($_POST['per_plate_price'] ?? 0.00);
    $total_plates = (int)($_POST['total_plates'] ?? 0);
    $catering_notes = trim($_POST['catering_notes'] ?? '');
    $dishes_posted = $_POST['dishes'] ?? []; // array of dish IDs
    
    // Stage work fields
    $stage_posted = $_POST['stage_items'] ?? []; // array of selected stage item IDs
    $stage_prices = $_POST['stage_custom_prices'] ?? []; // array of item_id => custom_price
    
    // Server-side validation
    if (empty($event_title) || empty($client_name) || empty($client_phone) || empty($event_date) || empty($venue)) {
        $error = 'Please fill in all required fields (Event Title, Client Details, Date, and Venue).';
    } else {
        try {
            // Check double-booking clash on the database level
            $clash_sql = "SELECT id, title FROM events WHERE event_date = :date AND LOWER(TRIM(venue)) = LOWER(TRIM(:venue))";
            $clash_params = ['date' => $event_date, 'venue' => $venue];
            if ($is_edit) {
                $clash_sql .= " AND id != :id";
                $clash_params['id'] = $event_id;
            }
            $clash_stmt = $db->prepare($clash_sql);
            $clash_stmt->execute($clash_params);
            $clash_found = $clash_stmt->fetch();
            
            if ($clash_found) {
                $error = "Clash Alert: Venue is already booked for another event (\"{$clash_found['title']}\") on this date!";
            } else {
                // Begin Transaction to write all data atomically
                $db->beginTransaction();
                
                // 1. Insert or Update events table
                if ($is_edit) {
                    $stmt = $db->prepare("UPDATE events SET title = :title, client_name = :client_name, client_phone = :client_phone, client_email = :client_email, event_date = :event_date, event_time = :event_time, venue = :venue, status = :status WHERE id = :id");
                    $stmt->execute([
                        'title' => $event_title, 'client_name' => $client_name, 'client_phone' => $client_phone,
                        'client_email' => $client_email, 'event_date' => $event_date, 'event_time' => $event_time,
                        'venue' => $venue, 'status' => $status, 'id' => $event_id
                    ]);
                } else {
                    $stmt = $db->prepare("INSERT INTO events (title, client_name, client_phone, client_email, event_date, event_time, venue, status) VALUES (:title, :client_name, :client_phone, :client_email, :event_date, :event_time, :venue, :status)");
                    $stmt->execute([
                        'title' => $event_title, 'client_name' => $client_name, 'client_phone' => $client_phone,
                        'client_email' => $client_email, 'event_date' => $event_date, 'event_time' => $event_time,
                        'venue' => $venue, 'status' => $status
                    ]);
                    $event_id = $db->lastInsertId();
                }
                
                // 2. Insert or Update event_catering
                if ($is_edit) {
                    $stmt = $db->prepare("UPDATE event_catering SET per_plate_price = :rate, total_plates = :plates, notes = :notes WHERE event_id = :event_id");
                    $stmt->execute(['rate' => $per_plate_price, 'plates' => $total_plates, 'notes' => $catering_notes, 'event_id' => $event_id]);
                    
                    // Fetch existing event_catering ID to update mapping
                    $stmt_id = $db->prepare("SELECT id FROM event_catering WHERE event_id = :ev_id");
                    $stmt_id->execute(['ev_id' => $event_id]);
                    $catering_id = $stmt_id->fetchColumn();
                } else {
                    $stmt = $db->prepare("INSERT INTO event_catering (event_id, per_plate_price, total_plates, notes) VALUES (:event_id, :rate, :plates, :notes)");
                    $stmt->execute(['event_id' => $event_id, 'rate' => $per_plate_price, 'plates' => $total_plates, 'notes' => $catering_notes]);
                    $catering_id = $db->lastInsertId();
                }
                
                // 3. Clear and Re-insert event_catering_dishes
                $db->prepare("DELETE FROM event_catering_dishes WHERE event_catering_id = :id")->execute(['id' => $catering_id]);
                if (!empty($dishes_posted)) {
                    $stmt_dish_insert = $db->prepare("INSERT INTO event_catering_dishes (event_catering_id, dish_id, plate_count) VALUES (:cat_id, :dish_id, :plates)");
                    $dish_plates = $_POST['dish_plates'] ?? [];
                    foreach ($dishes_posted as $d_id) {
                        $p_count = (isset($dish_plates[$d_id]) && $dish_plates[$d_id] !== '') ? (int)$dish_plates[$d_id] : null;
                        $stmt_dish_insert->execute([
                            'cat_id' => $catering_id,
                            'dish_id' => (int)$d_id,
                            'plates' => $p_count
                        ]);
                    }
                }
                
                // 4. Clear and Re-insert event_stage_work
                $db->prepare("DELETE FROM event_stage_work WHERE event_id = :ev_id")->execute(['ev_id' => $event_id]);
                $stage_total = 0.00;
                if (!empty($stage_posted)) {
                    $stmt_stage_insert = $db->prepare("INSERT INTO event_stage_work (event_id, stage_item_id, custom_price) VALUES (:ev_id, :item_id, :price)");
                    foreach ($stage_posted as $item_id) {
                        $c_price = (float)($stage_prices[$item_id] ?? 0.00);
                        $stmt_stage_insert->execute(['ev_id' => $event_id, 'item_id' => (int)$item_id, 'price' => $c_price]);
                        $stage_total += $c_price;
                    }
                }
                
                // 5. Generate / Update Draft Invoice
                $catering_total = $per_plate_price * $total_plates;
                $invoice_subtotal = $catering_total + $stage_total;
                
                // Fetch existing invoice data for comparison
                $stmt_compare = $db->prepare("SELECT advance_received, advance_paid_at, advance_payment_method, status FROM invoices WHERE event_id = :ev_id");
                $stmt_compare->execute(['ev_id' => $event_id]);
                $existing_inv = $stmt_compare->fetch();
                
                $new_adv_paid_at = null;
                $new_adv_method = null;
                $new_bal_paid_at = null;
                $new_bal_method = null;
                
                if ($advance_received > 0) {
                    if ($existing_inv && (float)$existing_inv['advance_received'] == $advance_received && $existing_inv['advance_paid_at'] !== null) {
                        $new_adv_paid_at = $existing_inv['advance_paid_at'];
                        $new_adv_method = $existing_inv['advance_payment_method'] ?: ($payment_method ?: 'CASH');
                    } else {
                        $new_adv_paid_at = date('Y-m-d H:i:s');
                        $new_adv_method = $payment_method ?: 'CASH';
                    }
                }
                
                // If the invoice was already paid, ensure its balance metadata is preserved or aligned
                if ($existing_inv && $existing_inv['status'] === 'paid') {
                    $new_bal_paid_at = $existing_inv['balance_paid_at'] ?: date('Y-m-d H:i:s');
                    $new_bal_method = $existing_inv['balance_payment_method'] ?: ($payment_method ?: 'CASH');
                }

                if ($existing_inv) {
                    $stmt_inv_up = $db->prepare("UPDATE invoices SET 
                                                    subtotal = :sub, 
                                                    final_total = :final, 
                                                    advance_received = :advance, 
                                                    payment_method = :pay_method,
                                                    advance_paid_at = :adv_paid_at,
                                                    advance_payment_method = :adv_method,
                                                    balance_paid_at = :bal_paid_at,
                                                    balance_payment_method = :bal_method
                                                 WHERE event_id = :ev_id");
                    $stmt_inv_up->execute([
                        'sub' => $invoice_subtotal,
                        'final' => $invoice_subtotal,
                        'advance' => $advance_received,
                        'pay_method' => $payment_method ?: null,
                        'adv_paid_at' => $new_adv_paid_at,
                        'adv_method' => $new_adv_method,
                        'bal_paid_at' => $new_bal_paid_at,
                        'bal_method' => $new_bal_method,
                        'ev_id' => $event_id
                    ]);
                } else {
                    // Generate new unique draft invoice number
                    $inv_num = "INV-" . date('Y') . "-" . str_pad($event_id, 4, '0', STR_PAD_LEFT);
                    $stmt_inv_in = $db->prepare("INSERT INTO invoices (event_id, invoice_number, subtotal, final_total, advance_received, payment_method, status, advance_paid_at, advance_payment_method) VALUES (:ev_id, :num, :sub, :final, :advance, :pay_method, 'draft', :adv_paid_at, :adv_method)");
                    $stmt_inv_in->execute([
                        'ev_id' => $event_id,
                        'num' => $inv_num,
                        'sub' => $invoice_subtotal,
                        'final' => $invoice_subtotal,
                        'advance' => $advance_received,
                        'pay_method' => $payment_method ?: null,
                        'adv_paid_at' => $new_adv_paid_at,
                        'adv_method' => $new_adv_method
                    ]);
                }
                
                $db->commit();
                
                $success_msg = $is_edit ? "Booking details updated successfully!" : "New event booking registered successfully!";
                
                // Update local loaded values
                if (!$is_edit) {
                    header("Location: event-form.php?id=$event_id&saved=1");
                    exit;
                }
                
                // Reload newly saved items
                $selected_stage_items = [];
                $stmt_stage = $db->prepare("SELECT stage_item_id, custom_price FROM event_stage_work WHERE event_id = :id");
                $stmt_stage->execute(['id' => $event_id]);
                while ($row = $stmt_stage->fetch()) {
                    $selected_stage_items[$row['stage_item_id']] = $row['custom_price'];
                }
                $selected_dishes = [];
                $stmt_dishes = $db->prepare("SELECT dish_id, plate_count FROM event_catering_dishes WHERE event_catering_id = :cat_id");
                $stmt_dishes->execute(['cat_id' => $catering_id]);
                while ($row = $stmt_dishes->fetch()) {
                    $selected_dishes[$row['dish_id']] = $row['plate_count'];
                }
            }
        } catch (Exception $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Failed to save event data: ' . $ex->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $success_msg = "Booking created successfully!";
}

// Fetch all available stage items & catering dishes for selectors
$all_stage_items = $db->query("SELECT * FROM stage_items ORDER BY item_name ASC")->fetchAll();
$all_categories = $db->query("SELECT * FROM menu_categories ORDER BY display_order ASC")->fetchAll();
$all_dishes = $db->query("SELECT * FROM dishes ORDER BY dish_name ASC")->fetchAll();

$dishes_by_category = [];
foreach ($all_dishes as $dish) {
    $dishes_by_category[$dish['category_id']][] = $dish;
}
?>

<div class="content-header">
    <div class="header-title">
        <h1><?= $is_edit ? 'Modify Booking: ' . h($event_data['title']) : 'New Booking Event Planner' ?></h1>
        <p>Register client details, plan catering menus, customize stage decor costs, and review live calculations.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fa-solid fa-chevron-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div style="background: rgba(46, 213, 115, 0.15); color: var(--success); border: 1px solid var(--success); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
        <div><i class="fa-solid fa-circle-check"></i> <span><?= h($success_msg) ?></span></div>
        <?php if ($is_edit): ?>
            <a href="view-invoice.php?event_id=<?= $event_id ?>" class="btn btn-success" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                <i class="fa-solid fa-file-invoice-dollar"></i> View Menu & Invoice Card
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: rgba(255, 71, 87, 0.15); color: var(--danger); border: 1px solid var(--danger); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i> <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<!-- Dynamic Warning Banner for Clash -->
<div id="clashWarning" style="background: rgba(255, 165, 0, 0.15); color: var(--warning); border: 1px solid var(--warning); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem; display: none; align-items: center; gap: 0.5rem;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span id="clashMessage">Slot Clash Warning: This venue is already booked!</span>
</div>

<!-- Dynamic Warning Banner for Unsaved Draft -->
<div id="draftNotification" style="background: rgba(100, 255, 218, 0.1); color: var(--accent-color); border: 1px solid var(--accent-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem; display: none; justify-content: space-between; align-items: center;">
    <div>
        <i class="fa-solid fa-floppy-disk"></i>
        <span>An unsaved draft from <strong id="draftTime"></strong> is available.</span>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <button type="button" class="btn btn-primary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; background: var(--accent-color); color: #0a192f; border: none; font-weight: 600; cursor: pointer; border-radius: 3px;" onclick="restoreDraft()">Restore Draft</button>
        <button type="button" class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; cursor: pointer; border-radius: 3px;" onclick="discardDraft()">Discard</button>
    </div>
</div>

<form action="" method="POST" id="eventForm">
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: start;">
        
        <!-- Left Side: Detail Forms -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            
            <!-- SECTION 1: Logistics & Booking Details -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-calendar-day" style="color: var(--accent-color);"></i> Logistics & Event Schedule</h3>
                <div class="form-group">
                    <label for="title" class="form-label">Event Booking Title *</label>
                    <input type="text" id="title" name="title" class="form-control" placeholder="e.g. Wedding Reception of Jerry & Shruthi" value="<?= h($event_data['title']) ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="event_date" class="form-label">Event Date *</label>
                        <input type="date" id="event_date" name="event_date" class="form-control" value="<?= h($event_data['event_date']) ?>" required onchange="checkSlotClash()">
                    </div>
                    <div class="form-group">
                        <label for="event_time" class="form-label">Event Start Time *</label>
                        <input type="time" id="event_time" name="event_time" class="form-control" value="<?= h($event_data['event_time']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label">Booking Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="draft" <?= $event_data['status'] == 'draft' ? 'selected' : '' ?>>Draft Package</option>
                            <option value="confirmed" <?= $event_data['status'] == 'confirmed' ? 'selected' : '' ?>>Confirmed Event</option>
                            <option value="cancelled" <?= $event_data['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="venue" class="form-label">Venue / Place Name *</label>
                    <input type="text" id="venue" name="venue" class="form-control" placeholder="e.g. Town Hall Auditorium, Alappuzha" value="<?= h($event_data['venue']) ?>" required oninput="checkSlotClash()">
                </div>
            </div>
            
            <!-- SECTION 2: Client Profile -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-user-tie" style="color: var(--accent-color);"></i> Client Details</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="client_name" class="form-label">Client Name *</label>
                        <input type="text" id="client_name" name="client_name" class="form-control" placeholder="Jerry George" value="<?= h($event_data['client_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="client_phone" class="form-label">Client Contact Mob *</label>
                        <input type="text" id="client_phone" name="client_phone" class="form-control" placeholder="9876543210" value="<?= h($event_data['client_phone']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="client_email" class="form-label">Client Email Address</label>
                        <input type="email" id="client_email" name="client_email" class="form-control" placeholder="jerry@example.com" value="<?= h($event_data['client_email']) ?>">
                    </div>
                </div>
            </div>
            
            <!-- SECTION 3: Stage Work Configuration -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-holly-berry" style="color: var(--accent-color);"></i> Stage Work Decoration Services</h3>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.5rem;">Select stage decoration, photography, generator, sound setup or customized layouts. Modify customized prices as necessary.</p>
                
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($all_stage_items as $item): ?>
                        <?php 
                        $is_checked = isset($selected_stage_items[$item['id']]);
                        $price_val = $is_checked ? $selected_stage_items[$item['id']] : $item['default_price'];
                        ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; background: rgba(255,255,255,0.02); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                            <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; flex: 1;">
                                <input type="checkbox" name="stage_items[]" value="<?= $item['id'] ?>" class="stage-chk" <?= $is_checked ? 'checked' : '' ?> onchange="calculateSummary()" style="width: 18px; height: 18px; accent-color: var(--accent-color);">
                                <span style="font-weight: 500; font-size: 0.95rem;"><?= h($item['item_name']) ?></span>
                            </label>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-size: 0.85rem; color: var(--text-muted);">Rate (Rs):</span>
                                <input type="number" step="0.01" name="stage_custom_prices[<?= $item['id'] ?>]" value="<?= $price_val ?>" class="form-control stage-price" style="width: 100px; padding: 0.35rem 0.5rem;" oninput="calculateSummary()">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- SECTION 4: Catering Services & Menu Planner -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-utensils" style="color: var(--accent-color);"></i> Catering & Menu Planner</h3>
                <div class="form-row" style="margin-bottom: 2rem;">
                    <div class="form-group">
                        <label for="total_plates" class="form-label">Total Plates (Quantity) *</label>
                        <input type="number" id="total_plates" name="total_plates" class="form-control" value="<?= h($catering_data['total_plates']) ?>" min="1" required oninput="calculateSummary()">
                    </div>
                    <div class="form-group">
                        <label for="per_plate_price" class="form-label">Rate Per Plate (Rs) *</label>
                        <input type="number" step="0.01" id="per_plate_price" name="per_plate_price" class="form-control" value="<?= h($catering_data['per_plate_price']) ?>" min="0" required oninput="calculateSummary()">
                    </div>
                </div>
                
                <!-- Catering Dish Accordion Selections -->
                <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--text-primary);">Catering Menu Selection:</h4>
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <?php foreach ($all_categories as $cat): ?>
                        <div style="border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.25rem; background: rgba(0,0,0,0.1);">
                            <h5 style="color: var(--accent-color); font-size: 0.95rem; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em; display: flex; align-items: center; justify-content: space-between;">
                                <span><?= h($cat['category_name']) ?></span>
                                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; text-transform: none;">Check items to add to this client's package</span>
                            </h5>
                            
                            <?php if (empty($dishes_by_category[$cat['id']])): ?>
                                <p style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">No dishes in database for this category.</p>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem;">
                                    <?php foreach ($dishes_by_category[$cat['id']] as $dish): ?>
                                        <?php 
                                        $is_checked = isset($selected_dishes[$dish['id']]);
                                        $p_val = $is_checked ? $selected_dishes[$dish['id']] : '';
                                        ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.35rem 0.5rem; background: rgba(255,255,255,0.02); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem; flex: 1; margin: 0;">
                                                <input type="checkbox" name="dishes[]" value="<?= $dish['id'] ?>" class="dish-chk" <?= $is_checked ? 'checked' : '' ?> style="accent-color: var(--accent-color);" data-dishname="<?= h($dish['dish_name']) ?>" data-category="<?= h($cat['category_name']) ?>" onchange="calculateSummary(); togglePlatesInput(this)">
                                                <span style="line-height: 1.2;"><?= h($dish['dish_name']) ?></span>
                                            </label>
                                            <input type="number" name="dish_plates[<?= $dish['id'] ?>]" placeholder="Plates" class="form-control dish-plates-input" value="<?= h($p_val) ?>" style="width: 75px; padding: 0.2rem 0.4rem; font-size: 0.75rem; display: <?= $is_checked ? 'inline-block' : 'none' ?>;" oninput="calculateSummary()">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-group" style="margin-top: 1.5rem;">
                    <label for="catering_notes" class="form-label">Special Catering Requests / Customizations</label>
                    <textarea id="catering_notes" name="catering_notes" class="form-control" rows="3" placeholder="e.g. Vegetarian count: 50. All fish curries medium spicy."><?= h($catering_data['notes']) ?></textarea>
                </div>
            </div>
            
            <!-- SECTION 5: Billing & Payments -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent-color);"></i> Billing & Payment Details</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="advance_received" class="form-label">Advance Payment Received (Rs.)</label>
                        <input type="number" step="0.01" id="advance_received" name="advance_received" class="form-control" placeholder="0.00" value="<?= h($advance_received) ?>" oninput="calculateSummary()">
                    </div>
                    <div class="form-group">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="form-control">
                            <option value="" <?= empty($payment_method) ? 'selected' : '' ?>>-- Select Payment Method --</option>
                            <option value="Cash" <?= $payment_method === 'Cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="GPay / UPI" <?= $payment_method === 'GPay / UPI' ? 'selected' : '' ?>>GPay / UPI</option>
                            <option value="Bank Transfer" <?= $payment_method === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="Card" <?= $payment_method === 'Card' ? 'selected' : '' ?>>Card Payment</option>
                        </select>
                    </div>
                </div>
            </div>
            
        </div>
        
        <!-- Right Side: Sticky Live Quote Summary -->
        <div style="position: sticky; top: 1.5rem; display: flex; flex-direction: column; gap: 1.5rem;">
            <div class="card" style="border-color: var(--accent-color);">
                <h3 class="card-title" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem; color: var(--accent-color);">
                    <span><i class="fa-solid fa-file-invoice-dollar"></i> Live Quote Draft</span>
                </h3>
                
                <!-- Client Summary -->
                <div style="margin-bottom: 1rem; font-size: 0.85rem;">
                    <p style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Client</p>
                    <p id="summaryClient" style="font-weight: 600;">Jerry George</p>
                    <p id="summaryLogistics" style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 0.2rem;">Date: -- | Place: --</p>
                </div>
                
                <!-- Cost Elements -->
                <div style="display: flex; flex-direction: column; gap: 0.75rem; border-top: 1px dashed var(--border-color); border-bottom: 1px dashed var(--border-color); padding: 1rem 0; margin-bottom: 1.25rem;">
                    <!-- Stage Work Summary Line -->
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span style="color: var(--text-secondary);">Stage Work:</span>
                        <span id="summaryStageTotal" style="font-weight: 600; color: var(--text-primary);">Rs. 0</span>
                    </div>
                    
                    <!-- Catering Summary Line -->
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span style="color: var(--text-secondary);">Catering:</span>
                        <span id="summaryCateringTotal" style="font-weight: 600; color: var(--text-primary);">Rs. 0</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-muted); margin-top: -0.5rem;">
                        <span id="summaryCateringDetails">500 plates x Rs. 250</span>
                    </div>
                    
                    <!-- Advance Summary Line -->
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem; border-top: 1px dotted var(--border-color); padding-top: 0.5rem;">
                        <span style="color: var(--text-secondary);">Advance Paid:</span>
                        <span id="summaryAdvance" style="font-weight: 600; color: var(--text-primary);">Rs. 0</span>
                    </div>
                </div>
                
                <!-- Grand Total & Balance -->
                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <span style="font-weight: 700; font-size: 0.9rem; color: var(--text-secondary);">Est. Subtotal:</span>
                        <span id="summaryGrandTotal" style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">Rs. 0</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; border-top: 1px solid var(--border-color); padding-top: 0.5rem;">
                        <span style="font-weight: 700; font-size: 1rem; color: var(--accent-color);">Rest to Get:</span>
                        <span id="summaryRestToGet" style="font-size: 1.5rem; font-weight: 800; color: var(--accent-color);">Rs. 0</span>
                    </div>
                </div>
                
                <!-- Save Button Wrapper -->
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary" style="width: 100%; height: 50px;">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Save Package Details
                    </button>
                    <?php if ($is_edit): ?>
                        <a href="view-invoice.php?event_id=<?= $event_id ?>" class="btn btn-secondary" style="width: 100%;">
                            <i class="fa-solid fa-file-invoice-dollar"></i> View & Print Card
                        </a>
                        
                        <?php
                        $stmt_inv_chk = $db->prepare("SELECT COUNT(*) FROM invoices WHERE event_id = :id");
                        $stmt_inv_chk->execute(['id' => $event_id]);
                        $has_invoice = (int)$stmt_inv_chk->fetchColumn() > 0;
                        ?>
                        
                        <?php if ($has_invoice): ?>
                            <button type="button" class="btn btn-danger" style="width: 100%; background-color: #ef4444; border-color: #ef4444; opacity: 0.65; margin-top: 0.5rem;" onclick="alert('Cannot delete booking. This event has an active invoice. Please delete the invoice first.');">
                                <i class="fa-solid fa-trash-can"></i> Delete Booking
                            </button>
                        <?php else: ?>
                            <form action="delete-event.php" method="POST" style="margin: 0; width: 100%; margin-top: 0.5rem;" onsubmit="return confirm('Are you sure you want to delete this event/booking? This action cannot be undone.');">
                                <input type="hidden" name="event_id" value="<?= $event_id ?>">
                                <button type="submit" class="btn btn-danger" style="width: 100%; background-color: #ef4444; border-color: #ef4444;">
                                    <i class="fa-solid fa-trash-can"></i> Delete Booking
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Summary of Selected Dishes -->
            <div class="card" style="padding: 1.25rem;">
                <h4 style="margin-bottom: 0.5rem; font-size: 0.85rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em;">Menu Preview</h4>
                <div id="summaryDishesList" style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.8rem; color: var(--text-primary); max-height: 250px; overflow-y: auto;">
                    <span style="color: var(--text-muted); font-style: italic;">No dishes selected yet.</span>
                </div>
            </div>
        </div>
        
    </div>
</form>

<script>
// Perform client-side verification to warn of booked date+venue clashes
function checkSlotClash() {
    const dateInput = document.getElementById('event_date').value;
    const venueInput = document.getElementById('venue').value.trim();
    const excludeId = <?= $event_id ?>;
    
    const warningBanner = document.getElementById('clashWarning');
    const warningMsg = document.getElementById('clashMessage');
    
    if (dateInput && venueInput) {
        fetch(`../api/check-availability.php?date=${dateInput}&venue=${encodeURIComponent(venueInput)}&exclude_id=${excludeId}`)
            .then(res => res.json())
            .then(data => {
                if (data.available === false) {
                    warningMsg.textContent = data.message;
                    warningBanner.style.display = 'flex';
                } else {
                    warningBanner.style.display = 'none';
                }
            })
            .catch(err => {
                console.error("AJAX Availability Check failed:", err);
            });
    } else {
        warningBanner.style.display = 'none';
    }
}

// Calculate rates dynamically in Javascript and update sticky box
function calculateSummary() {
    // 1. Client profile summary
    const clientNameInput = document.getElementById('client_name').value.trim();
    const eventDateInput = document.getElementById('event_date').value;
    const venueInput = document.getElementById('venue').value.trim();
    
    document.getElementById('summaryClient').textContent = clientNameInput ? clientNameInput : 'No Client Name';
    
    // Format date string for display
    let dateFormatted = '--/--/----';
    if (eventDateInput) {
        const dObj = new Date(eventDateInput);
        if (!isNaN(dObj.getTime())) {
            const dd = String(dObj.getDate()).padStart(2, '0');
            const mm = String(dObj.getMonth() + 1).padStart(2, '0');
            const yyyy = dObj.getFullYear();
            dateFormatted = `${dd}/${mm}/${yyyy}`;
        }
    }
    document.getElementById('summaryLogistics').textContent = `Date: ${dateFormatted} | Place: ${venueInput ? venueInput : '--'}`;
    
    // 2. Stage work calculations
    let stageTotal = 0;
    const checkboxes = document.querySelectorAll('.stage-chk');
    
    checkboxes.forEach(chk => {
        if (chk.checked) {
            const itemId = chk.value;
            const priceInput = document.querySelector(`.stage-price[name="stage_custom_prices[${itemId}]"]`);
            if (priceInput) {
                stageTotal += parseFloat(priceInput.value) || 0;
            }
        }
    });
    
    document.getElementById('summaryStageTotal').textContent = 'Rs. ' + stageTotal.toLocaleString();
    
    // 3. Catering calculations
    const plateCount = parseInt(document.getElementById('total_plates').value) || 0;
    const plateRate = parseFloat(document.getElementById('per_plate_price').value) || 0;
    const cateringTotal = plateCount * plateRate;
    
    document.getElementById('summaryCateringTotal').textContent = 'Rs. ' + cateringTotal.toLocaleString();
    document.getElementById('summaryCateringDetails').textContent = `${plateCount} plates x Rs. ${plateRate.toLocaleString()}`;
    
    // 4. Advance calculations
    const advancePaid = parseFloat(document.getElementById('advance_received').value) || 0;
    document.getElementById('summaryAdvance').textContent = 'Rs. ' + advancePaid.toLocaleString();
    
    // 5. Grand Total & Rest to Get
    const grandTotal = stageTotal + cateringTotal;
    const restToGet = grandTotal - advancePaid;
    
    document.getElementById('summaryGrandTotal').textContent = 'Rs. ' + grandTotal.toLocaleString();
    document.getElementById('summaryRestToGet').textContent = 'Rs. ' + restToGet.toLocaleString();
    
    // 5. Build Dynamic Dishes preview list grouped by category
    const dishCheckboxes = document.querySelectorAll('.dish-chk');
    const dishSummaryList = document.getElementById('summaryDishesList');
    
    let selectedDishesGrouped = {};
    dishCheckboxes.forEach(chk => {
        if (chk.checked) {
            const cat = chk.getAttribute('data-category');
            const dName = chk.getAttribute('data-dishname');
            const parent = chk.closest('div');
            const input = parent ? parent.querySelector('.dish-plates-input') : null;
            const platesVal = input ? input.value : '';
            
            if (!selectedDishesGrouped[cat]) {
                selectedDishesGrouped[cat] = [];
            }
            selectedDishesGrouped[cat].push({ name: dName, plates: platesVal });
        }
    });
    
    let htmlOutput = '';
    const keys = Object.keys(selectedDishesGrouped);
    if (keys.length === 0) {
        htmlOutput = '<span style="color: var(--text-muted); font-style: italic;">No dishes selected yet.</span>';
    } else {
        keys.forEach(cat => {
            htmlOutput += `<div style="margin-top: 0.5rem;"><strong style="color: var(--accent-color); font-size: 0.75rem; text-transform: uppercase;">${cat}</strong></div>`;
            selectedDishesGrouped[cat].forEach(d => {
                const platesSuffix = (d.plates && parseInt(d.plates) > 0) ? ` (${d.plates} Plates)` : '';
                htmlOutput += `<div style="padding-left: 0.5rem; border-left: 1px solid var(--border-color); margin-top: 0.15rem;">• ${d.name}${platesSuffix}</div>`;
            });
        });
    }
    dishSummaryList.innerHTML = htmlOutput;
}

function togglePlatesInput(chk) {
    const parent = chk.closest('div');
    const input = parent ? parent.querySelector('.dish-plates-input') : null;
    if (input) {
        if (chk.checked) {
            input.style.display = 'inline-block';
        } else {
            input.style.display = 'none';
            input.value = '';
        }
    }
}

const DRAFT_KEY = <?= $is_edit ? "'orange_event_draft_edit_" . (int)$event_id . "'" : "'orange_event_draft_new'" ?>;

function saveDraft() {
    const data = {
        title: document.getElementById('title').value,
        event_date: document.getElementById('event_date').value,
        event_time: document.getElementById('event_time').value,
        venue: document.getElementById('venue').value,
        client_name: document.getElementById('client_name').value,
        client_phone: document.getElementById('client_phone').value,
        client_email: document.getElementById('client_email').value,
        total_plates: document.getElementById('total_plates').value,
        per_plate_price: document.getElementById('per_plate_price').value,
        catering_notes: document.getElementById('catering_notes').value,
        advance_received: document.getElementById('advance_received').value,
        payment_method: document.getElementById('payment_method').value,
        stage_items: [],
        dishes: [],
        timestamp: new Date().toLocaleString()
    };
    
    // Save Stage work checked states & values
    document.querySelectorAll('.stage-chk').forEach(chk => {
        if (chk.checked) {
            const priceInput = document.querySelector(`.stage-price[name="stage_custom_prices[${chk.value}]"]`);
            data.stage_items.push({
                id: chk.value,
                price: priceInput ? priceInput.value : ''
            });
        }
    });
    
    // Save Catering selected dishes & plate counts
    document.querySelectorAll('.dish-chk').forEach(chk => {
        if (chk.checked) {
            const parent = chk.closest('div');
            const input = parent ? parent.querySelector('.dish-plates-input') : null;
            data.dishes.push({
                id: chk.value,
                plates: input ? input.value : ''
            });
        }
    });
    
    localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
}

function setupDraftAutoSave() {
    const form = document.getElementById('eventForm');
    if (!form) return;
    
    form.addEventListener('input', saveDraft);
    form.addEventListener('change', saveDraft);
    
    form.addEventListener('submit', () => {
        localStorage.removeItem(DRAFT_KEY);
    });
}

function checkPendingDraft() {
    const saved = localStorage.getItem(DRAFT_KEY);
    if (!saved) return;
    
    try {
        const data = JSON.parse(saved);
        if (data.timestamp && (data.title || data.client_name || data.venue || data.dishes.length > 0 || data.stage_items.length > 0)) {
            document.getElementById('draftTime').textContent = data.timestamp;
            document.getElementById('draftNotification').style.display = 'flex';
        }
    } catch (e) {
        localStorage.removeItem(DRAFT_KEY);
    }
}

function restoreDraft() {
    const saved = localStorage.getItem(DRAFT_KEY);
    if (!saved) return;
    
    try {
        const data = JSON.parse(saved);
        
        if (data.title !== undefined) document.getElementById('title').value = data.title;
        if (data.event_date !== undefined) document.getElementById('event_date').value = data.event_date;
        if (data.event_time !== undefined) document.getElementById('event_time').value = data.event_time;
        if (data.venue !== undefined) document.getElementById('venue').value = data.venue;
        if (data.client_name !== undefined) document.getElementById('client_name').value = data.client_name;
        if (data.client_phone !== undefined) document.getElementById('client_phone').value = data.client_phone;
        if (data.client_email !== undefined) document.getElementById('client_email').value = data.client_email;
        if (data.total_plates !== undefined) document.getElementById('total_plates').value = data.total_plates;
        if (data.per_plate_price !== undefined) document.getElementById('per_plate_price').value = data.per_plate_price;
        if (data.catering_notes !== undefined) document.getElementById('catering_notes').value = data.catering_notes;
        if (data.advance_received !== undefined) document.getElementById('advance_received').value = data.advance_received;
        if (data.payment_method !== undefined) document.getElementById('payment_method').value = data.payment_method;
        
        // Restore stage items
        document.querySelectorAll('.stage-chk').forEach(chk => {
            chk.checked = false;
            const priceInput = document.querySelector(`.stage-price[name="stage_custom_prices[${chk.value}]"]`);
            if (priceInput) priceInput.style.display = 'none';
        });
        if (data.stage_items) {
            data.stage_items.forEach(item => {
                const chk = document.querySelector(`.stage-chk[value="${item.id}"]`);
                if (chk) {
                    chk.checked = true;
                    const priceInput = document.querySelector(`.stage-price[name="stage_custom_prices[${item.id}]"]`);
                    if (priceInput) {
                        priceInput.style.display = 'block';
                        priceInput.value = item.price;
                    }
                }
            });
        }
        
        // Restore dishes
        document.querySelectorAll('.dish-chk').forEach(chk => {
            chk.checked = false;
            const parent = chk.closest('div');
            const input = parent ? parent.querySelector('.dish-plates-input') : null;
            if (input) {
                input.style.display = 'none';
                input.value = '';
            }
        });
        if (data.dishes) {
            data.dishes.forEach(dish => {
                const chk = document.querySelector(`.dish-chk[value="${dish.id}"]`);
                if (chk) {
                    chk.checked = true;
                    const parent = chk.closest('div');
                    const input = parent ? parent.querySelector('.dish-plates-input') : null;
                    if (input) {
                        input.style.display = 'inline-block';
                        input.value = dish.plates;
                    }
                }
            });
        }
        
        calculateSummary();
        checkSlotClash();
        
        document.getElementById('draftNotification').style.display = 'none';
    } catch (e) {
        alert("Failed to restore draft: " + e.message);
    }
}

function discardDraft() {
    localStorage.removeItem(DRAFT_KEY);
    document.getElementById('draftNotification').style.display = 'none';
}

// Attach event listeners for initial triggers
document.addEventListener('DOMContentLoaded', () => {
    calculateSummary();
    checkSlotClash();
    checkPendingDraft();
    setupDraftAutoSave();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
