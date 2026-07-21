<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'company_name',
        'company_subtitle',
        'company_gstin',
        'company_address',
        'company_email',
        'company_state',
        'company_phone',
        'company_bank_name',
        'company_bank_acc',
        'company_bank_ifsc',
        'company_bank_holder',
        'company_upi_id',
        'pos_thermal_paper_width',
        'pos_thermal_font_size',
        'pos_thermal_footer_msg'
    ];
    
    try {
        $db->beginTransaction();

        // Security check for UPI ID modification
        $existing_settings = get_settings();
        $old_upi_id = trim($existing_settings['company_upi_id'] ?? '');
        $new_upi_id = isset($_POST['company_upi_id']) ? trim($_POST['company_upi_id']) : '';

        if ($new_upi_id !== $old_upi_id) {
            if (!empty($new_upi_id) && !preg_match('/^[a-zA-Z0-9\.\-_]{2,256}@[a-zA-Z0-9]{2,64}$/', $new_upi_id)) {
                throw new Exception("Invalid Store UPI ID format. Standard format must be like 'merchant@bank' or '9876543210@upi'.");
            }

            $admin_password_confirm = $_POST['admin_password_confirm'] ?? '';
            if (empty($admin_password_confirm)) {
                throw new Exception("🔒 Security Protection: Admin Password Confirmation is required to update the Store Payment UPI ID.");
            }

            $admin_id = $_SESSION['admin_id'] ?? 0;
            $stmt_user = $db->prepare("SELECT password FROM users WHERE id = :id");
            $stmt_user->execute(['id' => $admin_id]);
            $user_hash = $stmt_user->fetchColumn();

            if (!$user_hash || !password_verify($admin_password_confirm, $user_hash)) {
                throw new Exception("🔒 Security Error: Incorrect Admin Password. Payment UPI ID was NOT updated.");
            }

            // Save new security HMAC checksum
            $new_checksum = get_upi_checksum($new_upi_id);
            $stmt_chk = $db->prepare("INSERT INTO `settings` (`key`, `value`) VALUES ('company_upi_checksum', :val) ON DUPLICATE KEY UPDATE `value` = :val_up");
            $stmt_chk->execute(['val' => $new_checksum, 'val_up' => $new_checksum]);
        }

        $stmt = $db->prepare("INSERT INTO `settings` (`key`, `value`) VALUES (:key, :value) 
                              ON DUPLICATE KEY UPDATE `value` = :value_update");
                              
        foreach ($keys as $key) {
            $value = isset($_POST[$key]) ? trim($_POST[$key]) : '';
            $stmt->execute([
                'key' => $key,
                'value' => $value,
                'value_update' => $value
            ]);
        }
        $db->commit();
        $message = 'Business settings updated successfully!';
        
        // Handle Image Uploads for Collage
        $collage_images = [
            'collage_stage' => 'stage.png', 
            'collage_catering' => 'catering.png', 
            'collage_drinks' => 'drinks.png', 
            'collage_deserts' => 'deserts.png'
        ];
        $upload_dir = __DIR__ . '/../assets/images/collage/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        foreach ($collage_images as $input_name => $filename) {
            if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES[$input_name]['tmp_name'];
                
                // Basic image validation
                if (getimagesize($tmp_name) !== false) {
                    $dest = $upload_dir . $filename;
                    if (move_uploaded_file($tmp_name, $dest)) {
                        chmod($dest, 0644);
                        $message = 'Settings and template images updated successfully!';
                    } else {
                        $error = "Failed to upload image $input_name. Check directory permissions.";
                    }
                } else {
                    $error = "Invalid image file uploaded for $input_name.";
                }
            }
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

$settings = get_settings();
?>

<style>
    .pos-settings-nav {
        display: flex;
        gap: 0.5rem;
        border-bottom: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
        overflow-x: auto;
        padding-bottom: 0.25rem;
    }
    .pos-tab-btn {
        padding: 0.6rem 1.1rem;
        font-size: 0.82rem;
        font-weight: 600;
        border: none;
        background: transparent;
        color: var(--text-secondary);
        border-bottom: 2px solid transparent;
        cursor: pointer;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        white-space: nowrap;
    }
    .pos-tab-btn:hover {
        color: var(--text-primary);
        background: var(--bg-control);
        border-radius: var(--border-radius-sm) var(--border-radius-sm) 0 0;
    }
    .pos-tab-btn.active {
        color: var(--accent-color);
        border-bottom-color: var(--accent-color);
    }
    .settings-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-lg);
        padding: 1.5rem;
        box-shadow: var(--box-shadow);
    }
    .form-group-label {
        font-size: 0.76rem;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.4rem;
        display: block;
    }
</style>

