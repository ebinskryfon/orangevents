# Implementation Plan: Quick Billing & POS Module

This plan details the design, database schema, user interface, and implementation workflow for the new **Billing Module** in Orange Events. The module is designed to facilitate quick over-the-counter sales of birthday and event items, support multiple size-based variants with price inheritance, and generate formatted thermal receipts.

---

## 1. Feature Specifications & Requirements

### 1.1 Category & Product Catalogue
- **Categories**: Organize inventory primarily into categories like *Birthday Items* and *Event Items*, with flexibility to add more (e.g., Party Favors, Decorative Props).
- **Products**: Create products belonging to a category, configured with a `base_price` and status.
- **Variants (Sizes & Customized Prices)**:
  - Products can have multiple variants (e.g., Balloon Pack: Medium vs. Jumbo, Candle: Small vs. Large).
  - Each variant defines a specific **Size** (or label) and an optional **Price**.
  - **Price Inheritance**: If the variant's price is unset or set to `NULL`, it automatically inherits the product's `base_price`. If set, the variant's custom price overrides the base price.

### 1.2 Quick Billing POS (Point of Sale)
- **Product Selector Grid**:
  - Filterable by Category (Birthday, Events, All) and searchable via a live text field.
  - Interactive grid displaying product cards.
  - Clicking a product with no variants adds it directly to the cart.
  - Clicking a product with variants opens a sleek popover/modal to select the desired size before adding.
- **Interactive Shopping Cart**:
  - Displays selected items with their size, quantity, unit price, and total.
  - Real-time quantity steppers (`+` and `-`) and a quick delete action.
  - Customer info fields (Name, Phone number).
  - Discount input (supports flat discount amounts).
  - Payment method selector (Cash, UPI, Card).
  - Checkout button that processes the sale, saves the order, and redirects to the receipt view.

### 1.3 Thermal Invoice Printing
- A clean, dedicated receipt screen (`admin/billing-invoice.php`) styled specifically for 80mm/58mm POS receipt printers.
- **Print Optimization (`@media print`)**:
  - Hides sidebars, headers, and buttons during printing.
  - Automatically invokes the browser print utility (`window.print()`).
  - Formatted layout displaying company details, GSTIN, receipt number, date, customer info, structured item details, discount, grand total, and a friendly footer.

---

## 2. Database Schema Design

We will create a new migration `database/migrations/10_create_billing_tables.php` with the following schema:

### 2.1 Table Schema Definitions

```sql
-- 1. Categories Table
CREATE TABLE IF NOT EXISTS `billing_categories` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(100) NOT NULL UNIQUE,
    `display_order` INT          DEFAULT 0,
    `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Products Table
CREATE TABLE IF NOT EXISTS `billing_products` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `category_id`  INT           NOT NULL,
    `product_name` VARCHAR(150)  NOT NULL,
    `description`  TEXT,
    `base_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `billing_categories`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Product Variants Table (Size & Price override)
CREATE TABLE IF NOT EXISTS `billing_product_variants` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT          NOT NULL,
    `size`       VARCHAR(50)  NOT NULL,
    `price`      DECIMAL(10,2) DEFAULT NULL, -- NULL = inherits billing_products.base_price
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `billing_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Billing Orders Table
CREATE TABLE IF NOT EXISTS `billing_orders` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50)   NOT NULL UNIQUE,
    `customer_name`  VARCHAR(100)  DEFAULT NULL,
    `customer_phone` VARCHAR(20)   DEFAULT NULL,
    `customer_address` TEXT        DEFAULT NULL,
    `total_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount_amount`DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `final_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(20)   NOT NULL DEFAULT 'Cash', -- Cash, UPI, Card
    `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Billing Order Items Table
CREATE TABLE IF NOT EXISTS `billing_order_items` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`     INT           NOT NULL,
    `product_id`   INT           NOT NULL,
    `variant_id`   INT           DEFAULT NULL,
    `product_name` VARCHAR(150)  NOT NULL,
    `variant_size` VARCHAR(50)   DEFAULT NULL,
    `price`        DECIMAL(10,2) NOT NULL,
    `quantity`     INT           NOT NULL DEFAULT 1,
    `total_price`  DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `billing_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `billing_products`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`variant_id`) REFERENCES `billing_product_variants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 3. UI/UX & Layout Design

### 3.1 POS Quick Billing UI (`admin/billing.php`)
- A full-screen split layout optimized for quick operations.
- **Left Panel (Catalogue Grid)**:
  - Top search input + category pill filters (`All`, `Birthday Items`, `Event Items`).
  - Product list with visual feedback. Cards display product title, base price, and category badge. If variants exist, a small tag `N Variants` is shown.
- **Variant Selector Modal**:
  - A clean modal triggered when clicking a multi-variant product.
  - Displays sizes and their calculated prices (highlighting whether they inherit the base price or have custom override pricing).
- **Right Panel (Cart & Checkout)**:
  - Sticky cart displaying all added items.
  - Interactive stepper buttons for quantity adjustments.
  - Input field for discount.
  - Customer registration fields (Name, Phone number).
  - Mode of payment button selector (Cash, UPI, Card).
  - Large **Complete Order & Print** button.

### 3.2 Catalogue Settings (`admin/billing-items.php`)
- Tabs to switch between **Categories**, **Products**, and **Variants**.
- Simple forms to add/edit products, check status, assign to categories, and manage sizes/prices for each product.
- Quick checkbox `Use Product Base Price` for variants to toggle between `NULL` (price inheritance) and custom price inputs.

### 3.3 Thermal Receipt Page (`admin/billing-invoice.php`)
- Displayed in a card matching the size of standard receipt paper (80mm width).
- Centered on screen, dark mode styled but printable in black & white.
- Print style tags:
  ```css
  @media print {
      body, html {
          background: #fff;
          color: #000;
          font-family: 'Courier New', Courier, monospace;
      }
      .app-container, .sidebar, .header, .btn-actions-bar {
          display: none !important;
      }
      .thermal-receipt-container {
          width: 80mm !important;
          margin: 0;
          padding: 0;
          box-shadow: none;
          border: none;
      }
  }
  ```

---

## 4. Step-by-Step Implementation Roadmap

### Phase 1: Database Setup
1. Create `database/migrations/10_create_billing_tables.php`.
2. Seed default categories (e.g., "Birthday Items", "Event Items").
3. Seed default products and sizes to test inheritance logic.

### Phase 2: Navigation & Catalog Management
1. Add a new navigation entry in `select-module.php` and `includes/header.php`.
2. Implement `admin/billing-items.php` to handle Categories and Products.
3. Add variant support in `billing-items.php` showing size entries, custom price input, and base-price inheritance checkbox.

### Phase 3: POS Quick Billing Development
1. Design `admin/billing.php` layout (Catalogue + Live Search + Cart sidebar).
2. Write Javascript handlers for live item additions, variant selections, quantity adjustments, and final checkout.
3. Build the checkout endpoint to generate invoice numbers (`OE-B-YYYYMMDD-XXXX`) and save records.

### Phase 4: Thermal Receipts & Sales Logs
1. Create `admin/billing-invoice.php` featuring responsive layout, print styles, and automatic print dialog popups.
2. Build `admin/billing-sales.php` showing sales statistics, filter options, and reprint shortcuts.
