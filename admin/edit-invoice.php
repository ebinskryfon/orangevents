<?php
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($event_id <= 0) {
    echo "<h3>Error: Event ID is required.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch event logistics
$stmt = $db->prepare("SELECT * FROM events WHERE id = :id");
$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch();

if (!$event) {
    echo "<h3>Error: Booking not found.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch invoice status
$stmt_inv = $db->prepare("SELECT * FROM invoices WHERE event_id = :id");
$stmt_inv->execute(['id' => $event_id]);
$invoice = $stmt_inv->fetch();

if (!$invoice) {
    // Auto-create invoice if missing
    $inv_num = "INV-" . date('Y') . "-" . str_pad($event_id, 4, '0', STR_PAD_LEFT);
    $db->prepare("INSERT INTO invoices (event_id, invoice_number, subtotal, final_total, status) VALUES (:id, :num, 0, 0, 'draft')")->execute(['id' => $event_id, 'num' => $inv_num]);
    
    $stmt_inv->execute(['id' => $event_id]);
    $invoice = $stmt_inv->fetch();
}

// Fetch catalog options
$all_stage_items = $db->query("SELECT * FROM stage_items ORDER BY item_name ASC")->fetchAll();
$all_categories = $db->query("SELECT * FROM menu_categories ORDER BY display_order ASC")->fetchAll();
$dishes = $db->query("SELECT d.*, c.category_name FROM dishes d JOIN menu_categories c ON d.category_id = c.id ORDER BY d.dish_name ASC")->fetchAll();
$dishes_by_category = [];
foreach ($dishes as $d) {
    $dishes_by_category[$d['category_id']][] = $d;
}

// Fetch selected items for the event
$selected_stage_items = [];
$stmt_stage_sel = $db->prepare("SELECT stage_item_id, quantity, unit_price, custom_price FROM event_stage_work WHERE event_id = :id ORDER BY id ASC");
$stmt_stage_sel->execute(['id' => $event_id]);
while ($row = $stmt_stage_sel->fetch()) {
    $selected_stage_items[$row['stage_item_id']] = [
        'price' => $row['custom_price'],
        'quantity' => $row['quantity'] ?: 1,
        'unit_price' => $row['unit_price']
    ];
}
$initial_selected_stage_ids = array_map('strval', array_keys($selected_stage_items));

$catering_data = [
    'per_plate_price' => 250.00,
    'total_plates' => 500,
    'notes' => ''
];
$selected_dishes = [];
$stmt_cat = $db->prepare("SELECT * FROM event_catering WHERE event_id = :id");
$stmt_cat->execute(['id' => $event_id]);
$loaded_catering = $stmt_cat->fetch();
if ($loaded_catering) {
    $catering_data = $loaded_catering;
    
    $stmt_dish_sel = $db->prepare("SELECT dish_id, plate_count, dish_rate FROM event_catering_dishes WHERE event_catering_id = :cat_id ORDER BY id ASC");
    $stmt_dish_sel->execute(['cat_id' => $loaded_catering['id']]);
    while ($row = $stmt_dish_sel->fetch()) {
        $selected_dishes[$row['dish_id']] = [
            'plates' => $row['plate_count'],
            'rate' => $row['dish_rate']
        ];
    }
}
$initial_selected_dish_ids = array_map('strval', array_keys($selected_dishes));

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Core Event details
    $title = trim($_POST['title']);
    $client_name = trim($_POST['client_name']);
    $client_phone = trim($_POST['client_phone']);
    $client_email = trim($_POST['client_email']);
    $event_date = trim($_POST['event_date']);
    $event_time = trim($_POST['event_time']);
    $venue = trim($_POST['venue']);
    
    // 2. Catering Details
    $per_plate_price = (float)$_POST['per_plate_price'];
    $total_plates = (int)$_POST['total_plates'];
    $catering_notes = trim($_POST['catering_notes']);
    $dishes_posted = $_POST['dishes'] ?? [];
    
    // 3. Stage Details
    $raw_stage_posted = $_POST['stage_items'] ?? [];
    $stage_posted = array_values(array_unique($raw_stage_posted));
    $stage_prices = $_POST['stage_custom_prices'] ?? [];
    $stage_quantities = $_POST['stage_quantities'] ?? [];
    $stage_unit_prices = $_POST['stage_unit_prices'] ?? [];
    
    // 4. Invoice details
    $invoice_number = trim($_POST['invoice_number']);
    $template_name = trim($_POST['template_name']);
    $status = trim($_POST['status']);
    $discount = (float)$_POST['discount'];
    $tax_rate = (float)$_POST['tax_rate'];
    $advance_received = (float)$_POST['advance_received'];
    $balance_received = (float)($_POST['balance_received'] ?? 0.0);
    $payment_method = trim($_POST['payment_method']);
    if ($payment_method === '') {
        $payment_method = null;
    }
    $created_at = trim($_POST['created_at']);
    
    if (empty($title) || empty($client_name) || empty($client_phone) || empty($event_date) || empty($venue) || empty($invoice_number)) {
        $error = "Please fill in all required fields.";
    } else {
        try {
            $db->beginTransaction();
            
            // Check double-booking clash on the database level
            $clash_sql = "SELECT id, title FROM events WHERE event_date = :date AND LOWER(TRIM(venue)) = LOWER(TRIM(:venue)) AND id != :id";
            $clash_stmt = $db->prepare($clash_sql);
            $clash_stmt->execute(['date' => $event_date, 'venue' => $venue, 'id' => $event_id]);
            $clash_found = $clash_stmt->fetch();
            
            if ($clash_found) {
                throw new Exception('Clash Alert: Venue is already booked for another event ("' . $clash_found['title'] . '") on this date!');
            }
            
            // Check invoice number unique
            $stmt_check = $db->prepare("SELECT id FROM invoices WHERE invoice_number = :num AND id != :id");
            $stmt_check->execute(['num' => $invoice_number, 'id' => $invoice['id']]);
            if ($stmt_check->fetch()) {
                throw new Exception("Invoice number '{$invoice_number}' is already taken by another invoice.");
            }
            
            // 1. Update events
            $stmt_ev = $db->prepare("UPDATE events SET title = :title, client_name = :client_name, client_phone = :client_phone, client_email = :client_email, event_date = :event_date, event_time = :event_time, venue = :venue, status = :status WHERE id = :id");
            $event_status = ($status === 'draft') ? 'draft' : 'confirmed';
            $stmt_ev->execute([
                'title' => $title, 'client_name' => $client_name, 'client_phone' => $client_phone,
                'client_email' => $client_email, 'event_date' => $event_date, 'event_time' => $event_time,
                'venue' => $venue, 'status' => $event_status, 'id' => $event_id
            ]);
            
            // 2. Update catering
            if ($loaded_catering) {
                $stmt_cat_up = $db->prepare("UPDATE event_catering SET per_plate_price = :rate, total_plates = :plates, notes = :notes WHERE event_id = :event_id");
                $stmt_cat_up->execute(['rate' => $per_plate_price, 'plates' => $total_plates, 'notes' => $catering_notes, 'event_id' => $event_id]);
                $catering_id = $loaded_catering['id'];
            } else {
                $stmt_cat_in = $db->prepare("INSERT INTO event_catering (event_id, per_plate_price, total_plates, notes) VALUES (:event_id, :rate, :plates, :notes)");
                $stmt_cat_in->execute(['event_id' => $event_id, 'rate' => $per_plate_price, 'plates' => $total_plates, 'notes' => $catering_notes]);
                $catering_id = $db->lastInsertId();
            }
            
            // 3. Update dishes
            $db->prepare("DELETE FROM event_catering_dishes WHERE event_catering_id = :id")->execute(['id' => $catering_id]);
            if (!empty($dishes_posted)) {
                $stmt_dish_insert = $db->prepare("INSERT INTO event_catering_dishes (event_catering_id, dish_id, plate_count, dish_rate) VALUES (:cat_id, :dish_id, :plates, :rate)");
                $dish_plates = $_POST['dish_plates'] ?? [];
                $dish_rates = $_POST['dish_rates'] ?? [];
                foreach ($dishes_posted as $d_id) {
                    $p_count = (isset($dish_plates[$d_id]) && $dish_plates[$d_id] !== '') ? (int)$dish_plates[$d_id] : null;
                    $d_rate = (isset($dish_rates[$d_id]) && $dish_rates[$d_id] !== '') ? (float)$dish_rates[$d_id] : null;
                    $stmt_dish_insert->execute([
                        'cat_id' => $catering_id,
                        'dish_id' => (int)$d_id,
                        'plates' => $p_count,
                        'rate' => $d_rate
                    ]);
                }
            }
            
            // 4. Update stage work
            $db->prepare("DELETE FROM event_stage_work WHERE event_id = :ev_id")->execute(['ev_id' => $event_id]);
            $stage_total = 0.00;
            if (!empty($stage_posted)) {
                $stmt_stage_insert = $db->prepare("INSERT INTO event_stage_work (event_id, stage_item_id, quantity, unit_price, custom_price) VALUES (:ev_id, :item_id, :qty, :u_price, :price)");
                foreach ($stage_posted as $item_id) {
                    $c_price = (float)($stage_prices[$item_id] ?? 0.00);
                    $qty = max(1, (int)($stage_quantities[$item_id] ?? 1));
                    $u_price = (isset($stage_unit_prices[$item_id]) && $stage_unit_prices[$item_id] !== '') ? (float)$stage_unit_prices[$item_id] : null;
                    $stmt_stage_insert->execute([
                        'ev_id' => $event_id,
                        'item_id' => (int)$item_id,
                        'qty' => $qty,
                        'u_price' => $u_price,
                        'price' => $c_price
                    ]);
                    $stage_total += $c_price;
                }
            }
            
            // Calculate final financials
            $catering_total = $per_plate_price * $total_plates;
            $subtotal = $catering_total + $stage_total;
            
            $taxable = $subtotal - $discount;
            if ($taxable < 0) {
                $taxable = 0;
            }
            $tax_amount = $taxable * ($tax_rate / 100);
            $final_total = $taxable + $tax_amount;
            
            // Manage advance payment metadata
            $new_adv_paid_at = $invoice['advance_paid_at'];
            $new_adv_method = $invoice['advance_payment_method'];
            if ($advance_received > 0) {
                if ((float)$invoice['advance_received'] == $advance_received && $invoice['advance_paid_at'] !== null) {
                    // Keep original, but update method if it was updated
                    if ($payment_method !== '') {
                        $new_adv_method = $payment_method;
                    }
                } else {
                    $new_adv_paid_at = date('Y-m-d H:i:s');
                    $new_adv_method = $payment_method ?: 'CASH';
                }
            } else {
                $new_adv_paid_at = null;
                $new_adv_method = null;
            }
            
            // Manage balance payment metadata
            if ($status === 'paid' && $balance_received == 0.0) {
                $balance_received = max(0.0, $final_total - $advance_received);
            }

            $new_bal_paid_at = $invoice['balance_paid_at'];
            $new_bal_method = $invoice['balance_payment_method'];
            if ($balance_received > 0) {
                if ((float)$invoice['balance_received'] == $balance_received && $invoice['balance_paid_at'] !== null) {
                    if ($payment_method !== '') {
                        $new_bal_method = $payment_method;
                    }
                } else {
                    $new_bal_paid_at = date('Y-m-d H:i:s');
                    $new_bal_method = $payment_method ?: 'CASH';
                }
            } else {
                $new_bal_paid_at = null;
                $new_bal_method = null;
            }
            
            // 5. Update invoice
            $stmt_update = $db->prepare("UPDATE invoices SET 
                                            invoice_number = :num,
                                            subtotal = :subtotal,
                                            discount = :discount,
                                            tax_rate = :tax_rate,
                                            tax_amount = :tax_amount,
                                            final_total = :final_total,
                                            advance_received = :advance,
                                            balance_received = :balance,
                                            payment_method = :method,
                                            status = :status,
                                            template_name = :temp,
                                            created_at = :created,
                                            advance_paid_at = :adv_paid_at,
                                            advance_payment_method = :adv_method,
                                            balance_paid_at = :bal_paid_at,
                                            balance_payment_method = :bal_method
                                          WHERE id = :id");
            $stmt_update->execute([
                'num' => $invoice_number,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => $tax_rate,
                'tax_amount' => $tax_amount,
                'final_total' => $final_total,
                'advance' => $advance_received,
                'balance' => $balance_received,
                'method' => $payment_method,
                'status' => $status,
                'temp' => $template_name,
                'created' => $created_at . ' ' . date('H:i:s', strtotime($invoice['created_at'])),
                'adv_paid_at' => $new_adv_paid_at,
                'adv_method' => $new_adv_method,
                'bal_paid_at' => $new_bal_paid_at,
                'bal_method' => $new_bal_method,
                'id' => $invoice['id']
            ]);
            
            $db->commit();
            
            // Redirect
            header("Location: view-invoice.php?event_id=" . $event_id . "&success=1");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<div class="content-header" style="margin-bottom: 2rem;">
    <div>
        <div style="margin-bottom: 0.5rem; display: flex; gap: 1.5rem; align-items: center;">
            <a href="view-invoice.php?event_id=<?= $event_id ?>" style="color: var(--accent-color); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-arrow-left"></i> Back to Invoice View
            </a>
        </div>
        <h1 style="font-size: 2.2rem; font-weight: 800; color: var(--text-primary);">Edit Booking & Invoice</h1>
        <p style="color: var(--text-secondary); margin-top: 0.25rem;">Adjust clients, stage decoration items, catering menus, dishes, and financial items.</p>
    </div>
</div>

<?php if ($error): ?>
    <div style="background-color: rgba(220, 38, 38, 0.1); border: 1px solid #dc2626; color: #dc2626; padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem;"></i>
        <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<form action="" method="POST" id="invoiceForm">
    <div style="display: grid; grid-template-columns: 1.3fr 0.7fr; gap: 2rem; align-items: start; margin-bottom: 3rem;">
        
        <!-- Left Column Form Fields -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            
            <!-- SECTION 1: Booking Profile & Venue -->
            <div class="card" style="padding: 2rem;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-calendar-check" style="color: var(--accent-color);"></i>
                    Booking & Client Profile
                </h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label for="title" class="form-label" style="font-weight: 600;">Event Title *</label>
                        <input type="text" id="title" name="title" class="form-control" value="<?= h($event['title']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="client_name" class="form-label" style="font-weight: 600;">Client Name *</label>
                        <input type="text" id="client_name" name="client_name" class="form-control" value="<?= h($event['client_name']) ?>" required>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label for="client_phone" class="form-label" style="font-weight: 600;">Client Contact Mob *</label>
                        <input type="text" id="client_phone" name="client_phone" class="form-control" value="<?= h($event['client_phone']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="client_email" class="form-label" style="font-weight: 600;">Client Email</label>
                        <input type="email" id="client_email" name="client_email" class="form-control" value="<?= h($event['client_email']) ?>">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label for="event_date" class="form-label" style="font-weight: 600;">Event Date *</label>
                        <input type="date" id="event_date" name="event_date" class="form-control" value="<?= h($event['event_date']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="event_time" class="form-label" style="font-weight: 600;">Event Time *</label>
                        <input type="time" id="event_time" name="event_time" class="form-control" value="<?= h($event['event_time']) ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="venue" class="form-label" style="font-weight: 600;">Event Venue Location *</label>
                    <input type="text" id="venue" name="venue" class="form-control" value="<?= h($event['venue']) ?>" required>
                </div>
            </div>
            
            <!-- SECTION 2: Stage Decoration Items -->
            <div class="card" style="padding: 2rem;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-holly-berry" style="color: var(--accent-color);"></i>
                    Stage Decorations
                </h3>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($all_stage_items as $item): ?>
                        <?php 
                        $is_checked = isset($selected_stage_items[$item['id']]);
                        $item_data = $selected_stage_items[$item['id']] ?? null;
                        $price_val = $is_checked ? $item_data['price'] : $item['default_price'];
                        $qty_val = $is_checked ? ($item_data['quantity'] ?? 1) : 1;
                        $unit_val = ($is_checked && isset($item_data['unit_price'])) ? $item_data['unit_price'] : '';
                        ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; background: rgba(255,255,255,0.02); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color); flex-wrap: wrap; gap: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; flex: 1; min-width: 180px; margin: 0;">
                                <input type="checkbox" value="<?= $item['id'] ?>" class="stage-chk" <?= $is_checked ? 'checked' : '' ?> onchange="toggleStageSelection(this)" style="width: 18px; height: 18px; accent-color: var(--accent-color);">
                                <span style="font-weight: 500; font-size: 0.9rem; color: var(--text-primary);"><?= h($item['item_name']) ?></span>
                            </label>
                            <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Qty:</span>
                                <input type="number" min="1" name="stage_quantities[<?= $item['id'] ?>]" value="<?= $qty_val ?>" class="form-control stage-qty" style="width: 60px; padding: 0.35rem 0.4rem; font-size: 0.8rem;" oninput="updateStageRowTotal(<?= $item['id'] ?>)">
                                
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Rate/Item:</span>
                                <input type="number" step="0.01" min="0" name="stage_unit_prices[<?= $item['id'] ?>]" value="<?= $unit_val ?>" placeholder="Optional" class="form-control stage-unit-price" style="width: 90px; padding: 0.35rem 0.4rem; font-size: 0.8rem;" oninput="updateStageRowTotal(<?= $item['id'] ?>)">
                                
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Total (Rs):</span>
                                <input type="number" step="0.01" name="stage_custom_prices[<?= $item['id'] ?>]" value="<?= $price_val ?>" class="form-control stage-price" style="width: 100px; padding: 0.35rem 0.5rem; font-size: 0.85rem;" oninput="calculateSummary()">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- SECTION 3: Catering & Dishes Selection -->
            <div class="card" style="padding: 2rem;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-utensils" style="color: var(--accent-color);"></i>
                    Catering & Menu Selection
                </h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div class="form-group">
                        <label for="total_plates" class="form-label" style="font-weight: 600;">Total Plates (Optional)</label>
                        <input type="number" id="total_plates" name="total_plates" class="form-control" value="<?= h($catering_data['total_plates']) ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label for="per_plate_price" class="form-label" style="font-weight: 600;">Rate Per Plate (Rs) (Optional)</label>
                        <input type="number" step="0.01" id="per_plate_price" name="per_plate_price" class="form-control" value="<?= h($catering_data['per_plate_price']) ?>" min="0">
                    </div>
                </div>
                
                <h4 style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin-bottom: 1rem;">Select Category Dishes:</h4>
                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <?php foreach ($all_categories as $cat): ?>
                        <div style="border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1rem; background: rgba(0,0,0,0.15);">
                            <h5 style="color: var(--accent-color); font-size: 0.85rem; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em; font-weight: 700;">
                                <?= h($cat['category_name']) ?>
                            </h5>
                            <?php if (empty($dishes_by_category[$cat['id']])): ?>
                                <p style="color: var(--text-muted); font-size: 0.8rem; font-style: italic;">No dishes in this category.</p>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.5rem;">
                                     <?php foreach ($dishes_by_category[$cat['id']] as $dish): ?>
                                         <?php 
                                         $is_checked = array_key_exists($dish['id'], $selected_dishes);
                                         $dish_data = $is_checked ? $selected_dishes[$dish['id']] : null;
                                         $p_val = is_array($dish_data) ? ($dish_data['plates'] ?? '') : ($is_checked ? $dish_data : '');
                                         $r_val = is_array($dish_data) ? ($dish_data['rate'] ?? '') : '';
                                         ?>
                                         <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.35rem 0.5rem; background: rgba(255,255,255,0.02); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color); gap: 0.35rem;">
                                             <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.8rem; flex: 1; margin: 0; color: var(--text-primary);">
                                                 <input type="checkbox" value="<?= $dish['id'] ?>" class="dish-chk" <?= $is_checked ? 'checked' : '' ?> style="accent-color: var(--accent-color);" data-dishname="<?= h($dish['dish_name']) ?>" data-category="<?= h($cat['category_name']) ?>" onchange="toggleDishSelection(this)">
                                                 <span><?= h($dish['dish_name']) ?></span>
                                             </label>
                                             <div class="dish-inputs-wrap" style="display: <?= $is_checked ? 'flex' : 'none' ?>; align-items: center; gap: 0.25rem;">
                                                 <input type="number" name="dish_plates[<?= $dish['id'] ?>]" placeholder="Qty" class="form-control dish-plates-input" value="<?= h($p_val) ?>" style="width: 60px; padding: 0.2rem 0.35rem; font-size: 0.75rem;" oninput="renderDishesPreview();">
                                                 <input type="number" step="0.01" name="dish_rates[<?= $dish['id'] ?>]" placeholder="Rate" class="form-control dish-rate-input" value="<?= h($r_val) ?>" style="width: 65px; padding: 0.2rem 0.35rem; font-size: 0.75rem;" oninput="renderDishesPreview();">
                                             </div>
                                         </div>
                                     <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-group" style="margin-top: 1.5rem;">
                    <label for="catering_notes" class="form-label" style="font-weight: 600;">Special Catering Requests / Customizations</label>
                    <textarea id="catering_notes" name="catering_notes" class="form-control" rows="3" placeholder="e.g. Vegetarian count: 50. Medium spicy."><?= h($catering_data['notes']) ?></textarea>
                </div>
            </div>
            
            <!-- SECTION 4: Invoice & Financial Details -->
            <div class="card" style="padding: 2rem;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-file-invoice" style="color: var(--accent-color);"></i>
                    Invoice & Payment Adjustments
                </h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div class="form-group">
                        <label for="invoice_number" class="form-label" style="font-weight: 600;">Invoice Number *</label>
                        <input type="text" id="invoice_number" name="invoice_number" class="form-control" value="<?= h($invoice['invoice_number']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="created_at" class="form-label" style="font-weight: 600;">Invoice Date *</label>
                        <input type="date" id="created_at" name="created_at" class="form-control" value="<?= date('Y-m-d', strtotime($invoice['created_at'])) ?>" required>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div class="form-group">
                        <label for="template_name" class="form-label" style="font-weight: 600;">Template Layout</label>
                        <select name="template_name" id="template_name" class="form-control">
                            <option value="orange_classic" <?= $invoice['template_name'] == 'orange_classic' ? 'selected' : '' ?>>Orange Classic</option>
                            <option value="royal_gold" <?= $invoice['template_name'] == 'royal_gold' ? 'selected' : '' ?>>Royal Gold</option>
                            <option value="midnight_dark" <?= $invoice['template_name'] == 'midnight_dark' ? 'selected' : '' ?>>Midnight Dark</option>
                            <option value="aedan_gardens" <?= $invoice['template_name'] == 'aedan_gardens' ? 'selected' : '' ?>>Aedan Gardens (Tax Invoice)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label" style="font-weight: 600;">Invoice Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="draft" <?= $invoice['status'] == 'draft' ? 'selected' : '' ?>>Draft (Unlocked)</option>
                            <option value="finalized" <?= $invoice['status'] == 'finalized' ? 'selected' : '' ?>>Finalized (Locked)</option>
                            <option value="paid" <?= $invoice['status'] == 'paid' ? 'selected' : '' ?>>Paid (Settled)</option>
                        </select>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div class="form-group">
                        <label for="discount" class="form-label" style="font-weight: 600;">Discount Amount (Rs.)</label>
                        <input type="number" step="0.01" id="discount" name="discount" class="form-control" value="<?= (float)$invoice['discount'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label for="tax_rate" class="form-label" style="font-weight: 600;">GST Tax Rate (%)</label>
                        <input type="number" step="0.01" id="tax_rate" name="tax_rate" class="form-control" value="<?= (float)$invoice['tax_rate'] ?>" min="0" max="100">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div class="form-group">
                        <label for="advance_received" class="form-label" style="font-weight: 600;">Advance Paid (Rs.)</label>
                        <input type="number" step="0.01" id="advance_received" name="advance_received" class="form-control" value="<?= (float)$invoice['advance_received'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label for="balance_received" class="form-label" style="font-weight: 600;">Balance Paid (Rs.)</label>
                        <input type="number" step="0.01" id="balance_received" name="balance_received" class="form-control" value="<?= (float)$invoice['balance_received'] ?>" min="0">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                    <div class="form-group">
                        <label for="payment_method" class="form-label" style="font-weight: 600;">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            <option value="">-- Select Payment Method --</option>
                            <option value="CASH" <?= $invoice['payment_method'] == 'CASH' ? 'selected' : '' ?>>Cash</option>
                            <option value="BANK TRANSFER" <?= $invoice['payment_method'] == 'BANK TRANSFER' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="UPI" <?= $invoice['payment_method'] == 'UPI' ? 'selected' : '' ?>>UPI (GPay/PhonePe)</option>
                            <option value="CARD" <?= $invoice['payment_method'] == 'CARD' ? 'selected' : '' ?>>Debit/Credit Card</option>
                            <option value="CHEQUE" <?= $invoice['payment_method'] == 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Form Submit Buttons -->
            <div style="display: flex; gap: 1rem; justify-content: flex-end; padding-top: 0.5rem;">
                <a href="view-invoice.php?event_id=<?= $event_id ?>" class="btn btn-secondary" style="padding: 0.75rem 1.5rem;">Cancel</a>
                <button type="submit" class="btn btn-success" style="padding: 0.75rem 2rem; font-weight: 600;">
                    <i class="fa-solid fa-save"></i> Save Booking & Invoice
                </button>
            </div>
        </div>
        
        <!-- Right Column Calculations Summary Panel -->
        <div style="position: sticky; top: 1.5rem;">
            <div class="card" style="padding: 2rem; background: var(--bg-body); border: 1px solid var(--border-color);">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                    <i class="fa-solid fa-receipt" style="color: var(--accent-color);"></i>
                    Live Calculation Preview
                </h3>
                
                <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.95rem;">
                    <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                        <span>Catering Total:</span>
                        <span id="previewCateringTotal" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                        <span>Stage Decorations Total:</span>
                        <span id="previewStageTotal" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px dashed var(--border-color); padding-top: 0.5rem; color: var(--text-secondary);">
                        <span>Combined Subtotal:</span>
                        <span id="summarySubtotal" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                        <span>Discount Applied:</span>
                        <span id="summaryDiscount" style="font-weight: 600; color: #dc2626;">-Rs. 0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--text-secondary); border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                        <span>Taxable Value:</span>
                        <span id="summaryTaxable" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                        <span>GST Tax Amount:</span>
                        <span id="summaryTax" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00 (0%)</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.75rem; font-size: 1.1rem;">
                        <strong style="color: var(--text-primary);">Final Invoice Total:</strong>
                        <strong id="summaryGrandTotal" style="color: var(--accent-color);">Rs. 0.00</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--text-secondary); border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                        <span>Advance Paid:</span>
                        <span id="summaryPaid" style="font-weight: 600; color: #16a34a;">Rs. 0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.75rem; font-size: 1.15rem; background: rgba(220, 38, 38, 0.05); padding: 0.75rem; border-radius: var(--border-radius-md); margin-top: 0.5rem;">
                        <strong style="color: #dc2626;">Balance Due:</strong>
                        <strong id="summaryBalance" style="color: #dc2626;">Rs. 0.00</strong>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;">
                    <i class="fa-solid fa-circle-info" style="color: var(--accent-color); margin-right: 0.25rem;"></i>
                    Note: Adjusting items or checking dishes will instantly recompute the preview invoice values in real-time.
                </div>
            </div>

            <!-- Selected Stage Work Order Preview Card -->
            <div class="card" style="padding: 1.5rem; background: var(--bg-body); border: 1px solid var(--border-color); margin-top: 1.5rem;">
                <h4 style="margin-bottom: 0.5rem; font-size: 0.85rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                    <i class="fa-solid fa-list-ol" style="color: var(--accent-color);"></i> Selected Stage Work Order
                </h4>
                <div id="summaryStageList" style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.8rem; color: var(--text-primary); max-height: 250px; overflow-y: auto;">
                    <span style="color: var(--text-muted); font-style: italic;">No stage items selected yet.</span>
                </div>
            </div>

            <!-- Selected Dishes Order Preview Card -->
            <div class="card" style="padding: 1.5rem; background: var(--bg-body); border: 1px solid var(--border-color); margin-top: 1.5rem;">
                <h4 style="margin-bottom: 0.5rem; font-size: 0.85rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                    <i class="fa-solid fa-utensils" style="color: var(--accent-color);"></i> Selected Dishes Order
                </h4>
                <div id="editInvoiceDishesList" style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.8rem; color: var(--text-primary); max-height: 250px; overflow-y: auto;">
                    <span style="color: var(--text-muted); font-style: italic;">No dishes selected yet.</span>
                </div>
            </div>
        </div>
        
    </div>
</form>

<script>
function updateStageRowTotal(itemId) {
    const qtyInput = document.querySelector(`.stage-qty[name="stage_quantities[${itemId}]"]`);
    const unitInput = document.querySelector(`.stage-unit-price[name="stage_unit_prices[${itemId}]"]`);
    const priceInput = document.querySelector(`.stage-price[name="stage_custom_prices[${itemId}]"]`);
    
    if (qtyInput && unitInput && priceInput) {
        const qty = parseInt(qtyInput.value) || 1;
        const unit = parseFloat(unitInput.value);
        if (!isNaN(unit) && unit >= 0) {
            priceInput.value = (qty * unit).toFixed(2);
        }
    }
    calculateSummary();
}

let selectionOrder = <?= json_encode($initial_selected_dish_ids) ?>;

function updateHiddenDishesInputs() {
    let container = document.getElementById('hiddenDishesContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'hiddenDishesContainer';
        const form = document.getElementById('invoiceForm');
        if (form) form.appendChild(container);
    }
    container.innerHTML = '';
    selectionOrder.forEach(dishId => {
        const chk = document.querySelector(`.dish-chk[value="${dishId}"]`);
        if (chk && chk.checked) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'dishes[]';
            hidden.value = dishId;
            container.appendChild(hidden);
        }
    });
}

