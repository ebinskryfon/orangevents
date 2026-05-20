<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
check_admin_auth();

$db = get_db_connection();
$message = '';
$error   = '';
$order   = null;
$order_items = [];

$edit_id = (int)($_GET['id'] ?? 0);

// ── Load existing order for editing ──────────────────────────────────────────
if ($edit_id > 0) {
    $stmt = $db->prepare("SELECT * FROM rental_orders WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $order = $stmt->fetch();
    if (!$order) { header('Location: rentals.php'); exit; }

    $oi = $db->prepare("SELECT * FROM rental_order_items WHERE order_id = :id ORDER BY id ASC");
    $oi->execute(['id' => $edit_id]);
    $order_items = $oi->fetchAll();
}

// ── POST: Save order ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_name  = trim($_POST['client_name']  ?? '');
    $client_phone = trim($_POST['client_phone'] ?? '');
    $client_email = trim($_POST['client_email'] ?? '');
    $client_addr  = trim($_POST['client_address'] ?? '');
    $event_name   = trim($_POST['event_name']   ?? '');
    $start_date   = $_POST['rental_start_date'] ?? '';
    $end_date     = $_POST['rental_end_date']   ?? '';
    $discount     = (float)($_POST['discount']  ?? 0);
    $notes        = trim($_POST['notes']        ?? '');
    $items_json   = $_POST['items_json']        ?? '[]';
    $items        = json_decode($items_json, true) ?: [];

    // Advance payment fields (only for new orders)
    $adv_amount = (float)($_POST['adv_amount'] ?? 0);
    $adv_method = $_POST['adv_method'] ?? 'cash';
    $adv_date   = $_POST['adv_date']   ?? date('Y-m-d');
    $adv_ref    = trim($_POST['adv_ref'] ?? '');

    if (empty($client_name) || empty($client_phone) || empty($start_date) || empty($end_date)) {
        $error = 'Client name, phone, and rental dates are required.';
    } elseif (empty($items)) {
        $error = 'Please add at least one rental item.';
    } else {
        $num_days = max(1, (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
        $subtotal = array_sum(array_column($items, 'subtotal'));
        $total    = max(0, $subtotal - $discount);

        if ($edit_id > 0) {
            // For edits, recalculate advance_paid from existing payments
            $adv_paid = (float)$db->query("SELECT IFNULL(SUM(amount),0) FROM rental_payments WHERE order_id=$edit_id")->fetchColumn();
            $balance  = max(0, $total - $adv_paid);

            $db->prepare("UPDATE rental_orders SET
                client_name=:cn, client_phone=:cp, client_email=:ce, client_address=:ca,
                event_name=:en, rental_start_date=:sd, rental_end_date=:ed, num_days=:nd,
                subtotal=:sub, discount=:disc, total_amount=:tot,
                advance_paid=:adv, balance_due=:bal, notes=:notes
                WHERE id=:id")
            ->execute([
                'cn'=>$client_name,'cp'=>$client_phone,'ce'=>$client_email,'ca'=>$client_addr,
                'en'=>$event_name,'sd'=>$start_date,'ed'=>$end_date,'nd'=>$num_days,
                'sub'=>$subtotal,'disc'=>$discount,'tot'=>$total,
                'adv'=>$adv_paid,'bal'=>$balance,'notes'=>$notes,'id'=>$edit_id,
            ]);
            $current_status = $db->query("SELECT status FROM rental_orders WHERE id=$edit_id")->fetchColumn();
            
            if ($current_status === 'active' || $current_status === 'overdue') {
                // Restore stock for old items before deleting them
                $old_items = $db->query("SELECT rental_item_id, quantity FROM rental_order_items WHERE order_id=$edit_id AND rental_item_id IS NOT NULL")->fetchAll();
                $upd_restore = $db->prepare("UPDATE rental_items SET quantity_in_stock = quantity_in_stock + :qty WHERE id = :id");
                foreach($old_items as $oi) {
                    $upd_restore->execute(['qty' => $oi['quantity'], 'id' => $oi['rental_item_id']]);
                }
            }

            $db->prepare("DELETE FROM rental_order_items WHERE order_id=:id")->execute(['id'=>$edit_id]);
            $order_id = $edit_id;
            $message  = 'Order updated successfully!';
        } else {
            // New order
            $seq          = (int)$db->query("SELECT COUNT(*) FROM rental_orders")->fetchColumn() + 1;
            $order_number = 'RENT-' . date('Y') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $balance      = max(0, $total - $adv_amount);

            $db->prepare("INSERT INTO rental_orders
                (order_number, client_name, client_phone, client_email, client_address,
                 event_name, rental_start_date, rental_end_date, num_days,
                 subtotal, discount, total_amount, advance_paid, balance_due, status, notes)
                VALUES
                (:on,:cn,:cp,:ce,:ca,:en,:sd,:ed,:nd,:sub,:disc,:tot,:adv,:bal,'draft',:notes)")
            ->execute([
                'on'=>$order_number,'cn'=>$client_name,'cp'=>$client_phone,
                'ce'=>$client_email,'ca'=>$client_addr,'en'=>$event_name,
                'sd'=>$start_date,'ed'=>$end_date,'nd'=>$num_days,
                'sub'=>$subtotal,'disc'=>$discount,'tot'=>$total,
                'adv'=>$adv_amount,'bal'=>$balance,'notes'=>$notes,
            ]);
            $order_id = (int)$db->lastInsertId();

            if ($adv_amount > 0) {
                $db->prepare("INSERT INTO rental_payments
                    (order_id, amount, payment_type, payment_method, payment_date, reference_number)
                    VALUES (:oid,:amt,'advance',:meth,:dt,:ref)")
                ->execute(['oid'=>$order_id,'amt'=>$adv_amount,'meth'=>$adv_method,'dt'=>$adv_date,'ref'=>$adv_ref]);
            }
            $message = 'Rental order created!';
        }

        // Save line items
        $item_stmt = $db->prepare("INSERT INTO rental_order_items
            (order_id, rental_item_id, item_name, daily_rate, quantity, num_days, subtotal)
            VALUES (:oid,:riid,:name,:rate,:qty,:days,:sub)");
            
        $upd_stock = null;
        if (isset($current_status) && ($current_status === 'active' || $current_status === 'overdue')) {
            $upd_stock = $db->prepare("UPDATE rental_items SET quantity_in_stock = quantity_in_stock - :qty WHERE id = :id");
        }

        foreach ($items as $it) {
            $riid = $it['rental_item_id'] ?: null;
            $qty  = (int)$it['quantity'];
            $item_stmt->execute([
                'oid'  => $order_id,
                'riid' => $riid,
                'name' => $it['item_name'],
                'rate' => (float)$it['daily_rate'],
                'qty'  => $qty,
                'days' => (int)$it['num_days'],
                'sub'  => (float)$it['subtotal'],
            ]);
            
            if ($upd_stock && $riid) {
                $upd_stock->execute(['qty' => $qty, 'id' => $riid]);
            }
        }

        // Safe to redirect — no HTML sent yet
        header("Location: view-rental.php?id=$order_id&msg=" . urlencode($message));
        exit;
    }
}

// ── Include header (outputs HTML) AFTER all redirects ────────────────────────
require_once __DIR__ . '/../includes/header.php';

// ── Fetch rental catalogue for picker ────────────────────────────────────────
$catalogue = $db->query("SELECT ri.id, ri.item_name, ri.daily_rate, ri.quantity_in_stock, rc.category_name
    FROM rental_items ri
    JOIN rental_categories rc ON rc.id = ri.category_id
    WHERE ri.is_active = 1
    ORDER BY rc.display_order ASC, ri.item_name ASC")->fetchAll();
$catalogue_json = json_encode($catalogue);

$existing_items_json = json_encode(array_map(fn($i) => [
    'rental_item_id' => $i['rental_item_id'],
    'item_name'      => $i['item_name'],
    'daily_rate'     => (float)$i['daily_rate'],
    'quantity'       => (int)$i['quantity'],
    'num_days'       => (int)$i['num_days'],
    'subtotal'       => (float)$i['subtotal'],
], $order_items));
?>

<div class="content-header">
    <div class="header-title">
        <h1><?= $edit_id ? 'Edit Rental Order' : 'New Rental Order' ?></h1>
        <p><?= $edit_id ? 'Update booking details and items.' : 'Create a new rental booking for a client.' ?></p>
    </div>
    <a href="rentals.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Orders
    </a>
</div>

<?php if ($error): ?>
<div style="background:rgba(255,71,87,0.15);color:var(--danger);border:1px solid var(--danger);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
    <i class="fa-solid fa-triangle-exclamation"></i> <?= h($error) ?>
</div>
<?php endif; ?>

<form method="POST" id="rentalForm">
    <input type="hidden" name="items_json" id="items_json" value="">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

        <!-- Client Details -->
        <div class="card">
            <h3 style="margin-bottom:1.25rem;font-size:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-user" style="color:var(--accent-color);"></i> Client Details
            </h3>
            <div class="form-group">
                <label class="form-label">Client Name *</label>
                <input type="text" name="client_name" class="form-control" required value="<?= h($order['client_name'] ?? '') ?>" placeholder="Full name">
            </div>
            <div class="form-group">
                <label class="form-label">Phone *</label>
                <input type="tel" name="client_phone" class="form-control" required value="<?= h($order['client_phone'] ?? '') ?>" placeholder="9876543210">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="client_email" class="form-control" value="<?= h($order['client_email'] ?? '') ?>" placeholder="optional">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Address</label>
                <textarea name="client_address" class="form-control" rows="2" placeholder="Client address"><?= h($order['client_address'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Event & Dates -->
        <div class="card">
            <h3 style="margin-bottom:1.25rem;font-size:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-calendar-days" style="color:var(--accent-color);"></i> Event & Rental Period
            </h3>
            <div class="form-group">
                <label class="form-label">Event / Occasion Name</label>
                <input type="text" name="event_name" class="form-control" value="<?= h($order['event_name'] ?? '') ?>" placeholder="e.g. Wedding – Raju & Priya">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Rental Start Date *</label>
                    <input type="date" id="startDate" name="rental_start_date" class="form-control" required value="<?= h($order['rental_start_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Rental End Date *</label>
                    <input type="date" id="endDate" name="rental_end_date" class="form-control" required value="<?= h($order['rental_end_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
            <div style="padding:0.6rem 1rem;background:rgba(255,107,53,0.08);border-radius:var(--border-radius-sm);border:1px solid var(--border-highlight);display:flex;align-items:center;gap:0.75rem;">
                <i class="fa-solid fa-clock" style="color:var(--accent-color);"></i>
                <span style="font-weight:600;">Rental Duration: <span id="daysDisplay" style="color:var(--accent-color);">—</span> day(s)</span>
            </div>
            <div class="form-group" style="margin-top:1rem;margin-bottom:0;">
                <label class="form-label">Notes / Special Instructions</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Delivery instructions, special requirements…"><?= h($order['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Items Section -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <h3 style="font-size:1rem;display:flex;align-items:center;gap:0.5rem;margin:0;">
                <i class="fa-solid fa-boxes-stacked" style="color:var(--accent-color);"></i> Rental Items
            </h3>
            <button type="button" onclick="openItemPicker()" class="btn btn-secondary" style="padding:0.45rem 1rem;font-size:0.875rem;">
                <i class="fa-solid fa-plus"></i> Add Item
            </button>
        </div>

        <div class="table-responsive">
            <table class="table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:35%;">Item</th>
                        <th style="width:15%;text-align:center;">Daily Rate</th>
                        <th style="width:12%;text-align:center;">Qty</th>
                        <th style="width:12%;text-align:center;">Days</th>
                        <th style="width:15%;text-align:right;">Subtotal</th>
                        <th style="width:11%;text-align:center;">Remove</th>
                    </tr>
                </thead>
                <tbody id="itemsTbody">
                    <tr id="emptyRow">
                        <td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted);">
                            No items added. Click <strong>Add Item</strong> above.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pricing & Payment -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

        <?php if (!$edit_id): ?>
        <!-- Advance Payment (new orders only) -->
        <div class="card">
            <h3 style="margin-bottom:1.25rem;font-size:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-hand-holding-dollar" style="color:var(--accent-color);"></i> Initial Advance Payment
            </h3>
            <div class="form-group">
                <label class="form-label">Advance Amount (Rs)</label>
                <input type="number" step="0.01" min="0" name="adv_amount" id="advAmount" class="form-control" placeholder="0" value="0">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="adv_method" class="form-control">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input type="date" name="adv_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Reference / UPI ID</label>
                <input type="text" name="adv_ref" class="form-control" placeholder="Optional – UPI txn, cheque no.">
            </div>
        </div>
        <?php else: ?>
        <div class="card" style="display:flex;align-items:center;justify-content:center;flex-direction:column;gap:0.5rem;color:var(--text-muted);text-align:center;">
            <i class="fa-solid fa-receipt" style="font-size:2rem;opacity:0.4;"></i>
            <p style="font-size:0.85rem;">Payments are managed from the<br><a href="view-rental.php?id=<?= $edit_id ?>" style="color:var(--accent-color);">Order Details page</a></p>
        </div>
        <?php endif; ?>

        <!-- Pricing Summary -->
        <div class="card">
            <h3 style="margin-bottom:1.25rem;font-size:1rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-calculator" style="color:var(--accent-color);"></i> Pricing Summary
            </h3>
            <div style="display:flex;flex-direction:column;gap:0.6rem;">
                <div style="display:flex;justify-content:space-between;padding:0.5rem 0;border-bottom:1px solid var(--border-color);">
                    <span style="color:var(--text-secondary);">Subtotal</span>
                    <span id="displaySubtotal" style="font-weight:600;">Rs. 0</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border-color);">
                    <span style="color:var(--text-secondary);">Discount (Rs)</span>
                    <input type="number" step="0.01" min="0" name="discount" id="discountInput"
                           class="form-control" style="width:120px;text-align:right;padding:0.4rem 0.75rem;"
                           value="<?= h($order['discount'] ?? '0') ?>" placeholder="0">
                </div>
                <div style="display:flex;justify-content:space-between;padding:0.75rem 0;font-size:1.15rem;font-weight:800;color:var(--accent-color);">
                    <span>Total</span>
                    <span id="displayTotal">Rs. 0</span>
                </div>
            </div>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
        <a href="rentals.php" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk"></i>
            <?= $edit_id ? 'Update Order' : 'Create Rental Order' ?>
        </button>
    </div>
</form>

<!-- Item Picker Modal -->
<div id="itemPickerModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <button class="modal-close" onclick="closeModal('itemPickerModal')">&times;</button>
        <h3 style="margin-bottom:1rem;"><i class="fa-solid fa-box-open" style="color:var(--accent-color);"></i> Select Rental Item</h3>
        <input type="text" id="pickerSearch" class="form-control" placeholder="Search items…" style="margin-bottom:1rem;">
        <div id="pickerList" style="max-height:380px;overflow-y:auto;display:flex;flex-direction:column;gap:0.4rem;"></div>
    </div>
</div>

<script>
const CATALOGUE   = <?= $catalogue_json ?>;
let rentalItems   = <?= $existing_items_json ?: '[]' ?>;
let numDays       = 1;

// ── Date helpers ──────────────────────────────────────────────────────────────
function calcDays() {
    const s = new Date(document.getElementById('startDate').value);
    const e = new Date(document.getElementById('endDate').value);
    if (isNaN(s) || isNaN(e) || e < s) { numDays = 1; }
    else { numDays = Math.round((e - s) / 86400000) + 1; }
    document.getElementById('daysDisplay').textContent = numDays;
    // Update days on all existing rows
    rentalItems = rentalItems.map(it => {
        it.num_days = numDays;
        it.subtotal = +(it.daily_rate * it.quantity * numDays).toFixed(2);
        return it;
    });
    renderItems();
}
document.getElementById('startDate').addEventListener('change', calcDays);
document.getElementById('endDate').addEventListener('change',   calcDays);

// ── Item rendering ────────────────────────────────────────────────────────────
function renderItems() {
    const tbody = document.getElementById('itemsTbody');

    if (rentalItems.length === 0) {
        tbody.innerHTML = `<tr>
            <td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted);">
                No items added. Click <strong>Add Item</strong> above.
            </td>
        </tr>`;
        updateTotals();
        return;
    }

    tbody.innerHTML = rentalItems.map((it, idx) => `
        <tr>
            <td style="font-weight:600;">${escHtml(it.item_name)}</td>
            <td style="text-align:center;color:var(--accent-color);">Rs. ${(+it.daily_rate).toLocaleString()}</td>
            <td style="text-align:center;">
                <input type="number" min="1" value="${it.quantity}"
                       onchange="updateQty(${idx}, this.value)"
                       style="width:60px;text-align:center;padding:0.3rem;background:var(--bg-control);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary);">
            </td>
            <td style="text-align:center;color:var(--text-secondary);">${it.num_days}</td>
            <td style="text-align:right;font-weight:700;">Rs. ${(+it.subtotal).toLocaleString()}</td>
            <td style="text-align:center;">
                <button type="button" onclick="removeItem(${idx})"
                        style="background:rgba(255,71,87,0.15);border:none;color:var(--danger);border-radius:6px;padding:0.3rem 0.6rem;cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </td>
        </tr>`).join('');
    updateTotals();
}

function escHtml(t){ const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

function updateQty(idx, val) {
    const qty = Math.max(1, parseInt(val) || 1);
    rentalItems[idx].quantity = qty;
    rentalItems[idx].subtotal = +(rentalItems[idx].daily_rate * qty * rentalItems[idx].num_days).toFixed(2);
    renderItems();
}

function removeItem(idx) {
    rentalItems.splice(idx, 1);
    renderItems();
}

function updateTotals() {
    const sub  = rentalItems.reduce((s, it) => s + (+it.subtotal), 0);
    const disc = parseFloat(document.getElementById('discountInput').value) || 0;
    const tot  = Math.max(0, sub - disc);
    document.getElementById('displaySubtotal').textContent = 'Rs. ' + sub.toLocaleString();
    document.getElementById('displayTotal').textContent    = 'Rs. ' + tot.toLocaleString();
    document.getElementById('items_json').value = JSON.stringify(rentalItems);
}
document.getElementById('discountInput').addEventListener('input', updateTotals);

// ── Picker modal ──────────────────────────────────────────────────────────────
function renderPicker(filter) {
    const list  = document.getElementById('pickerList');
    const lower = (filter || '').toLowerCase();
    const items = CATALOGUE.filter(c => c.item_name.toLowerCase().includes(lower) || c.category_name.toLowerCase().includes(lower));
    list.innerHTML = items.length === 0
        ? '<p style="text-align:center;color:var(--text-muted);padding:1.5rem;">No items found.</p>'
        : items.map((c, i) => {
            const outOfStock = c.quantity_in_stock <= 0;
            return `
            <div class="picker-row"
                 data-idx="${i}"
                 data-stock="${c.quantity_in_stock}"
                 style="display:flex;justify-content:space-between;align-items:center;
                        padding:0.75rem 1rem;border-radius:var(--border-radius-sm);
                        border:1px solid var(--border-color);
                        cursor:${outOfStock ? 'not-allowed' : 'pointer'};
                        opacity:${outOfStock ? '0.6' : '1'};
                        transition:var(--transition-fast);"
                 ${!outOfStock ? `onmouseover="this.style.borderColor='var(--accent-color)';this.style.background='rgba(255,107,53,0.06)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.background=''"` : ''}>
                <div>
                    <div style="font-weight:600;color:var(--text-primary);">${escHtml(c.item_name)}</div>
                    <div style="font-size:0.78rem;color:var(--text-muted);">
                        ${escHtml(c.category_name)} 
                        ${outOfStock ? '<span style="color:var(--warning);font-weight:bold;margin-left:5px;">(Out of Stock)</span>' : `<span style="color:var(--success);margin-left:5px;">(${c.quantity_in_stock} available)</span>`}
                    </div>
                </div>
                <span style="font-weight:700;color:var(--accent-color);">Rs. ${(+c.daily_rate).toLocaleString()}/day</span>
            </div>`;
        }).join('');

    // Store the filtered list so the delegated handler can look up items by index
    list._filtered = items;
}

// ── Event delegation — one listener, no inline onclick ────────────────────────
document.getElementById('pickerList').addEventListener('click', function(e) {
    const row = e.target.closest('.picker-row');
    if (!row || !this._filtered) return;
    const c = this._filtered[parseInt(row.dataset.idx)];
    if (c && c.quantity_in_stock > 0) addItem(c.id, c.item_name, c.daily_rate);
});

document.getElementById('pickerSearch').addEventListener('input', function(){ renderPicker(this.value); });

// Dedicated opener so app.js cannot overwrite it
function openItemPicker() {
    document.getElementById('pickerSearch').value = '';
    renderPicker('');
    openModal('itemPickerModal');
}

function addItem(id, name, rate) {
    const existing = rentalItems.findIndex(i => i.rental_item_id == id);
    if (existing >= 0) {
        rentalItems[existing].quantity++;
        rentalItems[existing].subtotal = +(rentalItems[existing].daily_rate * rentalItems[existing].quantity * rentalItems[existing].num_days).toFixed(2);
    } else {
        rentalItems.push({ rental_item_id: id, item_name: name, daily_rate: +rate, quantity: 1, num_days: numDays, subtotal: +(rate * numDays) });
    }
    closeModal('itemPickerModal');
    renderItems();
}

// ── Init ──────────────────────────────────────────────────────────────────────
calcDays();
renderItems();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
