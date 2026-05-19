<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($event_id <= 0) {
    echo "<h3>Error: Event ID is required.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch event logistics
$stmt = $db->prepare("SELECT * FROM events WHERE id = :id");
$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch();

if (!$event) {
    echo "<h3>Error: Booking not found.</h3>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch invoice status
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

// Fetch items to calculate subtotal
$stmt_stage = $db->prepare("SELECT SUM(custom_price) as total FROM event_stage_work WHERE event_id = :id");
$stmt_stage->execute(['id' => $event_id]);
$stage_total = (float)($stmt_stage->fetch()['total'] ?? 0.0);

$stmt_catering = $db->prepare("SELECT per_plate_price * total_plates as total FROM event_catering WHERE event_id = :id");
$stmt_catering->execute(['id' => $event_id]);
$catering_total = (float)($stmt_catering->fetch()['total'] ?? 0.0);

$subtotal = $stage_total + $catering_total;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number = trim($_POST['invoice_number']);
    $template_name = trim($_POST['template_name']);
    $status = trim($_POST['status']);
    $discount = (float)$_POST['discount'];
    $tax_rate = (float)$_POST['tax_rate'];
    $advance_received = (float)$_POST['advance_received'];
    $payment_method = trim($_POST['payment_method']);
    if ($payment_method === '') {
        $payment_method = null;
    }
    
    // Custom invoice date
    $created_at = trim($_POST['created_at']);
    
    if (empty($invoice_number)) {
        $error = "Invoice number cannot be empty.";
    } else {
        try {
            $db->beginTransaction();
            
            // Check uniqueness of invoice number
            $stmt_check = $db->prepare("SELECT id FROM invoices WHERE invoice_number = :num AND id != :id");
            $stmt_check->execute(['num' => $invoice_number, 'id' => $invoice['id']]);
            if ($stmt_check->fetch()) {
                throw new Exception("Invoice number '{$invoice_number}' is already taken by another invoice.");
            }
            
            // Calculate totals
            $taxable = $subtotal - $discount;
            if ($taxable < 0) {
                $taxable = 0;
            }
            $tax_amount = $taxable * ($tax_rate / 100);
            $final_total = $taxable + $tax_amount;
            
            // Update invoice
            $stmt_update = $db->prepare("UPDATE invoices SET 
                                            invoice_number = :num,
                                            subtotal = :subtotal,
                                            discount = :discount,
                                            tax_rate = :tax_rate,
                                            tax_amount = :tax_amount,
                                            final_total = :final_total,
                                            advance_received = :advance,
                                            payment_method = :method,
                                            status = :status,
                                            template_name = :temp,
                                            created_at = :created
                                         WHERE id = :id");
            $stmt_update->execute([
                'num' => $invoice_number,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => $tax_rate,
                'tax_amount' => $tax_amount,
                'final_total' => $final_total,
                'advance' => $advance_received,
                'method' => $payment_method,
                'status' => $status,
                'temp' => $template_name,
                'created' => $created_at . ' ' . date('H:i:s', strtotime($invoice['created_at'])),
                'id' => $invoice['id']
            ]);
            
            // Sync status back to event status if appropriate
            // finalize package -> confirmed status
            if ($status === 'paid' || $status === 'finalized') {
                $db->prepare("UPDATE events SET status = 'confirmed' WHERE id = :ev_id")->execute(['ev_id' => $event_id]);
            } else {
                $db->prepare("UPDATE events SET status = 'draft' WHERE id = :ev_id")->execute(['ev_id' => $event_id]);
            }
            
            $db->commit();
            
            // Redirect to view-invoice.php with success flag
            header("Location: view-invoice.php?event_id=" . $event_id . "&success=1");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<div class="content-header" style="margin-bottom: 2rem;">
    <div>
        <div style="margin-bottom: 0.5rem;">
            <a href="view-invoice.php?event_id=<?= $event_id ?>" style="color: var(--accent-color); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-arrow-left"></i> Back to Invoice View
            </a>
        </div>
        <h1 style="font-size: 2.2rem; font-weight: 800; color: var(--text-primary);">Edit Invoice Details</h1>
        <p style="color: var(--text-secondary); margin-top: 0.25rem;">Adjust financial inputs, invoice date, template settings, and payments.</p>
    </div>
</div>

<?php if ($error): ?>
    <div style="background-color: rgba(220, 38, 38, 0.1); border: 1px solid #dc2626; color: #dc2626; padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem;"></i>
        <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 2rem; align-items: start; margin-bottom: 3rem;">
    
    <!-- Edit Form Card -->
    <div class="card" style="padding: 2rem;">
        <form action="" method="POST" id="invoiceForm" style="display: flex; flex-direction: column; gap: 1.5rem;">
            
            <!-- Section: Core Invoice Details -->
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-file-invoice" style="color: var(--accent-color);"></i>
                    Core Information
                </h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                    <div class="form-group">
                        <label for="invoice_number" class="form-label" style="font-weight: 600;">Invoice Number</label>
                        <input type="text" id="invoice_number" name="invoice_number" class="form-control" value="<?= h($invoice['invoice_number']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="created_at" class="form-label" style="font-weight: 600;">Invoice Date</label>
                        <input type="date" id="created_at" name="created_at" class="form-control" value="<?= date('Y-m-d', strtotime($invoice['created_at'])) ?>" required>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-top: 1.25rem;">
                    <div class="form-group">
                        <label for="template_name" class="form-label" style="font-weight: 600;">Template Layout</label>
                        <select name="template_name" id="template_name" class="form-control">
                            <option value="orange_classic" <?= $invoice['template_name'] == 'orange_classic' ? 'selected' : '' ?>>Orange Classic</option>
                            <option value="royal_gold" <?= $invoice['template_name'] == 'royal_gold' ? 'selected' : '' ?>>Royal Gold</option>
                            <option value="midnight_dark" <?= $invoice['template_name'] == 'midnight_dark' ? 'selected' : '' ?>>Midnight Dark</option>
                            <option value="aedan_gardens" <?= $invoice['template_name'] == 'aedan_gardens' ? 'selected' : '' ?>>Aedan Gardens (Tax Invoice)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label" style="font-weight: 600;">Invoice Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="draft" <?= $invoice['status'] == 'draft' ? 'selected' : '' ?>>Draft (Unlocked)</option>
                            <option value="finalized" <?= $invoice['status'] == 'finalized' ? 'selected' : '' ?>>Finalized (Locked)</option>
                            <option value="paid" <?= $invoice['status'] == 'paid' ? 'selected' : '' ?>>Paid (Settled)</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Section: Financial Adjustments -->
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-calculator" style="color: var(--accent-color);"></i>
                    Financial Controls
                </h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                    <div class="form-group">
                        <label for="discount" class="form-label" style="font-weight: 600;">Discount Amount (Rs.)</label>
                        <input type="number" step="0.01" id="discount" name="discount" class="form-control" value="<?= (float)$invoice['discount'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label for="tax_rate" class="form-label" style="font-weight: 600;">GST Tax Rate (%)</label>
                        <input type="number" step="0.01" id="tax_rate" name="tax_rate" class="form-control" value="<?= (float)$invoice['tax_rate'] ?>" min="0" max="100">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-top: 1.25rem;">
                    <div class="form-group">
                        <label for="advance_received" class="form-label" style="font-weight: 600;">Amount Received / Paid (Rs.)</label>
                        <input type="number" step="0.01" id="advance_received" name="advance_received" class="form-control" value="<?= (float)$invoice['advance_received'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label for="payment_method" class="form-label" style="font-weight: 600;">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            <option value="">-- Select Payment Method --</option>
                            <option value="CASH" <?= $invoice['payment_method'] == 'CASH' ? 'selected' : '' ?>>Cash</option>
                            <option value="BANK TRANSFER" <?= $invoice['payment_method'] == 'BANK TRANSFER' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="UPI" <?= $invoice['payment_method'] == 'UPI' ? 'selected' : '' ?>>UPI (GPay/PhonePe)</option>
                            <option value="CARD" <?= $invoice['payment_method'] == 'CARD' ? 'selected' : '' ?>>Debit/Credit Card</option>
                            <option value="CHEQUE" <?= $invoice['payment_method'] == 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 1rem; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 1.5rem; margin-top: 1rem;">
                <a href="view-invoice.php?event_id=<?= $event_id ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
    
    <!-- Calculations Calculator Summary Panel -->
    <div class="card" style="padding: 2rem; background: var(--bg-body); border: 1px solid var(--border-color);">
        <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
            <i class="fa-solid fa-receipt" style="color: var(--accent-color);"></i>
            Calculation Summary
        </h3>
        
        <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.95rem;">
            <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                <span>Event Base Subtotal:</span>
                <span style="font-weight: 600; color: var(--text-primary);">Rs. <?= number_format($subtotal, 2) ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                <span>Discount Applied:</span>
                <span id="summaryDiscount" style="font-weight: 600; color: #dc2626;">-Rs. 0.00</span>
            </div>
            <div style="display: flex; justify-content: space-between; color: var(--text-secondary); border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                <span>Taxable Value:</span>
                <span id="summaryTaxable" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00</span>
            </div>
            <div style="display: flex; justify-content: space-between; color: var(--text-secondary);">
                <span>GST Tax (Rate%):</span>
                <span id="summaryTax" style="font-weight: 600; color: var(--text-primary);">Rs. 0.00 (0%)</span>
            </div>
            <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.75rem; font-size: 1.1rem;">
                <strong style="color: var(--text-primary);">Final Invoice Total:</strong>
                <strong id="summaryGrandTotal" style="color: var(--accent-color);">Rs. 0.00</strong>
            </div>
            <div style="display: flex; justify-content: space-between; color: var(--text-secondary); border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                <span>Amount Already Paid:</span>
                <span id="summaryPaid" style="font-weight: 600; color: #16a34a;">Rs. 0.00</span>
            </div>
            <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.75rem; font-size: 1.15rem; background: rgba(220, 38, 38, 0.05); padding: 0.75rem; border-radius: var(--border-radius-md); margin-top: 0.5rem;">
                <strong style="color: #dc2626;">Balance Due:</strong>
                <strong id="summaryBalance" style="color: #dc2626;">Rs. 0.00</strong>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;">
            <i class="fa-solid fa-circle-info" style="color: var(--accent-color); margin-right: 0.25rem;"></i>
            Note: Changes saved here will instantly synchronize totals on templates and booking reports.
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const subtotal = <?= (float)$subtotal ?>;
    
    const discountInput = document.getElementById('discount');
    const taxRateInput = document.getElementById('tax_rate');
    const paidInput = document.getElementById('advance_received');
    
    const summaryDiscount = document.getElementById('summaryDiscount');
    const summaryTaxable = document.getElementById('summaryTaxable');
    const summaryTax = document.getElementById('summaryTax');
    const summaryGrandTotal = document.getElementById('summaryGrandTotal');
    const summaryPaid = document.getElementById('summaryPaid');
    const summaryBalance = document.getElementById('summaryBalance');
    
    function calculate() {
        const discount = parseFloat(discountInput.value) || 0;
        const taxRate = parseFloat(taxRateInput.value) || 0;
        const paid = parseFloat(paidInput.value) || 0;
        
        const taxable = Math.max(0, subtotal - discount);
        const taxAmount = taxable * (taxRate / 100);
        const grandTotal = taxable + taxAmount;
        const balance = grandTotal - paid;
        
        summaryDiscount.textContent = '-Rs. ' + discount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryTaxable.textContent = 'Rs. ' + taxable.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryTax.textContent = 'Rs. ' + taxAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (' + taxRate + '%)';
        summaryGrandTotal.textContent = 'Rs. ' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryPaid.textContent = 'Rs. ' + paid.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryBalance.textContent = 'Rs. ' + balance.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    discountInput.addEventListener('input', calculate);
    taxRateInput.addEventListener('input', calculate);
    paidInput.addEventListener('input', calculate);
    
    // Initial run
    calculate();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
