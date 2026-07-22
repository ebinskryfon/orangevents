<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_update');

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
        $name = trim($_POST['category_name'] ?? '');
        if (empty($name)) {
            $error = 'Category name is required.';
        } else {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO billing_categories (category_name, display_order)
                     VALUES (:name, (SELECT IFNULL(MAX(display_order), 0) + 1 FROM billing_categories c))"
                );
                $stmt->execute(['name' => $name]);
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
        if ($cat_id <= 0 || empty($name)) {
            $error = 'Category name is required.';
        } else {
            try {
                $stmt = $db->prepare("UPDATE billing_categories SET category_name = :name WHERE id = :id");
                $stmt->execute(['name' => $name, 'id' => $cat_id]);
                $message = 'Category updated!';
            } catch (PDOException $e) {
                $error = 'Category name already exists.';
            }
        }
    }

    // ── DELETE CATEGORY ───────────────────────────────────
    if ($action === 'delete_category') {
        if (!has_permission('billing_delete')) {
            $error = 'Access denied: You do not have the required permission (billing_delete) to delete categories.';
        } else {
            $cat_id = (int)($_POST['category_id'] ?? 0);
            if ($cat_id > 0) {
                try {
                    $db->prepare("DELETE FROM billing_categories WHERE id = :id")->execute(['id' => $cat_id]);
                    $message = 'Category deleted.';
                } catch (PDOException $e) {
                    $error = 'Cannot delete category as it contains products that are referenced in previous orders.';
                }
            }
        }
    }
}

// Fetch categories
$categories = $db->query(
    "SELECT bc.*, COUNT(bp.id) as product_count
     FROM billing_categories bc
     LEFT JOIN billing_products bp ON bc.id = bp.category_id
     GROUP BY bc.id
     ORDER BY bc.display_order ASC"
)->fetchAll();
?>

<style>
    .table th, .table td { padding: 0.45rem 0.75rem; font-size: 0.82rem; }
    .card { padding: 0.85rem !important; }
    .card-title { font-size: 0.95rem !important; margin-bottom: 0.6rem !important; }
</style>

<!-- Page Header -->
<div class="content-header" style="margin-bottom: 0.75rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-folder-open" style="color:var(--accent-color);"></i>
            Billing Categories
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">
            Organize birthday and event items into custom categories.
        </p>
    </div>
</div>
<div style="display:flex; justify-content:flex-end; margin-bottom:0.75rem;">
    <button onclick="openModal('addCategoryModal')" class="btn btn-primary" style="height:32px; font-size:0.8rem; padding:0 0.85rem;">
        <i class="fa-solid fa-folder-plus"></i> New Category
    </button>
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

<!-- Categories Grid -->
<div class="card">
    <h2 class="card-title">Manage Categories</h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:10%;">ID</th>
                    <th style="width:45%;">Category Name</th>
                    <th style="width:15%; text-align:center;">Display Order</th>
                    <th style="width:15%; text-align:center;">Products Count</th>
                    <th style="width:15%; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem 0;">
                            No categories created yet. Click "New Category" to get started.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= $cat['id'] ?></td>
                            <td style="font-weight:600; color:var(--text-primary);">
                                <?= h($cat['category_name']) ?>
                            </td>
                            <td style="text-align:center;"><?= $cat['display_order'] ?></td>
                            <td style="text-align:center;">
                                <span style="display:inline-block;padding:0.1rem 0.5rem;background:rgba(255,107,53,0.12);color:var(--accent-color);font-weight:600;border-radius:50px;font-size:0.75rem;">
                                    <?= $cat['product_count'] ?> items
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:0.5rem;">
                                    <button
                                        class="btn btn-secondary"
                                        style="padding:0.35rem 0.65rem;font-size:0.8rem;"
                                        onclick="openEditCategory(<?= $cat['id'] ?>, <?= htmlspecialchars(json_encode($cat['category_name']), ENT_QUOTES) ?>)"
                                    >
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this category and all its products?');">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:0.35rem 0.65rem;font-size:0.8rem;">
                                            <i class="fa-solid fa-trash-can"></i> Delete
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

<!-- ============================================================
     MODAL: Add Category
     ============================================================ -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content" style="max-width:380px; padding:1.25rem;">
        <button class="modal-close" onclick="closeModal('addCategoryModal')">&times;</button>
        <h3 style="margin-bottom:1.25rem;">
            <i class="fa-solid fa-folder-plus" style="color:var(--accent-color);"></i> New Category
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_category">
            <div class="form-group">
                <label class="form-label">Category Name</label>
                <input type="text" name="category_name" class="form-control" placeholder="e.g. Birthday Items" required>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
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
    <div class="modal-content" style="max-width:380px; padding:1.25rem;">
        <button class="modal-close" onclick="closeModal('editCategoryModal')">&times;</button>
        <h3 style="margin-bottom:1.25rem;">
            <i class="fa-solid fa-folder-pen" style="color:var(--accent-color);"></i> Edit Category
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="category_id" id="editCatId">
            <div class="form-group">
                <label class="form-label">Category Name</label>
                <input type="text" id="editCatName" name="category_name" class="form-control" required>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('editCategoryModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditCategory(id, name) {
    document.getElementById('editCatId').value = id;
    document.getElementById('editCatName').value = name;
    openModal('editCategoryModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