function toggleDishSelection(chk) {
    const dishId = String(chk.value);
    if (chk.checked) {
        if (!selectionOrder.includes(dishId)) {
            selectionOrder.push(dishId);
        }
    } else {
        selectionOrder = selectionOrder.filter(id => id !== dishId);
    }
    togglePlatesInput(chk);
    updateHiddenDishesInputs();
    renderDishesPreview();
}

function moveDish(dishId, direction) {
    dishId = String(dishId);
    const index = selectionOrder.indexOf(dishId);
    if (index === -1) return;
    
    if (direction === 'up' && index > 0) {
        const temp = selectionOrder[index];
        selectionOrder[index] = selectionOrder[index - 1];
        selectionOrder[index - 1] = temp;
    } else if (direction === 'down' && index < selectionOrder.length - 1) {
        const temp = selectionOrder[index];
        selectionOrder[index] = selectionOrder[index + 1];
        selectionOrder[index + 1] = temp;
    }
    
    updateHiddenDishesInputs();
    renderDishesPreview();
}

function renderDishesPreview() {
    let categoryOrder = [];
    document.querySelectorAll('.dish-chk').forEach(chk => {
        const cat = chk.getAttribute('data-category');
        if (cat && !categoryOrder.includes(cat)) {
            categoryOrder.push(cat);
        }
    });

    let selectedDishesGrouped = {};
    categoryOrder.forEach(cat => {
        selectionOrder.forEach(dishId => {
            const chk = document.querySelector(`.dish-chk[value="${dishId}"]`);
            if (chk && chk.checked && chk.getAttribute('data-category') === cat) {
                const dName = chk.getAttribute('data-dishname');
                const parent = chk.closest('div');
                const pInput = parent ? parent.querySelector('.dish-plates-input') : null;
                const rInput = parent ? parent.querySelector('.dish-rate-input') : null;
                const platesVal = pInput ? pInput.value : '';
                const rateVal = rInput ? rInput.value : '';
                
                if (!selectedDishesGrouped[cat]) {
                    selectedDishesGrouped[cat] = [];
                }
                selectedDishesGrouped[cat].push({ id: dishId, name: dName, plates: platesVal, rate: rateVal });
            }
        });
    });
    
    let htmlOutput = '';
    const keys = Object.keys(selectedDishesGrouped);
    if (keys.length === 0) {
        htmlOutput = '<span style="color: var(--text-muted); font-style: italic;">No dishes selected yet.</span>';
    } else {
        keys.forEach(cat => {
            htmlOutput += `<div style="margin-top: 0.5rem;"><strong style="color: var(--accent-color); font-size: 0.75rem; text-transform: uppercase;">${cat}</strong></div>`;
            const catDishes = selectedDishesGrouped[cat];
            catDishes.forEach((d, idx) => {
                let specs = [];
                if (d.plates && parseInt(d.plates) > 0) specs.push(`${d.plates} Plates`);
                if (d.rate && parseFloat(d.rate) > 0) specs.push(`Rs. ${d.rate}`);
                const platesSuffix = specs.length > 0 ? ` (${specs.join(' x ')})` : '';
                const isFirst = idx === 0;
                const isLast = idx === catDishes.length - 1;
                htmlOutput += `<div class="preview-dish-row" data-dish-id="${d.id}" draggable="true" style="display: flex; align-items: center; justify-content: space-between; padding-left: 0.5rem; border-left: 2px solid var(--accent-color); margin-top: 0.25rem; font-size: 0.8rem; cursor: move;">
                    <span><i class="fa-solid fa-grip-vertical" style="color: var(--text-muted); margin-right: 0.35rem; font-size: 0.75rem;"></i> ${d.name}${platesSuffix}</span>
                    <div style="display: flex; gap: 0.2rem;">
                        <button type="button" onclick="moveDish('${d.id}', 'up')" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-primary); cursor: pointer; padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.7rem;" ${isFirst ? 'disabled style="opacity:0.3; cursor:default;"' : ''} title="Move Up"><i class="fa-solid fa-arrow-up"></i></button>
                        <button type="button" onclick="moveDish('${d.id}', 'down')" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-primary); cursor: pointer; padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.7rem;" ${isLast ? 'disabled style="opacity:0.3; cursor:default;"' : ''} title="Move Down"><i class="fa-solid fa-arrow-down"></i></button>
                    </div>
                </div>`;
            });
        });
    }
    const editDishesList = document.getElementById('editInvoiceDishesList');
    if (editDishesList) {
        editDishesList.innerHTML = htmlOutput;
        initEditInvoicePreviewDishDrag();
    }
}

