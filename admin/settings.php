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
        'company_bank_holder'
    ];
    
    try {
        $db->beginTransaction();
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
                        $message = 'Settings and images updated successfully! (Press Ctrl+F5 if you don\'t see the new images immediately).';
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
        $error = 'Failed to update settings: ' . $e->getMessage();
    }
}

$settings = get_settings();
?>

<div class="content-header" style="margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 2.2rem; font-weight: 800; color: var(--text-primary);">Business Settings</h1>
        <p style="color: var(--text-secondary); margin-top: 0.25rem;">Configure your business details displayed on invoice templates</p>
    </div>
</div>

<?php if ($message): ?>
    <div style="background-color: rgba(22, 163, 74, 0.1); border: 1px solid #16a34a; color: #16a34a; padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i>
        <span><?= h($message) ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background-color: rgba(220, 38, 38, 0.1); border: 1px solid #dc2626; color: #dc2626; padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem;"></i>
        <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 1000px; margin: 0 auto 3rem auto;">
    <form action="" method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 1.5rem;">
        
        <!-- Section: Company Branding -->
        <div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-building" style="color: var(--accent-color);"></i>
                Company Branding
            </h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="company_name" class="form-label">Business Name</label>
                    <input type="text" id="company_name" name="company_name" class="form-control" value="<?= h(isset($settings['company_name']) ? $settings['company_name'] : '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="company_subtitle" class="form-label">Sub-title / Description</label>
                    <input type="text" id="company_subtitle" name="company_subtitle" class="form-control" value="<?= h(isset($settings['company_subtitle']) ? $settings['company_subtitle'] : '') ?>" placeholder="e.g. Premium Catering & Stage Decors">
                </div>
                <div class="form-group">
                    <label for="company_gstin" class="form-label">GSTIN / Tax Number</label>
                    <input type="text" id="company_gstin" name="company_gstin" class="form-control" value="<?= h(isset($settings['company_gstin']) ? $settings['company_gstin'] : '') ?>" placeholder="e.g. 32AACCO2938M1Z2">
                </div>
            </div>
        </div>

        <!-- Section: Contact Details -->
        <div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-address-book" style="color: var(--accent-color);"></i>
                Contact & Location Details
            </h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label for="company_phone" class="form-label">Contact Numbers</label>
                    <input type="text" id="company_phone" name="company_phone" class="form-control" value="<?= h(isset($settings['company_phone']) ? $settings['company_phone'] : '') ?>" placeholder="e.g. 9946731720 | 9847634728" required>
                </div>
                <div class="form-group">
                    <label for="company_email" class="form-label">Email Address</label>
                    <input type="email" id="company_email" name="company_email" class="form-control" value="<?= h(isset($settings['company_email']) ? $settings['company_email'] : '') ?>" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="company_address" class="form-label">Office Address</label>
                    <input type="text" id="company_address" name="company_address" class="form-control" value="<?= h(isset($settings['company_address']) ? $settings['company_address'] : '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="company_state" class="form-label">State Code / Region</label>
                    <input type="text" id="company_state" name="company_state" class="form-control" value="<?= h(isset($settings['company_state']) ? $settings['company_state'] : '') ?>" placeholder="e.g. 32-Kerala" required>
                </div>
            </div>
        </div>

        <!-- Section: Bank Account Details -->
        <div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-wallet" style="color: var(--accent-color);"></i>
                Bank Account Details
            </h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label for="company_bank_name" class="form-label">Bank Name</label>
                    <input type="text" id="company_bank_name" name="company_bank_name" class="form-control" value="<?= h(isset($settings['company_bank_name']) ? $settings['company_bank_name'] : '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="company_bank_acc" class="form-label">Account Number</label>
                    <input type="text" id="company_bank_acc" name="company_bank_acc" class="form-control" value="<?= h(isset($settings['company_bank_acc']) ? $settings['company_bank_acc'] : '') ?>" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="company_bank_ifsc" class="form-label">IFSC Code</label>
                    <input type="text" id="company_bank_ifsc" name="company_bank_ifsc" class="form-control" value="<?= h(isset($settings['company_bank_ifsc']) ? $settings['company_bank_ifsc'] : '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="company_bank_holder" class="form-label">Account Holder Name</label>
                    <input type="text" id="company_bank_holder" name="company_bank_holder" class="form-control" value="<?= h(isset($settings['company_bank_holder']) ? $settings['company_bank_holder'] : '') ?>" required>
                </div>
            </div>
        </div>

        <!-- Section: Orange Classic Collage Images -->
        <div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-images" style="color: var(--accent-color);"></i>
                Orange Classic Template Images
            </h3>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">Upload new images to replace the left-sidebar collage in the <strong>Orange Classic</strong> invoice template. Note: Existing images will be permanently overwritten. Please ensure your images are roughly square.</p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label for="collage_stage" class="form-label">Stage Decor Image</label>
                    <input type="file" id="collage_stage" name="collage_stage" class="form-control" accept="image/*">
                </div>
                <div class="form-group">
                    <label for="collage_catering" class="form-label">Catering Image</label>
                    <input type="file" id="collage_catering" name="collage_catering" class="form-control" accept="image/*">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="collage_drinks" class="form-label">Drinks Image</label>
                    <input type="file" id="collage_drinks" name="collage_drinks" class="form-control" accept="image/*">
                </div>
                <div class="form-group">
                    <label for="collage_deserts" class="form-label">Desserts Image</label>
                    <input type="file" id="collage_deserts" name="collage_deserts" class="form-control" accept="image/*">
                </div>
            </div>
        </div>


        <div style="margin-top: 1rem; display: flex; gap: 1rem;">
            <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-floppy-disk"></i>
                Save Settings Changes
            </button>
        </div>

    </form>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
