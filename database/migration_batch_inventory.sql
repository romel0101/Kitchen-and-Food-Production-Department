-- Run ONCE on an existing database (phpMyAdmin > SQL tab). New installs use schema.sql instead.

-- 1. Waste can now point at the exact batch it came out of.
ALTER TABLE waste_logs
    ADD COLUMN batch_id INT UNSIGNED NULL AFTER ingredient_id,
    ADD CONSTRAINT fk_waste_batch FOREIGN KEY (batch_id) REFERENCES stock_receipts(id) ON DELETE SET NULL;

-- 2. Audit ledger behind "Recent Stock Movements".
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

-- 3. Back-fill the history that already exists (stock_after is unknown for these rows).
INSERT INTO stock_movements (ingredient_id, batch_id, movement_type, quantity_change, details, created_by, created_at)
SELECT ingredient_id, id, 'receive', current_stock, CONCAT_WS(' | ', supplier, invoice_number, notes), received_by, received_at
FROM stock_receipts;

INSERT INTO stock_movements (ingredient_id, movement_type, quantity_change, details, created_by, created_at)
SELECT ingredient_id, IF(notes LIKE 'Written off from expiry tracking%', 'write_off', 'waste'), -current_stock,
       CONCAT_WS(' | ', reason, notes), logged_by, logged_at
FROM waste_logs;