function initEditInvoicePreviewDishDrag() {
    const list = document.getElementById('editInvoiceDishesList');
    if (!list) return;

    let draggedRow = null;

    list.querySelectorAll('.preview-dish-row').forEach(row => {
        row.addEventListener('dragstart', function (e) {
            draggedRow = this;
            this.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
        });

        row.addEventListener('dragend', function () {
            this.style.opacity = '1';
            draggedRow = null;
        });

        row.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (this !== draggedRow && draggedRow) {
                const parent = this.parentNode;
                if (parent === draggedRow.parentNode) {
                    const rect = this.getBoundingClientRect();
                    const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
                    parent.insertBefore(draggedRow, next ? this.nextSibling : this);
                    
                    const newOrder = [];
                    list.querySelectorAll('.preview-dish-row').forEach(r => {
                        newOrder.push(r.getAttribute('data-dish-id'));
                    });
                    selectionOrder = newOrder;
                    updateHiddenDishesInputs();
                }
            }
        });
    });
}

function togglePlatesInput(chk) {
    const parent = chk.closest('div');
    const wrap = parent ? parent.querySelector('.dish-inputs-wrap') : null;
    if (wrap) {
        if (chk.checked) {
            wrap.style.display = 'flex';
        } else {
            wrap.style.display = 'none';
            const pInput = wrap.querySelector('.dish-plates-input');
            const rInput = wrap.querySelector('.dish-rate-input');
            if (pInput) pInput.value = '';
            if (rInput) rInput.value = '';
        }
    }
}

