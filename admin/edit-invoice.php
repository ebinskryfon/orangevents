<?php
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
$stmt_stage_sel = $db->prepare("SELECT stage_item_id, custom_price FROM event_stage_work WHERE event_id = :id");
$stmt_stage_sel->execute(['id' => $event_id]);
while ($row = $stmt_stage_sel->fetch()) {
    $selected_stage_items[$row['stage_item_id']] = $row['custom_price'];
}

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
    
    $stmt_dish_sel = $db->prepare("SELECT dish_id, plate_count FROM event_catering_dishes WHERE event_catering_id = :cat_id");
    $stmt_dish_sel->execute(['cat_id' => $loaded_catering['id']]);
    while ($row = $stmt_dish_sel->fetch()) {
        $selected_dishes[$row['dish_id']] = $row['plate_count'];
    }
}

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
    $stage_posted = $_POST['stage_items'] ?? [];
    $stage_prices = $_POST['stage_custom_prices'] ?? [];
    
    // 4. Invoice details
    $invoice_number = trim($_POST['invoice_number']);
    $template_name = trim($_POST['template_name']);
    $status = trim($_POST['status']);
    $discount = (float)$_POST['discount'];
    $tax_rate = (float)$_POST['tax_rate'];
    $advance_received = (float)$_POST['advance_received'];
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
                throw new Exception("Clash Alert: Venue is already booked for another event (\"{$clash_found['title']}\") on this date!");
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
            
            // 4. Update stage work
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
            
            // Calculate final financials
            $catering_total = $per_plate_price * $total_plates;
            $subtotal = $catering_total + $stage_total;
            
            $taxable = $subtotal - $discount;
            if ($taxable < 0) {
                $taxable = 0;
            }
            $tax_amount = $taxable * ($tax_rate / 100);
            $final_total = $taxable + $tax_amount;
            
            // 5. Update invoice
            $stmt_update = $db->prepare("UPDATE invoices SET 
                                            invoice_number = :num,
                                            subtotal = :subtotal,
                                            discount = :discount,
                                            tax_rate = :tax_rate,
                                            tax_amount = :tax_amount,
                                            final_total = :final_total,
                                            advance_received = :advance,
                                            payment_method = :method,
                                            status = :status,
                                            template_name = :temp,
                                            created_at = :created
                                         WHERE id = :id");
            $stmt_update->execute([
                'num' => $invoice_number,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => $tax_rate,
                'tax_amount' => $tax_amount,
                'final_total' => $final_total,
                'advance' => $advance_received,
                'method' => $payment_method,
                'status' => $status,
                'temp' => $template_name,
                'created' => $created_at . ' ' . date('H:i:s', strtotime($invoice['created_at'])),
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
                        $price_val = $is_checked ? $selected_stage_items[$item['id']] : $item['default_price'];
                        ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; background: rgba(255,255,255,0.02); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                            <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; flex: 1; margin: 0;">
                                <input type="checkbox" name="stage_items[]" value="<?= $item['id'] ?>" class="stage-chk" <?= $is_checked ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: var(--accent-color);">
                                <span style="font-weight: 500; font-size: 0.9rem; color: var(--text-primary);"><?= h($item['item_name']) ?></span>
                            </label>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Rate (Rs):</span>
                                <input type="number" step="0.01" name="stage_custom_prices[<?= $item['id'] ?>]" value="<?= $price_val ?>" class="form-control stage-price" style="width: 110px; padding: 0.35rem 0.5rem; font-size: 0.85rem;">
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
                        <label for="total_plates" class="form-label" style="font-weight: 600;">Total Plates *</label>
                        <input type="number" id="total_plates" name="total_plates" class="form-control" value="<?= h($catering_data['total_plates']) ?>" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="per_plate_price" class="form-label" style="font-weight: 600;">Rate Per Plate (Rs) *</label>
                        <input type="number" step="0.01" id="per_plate_price" name="per_plate_price" class="form-control" value="<?= h($catering_data['per_plate_price']) ?>" min="0" required>
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
                                        $is_checked = isset($selected_dishes[$dish['id']]);
                                        $p_val = $is_checked ? $selected_dishes[$dish['id']] : '';
                                        ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.35rem 0.5rem; background: rgba(255,255,255,0.02); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.8rem; flex: 1; margin: 0; color: var(--text-primary);">
                                                <input type="checkbox" name="dishes[]" value="<?= $dish['id'] ?>" class="dish-chk" <?= $is_checked ? 'checked' : '' ?> style="accent-color: var(--accent-color);" data-dishname="<?= h($dish['dish_name']) ?>" data-category="<?= h($cat['category_name']) ?>" onchange="togglePlatesInput(this)">
                                                <span><?= h($dish['dish_name']) ?></span>
                                            </label>
                                            <input type="number" name="dish_plates[<?= $dish['id'] ?>]" placeholder="Plates" class="form-control dish-plates-input" value="<?= h($p_val) ?>" style="width: 70px; padding: 0.2rem 0.4rem; font-size: 0.75rem; display: <?= $is_checked ? 'inline-block' : 'none' ?>;">
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
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                    <div class="form-group">
                        <label for="advance_received" class="form-label" style="font-weight: 600;">Advance Paid (Rs.)</label>
                        <input type="number" step="0.01" id="advance_received" name="advance_received" class="form-control" value="<?= (float)$invoice['advance_received'] ?>" min="0">
                    </div>
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
        </div>
        
    </div>
</form>

<script>
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
        const cateringTotal = plates * rate;
        
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
    
    discountInput.addEventListener('input', calculate);
    taxRateInput.addEventListener('input', calculate);
    paidInput.addEventListener('input', calculate);
    
    // Initial run
    calculate();
});

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
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
