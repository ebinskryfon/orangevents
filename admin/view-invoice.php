<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if ($event_id <= 0) {
    echo "<h3>Error: Event ID is required.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// 1. Fetch Event logistics
$stmt = $db->prepare("SELECT * FROM events WHERE id = :id");
$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch();

if (!$event) {
    echo "<h3>Error: Booking not found.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// 2. Fetch Invoice status
$stmt_inv = $db->prepare("SELECT * FROM invoices WHERE event_id = :id");
$stmt_inv->execute(['id' => $event_id]);
$invoice = $stmt_inv->fetch();

if (!$invoice) {
    // Auto-create invoice if missing
    $inv_num = "INV-" . date('Y') . "-" . str_pad($event_id, 4, '0', STR_PAD_LEFT);
    $db->prepare("INSERT INTO invoices (event_id, invoice_number, subtotal, final_total, status) VALUES (:id, :num, 0, 0, 'draft')")->execute(['id' => $event_id, 'num' => $inv_num]);
    
    $stmt_inv->execute(['id' => $event_id]);
    $invoice = $stmt_inv->fetch();
}

// 3. Fetch Stage Work items selected
$stmt_stage = $db->prepare("SELECT esw.custom_price, si.item_name, si.description 
                            FROM event_stage_work esw 
                            JOIN stage_items si ON esw.stage_item_id = si.id 
                            WHERE esw.event_id = :id");
$stmt_stage->execute(['id' => $event_id]);
$stage_work_items = $stmt_stage->fetchAll();

// Calculate actual Stage Work Total
$stage_total = 0;
foreach ($stage_work_items as $si) {
    $stage_total += $si['custom_price'];
}

// 4. Fetch Catering details
$stmt_catering = $db->prepare("SELECT * FROM event_catering WHERE event_id = :id");
$stmt_catering->execute(['id' => $event_id]);
$catering = $stmt_catering->fetch();

$catering_total = 0;
$dishes_by_category = [];

if ($catering) {
    $catering_total = $catering['per_plate_price'] * $catering['total_plates'];
    
    // 5. Fetch selected dishes grouped by category name
    $stmt_dishes = $db->prepare("SELECT d.dish_name, mc.category_name, ecd.plate_count 
                                 FROM event_catering_dishes ecd 
                                 JOIN dishes d ON ecd.dish_id = d.id 
                                 JOIN menu_categories mc ON d.category_id = mc.id 
                                 WHERE ecd.event_catering_id = :cat_id 
                                 ORDER BY mc.display_order ASC, d.dish_name ASC");
    $stmt_dishes->execute(['cat_id' => $catering['id']]);
    $selected_dishes = $stmt_dishes->fetchAll();
    
    foreach ($selected_dishes as $sd) {
        $dishes_by_category[$sd['category_name']][] = [
            'name' => $sd['dish_name'],
            'plates' => $sd['plate_count']
        ];
    }
}

// Re-calculate Grand Total to keep invoice in sync
$grand_total = $stage_total + $catering_total;

if ($invoice['status'] === 'draft' && $invoice['subtotal'] != $grand_total) {
    $db->prepare("UPDATE invoices SET subtotal = :sub, final_total = :final WHERE id = :inv_id")
       ->execute(['sub' => $grand_total, 'final' => $grand_total, 'inv_id' => $invoice['id']]);
    $invoice['subtotal'] = $grand_total;
    $invoice['final_total'] = $grand_total;
}

// Handle Template Selection Update & Finalization POST triggers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_template') {
            $new_template = $_POST['template_name'] ?? 'orange_classic';
            $db->prepare("UPDATE invoices SET template_name = :temp WHERE id = :id")->execute(['temp' => $new_template, 'id' => $invoice['id']]);
            $invoice['template_name'] = $new_template;
        }
        
        if ($action === 'finalize') {
            // Lock invoice to finalized, and set event status to confirmed
            $db->beginTransaction();
            $db->prepare("UPDATE invoices SET status = 'finalized' WHERE id = :id")->execute(['id' => $invoice['id']]);
            $db->prepare("UPDATE events SET status = 'confirmed' WHERE id = :ev_id")->execute(['ev_id' => $event_id]);
            $db->commit();
            
            $invoice['status'] = 'finalized';
            $event['status'] = 'confirmed';
        }
    }
}

$template = $invoice['template_name'];
?>

<?php if ($template === 'aedan_gardens'): ?>
<style>
@media print {
    @page {
        size: landscape;
        margin: 0.6cm;
    }
}
</style>
<?php else: ?>
<style>
@media print {
    @page {
        size: portrait;
        margin: 0.8cm;
    }
}
</style>
<?php endif; ?>

<style>
/* Controls bar visible only on screen */
.print-control-bar {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    padding: 1.25rem 2rem;
    border-radius: var(--border-radius-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    gap: 1rem;
}

@media print {
    .print-control-bar {
        display: none !important;
    }
    .sidebar {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
}
</style>

<!-- Admin Action Controls -->
<div class="print-control-bar">
    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <span style="font-weight: 600; color: var(--text-secondary);">Select Template Layout:</span>
        <form action="" method="POST" id="templateForm" style="margin: 0; display: inline-flex; align-items: center; gap: 0.5rem;">
            <input type="hidden" name="action" value="update_template">
            <select name="template_name" class="form-control" style="width: 200px; padding: 0.4rem 0.8rem;" onchange="document.getElementById('templateForm').submit()">
                <option value="orange_classic" <?= $template == 'orange_classic' ? 'selected' : '' ?>>Orange Classic (Image representation)</option>
                <option value="royal_gold" <?= $template == 'royal_gold' ? 'selected' : '' ?>>Royal Gold</option>
                <option value="midnight_dark" <?= $template == 'midnight_dark' ? 'selected' : '' ?>>Midnight Dark</option>
                <option value="aedan_gardens" <?= $template == 'aedan_gardens' ? 'selected' : '' ?>>Aedan Gardens (Tax Invoice)</option>
            </select>
        </form>
    </div>

    <div style="display: flex; gap: 1.5rem; align-items: center; margin-left: 1.5rem; margin-right: auto;">
        <label style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; user-select: none; margin: 0;">
            <input type="checkbox" id="toggleTotalCheckbox" checked style="width: 18px; height: 18px; accent-color: var(--accent-color); cursor: pointer;">
            Show Estimated Total
        </label>
    </div>
    
    <div style="display: flex; gap: 0.5rem;">
        <?php if ($invoice['status'] === 'draft'): ?>
            <form action="" method="POST" style="margin: 0;" onsubmit="return confirm('Finalizing will lock details and mark the event Confirmed. Proceed?');">
                <input type="hidden" name="action" value="finalize">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-lock"></i> Finalize Package
                </button>
            </form>
        <?php endif; ?>
        
        <button id="downloadImageBtn" class="btn btn-secondary">
            <i class="fa-solid fa-image"></i> Save as Image
        </button>
        
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa-solid fa-print"></i> Print / Save PDF
        </button>
    </div>
</div>

<!-- Template Container -->
<div class="template-preview-container">
    
    <!-- INVOICE CARD START -->
    <div class="invoice-card <?= h($template) ?>">
        
        <?php if ($template === 'orange_classic'): ?>
            <!-- ORANGE CLASSIC LAYOUT -->
            
            <header class="template-header">
                <div class="header-logo-container">
                    <div class="logo-icon">
                        <img src="../assets/images/logo.png" alt="Orange Events Logo" class="header-logo-img">
                    </div>
                    <div class="logo-title"><span class="text-orange">ORANGE</span> <span class="text-white">EVENTS</span></div>
                    <div class="logo-subtitle">THUMPOLY, ALAPPUZHA</div>
                    <div class="logo-contact">
                        MOB : 9946731720 | 9847634728<br>
                        990670059
                    </div>
                </div>
            </header>
            
            <div class="header-date-bar">
                DATE: <?= format_date($event['event_date']) ?>
            </div>
            
            <div class="template-body">
                <!-- Left Accent Sidebar (Collage & Orange Vertical Band) -->
                <div class="left-accent-col">
                    <div class="image-collage">
                        <div class="collage-img img-stage">
                            <span class="collage-img-label">Stage Setup</span>
                        </div>
                        <div class="collage-img img-catering">
                            <span class="collage-img-label">Catering</span>
                        </div>
                        <div class="collage-img img-drinks">
                            <span class="collage-img-label">Welcome Drinks</span>
                        </div>
                        <div class="collage-img img-deserts">
                            <span class="collage-img-label">Desserts</span>
                        </div>
                    </div>
                    <div class="vertical-orange-band">
                        <div class="band-icon"><i class="fa-solid fa-utensils"></i></div>
                        <div class="band-text">ORANGE EVENTS & CATERING <span>SPECIAL MENU</span></div>
                        <div class="band-icon"><i class="fa-solid fa-utensils"></i></div>
                    </div>
                </div>
                
                <!-- Right Content Area (Menus & Services breakdown) -->
                <div class="right-content-col">
                    
                    <!-- 1. STAGE WORK SECTION -->
                    <?php if (!empty($stage_work_items)): ?>
                        <div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">STAGE WORK</h3>
                            </div>
                            <ul class="section-list">
                                <?php foreach ($stage_work_items as $sw): ?>
                                    <li class="item-row">
                                        <span class="item-name"><?= h($sw['item_name']) ?></span>
                                        <span class="item-price">-Rs <?= number_format($sw['custom_price'], 0) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 2. WELCOME DRINK SECTION -->
                    <?php if (isset($dishes_by_category['WELCOME DRINK'])): ?>
                        <div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">WELCOME DRINK</h3>
                                <?php if ($catering): ?>
                                    <span class="section-subtitle">Per Plate-Rs.<?= number_format($catering['per_plate_price'], 0) ?> (Nos:<?= $catering['total_plates'] ?>)</span>
                                <?php endif; ?>
                            </div>
                            <ul class="section-list">
                                <?php foreach ($dishes_by_category['WELCOME DRINK'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <li class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                        <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 3. STARTERS SECTION -->
                    <?php if (isset($dishes_by_category['STARTERS'])): ?>
                        <div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">STARTERS</h3>
                            </div>
                            <ul class="section-list">
                                <?php foreach ($dishes_by_category['STARTERS'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <li class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                        <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 4. MAIN COURSE SECTION -->
                    <?php if (isset($dishes_by_category['MAIN COURSE'])): ?>
                        <div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">MAIN COURSE</h3>
                            </div>
                            <ul class="section-list multi-col-list">
                                <?php foreach ($dishes_by_category['MAIN COURSE'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <li class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                        <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 5. DESERTS SECTION -->
                    <?php if (isset($dishes_by_category['DESERTS'])): ?>
                        <div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">DESERTS</h3>
                            </div>
                            <ul class="section-list">
                                <?php foreach ($dishes_by_category['DESERTS'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <li class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                        <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 6. SERVICE & WASTE MANAGEMENT SECTION -->
                    <?php if (isset($dishes_by_category['SERVICE & WASTE MANAGEMENT'])): ?>
                        <div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">SERVICE & WASTE MANAGEMENT</h3>
                            </div>
                            <ul class="section-list">
                                <?php foreach ($dishes_by_category['SERVICE & WASTE MANAGEMENT'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <li class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                        <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Bottom Invoice Balance Summary -->
                    <div class="footer-summary-bar">
                        <div style="font-size: 0.8rem; color: #a4b0be; line-height: 1.4;">
                            Client: <strong><?= h($event['client_name']) ?></strong><br>
                            Venue: <?= h($event['venue']) ?><br>
                            Inv Status: <?= strtoupper($invoice['status']) ?>
                            <?php if (!empty($invoice['payment_method'])): ?>
                                <br>Payment: <?= h($invoice['payment_method']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="summary-box" style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #a4b0be;">
                                <span>Estimated Total:</span>
                                <span style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['final_total']) ?></span>
                            </div>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #a4b0be;">
                                <span>Advance Paid:</span>
                                <span style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['advance_received']) ?></span>
                            </div>
                            <div style="display: flex; gap: 1rem; font-size: 1rem; color: #ffffff; border-top: 1px dashed rgba(255,255,255,0.2); padding-top: 0.25rem; font-weight: bold;">
                                <span style="color: #eb6b34;">Rest to Get:</span>
                                <span style="color: #eb6b34;"><?= format_price($invoice['final_total'] - $invoice['advance_received']) ?></span>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            
        <?php elseif ($template === 'royal_gold'): ?>
            <!-- ROYAL GOLD TEMPLATE -->
            <header class="template-header">
                <div class="logo-icon"><i class="fa-solid fa-leaf"></i></div>
                <div class="logo-title">ORANGE EVENTS</div>
                <div class="logo-subtitle">Premium Catering & Stage Decors</div>
                <div style="font-size: 0.85rem; color: #7f6a58; margin-top: 0.5rem;">Thumpoly, Alappuzha | Mob: 9946731720</div>
            </header>
            
            <div class="header-date-bar">
                Event Booking Date: <?= format_date($event['event_date']) ?>
            </div>
            
            <div class="template-body">
                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 2rem;">
                    
                    <!-- Column 1: Food details -->
                    <div>
                        <!-- Welcome Drinks -->
                        <?php if (isset($dishes_by_category['WELCOME DRINK'])): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <div class="section-title-wrap">
                                    <h3 class="section-title">Welcome Drinks</h3>
                                </div>
                                <?php foreach ($dishes_by_category['WELCOME DRINK'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Starters -->
                        <?php if (isset($dishes_by_category['STARTERS'])): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <div class="section-title-wrap">
                                    <h3 class="section-title">Appetizers & Starters</h3>
                                </div>
                                <?php foreach ($dishes_by_category['STARTERS'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Main Course -->
                        <?php if (isset($dishes_by_category['MAIN COURSE'])): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <div class="section-title-wrap">
                                    <h3 class="section-title">Grand Buffet Main Course</h3>
                                </div>
                                <div class="multi-col-list">
                                    <?php foreach ($dishes_by_category['MAIN COURSE'] as $dish): ?>
                                        <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                        <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Desserts -->
                        <?php if (isset($dishes_by_category['DESERTS'])): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <div class="section-title-wrap">
                                    <h3 class="section-title">Sweet Desserts</h3>
                                </div>
                                <?php foreach ($dishes_by_category['DESERTS'] as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Column 2: Stage decoration services and catering invoice specs -->
                    <div>
                        <!-- Stage work -->
                        <?php if (!empty($stage_work_items)): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <div class="section-title-wrap">
                                    <h3 class="section-title">Stage & Setup Work</h3>
                                </div>
                                <?php foreach ($stage_work_items as $sw): ?>
                                    <div class="item-row">
                                        <span class="item-name"><?= h($sw['item_name']) ?></span>
                                        <span class="item-price"><?= format_price($sw['custom_price']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Catering Details -->
                        <?php if ($catering): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <div class="section-title-wrap">
                                    <h3 class="section-title">Catering Calculations</h3>
                                </div>
                                <div class="item-row">
                                    <span>Rate per Plate:</span>
                                    <span><?= format_price($catering['per_plate_price']) ?></span>
                                </div>
                                <div class="item-row">
                                    <span>Plates Count:</span>
                                    <span><?= $catering['total_plates'] ?> nos</span>
                                </div>
                                <div class="item-row" style="font-weight: bold;">
                                    <span>Catering Total:</span>
                                    <span><?= format_price($catering_total) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
                
                <!-- Bottom Invoice Summary -->
                <div class="footer-summary-bar">
                    <div style="line-height: 1.4;">
                        Client Name: <strong><?= h($event['client_name']) ?></strong><br>
                        Venue Location: <?= h($event['venue']) ?>
                        <?php if (!empty($invoice['payment_method'])): ?>
                            <br>Payment Method: <?= h($invoice['payment_method']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="summary-box" style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #7f6a58;">
                            <span>Grand Total:</span>
                            <span style="font-weight: 600; color: #3a2e2b;"><?= format_price($invoice['final_total']) ?></span>
                        </div>
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #7f6a58;">
                            <span>Advance Paid:</span>
                            <span style="font-weight: 600; color: #3a2e2b;"><?= format_price($invoice['advance_received']) ?></span>
                        </div>
                        <div style="display: flex; gap: 1rem; font-size: 1rem; color: #8c7223; border-top: 1px dashed rgba(140,114,35,0.3); padding-top: 0.25rem; font-weight: bold;">
                            <span>Rest to Get:</span>
                            <span><?= format_price($invoice['final_total'] - $invoice['advance_received']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php elseif ($template === 'midnight_dark'): ?>
            <!-- MIDNIGHT DARK TEMPLATE -->
            <header class="template-header">
                <div>
                    <div class="logo-title">ORANGE EVENTS</div>
                    <div style="font-size: 0.8rem; color: #8892b0; margin-top: 0.25rem;">Modern Catering & Decor Architects</div>
                </div>
                <div class="header-date-bar">
                    DATE: <?= format_date($event['event_date']) ?>
                </div>
            </header>
            
            <div class="template-body">
                <!-- Main courses list -->
                <div>
                    <h4 style="color: #64ffda; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.25rem;">EVENT DETAILS</h4>
                    <div style="font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.4rem;">
                        <div>Client: <strong><?= h($event['client_name']) ?></strong></div>
                        <div>Phone: <?= h($event['client_phone']) ?></div>
                        <div>Venue: <?= h($event['venue']) ?></div>
                        <div>Time: <?= format_time($event['event_time']) ?></div>
                    </div>
                    
                    <?php if (isset($dishes_by_category['WELCOME DRINK'])): ?>
                        <div style="margin-bottom: 1.25rem;">
                            <div class="section-title-wrap"><h3 class="section-title">Welcome Drinks</h3></div>
                            <?php foreach ($dishes_by_category['WELCOME DRINK'] as $dish): ?>
                                <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($dishes_by_category['STARTERS'])): ?>
                        <div style="margin-bottom: 1.25rem;">
                            <div class="section-title-wrap"><h3 class="section-title">Starters</h3></div>
                            <?php foreach ($dishes_by_category['STARTERS'] as $dish): ?>
                                <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($dishes_by_category['MAIN COURSE'])): ?>
                        <div style="margin-bottom: 1.25rem;">
                            <div class="section-title-wrap"><h3 class="section-title">Main Courses</h3></div>
                            <?php foreach ($dishes_by_category['MAIN COURSE'] as $dish): ?>
                                <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>"><span class="item-name"><?= h($dish['name']) . $p_suffix ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Financial details column -->
                <div>
                    <!-- Stage decor services -->
                    <?php if (!empty($stage_work_items)): ?>
                        <div style="margin-bottom: 2rem;">
                            <h4 style="color: #64ffda; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.25rem;">STAGE & SERVICES</h4>
                            <?php foreach ($stage_work_items as $sw): ?>
                                <div class="item-row">
                                    <span><?= h($sw['item_name']) ?></span>
                                    <span><?= format_price($sw['custom_price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Catering quote -->
                    <?php if ($catering): ?>
                        <div style="margin-bottom: 2rem;">
                            <h4 style="color: #64ffda; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.25rem;">CATERING STATEMENT</h4>
                            <div class="item-row">
                                <span>Plate rate:</span>
                                <span><?= format_price($catering['per_plate_price']) ?></span>
                            </div>
                            <div class="item-row">
                                <span>Count:</span>
                                <span><?= $catering['total_plates'] ?> plates</span>
                            </div>
                            <div class="item-row" style="border-top: 1px dashed rgba(255,255,255,0.1); font-weight: bold; color: #64ffda;">
                                <span>Catering Total:</span>
                                <span><?= format_price($catering_total) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Footer stats -->
                <div class="footer-summary-bar" style="display: flex; justify-content: space-between; align-items: flex-end; width: 100%;">
                    <div style="font-size: 0.8rem; color: #8892b0; line-height: 1.4;">
                        Status: <?= strtoupper($invoice['status']) ?>
                        <?php if (!empty($invoice['payment_method'])): ?>
                            <br>Payment: <?= h($invoice['payment_method']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="summary-box" style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #8892b0;">
                            <span>Total Statement:</span>
                            <span style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['final_total']) ?></span>
                        </div>
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #8892b0;">
                            <span>Advance Paid:</span>
                            <span style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['advance_received']) ?></span>
                        </div>
                        <div style="display: flex; gap: 1rem; font-size: 1rem; color: #64ffda; border-top: 1px dashed rgba(100,255,218,0.2); padding-top: 0.25rem; font-weight: bold;">
                            <span>Rest to Get:</span>
                            <span><?= format_price($invoice['final_total'] - $invoice['advance_received']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($template === 'aedan_gardens'): ?>
            <!-- AEDAN GARDENS TEMPLATE (TAX INVOICE) -->
            <?php
            // Prepare table items list
            $table_items = [];
            
            // 1. Add Stage setup items
            foreach ($stage_work_items as $sw) {
                $table_items[] = [
                    'name' => $sw['item_name'],
                    'category' => 'Stage Work',
                    'size' => 'Standard',
                    'qty' => 1,
                    'unit' => 'Setup',
                    'price' => (float)$sw['custom_price'],
                    'discount' => '0.00%',
                    'amount' => (float)$sw['custom_price']
                ];
            }
            
            // 2. Add Catering Package
            if ($catering) {
                $table_items[] = [
                    'name' => 'Catering Package (' . format_price($catering['per_plate_price']) . ' per plate)',
                    'category' => 'Catering Services',
                    'size' => 'Standard',
                    'qty' => (int)$catering['total_plates'],
                    'unit' => 'Plates',
                    'price' => (float)$catering['per_plate_price'],
                    'discount' => '0.00%',
                    'amount' => (float)$catering_total
                ];
                
                // 3. Add Selected Dishes (zero cost, marked as "INCLUDED")
                foreach ($dishes_by_category as $cat_name => $dishes) {
                    foreach ($dishes as $dish) {
                        $qty = ($dish['plates'] > 0) ? (int)$dish['plates'] : 1;
                        $unit = ($dish['plates'] > 0) ? 'Plates' : 'Nos';
                        $size = ($dish['plates'] > 0) ? 'Custom' : 'Standard';
                        
                        $table_items[] = [
                            'name' => $dish['name'],
                            'category' => $cat_name,
                            'size' => $size,
                            'qty' => $qty,
                            'unit' => $unit,
                            'price' => 0.00,
                            'discount' => '0.00%',
                            'amount' => 0.00,
                            'is_dish' => true
                        ];
                    }
                }
            }
            ?>
            
            <!-- Header layout matching image -->
            <div class="aedan-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                        <img src="../assets/images/logo.png" alt="Aedan Gardens Logo" style="height: 55px; width: auto;">
                        <div style="font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 800; color: #2e7d32; line-height: 1; letter-spacing: -0.02em;">aedan gardens</div>
                    </div>
                    <div style="font-size: 0.85rem; line-height: 1.4; color: #1e293b;">
                        <strong>(Plant Nursery & Garden Center)</strong><br>
                        Thumpoly P.O Alappuzha<br>
                        Alappuzha<br>
                        Email: aedangardens04@gmail.com<br>
                        State: 32-Kerala
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <h1 style="font-size: 2.2rem; font-weight: 800; color: #1e293b; margin: 0 0 0.75rem 0; letter-spacing: 0.05em; text-transform: uppercase;">Tax Invoice</h1>
                    <div style="font-size: 0.9rem; line-height: 1.5; color: #1e293b;">
                        <strong>Invoice No:</strong> <?= h($invoice['invoice_number']) ?><br>
                        <strong>Date:</strong> <?= format_date($event['event_date']) ?><br>
                        <strong>Place of Supply:</strong> 32-Kerala
                    </div>
                </div>
            </div>
            
            <!-- Bill To Section -->
            <div style="margin-bottom: 1.5rem; border-top: 1px solid #000000; padding-top: 0.75rem; font-family: 'Inter', sans-serif;">
                <div style="font-size: 0.85rem; line-height: 1.4; color: #1e293b;">
                    <strong style="font-size: 0.9rem; color: #000000; text-transform: uppercase;">Bill To:</strong><br>
                    <span style="font-weight: 700; font-size: 0.95rem; color: #000000;"><?= h($event['client_name']) ?></span><br>
                    <?= h($event['venue']) ?><br>
                    Contact No: <?= h($event['client_phone']) ?><br>
                    Email: <?= h($event['client_email']) ?><br>
                    State: 32-Kerala
                </div>
            </div>
            
            <!-- Items Table -->
            <table class="aedan-table" style="width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; border: 1.2px solid #000000; font-family: 'Inter', sans-serif;">
                <thead>
                    <tr style="background-color: #f8fafc; border-bottom: 1.2px solid #000000;">
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; width: 50px; text-align: center; color: #000000;">Sl No</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: left; color: #000000;">Item Name</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: left; width: 150px; color: #000000;">Category</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: left; width: 90px; color: #000000;">Size</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: center; width: 80px; color: #000000;">Quantity</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: center; width: 70px; color: #000000;">Unit</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right; width: 110px; color: #000000;">Price/Unit</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: center; width: 90px; color: #000000;">Discount</th>
                        <th style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right; width: 120px; color: #000000;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sl = 1;
                    foreach ($table_items as $item): 
                        $is_dish = !empty($item['is_dish']);
                    ?>
                        <tr style="border-bottom: 1px solid #000000; background-color: #ffffff; color: #000000;">
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;"><?= $sl++ ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: <?= $is_dish ? 'normal' : '700' ?>;"><?= h($item['name']) ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem;"><?= h($item['category']) ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem;"><?= h($item['size']) ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;"><?= $item['qty'] ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;"><?= h($item['unit']) ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right;">
                                <?= $item['price'] > 0 ? format_price($item['price']) : 'Included' ?>
                            </td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;"><?= h($item['discount']) ?></td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right; font-weight: bold;">
                                <?= $item['amount'] > 0 ? format_price($item['amount']) : 'Included' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Total Row -->
                    <tr style="border-top: 1.2px solid #000000; font-weight: bold; background-color: #f8fafc; color: #000000;">
                        <td colspan="8" style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right; text-transform: uppercase;">Total</td>
                        <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right; font-weight: 800;">
                            <?= format_price($invoice['final_total']) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Bottom Boxes Layout (Side by Side) -->
            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1rem; margin-bottom: 1.5rem; font-family: 'Inter', sans-serif; color: #000000;">
                <!-- Left Details Box -->
                <div style="border: 1.2px solid #000000; display: flex; flex-direction: column;">
                    <div style="border-bottom: 1.2px solid #000000; padding: 0.5rem 0.75rem;">
                        <span style="font-size: 0.75rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.03em;">Invoice Amount in Words</span>
                        <div style="font-size: 0.8rem; font-weight: 700; margin-top: 0.15rem; color: #000000;">
                            <?= convert_number_to_words($invoice['final_total']) ?> Only
                        </div>
                    </div>
                    <div style="padding: 0.5rem 0.75rem; flex-grow: 1;">
                        <span style="font-size: 0.75rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.03em;">Description</span>
                        <div style="font-size: 0.8rem; margin-top: 0.15rem; color: #000000; line-height: 1.4;">
                            Event package setup: "<?= h($event['title']) ?>" at <?= h($event['venue']) ?>. 
                            Includes all standard catering management, curated stage decors, guest reception coordination, and venue cleanup.
                            <?php if ($catering && !empty($catering['notes'])): ?>
                                <br><strong>Special Requests:</strong> <?= h($catering['notes']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Pricing Summary Table -->
                <div class="summary-box" style="border: 1.2px solid #000000; border-collapse: collapse;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="border-bottom: 1px solid #000000;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 600;">Sub Total</td>
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right;"><?= format_price($invoice['final_total']) ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #000000; font-weight: bold; background-color: #f8fafc;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700;">Total</td>
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 800; text-align: right;"><?= format_price($invoice['final_total']) ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #000000;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 600;">Received</td>
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right;"><?= format_price($invoice['advance_received']) ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #000000; font-weight: bold; background-color: #fcf2f2;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700;">Balance</td>
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 800; text-align: right;"><?= format_price($invoice['final_total'] - $invoice['advance_received']) ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">
                                Payment Method: <?= h($invoice['payment_method'] ?: 'PENDING / CASH') ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Terms & Conditions Section -->
            <div style="background-color: #f1f5f9; padding: 0.5rem 0.75rem; margin-bottom: 1.5rem; font-family: 'Inter', sans-serif; color: #000000; border-radius: 4px;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 0.15rem;">Terms and Conditions</div>
                <div style="font-size: 0.75rem; line-height: 1.3;">
                    Thanks for choosing Aedan Gardens! Total Items: <?= count($table_items) ?>
                </div>
            </div>
            
            <!-- Bank Details and Signature -->
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: auto; font-family: 'Inter', sans-serif; color: #000000;">
                <!-- Bank Info -->
                <div style="font-size: 0.8rem; line-height: 1.5;">
                    <strong style="text-transform: uppercase;">Company's Bank Details:</strong><br>
                    <strong>Bank:</strong> STATE BANK OF INDIA<br>
                    <strong>Acc No:</strong> 40590127711<br>
                    <strong>IFSC:</strong> SBIN0000007<br>
                    <strong>Name:</strong> AEDAN GARDENS
                </div>
                
                <!-- Signature Box -->
                <div style="text-align: center; font-size: 0.8rem; min-width: 200px;">
                    <div style="margin-bottom: 2.2rem; font-weight: bold;">For, Aedan Gardens</div>
                    <div style="border-top: 1px solid #000000; padding-top: 0.4rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem;">Authorized Signatory</div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
    <!-- INVOICE CARD END -->
    
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// Apply template class to body for custom print styles
document.body.classList.add('template-<?= h($template) ?>');

document.getElementById('toggleTotalCheckbox').addEventListener('change', function(e) {
    const summaryBoxes = document.querySelectorAll('.summary-box');
    summaryBoxes.forEach(box => {
        if (e.target.checked) {
            box.style.setProperty('display', 'block', 'important');
        } else {
            box.style.setProperty('display', 'none', 'important');
        }
    });
});

document.getElementById('downloadImageBtn').addEventListener('click', function() {
    const invoiceCard = document.querySelector('.invoice-card');
    if (!invoiceCard) return;

    const originalBtnContent = this.innerHTML;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
    this.disabled = true;

    html2canvas(invoiceCard, {
        useCORS: true,
        scale: 2, // High resolution scale
        backgroundColor: null
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = '<?= h($invoice["invoice_number"]) ?>.png';
        link.href = canvas.toDataURL('image/png');
        link.click();

        this.innerHTML = originalBtnContent;
        this.disabled = false;
    }).catch(err => {
        console.error('Error generating image:', err);
        alert('Failed to generate image. Please try again.');
        this.innerHTML = originalBtnContent;
        this.disabled = false;
    });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
