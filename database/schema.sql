-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `orange_events` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `orange_events`;

-- 1. Users & Authentication
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100),
    `role` ENUM('admin', 'staff') DEFAULT 'admin',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Stage Work Base Catalog
CREATE TABLE IF NOT EXISTS `stage_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_name` VARCHAR(150) NOT NULL,
    `default_price` DECIMAL(10,2) DEFAULT 0.00,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Catering Menu Categories
CREATE TABLE IF NOT EXISTS `menu_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(100) NOT NULL UNIQUE,
    `display_order` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Catering Dishes Catalog
CREATE TABLE IF NOT EXISTS `dishes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `dish_name` VARCHAR(150) NOT NULL,
    `description` TEXT,
    FOREIGN KEY (`category_id`) REFERENCES `menu_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Events
CREATE TABLE IF NOT EXISTS `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `client_name` VARCHAR(100) NOT NULL,
    `client_phone` VARCHAR(20) NOT NULL,
    `client_email` VARCHAR(100),
    `event_date` DATE NOT NULL,
    `event_time` TIME NOT NULL,
    `venue` VARCHAR(255) NOT NULL,
    `status` ENUM('draft', 'confirmed', 'cancelled') DEFAULT 'draft',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `unique_booking_slot` UNIQUE (`event_date`, `event_time`, `venue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Event Stage Work Mapping
CREATE TABLE IF NOT EXISTS `event_stage_work` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `stage_item_id` INT NOT NULL,
    `custom_price` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`stage_item_id`) REFERENCES `stage_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Event Catering Details
CREATE TABLE IF NOT EXISTS `event_catering` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL UNIQUE,
    `per_plate_price` DECIMAL(10,2) NOT NULL,
    `total_plates` INT NOT NULL,
    `notes` TEXT,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Event Catering Dishes
CREATE TABLE IF NOT EXISTS `event_catering_dishes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_catering_id` INT NOT NULL,
    `dish_id` INT NOT NULL,
    `plate_count` INT DEFAULT NULL,
    FOREIGN KEY (`event_catering_id`) REFERENCES `event_catering`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dish_id`) REFERENCES `dishes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Invoices
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL UNIQUE,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
    `tax_amount` DECIMAL(10,2) DEFAULT 0.00,
    `final_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `advance_received` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('draft', 'finalized', 'paid') DEFAULT 'draft',
    `template_name` VARCHAR(50) DEFAULT 'orange_classic',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- SEED INITIAL DATA
-- =========================================================================

-- 1. Default Administrator Account (password: admin123)
-- Hash generated using password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('admin', '$2y$10$tZ8.sM1z6M1m6X.937/FSuL72UuL8rUoWvD21rUp6/Qk.XwD4046K', 'admin@orangeevents.com', 'admin');

-- 2. Stage Work Base Catalog
INSERT INTO `stage_items` (`item_name`, `default_price`, `description`) VALUES
('Stage, Including Cake, Photos, Flex, Entrance Carpet & Candles', 15000.00, 'Complete stage decoration pack including entrance carpet, candles, and cake cutting table decoration.'),
('Generator & Diesel', 5000.00, 'Backup generator and diesel fuel for power stability.'),
('Sound System', 3500.00, 'Standard sound setup with microphones and background audio control.'),
('Chair Cover & Table Cover', 1200.00, 'Premium cloth seat covers and table linen for buffet table & tables.');

-- 3. Catering Menu Categories
INSERT INTO `menu_categories` (`category_name`, `display_order`) VALUES
('WELCOME DRINK', 1),
('STARTERS', 2),
('MAIN COURSE', 3),
('DESERTS', 4),
('SERVICE & WASTE MANAGEMENT', 5);

-- 4. Catering Dishes Catalog
-- Welcome Drinks (Category ID: 1)
INSERT INTO `dishes` (`category_id`, `dish_name`, `description`) VALUES
(1, 'Fresh Juices (4 types)', 'Orange, Grape, Pineapple, Watermelon juices.'),
(1, 'Mojito (3 types)', 'Mint Lime, Blue Curacao, Strawberry mojitos.');

-- Starters (Category ID: 2)
INSERT INTO `dishes` (`category_id`, `dish_name`, `description`) VALUES
(2, 'Cutlet', 'Crispy fried veg/chicken cutlets served with sauce.'),
(2, 'Appam', 'Soft and lacy rice pancakes.'),
(2, 'Idiyappam', 'Steamed string hoppers.'),
(2, 'Chicken Stew & Vegetable Stew', 'Rich coconut milk gravy stew with chicken or mixed vegetables.');

-- Main Course (Category ID: 3)
INSERT INTO `dishes` (`category_id`, `dish_name`, `description`) VALUES
(3, 'Rice', 'Premium steamed Basmati rice.'),
(3, 'Beef Fry with Coconut', 'Traditional Kerala style slow-roasted beef with sliced coconuts.'),
(3, 'Fish-Kera', 'Spicy Kerala fish curry using Kera.'),
(3, 'Moru', 'Spiced seasoned buttermilk.'),
(3, 'Aviyal', 'Mixed vegetables cooked with coconut paste and curd.'),
(3, 'Thoran', 'Dry vegetable stir-fry with shredded coconut.'),
(3, 'Chemmen Podi', 'Spicy powdered dried shrimp condiment.'),
(3, 'Pickle', 'Traditional lemon/mango pickle.'),
(3, 'Pachadi', 'Sweet and sour yogurt-based side dish.'),
(3, 'Water', 'Purified bottled drinking water.');

-- Deserts (Category ID: 4)
INSERT INTO `dishes` (`category_id`, `dish_name`, `description`) VALUES
(4, 'Ice cream', 'Vanilla or chocolate ice cream scoop served with chocolate sauce.');

-- Service & Waste Management (Category ID: 5)
INSERT INTO `dishes` (`category_id`, `dish_name`, `description`) VALUES
(5, 'Food Service Staff', 'Professional catering staff for buffet service.'),
(5, 'Waste Management & Cleanup', 'Eco-friendly disposal and site cleaning service.');
