<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db      = get_db_connection();
$message = '';
$error   = '';

$product_id = (int)($_GET['product_id'] ?? 0);

// Fetch product details
$stmt = $db->prepare("
    SELECT p.*, c.category_name 
      FROM billing_products p
      JOIN billing_categories c ON p.category_id = c.id
     WHERE p.id = :id
");
$stmt->execute(['id' => $product_id]);
$product = $stmt->fetch();

if (!$product) {
    echo "<div class='card' style='text-align:center; padding:3rem; margin:2rem;'><h3>Product not found.</h3><a href='billing-products.php' class='btn btn-primary' style='margin-top:1rem;'>Back to Products</a></div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

function generate_unique_barcode($db) {
    do {
        // EAN-13 internal store barcode prefix (200) + 9 random digits
        $barcode = '200' . str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        
        // EAN-13 checksum digit calculation
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $num = (int)$barcode[$i];
            $sum += ($i % 2 === 0) ? $num * 1 : $num * 3;
        }
        $checksum = (10 - ($sum % 10)) % 10;
        $final_barcode = $barcode . $checksum;
        
        // Ensure uniqueness
        $stmt = $db->prepare("SELECT id FROM billing_product_variants WHERE barcode = :barcode");
        $stmt->execute(['barcode' => $final_barcode]);
    } while ($stmt->fetch());
    
    return $final_barcode;
}

// =========================================================
// POST HANDLERS (Add / Edit / Delete Variant)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── SAVE VARIANT ──────────────────────────────────────
    if ($action === 'save_variant') {
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        $size       = trim($_POST['size'] ?? '');
        $inherit    = isset($_POST['inherit_price']) ? 1 : 0;
        $price      = $inherit ? null : (float)($_POST['price'] ?? 0);
        $barcode    = trim($_POST['barcode'] ?? '');

        if (empty($size)) {
            $error = 'Size / Label is required.';
        } else {
            $barcode_ok = true;
            if (!empty($barcode)) {
                $stmt_chk = $db->prepare("SELECT id FROM billing_product_variants WHERE barcode = :barcode AND id != :id");
                $stmt_chk->execute(['barcode' => $barcode, 'id' => $variant_id]);
                if ($stmt_chk->fetch()) {
                    $error = "Barcode '{$barcode}' is already in use by another variant.";
                    $barcode_ok = false;
                }
            } else {
                if ($variant_id === 0) {
                    $barcode = generate_unique_barcode($db);
                } else {
                    $stmt_cur = $db->prepare("SELECT barcode FROM billing_product_variants WHERE id = :id");
                    $stmt_cur->execute(['id' => $variant_id]);
                    $curr_barcode = $stmt_cur->fetchColumn();
                    if (empty($curr_barcode)) {
                        $barcode = generate_unique_barcode($db);
                    } else {
                        $barcode = $curr_barcode;
                    }
                }
            }

            if ($barcode_ok) {
                if ($variant_id > 0) {
                    $stmt_upd = $db->prepare("
                        UPDATE billing_product_variants 
                           SET size = :size, price = :price, barcode = :barcode 
                         WHERE id = :id AND product_id = :prod_id
                    ");
                    $stmt_upd->execute([
                        'size' => $size,
                        'price' => $price,
                        'barcode' => $barcode,
                        'id' => $variant_id,
                        'prod_id' => $product_id
                    ]);
                    $message = 'Variant updated successfully!';
                } else {
                    $stmt_ins = $db->prepare("
                        INSERT INTO billing_product_variants (product_id, size, price, barcode) 
                        VALUES (:prod, :size, :price, :barcode)
                    ");
                    $stmt_ins->execute([
                        'prod' => $product_id,
                        'size' => $size,
                        'price' => $price,
                        'barcode' => $barcode
                    ]);
                    $message = 'Variant added successfully!';
                }
            }
        }
    }

    // ── DELETE VARIANT ────────────────────────────────────
    if ($action === 'delete_variant') {
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        if ($variant_id > 0) {
            try {
                $stmt_del = $db->prepare("
                    DELETE FROM billing_product_variants 
                     WHERE id = :id AND product_id = :prod_id
                ");
                $stmt_del->execute([
                    'id' => $variant_id,
                    'prod_id' => $product_id
                ]);
                $message = 'Variant deleted.';
            } catch (PDOException $e) {
                $error = 'Cannot delete variant. It may be referenced in past orders.';
            }
        }
    }
}

// Fetch all variants for this product
$stmt_vars = $db->prepare("
    SELECT * 
      FROM billing_product_variants 
     WHERE product_id = :prod_id 
     ORDER BY id ASC
");
$stmt_vars->execute(['prod_id' => $product_id]);
$variants = $stmt_vars->fetchAll();
?>

<!-- Back Button and Title -->
<div class="content-header">
    <div class="header-title">
        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
            <a href="billing-products.php" class="btn btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.85rem;">
                <i class="fa-solid fa-arrow-left-long"></i> Back
            </a>
            <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.75rem; padding:0.2rem 0.5rem;">
                <?= h($product['category_name']) ?>
            </span>
        </div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; margin-top:0.25rem;">
            <i class="fa-solid fa-layer-group" style="color:var(--accent-color);"></i>
            Variants of <?= h($product['product_name']) ?>
        </h1>
        <p style="color:var(--text-muted); margin-top:0.25rem;">
            Manage size options and custom price overrides for this product.
        </p>
    </div>
    <div>
        <button onclick="openAddVariantModal()" class="btn btn-primary">
            <i class="fa-solid fa-plus-circle"></i> Add Size / Variant
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

<div style="display:grid; grid-template-columns: 280px 1fr; gap:1.5rem; align-items:start;">
    <!-- Product Info Sidebar Card -->
    <div class="card" style="padding:1.25rem; display:flex; flex-direction:column; gap:1rem;">
        <h3 style="font-size:1rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; color:var(--text-primary);">
            Product Details
        </h3>
        
        <!-- Image display -->
        <?php if (!empty($product['image_path'])): ?>
            <div style="width:100%; height:150px; border-radius:var(--border-radius-sm); overflow:hidden; border:1px solid var(--border-color);">
                <img src="../<?= h($product['image_path']) ?>" alt="Product Image" style="width:100%; height:100%; object-fit:cover;">
            </div>
        <?php else: ?>
            <div style="width:100%; height:150px; border-radius:var(--border-radius-sm); background:var(--bg-control); display:flex; align-items:center; justify-content:center; color:var(--text-muted); border:1px solid var(--border-color);">
                <i class="fa-solid fa-image" style="font-size:3rem;"></i>
            </div>
        <?php endif; ?>

        <div>
            <div style="font-size:0.8rem; color:var(--text-muted);">Base Price</div>
            <div style="font-size:1.4rem; font-weight:700; color:var(--accent-color);"><?= format_price($product['base_price']) ?></div>
        </div>

        <div>
            <div style="font-size:0.8rem; color:var(--text-muted);">Description</div>
            <div style="font-size:0.85rem; color:var(--text-secondary); line-height:1.4; margin-top:0.25rem;">
                <?= h($product['description'] ?: 'No description provided.') ?>
            </div>
        </div>

        <div>
            <div style="font-size:0.8rem; color:var(--text-muted);">Status</div>
            <div style="margin-top:0.25rem;">
                <?php if ($product['is_active']): ?>
                    <span class="badge badge-confirmed">Active in POS</span>
                <?php else: ?>
                    <span class="badge badge-cancelled">Inactive</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Variants List Table Card -->
    <div class="card" style="padding:1.25rem;">
        <h3 style="font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:1rem; display:flex; align-items:center; justify-content:between;">
            <span>Active Variants</span>
            <span style="font-size:0.8rem; font-weight:normal; color:var(--text-muted);">(<?= count($variants) ?> sizes)</span>
        </h3>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:40%;">Size / Option Name</th>
                        <th style="width:25%; text-align:center;">Barcode</th>
                        <th style="width:20%; text-align:center;">Selling Price</th>
                        <th style="width:15%; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($variants)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:3rem; color:var(--text-muted);">
                                <i class="fa-solid fa-layer-group" style="font-size:2rem; margin-bottom:0.5rem; opacity:0.3; display:block;"></i>
                                No custom sizes/variants configured. This product will sell at the base price of <?= format_price($product['base_price']) ?>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($variants as $v): 
                            $v_price = $v['price'] !== null ? $v['price'] : $product['base_price'];
                        ?>
                            <tr>
                                <td style="font-weight:600; color:var(--text-primary); vertical-align:middle;">
                                    <?= h($v['size']) ?>
                                </td>
                                <td style="text-align:center; font-family: monospace; font-size:0.9rem; color:var(--text-secondary); vertical-align:middle;">
                                    <?= h($v['barcode'] ?: 'N/A') ?>
                                </td>
                                <td style="text-align:center; font-weight:700; color:var(--accent-color); vertical-align:middle;">
                                    <?= format_price($v_price) ?>
                                    <?php if ($v['price'] === null): ?>
                                        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:normal; display:block;">(Inherited from Base)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; vertical-align:middle;">
                                    <div style="display:inline-flex; gap:0.35rem;">
                                        <button
                                            class="btn btn-secondary"
                                            style="padding:0.3rem 0.55rem; font-size:0.75rem;"
                                            onclick='openEditVariantModal(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'
                                            title="Edit Variant"
                                        >
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this variant size option?');">
                                            <input type="hidden" name="action" value="delete_variant">
                                            <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.55rem; font-size:0.75rem;" title="Delete Variant">
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
</div>

<!-- ============================================================
     MODAL: Add / Edit Variant
     ============================================================ -->
<div id="variantModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <button class="modal-close" onclick="closeModal('variantModal')">&times;</button>
        <h3 id="variantModalTitle" style="margin-bottom:1.5rem;">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Size/Variant
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_variant">
            <input type="hidden" name="variant_id" id="variantId" value="0">

            <div class="form-group">
                <label class="form-label">Size / Variant Label</label>
                <input type="text" name="size" id="variantSize" class="form-control" placeholder="e.g. Standard, Large, Metallic" required>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Barcode (Auto-generated if left blank)</label>
                <input type="text" name="barcode" id="variantBarcode" class="form-control" placeholder="e.g. 200123456789">
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <div style="display:flex; align-items:center; gap:0.6rem;">
                    <input type="checkbox" id="variantInherit" name="inherit_price" value="1" checked style="width:18px; height:18px; accent-color:var(--accent-color); cursor:pointer;">
                    <label for="variantInherit" class="form-label" style="margin:0; cursor:pointer;">Inherit Product Base Price (<?= format_price($product['base_price']) ?>)</label>
                </div>
            </div>

            <div class="form-group" id="variantPriceGroup" style="display:none; margin-top:1rem;">
                <label class="form-label">Custom Price for this Variant (Rs)</label>
                <input type="number" step="0.01" min="0" name="price" id="variantPrice" class="form-control" placeholder="<?= $product['base_price'] ?>">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeModal('variantModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Variant</button>
            </div>
        </form>
    </div>
</div>

<script>
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

function openAddVariantModal() {
    document.getElementById('variantModalTitle').innerHTML =
        '<i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Size/Variant';
    document.getElementById('variantId').value = '0';
    document.getElementById('variantSize').value = '';
    document.getElementById('variantBarcode').value = '';
    inheritCheckbox.checked = true;
    priceGroup.style.display = 'none';
    priceInput.removeAttribute('required');
    priceInput.value = '';
    openModal('variantModal');
}

function openEditVariantModal(v) {
    document.getElementById('variantModalTitle').innerHTML =
        '<i class="fa-solid fa-pen-to-square" style="color:var(--accent-color);"></i> Edit Size/Variant';
    document.getElementById('variantId').value = v.id;
    document.getElementById('variantSize').value = v.size;
    document.getElementById('variantBarcode').value = v.barcode || '';
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
