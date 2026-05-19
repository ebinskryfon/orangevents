<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$message = '';
$error = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. ADD DISH
    if ($action === 'add_dish') {
        $dish_name = trim($_POST['dish_name'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        if (empty($dish_name) || $category_id <= 0) {
            $error = 'Dish name and category are required.';
        } else {
            $stmt = $db->prepare("INSERT INTO dishes (dish_name, category_id, description) VALUES (:name, :cat_id, :desc)");
            $stmt->execute(['name' => $dish_name, 'cat_id' => $category_id, 'desc' => $description]);
            $message = 'Dish added successfully!';
        }
    }
    
    // 2. DELETE DISH
    if ($action === 'delete_dish') {
        $dish_id = (int)($_POST['dish_id'] ?? 0);
        if ($dish_id > 0) {
            $stmt = $db->prepare("DELETE FROM dishes WHERE id = :id");
            $stmt->execute(['id' => $dish_id]);
            $message = 'Dish deleted successfully!';
        }
    }
    
    // 3. ADD CATEGORY
    if ($action === 'add_category') {
        $category_name = strtoupper(trim($_POST['category_name'] ?? ''));
        if (empty($category_name)) {
            $error = 'Category name cannot be empty.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO menu_categories (category_name, display_order) VALUES (:name, (SELECT IFNULL(MAX(display_order), 0) + 1 FROM menu_categories mc))");
                $stmt->execute(['name' => $category_name]);
                $message = 'Category added successfully!';
            } catch (PDOException $e) {
                $error = 'Category already exists or database error.';
            }
        }
    }
}

// Fetch categories & dishes
$categories = $db->query("SELECT * FROM menu_categories ORDER BY display_order ASC")->fetchAll();
$dishes = $db->query("SELECT d.*, c.category_name FROM dishes d JOIN menu_categories c ON d.category_id = c.id ORDER BY c.display_order ASC, d.dish_name ASC")->fetchAll();

// Group dishes by category
$dishes_by_cat = [];
foreach ($dishes as $dish) {
    $dishes_by_cat[$dish['category_id']][] = $dish;
}
?>

<div class="content-header">
    <div class="header-title">
        <h1>Catering Dishes Catalog</h1>
        <p>Manage categories and items for welcome drinks, starters, and menus.</p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <button onclick="openModal('addCategoryModal')" class="btn btn-secondary">
            <i class="fa-solid fa-folder-plus"></i> New Category
        </button>
        <button onclick="openModal('addDishModal')" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add New Dish
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

<!-- Category Accordions / Cards -->
<div style="display: flex; flex-direction: column; gap: 2rem;">
    <?php foreach ($categories as $cat): ?>
        <div class="card">
            <h2 class="card-title" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                <span>
                    <i class="fa-solid fa-utensils" style="color: var(--accent-color); margin-right: 0.5rem;"></i>
                    <?= h($cat['category_name']) ?>
                </span>
                <span style="font-size: 0.8rem; color: var(--text-secondary); font-weight: normal;">
                    <?= isset($dishes_by_cat[$cat['id']]) ? count($dishes_by_cat[$cat['id']]) : 0 ?> items
                </span>
            </h2>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Dish Name</th>
                            <th style="width: 50%;">Description / Specs</th>
                            <th style="width: 20%; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dishes_by_cat[$cat['id']])): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 2rem 0;">
                                    No dishes added to this category.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dishes_by_cat[$cat['id']] as $dish): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--text-primary);"><?= h($dish['dish_name']) ?></td>
                                    <td style="color: var(--text-secondary); font-size: 0.85rem;"><?= h($dish['description']) ?></td>
                                    <td style="text-align: right;">
                                        <form action="" method="POST" onsubmit="return confirm('Are you sure you want to delete this dish?');" style="display: inline-block;">
                                            <input type="hidden" name="action" value="delete_dish">
                                            <input type="hidden" name="dish_id" value="<?= $dish['id'] ?>">
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
    <?php endforeach; ?>
</div>

<!-- Modal 1: Add Category -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('addCategoryModal')">&times;</button>
        <h3 style="margin-bottom: 1.5rem;"><i class="fa-solid fa-folder-plus" style="color: var(--accent-color);"></i> Add Category</h3>
        
        <form action="" method="POST">
            <input type="hidden" name="action" value="add_category">
            
            <div class="form-group">
                <label for="category_name" class="form-label">Category Name</label>
                <input type="text" id="category_name" name="category_name" class="form-control" placeholder="e.g. STARTERS" required style="text-transform: uppercase;">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 2rem;">
                <button type="button" onclick="closeModal('addCategoryModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Add Dish -->
<div id="addDishModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('addDishModal')">&times;</button>
        <h3 style="margin-bottom: 1.5rem;"><i class="fa-solid fa-circle-plus" style="color: var(--accent-color);"></i> Add New Dish</h3>
        
        <form action="" method="POST">
            <input type="hidden" name="action" value="add_dish">
            
            <div class="form-group">
                <label for="category_id" class="form-label">Menu Category</label>
                <select id="category_id" name="category_id" class="form-control" required>
                    <option value="">Select a category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= h($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="dish_name" class="form-label">Dish Name</label>
                <input type="text" id="dish_name" name="dish_name" class="form-control" placeholder="e.g. Mojito (3 types)" required>
            </div>
            
            <div class="form-group">
                <label for="description" class="form-label">Description / Ingredients</label>
                <textarea id="description" name="description" class="form-control" rows="3" placeholder="e.g. Mint Lime, Blue Curacao, Strawberry mojitos."></textarea>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 2rem;">
                <button type="button" onclick="closeModal('addDishModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Dish</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
