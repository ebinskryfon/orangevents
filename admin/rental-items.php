<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db      = get_db_connection();
$message = '';
$error   = '';

// =========================================================
// POST HANDLERS
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD CATEGORY ──────────────────────────────────────
    if ($action === 'add_category') {
        $name  = trim($_POST['category_name'] ?? '');
        $icon  = trim($_POST['category_icon'] ?? 'fa-box-open');
        if (empty($name)) {
            $error = 'Category name is required.';
        } else {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO rental_categories (category_name, icon, display_order)
                     VALUES (:name, :icon,
                             (SELECT IFNULL(MAX(display_order),0)+1 FROM rental_categories rc))"
                );
                $stmt->execute(['name' => $name, 'icon' => $icon]);
                $message = 'Category created successfully!';
            } catch (PDOException $e) {
                $error = 'Category already exists.';
            }
        }
    }

    // ── UPDATE CATEGORY ───────────────────────────────────
    if ($action === 'update_category') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        $name   = trim($_POST['category_name'] ?? '');
        $icon   = trim($_POST['category_icon'] ?? 'fa-box-open');
        if ($cat_id <= 0 || empty($name)) {
            $error = 'Category name is required.';
        } else {
            $stmt = $db->prepare(
                "UPDATE rental_categories SET category_name = :name, icon = :icon WHERE id = :id"
            );
            $stmt->execute(['name' => $name, 'icon' => $icon, 'id' => $cat_id]);
            $message = 'Category updated!';
        }
    }

    // ── DELETE CATEGORY ───────────────────────────────────
    if ($action === 'delete_category') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        if ($cat_id > 0) {
            $db->prepare("DELETE FROM rental_categories WHERE id = :id")->execute(['id' => $cat_id]);
            $message = 'Category deleted (all its items removed).';
        }
    }

    // ── ADD / UPDATE ITEM ─────────────────────────────────
    if ($action === 'save_item') {
        $item_id    = (int)($_POST['item_id'] ?? 0);
        $cat_id     = (int)($_POST['category_id'] ?? 0);
        $item_name  = trim($_POST['item_name'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $rate       = (float)($_POST['daily_rate'] ?? 0);
        $qty        = (int)($_POST['quantity_in_stock'] ?? 1);
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($item_name) || $cat_id <= 0) {
            $error = 'Item name and category are required.';
        } else {
            if ($item_id > 0) {
                $stmt = $db->prepare(
                    "UPDATE rental_items
                        SET category_id=:cat, item_name=:name, description=:desc,
                            daily_rate=:rate, quantity_in_stock=:qty, is_active=:active
                      WHERE id=:id"
                );
                $stmt->execute([
                    'cat' => $cat_id, 'name' => $item_name, 'desc' => $desc,
                    'rate' => $rate, 'qty' => $qty, 'active' => $is_active, 'id' => $item_id,
                ]);
                $message = 'Item updated!';
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO rental_items
                        (category_id, item_name, description, daily_rate, quantity_in_stock, is_active)
                     VALUES (:cat, :name, :desc, :rate, :qty, :active)"
                );
                $stmt->execute([
                    'cat' => $cat_id, 'name' => $item_name, 'desc' => $desc,
                    'rate' => $rate, 'qty' => $qty, 'active' => $is_active,
                ]);
                $message = 'Item added!';
            }
        }
    }

    // ── DELETE ITEM ───────────────────────────────────────
    if ($action === 'delete_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $db->prepare("DELETE FROM rental_items WHERE id = :id")->execute(['id' => $item_id]);
            $message = 'Item deleted.';
        }
    }

    // ── TOGGLE ACTIVE ─────────────────────────────────────
    if ($action === 'toggle_active') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $db->prepare("UPDATE rental_items SET is_active = 1 - is_active WHERE id = :id")
               ->execute(['id' => $item_id]);
            $message = 'Item availability toggled.';
        }
    }
}