// Stage Work Drag & Drop Reordering Logic
let stageSelectionOrder = <?= json_encode($initial_selected_stage_ids) ?>;

function updateHiddenStageInputs() {
    let container = document.getElementById('hiddenStageContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'hiddenStageContainer';
        const form = document.getElementById('invoiceForm') || document.querySelector('form');
        if (form) form.appendChild(container);
    }
    container.innerHTML = '';
    stageSelectionOrder.forEach(itemId => {
        const chk = document.querySelector(`.stage-chk[value="${itemId}"]`);
        if (chk && chk.checked) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'stage_items[]';
            hidden.value = itemId;
            container.appendChild(hidden);
        }
    });
}

function toggleStageSelection(chk) {
    const itemId = String(chk.value);
    if (chk.checked) {
        if (!stageSelectionOrder.includes(itemId)) {
            stageSelectionOrder.push(itemId);
        }
    } else {
        stageSelectionOrder = stageSelectionOrder.filter(id => id !== itemId);
    }
    updateHiddenStageInputs();
    calculateSummary();
}

function moveStageItem(itemId, direction) {
    itemId = String(itemId);
    const index = stageSelectionOrder.indexOf(itemId);
    if (index === -1) return;
    
    if (direction === 'up' && index > 0) {
        const temp = stageSelectionOrder[index];
        stageSelectionOrder[index] = stageSelectionOrder[index - 1];
        stageSelectionOrder[index - 1] = temp;
    } else if (direction === 'down' && index < stageSelectionOrder.length - 1) {
        const temp = stageSelectionOrder[index];
        stageSelectionOrder[index] = stageSelectionOrder[index + 1];
        stageSelectionOrder[index + 1] = temp;
    }
    
    updateHiddenStageInputs();
    calculateSummary();
}

