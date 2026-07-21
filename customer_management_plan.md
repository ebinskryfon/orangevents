# Customer Management & POS Live Auto-Fetch Module - Implementation Plan

This document outlines the detailed architecture, database schema, API endpoints, POS terminal auto-fetch integration, and UI workflow for introducing **Customer Management** to Orange Events.

---

## 🎯 Objectives
1. **Dedicated Customer Directory**: Centralize client records across POS Billing, Event Bookings, and Rentals.
2. **POS Live Auto-Fetch**: Automatically populate Customer Name, Address, and GSTIN when entering/scanning a phone number in the POS checkout screen.
3. **Customer Insights & History**: Track purchase counts, total spent, order history, and client notes.
4. **Retroactive Data Sync**: Automatically backfill existing unique customer records from past `billing_orders` and `invoices`.

---

## 🗄️ 1. Database Schema (`customers` Table)

Create migration `database/migrations/23_create_customers_table.php`:

```sql
CREATE TABLE IF NOT EXISTS `customers` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `name`           VARCHAR(150)  NOT NULL,
    `phone`          VARCHAR(20)   NOT NULL UNIQUE,
    `email`          VARCHAR(150)  DEFAULT NULL,
    `address`        TEXT          DEFAULT NULL,
    `city`           VARCHAR(100)  DEFAULT NULL,
    `gstin`          VARCHAR(20)   DEFAULT NULL,
    `total_orders`   INT           NOT NULL DEFAULT 0,
    `total_spent`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `notes`          TEXT          DEFAULT NULL,
    `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 🔌 2. API Endpoints

### 2.1 `api/search-customer.php`
- **GET Request**: `?phone=9946731720` or `?query=John`
- **Response JSON**:
  ```json
  {
    "success": true,
    "found": true,
    "customer": {
      "id": 14,
      "name": "John Doe",
      "phone": "9946731720",
      "address": "Alappuzha, Kerala",
      "gstin": "",
      "total_orders": 5,
      "total_spent": 8450.00
    }
  }
  ```

### 2.2 Customer Checkout Auto-Sync
- In `admin/billing-cart.php` checkout handler:
  - When an order is finalized, automatically create or update the matching `customers` record by phone number.
  - Increment `total_orders` and add `final_amount` to `total_spent`.

---

## 💻 3. POS Live Auto-Fetch Integration

1. **Phone Number Listener (`admin/billing-cart.php` & `admin/barcode-billing.php`)**:
   - Attach a debounced `oninput` handler (300ms) to `#customerPhone`.
   - When 10 digits (or valid phone format) are entered/scanned, call `api/search-customer.php`.

2. **Auto-Fill & Visual Badge**:
   - Auto-fills `#customerName` and `#customerAddress`.
   - Renders a compact **Customer Info Badge** above the customer form:
     ```text
     👤 Returning Client: John Doe | 🛍️ 5 Orders | 💰 Total Spent: ₹8,450.00
     ```

3. **New Customer Indicator**:
   - If phone number is not found in database, displays:
     ```text
     ✨ New Customer (Will be saved upon order completion)
     ```

---

## 🖥️ 4. Admin Customer Management UI (`admin/customers.php`)

1. **Customer Directory Table**:
   - Columns: Name, Phone, Email, Address, Total Orders, Total Spent, Actions (View History / Edit / Delete).
   - Search bar & filters by name/phone or spending volume.

2. **Add / Edit Customer Modal**:
   - Form inputs: Full Name, Phone Number, Email, Address, GSTIN, Notes.

3. **Customer Profile & Purchase History Page (`admin/view-customer.php`)**:
   - Client Metrics: Total Spend, Lifetime Orders, Average Order Value, Last Visit Date.
   - Tabs:
     - 🛒 **POS Billing History**
     - 📅 **Event Catering & Decor Bookings**
     - 📦 **Rental Orders**

---

## 🛠️ Implementation Workflow Plan

- [ ] **Phase 1: Migration & Data Backfill**
  - Create `database/migrations/23_create_customers_table.php`.
  - Execute migration and backfill existing unique phone numbers from `billing_orders` and `invoices`.

- [ ] **Phase 2: Customer API Endpoint (`api/search-customer.php`)**
  - Build fast phone/name lookup API endpoint.

- [ ] **Phase 3: POS Auto-Fetch UI Integration**
  - Add debounced phone lookup listener in `admin/billing-cart.php` and `admin/barcode-billing.php`.
  - Render auto-fill fields and Returning Client Badge.

- [ ] **Phase 4: Customer Management Admin Pages**
  - Create `admin/customers.php` (Customer Directory & Add/Edit Modal).
  - Create `admin/view-customer.php` (Profile & Multi-module Purchase History).
  - Add "Customers" link to main navigation in `includes/header.php`.

---

## ❓ Action Plan Confirmation
Should we proceed with executing **Phase 1 (Database Migration & Backfill)** now?
