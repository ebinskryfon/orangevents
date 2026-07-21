<?php
/**
 * Customer Directory & Management
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_read');

$db = get_db_connection();
$message = '';
$error = '';

// POST Handlers (Add / Edit / Delete Customer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_customer') {
        $cust_id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name) || empty($phone)) {
            $error = "Customer Name and Phone Number are required fields.";
        } else {
            try {
                // Check phone uniqueness
                $stmt_chk = $db->prepare("SELECT id FROM customers WHERE phone = :phone AND id != :id LIMIT 1");
                $stmt_chk->execute(['phone' => $phone, 'id' => $cust_id]);
                if ($stmt_chk->fetch()) {
                    $error = "A customer with phone number $phone already exists.";
                } else {
                    if ($cust_id > 0) {
                        $stmt_up = $db->prepare("
                            UPDATE customers
                               SET name = :name, phone = :phone, email = :email, address = :address,
                                   city = :city, gstin = :gstin, notes = :notes
                             WHERE id = :id
                        ");
                        $stmt_up->execute([
                            'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                            'address' => $address ?: null, 'city' => $city ?: null,
                            'gstin' => $gstin ?: null, 'notes' => $notes ?: null, 'id' => $cust_id
                        ]);
                        $message = "Customer profile updated successfully.";
                    } else {
                        $stmt_ins = $db->prepare("
                            INSERT INTO customers (name, phone, email, address, city, gstin, notes)
                            VALUES (:name, :phone, :email, :address, :city, :gstin, :notes)
                        ");
                        $stmt_ins->execute([
                            'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                            'address' => $address ?: null, 'city' => $city ?: null,
                            'gstin' => $gstin ?: null, 'notes' => $notes ?: null
                        ]);
                        $message = "New customer created successfully.";
                    }
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_customer') {
        $cust_id = (int)($_POST['id'] ?? 0);
        if ($cust_id > 0) {
            try {
                $db->prepare("DELETE FROM customers WHERE id = :id")->execute(['id' => $cust_id]);
                $message = "Customer profile deleted successfully.";
            } catch (Exception $e) {
                $error = "Failed to delete customer: " . $e->getMessage();
            }
        }
    }
}

// Search & Filter
$search = trim($_GET['search'] ?? '');
$sort = trim($_GET['sort'] ?? 'id_desc');

$query_sql = "SELECT * FROM customers WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query_sql .= " AND (name LIKE :search OR phone LIKE :search OR email LIKE :search OR city LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($sort === 'spent_desc') {
    $query_sql .= " ORDER BY total_spent DESC";
} elseif ($sort === 'orders_desc') {
    $query_sql .= " ORDER BY total_orders DESC";
} elseif ($sort === 'name_asc') {
    $query_sql .= " ORDER BY name ASC";
} else {
    $query_sql .= " ORDER BY id DESC";
}

$query_sql .= " LIMIT 150";

$stmt = $db->prepare($query_sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Directory summary totals
$total_clients_count = (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$total_lifetime_spend = (float)$db->query("SELECT COALESCE(SUM(total_spent), 0) FROM customers")->fetchColumn();
?>

<div class="content-header" style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-users" style="color:var(--accent-color);"></i>
            Customer Directory & Management
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            Manage client profiles, view total spending, and track multi-module purchase histories.
        </p>
    </div>
    <div>
        <button onclick="openAddCustomerModal()" class="btn btn-primary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <i class="fa-solid fa-user-plus"></i> Add New Customer
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="font-size:0.85rem; padding:0.6rem 1rem; margin-bottom:1rem;"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="font-size:0.85rem; padding:0.6rem 1rem; margin-bottom:1rem;"><?= h($error) ?></div>
<?php endif; ?>

<!-- Summary Metrics Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:0.75rem; margin-bottom:1rem;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(255, 107, 53, 0.1); color:var(--accent-color); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-address-book"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Total Clients</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary);"><?= number_format($total_clients_count) ?> Profiles</div>
        </div>
    </div>

    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; display:flex; align-items:center; gap:0.75rem;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(46, 213, 115, 0.1); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">Lifetime Client Revenue</div>
            <div style="font-size:1.1rem; font-weight:800; color:var(--success);">₹<?= number_format($total_lifetime_spend, 2) ?></div>
        </div>
    </div>
</div>

<!-- Search & Filter Form -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); padding:0.75rem; margin-bottom:1rem; box-shadow:var(--box-shadow);">
    <form method="GET" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; margin:0;">
        <div style="flex:1; min-width:220px;">
            <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem; display:block;">Search (Name / Phone / Email / City)</label>
            <input type="text" name="search" class="form-control" placeholder="Search customer..." value="<?= h($search) ?>" style="height:32px; font-size:0.8rem;">
        </div>

        <div style="width:160px;">
            <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem; display:block;">Sort By</label>
            <select name="sort" class="form-control" style="height:32px; font-size:0.8rem;" onchange="this.form.submit()">
                <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>Newest Added</option>
                <option value="spent_desc" <?= $sort === 'spent_desc' ? 'selected' : '' ?>>Highest Spend</option>
                <option value="orders_desc" <?= $sort === 'orders_desc' ? 'selected' : '' ?>>Most Orders</option>
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
            </select>
        </div>

        <div style="display:flex; gap:0.3rem;">
            <button type="submit" class="btn btn-primary" style="height:32px; font-size:0.75rem; padding:0 0.8rem;">
                <i class="fa-solid fa-filter"></i> Search
            </button>
            <a href="customers.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; padding:0 0.6rem;" title="Reset Filters">
                <i class="fa-solid fa-rotate-right"></i> Reset
            </a>
        </div>
    </form>
</div>

<!-- Customer Directory Table -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); overflow:hidden; box-shadow:var(--box-shadow);">
    <div class="table-responsive">
        <table class="table" style="width:100%; margin:0; font-size:0.8rem;">
            <thead style="background:var(--bg-control); border-bottom:1px solid var(--border-color);">
                <tr>
                    <th style="padding:0.6rem 0.75rem;">Customer Name</th>
                    <th style="padding:0.6rem 0.75rem;">Phone Number</th>
                    <th style="padding:0.6rem 0.75rem;">Address / City</th>
                    <th style="padding:0.6rem 0.75rem; text-align:center;">Total Orders</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Total Spent</th>
                    <th style="padding:0.6rem 0.75rem; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:2rem; color:var(--text-muted);">
                            <i class="fa-solid fa-users-slash" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>
                            No customer records found matching search.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr style="border-bottom:1px solid var(--border-color);">
                            <td style="padding:0.6rem 0.75rem; font-weight:700; color:var(--text-primary);">
                                <a href="view-customer.php?id=<?= $c['id'] ?>" style="color:var(--text-primary); text-decoration:none;">
                                    <i class="fa-solid fa-user-circle" style="color:var(--accent-color); margin-right:0.3rem;"></i>
                                    <?= h($c['name']) ?>
                                </a>
                                <?php if (!empty($c['gstin'])): ?>
                                    <br><small style="color:var(--text-muted);">GSTIN: <?= h($c['gstin']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; font-weight:600; color:var(--accent-color);">
                                <a href="tel:<?= h($c['phone']) ?>" style="color:var(--accent-color); text-decoration:none;">
                                    <i class="fa-solid fa-phone" style="font-size:0.7rem; margin-right:0.2rem;"></i> <?= h($c['phone']) ?>
                                </a>
                            </td>
                            <td style="padding:0.6rem 0.75rem; color:var(--text-secondary); max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= h($c['address'] ?: ($c['city'] ?: 'N/A')) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:center;">
                                <span class="badge" style="background:rgba(255,107,53,0.1); color:var(--accent-color); padding:2px 8px; border-radius:10px; font-weight:700;">
                                    <?= number_format($c['total_orders']) ?> orders
                                </span>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right; font-weight:700; color:var(--success);">
                                ₹<?= number_format($c['total_spent'], 2) ?>
                            </td>
                            <td style="padding:0.6rem 0.75rem; text-align:right;">
                                <div style="display:inline-flex; gap:0.25rem;">
                                    <a href="view-customer.php?id=<?= $c['id'] ?>" class="btn btn-secondary" style="padding:0.2rem 0.45rem; font-size:0.7rem;" title="View Purchase History">
                                        <i class="fa-solid fa-clock-rotate-left"></i> History
                                    </a>
                                    <button onclick='openEditCustomerModal(<?= json_encode($c) ?>)' class="btn btn-secondary" style="padding:0.2rem 0.45rem; font-size:0.7rem;" title="Edit Profile">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add / Edit Customer -->
<div id="customerModal" class="modal" tabindex="-1" style="z-index:1050;">
    <div class="modal-content" style="max-width:520px; padding:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:1rem;">
            <h3 id="customerModalTitle" style="font-size:1.1rem; font-weight:800; margin:0; display:flex; align-items:center; gap:0.5rem; color:var(--text-primary);">
                <i class="fa-solid fa-user-plus" style="color:var(--accent-color);"></i>
                Add New Customer
            </h3>
            <button type="button" onclick="closeModal('customerModal')" style="background:none; border:none; color:var(--text-secondary); font-size:1.2rem; cursor:pointer;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="save_customer">
            <input type="hidden" id="custFormId" name="id" value="0">

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-bottom:0.6rem;">
                <div>
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Full Name *</label>
                    <input type="text" id="custFormName" name="name" class="form-control" placeholder="Customer Name" required style="height:32px; font-size:0.8rem;">
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Phone Number *</label>
                    <input type="text" id="custFormPhone" name="phone" class="form-control" placeholder="9946731720" required style="height:32px; font-size:0.8rem;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-bottom:0.6rem;">
                <div>
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Email Address</label>
                    <input type="email" id="custFormEmail" name="email" class="form-control" placeholder="client@example.com" style="height:32px; font-size:0.8rem;">
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">City / Location</label>
                    <input type="text" id="custFormCity" name="city" class="form-control" placeholder="Alappuzha" style="height:32px; font-size:0.8rem;">
                </div>
            </div>

            <div style="margin-bottom:0.6rem;">
                <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">GSTIN Number (Optional)</label>
                <input type="text" id="custFormGstin" name="gstin" class="form-control" placeholder="32AAAAA0000A1Z5" style="height:32px; font-size:0.8rem;">
            </div>

            <div style="margin-bottom:0.6rem;">
                <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Customer Address</label>
                <textarea id="custFormAddress" name="address" class="form-control" rows="2" placeholder="Full street address..." style="font-size:0.8rem; padding:0.3rem 0.5rem;"></textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label" style="font-size:0.75rem; margin-bottom:0.2rem;">Notes / Preferences</label>
                <textarea id="custFormNotes" name="notes" class="form-control" rows="2" placeholder="Catering preferences, special discounts..." style="font-size:0.8rem; padding:0.3rem 0.5rem;"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.4rem; border-top:1px solid var(--border-color); padding-top:0.75rem;">
                <button type="button" onclick="closeModal('customerModal')" class="btn btn-secondary" style="height:34px; font-size:0.75rem;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="height:34px; font-size:0.8rem; font-weight:700; padding:0 1rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Customer Profile
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddCustomerModal() {
    document.getElementById('custFormId').value = '0';
    document.getElementById('custFormName').value = '';
    document.getElementById('custFormPhone').value = '';
    document.getElementById('custFormEmail').value = '';
    document.getElementById('custFormCity').value = '';
    document.getElementById('custFormGstin').value = '';
    document.getElementById('custFormAddress').value = '';
    document.getElementById('custFormNotes').value = '';
    document.getElementById('customerModalTitle').innerHTML = '<i class="fa-solid fa-user-plus" style="color:var(--accent-color);"></i> Add New Customer';
    openModal('customerModal');
}

function openEditCustomerModal(cust) {
    document.getElementById('custFormId').value = cust.id;
    document.getElementById('custFormName').value = cust.name || '';
    document.getElementById('custFormPhone').value = cust.phone || '';
    document.getElementById('custFormEmail').value = cust.email || '';
    document.getElementById('custFormCity').value = cust.city || '';
    document.getElementById('custFormGstin').value = cust.gstin || '';
    document.getElementById('custFormAddress').value = cust.address || '';
    document.getElementById('custFormNotes').value = cust.notes || '';
    document.getElementById('customerModalTitle').innerHTML = '<i class="fa-solid fa-user-pen" style="color:var(--accent-color);"></i> Edit Customer Profile';
    openModal('customerModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
