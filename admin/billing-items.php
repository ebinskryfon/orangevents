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

    // ── SAVE PRODUCT ──────────────────────────────────────
    if ($action === 'save_product') {
        $prod_id    = (int)($_POST['product_id'] ?? 0);
        $cat_id     = (int)($_POST['category_id'] ?? 0);
        $prod_name  = trim($_POST['product_name'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $base_price = (float)($_POST['base_price'] ?? 0);
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($prod_name) || $cat_id <= 0) {
            $error = 'Product name and category are required.';
        } else {
            if ($prod_id > 0) {
                $stmt = $db->prepare(
                    "UPDATE billing_products
                        SET category_id = :cat, product_name = :name, description = :desc,
                            base_price = :price, is_active = :active
                      WHERE id = :id"
                );
                $stmt->execute([
                    'cat' => $cat_id, 'name' => $prod_name, 'desc' => $desc,
                    'price' => $base_price, 'active' => $is_active, 'id' => $prod_id
                ]);
                $message = 'Product updated successfully!';
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO billing_products (category_id, product_name, description, base_price, is_active)
                     VALUES (:cat, :name, :desc, :price, :active)"
                );
                $stmt->execute([
                    'cat' => $cat_id, 'name' => $prod_name, 'desc' => $desc,
                    'price' => $base_price, 'active' => $is_active
                ]);
                $message = 'Product added successfully!';
            }
        }
    }

    // ── DELETE PRODUCT ────────────────────────────────────
    if ($action === 'delete_product') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        if ($prod_id > 0) {
            try {
                $db->prepare("DELETE FROM billing_products WHERE id = :id")->execute(['id' => $prod_id]);
                $message = 'Product deleted.';
            } catch (PDOException $e) {
                $error = 'Cannot delete product. It may be referenced in past orders.';
            }
        }
    }

    // ── SAVE VARIANT ──────────────────────────────────────
    if ($action === 'save_variant') {
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);
        $size       = trim($_POST['size'] ?? '');
        $inherit    = isset($_POST['inherit_price']) ? 1 : 0;
        $price      = $inherit ? null : (float)($_POST['price'] ?? 0);

        if ($product_id <= 0 || empty($size)) {
            $error = 'Size / Label is required.';
        } else {
            if ($variant_id > 0) {
                $stmt = $db->prepare(
                    "UPDATE billing_product_variants SET size = :size, price = :price WHERE id = :id"
                );
                $stmt->execute(['size' => $size, 'price' => $price, 'id' => $variant_id]);
                $message = 'Variant updated!';
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO billing_product_variants (product_id, size, price) VALUES (:prod, :size, :price)"
                );
                $stmt->execute(['prod' => $product_id, 'size' => $size, 'price' => $price]);
                $message = 'Variant added successfully!';
            }
        }
    }

    // ── DELETE VARIANT ────────────────────────────────────
    if ($action === 'delete_variant') {
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        if ($variant_id > 0) {
            try {
                $db->prepare("DELETE FROM billing_product_variants WHERE id = :id")->execute(['id' => $variant_id]);
                $message = 'Variant deleted.';
            } catch (PDOException $e) {
                $error = 'Cannot delete variant. It may be referenced in past orders.';
            }
        }
    }

    // ── TOGGLE ACTIVE PRODUCT ─────────────────────────────
    if ($action === 'toggle_active') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        if ($prod_id > 0) {
            $db->prepare("UPDATE billing_products SET is_active = 1 - is_active WHERE id = :id")
               ->execute(['id' => $prod_id]);
            $message = 'Product status updated.';
        }
    }
}

// =========================================================
// FETCH DATA
// =========================================================
$categories = $db->query("SELECT * FROM billing_categories ORDER BY display_order ASC")->fetchAll();
$all_products = $db->query(
    "SELECT p.*, c.category_name
       FROM billing_products p
       JOIN billing_categories c ON p.category_id = c.id
      ORDER BY c.display_order ASC, p.product_name ASC"
)->fetchAll();

// Group products by category
$products_by_cat = [];
foreach ($all_products as $prod) {
    $products_by_cat[$prod['category_id']][] = $prod;
}