function renderStagePreview() {
    let selectedItems = [];
    stageSelectionOrder.forEach(itemId => {
        const chk = document.querySelector(`.stage-chk[value="${itemId}"]`);
        if (chk && chk.checked) {
            const parent = chk.closest('div');
            const nameSpan = chk.parentNode ? chk.parentNode.querySelector('span') : null;
            const itemName = nameSpan ? nameSpan.textContent.trim() : 'Stage Item';
            const qtyInput = parent ? parent.querySelector('.stage-qty') : null;
            const unitInput = parent ? parent.querySelector('.stage-unit-price') : null;
            const priceInput = parent ? parent.querySelector('.stage-price') : null;
            
            const qtyVal = qtyInput ? qtyInput.value : '1';
            const unitVal = unitInput ? unitInput.value : '';
            const priceVal = priceInput ? priceInput.value : '0';
            
            selectedItems.push({
                id: itemId,
                name: itemName,
                qty: qtyVal,
                unit: unitVal,
                price: priceVal
            });
        }
    });
    
    let htmlOutput = '';
    if (selectedItems.length === 0) {
        htmlOutput = '<span style="color: var(--text-muted); font-style: italic;">No stage items selected yet.</span>';
    } else {
        selectedItems.forEach((item, idx) => {
            let specs = [];
            if (item.qty && parseInt(item.qty) > 1) specs.push(`${item.qty} nos`);
            if (item.unit && parseFloat(item.unit) > 0) specs.push(`Rs. ${item.unit}`);
            let specSuffix = specs.length > 0 ? ` (${specs.join(' x ')})` : '';
            if (parseFloat(item.price) > 0) specSuffix += ` - Rs. ${item.price}`;
            
            const isFirst = idx === 0;
            const isLast = idx === selectedItems.length - 1;
            htmlOutput += `<div class="preview-stage-row" data-stage-id="${item.id}" draggable="true" style="display: flex; align-items: center; justify-content: space-between; padding-left: 0.5rem; border-left: 2px solid var(--accent-color); margin-top: 0.25rem; font-size: 0.8rem; cursor: move;">
                <span><i class="fa-solid fa-grip-vertical" style="color: var(--text-muted); margin-right: 0.35rem; font-size: 0.75rem;"></i> ${item.name}${specSuffix}</span>
                <div style="display: flex; gap: 0.2rem;">
                    <button type="button" onclick="moveStageItem('${item.id}', 'up')" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-primary); cursor: pointer; padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.7rem;" ${isFirst ? 'disabled style="opacity:0.3; cursor:default;"' : ''} title="Move Up"><i class="fa-solid fa-arrow-up"></i></button>
                    <button type="button" onclick="moveStageItem('${item.id}', 'down')" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-primary); cursor: pointer; padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.7rem;" ${isLast ? 'disabled style="opacity:0.3; cursor:default;"' : ''} title="Move Down"><i class="fa-solid fa-arrow-down"></i></button>
                </div>
            </div>`;
        });
    }
    const stageSummaryList = document.getElementById('summaryStageList');
    if (stageSummaryList) {
        stageSummaryList.innerHTML = htmlOutput;
        initStagePreviewDrag();
    }
}

