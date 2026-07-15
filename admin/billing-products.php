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
            // Image Upload Handling
            $image_path = null;
            if ($prod_id > 0) {
                // Get existing image path in case no new image is uploaded
                $stmt_img = $db->prepare("SELECT image_path FROM billing_products WHERE id = :id");
                $stmt_img->execute(['id' => $prod_id]);
                $image_path = $stmt_img->fetchColumn();
            }

            if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['product_image']['tmp_name'];
                $orig_name = $_FILES['product_image']['name'];
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                if (in_array($ext, $allowed)) {
                    $upload_dir = __DIR__ . '/../uploads/billing/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $new_name = 'prod_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $dest = $upload_dir . $new_name;

                    if (move_uploaded_file($tmp_name, $dest)) {
                        // Delete old image if exists
                        if ($image_path && file_exists(__DIR__ . '/../' . $image_path)) {
                            @unlink(__DIR__ . '/../' . $image_path);
                        }
                        $image_path = 'uploads/billing/' . $new_name;
                    } else {
                        $error = 'Failed to move uploaded file.';
                    }
                } else {
                    $error = 'Invalid image type. Allowed: JPG, JPEG, PNG, WEBP, GIF.';
                }
            }

            if (empty($error)) {
                if ($prod_id > 0) {
                    $stmt = $db->prepare(
                        "UPDATE billing_products
                            SET category_id = :cat, product_name = :name, description = :desc,
                                base_price = :price, is_active = :active, image_path = :img
                          WHERE id = :id"
                    );
                    $stmt->execute([
                        'cat' => $cat_id, 'name' => $prod_name, 'desc' => $desc,
                        'price' => $base_price, 'active' => $is_active, 'img' => $image_path, 'id' => $prod_id
                    ]);
                    $message = 'Product updated successfully!';
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO billing_products (category_id, product_name, description, base_price, is_active, image_path)
                         VALUES (:cat, :name, :desc, :price, :active, :img)"
                    );
                    $stmt->execute([
                        'cat' => $cat_id, 'name' => $prod_name, 'desc' => $desc,
                        'price' => $base_price, 'active' => $is_active, 'img' => $image_path
                    ]);
                    $message = 'Product added successfully!';
                }
            }
        }
    }

    // ── DELETE PRODUCT ────────────────────────────────────
    if ($action === 'delete_product') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        if ($prod_id > 0) {
            try {
                // Delete image file first
                $stmt_img = $db->prepare("SELECT image_path FROM billing_products WHERE id = :id");
                $stmt_img->execute(['id' => $prod_id]);
                $img = $stmt_img->fetchColumn();
                if ($img && file_exists(__DIR__ . '/../' . $img)) {
                    @unlink(__DIR__ . '/../' . $img);
                }

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
?>

<!-- Page Header -->
<div class="content-header">
    <div class="header-title">
        <h1>Billing Products</h1>
        <p>Manage catalog products, sizes, prices, and upload product images.</p>
    </div>
    <div>
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
<!-- Catalog Search -->
<div class="card" style="padding:1rem; margin-bottom:1.5rem;">
    <div style="position:relative; max-width:450px;">
        <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:1rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.9rem;"></i>
        <input type="text" id="catalogSearch" class="form-control" placeholder="Search products or variants..." style="padding-left:2.5rem; margin:0;">
    </div>
</div>

<!-- Products Grid by Category -->
<div style="display:flex; flex-direction:column; gap:2rem;">
    <?php if (empty($categories)): ?>
        <div class="card" style="text-align:center; padding:3rem; color:var(--text-muted);">
            <i class="fa-solid fa-box-open" style="font-size:3rem; margin-bottom:1rem; opacity:0.4;"></i>
            <p>No categories found. Please create categories first on the <a href="billing-categories.php" style="color:var(--accent-color); font-weight:600;">Categories Page</a>.</p>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <?php $cat_prods = $products_by_cat[$cat['id']] ?? []; ?>
            <div class="card">
                <!-- Category Title -->
                <h2 class="card-title" style="border-bottom:1px solid var(--border-color); padding-bottom:0.75rem;">
                    <span style="display:flex; align-items:center; gap:0.5rem;">
                        <i class="fa-solid fa-folder-open" style="color:var(--accent-color);"></i>
                        <?= h($cat['category_name']) ?>
                        <span style="font-size:0.8rem; font-weight:400; color:var(--text-muted);">(<?= count($cat_prods) ?> Products)</span>
                    </span>
                </h2>

                <!-- Products Table -->
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:8%; text-align:center;">Image</th>
                                <th style="width:25%;">Product / Variant Name</th>
                                <th style="width:22%;">Description</th>
                                <th style="width:12%; text-align:center;">Price</th>
                                <th style="width:15%; text-align:center;">Type / Size</th>
                                <th style="width:10%; text-align:center;">Status</th>
                                <th style="width:8%; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cat_prods)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; color:var(--text-muted); padding:1.5rem 0;">
                                        No products in this category.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cat_prods as $prod): ?>
                                    <?php $prod_variants = $variants_by_prod[$prod['id']] ?? []; ?>
                                    
                                    <!-- Base Product Row -->
                                    <tr class="product-row" 
                                        data-search-text="<?= htmlspecialchars(strtolower($prod['product_name'] . ' ' . ($prod['description'] ?? '')), ENT_QUOTES) ?>" 
                                        style="<?= !$prod['is_active'] ? 'opacity:0.6;' : '' ?>">
                                        
                                        <!-- Image -->
                                        <td style="text-align:center; vertical-align:middle;">
                                            <?php if (!empty($prod['image_path'])): ?>
                                                <img src="../<?= h($prod['image_path']) ?>" alt="Product Image" style="width:40px; height:40px; object-fit:cover; border-radius:var(--border-radius-sm); border:1px solid var(--border-color);">
                                            <?php else: ?>
                                                <div style="width:40px; height:40px; border-radius:var(--border-radius-sm); background:var(--bg-control); display:flex; align-items:center; justify-content:center; color:var(--text-muted); border:1px solid var(--border-color);">
                                                    <i class="fa-solid fa-image" style="font-size:1rem;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Name -->
                                        <td style="font-weight:600; color:var(--text-primary); vertical-align:middle;">
                                            <?= h($prod['product_name']) ?>
                                        </td>

                                        <!-- Description -->
                                        <td style="color:var(--text-secondary); font-size:0.85rem; vertical-align:middle;">
                                            <?= h($prod['description'] ?: '—') ?>
                                        </td>

                                        <!-- Price -->
                                        <td style="text-align:center; font-weight:700; color:var(--accent-color); vertical-align:middle;">
                                            <?= format_price($prod['base_price']) ?>
                                        </td>

                                        <!-- Type / Size / Add Variant button -->
                                        <td style="text-align:center; vertical-align:middle;">
                                            <div style="display:flex; flex-direction:column; align-items:center; gap:0.25rem;">
                                                <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.7rem; padding:0.15rem 0.4rem;">Base Product</span>
                                                <button
                                                    class="btn btn-secondary"
                                                    style="padding:0.15rem 0.4rem; font-size:0.65rem;"
                                                    onclick="openAddVariantModal(<?= $prod['id'] ?>, <?= htmlspecialchars(json_encode($prod['product_name']), ENT_QUOTES) ?>)"
                                                    title="Add a new variant/size override for this product"
                                                >
                                                    <i class="fa-solid fa-plus-circle"></i> Add Size
                                                </button>
                                            </div>
                                        </td>

                                        <!-- Status -->
                                        <td style="text-align:center; vertical-align:middle;">
                                            <form method="POST" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                                <button type="submit" title="Click to toggle status" style="border:none; background:none; cursor:pointer; padding:0;">
                                                    <?php if (!$prod['is_active']): ?>
                                                        <span class="badge badge-cancelled">Inactive</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-confirmed">Active</span>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align:right; vertical-align:middle;">
                                            <div style="display:inline-flex; gap:0.35rem;">
                                                <button
                                                    class="btn btn-secondary"
                                                    style="padding:0.3rem 0.55rem; font-size:0.75rem;"
                                                    onclick='openEditProductModal(<?= htmlspecialchars(json_encode($prod), ENT_QUOTES) ?>)'
                                                    title="Edit Product Details"
                                                >
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>
                                                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this product? All its variants will also be deleted.');">
                                                    <input type="hidden" name="action" value="delete_product">
                                                    <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                                    <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.55rem; font-size:0.75rem;" title="Delete Product">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Variant Rows -->
                                    <?php foreach ($prod_variants as $v): 
                                        $v_price = $v['price'] !== null ? $v['price'] : $prod['base_price'];
                                    ?>
                                        <tr class="product-row variant-row" 
                                            data-search-text="<?= htmlspecialchars(strtolower($prod['product_name'] . ' ' . $v['size']), ENT_QUOTES) ?>" 
                                            style="background:rgba(255,255,255,0.015); <?= !$prod['is_active'] ? 'opacity:0.6;' : '' ?>">
                                            
                                            <!-- Faded Parent Image -->
                                            <td style="text-align:center; vertical-align:middle; opacity:0.5;">
                                                <?php if (!empty($prod['image_path'])): ?>
                                                    <img src="../<?= h($prod['image_path']) ?>" alt="Product Image" style="width:32px; height:32px; object-fit:cover; border-radius:var(--border-radius-sm); border:1px solid var(--border-color);">
                                                <?php else: ?>
                                                    <div style="width:32px; height:32px; border-radius:var(--border-radius-sm); background:var(--bg-control); display:flex; align-items:center; justify-content:center; color:var(--text-muted); border:1px solid var(--border-color);">
                                                        <i class="fa-solid fa-image" style="font-size:0.8rem;"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Indented Variant Name -->
                                            <td style="vertical-align:middle; padding-left:1.5rem; color:var(--text-secondary);">
                                                <i class="fa-solid fa-arrow-turn-up" style="transform: rotate(90deg); margin-right:0.5rem; color:var(--text-muted);"></i>
                                                <span style="font-weight:500;"><?= h($v['size']) ?></span>
                                            </td>

                                            <!-- Reference Desc -->
                                            <td style="color:var(--text-muted); font-size:0.8rem; vertical-align:middle; font-style:italic;">
                                                Size override for <?= h($prod['product_name']) ?>
                                            </td>

                                            <!-- Variant Price -->
                                            <td style="text-align:center; font-weight:700; color:var(--accent-color); vertical-align:middle;">
                                                <?= format_price($v_price) ?>
                                                <?php if ($v['price'] === null): ?>
                                                    <span style="font-size:0.7rem; color:var(--text-muted); font-weight:normal; display:block;">(Inherited)</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Size Tag Badge -->
                                            <td style="text-align:center; vertical-align:middle;">
                                                <span class="badge" style="background:rgba(54,162,235,0.08); color:var(--info); font-size:0.7rem; padding:0.15rem 0.4rem;">Size Option</span>
                                            </td>

                                            <!-- Parent Status Inheritance -->
                                            <td style="text-align:center; vertical-align:middle;">
                                                <?php if (!$prod['is_active']): ?>
                                                    <span class="badge badge-cancelled" title="Parent product is inactive">Inactive</span>
                                                <?php else: ?>
                                                    <span class="badge badge-confirmed">Active</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Variant Actions -->
                                            <td style="text-align:right; vertical-align:middle;">
                                                <div style="display:inline-flex; gap:0.35rem;">
                                                    <button
                                                        class="btn btn-secondary"
                                                        style="padding:0.25rem 0.5rem; font-size:0.7rem;"
                                                        onclick='openEditVariantModal(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'
                                                        title="Edit Size / Price"
                                                    >
                                                        <i class="fa-solid fa-pencil"></i>
                                                    </button>
                                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this variant?');">
                                                        <input type="hidden" name="action" value="delete_variant">
                                                        <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding:0.25rem 0.5rem; font-size:0.7rem;" title="Delete Variant">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

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
     MODAL: Add / Edit Product
     ============================================================ -->
<div id="productModal" class="modal">
    <div class="modal-content" style="max-width:560px;">
        <button class="modal-close" onclick="closeModal('productModal')">&times;</button>
        <h3 id="productModalTitle" style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Product
        </h3>
        <form method="POST" enctype="multipart/form-data">
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

            <div class="form-group">
                <label class="form-label">Product Image</label>
                <input type="file" name="product_image" id="productImage" class="form-control" accept="image/*">
                <p style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">Supported: JPG, JPEG, PNG, WEBP, GIF (Max 2MB)</p>
                <div id="imagePreviewContainer" style="display:none; margin-top:0.75rem; border:1px solid var(--border-color); border-radius:4px; padding:4px; display:inline-block;">
                    <img id="productImagePreview" src="" alt="Preview" style="max-height:80px; display:block; border-radius:4px;">
                </div>
            </div>

            <div class="form-group" style="display:flex; align-items:center; gap:0.6rem; padding-top:0.5rem;">
                <input type="checkbox" name="is_active" id="productActive" value="1" checked style="width:18px; height:18px; accent-color:var(--accent-color); cursor:pointer;">
                <label for="productActive" class="form-label" style="margin:0; cursor:pointer;">Mark as Active</label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
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
        <p id="variantProductLabel" style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:1rem;"></p>
        <form method="POST">
            <input type="hidden" name="action" value="save_variant">
            <input type="hidden" name="product_id" id="variantProductId">
            <input type="hidden" name="variant_id" id="variantId" value="0">

            <div class="form-group">
                <label class="form-label">Size / Label</label>
                <input type="text" name="size" id="variantSize" class="form-control" placeholder="e.g. Standard (50 pcs)" required>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <div style="display:flex; align-items:center; gap:0.6rem;">
                    <input type="checkbox" id="variantInherit" name="inherit_price" value="1" checked style="width:18px; height:18px; accent-color:var(--accent-color); cursor:pointer;">
                    <label for="variantInherit" class="form-label" style="margin:0; cursor:pointer;">Inherit Product Base Price</label>
                </div>
            </div>

            <div class="form-group" id="variantPriceGroup" style="display:none; margin-top:1rem;">
                <label class="form-label">Custom Variant Price (Rs)</label>
                <input type="number" step="0.01" min="0" name="price" id="variantPrice" class="form-control" placeholder="180">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeModal('variantModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Variant</button>
            </div>
        </form>
    </div>
</div>

<script>
// Product Modal Helpers
function openAddProductModal() {
    document.getElementById('productModalTitle').innerHTML =
        '<i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Product';
    document.getElementById('productId').value = '0';
    document.getElementById('productCategoryId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('productDesc').value = '';
    document.getElementById('productBasePrice').value = '';
    document.getElementById('productActive').checked = true;
    document.getElementById('productImage').value = '';
    document.getElementById('imagePreviewContainer').style.display = 'none';
    document.getElementById('productImagePreview').src = '';
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
    document.getElementById('productImage').value = '';
    
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('productImagePreview');
    if (prod.image_path) {
        previewImg.src = '../' + prod.image_path;
        previewContainer.style.display = 'inline-block';
    } else {
        previewContainer.style.display = 'none';
        previewImg.src = '';
    }
    openModal('productModal');
}

// Live Image Preview Selector
document.getElementById('productImage').addEventListener('change', function() {
    const file = this.files[0];
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('productImagePreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'inline-block';
        }
        reader.readAsDataURL(file);
    }
});

// Variant Modal Helpers
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

// Search Filtering Functionality
const searchInput = document.getElementById('catalogSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.product-row');
        rows.forEach(row => {
            const text = row.getAttribute('data-search-text') || '';
            if (text.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
