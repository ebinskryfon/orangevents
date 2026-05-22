<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
check_admin_auth();

$db = get_db_connection();
$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($event_id <= 0) {
    require_once __DIR__ . '/../includes/header.php';
    echo "<h3>Error: Event ID is required.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// 1. Fetch Event logistics
$stmt = $db->prepare("SELECT * FROM events WHERE id = :id");
$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch();

if (!$event) {
    require_once __DIR__ . '/../includes/header.php';
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

$rest_to_give = $invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received']);

// Handle Template Selection Update, Finalization, Payments, and Deletion POST triggers
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

        if ($action === 'mark_paid') {
            $amount_paid = isset($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : 0.0;
            $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'CASH';
            $payment_treatment = isset($_POST['payment_treatment']) ? trim($_POST['payment_treatment']) : 'partial';

            $rest_to_give = $invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received']);

            if ($amount_paid > 0) {
                $db->beginTransaction();

                $current_advance = (float) $invoice['advance_received'];
                $current_balance = (float) $invoice['balance_received'];

                if ($current_advance == 0.0) {
                    if ($amount_paid >= $rest_to_give || $payment_treatment === 'write_off') {
                        $new_advance = $invoice['final_total'];
                        $new_balance = 0.0;
                        $status = 'paid';
                    } else {
                        $new_advance = $amount_paid;
                        $new_balance = 0.0;
                        $status = 'finalized';
                    }
                } else {
                    $new_advance = $current_advance;
                    if ($amount_paid >= $rest_to_give || $payment_treatment === 'write_off') {
                        $new_balance = $rest_to_give;
                        $status = 'paid';
                    } else {
                        $new_balance = $current_balance + $amount_paid;
                        $status = 'finalized';
                    }
                }

                $new_adv_paid_at = $invoice['advance_paid_at'];
                $new_adv_method = $invoice['advance_payment_method'];
                $new_bal_paid_at = $invoice['balance_paid_at'];
                $new_bal_method = $invoice['balance_payment_method'];

                if ($current_advance == 0.0 && $new_advance > 0) {
                    $new_adv_paid_at = date('Y-m-d H:i:s');
                    $new_adv_method = $payment_method;
                }

                if ($status === 'paid' && $new_balance > 0) {
                    $new_bal_paid_at = date('Y-m-d H:i:s');
                    $new_bal_method = $payment_method;
                }

                $stmt = $db->prepare("UPDATE `invoices` SET 
                                        `advance_received` = :advance, 
                                        `balance_received` = :balance,
                                        `status` = :status, 
                                        `payment_method` = :method,
                                        `advance_paid_at` = :adv_paid_at,
                                        `advance_payment_method` = :adv_method,
                                        `balance_paid_at` = :bal_paid_at,
                                        `balance_payment_method` = :bal_method
                                      WHERE `id` = :id");
                $stmt->execute([
                    'advance' => $new_advance,
                    'balance' => $new_balance,
                    'status' => $status,
                    'method' => $payment_method,
                    'adv_paid_at' => $new_adv_paid_at,
                    'adv_method' => $new_adv_method,
                    'bal_paid_at' => $new_bal_paid_at,
                    'bal_method' => $new_bal_method,
                    'id' => $invoice['id']
                ]);

                $db->commit();

                header("Location: view-invoice.php?event_id=" . $event['id'] . "&payment_success=1");
                exit;
            }
        }

        if ($action === 'delete_invoice') {
            $input_num = isset($_POST['invoice_number']) ? trim($_POST['invoice_number']) : '';
            if ($input_num === $invoice['invoice_number']) {
                $db->beginTransaction();
                $db->prepare("UPDATE `events` SET `status` = 'draft' WHERE `id` = :ev_id")->execute(['ev_id' => $event_id]);
                $db->prepare("DELETE FROM `invoices` WHERE `id` = :id")->execute(['id' => $invoice['id']]);
                $db->commit();

                header("Location: invoices.php?deleted=1");
                exit;
            }
        }
    }
}

// Now include header, because no more redirects will happen
require_once __DIR__ . '/../includes/header.php';

$template = $invoice['template_name'];
$settings = get_settings();
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

    /* Table Sl No CSS counter */
    .aedan-table {
        counter-reset: rowNumber;
    }

    .aedan-table tbody tr.countable-row {
        counter-increment: rowNumber;
    }

    .aedan-table tbody tr.countable-row td.serial-col::before {
        content: counter(rowNumber);
    }

    .invoice-card.hide-dishes .dish-row,
    .invoice-card.hide-dishes .menu-category-section {
        display: none !important;
    }

    .invoice-card.hide-payments .payment-history {
        display: none !important;
    }

    /* Page break helper indicators (only visible on screen when split controls checkbox is active) */
    .page-break-indicator {
        display: none;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.5rem;
        margin: 0.5rem 0 1rem 0;
        border: 1.5px dashed var(--accent-color, #f07c1b);
        border-radius: var(--border-radius-sm, 6px);
        color: var(--accent-color, #f07c1b);
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        background-color: rgba(240, 124, 27, 0.04);
        transition: all 0.2s ease;
        user-select: none;
        width: 100%;
        box-sizing: border-box;
    }
    .page-break-indicator:hover {
        background-color: rgba(240, 124, 27, 0.12);
        border-style: solid;
    }
    .invoice-card.enable-split-controls .page-break-indicator {
        display: flex;
    }
    .menu-category-section.has-page-break .page-break-indicator {
        border-color: #2ed573;
        color: #2ed573;
        background-color: rgba(46, 213, 115, 0.06);
    }
    .menu-category-section.has-page-break .page-break-indicator:hover {
        background-color: rgba(46, 213, 115, 0.15);
    }


    @media print {
        .page-break-indicator {
            display: none !important;
        }
        .menu-category-section.has-page-break {
            page-break-before: always !important;
            margin-top: 0 !important;
            padding-top: 0 !important;
        }
        .menu-category-section.has-page-break::before {
            display: none !important;
        }
    }

    /* -----------------------------------------------------------------------
       CAPTURE MODE: applied during html2canvas image export.
    ----------------------------------------------------------------------- */
    /* Always hide the page-break indicators in captured image */
    .invoice-card.capture-mode .page-break-indicator {
        display: none !important;
    }
    .invoice-card.capture-mode.enable-split-controls .page-break-indicator {
        display: none !important;
    }

    /* Premium modal styles */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    }
</style>

<?php if (isset($_GET['payment_success'])): ?>
    <div style="background-color: #22c55e; color: #ffffff; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: 600;"
        class="print-control-bar">
        <span><i class="fa-solid fa-circle-check"></i> Payment recorded successfully!</span>
        <button onclick="this.parentElement.style.display='none'"
            style="background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- Admin Action Controls -->
<div class="print-control-bar">
    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <span style="font-weight: 600; color: var(--text-secondary);">Select Template Layout:</span>
        <form action="" method="POST" id="templateForm"
            style="margin: 0; display: inline-flex; align-items: center; gap: 0.5rem;">
            <input type="hidden" name="action" value="update_template">
            <select name="template_name" class="form-control" style="width: 200px; padding: 0.4rem 0.8rem;"
                onchange="document.getElementById('templateForm').submit()">
                <option value="orange_classic" <?= $template == 'orange_classic' ? 'selected' : '' ?>>Orange Classic (Image
                    representation)</option>
                <option value="royal_gold" <?= $template == 'royal_gold' ? 'selected' : '' ?>>Royal Gold</option>
                <option value="midnight_dark" <?= $template == 'midnight_dark' ? 'selected' : '' ?>>Midnight Dark</option>
                <option value="aedan_gardens" <?= $template == 'aedan_gardens' ? 'selected' : '' ?>>Aedan Gardens (Tax
                    Invoice)</option>
                <option value="modern_minimalist" <?= $template == 'modern_minimalist' ? 'selected' : '' ?>>Modern
                    Minimalist</option>
                <option value="emerald_luxury" <?= $template == 'emerald_luxury' ? 'selected' : '' ?>>Emerald Luxury
                </option>
                <option value="blossom_chic" <?= $template == 'blossom_chic' ? 'selected' : '' ?>>Blossom Chic</option>
            </select>
        </form>
    </div>

    <div style="display: flex; gap: 1.5rem; align-items: center; margin-left: 1.5rem; margin-right: auto;">
        <label
            style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; user-select: none; margin: 0;">
            <input type="checkbox" id="toggleTotalCheckbox" checked
                style="width: 18px; height: 18px; accent-color: var(--accent-color); cursor: pointer;">
            Show Estimated Total
        </label>

        <label
            style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; user-select: none; margin: 0;">
            <input type="checkbox" id="toggleDishesCheckbox" checked
                style="width: 18px; height: 18px; accent-color: var(--accent-color); cursor: pointer;">
            Show Dishes & Services
        </label>

        <label
            style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; user-select: none; margin: 0;">
            <input type="checkbox" id="togglePaymentCheckbox" checked
                style="width: 18px; height: 18px; accent-color: var(--accent-color); cursor: pointer;">
            Show Payment History
        </label>

        <label
            style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; user-select: none; margin: 0;">
            <input type="checkbox" id="toggleSplitCheckbox"
                style="width: 18px; height: 18px; accent-color: var(--accent-color); cursor: pointer;">
            Page Split Controls
        </label>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <?php if ($invoice['status'] === 'draft'): ?>
            <form action="" method="POST" style="margin: 0;"
                onsubmit="return confirm('Finalizing will lock details and mark the event Confirmed. Proceed?');">
                <input type="hidden" name="action" value="finalize">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-lock"></i> Finalize Package
                </button>
            </form>
        <?php endif; ?>

        <?php if ($invoice['status'] === 'finalized'): ?>
            <button id="openPaymentModalBtn" class="btn btn-success">
                <i class="fa-solid fa-circle-check"></i> Mark as Paid
            </button>
        <?php endif; ?>

        <button id="downloadImageBtn" class="btn btn-secondary">
            <i class="fa-solid fa-image"></i> Save as Image
        </button>

        <button id="whatsappShareBtn" class="btn" style="background-color: #25D366; border-color: #25D366; color: white; font-weight: 600;">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp Share
        </button>

        <button onclick="window.print()" class="btn btn-primary">
            <i class="fa-solid fa-print"></i> Print / Save PDF
        </button>

        <button id="deleteInvoiceBtn" class="btn btn-danger" style="background-color: #dc2626; border-color: #dc2626;">
            <i class="fa-solid fa-trash-can"></i> Delete Invoice
        </button>

        <a href="edit-invoice.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary"
            style="background-color: var(--bg-body); border-color: var(--border-color); color: var(--text-primary); text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;">
            <i class="fa-solid fa-pen-to-square"></i> Edit Details
        </a>
    </div>
</div>

<!-- Screen Only: Payment Audit and Metadata Bar -->
<div class="print-control-bar"
    style="margin-bottom: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
    <!-- Box 1: Status & Payment Progress -->
    <div class="card"
        style="padding: 1rem 1.25rem; display: flex; flex-direction: column; justify-content: center; border-left: 4px solid <?= $invoice['status'] === 'paid' ? '#16a34a' : ($invoice['status'] === 'finalized' ? '#eab308' : '#64748b') ?>; background: var(--card-bg); border-radius: var(--border-radius-md); box-shadow: var(--shadow-sm);">
        <h4
            style="margin: 0 0 0.5rem 0; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; font-weight: 700;">
            Payment Progress & Status</h4>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">
                <?= $invoice['status'] === 'paid' ? 'Full Payment Completed' : ($invoice['status'] === 'finalized' ? 'Partially Settled' : 'Draft Estimate') ?>
            </span>
            <span class="badge badge-<?= h($invoice['status']) ?>"><?= h($invoice['status']) ?></span>
        </div>
        <div
            style="margin-top: 0.5rem; background: rgba(255,255,255,0.05); height: 8px; border-radius: 4px; overflow: hidden;">
            <?php
            $progress = $invoice['final_total'] > 0 ? min(100, (($invoice['advance_received'] + $invoice['balance_received']) / $invoice['final_total']) * 100) : 0;
            ?>
            <div
                style="background: <?= $invoice['status'] === 'paid' ? '#16a34a' : 'var(--accent-color)' ?>; width: <?= $progress ?>%; height: 100%;">
            </div>
        </div>
        <span
            style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; font-weight: 600;"><?= number_format($progress, 1) ?>%
            Paid</span>
    </div>

    <!-- Box 2: Advance Payment Info -->
    <div class="card"
        style="padding: 1rem 1.25rem; border-left: 4px solid #3b82f6; background: var(--card-bg); border-radius: var(--border-radius-md); box-shadow: var(--shadow-sm);">
        <h4
            style="margin: 0 0 0.25rem 0; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; font-weight: 700;">
            Advance Payment Details</h4>
        <?php if ($invoice['advance_received'] > 0): ?>
            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-primary);">
                <?= format_price($invoice['advance_received']) ?>
            </div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.35rem; line-height: 1.4;">
                <i class="fa-solid fa-credit-card" style="color: var(--accent-color); width: 14px;"></i> Method:
                <strong><?= h($invoice['advance_payment_method'] ?: 'CASH') ?></strong><br>
                <i class="fa-solid fa-calendar-day" style="color: var(--accent-color); width: 14px;"></i> Date:
                <strong><?= !empty($invoice['advance_paid_at']) ? date('d-M-Y h:i A', strtotime($invoice['advance_paid_at'])) : 'Unknown / Imported' ?></strong>
            </div>
        <?php else: ?>
            <div style="color: var(--text-muted); font-size: 0.85rem; font-style: italic; margin-top: 0.5rem;">No advance
                payment recorded.</div>
        <?php endif; ?>
    </div>

    <!-- Box 3: Balance / Rest Payment Info -->
    <div class="card"
        style="padding: 1rem 1.25rem; border-left: 4px solid #ef4444; background: var(--card-bg); border-radius: var(--border-radius-md); box-shadow: var(--shadow-sm);">
        <h4
            style="margin: 0 0 0.25rem 0; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; font-weight: 700;">
            Balance / Rest Details</h4>
        <?php if ($invoice['status'] === 'paid'): ?>
            <div style="font-size: 1.15rem; font-weight: 800; color: #16a34a;"><i class="fa-solid fa-circle-check"></i> Paid
                Fully</div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.35rem; line-height: 1.4;">
                <i class="fa-solid fa-credit-card" style="color: #16a34a; width: 14px;"></i> Method:
                <strong><?= h($invoice['balance_payment_method'] ?: 'CASH') ?></strong><br>
                <i class="fa-solid fa-calendar-day" style="color: #16a34a; width: 14px;"></i> Date:
                <strong><?= !empty($invoice['balance_paid_at']) ? date('d-M-Y h:i A', strtotime($invoice['balance_paid_at'])) : 'Unknown / Imported' ?></strong>
            </div>
        <?php else: ?>
            <div style="font-size: 1.15rem; font-weight: 800; color: #ef4444;">
                <?= format_price($invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received'])) ?>
            </div>
            <span
                style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; display: inline-block;">Remaining
                balance outstanding.</span>
        <?php endif; ?>
    </div>
</div>

<div class="template-preview-container" style="flex-direction: column; align-items: center; gap: 2rem;">

    <!-- Rendered paginated pages will go here -->
    <div id="paginatedPagesContainer" style="display: flex; flex-direction: column; gap: 2rem; align-items: center; width: 100%;"></div>

    <!-- INVOICE CARD START (Hidden Data Source) -->
    <div class="invoice-card <?= h($template) ?>" id="originalInvoiceCard" style="display: none;">

        <?php if ($template === 'orange_classic'): ?>
            <!-- ORANGE CLASSIC LAYOUT -->

            <header class="template-header">
                <div class="header-logo-container">
                    <div class="logo-icon">
                        <img src="../assets/images/logo.png" alt="Orange Events Logo" class="header-logo-img">
                    </div>
                    <div class="logo-title"><span
                            class="text-orange"><?= h(isset($settings['company_name']) ? strtoupper($settings['company_name']) : 'ORANGE EVENTS') ?></span>
                    </div>
                    <div class="logo-subtitle">
                        <?= h(isset($settings['company_address']) ? strtoupper($settings['company_address']) : 'THUMPOLY, ALAPPUZHA') ?>
                    </div>
                    <div class="logo-contact">
                        MOB :
                        <?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '9946731720 | 9847634728') ?><br>
                        Email :
                        <?= h(isset($settings['company_email']) ? $settings['company_email'] : 'orangedecorations@gmail.com') ?>
                    </div>
                </div>
            </header>

            <div class="header-date-bar" style="display: flex; justify-content: space-between; align-items: center; padding-left: 2rem; padding-right: 2rem;">
                <span>DATE: <?= format_date($event['event_date']) ?></span>
                <?php if ($catering): ?>
                    <span style="font-weight: 600; font-size: 0.95rem;">NOS: <?= $catering['total_plates'] ?> &nbsp;&nbsp;|&nbsp;&nbsp; PER PLATE: Rs. <?= number_format($catering['per_plate_price'], 0) ?></span>
                <?php endif; ?>
            </div>

            <div class="template-body">
                <!-- Left Accent Sidebar (Collage & Orange Vertical Band) -->
                <div class="left-accent-col">
                    <div class="image-collage">
                        <div class="collage-img img-stage"></div>
                        <div class="collage-img img-catering"></div>
                        <div class="collage-img img-drinks"></div>
                        <div class="collage-img img-deserts"></div>
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
                        <div class="stage-work-section">
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

                    <!-- Catering Categories dynamically -->
                    <?php foreach ($dishes_by_category as $category_name => $dishes): ?>
                        <div class="menu-category-section" data-category-name="<?= h($category_name) ?>">
                            <div class="page-break-indicator" onclick="togglePageBreak(this)">
                                <i class="fa-solid fa-scissors"></i> Insert Page Break Here
                            </div>
                            <div class="section-title-wrap">
                                <h3 class="section-title"><?= h(strtoupper($category_name)) ?></h3>
                            </div>
                            <ul
                                class="section-list<?= (strtoupper($category_name) === 'MAIN COURSE') ? ' multi-col-list' : '' ?>">
                                <?php foreach ($dishes as $dish): ?>
                                    <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                    <li class="item-row dish-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                        <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>

                    <!-- Bottom Invoice Balance Summary -->
                    <div class="footer-summary-bar">
                        <div class="payment-history" style="font-size: 0.8rem; color: #a4b0be; line-height: 1.4;">
                            Client: <strong><?= h($event['client_name']) ?></strong><br>
                            Venue: <?= h($event['venue']) ?><br>
                            Inv Status: <?= strtoupper($invoice['status']) ?>
                            <?php if ($invoice['advance_received'] > 0 && !empty($invoice['advance_paid_at'])): ?>
                                <br>Advance Paid: <?= format_price($invoice['advance_received']) ?>
                                (<?= h($invoice['advance_payment_method'] ?: 'CASH') ?>) on
                                <?= date('d-M-Y', strtotime($invoice['advance_paid_at'])) ?>
                            <?php endif; ?>
                            <?php if ($invoice['balance_received'] > 0 && !empty($invoice['balance_paid_at'])): ?>
                                <br>Balance Paid: <?= format_price($invoice['balance_received']) ?>
                                (<?= h($invoice['balance_payment_method'] ?: 'CASH') ?>) on
                                <?= date('d-M-Y', strtotime($invoice['balance_paid_at'])) ?>
                            <?php endif; ?>
                        </div>
                        <div class="summary-box"
                            style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #a4b0be;">
                                <span>Sub Total:</span>
                                <span
                                    style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['subtotal']) ?></span>
                            </div>
                            <?php if ($invoice['discount'] > 0): ?>
                                <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #ff6b6b;">
                                    <span>Discount:</span>
                                    <span>-<?= format_price($invoice['discount']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($invoice['tax_rate'] > 0): ?>
                                <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #a4b0be;">
                                    <span>GST (<?= (float) $invoice['tax_rate'] ?>%):</span>
                                    <span
                                        style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['tax_amount']) ?></span>
                                </div>
                            <?php endif; ?>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #a4b0be;">
                                <span>Grand Total:</span>
                                <span
                                    style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['final_total']) ?></span>
                            </div>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #a4b0be;">
                                <span>Amount Paid:</span>
                                <span
                                    style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['advance_received'] + $invoice['balance_received']) ?></span>
                            </div>
                            <div
                                style="display: flex; gap: 1rem; font-size: 1rem; color: #ffffff; border-top: 1px dashed rgba(255,255,255,0.2); padding-top: 0.25rem; font-weight: bold;">
                                <span style="color: #eb6b34;">Rest to Get:</span>
                                <span
                                    style="color: #eb6b34;"><?= format_price($invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received'])) ?></span>
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
                <div style="font-size: 0.85rem; color: #7f6a58; margin-top: 0.5rem;">Thumpoly, Alappuzha | Mob: 9946731720
                </div>
            </header>

            <div class="header-date-bar">
                Event Booking Date: <?= format_date($event['event_date']) ?>
            </div>

            <div class="template-body">
                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 2rem;">

                    <!-- Column 1: Food details -->
                    <div>
                        <?php foreach ($dishes_by_category as $cat_name => $dishes): ?>
                            <div class="menu-category-section" style="margin-bottom: 1.5rem;" data-category-name="<?= h($cat_name) ?>">
                                <div class="page-break-indicator" onclick="togglePageBreak(this)">
                                    <i class="fa-solid fa-scissors"></i> Insert Page Break Here
                                </div>
                                <div class="section-title-wrap">
                                    <h3 class="section-title">
                                        <?php
                                        $upper_name = strtoupper($cat_name);
                                        if ($upper_name === 'WELCOME DRINK') {
                                            echo 'Welcome Drinks';
                                        } elseif ($upper_name === 'STARTERS') {
                                            echo 'Appetizers & Starters';
                                        } elseif ($upper_name === 'MAIN COURSE') {
                                            echo 'Grand Buffet Main Course';
                                        } elseif ($upper_name === 'DESERTS') {
                                            echo 'Sweet Desserts';
                                        } else {
                                            echo h(ucwords(strtolower($cat_name)));
                                        }
                                        ?>
                                    </h3>
                                </div>
                                <div class="<?= (strtoupper($cat_name) === 'MAIN COURSE') ? 'multi-col-list' : '' ?>">
                                    <?php foreach ($dishes as $dish): ?>
                                        <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                        <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                            <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Column 2: Stage decoration services and catering invoice specs -->
                    <div>
                        <!-- Stage work -->
                        <?php if (!empty($stage_work_items)): ?>
                            <div class="stage-work-section" style="margin-bottom: 1.5rem;">
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
                    <div class="payment-history" style="line-height: 1.4;">
                        Client Name: <strong><?= h($event['client_name']) ?></strong><br>
                        Venue Location: <?= h($event['venue']) ?>
                        <?php if ($invoice['advance_received'] > 0 && !empty($invoice['advance_paid_at'])): ?>
                            <br>Advance Paid: <?= format_price($invoice['advance_received']) ?> via
                            <?= h($invoice['advance_payment_method'] ?: 'CASH') ?> on
                            <?= date('d-M-Y', strtotime($invoice['advance_paid_at'])) ?>
                        <?php endif; ?>
                        <?php if ($invoice['balance_received'] > 0 && !empty($invoice['balance_paid_at'])): ?>
                            <br>Balance Paid: <?= format_price($invoice['balance_received']) ?> via
                            <?= h($invoice['balance_payment_method'] ?: 'CASH') ?> on
                            <?= date('d-M-Y', strtotime($invoice['balance_paid_at'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="summary-box"
                        style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #7f6a58;">
                            <span>Sub Total:</span>
                            <span style="font-weight: 600; color: #3a2e2b;"><?= format_price($invoice['subtotal']) ?></span>
                        </div>
                        <?php if ($invoice['discount'] > 0): ?>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #aa2222;">
                                <span>Discount:</span>
                                <span>-<?= format_price($invoice['discount']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($invoice['tax_rate'] > 0): ?>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #7f6a58;">
                                <span>GST (<?= (float) $invoice['tax_rate'] ?>%):</span>
                                <span
                                    style="font-weight: 600; color: #3a2e2b;"><?= format_price($invoice['tax_amount']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #7f6a58;">
                            <span>Grand Total:</span>
                            <span
                                style="font-weight: 600; color: #3a2e2b;"><?= format_price($invoice['final_total']) ?></span>
                        </div>
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #7f6a58;">
                            <span>Amount Paid:</span>
                            <span
                                style="font-weight: 600; color: #3a2e2b;"><?= format_price($invoice['advance_received'] + $invoice['balance_received']) ?></span>
                        </div>
                        <div
                            style="display: flex; gap: 1rem; font-size: 1rem; color: #8c7223; border-top: 1px dashed rgba(140,114,35,0.3); padding-top: 0.25rem; font-weight: bold;">
                            <span>Rest to Get:</span>
                            <span><?= format_price($invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($template === 'midnight_dark'): ?>
            <!-- MIDNIGHT DARK TEMPLATE -->
            <header class="template-header">
                <div>
                    <div class="logo-title">ORANGE EVENTS</div>
                    <div style="font-size: 0.8rem; color: #8892b0; margin-top: 0.25rem;">Modern Catering & Decor Architects
                    </div>
                </div>
                <div class="header-date-bar">
                    DATE: <?= format_date($event['event_date']) ?>
                </div>
            </header>

            <div class="template-body">
                <!-- Main courses list -->
                <div>
                    <h4
                        style="color: #64ffda; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.25rem;">
                        EVENT DETAILS</h4>
                    <div
                        style="font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.4rem;">
                        <div>Client: <strong><?= h($event['client_name']) ?></strong></div>
                        <div>Phone: <?= h($event['client_phone']) ?></div>
                        <div>Venue: <?= h($event['venue']) ?></div>
                        <div>Time: <?= format_time($event['event_time']) ?></div>
                    </div>

                    <?php foreach ($dishes_by_category as $cat_name => $dishes): ?>
                        <div class="menu-category-section" style="margin-bottom: 1.25rem;" data-category-name="<?= h($cat_name) ?>">
                            <div class="page-break-indicator" onclick="togglePageBreak(this)">
                                <i class="fa-solid fa-scissors"></i> Insert Page Break Here
                            </div>
                            <div class="section-title-wrap">
                                <h3 class="section-title">
                                    <?php
                                    $upper_name = strtoupper($cat_name);
                                    if ($upper_name === 'WELCOME DRINK') {
                                        echo 'Welcome Drinks';
                                    } elseif ($upper_name === 'STARTERS') {
                                        echo 'Starters';
                                    } elseif ($upper_name === 'MAIN COURSE') {
                                        echo 'Main Courses';
                                    } else {
                                        echo h(ucwords(strtolower($cat_name)));
                                    }
                                    ?>
                                </h3>
                            </div>
                            <?php foreach ($dishes as $dish): ?>
                                <?php $p_suffix = ($dish['plates'] > 0) ? " (" . h($dish['plates']) . " Plates)" : ""; ?>
                                <div class="item-row<?= ($dish['plates'] > 0) ? ' highlighted-dish' : '' ?>">
                                    <span class="item-name"><?= h($dish['name']) . $p_suffix ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Financial details column -->
                <div>
                    <!-- Stage decor services -->
                    <?php if (!empty($stage_work_items)): ?>
                        <div class="stage-work-section" style="margin-bottom: 2rem;">
                            <h4
                                style="color: #64ffda; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.25rem;">
                                STAGE & SERVICES</h4>
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
                            <h4
                                style="color: #64ffda; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.25rem;">
                                CATERING STATEMENT</h4>
                            <div class="item-row">
                                <span>Plate rate:</span>
                                <span><?= format_price($catering['per_plate_price']) ?></span>
                            </div>
                            <div class="item-row">
                                <span>Count:</span>
                                <span><?= $catering['total_plates'] ?> plates</span>
                            </div>
                            <div class="item-row"
                                style="border-top: 1px dashed rgba(255,255,255,0.1); font-weight: bold; color: #64ffda;">
                                <span>Catering Total:</span>
                                <span><?= format_price($catering_total) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Footer stats -->
                <div class="footer-summary-bar"
                    style="display: flex; justify-content: space-between; align-items: flex-end; width: 100%;">
                    <div class="payment-history" style="font-size: 0.8rem; color: #8892b0; line-height: 1.4;">
                        Status: <?= strtoupper($invoice['status']) ?>
                        <?php if ($invoice['advance_received'] > 0 && !empty($invoice['advance_paid_at'])): ?>
                            <br>Advance: <?= format_price($invoice['advance_received']) ?> via
                            <?= h($invoice['advance_payment_method'] ?: 'CASH') ?> on
                            <?= date('d-M-Y', strtotime($invoice['advance_paid_at'])) ?>
                        <?php endif; ?>
                        <?php if ($invoice['balance_received'] > 0 && !empty($invoice['balance_paid_at'])): ?>
                            <br>Balance: <?= format_price($invoice['balance_received']) ?> via
                            <?= h($invoice['balance_payment_method'] ?: 'CASH') ?> on
                            <?= date('d-M-Y', strtotime($invoice['balance_paid_at'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="summary-box"
                        style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #8892b0;">
                            <span>Sub Total:</span>
                            <span style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['subtotal']) ?></span>
                        </div>
                        <?php if ($invoice['discount'] > 0): ?>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #ff6b6b;">
                                <span>Discount:</span>
                                <span>-<?= format_price($invoice['discount']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($invoice['tax_rate'] > 0): ?>
                            <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #8892b0;">
                                <span>GST (<?= (float) $invoice['tax_rate'] ?>%):</span>
                                <span
                                    style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['tax_amount']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #8892b0;">
                            <span>Grand Total:</span>
                            <span
                                style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['final_total']) ?></span>
                        </div>
                        <div style="display: flex; gap: 1rem; font-size: 0.85rem; color: #8892b0;">
                            <span>Amount Paid:</span>
                            <span
                                style="font-weight: 600; color: #ffffff;"><?= format_price($invoice['advance_received'] + $invoice['balance_received']) ?></span>
                        </div>
                        <div
                            style="display: flex; gap: 1rem; font-size: 1rem; color: #64ffda; border-top: 1px dashed rgba(100,255,218,0.2); padding-top: 0.25rem; font-weight: bold;">
                            <span>Rest to Get:</span>
                            <span><?= format_price($invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received'])) ?></span>
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
                    'price' => (float) $sw['custom_price'],
                    'discount' => '0.00%',
                    'amount' => (float) $sw['custom_price']
                ];
            }

            // 2. Add Catering Package
            if ($catering) {
                $table_items[] = [
                    'name' => 'Catering Package (' . format_price($catering['per_plate_price']) . ' per plate)',
                    'category' => 'Catering Services',
                    'size' => 'Standard',
                    'qty' => (int) $catering['total_plates'],
                    'unit' => 'Plates',
                    'price' => (float) $catering['per_plate_price'],
                    'discount' => '0.00%',
                    'amount' => (float) $catering_total
                ];

                // 3. Add Selected Dishes (zero cost, marked as "INCLUDED")
                foreach ($dishes_by_category as $cat_name => $dishes) {
                    foreach ($dishes as $dish) {
                        $qty = ($dish['plates'] > 0) ? (int) $dish['plates'] : 1;
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
            <div class="aedan-header"
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                        <img src="../assets/images/logo.png" alt="Company Logo" style="height: 55px; width: auto;">
                        <div
                            style="font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 800; color: #f07c1b; line-height: 1; letter-spacing: -0.02em; text-transform: uppercase;">
                            <?= h(isset($settings['company_name']) ? $settings['company_name'] : 'orange decorations') ?>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; line-height: 1.4; color: #1e293b;">
                        <strong>(<?= h(isset($settings['company_subtitle']) ? $settings['company_subtitle'] : 'Premium Catering & Stage Decors') ?>)</strong><br>
                        <?= h(isset($settings['company_address']) ? $settings['company_address'] : 'Thumpoly P.O, Alappuzha') ?><br>
                        Email:
                        <?= h(isset($settings['company_email']) ? $settings['company_email'] : 'orangedecorations@gmail.com') ?><br>
                        Phone:
                        <?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '9946731720 | 9847634728') ?><br>
                        State: <?= h(isset($settings['company_state']) ? $settings['company_state'] : '32-Kerala') ?>
                        <?php if (!empty($settings['company_gstin'])): ?>
                            <br><strong>GSTIN:</strong> <?= h($settings['company_gstin']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="text-align: right;">
                    <h1
                        style="font-size: 2.2rem; font-weight: 800; color: #1e293b; margin: 0 0 0.75rem 0; letter-spacing: 0.05em; text-transform: uppercase;">
                        Tax Invoice</h1>
                    <div style="font-size: 0.9rem; line-height: 1.5; color: #1e293b;">
                        <strong>Invoice No:</strong> <?= h($invoice['invoice_number']) ?><br>
                        <strong>Date:</strong> <?= format_date($event['event_date']) ?><br>
                        <strong>Place of Supply:</strong> 32-Kerala
                    </div>
                </div>
            </div>

            <!-- Bill To Section -->
            <div
                style="margin-bottom: 1.5rem; border-top: 1px solid #000000; padding-top: 0.75rem; font-family: 'Inter', sans-serif;">
                <div style="font-size: 0.85rem; line-height: 1.4; color: #1e293b;">
                    <strong style="font-size: 0.9rem; color: #000000; text-transform: uppercase;">Bill To:</strong><br>
                    <span
                        style="font-weight: 700; font-size: 0.95rem; color: #000000;"><?= h($event['client_name']) ?></span><br>
                    <?= h($event['venue']) ?><br>
                    Contact No: <?= h($event['client_phone']) ?><br>
                    Email: <?= h($event['client_email']) ?><br>
                    State: 32-Kerala
                </div>
            </div>

            <!-- Items Table -->
            <table class="aedan-table"
                style="width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; border: 1.2px solid #000000; font-family: 'Inter', sans-serif;">
                <thead>
                    <tr style="background-color: #f8fafc; border-bottom: 1.2px solid #000000;">
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; width: 50px; text-align: center; color: #000000;">
                            Sl No</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: left; color: #000000;">
                            Item Name</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: left; width: 150px; color: #000000;">
                            Category</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: left; width: 90px; color: #000000;">
                            Size</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: center; width: 80px; color: #000000;">
                            Quantity</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: center; width: 70px; color: #000000;">
                            Unit</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right; width: 110px; color: #000000;">
                            Price/Unit</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: center; width: 90px; color: #000000;">
                            Discount</th>
                        <th
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right; width: 120px; color: #000000;">
                            Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sl = 1;
                    foreach ($table_items as $item):
                        $is_dish = !empty($item['is_dish']);
                        ?>
                        <tr class="countable-row <?= $is_dish ? 'dish-row' : '' ?>"
                            style="border-bottom: 1px solid #000000; background-color: #ffffff; color: #000000;">
                            <td class="serial-col"
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;">
                            </td>
                            <td
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: <?= $is_dish ? 'normal' : '700' ?>;">
                                <?= h($item['name']) ?>
                            </td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem;">
                                <?= h($item['category']) ?>
                            </td>
                            <td style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem;">
                                <?= h($item['size']) ?>
                            </td>
                            <td
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;">
                                <?= $item['qty'] ?>
                            </td>
                            <td
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;">
                                <?= h($item['unit']) ?>
                            </td>
                            <td
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right;">
                                <?= $item['price'] > 0 ? format_price($item['price']) : 'Included' ?>
                            </td>
                            <td
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: center;">
                                <?= h($item['discount']) ?>
                            </td>
                            <td
                                style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right; font-weight: bold;">
                                <?= $item['amount'] > 0 ? format_price($item['amount']) : 'Included' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Total Row -->
                    <tr
                        style="border-top: 1.2px solid #000000; font-weight: bold; background-color: #f8fafc; color: #000000;">
                        <td colspan="8"
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right; text-transform: uppercase;">
                            Total</td>
                        <td
                            style="border: 1px solid #000000; padding: 0.4rem 0.6rem; font-size: 0.8rem; text-align: right; font-weight: 800;">
                            <?= format_price($invoice['final_total']) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Bottom Boxes Layout (Side by Side) -->
            <div
                style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1rem; margin-bottom: 1.5rem; font-family: 'Inter', sans-serif; color: #000000;">
                <!-- Left Details Box -->
                <div style="border: 1.2px solid #000000; display: flex; flex-direction: column;">
                    <div style="border-bottom: 1.2px solid #000000; padding: 0.5rem 0.75rem;">
                        <span
                            style="font-size: 0.75rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.03em;">Invoice
                            Amount in Words</span>
                        <div style="font-size: 0.8rem; font-weight: 700; margin-top: 0.15rem; color: #000000;">
                            <?= convert_number_to_words($invoice['final_total']) ?> Only
                        </div>
                    </div>
                    <div style="padding: 0.5rem 0.75rem; flex-grow: 1;">
                        <span
                            style="font-size: 0.75rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.03em;">Description</span>
                        <div style="font-size: 0.8rem; margin-top: 0.15rem; color: #000000; line-height: 1.4;">
                            Event package setup: "<?= h($event['title']) ?>" at <?= h($event['venue']) ?>.
                            Includes all standard catering management, curated stage decors, guest reception coordination,
                            and venue cleanup.
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
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right;">
                                <?= format_price($invoice['subtotal']) ?>
                            </td>
                        </tr>
                        <?php if ($invoice['discount'] > 0): ?>
                            <tr style="border-bottom: 1px solid #000000;">
                                <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 600; color: #aa2222;">
                                    Discount</td>
                                <td
                                    style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right; color: #aa2222;">
                                    -<?= format_price($invoice['discount']) ?></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #000000;">
                                <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 600;">Taxable Value</td>
                                <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right;">
                                    <?= format_price(max(0, $invoice['subtotal'] - $invoice['discount'])) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($invoice['tax_rate'] > 0): ?>
                            <tr style="border-bottom: 1px solid #000000;">
                                <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 600;">GST
                                    (<?= (float) $invoice['tax_rate'] ?>%)</td>
                                <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right;">
                                    <?= format_price($invoice['tax_amount']) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr style="border-bottom: 1px solid #000000; font-weight: bold; background-color: #f8fafc;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700;">Grand Total</td>
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 800; text-align: right;">
                                <?= format_price($invoice['final_total']) ?>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #000000;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 600;">Amount Paid</td>
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-align: right;">
                                <?= format_price($invoice['advance_received'] + $invoice['balance_received']) ?>
                            </td>
                        </tr>
                        <?php if ($invoice['advance_received'] > 0 && !empty($invoice['advance_paid_at'])): ?>
                            <tr class="payment-history"
                                style="border-bottom: 1px solid #000000; font-size: 0.7rem; color: #475569;">
                                <td colspan="2" style="padding: 0.25rem 0.6rem; font-style: italic;">
                                    ↳ Adv Paid: <?= format_price($invoice['advance_received']) ?> via
                                    <?= h($invoice['advance_payment_method'] ?: 'CASH') ?> on
                                    <?= date('d-M-Y', strtotime($invoice['advance_paid_at'])) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr style="border-bottom: 1px solid #000000; font-weight: bold; background-color: #fcf2f2;">
                            <td style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; color: #dc2626;">Rest to
                                Pay</td>
                            <td
                                style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 800; text-align: right; color: #dc2626;">
                                <?= format_price($invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received'])) ?>
                            </td>
                        </tr>
                        <?php if ($invoice['balance_received'] > 0 && !empty($invoice['balance_paid_at'])): ?>
                            <tr class="payment-history"
                                style="border-bottom: 1px solid #000000; font-size: 0.7rem; color: #475569;">
                                <td colspan="2" style="padding: 0.25rem 0.6rem; font-style: italic;">
                                    ↳ Bal Paid: <?= format_price($invoice['balance_received']) ?> via
                                    <?= h($invoice['balance_payment_method'] ?: 'CASH') ?> on
                                    <?= date('d-M-Y', strtotime($invoice['balance_paid_at'])) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($invoice['status'] === 'paid'): ?>
                            <tr style="border-bottom: 1px solid #000000; font-weight: bold; background-color: #e2f0d9;">
                                <td colspan="2"
                                    style="padding: 0.35rem 0.6rem; font-size: 0.75rem; font-weight: 700; color: #15803d; text-align: center; text-transform: uppercase;">
                                    <i class="fa-solid fa-circle-check"></i> Full Payment Completed
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="2"
                                style="padding: 0.4rem 0.6rem; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">
                                Primary Method: <?= h($invoice['payment_method'] ?: 'PENDING / CASH') ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Terms & Conditions Section -->
            <div
                style="background-color: #f1f5f9; padding: 0.5rem 0.75rem; margin-bottom: 1.5rem; font-family: 'Inter', sans-serif; color: #000000; border-radius: 4px;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 0.15rem;">Terms
                    and Conditions</div>
                <div style="font-size: 0.75rem; line-height: 1.3;">
                    Thanks for choosing
                    <?= h(isset($settings['company_name']) ? $settings['company_name'] : 'Orange Decorations') ?>! Total
                    Items: <?= count($table_items) ?>
                </div>
            </div>

        </div>

    <?php elseif ($template === 'modern_minimalist'): ?>
        <!-- MODERN MINIMALIST TEMPLATE -->
        <header class="template-header">
            <div class="header-logo-container">
                <img src="../assets/images/logo.png" alt="Company Logo" class="header-logo-img">
                <div>
                    <div class="logo-title">
                        <?= h(isset($settings['company_name']) ? $settings['company_name'] : 'ORANGE EVENTS') ?>
                    </div>
                    <div class="logo-subtitle">
                        <?= h(isset($settings['company_subtitle']) ? $settings['company_subtitle'] : 'Premium Catering & Stage Decors') ?>
                    </div>
                </div>
            </div>
            <div class="logo-contact">
                <strong>Proposal No:</strong> <?= h($invoice['invoice_number']) ?><br>
                <strong>Date:</strong> <?= format_date($event['event_date']) ?><br>
                MOB: <?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '9946731720') ?>
            </div>
        </header>

        <div class="header-date-bar">
            <div>Prepared For: <strong><?= h($event['client_name']) ?></strong> (<?= h($event['client_phone']) ?>)</div>
            <div>Venue: <strong><?= h($event['venue']) ?></strong></div>
        </div>

        <div class="template-body" style="grid-template-columns: 1fr 1fr; gap: 3rem;">
            <!-- Column 1: Food Details & Services -->
            <div>
                <!-- Catering Package & Dishes -->
                <div style="margin-bottom: 2rem;">
                    <div class="section-title-wrap">
                        <h3 class="section-title">Menu Curation</h3>
                        <span class="section-subtitle">Exquisite food selection for your event</span>
                    </div>

                    <?php foreach ($dishes_by_category as $cat_name => $dishes): ?>
                        <div class="menu-category-section" style="margin-bottom: 1.5rem;" data-category-name="<?= h($cat_name) ?>">
                            <div class="page-break-indicator" onclick="togglePageBreak(this)">
                                <i class="fa-solid fa-scissors"></i> Insert Page Break Here
                            </div>
                            <strong
                                style="font-size: 0.85rem; color: #f07c1b; text-transform: uppercase; letter-spacing: 0.05em; display: block; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.25rem; margin-bottom: 0.5rem;">
                                <?php
                                $upper_name = strtoupper($cat_name);
                                if ($upper_name === 'WELCOME DRINK') {
                                    echo 'Welcome Drinks';
                                } elseif ($upper_name === 'STARTERS') {
                                    echo 'Starters';
                                } elseif ($upper_name === 'MAIN COURSE') {
                                    echo 'Main Course';
                                } elseif ($upper_name === 'DESERTS') {
                                    echo 'Desserts';
                                } else {
                                    echo h($cat_name);
                                }
                                ?>
                            </strong>
                            <?php foreach ($dishes as $dish): ?>
                                <div class="item-row">
                                    <span class="item-name"><?= h($dish['name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Column 2: Stage & Services -->
            <div>
                <!-- Stage work -->
                <?php if (!empty($stage_work_items)): ?>
                    <div class="stage-work-section" style="margin-bottom: 2rem;">
                        <div class="section-title-wrap">
                            <h3 class="section-title">Stage & Decor Plan</h3>
                            <span class="section-subtitle">Visual & ambiance arrangements</span>
                        </div>
                        <?php foreach ($stage_work_items as $sw): ?>
                            <div class="item-row">
                                <span class="item-name"><?= h($sw['item_name']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div
                    style="background-color: #f8fafc; padding: 1.5rem; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 2rem;">
                    <h4
                        style="margin: 0 0 0.75rem 0; font-size: 0.9rem; text-transform: uppercase; color: #0f172a; font-weight: 700; letter-spacing: 0.05em;">
                        Proposal Notice</h4>
                    <p style="font-size: 0.8rem; line-height: 1.6; color: #475569; margin: 0;">
                        This is a curated menu and decoration proposal created specifically for your upcoming event. Items
                        can be customized or scaled as per your guest count and preference.
                    </p>
                </div>
            </div>
        </div>

        <div
            style="border-top: 2px solid #f1f5f9; padding-top: 1.5rem; margin-top: 3rem; text-align: center; font-size: 0.8rem; color: #64748b; font-family: 'Inter', sans-serif;">
            Thank you for considering
            <?= h(isset($settings['company_name']) ? $settings['company_name'] : 'Orange Events') ?>. We look forward to
            making your event memorable!
        </div>

    <?php elseif ($template === 'emerald_luxury'): ?>
        <!-- EMERALD LUXURY TEMPLATE -->
        <header class="template-header">
            <div class="header-logo-container">
                <img src="../assets/images/logo.png" alt="Company Logo" class="header-logo-img">
            </div>
            <div class="logo-title"><?= h(isset($settings['company_name']) ? $settings['company_name'] : 'ORANGE EVENTS') ?>
            </div>
            <div class="logo-subtitle">
                <?= h(isset($settings['company_subtitle']) ? $settings['company_subtitle'] : 'Premium Catering & Stage Decors') ?>
            </div>
            <div class="logo-contact">
                Mob: <?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '9946731720') ?> | Address:
                <?= h(isset($settings['company_address']) ? $settings['company_address'] : 'Thumpoly, Alappuzha') ?>
            </div>
        </header>

        <div class="header-date-bar">
            ROYAL EVENT PROPOSAL • <?= h(strtoupper($event['client_name'])) ?> • DATE:
            <?= format_date($event['event_date']) ?>
        </div>

        <div class="template-body" style="grid-template-columns: 1fr 1fr; gap: 3rem;">
            <!-- Column 1: Food Details & Services -->
            <div>
                <div style="margin-bottom: 2rem;">
                    <div class="section-title-wrap">
                        <h3 class="section-title">Royal Menu</h3>
                        <span class="section-subtitle">Exquisite Catering Selections</span>
                    </div>

                    <?php foreach ($dishes_by_category as $cat_name => $dishes): ?>
                        <div class="menu-category-section" style="margin-bottom: 1.5rem;" data-category-name="<?= h($cat_name) ?>">
                            <div class="page-break-indicator" onclick="togglePageBreak(this)">
                                <i class="fa-solid fa-scissors"></i> Insert Page Break Here
                            </div>
                            <strong
                                style="font-size: 0.85rem; color: #d4af37; text-transform: uppercase; letter-spacing: 0.1em; display: block; border-bottom: 1px solid rgba(212, 175, 55, 0.2); padding-bottom: 0.25rem; margin-bottom: 0.5rem;">
                                <?php
                                $upper_name = strtoupper($cat_name);
                                if ($upper_name === 'WELCOME DRINK') {
                                    echo 'Welcome Drinks';
                                } elseif ($upper_name === 'STARTERS') {
                                    echo 'Royal Appetizers';
                                } elseif ($upper_name === 'MAIN COURSE') {
                                    echo 'Grand Buffet Main Course';
                                } elseif ($upper_name === 'DESERTS') {
                                    echo 'Sweet Confiserie & Desserts';
                                } else {
                                    echo h($cat_name);
                                }
                                ?>
                            </strong>
                            <?php foreach ($dishes as $dish): ?>
                                <div class="item-row">
                                    <span class="item-name"><?= h($dish['name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Column 2: Event Details & Stage Services -->
            <div>
                <?php if (!empty($stage_work_items)): ?>
                    <div class="stage-work-section" style="margin-bottom: 2rem;">
                        <div class="section-title-wrap">
                            <h3 class="section-title">Decors & Artistry</h3>
                            <span class="section-subtitle">Stage designs & custom requirements</span>
                        </div>
                        <?php foreach ($stage_work_items as $sw): ?>
                            <div class="item-row">
                                <span class="item-name"><?= h($sw['item_name']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div
                    style="background-color: #03110d; padding: 1.5rem; border: 1px solid rgba(212, 175, 55, 0.2); margin-top: 2rem;">
                    <h4
                        style="margin: 0 0 1rem 0; font-size: 0.95rem; text-transform: uppercase; color: #d4af37; font-weight: 700; letter-spacing: 0.1em; text-align: center;">
                        Royal Venue</h4>
                    <div style="font-size: 0.85rem; line-height: 1.8; color: #f1f2f6; font-family: 'Inter', sans-serif;">
                        <strong>Proposed Venue:</strong> <?= h($event['venue']) ?><br>
                        <strong>Proposed Time:</strong> <?= format_time($event['event_time']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div
            style="border-top: 1px solid rgba(212, 175, 55, 0.2); padding-top: 1.5rem; margin-top: 3rem; text-align: center; font-size: 0.85rem; color: #a4b0be; font-family: 'Inter', sans-serif;">
            We are dedicated to crafting an extraordinary experience for you.
        </div>

    <?php elseif ($template === 'blossom_chic'): ?>
        <!-- BLOSSOM CHIC TEMPLATE -->
        <header class="template-header">
            <div class="header-logo-container">
                <img src="../assets/images/logo.png" alt="Company Logo" class="header-logo-img">
                <div>
                    <div class="logo-title">
                        <?= h(isset($settings['company_name']) ? $settings['company_name'] : 'ORANGE EVENTS') ?>
                    </div>
                    <div class="logo-subtitle">
                        <?= h(isset($settings['company_subtitle']) ? $settings['company_subtitle'] : 'Premium Catering & Stage Decors') ?>
                    </div>
                </div>
            </div>
            <div class="logo-contact">
                Mob: <?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '9946731720') ?><br>
                Email:
                <?= h(isset($settings['company_email']) ? $settings['company_email'] : 'orangedecorations@gmail.com') ?>
            </div>
        </header>

        <div class="header-date-bar">
            Celebration Details for <?= h($event['client_name']) ?> • Event Date: <?= format_date($event['event_date']) ?>
        </div>

        <div class="template-body" style="grid-template-columns: 1fr 1fr; gap: 3rem;">
            <!-- Column 1: Food Details & Services -->
            <div>
                <div style="margin-bottom: 2rem;">
                    <div class="section-title-wrap">
                        <h3 class="section-title">Curated Menu</h3>
                        <span class="section-subtitle">Chic Food & Refreshments selections</span>
                    </div>

                    <?php foreach ($dishes_by_category as $cat_name => $dishes): ?>
                        <div class="menu-category-section" style="margin-bottom: 1.25rem;" data-category-name="<?= h($cat_name) ?>">
                            <div class="page-break-indicator" onclick="togglePageBreak(this)">
                                <i class="fa-solid fa-scissors"></i> Insert Page Break Here
                            </div>
                            <strong
                                style="font-size: 0.85rem; color: #b25068; text-transform: uppercase; letter-spacing: 0.05em; display: block; border-bottom: 1px dotted #ffd2d2; padding-bottom: 0.25rem; margin-bottom: 0.5rem;">
                                <?php
                                $upper_name = strtoupper($cat_name);
                                if ($upper_name === 'WELCOME DRINK') {
                                    echo 'Welcome Drinks';
                                } elseif ($upper_name === 'STARTERS') {
                                    echo 'Sweet & Savory Starters';
                                } elseif ($upper_name === 'MAIN COURSE') {
                                    echo 'Grand Main Course';
                                } elseif ($upper_name === 'DESERTS') {
                                    echo 'Sweet Confiseur & Desserts';
                                } else {
                                    echo h($cat_name);
                                }
                                ?>
                            </strong>
                            <?php foreach ($dishes as $dish): ?>
                                <div class="item-row">
                                    <span class="item-name"><?= h($dish['name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Column 2: Event Details & Stage Services -->
            <div>
                <?php if (!empty($stage_work_items)): ?>
                    <div class="stage-work-section" style="margin-bottom: 2rem;">
                        <div class="section-title-wrap">
                            <h3 class="section-title">Decorations</h3>
                            <span class="section-subtitle">Chic stage styling & accessories</span>
                        </div>
                        <?php foreach ($stage_work_items as $sw): ?>
                            <div class="item-row">
                                <span class="item-name"><?= h($sw['item_name']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div
                    style="background-color: #fff0f0; padding: 1.25rem; border-radius: 8px; border: 1px solid #ffd2d2; margin-top: 2rem;">
                    <h4
                        style="margin: 0 0 0.75rem 0; font-size: 0.9rem; text-transform: uppercase; color: #b25068; font-weight: 700; letter-spacing: 0.05em;">
                        Ambiance & Plan</h4>
                    <div style="font-size: 0.8rem; line-height: 1.6; color: #7c5c64; font-family: 'Inter', sans-serif;">
                        <strong>Venue:</strong> <?= h($event['venue']) ?><br>
                        <strong>Time:</strong> <?= format_time($event['event_time']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div
            style="border-top: 2px dashed #ffd2d2; padding-top: 1.5rem; margin-top: 3rem; text-align: center; font-size: 0.85rem; color: #7c5c64; font-family: 'Inter', sans-serif;">
            We are honored to be a part of your celebrations!
        </div>
    <?php endif; ?>

</div>
<!-- INVOICE CARD END -->

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    // Apply template class to body for custom print styles
    document.body.classList.add('template-<?= h($template) ?>');

    document.getElementById('toggleTotalCheckbox').addEventListener('change', function (e) {
        const summaryBoxes = document.querySelectorAll('.summary-box');
        summaryBoxes.forEach(box => {
            if (e.target.checked) {
                box.style.setProperty('display', 'block', 'important');
            } else {
                box.style.setProperty('display', 'none', 'important');
            }
        });
    });

    document.getElementById('toggleDishesCheckbox').addEventListener('change', function (e) {
        document.querySelectorAll('.invoice-card').forEach(card => {
            if (e.target.checked) card.classList.remove('hide-dishes');
            else card.classList.add('hide-dishes');
        });
    });

    document.getElementById('togglePaymentCheckbox').addEventListener('change', function (e) {
        document.querySelectorAll('.invoice-card').forEach(card => {
            if (e.target.checked) card.classList.remove('hide-payments');
            else card.classList.add('hide-payments');
        });
    });

    // Toggle Page Break Split Controls visibility
    document.getElementById('toggleSplitCheckbox').addEventListener('change', function (e) {
        document.querySelectorAll('.invoice-card').forEach(card => {
            if (e.target.checked) card.classList.add('enable-split-controls');
            else card.classList.remove('enable-split-controls');
        });
    });

    // ---------------------------------------------------------
    // JS PAGINATION LOGIC
    // ---------------------------------------------------------
    function togglePageBreak(indicator) {
        const section = indicator.closest('.menu-category-section');
        if (!section) return;
        
        const catName = section.getAttribute('data-category-name');
        
        // Update the original hidden card
        const originalCard = document.getElementById('originalInvoiceCard');
        const originalSection = originalCard.querySelector(`.menu-category-section[data-category-name="${catName}"]`);
        
        if (originalSection) {
            originalSection.classList.toggle('has-page-break');
            const isBroken = originalSection.classList.contains('has-page-break');
            
            // Persist
            const eventId = '<?= (int)$event["id"] ?>';
            const template = '<?= h($template) ?>';
            localStorage.setItem(`pb_${eventId}_${template}_${catName}`, isBroken ? 'true' : 'false');
            
            // Re-render pages
            renderPaginatedPages();
        }
    }

    function updateIndicatorText(indicator, isBroken) {
        if (isBroken) {
            indicator.innerHTML = '<i class="fa-solid fa-scissors"></i> Page Break Active (Starts new page here)';
        } else {
            indicator.innerHTML = '<i class="fa-solid fa-scissors"></i> Insert Page Break Here';
        }
    }

    function renderPaginatedPages() {
        const originalCard = document.getElementById('originalInvoiceCard');
        const container = document.getElementById('paginatedPagesContainer');
        container.innerHTML = '';
        
        const allSections = Array.from(originalCard.querySelectorAll('.menu-category-section'));
        if (allSections.length === 0) {
            // No categories, just show the card
            const clone = originalCard.cloneNode(true);
            clone.id = 'invoicePage_1';
            clone.style.display = 'flex';
            container.appendChild(clone);
            return;
        }

        const pagesChunks = [];
        let currentPage = [];
        
        allSections.forEach((sec, index) => {
            // Split if it's marked as a page break and NOT the very first section
            if (sec.classList.contains('has-page-break') && index !== 0 && currentPage.length > 0) {
                pagesChunks.push(currentPage);
                currentPage = [];
            }
            currentPage.push(index);
        });
        if (currentPage.length > 0) {
            pagesChunks.push(currentPage);
        }

        pagesChunks.forEach((pageIndices, pageIndex) => {
            const clone = originalCard.cloneNode(true);
            clone.id = 'invoicePage_' + (pageIndex + 1);
            clone.style.display = 'flex';
            
            // Sync checkbox states just in case
            if (document.getElementById('toggleDishesCheckbox').checked) clone.classList.remove('hide-dishes');
            else clone.classList.add('hide-dishes');
            
            if (document.getElementById('togglePaymentCheckbox').checked) clone.classList.remove('hide-payments');
            else clone.classList.add('hide-payments');
            
            if (document.getElementById('toggleSplitCheckbox').checked) clone.classList.add('enable-split-controls');
            else clone.classList.remove('enable-split-controls');

            // Remove categories that do not belong to this page chunk
            const cloneSections = Array.from(clone.querySelectorAll('.menu-category-section'));
            cloneSections.forEach((cSec, index) => {
                if (!pageIndices.includes(index)) {
                    cSec.remove();
                } else {
                    const indicator = cSec.querySelector('.page-break-indicator');
                    if (indicator) {
                        updateIndicatorText(indicator, cSec.classList.contains('has-page-break'));
                    }
                }
            });

            // Hide the footer summary block on all pages except the very last one
            if (pageIndex < pagesChunks.length - 1) {
                const footer = clone.querySelector('.footer-summary-bar');
                if (footer) footer.style.display = 'none';
            }

            // Hide the stage work section on all pages except the very first one
            if (pageIndex > 0) {
                const stageWork = clone.querySelector('.stage-work-section');
                if (stageWork) stageWork.style.display = 'none';
            }

            container.appendChild(clone);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const eventId = '<?= (int)$event["id"] ?>';
        const template = '<?= h($template) ?>';
        const originalCard = document.getElementById('originalInvoiceCard');
        
        // Sync original card with localStorage state
        originalCard.querySelectorAll('.menu-category-section').forEach(section => {
            const catName = section.getAttribute('data-category-name');
            if (!catName) return;
            const isBroken = localStorage.getItem(`pb_${eventId}_${template}_${catName}`);
            
            if (isBroken !== null) {
                if (isBroken === 'true') {
                    section.classList.add('has-page-break');
                } else {
                    section.classList.remove('has-page-break');
                }
            }
        });
        
        // Render pages on load
        renderPaginatedPages();
    });

    // ---------------------------------------------------------
    // SAVE MULTIPLE IMAGES
    // ---------------------------------------------------------
    document.getElementById('downloadImageBtn').addEventListener('click', async function () {
        const pages = document.querySelectorAll('#paginatedPagesContainer .invoice-card');
        if (pages.length === 0) return;

        const btn = this;
        const originalBtnContent = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
        btn.disabled = true;

        try {
            for (let i = 0; i < pages.length; i++) {
                const page = pages[i];
                
                // Update button text to show progress
                if (pages.length > 1) {
                    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving Page ${i + 1}/${pages.length}...`;
                }

                page.classList.add('capture-mode');
                
                // Ensure page breaks display properly in the image
                page.querySelectorAll('.has-page-break').forEach(sec => {
                    sec.style.marginTop = '0';
                    sec.style.paddingTop = '0';
                    sec.style.borderTop = 'none';
                });

                await new Promise(resolve => setTimeout(resolve, 150));

                const canvas = await html2canvas(page, {
                    useCORS: true,
                    scale: 2,
                    backgroundColor: null,
                    scrollX: 0,
                    scrollY: -window.scrollY,
                    windowWidth: page.scrollWidth,
                    windowHeight: page.scrollHeight,
                });

                page.classList.remove('capture-mode');
                
                // Restore styles
                page.querySelectorAll('.has-page-break').forEach(sec => {
                    sec.style.marginTop = '';
                    sec.style.paddingTop = '';
                });

                const link = document.createElement('a');
                let fileName = '<?= h($invoice["invoice_number"]) ?>';
                if (pages.length > 1) fileName += `_Page_${i + 1}`;
                link.download = fileName + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                if (i < pages.length - 1) {
                    await new Promise(resolve => setTimeout(resolve, 800)); // Pause between downloads to allow browser to process
                }
            }
        } catch (err) {
            console.error('Error generating image:', err);
            alert('Failed to generate image. Please try again.');
        }

        btn.innerHTML = originalBtnContent;
        btn.disabled = false;
    });

    // ---------------------------------------------------------
    // WHATSAPP SHARE IMAGES
    // ---------------------------------------------------------
    const waShareBtn = document.getElementById('whatsappShareBtn');
    if (waShareBtn) {
        waShareBtn.addEventListener('click', async function () {
            const pages = document.querySelectorAll('#paginatedPagesContainer .invoice-card');
            if (pages.length === 0) return;

            const btn = this;
            const originalBtnContent = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing...';
            btn.disabled = true;

            try {
                let filesArray = [];
                
                for (let i = 0; i < pages.length; i++) {
                    const page = pages[i];
                    if (pages.length > 1) {
                        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing Page ${i + 1}/${pages.length}...`;
                    }

                    page.classList.add('capture-mode');
                    page.querySelectorAll('.has-page-break').forEach(sec => {
                        sec.style.marginTop = '0';
                        sec.style.paddingTop = '0';
                        sec.style.borderTop = 'none';
                    });

                    await new Promise(resolve => setTimeout(resolve, 150));

                    const canvas = await html2canvas(page, {
                        useCORS: true,
                        scale: 2,
                        backgroundColor: null,
                        scrollX: 0,
                        scrollY: -window.scrollY,
                        windowWidth: page.scrollWidth,
                        windowHeight: page.scrollHeight,
                    });

                    page.classList.remove('capture-mode');
                    page.querySelectorAll('.has-page-break').forEach(sec => {
                        sec.style.marginTop = '';
                        sec.style.paddingTop = '';
                    });

                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
                    let fileName = '<?= h($invoice["invoice_number"]) ?>';
                    if (pages.length > 1) fileName += `_Page_${i + 1}`;
                    fileName += '.png';
                    
                    const file = new File([blob], fileName, { type: 'image/png' });
                    filesArray.push(file);
                }
                
                const clientName = <?= json_encode($event['client_name']) ?>;
                const eventDate = <?= json_encode(format_date($event['event_date'])) ?>;
                const grandTotal = "Rs. " + <?= json_encode(number_format($invoice['final_total'], 2)) ?>;
                const amountPaid = "Rs. " + <?= json_encode(number_format($invoice['advance_received'] + $invoice['balance_received'], 2)) ?>;
                const restDue = "Rs. " + <?= json_encode(number_format($invoice['final_total'] - ($invoice['advance_received'] + $invoice['balance_received']), 2)) ?>;
                
                const waMessage = `*Invoice: ${clientName}*\nEvent Date: ${eventDate}\nGrand Total: ${grandTotal}\nAmount Paid: ${amountPaid}\n*Balance Due: ${restDue}*`;

                if (navigator.canShare && navigator.canShare({ files: filesArray })) {
                    // Mobile native share sheet
                    await navigator.share({
                        files: filesArray,
                        title: 'Event Invoice',
                        text: waMessage
                    });
                } else {
                    // Desktop fallback: Download files and open WhatsApp Web
                    alert("Your desktop browser doesn't support direct image attachment to WhatsApp. \n\n1. The images will now download to your computer.\n2. WhatsApp Web will open automatically.\n3. Simply drag and drop the downloaded images into the chat window!");
                    
                    for (let i = 0; i < filesArray.length; i++) {
                        const link = document.createElement('a');
                        link.download = filesArray[i].name;
                        link.href = URL.createObjectURL(filesArray[i]);
                        link.click();
                        if (i < filesArray.length - 1) await new Promise(r => setTimeout(r, 800));
                    }
                    
                    window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(waMessage)}`, '_blank');
                }
                
            } catch (err) {
                console.error('Error preparing WhatsApp share:', err);
                alert('Failed to prepare images for WhatsApp. Please try again.');
            }

            btn.innerHTML = originalBtnContent;
            btn.disabled = false;
        });
    }



    <?php if ($invoice['status'] === 'finalized'): ?>
        const openBtn = document.getElementById('openPaymentModalBtn');
        const closeBtn = document.getElementById('closePaymentModalBtn');
        const modal = document.getElementById('paymentModal');
        const amountInput = document.getElementById('amount_paid');
        const partialOptions = document.getElementById('partialPaymentOptions');
        const remSpan = document.getElementById('remainingBalance');
        const writeOffSpan = document.getElementById('writeOffBalance');
        const restToGive = <?= (float) $rest_to_give ?>;

        if (openBtn && modal) {
            openBtn.addEventListener('click', function () {
                const rest = <?= (float) $rest_to_give ?>;
                const confirmMsg = `Record full payment of Rs. ${rest.toLocaleString('en-IN')} now?\n\n- Click OK to save instantly.\n- Click Cancel to enter custom payment details (partial payment or bank methods).`;
                if (confirm(confirmMsg)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'mark_paid';
                    form.appendChild(actionInput);

                    const amountInput = document.createElement('input');
                    amountInput.type = 'hidden';
                    amountInput.name = 'amount_paid';
                    amountInput.value = rest;
                    form.appendChild(amountInput);

                    const methodInput = document.createElement('input');
                    methodInput.type = 'hidden';
                    methodInput.name = 'payment_method';
                    methodInput.value = 'CASH';
                    form.appendChild(methodInput);

                    document.body.appendChild(form);
                    form.submit();
                } else {
                    modal.style.display = 'flex';
                }
            });
        }

        if (closeBtn && modal) {
            closeBtn.addEventListener('click', function () {
                modal.style.display = 'none';
            });
        }

        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        if (amountInput) {
            amountInput.addEventListener('input', function () {
                const val = parseFloat(amountInput.value) || 0;
                if (val < restToGive) {
                    partialOptions.style.display = 'block';
                    const diff = (restToGive - val).toFixed(2);
                    remSpan.textContent = parseFloat(diff).toLocaleString('en-IN');
                    writeOffSpan.textContent = parseFloat(diff).toLocaleString('en-IN');
                } else {
                    partialOptions.style.display = 'none';
                }
            });
        }
    <?php endif; ?>

    const deleteInvoiceBtn = document.getElementById('deleteInvoiceBtn');
    if (deleteInvoiceBtn) {
        deleteInvoiceBtn.addEventListener('click', function () {
            const expectedNum = '<?= h($invoice["invoice_number"]) ?>';
            const userInput = prompt(`Are you absolutely sure you want to delete this invoice? This will reset the event booking back to Draft status.\n\nPlease type the invoice number "${expectedNum}" to confirm:`);

            if (userInput === null) {
                return;
            }

            if (userInput.trim() === expectedNum) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_invoice';
                form.appendChild(actionInput);

                const numInput = document.createElement('input');
                numInput.type = 'hidden';
                numInput.name = 'invoice_number';
                numInput.value = userInput.trim();
                form.appendChild(numInput);

                document.body.appendChild(form);
                form.submit();
            } else {
                alert("The invoice number you entered did not match. Deletion cancelled.");
            }
        });
    }
</script>

<!-- Payment Modal -->
<?php if ($invoice['status'] === 'finalized'): ?>
    <div id="paymentModal" class="modal-overlay">
        <div class="card"
            style="width: 450px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); padding: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.3); font-family: 'Inter', sans-serif;">
            <h3
                style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0 0 1.5rem 0; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                <i class="fa-solid fa-money-bill-wave" style="color: var(--accent-color);"></i>
                Record Final Payment
            </h3>

            <form action="" method="POST" id="paymentForm">
                <input type="hidden" name="action" value="mark_paid">

                <div style="margin-bottom: 1.25rem;">
                    <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Total Invoice
                        Amount</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">
                        <?= format_price($invoice['final_total']) ?>
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">Already Received</span>
                        <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary);">
                            <?= format_price($invoice['advance_received'] + $invoice['balance_received']) ?>
                        </div>
                    </div>
                    <div>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">Rest to Give</span>
                        <div style="font-size: 1rem; font-weight: 700; color: #dc2626;"><?= format_price($rest_to_give) ?>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="amount_paid" class="form-label"
                        style="font-weight: 600; margin-bottom: 0.5rem; display: block; color: var(--text-primary);">Amount
                        Received Today (Rs.)</label>
                    <input type="number" step="0.01" id="amount_paid" name="amount_paid" class="form-control"
                        value="<?= $rest_to_give ?>" max="<?= $rest_to_give ?>" min="0.01" required
                        style="font-size: 1.1rem; font-weight: 700; width: 100%; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="payment_method" class="form-label"
                        style="font-weight: 600; margin-bottom: 0.5rem; display: block; color: var(--text-primary);">Payment
                        Method</label>
                    <select name="payment_method" id="payment_method" class="form-control"
                        style="width: 100%; box-sizing: border-box;">
                        <option value="CASH">Cash</option>
                        <option value="BANK TRANSFER">Bank Transfer</option>
                        <option value="UPI">UPI (GPay/PhonePe)</option>
                        <option value="CARD">Debit/Credit Card</option>
                        <option value="CHEQUE">Cheque</option>
                    </select>
                </div>

                <!-- Partial Payment options -->
                <div id="partialPaymentOptions"
                    style="display: none; background: rgba(220, 38, 38, 0.05); border: 1px solid rgba(220, 38, 38, 0.2); padding: 0.75rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem;">
                    <div style="font-weight: 700; color: #dc2626; font-size: 0.85rem; margin-bottom: 0.25rem;">Partial
                        Payment Option</div>
                    <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.4; margin-bottom: 0.5rem;">
                        The amount is less than the rest to give. Please choose how to handle the rest:
                    </div>
                    <label
                        style="display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.85rem; color: var(--text-primary); cursor: pointer; margin-bottom: 0.5rem;">
                        <input type="radio" name="payment_treatment" value="partial" checked style="margin-top: 2px;">
                        <div>
                            <strong>Add to received</strong><br>
                            <span style="font-size: 0.75rem; color: var(--text-secondary);">Leaves a remaining balance of
                                Rs. <span id="remainingBalance">0</span></span>
                        </div>
                    </label>
                    <label
                        style="display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.85rem; color: var(--text-primary); cursor: pointer;">
                        <input type="radio" name="payment_treatment" value="write_off" style="margin-top: 2px;">
                        <div>
                            <strong>Mark as fully Paid anyway</strong><br>
                            <span style="font-size: 0.75rem; color: var(--text-secondary);">Write off the remaining Rs.
                                <span id="writeOffBalance">0</span></span>
                        </div>
                    </label>
                </div>

                <div
                    style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                    <button type="button" id="closePaymentModalBtn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>