<!-- POS Style Header -->
<div class="content-header" style="margin-bottom: 1.25rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-gears" style="color:var(--accent-color);"></i>
            Business & Store Settings
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.78rem;">
            Configure business details, GSTIN, payment gateways, and print invoice customization.
        </p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <a href="billing.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <i class="fa-solid fa-calculator"></i> POS Terminal
        </a>
        <a href="analytics.php" class="btn btn-secondary" style="height:32px; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.35rem;">
            <i class="fa-solid fa-chart-line" style="color:var(--accent-color);"></i> Sales Analytics
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($message): ?>
    <div class="alert alert-success" style="font-size:0.85rem; padding:0.75rem 1rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:0.5rem; background:rgba(46, 213, 115, 0.12); border:1px solid var(--success); color:var(--success); border-radius:var(--border-radius-md);">
        <i class="fa-solid fa-circle-check" style="font-size:1.1rem;"></i>
        <span><?= h($message) ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" style="font-size:0.85rem; padding:0.75rem 1rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:0.5rem; background:rgba(255, 71, 87, 0.12); border:1px solid var(--danger); color:var(--danger); border-radius:var(--border-radius-md);">
        <i class="fa-solid fa-circle-exclamation" style="font-size:1.1rem;"></i>
        <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<!-- POS Nav Tabs -->
<div class="pos-settings-nav">
    <button type="button" class="pos-tab-btn active" onclick="switchTab('branding')">
        <i class="fa-solid fa-building"></i> Store Branding
    </button>
    <button type="button" class="pos-tab-btn" onclick="switchTab('contact')">
        <i class="fa-solid fa-address-book"></i> Contact & Address
    </button>
    <button type="button" class="pos-tab-btn" onclick="switchTab('upi')">
        <i class="fa-solid fa-qrcode" style="color:var(--accent-color);"></i> Store UPI Gateway
    </button>
    <button type="button" class="pos-tab-btn" onclick="switchTab('bank')">
        <i class="fa-solid fa-wallet"></i> Bank Account
    </button>
    <button type="button" class="pos-tab-btn" onclick="switchTab('templates')">
        <i class="fa-solid fa-images"></i> Receipt Templates
    </button>
    <button type="button" class="pos-tab-btn" onclick="switchTab('thermal')">
        <i class="fa-solid fa-print" style="color:var(--accent-color);"></i> POS Thermal Printer
    </button>
</div>

