<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('billing_update');

$db = get_db_connection();
$message = '';
$error = '';

$product_id = (int) ($_GET['product_id'] ?? 0);

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

function get_effective_stock_label($variant)
{
    $stock = (float)$variant['stock_quantity'];
    $out = '<span class="badge" style="background:rgba(46,213,115,0.12); color:var(--success); font-weight:600;">' . number_format($stock, 2) . ' Packets</span>';
    
    if ($variant['allow_loose']) {
        $units = (float)$variant['loose_units_per_whole'];
        $loose_equiv = $stock * $units;
        $out .= '<div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">' . number_format($loose_equiv, 2) . ' Loose equivalent</div>';
        $out .= '<div style="font-size:0.7rem; color:var(--text-muted);">(' . number_format($units, 0) . ' units/pack @ ' . format_price($variant['loose_price'] ?: 0) . ')</div>';
    }
    
    return $out;
}

function generate_unique_barcode($db)
{
    do {
        // EAN-13 internal store barcode prefix (200) + 9 random digits
        $barcode = '200' . str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);

        // EAN-13 checksum digit calculation
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $num = (int) $barcode[$i];
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
        $variant_id = (int) ($_POST['variant_id'] ?? 0);
        $size = trim($_POST['size'] ?? '');
        $inherit = isset($_POST['inherit_price']) ? 1 : 0;
        $price = $inherit ? null : (float) ($_POST['price'] ?? 0);
        $raw_barcode = trim($_POST['barcode'] ?? '');
        $barcode = preg_replace('/^\][A-Za-z0-9]{1,4}\s*/', '', preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $raw_barcode));
        $stock_qty = (float) ($_POST['stock_quantity'] ?? 0);
        $allow_loose = isset($_POST['allow_loose']) ? 1 : 0;
        $loose_price = $_POST['loose_price'] !== '' ? (float) $_POST['loose_price'] : null;
        $loose_units_per_whole = $_POST['loose_units_per_whole'] !== '' ? (float) $_POST['loose_units_per_whole'] : 1.00;

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
                           SET size = :size, price = :price, barcode = :barcode,
                               stock_quantity = :stock_quantity,
                               allow_loose = :allow_loose,
                               loose_price = :loose_price,
                               loose_units_per_whole = :loose_units_per_whole
                          WHERE id = :id AND product_id = :prod_id
                    ");
                    $stmt_upd->execute([
                        'size' => $size,
                        'price' => $price,
                        'barcode' => $barcode,
                        'stock_quantity' => $stock_qty,
                        'allow_loose' => $allow_loose,
                        'loose_price' => $loose_price,
                        'loose_units_per_whole' => $loose_units_per_whole,
                        'id' => $variant_id,
                        'prod_id' => $product_id
                    ]);
                    $message = 'Variant updated successfully!';
                } else {
                    $stmt_ins = $db->prepare("
                        INSERT INTO billing_product_variants (product_id, size, price, barcode, stock_quantity, allow_loose, loose_price, loose_units_per_whole) 
                        VALUES (:prod, :size, :price, :barcode, :stock_quantity, :allow_loose, :loose_price, :loose_units_per_whole)
                    ");
                    $stmt_ins->execute([
                        'prod' => $product_id,
                        'size' => $size,
                        'price' => $price,
                        'barcode' => $barcode,
                        'stock_quantity' => $stock_qty,
                        'allow_loose' => $allow_loose,
                        'loose_price' => $loose_price,
                        'loose_units_per_whole' => $loose_units_per_whole
                    ]);
                    $message = 'Variant added successfully!';
                }
            }
        }
    }

    // ── DELETE VARIANT ────────────────────────────────────
    if ($action === 'delete_variant') {
        if (!has_permission('billing_delete')) {
            $error = 'Access denied: You do not have the required permission (billing_delete) to delete variants.';
        } else {
            $variant_id = (int) ($_POST['variant_id'] ?? 0);
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

<style>
    .table th, .table td { padding: 0.4rem 0.65rem; font-size: 0.8rem; }
    .card { padding: 0.85rem !important; }
</style>

<!-- Back Button and Title -->
<div class="content-header" style="margin-bottom: 0.75rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between; align-items:flex-start;">
    <div class="header-title">
        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
            <a href="billing-products.php" class="btn btn-secondary" style="padding:0.2rem 0.55rem; font-size:0.75rem; height:26px; display:inline-flex; align-items:center; gap:0.25rem;">
                <i class="fa-solid fa-arrow-left-long"></i> Back
            </a>
            <span class="badge" style="background:rgba(255,107,53,0.08); color:var(--accent-color); font-size:0.7rem; padding:0.15rem 0.45rem;">
                <?= h($product['category_name']) ?>
            </span>
        </div>
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-layer-group" style="color:var(--accent-color);"></i>
            <?= h($product['product_name']) ?> — Variants
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">Manage size options and custom price overrides for this product.</p>
    </div>
    <div style="display:flex; gap:0.4rem; flex-shrink:0;">
        <a href="print-barcode.php?product_id=<?= $product_id ?>" class="btn btn-secondary" style="background:rgba(255, 107, 53, 0.12); color:var(--accent-color); border-color:rgba(255,107,53,0.2); display:inline-flex; align-items:center; gap:0.3rem; height:32px; font-size:0.8rem; padding:0 0.75rem;" target="_blank">
            <i class="fa-solid fa-barcode"></i> Print Stickers
        </a>
        <button onclick="openAddVariantModal()" class="btn btn-primary" style="height:32px; font-size:0.8rem; padding:0 0.85rem;">
            <i class="fa-solid fa-plus-circle"></i> Add Variant
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if (!empty($message)): ?>
    <div
        style="background:rgba(46,213,115,0.15);color:var(--success);border:1px solid var(--success);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
        <i class="fa-solid fa-circle-check"></i> <span><?= h($message) ?></span>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div
        style="background:rgba(255,71,87,0.15);color:var(--danger);border:1px solid var(--danger);padding:0.75rem 1rem;border-radius:var(--border-radius-sm);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i> <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 220px 1fr; gap:0.75rem; align-items:start;">
    <!-- Product Info Sidebar Card -->
    <div class="card" style="display:flex; flex-direction:column; gap:0.65rem;">
        <h3 style="font-size:0.88rem; border-bottom:1px solid var(--border-color); padding-bottom:0.4rem; color:var(--text-primary); margin:0;">
            Product Details
        </h3>

        <!-- Image display -->
        <?php if (!empty($product['image_path'])): ?>
            <div style="width:100%; height:110px; border-radius:var(--border-radius-sm); overflow:hidden; border:1px solid var(--border-color);">
                <img src="../<?= h($product['image_path']) ?>" alt="Product Image" style="width:100%; height:100%; object-fit:cover;">
            </div>
        <?php else: ?>
            <div style="width:100%; height:80px; border-radius:var(--border-radius-sm); background:var(--bg-control); display:flex; align-items:center; justify-content:center; color:var(--text-muted); border:1px solid var(--border-color);">
                <i class="fa-solid fa-image" style="font-size:2rem;"></i>
            </div>
        <?php endif; ?>

        <div>
            <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">Base Price</div>
            <div style="font-size:1.15rem; font-weight:700; color:var(--accent-color);"><?= format_price($product['base_price']) ?></div>
        </div>

        <div>
            <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">Description</div>
            <div style="font-size:0.78rem; color:var(--text-secondary); line-height:1.4; margin-top:0.15rem;">
                <?= h($product['description'] ?: 'No description.') ?>
            </div>
        </div>

        <div>
            <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">Status</div>
            <div style="margin-top:0.2rem;">
                <?php if ($product['is_active']): ?>
                    <span class="badge badge-confirmed" style="font-size:0.7rem;">Active in POS</span>
                <?php else: ?>
                    <span class="badge badge-cancelled" style="font-size:0.7rem;">Inactive</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Variants List Table Card -->
    <div class="card">
        <h3 style="font-size:0.95rem; border-bottom:1px solid var(--border-color); padding-bottom:0.4rem; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
            <span>Active Variants</span>
            <span style="background:rgba(255,107,53,0.1); color:var(--accent-color); font-size:0.7rem; font-weight:700; padding:0.1rem 0.45rem; border-radius:20px;"><?= count($variants) ?> sizes</span>
        </h3>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:30%;">Size / Option Name</th>
                        <th style="width:20%; text-align:center;">Barcode</th>
                        <th style="width:22%; text-align:center;">Stock Level</th>
                        <th style="width:16%; text-align:center;">Selling Price</th>
                        <th style="width:12%; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($variants)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:3rem; color:var(--text-muted);">
                                <i class="fa-solid fa-layer-group"
                                    style="font-size:2rem; margin-bottom:0.5rem; opacity:0.3; display:block;"></i>
                                No custom sizes/variants configured. This product will sell at the base price of
                                <?= format_price($product['base_price']) ?>.
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
                                <td
                                    style="text-align:center; font-family: monospace; font-size:0.9rem; color:var(--text-secondary); vertical-align:middle;">
                                    <?= h($v['barcode'] ?: 'N/A') ?>
                                </td>
                                <td style="text-align:center; vertical-align:middle;">
                                     <?= get_effective_stock_label($v) ?>
                                </td>
                                <td
                                    style="text-align:center; font-weight:700; color:var(--accent-color); vertical-align:middle;">
                                    <?= format_price($v_price) ?>
                                    <?php if ($v['price'] === null): ?>
                                        <span
                                            style="font-size:0.75rem; color:var(--text-muted); font-weight:normal; display:block;">(Inherited
                                            from Base)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; vertical-align:middle;">
                                    <div style="display:inline-flex; gap:0.35rem;">
                                        <a href="print-barcode.php?variant_id=<?= $v['id'] ?>" class="btn btn-secondary" style="padding:0.3rem 0.55rem; font-size:0.75rem; background:rgba(255, 107, 53, 0.12); color:var(--accent-color); border-color:rgba(255,107,53,0.15); display:inline-flex; align-items:center;" target="_blank" title="Print Barcode Sticker">
                                            <i class="fa-solid fa-barcode"></i>
                                        </a>
                                        <button class="btn btn-secondary" style="padding:0.3rem 0.55rem; font-size:0.75rem;"
                                            onclick='openEditVariantModal(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'
                                            title="Edit Variant">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" style="margin:0;"
                                            onsubmit="return confirm('Delete this variant size option?');">
                                            <input type="hidden" name="action" value="delete_variant">
                                            <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
                                            <button type="submit" class="btn btn-danger"
                                                style="padding:0.3rem 0.55rem; font-size:0.75rem;" title="Delete Variant">
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
    <div class="modal-content" style="max-width:640px; padding:1.25rem;">
        <button class="modal-close" onclick="closeModal('variantModal')">&times;</button>
        <h3 id="variantModalTitle" style="margin-bottom:1rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; font-size:1.1rem;">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Size/Variant
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_variant">
            <input type="hidden" name="variant_id" id="variantId" value="0">

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem; align-items:start;">
                <!-- Left Column: Size, Barcode & Price -->
                <div style="display:flex; flex-direction:column; gap:0.65rem;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:0.75rem;">Size / Variant Label</label>
                        <input type="text" name="size" id="variantSize" class="form-control" placeholder="e.g. Standard, Large, Metallic" style="height:34px; font-size:0.8rem;" required>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:0.75rem;">Barcode (Auto-generated if left blank)</label>
                        <div style="display:flex; gap:0.35rem; align-items:center;">
                            <input type="text" name="barcode" id="variantBarcode" class="form-control" placeholder="e.g. 200123456789" style="height:34px; font-size:0.8rem; flex:1;">
                            <button type="button" class="btn btn-secondary" onclick="scanVariantBarcodeWithCamera()" style="white-space:nowrap; display:flex; align-items:center; gap:0.25rem; height:34px; font-size:0.75rem; padding:0 0.5rem;">
                                <i class="fa-solid fa-barcode" style="color:var(--accent-color);"></i> Scan
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <div style="display:flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" id="variantInherit" name="inherit_price" value="1" checked style="width:16px; height:16px; accent-color:var(--accent-color); cursor:pointer;">
                            <label for="variantInherit" class="form-label" style="margin:0; cursor:pointer; font-size:0.78rem;">Inherit Base Price (<?= format_price($product['base_price']) ?>)</label>
                        </div>
                    </div>

                    <div class="form-group" id="variantPriceGroup" style="display:none; margin:0;">
                        <label class="form-label" style="font-size:0.75rem;">Custom Price for this Variant (Rs)</label>
                        <input type="number" step="0.01" min="0" name="price" id="variantPrice" class="form-control" placeholder="<?= $product['base_price'] ?>" style="height:34px; font-size:0.8rem;">
                    </div>
                </div>

                <!-- Right Column: Stock & Inventory Management -->
                <div style="background:var(--bg-control); border:1px solid var(--border-color); border-radius:var(--border-radius-md); padding:0.75rem; display:flex; flex-direction:column; gap:0.6rem;">
                    <h4 style="font-size:0.85rem; margin:0; color:var(--text-primary); display:flex; align-items:center; gap:0.4rem; border-bottom:1px solid var(--border-color); padding-bottom:0.35rem;">
                        <i class="fa-solid fa-boxes-stacked" style="color:var(--accent-color);"></i> Stock & Inventory
                    </h4>

                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:0.75rem;">Stock Quantity (Whole Packets)</label>
                        <input type="number" step="0.01" name="stock_quantity" id="variantStockQty" class="form-control" placeholder="0.00" style="height:34px; font-size:0.8rem;" required>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <div style="display:flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" id="variantAllowLoose" name="allow_loose" value="1" style="width:16px; height:16px; accent-color:var(--accent-color); cursor:pointer;">
                            <label for="variantAllowLoose" class="form-label" style="margin:0; cursor:pointer; font-size:0.78rem;">Allow Loose sales from packet?</label>
                        </div>
                    </div>

                    <div id="variantLooseSalesGroup" style="display:none; flex-direction:column; gap:0.5rem; padding-left:0.75rem; border-left:2px solid var(--accent-color);">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:0.72rem;">Loose Unit Price (Rs)</label>
                            <input type="number" step="0.01" min="0.01" name="loose_price" id="variantLoosePrice" class="form-control" placeholder="e.g. 3.00" style="height:32px; font-size:0.78rem;">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:0.72rem;">Loose units in 1 Whole packet</label>
                            <input type="number" step="0.01" min="1" name="loose_units_per_whole" id="variantLooseUnitsPerWhole" class="form-control" placeholder="e.g. 50" style="height:32px; font-size:0.78rem;">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem; border-top:1px solid var(--border-color); padding-top:0.75rem;">
                <button type="button" onclick="closeModal('variantModal')" class="btn btn-secondary" style="height:32px; font-size:0.8rem;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="height:32px; font-size:0.8rem;"><i class="fa-solid fa-floppy-disk"></i> Save Variant</button>
            </div>
        </form>
    </div>
</div>

<script>
    const inheritCheckbox = document.getElementById('variantInherit');
    const priceGroup = document.getElementById('variantPriceGroup');
    const priceInput = document.getElementById('variantPrice');
    const allowLooseCheckbox = document.getElementById('variantAllowLoose');
    const looseSalesGroup = document.getElementById('variantLooseSalesGroup');
    const loosePriceInput = document.getElementById('variantLoosePrice');
    const looseUnitsInput = document.getElementById('variantLooseUnitsPerWhole');

    inheritCheckbox.addEventListener('change', function () {
        if (this.checked) {
            priceGroup.style.display = 'none';
            priceInput.removeAttribute('required');
        } else {
            priceGroup.style.display = 'block';
            priceInput.setAttribute('required', 'required');
        }
    });

    allowLooseCheckbox.addEventListener('change', function () {
        if (this.checked) {
            looseSalesGroup.style.display = 'block';
            loosePriceInput.setAttribute('required', 'required');
            looseUnitsInput.setAttribute('required', 'required');
        } else {
            looseSalesGroup.style.display = 'none';
            loosePriceInput.removeAttribute('required');
            looseUnitsInput.removeAttribute('required');
        }
    });

    function openAddVariantModal() {
        document.getElementById('variantModalTitle').innerHTML =
            '<i class="fa-solid fa-circle-plus" style="color:var(--accent-color);"></i> Add Size/Variant';
        document.getElementById('variantId').value = '0';
        document.getElementById('variantSize').value = '';
        document.getElementById('variantBarcode').value = '';
        document.getElementById('variantStockQty').value = '0.00';
        
        allowLooseCheckbox.checked = false;
        looseSalesGroup.style.display = 'none';
        loosePriceInput.value = '';
        loosePriceInput.removeAttribute('required');
        looseUnitsInput.value = '';
        looseUnitsInput.removeAttribute('required');

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
        document.getElementById('variantStockQty').value = v.stock_quantity || '0.00';

        if (parseInt(v.allow_loose) === 1) {
            allowLooseCheckbox.checked = true;
            looseSalesGroup.style.display = 'block';
            loosePriceInput.value = v.loose_price || '';
            loosePriceInput.setAttribute('required', 'required');
            looseUnitsInput.value = v.loose_units_per_whole || '';
            looseUnitsInput.setAttribute('required', 'required');
        } else {
            allowLooseCheckbox.checked = false;
            looseSalesGroup.style.display = 'none';
            loosePriceInput.value = '';
            loosePriceInput.removeAttribute('required');
            looseUnitsInput.value = '';
            looseUnitsInput.removeAttribute('required');
        }

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

    // Trigger Camera Barcode Scanning for Variant Barcode
    function scanVariantBarcodeWithCamera() {
        if (!window.OrangeCameraUtils) {
            alert('Camera utilities loading... Please try again.');
            return;
        }
        window.OrangeCameraUtils.openBarcodeScanModal(function(scannedBarcode) {
            const barcodeInput = document.getElementById('variantBarcode');
            if (barcodeInput) {
                barcodeInput.value = window.OrangeCameraUtils ? window.OrangeCameraUtils.cleanBarcode(scannedBarcode) : scannedBarcode;
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>