function initStagePreviewDrag() {
    const list = document.getElementById('summaryStageList');
    if (!list) return;

    let draggedRow = null;

    list.querySelectorAll('.preview-stage-row').forEach(row => {
        row.addEventListener('dragstart', function (e) {
            draggedRow = this;
            this.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
        });

        row.addEventListener('dragend', function () {
            this.style.opacity = '1';
            draggedRow = null;
        });

        row.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (this !== draggedRow && draggedRow) {
                const parent = this.parentNode;
                if (parent === draggedRow.parentNode) {
                    const rect = this.getBoundingClientRect();
                    const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
                    parent.insertBefore(draggedRow, next ? this.nextSibling : this);
                    
                    const newOrder = [];
                    list.querySelectorAll('.preview-stage-row').forEach(r => {
                        newOrder.push(r.getAttribute('data-stage-id'));
                    });
                    stageSelectionOrder = newOrder;
                    updateHiddenStageInputs();
                }
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const totalPlatesInput = document.getElementById('total_plates');
    const perPlatePriceInput = document.getElementById('per_plate_price');
    const stageCheckboxes = document.querySelectorAll('.stage-chk');
    const stagePriceInputs = document.querySelectorAll('.stage-price');
    
    const discountInput = document.getElementById('discount');
    const taxRateInput = document.getElementById('tax_rate');
    const paidInput = document.getElementById('advance_received');
    
    const previewCateringTotal = document.getElementById('previewCateringTotal');
    const previewStageTotal = document.getElementById('previewStageTotal');
    const summarySubtotal = document.getElementById('summarySubtotal');
    
    const summaryDiscount = document.getElementById('summaryDiscount');
    const summaryTaxable = document.getElementById('summaryTaxable');
    const summaryTax = document.getElementById('summaryTax');
    const summaryGrandTotal = document.getElementById('summaryGrandTotal');
    const summaryPaid = document.getElementById('summaryPaid');
    const summaryBalance = document.getElementById('summaryBalance');
    
    function calculate() {
        const plates = parseFloat(totalPlatesInput.value) || 0;
        const rate = parseFloat(perPlatePriceInput.value) || 0;
        let cateringTotal = plates * rate;

        document.querySelectorAll('.dish-chk').forEach(chk => {
            if (chk.checked) {
                const parent = chk.closest('div');
                const pInput = parent ? parent.querySelector('.dish-plates-input') : null;
                const rInput = parent ? parent.querySelector('.dish-rate-input') : null;
                const pVal = pInput ? (parseInt(pInput.value) || 0) : 0;
                const rVal = rInput ? (parseFloat(rInput.value) || 0) : 0;
                if (rVal > 0) {
                    cateringTotal += (pVal > 0) ? (pVal * rVal) : rVal;
                }
            }
        });
        
        let stageTotal = 0;
        stageCheckboxes.forEach(chk => {
            if (chk.checked) {
                const parent = chk.closest('div');
                const priceInput = parent ? parent.querySelector('.stage-price') : null;
                const val = parseFloat(priceInput ? priceInput.value : 0) || 0;
                stageTotal += val;
            }
        });
        
        const subtotal = cateringTotal + stageTotal;
        
        previewCateringTotal.textContent = 'Rs. ' + cateringTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        previewStageTotal.textContent = 'Rs. ' + stageTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summarySubtotal.textContent = 'Rs. ' + subtotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        
        const discount = parseFloat(discountInput.value) || 0;
        const taxRate = parseFloat(taxRateInput.value) || 0;
        const paid = parseFloat(paidInput.value) || 0;
        
        const taxable = Math.max(0, subtotal - discount);
        const taxAmount = taxable * (taxRate / 100);
        const grandTotal = taxable + taxAmount;
        const balance = grandTotal - paid;
        
        summaryDiscount.textContent = '-Rs. ' + discount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryTaxable.textContent = 'Rs. ' + taxable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryTax.textContent = 'Rs. ' + taxAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (' + taxRate + '%)';
        summaryGrandTotal.textContent = 'Rs. ' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryPaid.textContent = 'Rs. ' + paid.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryBalance.textContent = 'Rs. ' + balance.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    // Listeners for triggers
    totalPlatesInput.addEventListener('input', calculate);
    perPlatePriceInput.addEventListener('input', calculate);
    stageCheckboxes.forEach(chk => chk.addEventListener('change', calculate));
    stagePriceInputs.forEach(input => input.addEventListener('input', calculate));
    
    document.querySelectorAll('.dish-chk').forEach(chk => chk.addEventListener('change', calculate));
    document.querySelectorAll('.dish-plates-input, .dish-rate-input').forEach(input => input.addEventListener('input', calculate));
    
    discountInput.addEventListener('input', calculate);
    taxRateInput.addEventListener('input', calculate);
    paidInput.addEventListener('input', calculate);
    
    const form = document.getElementById('invoiceForm');
    if (form) {
        form.addEventListener('submit', function() {
            updateHiddenDishesInputs();
            updateHiddenStageInputs();
        });
    }

    // Initial runs
    updateHiddenDishesInputs();
    renderDishesPreview();
    updateHiddenStageInputs();
    renderStagePreview();
    calculate();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
