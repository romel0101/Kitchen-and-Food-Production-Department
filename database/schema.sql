CREATE DATABASE IF NOT EXISTS hotel_kitchen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotel_kitchen;
SET NAMES utf8mb4;

-- =============================================================================
-- USERS & ROLES
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150)  NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    role            ENUM('A','C','I') NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    first_name      VARCHAR(50)  NULL,
    middle_name     VARCHAR(50)  NULL,
    last_name       VARCHAR(50)  NULL,
    suffix          VARCHAR(10)  NULL,
    birthdate       DATE         NULL,
    address         VARCHAR(255) NULL,
    contact_number  VARCHAR(25)  NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login      TIMESTAMP    NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO users (email, password, role, first_name, middle_name, last_name, birthdate, address, contact_number) VALUES
('admin@hotel.local',     '$2b$10$MX9f2sDNKhzHmxiUjPxjkeSlf1jFdDj3NggQnqxtGCoTzYR44BIxW', 'A', 'Romel', 'Bestudio', 'Riray', '2004-09-29', 'Manila', '09123456789'),
('chef@hotel.local',      '$2b$10$MX9f2sDNKhzHmxiUjPxjkeSlf1jFdDj3NggQnqxtGCoTzYR44BIxW', 'C', 'Juan', 'Dela', 'Cruz', '1983-05-20', '456 Culinary Ave, Foodtown', '09234567890'),
('inventory@hotel.local', '$2b$10$MX9f2sDNKhzHmxiUjPxjkeSlf1jFdDj3NggQnqxtGCoTzYR44BIxW', 'I', 'Jose', 'Garcia', 'Bautista', '1988-03-10', '789 Supply Rd, Storagetown', '09345678901');

