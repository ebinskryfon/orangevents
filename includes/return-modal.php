<!-- POS Return & Item Exchange Modal -->
<div id="posReturnModal" class="modal" tabindex="-1" style="z-index:1050;">
    <div class="modal-content" style="max-width:680px; padding:1.25rem;">
            
            <!-- Modal Header -->
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:1rem;">
                <h3 style="font-size:1.1rem; font-weight:800; margin:0; display:flex; align-items:center; gap:0.5rem; color:var(--text-primary);">
                    <i class="fa-solid fa-rotate-left" style="color:var(--accent-color);"></i>
                    Process Item Return & Exchange
                </h3>
                <button type="button" onclick="closePosReturnModal()" style="background:none; border:none; color:var(--text-secondary); font-size:1.2rem; cursor:pointer; padding:0 0.4rem;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Search Invoice Input -->
            <div style="margin-bottom:1rem;">
                <label style="font-size:0.75rem; font-weight:700; color:var(--text-secondary); margin-bottom:0.25rem; display:block;">
                    Scan Receipt Barcode or Enter Invoice / Phone Number
                </label>
                <div style="display:flex; gap:0.4rem;">
                    <input type="text" id="returnInvoiceSearchInput" class="form-control" placeholder="e.g. OE-B-20260721-0001 or Phone No." style="height:36px; font-size:0.85rem;" onkeypress="if(event.key === 'Enter'){ event.preventDefault(); searchReturnInvoice(); }">
                    <button type="button" onclick="searchReturnInvoice()" class="btn btn-primary" style="height:36px; font-size:0.8rem; padding:0 1rem; display:inline-flex; align-items:center; gap:0.4rem;">
                        <i class="fa-solid fa-magnifying-glass"></i> Find Bill
                    </button>
                </div>
            </div>

            <div id="returnModalAlert" class="alert" style="display:none; font-size:0.8rem; padding:0.5rem 0.75rem; margin-bottom:1rem;"></div>

            <!-- Invoice Data Container -->
            <div id="returnOrderDetailsArea" style="display:none;">
                <!-- Order Meta Card -->
                <div style="background:var(--bg-control); border:1px solid var(--border-color); border-radius:var(--border-radius-md); padding:0.6rem 0.8rem; margin-bottom:0.8rem; font-size:0.8rem; display:flex; justify-content:space-between; flex-wrap:wrap; gap:0.4rem;">
                    <div>
                        <span style="color:var(--text-secondary);">Invoice:</span>
                        <strong id="retInvoiceNum" style="color:var(--accent-color);"></strong>
                    </div>
                    <div>
                        <span style="color:var(--text-secondary);">Customer:</span>
                        <strong id="retCustomerName"></strong>
                    </div>
                    <div>
                        <span style="color:var(--text-secondary);">Date:</span>
                        <span id="retOrderDate"></span>
                    </div>
                    <div>
                        <span style="color:var(--text-secondary);">Original Paid:</span>
                        <strong id="retOriginalPaid"></strong>
                    </div>
                </div>

                <!-- Items Table -->
                <div style="max-height:240px; overflow-y:auto; border:1px solid var(--border-color); border-radius:var(--border-radius-md); margin-bottom:1rem;">
                    <table class="table" style="width:100%; margin:0; font-size:0.75rem;">
                        <thead style="background:var(--bg-control); position:sticky; top:0; border-bottom:1px solid var(--border-color); z-index:1;">
                            <tr>
                                <th style="padding:0.4rem 0.6rem;">Item Name</th>
                                <th style="padding:0.4rem 0.6rem; text-align:right;">Price</th>
                                <th style="padding:0.4rem 0.6rem; text-align:center;">Purchased</th>
                                <th style="padding:0.4rem 0.6rem; text-align:center;">Returned</th>
                                <th style="padding:0.4rem 0.6rem; text-align:center;">Return Qty</th>
                                <th style="padding:0.4rem 0.6rem; text-align:right;">Refund Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="returnItemsTableBody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>

                <!-- Return Options Grid -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem; margin-bottom:1rem;">
                    <div>
                        <label style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:0.2rem; display:block; font-weight:600;">
                            Mode of Refund
                        </label>
                        <select id="returnRefundMethodSelect" class="form-control" style="height:32px; font-size:0.8rem; padding:0.2rem 0.5rem;">
                            <option value="Cash">Cash Refund (Drawer)</option>
                            <option value="Store Credit">Store Credit / Coupon</option>
                            <option value="Original Tender">Original Tender / Card / UPI</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:0.2rem; display:block; font-weight:600;">
                            Reason for Return
                        </label>
                        <select id="returnReasonSelect" class="form-control" style="height:32px; font-size:0.8rem; padding:0.2rem 0.5rem;">
                            <option value="Damaged / Defective Item">Damaged / Defective Item</option>
                            <option value="Wrong Size / Variant">Wrong Size / Variant</option>
                            <option value="Customer Changed Mind">Customer Changed Mind</option>
                            <option value="Exchange for Other Item">Exchange for Other Item</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <!-- Footer Action & Grand Refund Total -->
                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-color); padding-top:0.75rem;">
                    <div style="font-size:0.9rem; font-weight:700; color:var(--text-primary);">
                        Total Refund Amount: 
                        <span id="retGrandRefundTotal" style="color:var(--danger); font-size:1.1rem; font-weight:800;">₹0.00</span>
                    </div>

                    <div style="display:flex; gap:0.4rem;">
                        <button type="button" onclick="closePosReturnModal()" class="btn btn-secondary" style="height:34px; font-size:0.75rem;">
                            Cancel
                        </button>
                        <button type="button" id="submitReturnBtn" onclick="submitPosReturn()" class="btn btn-success" style="height:34px; font-size:0.8rem; font-weight:700; padding:0 1rem; display:inline-flex; align-items:center; gap:0.4rem;">
                            <i class="fa-solid fa-check-circle"></i> Complete Return & Print Voucher
                        </button>
                    </div>
                </div>
    </div>