// =========================================================
// FETCH DATA
// =========================================================
$categories = $db->query("SELECT * FROM rental_categories ORDER BY display_order ASC")->fetchAll();
$all_items  = $db->query(
    "SELECT ri.*, rc.category_name
       FROM rental_items ri
       JOIN rental_categories rc ON ri.category_id = rc.id
      ORDER BY rc.display_order ASC, ri.item_name ASC"
)->fetchAll();

// Group items by category id
$items_by_cat = [];
foreach ($all_items as $item) {
    $items_by_cat[$item['category_id']][] = $item;
}

// Summary stats
$total_items  = count($all_items);
$active_items = count(array_filter($all_items, fn($i) => $i['is_active']));
$total_cats   = count($categories);
?>

<!-- Page Header -->
<div class="content-header">
    <div class="header-title">
        <h1>Rental Items Catalogue</h1>
        <p>Manage items available for rent — wedding accessories, baby items, decor, and more.</p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <button onclick="openModal('addCategoryModal')" class="btn btn-secondary">
            <i class="fa-solid fa-folder-plus"></i> New Category
        </button>
        <button onclick="openAddItemModal()" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add Rental Item
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if (!empty($message)): ?>
<div style="background:rgba(46,213,115,0.15);color:var(--success);border:1px solid var(--success);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
    <i class="fa-solid fa-circle-check"></i> <span><?= h($message) ?></span>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div style="background:rgba(255,71,87,0.15);color:var(--danger);border:1px solid var(--danger);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
    <i class="fa-solid fa-triangle-exclamation"></i> <span><?= h($error) ?></span>
</div>
<?php endif; ?>

<!-- Summary Stat Cards -->
<div class="stats-grid" style="margin-bottom:2rem;">
    <div class="card stat-card">
        <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="stat-info">
            <h3><?= $total_items ?></h3>
            <p>Total Rental Items</p>
        </div>
    </div>
    <div class="card stat-card green">
        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <h3><?= $active_items ?></h3>
            <p>Available Items</p>
        </div>
    </div>
    <div class="card stat-card blue">
        <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
        <div class="stat-info">
            <h3><?= $total_cats ?></h3>
            <p>Categories</p>
        </div>
    </div>
    <div class="card stat-card purple">
        <div class="stat-icon"><i class="fa-solid fa-tags"></i></div>
        <div class="stat-info">
            <h3><?= $total_items - $active_items ?></h3>
            <p>Unavailable</p>
        </div>
    </div>
</div>