-- =============================================================================
-- MENU & RECIPES
-- =============================================================================
CREATE TABLE IF NOT EXISTS menu_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL UNIQUE,
    category   VARCHAR(50)  NOT NULL DEFAULT 'Main',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredients (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                        VARCHAR(100) NOT NULL UNIQUE,
    sku                         VARCHAR(50)  NULL UNIQUE,
    category                    VARCHAR(50)  NOT NULL DEFAULT 'General',
    unit                        VARCHAR(20)  NOT NULL DEFAULT 'kg',
    reorder_level               DECIMAL(10,3) NOT NULL DEFAULT 0,
    current_stock               DECIMAL(10,3) NOT NULL DEFAULT 0,
    storage_location            VARCHAR(80) NOT NULL DEFAULT 'General',
    is_active                   TINYINT(1) NOT NULL DEFAULT 1,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                  TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id      INT UNSIGNED NOT NULL,
    ingredient_id     INT UNSIGNED NOT NULL,
    quantity_required DECIMAL(10,3) NOT NULL,
    unit              VARCHAR(20)   NULL,      -- recipe's own unit (pcs, g, mL...); NULL = ingredient's unit
    UNIQUE KEY uq_recipe (menu_item_id, ingredient_id),
    FOREIGN KEY (menu_item_id)  REFERENCES menu_items(id)  ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- POS INTEGRATION (orders & order_items)
-- =============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number            VARCHAR(64)  NOT NULL,
    pos_order_id            VARCHAR(64)  NULL,
    order_type              VARCHAR(50)  NOT NULL,
    table_number            VARCHAR(20)  NULL,
    customer_name           VARCHAR(100) NOT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_at                TIMESTAMP NULL,
    KEY idx_queue (created_at, id),
    UNIQUE KEY uq_pos_ticket_line (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id                INT UNSIGNED NOT NULL,
    pos_order_id            VARCHAR(64)  NULL,
    menu_item_id            INT UNSIGNED NULL,
    item_name               VARCHAR(150) NOT NULL,
    current_stock           INT UNSIGNED NOT NULL DEFAULT 1,
    special_instructions    VARCHAR(255) NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- INVENTORY: BATCH RECEIPTS, USAGE, WASTE, STOCK MOVEMENTS (AUDIT LEDGER)
-- =============================================================================
CREATE TABLE IF NOT EXISTS stock_receipts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ingredient_id      INT UNSIGNED NOT NULL,
    current_stock      DECIMAL(10,3) NOT NULL,
    quantity_remaining DECIMAL(10,3) NOT NULL,
    cost_per_unit      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    expiry_date        DATE NULL,
    invoice_number     VARCHAR(50) NULL,
    supplier           VARCHAR(100) NULL,
    notes              VARCHAR(255) NULL,
    received_by        INT UNSIGNED NOT NULL,
    received_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    FOREIGN KEY (received_by)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    current_stock DECIMAL(10,3) NOT NULL,
    used_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_used_at (used_at),
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waste_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT UNSIGNED NOT NULL,
    batch_id      INT UNSIGNED NULL,   -- exact batch the waste came from (NULL for legacy/FIFO rows)
    current_stock DECIMAL(10,3) NOT NULL,
    reason        ENUM('spoiled','damaged','expired','unused','other') NOT NULL,
    notes         VARCHAR(255) NULL,
    logged_by     INT UNSIGNED NOT NULL,
    logged_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logged_at (logged_at),
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    FOREIGN KEY (batch_id)      REFERENCES stock_receipts(id) ON DELETE SET NULL,
    FOREIGN KEY (logged_by)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit ledger: one row per stock change (receive, waste, batch_edit, usage, write_off).
-- quantity_change = signed change to ingredient's global current_stock.
-- stock_after     = ingredient's current_stock after the change (NULL for back-filled history).
CREATE TABLE IF NOT EXISTS stock_movements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ingredient_id   INT UNSIGNED NOT NULL,
    batch_id        INT UNSIGNED NULL,
    movement_type   ENUM('receive','waste','batch_edit','usage','write_off') NOT NULL,
    quantity_change DECIMAL(10,3) NOT NULL,
    stock_after     DECIMAL(10,3) NULL,
    details         VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ing_time (ingredient_id, created_at, id),
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    FOREIGN KEY (batch_id)      REFERENCES stock_receipts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Back-fill history for new installs (safe to run anytime; duplicates are prevented by PK)
INSERT IGNORE INTO stock_movements (ingredient_id, batch_id, movement_type, quantity_change, details, created_by, created_at)
SELECT ingredient_id, id, 'receive', current_stock,
       CONCAT_WS(' | ', supplier, invoice_number, notes), received_by, received_at
FROM stock_receipts;

INSERT IGNORE INTO stock_movements (ingredient_id, movement_type, quantity_change, details, created_by, created_at)
SELECT ingredient_id,
       IF(notes LIKE 'Written off from expiry tracking%', 'write_off', 'waste'),
       -current_stock,
       CONCAT_WS(' | ', reason, notes), logged_by, logged_at
FROM waste_logs;

-- =============================================================================
-- FOOD SAFETY LOGS
-- =============================================================================
CREATE TABLE IF NOT EXISTS safety_logs (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_type  ENUM('temperature','sanitation') NOT NULL,
    subject   VARCHAR(100) NOT NULL,
    reading   DECIMAL(5,1) NULL,
    passed    TINYINT(1) NOT NULL,
    notes     VARCHAR(255) NULL,
    logged_by INT UNSIGNED NOT NULL,
    logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logged_at (logged_at),
    FOREIGN KEY (logged_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- USER ACTIVITY LOG
-- =============================================================================
CREATE TABLE IF NOT EXISTS user_activity_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    activity_type   VARCHAR(50)  NOT NULL,          -- login, logout, create, update, delete, view, export
    description     VARCHAR(255) NOT NULL,
    ip_address      VARCHAR(45)  NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, created_at, id),
    INDEX idx_type_time (activity_type, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. MENU ITEMS (Silogs, Sinigang, Separated Rice, Drinks, Halo-Halo)
-- =============================================================================
INSERT INTO menu_items (id, name, category) VALUES
(10, 'Tapsilog', 'Breakfast'),
(11, 'Tocilog', 'Breakfast'),
(12, 'Bangusilog', 'Breakfast'),
(13, 'Sinigang na Hipon', 'Main'),
(14, 'Sinigang na Baboy', 'Main'),
(15, 'Regular Rice', 'Sides'),
(16, 'Fried Rice (Sinangag)', 'Sides'),
(17, 'Bottled Water (500ml)', 'Beverages'),
(18, 'Bottled Water (1L)', 'Beverages'),
(19, 'Hot Barako Coffee', 'Beverages'),
(20, 'Special Filipino Halo-Halo', 'Dessert');

-- =============================================================================
-- 3. INSERT INGREDIENTS FIRST (IDs 10 to 26)
-- =============================================================================
INSERT INTO `ingredients` (`id`, `name`, `sku`, `category`, `unit`, `reorder_level`, `current_stock`, `storage_location`) VALUES
(1,  'Egg', 'ING-EGG-01', 'Proteins', 'pcs', 48.000, 91.000, 'Walk-in Refrigerator'),
(10, 'Beef Sirloin (Sliced)', 'ING-BEEF-01', 'Meat', 'kg', 5.000, 15.000, 'Freezer'),
(11, 'Cured Pork (Tocino)', 'ING-PORK-01', 'Meat', 'kg', 5.000, 20.000, 'Freezer'),
(12, 'Milkfish (Bangus)', 'ING-FISH-01', 'Seafood', 'kg', 4.000, 12.000, 'Freezer'),
(13, 'Pork Belly (Liempo)', 'ING-PORK-02', 'Meat', 'kg', 10.000, 25.000, 'Freezer'),
(14, 'Fresh Shrimp (Hipon)', 'ING-SHR-01', 'Seafood', 'kg', 5.000, 10.000, 'Freezer'),
(15, 'Sinigang Mix (Tamarind)', 'ING-SPV-01', 'Spices', 'kg', 2.000, 15.000, 'Dry Storage'),
(16, 'White Rice (Uncooked)', 'ING-RICE-01', 'Grains', 'kg', 20.000, 100.000, 'Dry Storage'),
(17, 'Cooking Oil', 'ING-OIL-01', 'Condiments', 'L', 10.000, 30.000, 'Dry Storage'),
(18, 'Garlic', 'ING-VET-01', 'Vegetables', 'kg', 3.000, 8.000, 'Dry Storage'),
(19, 'Sinigang Vegetables', 'ING-VET-02', 'Vegetables', 'kg', 5.000, 15.000, 'Refrigerator'),
(20, 'Bottled Water (500ml)', 'ING-BEV-01', 'Beverages', 'pcs', 24.000, 120.000, 'Beverage Chiller'),
(21, 'Bottled Water (1L)', 'ING-BEV-02', 'Beverages', 'pcs', 12.000, 60.000, 'Beverage Chiller'),
(22, 'Barako Coffee Beans', 'ING-BEV-03', 'Beverages', 'kg', 2.000, 10.000, 'Dry Storage'),
(23, 'Condensed Milk', 'ING-DES-01', 'Dairy', 'L', 5.000, 25.000, 'Refrigerator'),
(24, 'Evaporated Milk', 'ING-DES-02', 'Dairy', 'L', 5.000, 25.000, 'Refrigerator'),
(25, 'Halo-Halo Preserves', 'ING-DES-03', 'Pantry', 'kg', 4.000, 20.000, 'Dry Storage'),
(26, 'Ube Halaya / Ice Cream', 'ING-DES-04', 'Dairy', 'kg', 3.000, 12.000, 'Freezer');

-- =============================================================================
-- 4. RECIPE INGREDIENTS (Mapping items to exact ingredient portions)
-- =============================================================================
INSERT INTO `recipe_ingredients` (`menu_item_id`, `ingredient_id`, `quantity_required`, `unit`) VALUES
-- Tapsilog (Beef, Egg [ID 1], Garlic)
(10, 10, 0.150, 'kg'),
(10, 1,  1.000, 'pcs'),
(10, 18, 0.020, 'kg'),

-- Tocilog (Tocino, Egg [ID 1])
(11, 11, 0.150, 'kg'),
(11, 1,  1.000, 'pcs'),

-- Bangusilog (Bangus, Egg [ID 1])
(12, 12, 0.200, 'kg'),
(12, 1,  1.000, 'pcs'),

-- Sinigang na Hipon
(13, 14, 0.250, 'kg'),
(13, 15, 0.030, 'kg'),
(13, 19, 0.150, 'kg'),

-- Sinigang na Baboy
(14, 13, 0.250, 'kg'),
(14, 15, 0.030, 'kg'),
(14, 19, 0.150, 'kg'),

-- Regular Rice
(15, 16, 0.100, 'kg'),

-- Fried Rice (Sinangag)
(16, 16, 0.100, 'kg'),
(16, 18, 0.025, 'kg'),
(16, 17, 0.020, 'L'),

-- Drinks & Dessert
(17, 20, 1.000, 'pcs'),
(18, 21, 1.000, 'pcs'),
(19, 22, 0.020, 'kg'),
(20, 23, 0.030, 'L'),
(20, 24, 0.050, 'L'),
(20, 25, 0.100, 'kg'),
(20, 26, 0.050, 'kg');


-- =============================================================================
-- 5. ORDERS & ORDER ITEMS (At least 3 orders with different order types)
-- =============================================================================

-- Order #1: Dine-In
INSERT INTO `orders` (`id`, `order_number`, `pos_order_id`, `order_type`, `table_number`, `customer_name`, `created_at`, `ready_at`) VALUES
(10, '101', 'POS-2001', 'Dine-In', 'Table 3', 'Jhazlyn Reyes', NOW() - INTERVAL 30 MINUTE, NOW() - INTERVAL 15 MINUTE);

INSERT INTO `order_items` (`order_id`, `pos_order_id`, `menu_item_id`, `item_name`, `current_stock`, `special_instructions`) VALUES
(10, 'POS-2001', 10, 'Tapsilog', 2, 'Medium rare egg'),
(10, 'POS-2001', 16, 'Fried Rice (Sinangag)', 2, 'Extra garlic'),
(10, 'POS-2001', 19, 'Hot Barako Coffee', 1, 'Less sugar'),
(10, 'POS-2001', 20, 'Special Filipino Halo-Halo', 2, 'No macapuno');

-- Order #2: Room Service
INSERT INTO `orders` (`id`, `order_number`, `pos_order_id`, `order_type`, `table_number`, `customer_name`, `created_at`, `ready_at`) VALUES
(11, '102', 'POS-2002', 'Room Service', 'Room 405', 'Mr. Henderson', NOW() - INTERVAL 20 MINUTE, NULL);

INSERT INTO `order_items` (`order_id`, `pos_order_id`, `menu_item_id`, `item_name`, `current_stock`, `special_instructions`) VALUES
(11, 'POS-2002', 12, 'Bangusilog', 1, 'Vinegar dip on the side'),
(11, 'POS-2002', 13, 'Sinigang na Hipon', 1, 'Extra sour broth'),
(11, 'POS-2002', 15, 'Regular Rice', 2, NULL),
(11, 'POS-2002', 17, 'Bottled Water (500ml)', 2, 'Cold');

-- Order #3: Takeout
INSERT INTO `orders` (`id`, `order_number`, `pos_order_id`, `order_type`, `table_number`, `customer_name`, `created_at`, `ready_at`) VALUES
(12, '103', 'POS-2003', 'Takeout', NULL, 'Amina Lim', NOW() - INTERVAL 10 MINUTE, NULL);

INSERT INTO `order_items` (`order_id`, `pos_order_id`, `menu_item_id`, `item_name`, `current_stock`, `special_instructions`) VALUES
(12, 'POS-2003', 11, 'Tocilog', 3, NULL),
(12, 'POS-2003', 14, 'Sinigang na Baboy', 1, 'More Soup'),
(12, 'POS-2003', 16, 'Fried Rice (Sinangag)', 3, NULL),
(12, 'POS-2003', 18, 'Bottled Water (1L)', 1, NULL);

-- =============================================================================
-- 6. STOCK RECEIPTS (Batches mapped with 1-day, 7-day, and 30-day expiry ranges)
-- =============================================================================
INSERT INTO `stock_receipts` (`id`, `ingredient_id`, `current_stock`, `quantity_remaining`, `cost_per_unit`, `expiry_date`, `invoice_number`, `supplier`, `notes`, `received_by`, `received_at`) VALUES
-- 1-Day Range (Expiring almost immediately - High priority/Near expiry batches)
(1, 1,  91.000, 91.000, 6.00,  DATE_ADD(NOW(), INTERVAL 1 DAY), 'INV-2026-001', 'FreshPoultry Inc.', 'Urgent batch - 1 day expiry', 1, DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 485 MINUTE),
(2, 18, 8.000,  8.000,  80.00, DATE_ADD(NOW(), INTERVAL 1 DAY), 'INV-2026-002', 'Local Agri Produce', 'Fresh garlic stock - 6:30 PM entry', 1, CONCAT(CURDATE(), ' 18:30:00')),

-- 7-Day Range (Expiring within a week - Medium term batches)
(3, 10, 15.000, 15.000, 350.00, DATE_ADD(NOW(), INTERVAL 7 DAY), 'INV-2026-003', 'Prime Meat Exporters', 'Beef sirloin weekly batch', 1, DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 540 MINUTE),
(4, 11, 20.000, 20.000, 180.00, DATE_ADD(NOW(), INTERVAL 7 DAY), 'INV-2026-004', 'Zambales Meat Corp', 'Tocino batch 7-day window', 1, DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 620 MINUTE),
(5, 12, 12.000, 12.000, 160.00, DATE_ADD(NOW(), INTERVAL 7 DAY), 'INV-2026-005', 'Subic Seafoods Market', 'Fresh milkfish (bangus)', 1, DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 750 MINUTE),
(6, 13, 25.000, 25.000, 220.00, DATE_ADD(NOW(), INTERVAL 7 DAY), 'INV-2026-006', 'Prime Meat Exporters', 'Pork belly liempo batch', 1, DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 810 MINUTE),
(7, 14, 10.000, 10.000, 400.00, DATE_ADD(NOW(), INTERVAL 7 DAY), 'INV-2026-007', 'Subic Seafoods Market', 'Fresh shrimp stock', 1, DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 300 MINUTE),
(8, 19, 15.000, 15.000, 50.00,  DATE_ADD(NOW(), INTERVAL 7 DAY), 'INV-2026-008', 'Olongapo Veggie Hub', 'Sinigang mix vegetables', 1, DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 450 MINUTE),

-- 30-Day Range (Expiring in a month - Long term dry goods and bulk supplies)
(9,  15, 15.000,  15.000,  90.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-009', 'Spice World Ph', 'Tamarind sinigang powder bulk', 1, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 330 MINUTE),
(10, 16, 100.000, 100.000, 50.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-010', 'Central Grains Trading', 'White rice sacks (monthly stock)', 1, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 600 MINUTE),
(11, 17, 30.000,  30.000,  95.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-011', 'Super Grocer Supplies', 'Cooking oil gallons', 1, DATE_SUB(NOW(), INTERVAL 4 DAY) + INTERVAL 720 MINUTE),
(12, 20, 120.000, 120.000, 12.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-012', 'AquaPure Distributors', 'Bottled water 500ml', 1, DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 540 MINUTE),
(13, 21, 60.000,  60.000,  20.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-013', 'AquaPure Distributors', 'Bottled water 1L', 1, DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 890 MINUTE),
(14, 22, 10.000,  10.000,  500.00, DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-014', 'Batangas Coffee Roasters', 'Barako coffee beans', 1, DATE_SUB(NOW(), INTERVAL 10 DAY) + INTERVAL 400 MINUTE),
(15, 23, 25.000,  25.000,  75.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-015', 'Dairy Fresh Ph', 'Condensed milk cans', 1, DATE_SUB(NOW(), INTERVAL 7 DAY) + INTERVAL 550 MINUTE),
(16, 24, 25.000,  25.000,  65.00,  DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-016', 'Dairy Fresh Ph', 'Evaporated milk cans', 1, DATE_SUB(NOW(), INTERVAL 7 DAY) + INTERVAL 670 MINUTE),
(17, 25, 20.000,  20.000,  110.00, DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-017', 'Pantry Essentials Inc', 'Halo-halo preserves mix', 1, DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 480 MINUTE),
(18, 26, 12.000,  12.000,  150.00, DATE_ADD(NOW(), INTERVAL 30 DAY), 'INV-2026-018', 'Dairy Fresh Ph', 'Ube halaya and ice cream tubs', 1, DATE_SUB(NOW(), INTERVAL 4 DAY) + INTERVAL 790 MINUTE);

-- =============================================================================
-- 7. SYNC STOCK MOVEMENTS (Back-fill audit ledger for the newly created batches)
-- =============================================================================
INSERT IGNORE INTO stock_movements (ingredient_id, batch_id, movement_type, quantity_change, details, created_by, created_at)
SELECT ingredient_id, id, 'receive', current_stock,
       CONCAT_WS(' | ', supplier, invoice_number, notes), received_by, received_at
FROM stock_receipts;

-- =============================================================================
-- 8. USAGE LOGS (Tracking Top Used Ingredients for Orders & Reports)
-- =============================================================================
INSERT INTO `usage_logs` (`id`, `order_id`, `ingredient_id`, `current_stock`, `used_at`) VALUES
-- Usage for Order #101 (Tapsilog, Garlic Rice, Coffee, Halo-Halo)
(1, 10, 10, 0.150, NOW() - INTERVAL 28 MINUTE), -- Beef Sirloin
(2, 10, 1,  1.000, NOW() - INTERVAL 28 MINUTE), -- Egg
(3, 10, 18, 0.045, NOW() - INTERVAL 28 MINUTE), -- Garlic (Rice + Tapsi)
(4, 10, 16, 0.200, NOW() - INTERVAL 28 MINUTE), -- Uncooked Rice
(5, 10, 17, 0.040, NOW() - INTERVAL 28 MINUTE), -- Cooking Oil
(6, 10, 22, 0.020, NOW() - INTERVAL 28 MINUTE), -- Barako Coffee
(7, 10, 23, 0.060, NOW() - INTERVAL 28 MINUTE), -- Condensed Milk
(8, 10, 24, 0.100, NOW() - INTERVAL 28 MINUTE), -- Evaporated Milk
(9, 10, 25, 0.200, NOW() - INTERVAL 28 MINUTE), -- Halo-Halo Preserves
(10, 10, 26, 0.100, NOW() - INTERVAL 28 MINUTE), -- Ube / Ice Cream

-- Usage for Order #102 (Bangusilog, Sinigang na Hipon, Regular Rice, Water)
(11, 11, 12, 0.200, NOW() - INTERVAL 18 MINUTE), -- Milkfish (Bangus)
(12, 11, 1,  1.000, NOW() - INTERVAL 18 MINUTE), -- Egg
(13, 11, 14, 0.250, NOW() - INTERVAL 18 MINUTE), -- Fresh Shrimp
(14, 11, 15, 0.030, NOW() - INTERVAL 18 MINUTE), -- Sinigang Mix
(15, 11, 19, 0.150, NOW() - INTERVAL 18 MINUTE), -- Sinigang Vegetables
(16, 11, 16, 0.200, NOW() - INTERVAL 18 MINUTE), -- Uncooked Rice (Regular Rice)
(17, 11, 20, 1.000, NOW() - INTERVAL 18 MINUTE), -- Bottled Water 500ml

-- Usage for Order #103 (Tocilog, Sinigang na Baboy, Fried Rice, Water)
(18, 12, 11, 0.450, NOW() - INTERVAL 8 MINUTE),  -- Cured Pork (Tocino - 3 servings)
(19, 12, 1,  3.000, NOW() - INTERVAL 8 MINUTE),  -- Eggs (3 servings)
(20, 12, 13, 0.250, NOW() - INTERVAL 8 MINUTE),  -- Pork Belly (Liempo)
(21, 12, 15, 0.030, NOW() - INTERVAL 8 MINUTE),  -- Sinigang Mix
(22, 12, 19, 0.150, NOW() - INTERVAL 8 MINUTE),  -- Sinigang Vegetables
(23, 12, 16, 0.300, NOW() - INTERVAL 8 MINUTE),  -- Uncooked Rice
(24, 12, 18, 0.075, NOW() - INTERVAL 8 MINUTE),  -- Garlic
(25, 12, 17, 0.060, NOW() - INTERVAL 8 MINUTE),  -- Cooking Oil
(26, 12, 21, 1.000, NOW() - INTERVAL 8 MINUTE);  -- Bottled Water 1L