</div>

<script>
let currentLoadedReturnOrder = null;

function openPosReturnModal(initialQuery = '') {
    const modal = document.getElementById('posReturnModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        const input = document.getElementById('returnInvoiceSearchInput');
        if (input) {
            if (initialQuery) input.value = initialQuery;
            setTimeout(() => input.focus(), 100);
            if (initialQuery) searchReturnInvoice();
        }
    }
}

function closePosReturnModal() {
    const modal = document.getElementById('posReturnModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function searchReturnInvoice() {
    const query = document.getElementById('returnInvoiceSearchInput').value.trim();
    const alertBox = document.getElementById('returnModalAlert');
    const orderArea = document.getElementById('returnOrderDetailsArea');

    if (!query) {
        showReturnAlert('Please enter an invoice or phone number to search.', 'warning');
        return;
    }

    alertBox.style.display = 'none';
    orderArea.style.display = 'none';

    fetch(`../api/process-return.php?action=lookup&query=${encodeURIComponent(query)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showReturnAlert(data.error || 'Invoice not found.', 'danger');
                return;
            }
            renderReturnOrderData(data);
        })
        .catch(err => {
            // Fallback try relative root path
            fetch(`api/process-return.php?action=lookup&query=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        showReturnAlert(data.error || 'Invoice not found.', 'danger');
                        return;
                    }
                    renderReturnOrderData(data);
                })
                .catch(e => {
                    showReturnAlert('Failed to connect to API endpoint: ' + e.message, 'danger');
                });
        });
}