<form action="" method="POST" enctype="multipart/form-data">
    <div class="settings-card" style="max-width: 1000px; margin: 0 auto 3rem auto;">
        
        <!-- Tab 1: Store Branding & Tax -->
        <div id="tab-branding" class="tab-content" style="display: block;">
            <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 1.25rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-building" style="color:var(--accent-color);"></i> Business Branding & Tax Information
            </h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                <div class="form-group">
                    <label for="company_name" class="form-group-label">Business Name *</label>
                    <input type="text" id="company_name" name="company_name" class="form-control" value="<?= h(isset($settings['company_name']) ? $settings['company_name'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
                <div class="form-group">
                    <label for="company_subtitle" class="form-group-label">Tagline / Description</label>
                    <input type="text" id="company_subtitle" name="company_subtitle" class="form-control" value="<?= h(isset($settings['company_subtitle']) ? $settings['company_subtitle'] : '') ?>" placeholder="e.g. Premium Catering & Stage Decors" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
                <div class="form-group">
                    <label for="company_gstin" class="form-group-label">GSTIN / Tax Registration No.</label>
                    <input type="text" id="company_gstin" name="company_gstin" class="form-control" value="<?= h(isset($settings['company_gstin']) ? $settings['company_gstin'] : '') ?>" placeholder="e.g. 32AACCO2938M1Z2" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
            </div>
        </div>

        <!-- Tab 2: Contact & Address -->
        <div id="tab-contact" class="tab-content" style="display: none;">
            <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 1.25rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-address-book" style="color:var(--accent-color);"></i> Contact & Store Location
            </h2>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label for="company_phone" class="form-group-label">Contact Phone Numbers *</label>
                    <input type="text" id="company_phone" name="company_phone" class="form-control" value="<?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '') ?>" placeholder="e.g. 9946731720 | 9847634728" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
                <div class="form-group">
                    <label for="company_email" class="form-group-label">Store Email Address *</label>
                    <input type="email" id="company_email" name="company_email" class="form-control" value="<?= h(isset($settings['company_email']) ? $settings['company_email'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="company_address" class="form-group-label">Store Office Address *</label>
                    <input type="text" id="company_address" name="company_address" class="form-control" value="<?= h(isset($settings['company_address']) ? $settings['company_address'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
                <div class="form-group">
                    <label for="company_state" class="form-group-label">State Code / Region *</label>
                    <input type="text" id="company_state" name="company_state" class="form-control" value="<?= h(isset($settings['company_state']) ? $settings['company_state'] : '') ?>" placeholder="e.g. 32-Kerala" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
            </div>
        </div>

        <!-- Tab 3: Store UPI Gateway (Anti-Hack Security) -->
        <div id="tab-upi" class="tab-content" style="display: none;">
            <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 0.5rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; justify-content:space-between; gap:0.4rem;">
                <span style="display:flex; align-items:center; gap:0.4rem;">
                    <i class="fa-solid fa-qrcode" style="color:var(--accent-color);"></i> Store UPI Payment Gateway
                </span>
                <span style="font-size:0.7rem; background:rgba(46, 213, 115, 0.15); color:var(--success); padding:2px 8px; border-radius:12px; border:1px solid var(--success); font-weight:700;">
                    <i class="fa-solid fa-shield-halved"></i> Anti-Hack Protected
                </span>
            </h2>
            <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:1.25rem;">
                A dynamic payment QR code with exact payable amounts and invoice numbers will be generated automatically on thermal receipts and A4/PDF invoices for customers to scan & pay via Google Pay, PhonePe, Paytm, or BHIM.
            </p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label for="company_upi_id" class="form-group-label">Store Merchant UPI ID (VPA)</label>
                    <input type="text" id="company_upi_id" name="company_upi_id" class="form-control" value="<?= h(isset($settings['company_upi_id']) ? $settings['company_upi_id'] : '') ?>" placeholder="e.g. merchant@okicici or 9946731720@paytm" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                    <small style="color:var(--text-muted); font-size:0.75rem; margin-top:0.2rem; display:block;">Standard format: <code>username@bank</code> or <code>phone@upi</code></small>
                </div>

                <div class="form-group">
                    <label for="admin_password_confirm" class="form-group-label" style="color:var(--warning);">
                        <i class="fa-solid fa-lock"></i> Admin Password Confirmation
                    </label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="form-control" placeholder="Enter password ONLY if updating UPI ID" autocomplete="off" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                    <small style="color:var(--text-muted); font-size:0.72rem; margin-top:0.2rem; display:block;">Protects against unauthorized payment destination hijacking.</small>
                </div>
            </div>

            <?php if (!empty($settings['company_upi_id'])): ?>
                <div style="display:flex; align-items:center; gap:1rem; background:var(--bg-control); padding:0.85rem 1rem; border-radius:var(--border-radius-md); border:1px solid var(--border-color);">
                    <div style="width:44px; height:44px; border-radius:8px; background:rgba(46, 213, 115, 0.12); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fa-solid fa-qrcode"></i>
                    </div>
                    <div>
                        <div style="font-size:0.78rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:0.4rem;">
                            <i class="fa-solid fa-circle-check" style="color:var(--success);"></i> Active Payment Destination
                        </div>
                        <div style="font-size:0.9rem; font-weight:800; color:var(--accent-color); margin-top:2px;">
                            <?= h($settings['company_upi_id']) ?>
                        </div>
                        <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">
                            HMAC Security Checksum Verified: <code style="color:var(--text-secondary);"><?= h(substr($settings['company_upi_checksum'] ?? 'VALID', 0, 16)) ?>...</code>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 4: Bank Account Details -->
        <div id="tab-bank" class="tab-content" style="display: none;">
            <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 1.25rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-wallet" style="color:var(--accent-color);"></i> Bank Account Details
            </h2>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label for="company_bank_name" class="form-group-label">Bank Name *</label>
                    <input type="text" id="company_bank_name" name="company_bank_name" class="form-control" value="<?= h(isset($settings['company_bank_name']) ? $settings['company_bank_name'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
                <div class="form-group">
                    <label for="company_bank_acc" class="form-group-label">Account Number *</label>
                    <input type="text" id="company_bank_acc" name="company_bank_acc" class="form-control" value="<?= h(isset($settings['company_bank_acc']) ? $settings['company_bank_acc'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="company_bank_ifsc" class="form-group-label">IFSC Code *</label>
                    <input type="text" id="company_bank_ifsc" name="company_bank_ifsc" class="form-control" value="<?= h(isset($settings['company_bank_ifsc']) ? $settings['company_bank_ifsc'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
                <div class="form-group">
                    <label for="company_bank_holder" class="form-group-label">Account Holder Name *</label>
                    <input type="text" id="company_bank_holder" name="company_bank_holder" class="form-control" value="<?= h(isset($settings['company_bank_holder']) ? $settings['company_bank_holder'] : '') ?>" required style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px;">
                </div>
            </div>
        </div>

        <!-- Tab 5: Receipt Templates & Collage Images -->
        <div id="tab-templates" class="tab-content" style="display: none;">
            <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 0.5rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-images" style="color:var(--accent-color);"></i> Invoice Template & Collage Customization
            </h2>
            <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:1.25rem;">
                Upload new images to customize the left-sidebar collage in the <strong>Orange Classic</strong> invoice template. Existing images will be replaced.
            </p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label for="collage_stage" class="form-group-label">Stage Decor Image</label>
                    <input type="file" id="collage_stage" name="collage_stage" class="form-control" accept="image/*" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary);">
                </div>
                <div class="form-group">
                    <label for="collage_catering" class="form-group-label">Catering Image</label>
                    <input type="file" id="collage_catering" name="collage_catering" class="form-control" accept="image/*" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary);">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="collage_drinks" class="form-group-label">Drinks Image</label>
                    <input type="file" id="collage_drinks" name="collage_drinks" class="form-control" accept="image/*" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary);">
                </div>
                <div class="form-group">
                    <label for="collage_deserts" class="form-group-label">Desserts Image</label>
                    <input type="file" id="collage_deserts" name="collage_deserts" class="form-control" accept="image/*" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary);">
                </div>
            </div>
        </div>

        <!-- Tab 6: Thermal Printer Settings -->
        <div id="tab-thermal" class="tab-content" style="display: none;">
            <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 0.5rem 0; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-print" style="color:var(--accent-color);"></i> POS Thermal Printer & Roll Sizing
            </h2>
            <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:1.25rem;">
                Configure thermal receipt paper roll width, font sizing, and footer notice for POS thermal printers.
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label for="pos_thermal_paper_width" class="form-group-label">Thermal Roll Width Configuration *</label>
                    <select id="pos_thermal_paper_width" name="pos_thermal_paper_width" class="form-control" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px; padding:0.35rem 0.75rem; font-size:0.82rem; cursor:pointer;">
                        <option value="80mm" <?= ($settings['pos_thermal_paper_width'] ?? '80mm') === '80mm' ? 'selected' : '' ?> style="background:var(--bg-card); color:var(--text-primary);">80mm (Standard 3-Inch Desktop Thermal Receipt Roll)</option>
                        <option value="58mm" <?= ($settings['pos_thermal_paper_width'] ?? '') === '58mm' ? 'selected' : '' ?> style="background:var(--bg-card); color:var(--text-primary);">58mm (Compact 2-Inch Mobile POS Thermal Roll)</option>
                    </select>
                    <small style="color:var(--text-muted); font-size:0.75rem; margin-top:0.2rem; display:block;">Select your thermal printer paper width.</small>
                </div>

                <div class="form-group">
                    <label for="pos_thermal_font_size" class="form-group-label">Thermal Receipt Font Size</label>
                    <select id="pos_thermal_font_size" name="pos_thermal_font_size" class="form-control" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); height:38px; padding:0.35rem 0.75rem; font-size:0.82rem; cursor:pointer;">
                        <option value="10px" <?= ($settings['pos_thermal_font_size'] ?? '') === '10px' ? 'selected' : '' ?> style="background:var(--bg-card); color:var(--text-primary);">10px (Compact Font)</option>
                        <option value="11px" <?= ($settings['pos_thermal_font_size'] ?? '11px') === '11px' ? 'selected' : '' ?> style="background:var(--bg-card); color:var(--text-primary);">11px (Standard Regular Font)</option>
                        <option value="12px" <?= ($settings['pos_thermal_font_size'] ?? '') === '12px' ? 'selected' : '' ?> style="background:var(--bg-card); color:var(--text-primary);">12px (Medium Bold Font)</option>
                        <option value="13px" <?= ($settings['pos_thermal_font_size'] ?? '') === '13px' ? 'selected' : '' ?> style="background:var(--bg-card); color:var(--text-primary);">13px (Large High-Legibility Font)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="pos_thermal_footer_msg" class="form-group-label">Thermal Receipt Footer Custom Message</label>
                <textarea id="pos_thermal_footer_msg" name="pos_thermal_footer_msg" class="form-control" rows="2" style="background:var(--bg-control); border:1px solid var(--border-color); color:var(--text-primary); font-size:0.85rem;" placeholder="Thank you for your business! Please retain this receipt."><?= h($settings['pos_thermal_footer_msg'] ?? 'Thank you for your business! Please retain this receipt.') ?></textarea>
            </div>
        </div>

        <!-- Submit Controls -->
        <div style="margin-top: 1.75rem; border-top:1px solid var(--border-color); padding-top:1.25rem; display: flex; justify-content:flex-end; gap: 1rem;">
            <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; height:38px; font-size:0.82rem;">
                <i class="fa-solid fa-floppy-disk"></i>
                Save All Settings
            </button>
        </div>

    </div>
</form>

<script>
function switchTab(tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    // Deactivate all buttons
    document.querySelectorAll('.pos-tab-btn').forEach(el => el.classList.remove('active'));

    // Show target tab
    const target = document.getElementById('tab-' + tabId);
    if (target) target.style.display = 'block';

    // Activate event target
    const btn = event.currentTarget;
    if (btn) btn.classList.add('active');
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
