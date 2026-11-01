CREATE DATABASE IF NOT EXISTS hotel_kitchen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotel_kitchen;
SET NAMES utf8mb4;

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
    unit              VARCHAR(20)   NULL,      -- unit the recipe is written in (kg/g, L/mL, pcs...); NULL = the ingredient's own unit
    UNIQUE KEY uq_recipe (menu_item_id, ingredient_id),
    FOREIGN KEY (menu_item_id)  REFERENCES menu_items(id)  ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- POS integration: the POS shares this database and writes straight into `orders` and
-- `order_items`. There is no API. For each ticket, do both inserts in ONE transaction so the
-- kitchen never sees a ticket without its dishes:
--
--   1. INSERT INTO orders (order_number, pos_order_id, order_type, table_number, customer_name)
--        order_number  the ticket number shown in the kitchen (must be unique)
--        order_type    for example 'Dine-In', 'Room Service' or 'Takeout'
--        table_number  table or room, may be NULL
--      Do NOT set created_at or ready_at. The database stamps created_at on insert, and the
--      kitchen queue is first come, first served on it. The kitchen sets ready_at when done.
--
--   2. INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, special_instructions)
--        one row per dish; order_id is the id of the row from step 1; menu_item_id comes from
--        menu_items (it may be NULL for a dish the kitchen menu does not know).
-- ---------------------------------------------------------------------------------------------
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
    current_stock DECIMAL(10,3) NOT NULL,
    batch_id      INT UNSIGNED NULL,   -- the batch the waste came out of (NULL for old rows / FIFO waste)
    reason        ENUM('spoiled','damaged','expired','unused','other') NOT NULL,
    notes         VARCHAR(255) NULL,
    logged_by     INT UNSIGNED NOT NULL,
    logged_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logged_at (logged_at),
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    FOREIGN KEY (batch_id)      REFERENCES stock_receipts(id) ON DELETE SET NULL,
    FOREIGN KEY (logged_by)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit ledger: one row for every change to stock (receiving, waste, batch edits, kitchen usage, write-offs).
-- quantity_change = the signed change to the ingredient's global current_stock.
-- stock_after     = the ingredient's current_stock right after the change (NULL on back-filled history).
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