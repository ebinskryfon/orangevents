<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$message = '';
$error = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. ADD / EDIT ITEM
    if ($action === 'save_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $item_name = trim($_POST['item_name'] ?? '');
        $default_price = (float)($_POST['default_price'] ?? 0.00);
        $description = trim($_POST['description'] ?? '');
        
        if (empty($item_name)) {
            $error = 'Item name is required.';
        } else {
            if ($item_id > 0) {
                // Update
                $stmt = $db->prepare("UPDATE stage_items SET item_name = :name, default_price = :price, description = :desc WHERE id = :id");
                $stmt->execute(['name' => $item_name, 'price' => $default_price, 'desc' => $description, 'id' => $item_id]);
                $message = 'Item updated successfully!';
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO stage_items (item_name, default_price, description) VALUES (:name, :price, :desc)");
                $stmt->execute(['name' => $item_name, 'price' => $default_price, 'desc' => $description]);
                $message = 'Item added successfully!';
            }
        }
    }
    
    // 2. DELETE ITEM
    if ($action === 'delete_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $stmt = $db->prepare("DELETE FROM stage_items WHERE id = :id");
            $stmt->execute(['id' => $item_id]);
            $message = 'Item deleted successfully!';
        }
    }
}

// Fetch all stage items
$stage_items = $db->query("SELECT * FROM stage_items ORDER BY default_price DESC, item_name ASC")->fetchAll();
?>

<div class="content-header">
    <div class="header-title">
        <h1>Stage Work & Decors</h1>
        <p>Manage default stage props, generators, light, sound systems, and fabric rates.</p>
    </div>
    <div>
        <button onclick="openAddModal()" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add Stage Item
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div style="background: rgba(46, 213, 115, 0.15); color: var(--success); border: 1px solid var(--success); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-circle-check"></i> <span><?= h($message) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: rgba(255, 71, 87, 0.15); color: var(--danger); border: 1px solid var(--danger); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i> <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 40%;">Decoration Item</th>
                    <th style="width: 15%;">Default Rate</th>
                    <th style="width: 25%;">Description</th>
                    <th style="width: 20%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stage_items)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            No stage items in database. Click "Add Stage Item" to create one.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stage_items as $item): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-primary);"><?= h($item['item_name']) ?></td>
                            <td style="font-weight: 600; color: var(--accent-color);"><?= format_price($item['default_price']) ?></td>
                            <td style="color: var(--text-secondary); font-size: 0.85rem;"><?= h($item['description']) ?></td>
                            <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($item)) ?>)" class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </button>
                                <form action="" method="POST" onsubmit="return confirm('Are you sure you want to delete this item?');" style="display: inline-block;">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;">
                                        <i class="fa-solid fa-trash-can"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Item Modal -->
<div id="itemModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('itemModal')">&times;</button>
        <h3 id="modalTitle" style="margin-bottom: 1.5rem;"><i class="fa-solid fa-circle-plus" style="color: var(--accent-color);"></i> Add Stage Item</h3>
        
        <form action="" method="POST">
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" id="item_id" name="item_id" value="0">
            
            <div class="form-group">
                <label for="item_name" class="form-label">Decoration / Service Title</label>
                <input type="text" id="item_name" name="item_name" class="form-control" placeholder="e.g. Generator & Diesel" required>
            </div>
            
            <div class="form-group">
                <label for="default_price" class="form-label">Default Price (Rs)</label>
                <input type="number" step="0.01" min="0" id="default_price" name="default_price" class="form-control" placeholder="5000" required>
            </div>
            
            <div class="form-group">
                <label for="description" class="form-label">Description / Inclusions</label>
                <textarea id="description" name="description" class="form-control" rows="3" placeholder="e.g. Includes backup generator and diesel fuel for 6 hours."></textarea>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 2rem;">
                <button type="button" onclick="closeModal('itemModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Item</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-circle-plus" style="color: var(--accent-color);"></i> Add Stage Item';
    document.getElementById('item_id').value = '0';
    document.getElementById('item_name').value = '';
    document.getElementById('default_price').value = '';
    document.getElementById('description').value = '';
    document.getElementById('itemModal').classList.add('active');
}

function openEditModal(item) {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color: var(--accent-color);"></i> Edit Stage Item';
    document.getElementById('item_id').value = item.id;
    document.getElementById('item_name').value = item.item_name;
    document.getElementById('default_price').value = item.default_price;
    document.getElementById('description').value = item.description;
    document.getElementById('itemModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
