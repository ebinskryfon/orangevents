# POS Module Enhancements - Step-by-Step Execution Plan

This document outlines the roadmap and detailed technical specifications for introducing advanced, high-value POS features to **Orange Events Billing & POS System**.

---

## 📌 Implementation Checklist & Progress Tracker

- [x] **Phase 1: Keyboard Hotkeys & Rapid Checkout Navigation** (Completed)
- [x] **Phase 2: Park & Resume Cart (Hold Bill)** (Completed)
- [ ] **Phase 3: Quick Preset Discounts & Coupon Chips**
- [ ] **Phase 4: Split Payment Support (Multi-Tender)**
- [ ] **Phase 5: Direct WhatsApp & Digital Receipt Sharing**
- [ ] **Phase 6: Shift Petty Cash (Cash-In / Cash-Out Log)**
- [ ] **Phase 7: Return & Item Exchange Terminal**

---

## 🗺️ Feature Specifications & Technical Details

### Phase 1: Keyboard Hotkeys & Rapid Checkout Navigation
* **Goal:** Enable mouse-free, ultra-fast terminal operation for cashiers using dedicated keyboard shortcuts.
* **Key Bindings:**
  * `F2`: Jump focus to Barcode / Product Search input
  * `F4`: Park current cart order
  * `F8`: Focus Discount input
  * `F9`: Instant Cash Checkout & Print
  * `F10`: Card / UPI Checkout selector
  * `ESC`: Clear Cart / Dismiss Modals
* **UI Additions:** A subtle keyboard shortcut legend toolbar at the bottom of the POS screen (`admin/barcode-billing.php`).

---

### Phase 2: Park & Resume Cart (Hold Bill)
* **Goal:** Allow cashiers to "Park" a customer's active cart when delayed, serve another customer, and resume the parked cart later.
* **Technical Flow:**
  * Cart state saved locally in `localStorage` key `pos_parked_orders` or database session.
  * "Park Order (F4)" button added to cart action bar.
  * Parked Orders Drawer / Modal displaying: Customer name/phone, timestamp, item count, total price, and "Resume" / "Delete" buttons.
  * Badge counter on POS header showing active parked bills (e.g., `Parked (2)`).

---

### Phase 3: Quick Preset Discounts & Coupon Chips
* **Goal:** Provide instant percentage/flat discount chips for frequent promotions.
* **UI Additions:**
  * Discount preset chips above cart total: `[ 5% ]` `[ 10% ]` `[ 15% ]` `[ ₹50 Off ]` `[ ₹100 Off ]` `[ Custom ]`.
  * One-click calculation update to subtotal and tax amounts.

---

### Phase 4: Split Payment Support (Multi-Tender)
* **Goal:** Allow splitting payments across multiple tenders (e.g., ₹500 Cash + ₹1,000 UPI).
* **Database Updates:** Add `payment_breakdown` JSON or `paid_cash`, `paid_card`, `paid_upi` fields to `billing_orders` table.
* **UI Additions:**
  * Split Payment Modal in checkout drawer allowing cashiers to enter split amounts and calculate remaining balance automatically.

---

### Phase 5: Direct WhatsApp & Digital Receipt Sharing
* **Goal:** Send digital invoice receipts directly to customers via WhatsApp or SMS upon order completion.
* **UI & Logic:**
  * Post-checkout modal & `admin/view-invoice.php` button: "📲 Send Receipt via WhatsApp".
  * Auto-formatted WhatsApp message with customer greeting, summary invoice total, items breakdown, and direct link to view digital receipt.

---

### Phase 6: Shift Petty Cash (Cash-In / Cash-Out Log)
* **Goal:** Track shop expenses (e.g., ₹200 tea/snacks or shop supplies) paid directly out of the cash register drawer.
* **Database:** Table `register_cash_adjustments` (`register_id`, `type`, `amount`, `reason`, `created_by`).
* **UI Additions:** "Petty Cash / Adjust Cash" button in Register management drawer, updating expected closing cash balance automatically.

---

### Phase 7: Return & Item Exchange Terminal
* **Goal:** Process returns and exchanges directly within the POS terminal interface.
* **UI & Logic:**
  * Invoice search lookup modal in POS to pull up past bills by invoice number or scanned receipt barcode.
  * Mark items for return, adjust stock back into inventory, and issue cash refund or store credit.

---

## 🎯 Next Steps
We will execute these features one by one starting with **Phase 1: Keyboard Hotkeys & Rapid Checkout Navigation**.