<!-- Categories + Items -->
<div style="display:flex;flex-direction:column;gap:2rem;">
    <?php if (empty($categories)): ?>
        <div class="card" style="text-align:center;padding:3rem;color:var(--text-muted);">
            <i class="fa-solid fa-box-open" style="font-size:3rem;margin-bottom:1rem;opacity:0.4;"></i>
            <p>No categories yet. Click <strong>New Category</strong> to get started.</p>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <?php $cat_items = $items_by_cat[$cat['id']] ?? []; ?>
            <div class="card">
                <!-- Category Header -->
                <h2 class="card-title" style="border-bottom:1px solid var(--border-color);padding-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
                    <span style="display:flex;align-items:center;gap:0.75rem;">
                        <span style="width:36px;height:36px;border-radius:var(--border-radius-sm);background:rgba(255,107,53,0.12);display:flex;align-items:center;justify-content:center;">
                            <i class="fa-solid <?= h($cat['icon']) ?>" style="color:var(--accent-color);font-size:1rem;"></i>
                        </span>
                        <?= h($cat['category_name']) ?>
                        <span style="font-size:0.78rem;font-weight:400;color:var(--text-muted);"><?= count($cat_items) ?> item<?= count($cat_items) !== 1 ? 's' : '' ?></span>
                    </span>
                    <div style="display:flex;gap:0.4rem;">
                        <button
                            class="btn btn-secondary"
                            style="padding:0.3rem 0.65rem;font-size:0.78rem;"
                            onclick="openEditCategory(<?= $cat['id'] ?>,<?= htmlspecialchars(json_encode($cat['category_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($cat['icon']),ENT_QUOTES) ?>)"
                        >
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </button>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this category and ALL its items?');">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.65rem;font-size:0.78rem;">
                                <i class="fa-solid fa-trash-can"></i> Delete
                            </button>
                        </form>
                    </div>
                </h2>

                <!-- Items Table -->
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:28%;">Item</th>
                                <th style="width:30%;">Description</th>
                                <th style="width:12%;text-align:center;">Rate / Day</th>
                                <th style="width:10%;text-align:center;">Qty</th>
                                <th style="width:10%;text-align:center;">Status</th>
                                <th style="width:10%;text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cat_items)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;color:var(--text-muted);padding:1.75rem 0;">
                                        No items in this category yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cat_items as $item): ?>
                                    <tr style="<?= !$item['is_active'] ? 'opacity:0.5;' : '' ?>">
                                        <td style="font-weight:600;color:var(--text-primary);">
                                            <?= h($item['item_name']) ?>
                                        </td>
                                        <td style="color:var(--text-secondary);font-size:0.84rem;">
                                            <?= h($item['description']) ?>
                                        </td>
                                        <td style="text-align:center;font-weight:700;color:var(--accent-color);">
                                            <?= format_price($item['daily_rate']) ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span style="display:inline-block;min-width:28px;padding:0.2rem 0.5rem;border-radius:50px;background:rgba(255,107,53,0.12);font-weight:600;font-size:0.85rem;">
                                                <?= $item['quantity_in_stock'] ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <form method="POST" style="margin:0;display:inline;">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                <button type="submit"
                                                    title="Click to toggle availability"
                                                    style="border:none;background:none;cursor:pointer;padding:0;">
                                                    <?php if (!$item['is_active']): ?>
                                                        <span class="badge badge-cancelled">Unavailable</span>
                                                    <?php elseif ($item['quantity_in_stock'] <= 0): ?>
                                                        <span class="badge" style="background:var(--warning);color:#fff;">Out of Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-confirmed">Available</span>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td style="text-align:right;">
                                            <div style="display:inline-flex;gap:0.35rem;">
                                                <button
                                                    class="btn btn-secondary"
                                                    style="padding:0.3rem 0.55rem;font-size:0.75rem;"
                                                    onclick="openEditItemModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)"
                                                >
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>
                                                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this item?');">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.55rem;font-size:0.75rem;">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ============================================================
     MODAL: Add Category
     ============================================================ -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('addCategoryModal')">&times;</button>
        <h3 style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-folder-plus" style="color:var(--accent-color);"></i> New Category
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_category">
            <div class="form-group">
                <label class="form-label">Category Name</label>
                <input type="text" name="category_name" class="form-control" placeholder="e.g. Wedding Accessories" required>
            </div>
            <div class="form-group">
                <label class="form-label">Icon <span style="font-size:0.8rem;color:var(--text-muted);">(FontAwesome class)</span></label>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="text" name="category_icon" id="addIconInput" class="form-control" value="fa-box-open" placeholder="fa-box-open">
                    <span id="addIconPreview" style="font-size:1.4rem;color:var(--accent-color);min-width:1.8rem;text-align:center;">
                        <i class="fa-solid fa-box-open"></i>
                    </span>
                </div>
                <p style="font-size:0.78rem;color:var(--text-muted);margin-top:0.4rem;">
                    Find icons at <a href="https://fontawesome.com/icons" target="_blank" style="color:var(--accent-color);">fontawesome.com/icons</a>
                </p>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('addCategoryModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Category</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: Edit Category
     ============================================================ -->
<div id="editCategoryModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('editCategoryModal')">&times;</button>
        <h3 style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-folder-pen" style="color:var(--accent-color);"></i> Edit Category
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="category_id" id="editCatId">
            <div class="form-group">
                <label class="form-label">Category Name</label>
                <input type="text" id="editCatName" name="category_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Icon <span style="font-size:0.8rem;color:var(--text-muted);">(FontAwesome class)</span></label>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="text" name="category_icon" id="editIconInput" class="form-control" placeholder="fa-box-open">
                    <span id="editIconPreview" style="font-size:1.4rem;color:var(--accent-color);min-width:1.8rem;text-align:center;">
                        <i id="editIconEl" class="fa-solid fa-box-open"></i>
                    </span>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('editCategoryModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: Add / Edit Rental Item
     ============================================================ -->
<div id="itemModal" class="modal">
    <div class="modal-content" style="max-width:560px;">
        <button class="modal-close" onclick="closeModal('itemModal')">&times;</button>
        <h3 id="itemModalTitle" style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Rental Item
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" name="item_id" id="itemId" value="0">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" id="itemCategoryId" class="form-control" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= h($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Daily Rental Rate (Rs)</label>
                    <input type="number" step="0.01" min="0" name="daily_rate" id="itemRate" class="form-control" placeholder="500" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Item Name</label>
                <input type="text" name="item_name" id="itemName" class="form-control" placeholder="e.g. Marriage Garland (Mala)" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="itemDesc" class="form-control" rows="2" placeholder="Short description of the item..."></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Quantity in Stock</label>
                    <input type="number" min="0" name="quantity_in_stock" id="itemQty" class="form-control" value="1" required>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:0.6rem;padding-top:1.9rem;">
                    <input type="checkbox" name="is_active" id="itemActive" value="1" checked style="width:18px;height:18px;accent-color:var(--accent-color);cursor:pointer;">
                    <label for="itemActive" class="form-label" style="margin:0;cursor:pointer;">Mark as Available</label>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('itemModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" id="itemSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Save Item</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Icon live-preview helpers ──────────────────────────────────
document.getElementById('addIconInput').addEventListener('input', function () {
    const ic = this.value.trim();
    document.getElementById('addIconPreview').innerHTML = ic
        ? `<i class="fa-solid ${ic}"></i>`
        : '';
});
document.getElementById('editIconInput').addEventListener('input', function () {
    const ic = this.value.trim();
    document.getElementById('editIconPreview').innerHTML = ic
        ? `<i class="fa-solid ${ic}"></i>`
        : '';
});

// ── Category modals ────────────────────────────────────────────
function openEditCategory(id, name, icon) {
    document.getElementById('editCatId').value        = id;
    document.getElementById('editCatName').value      = name;
    document.getElementById('editIconInput').value    = icon;
    document.getElementById('editIconPreview').innerHTML = `<i class="fa-solid ${icon}"></i>`;
    openModal('editCategoryModal');
}

// ── Item modals ────────────────────────────────────────────────
function openAddItemModal() {
    document.getElementById('itemModalTitle').innerHTML =
        '<i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Rental Item';
    document.getElementById('itemId').value          = '0';
    document.getElementById('itemCategoryId').value  = '';
    document.getElementById('itemName').value        = '';
    document.getElementById('itemDesc').value        = '';
    document.getElementById('itemRate').value        = '';
    document.getElementById('itemQty').value         = '1';
    document.getElementById('itemActive').checked    = true;
    openModal('itemModal');
}

function openEditItemModal(item) {
    document.getElementById('itemModalTitle').innerHTML =
        '<i class="fa-solid fa-pen-to-square" style="color:var(--accent-color);"></i> Edit Rental Item';
    document.getElementById('itemId').value          = item.id;
    document.getElementById('itemCategoryId').value  = item.category_id;
    document.getElementById('itemName').value        = item.item_name;
    document.getElementById('itemDesc').value        = item.description || '';
    document.getElementById('itemRate').value        = item.daily_rate;
    document.getElementById('itemQty').value         = item.quantity_in_stock;
    document.getElementById('itemActive').checked    = parseInt(item.is_active) === 1;
    openModal('itemModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