// Fetch all variants and group by product
$all_variants = $db->query("SELECT * FROM billing_product_variants ORDER BY id ASC")->fetchAll();
$variants_by_prod = [];
foreach ($all_variants as $v) {
    $variants_by_prod[$v['product_id']][] = $v;
}

// Summary stats
$total_products = count($all_products);
$active_products = count(array_filter($all_products, fn($p) => $p['is_active']));
$total_cats = count($categories);
?>

<!-- Page Header -->
<div class="content-header">
    <div class="header-title">
        <h1>Billing Catalogue</h1>
        <p>Manage products, categories, and size-based price variants for quick POS billing.</p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <button onclick="openModal('addCategoryModal')" class="btn btn-secondary">
            <i class="fa-solid fa-folder-plus"></i> New Category
        </button>
        <button onclick="openAddProductModal()" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add Product
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
        <div class="stat-icon"><i class="fa-solid fa-tags"></i></div>
        <div class="stat-info">
            <h3><?= $total_products ?></h3>
            <p>Total Products</p>
        </div>
    </div>
    <div class="card stat-card green">
        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <h3><?= $active_products ?></h3>
            <p>Active Products</p>
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
        <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="stat-info">
            <h3><?= count($all_variants) ?></h3>
            <p>Total Variants</p>
        </div>
    </div>
</div>

