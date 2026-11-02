CREATE DATABASE IF NOT EXISTS hotel_kitchen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotel_kitchen;
SET NAMES utf8mb4;

-- =============================================================================
-- USERS & ROLES
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('A','C','I') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO users (username, password, role) VALUES
('admin',     '$2b$10$MX9f2sDNKhzHmxiUjPxjkeSlf1jFdDj3NggQnqxtGCoTzYR44BIxW', 'A'),
('chef',      '$2b$10$MX9f2sDNKhzHmxiUjPxjkeSlf1jFdDj3NggQnqxtGCoTzYR44BIxW', 'C'),
('inventory', '$2b$10$MX9f2sDNKhzHmxiUjPxjkeSlf1jFdDj3NggQnqxtGCoTzYR44BIxW', 'I');

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
    expiry_alert_days           INT UNSIGNED NOT NULL DEFAULT 3,
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