function renderReturnOrderData(data) {
    currentLoadedReturnOrder = data;
    const order = data.order;
    const items = data.items;

    document.getElementById('retInvoiceNum').textContent = order.invoice_number;
    document.getElementById('retCustomerName').textContent = order.customer_name;
    document.getElementById('retOrderDate').textContent = order.created_at;
    document.getElementById('retOriginalPaid').textContent = '₹' + order.final_amount.toFixed(2);

    const tbody = document.getElementById('returnItemsTableBody');
    tbody.innerHTML = '';

    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center" style="padding:1rem;">No items found in order.</td></tr>';
    } else {
        items.forEach(item => {
            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid var(--border-color)';
            
            const variantText = item.variant_size ? ` <small style="color:var(--text-secondary);">(${escapeHtml(item.variant_size)})</small>` : '';
            const maxReturn = item.available_for_return;

            tr.innerHTML = `
                <td style="padding:0.4rem 0.6rem; font-weight:600;">
                    ${escapeHtml(item.product_name)}${variantText}
                </td>
                <td style="padding:0.4rem 0.6rem; text-align:right;">₹${item.price.toFixed(2)}</td>
                <td style="padding:0.4rem 0.6rem; text-align:center;">${item.purchased_qty}</td>
                <td style="padding:0.4rem 0.6rem; text-align:center; color:var(--danger);">${item.returned_qty}</td>
                <td style="padding:0.4rem 0.6rem; text-align:center;">
                    ${maxReturn > 0 ? `
                        <div style="display:inline-flex; align-items:center; gap:0.2rem;">
                            <button type="button" onclick="adjustReturnQty(${item.order_item_id}, -1)" class="btn btn-secondary" style="padding:0 6px; height:22px; font-size:0.7rem;">-</button>
                            <input type="number" id="retQty_${item.order_item_id}" value="0" min="0" max="${maxReturn}" 
                                style="width:45px; height:22px; text-align:center; font-size:0.75rem; padding:0;" 
                                oninput="validateReturnQty(${item.order_item_id}, ${maxReturn}, ${item.price})">
                            <button type="button" onclick="adjustReturnQty(${item.order_item_id}, 1)" class="btn btn-secondary" style="padding:0 6px; height:22px; font-size:0.7rem;">+</button>
                        </div>
                    ` : '<span style="color:var(--text-muted); font-size:0.7rem;">Fully Returned</span>'}
                </td>
                <td style="padding:0.4rem 0.6rem; text-align:right; font-weight:700; color:var(--danger);" id="retSubtotal_${item.order_item_id}">
                    ₹0.00
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('returnOrderDetailsArea').style.display = 'block';
    recalculateReturnGrandTotal();
}

function adjustReturnQty(itemId, delta) {
    const input = document.getElementById(`retQty_${itemId}`);
    if (!input) return;
    let val = parseInt(input.value) || 0;
    const max = parseInt(input.getAttribute('max')) || 0;
    val += delta;
    if (val < 0) val = 0;
    if (val > max) val = max;
    input.value = val;

    const item = currentLoadedReturnOrder.items.find(i => i.order_item_id === itemId);
    if (item) {
        const subtotal = val * item.price;
        document.getElementById(`retSubtotal_${itemId}`).textContent = '₹' + subtotal.toFixed(2);
    }
    recalculateReturnGrandTotal();
}

function validateReturnQty(itemId, max, price) {
    const input = document.getElementById(`retQty_${itemId}`);
    if (!input) return;
    let val = parseInt(input.value) || 0;
    if (val < 0) val = 0;
    if (val > max) val = max;
    input.value = val;

    const subtotal = val * price;
    document.getElementById(`retSubtotal_${itemId}`).textContent = '₹' + subtotal.toFixed(2);
    recalculateReturnGrandTotal();
}

function recalculateReturnGrandTotal() {
    let grand = 0;
    if (currentLoadedReturnOrder && currentLoadedReturnOrder.items) {
        currentLoadedReturnOrder.items.forEach(item => {
            const input = document.getElementById(`retQty_${item.order_item_id}`);
            if (input) {
                const qty = parseInt(input.value) || 0;
                grand += (qty * item.price);
            }
        });
    }
    document.getElementById('retGrandRefundTotal').textContent = '₹' + grand.toFixed(2);
}

function submitPosReturn() {
    if (!currentLoadedReturnOrder) return;

    const returnItemsPayload = [];
    currentLoadedReturnOrder.items.forEach(item => {
        const input = document.getElementById(`retQty_${item.order_item_id}`);
        if (input) {
            const qty = parseInt(input.value) || 0;
            if (qty > 0) {
                returnItemsPayload.push({
                    order_item_id: item.order_item_id,
                    quantity: qty,
                    unit_price: item.price
                });
            }
        }
    });

    if (returnItemsPayload.length === 0) {
        showReturnAlert('Please select at least 1 item to return.', 'warning');
        return;
    }

    const method = document.getElementById('returnRefundMethodSelect').value;
    const reason = document.getElementById('returnReasonSelect').value;
    const submitBtn = document.getElementById('submitReturnBtn');

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

    const payload = {
        action: 'process',
        order_id: currentLoadedReturnOrder.order.id,
        refund_method: method,
        reason: reason,
        items: returnItemsPayload
    };

    const apiPath = window.location.pathname.includes('/admin/') ? '../api/process-return.php' : 'api/process-return.php';

    fetch(apiPath, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Return & Print Voucher';

        if (!data.success) {
            showReturnAlert(data.error || 'Failed to process return.', 'danger');
            return;
        }

        closePosReturnModal();
        alert(`Return completed successfully!\nReturn Voucher #${data.return_number}\nTotal Refunded: ₹${data.refund_amount.toFixed(2)}`);
        
        // Open Return Receipt in new window or redirect
        const receiptPath = window.location.pathname.includes('/admin/') ? `return-receipt.php?id=${data.return_id}` : `admin/return-receipt.php?id=${data.return_id}`;
        window.location.href = receiptPath;
    })
    .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Return & Print Voucher';
        showReturnAlert('Network error: ' + err.message, 'danger');
    });
}

function showReturnAlert(msg, type) {
    const box = document.getElementById('returnModalAlert');
    if (box) {
        box.className = `alert alert-${type}`;
        box.textContent = msg;
        box.style.display = 'block';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Global Keyboard Shortcut: F7 for Return Terminal
document.addEventListener('keydown', (e) => {
    if (e.key === 'F7') {
        e.preventDefault();
        openPosReturnModal();
    }
});
</script>