<!-- Categories & Products List -->
<div style="display:flex;flex-direction:column;gap:2rem;">
    <?php if (empty($categories)): ?>
        <div class="card" style="text-align:center;padding:3rem;color:var(--text-muted);">
            <i class="fa-solid fa-box-open" style="font-size:3rem;margin-bottom:1rem;opacity:0.4;"></i>
            <p>No categories yet. Click <strong>New Category</strong> to get started.</p>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <?php $cat_prods = $products_by_cat[$cat['id']] ?? []; ?>
            <div class="card">
                <!-- Category Header -->
                <h2 class="card-title" style="border-bottom:1px solid var(--border-color);padding-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
                    <span style="display:flex;align-items:center;gap:0.75rem;">
                        <span style="width:36px;height:36px;border-radius:var(--border-radius-sm);background:rgba(255,107,53,0.12);display:flex;align-items:center;justify-content:center;">
                            <i class="fa-solid fa-folder-open" style="color:var(--accent-color);font-size:1rem;"></i>
                        </span>
                        <?= h($cat['category_name']) ?>
                        <span style="font-size:0.78rem;font-weight:400;color:var(--text-muted);"><?= count($cat_prods) ?> product<?= count($cat_prods) !== 1 ? 's' : '' ?></span>
                    </span>
                    <div style="display:flex;gap:0.4rem;">
                        <button
                            class="btn btn-secondary"
                            style="padding:0.3rem 0.65rem;font-size:0.78rem;"
                            onclick="openEditCategory(<?= $cat['id'] ?>, <?= htmlspecialchars(json_encode($cat['category_name']), ENT_QUOTES) ?>)"
                        >
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </button>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this category and ALL its products?');">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.65rem;font-size:0.78rem;">
                                <i class="fa-solid fa-trash-can"></i> Delete
                            </button>
                        </form>
                    </div>
                </h2>

                <!-- Products Table -->
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:25%;">Product</th>
                                <th style="width:25%;">Description</th>
                                <th style="width:12%;text-align:center;">Base Price</th>
                                <th style="width:20%;">Variants & Pricing</th>
                                <th style="width:10%;text-align:center;">Status</th>
                                <th style="width:8%;text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cat_prods)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;color:var(--text-muted);padding:1.75rem 0;">
                                        No products in this category yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cat_prods as $prod): ?>
                                    <?php $prod_variants = $variants_by_prod[$prod['id']] ?? []; ?>
                                    <tr style="<?= !$prod['is_active'] ? 'opacity:0.5;' : '' ?>">
                                        <td style="font-weight:600;color:var(--text-primary);">
                                            <?= h($prod['product_name']) ?>
                                        </td>
                                        <td style="color:var(--text-secondary);font-size:0.84rem;">
                                            <?= h($prod['description'] ?: '—') ?>
                                        </td>
                                        <td style="text-align:center;font-weight:700;color:var(--accent-color);">
                                            <?= format_price($prod['base_price']) ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;flex-direction:column;gap:0.3rem;">
                                                <?php foreach ($prod_variants as $v): ?>
                                                    <div style="font-size:0.82rem;display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.03);padding:0.2rem 0.5rem;border-radius:4px;border:1px solid var(--border-color);">
                                                        <span><?= h($v['size']) ?></span>
                                                        <span style="font-weight:600;">
                                                            <?php if ($v['price'] === null): ?>
                                                                <span style="color:var(--text-muted);font-style:italic;" title="Inherits Base Price"><?= format_price($prod['base_price']) ?> (inherited)</span>
                                                            <?php else: ?>
                                                                <?= format_price($v['price']) ?>
                                                            <?php endif; ?>
                                                        </span>
                                                        <div style="display:flex;gap:0.25rem;">
                                                            <button
                                                                onclick='openEditVariantModal(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'
                                                                style="background:none;border:none;color:var(--text-secondary);cursor:pointer;padding:0 2px;"
                                                                title="Edit Variant"
                                                            >
                                                                <i class="fa-solid fa-pencil" style="font-size:0.75rem;"></i>
                                                            </button>
                                                            <form method="POST" style="margin:0;display:inline;" onsubmit="return confirm('Delete this variant?');">
                                                                <input type="hidden" name="action" value="delete_variant">
                                                                <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
                                                                <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;padding:0 2px;" title="Delete Variant">
                                                                    <i class="fa-solid fa-trash" style="font-size:0.75rem;"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <button
                                                    class="btn btn-secondary"
                                                    style="padding:0.2rem 0.5rem;font-size:0.75rem;align-self:flex-start;margin-top:0.25rem;"
                                                    onclick="openAddVariantModal(<?= $prod['id'] ?>, <?= htmlspecialchars(json_encode($prod['product_name']), ENT_QUOTES) ?>)"
                                                >
                                                    <i class="fa-solid fa-plus-circle"></i> Add Size/Variant
                                                </button>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <form method="POST" style="margin:0;display:inline;">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                                <button type="submit" title="Click to toggle status" style="border:none;background:none;cursor:pointer;padding:0;">
                                                    <?php if (!$prod['is_active']): ?>
                                                        <span class="badge badge-cancelled">Inactive</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-confirmed">Active</span>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td style="text-align:right;">
                                            <div style="display:inline-flex;gap:0.35rem;">
                                                <button
                                                    class="btn btn-secondary"
                                                    style="padding:0.3rem 0.55rem;font-size:0.75rem;"
                                                    onclick='openEditProductModal(<?= htmlspecialchars(json_encode($prod), ENT_QUOTES) ?>)'
                                                >
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>
                                                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this product?');">
                                                    <input type="hidden" name="action" value="delete_product">
                                                    <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
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
                <input type="text" name="category_name" class="form-control" placeholder="e.g. Birthday Items" required>
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
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('editCategoryModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: Add / Edit Product
     ============================================================ -->
<div id="productModal" class="modal">
    <div class="modal-content" style="max-width:560px;">
        <button class="modal-close" onclick="closeModal('productModal')">&times;</button>
        <h3 id="productModalTitle" style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Product
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="product_id" id="productId" value="0">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" id="productCategoryId" class="form-control" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= h($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Base Price (Rs)</label>
                    <input type="number" step="0.01" min="0" name="base_price" id="productBasePrice" class="form-control" placeholder="120" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Product Name</label>
                <input type="text" name="product_name" id="productName" class="form-control" placeholder="e.g. Premium Balloon Pack" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="productDesc" class="form-control" rows="2" placeholder="Short description of the product..."></textarea>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:0.6rem;padding-top:0.5rem;">
                <input type="checkbox" name="is_active" id="productActive" value="1" checked style="width:18px;height:18px;accent-color:var(--accent-color);cursor:pointer;">
                <label for="productActive" class="form-label" style="margin:0;cursor:pointer;">Mark as Active</label>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('productModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" id="productSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: Add / Edit Variant
     ============================================================ -->
<div id="variantModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('variantModal')">&times;</button>
        <h3 id="variantModalTitle" style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Size/Variant
        </h3>
        <p id="variantProductLabel" style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:1rem;"></p>
        <form method="POST">
            <input type="hidden" name="action" value="save_variant">
            <input type="hidden" name="product_id" id="variantProductId">
            <input type="hidden" name="variant_id" id="variantId" value="0">

            <div class="form-group">
                <label class="form-label">Size / Label</label>
                <input type="text" name="size" id="variantSize" class="form-control" placeholder="e.g. Standard (50 pcs) or Large" required>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <div style="display:flex;align-items:center;gap:0.6rem;">
                    <input type="checkbox" id="variantInherit" name="inherit_price" value="1" checked style="width:18px;height:18px;accent-color:var(--accent-color);cursor:pointer;">
                    <label for="variantInherit" class="form-label" style="margin:0;cursor:pointer;">Inherit Product Base Price</label>
                </div>
            </div>

            <div class="form-group" id="variantPriceGroup" style="display:none;margin-top:1rem;">
                <label class="form-label">Custom Variant Price (Rs)</label>
                <input type="number" step="0.01" min="0" name="price" id="variantPrice" class="form-control" placeholder="180">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.5rem;">
                <button type="button" onclick="closeModal('variantModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Variant</button>
            </div>
        </form>
    </div>
</div>

<script>
// Category Modals
function openEditCategory(id, name) {
    document.getElementById('editCatId').value = id;
    document.getElementById('editCatName').value = name;
    openModal('editCategoryModal');
}

// Product Modals
function openAddProductModal() {
    document.getElementById('productModalTitle').innerHTML =
        '<i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Product';
    document.getElementById('productId').value = '0';
    document.getElementById('productCategoryId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('productDesc').value = '';
    document.getElementById('productBasePrice').value = '';
    document.getElementById('productActive').checked = true;
    openModal('productModal');
}

function openEditProductModal(prod) {
    document.getElementById('productModalTitle').innerHTML =
        '<i class="fa-solid fa-pen-to-square" style="color:var(--accent-color);"></i> Edit Product';
    document.getElementById('productId').value = prod.id;
    document.getElementById('productCategoryId').value = prod.category_id;
    document.getElementById('productName').value = prod.product_name;
    document.getElementById('productDesc').value = prod.description || '';
    document.getElementById('productBasePrice').value = prod.base_price;
    document.getElementById('productActive').checked = parseInt(prod.is_active) === 1;
    openModal('productModal');
}

// Variant Modals
const inheritCheckbox = document.getElementById('variantInherit');
const priceGroup = document.getElementById('variantPriceGroup');
const priceInput = document.getElementById('variantPrice');

inheritCheckbox.addEventListener('change', function() {
    if (this.checked) {
        priceGroup.style.display = 'none';
        priceInput.removeAttribute('required');
    } else {
        priceGroup.style.display = 'block';
        priceInput.setAttribute('required', 'required');
    }
});

function openAddVariantModal(productId, productName) {
    document.getElementById('variantModalTitle').innerHTML =
        '<i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Size/Variant';
    document.getElementById('variantProductLabel').innerText = 'Product: ' + productName;
    document.getElementById('variantProductId').value = productId;
    document.getElementById('variantId').value = '0';
    document.getElementById('variantSize').value = '';
    inheritCheckbox.checked = true;
    priceGroup.style.display = 'none';
    priceInput.removeAttribute('required');
    priceInput.value = '';
    openModal('variantModal');
}

function openEditVariantModal(v) {
    document.getElementById('variantModalTitle').innerHTML =
        '<i class="fa-solid fa-pen-to-square" style="color:var(--accent-color);"></i> Edit Size/Variant';
    document.getElementById('variantProductLabel').innerText = '';
    document.getElementById('variantProductId').value = v.product_id;
    document.getElementById('variantId').value = v.id;
    document.getElementById('variantSize').value = v.size;
    if (v.price === null || v.price === undefined) {
        inheritCheckbox.checked = true;
        priceGroup.style.display = 'none';
        priceInput.removeAttribute('required');
        priceInput.value = '';
    } else {
        inheritCheckbox.checked = false;
        priceGroup.style.display = 'block';
        priceInput.setAttribute('required', 'required');
        priceInput.value = v.price;
    }
    openModal('variantModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
