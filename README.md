# Kitchen & Food Production Department (KAFPD)

PHP 8.1+ (PDO) + MySQL 5.7+/MariaDB 10.4+ (utf8mb4) + Tailwind (CDN). No API: the POS shares this database.

## Install (new server)
1. Create the database and tables: import `database/schema.sql` (phpMyAdmin > Import).
2. Upload everything to your web root (or a subfolder). Apache needs `mod_rewrite` and `AllowOverride All`
   so `.htaccess` works; it blocks `config/`, `includes/`, `sections/` and `database/` from the browser.
3. Database login: edit `config/db.php`, or set environment variables `DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USER`, `DB_PASS`. Timezone: `APP_TZ` (default `Asia/Manila`).
4. Open `login.php`. Seed accounts `admin`, `chef`, `inventory` all use password `password123`.
   **Sign in as admin > Users and change all three passwords before going live.**

## Upgrade an existing database
Run once each, in this order, only if you have not run them yet:
1. `database/migration_batch_inventory.sql`  (batch waste + stock movement ledger)
2. `database/migration_recipe_units.sql`     (recipe units)

## How stock works
* Each ingredient has one stock unit. Stock and batches are kept in it.
* Recipes: pcs ingredients use pcs; kg ingredients use kg or g; L ingredients use L or mL. Everything is converted
  before it is compared or deducted (250 g comes off 10 kg as 0.25 kg).
* Stock is taken out when the kitchen presses **Mark Ready** on a ticket, whether the ticket came from the POS
  (inserted straight into `orders` / `order_items`) or from the test form. If stock is short at that moment, the
  ticket is still marked ready, what is there is deducted (never below 0) and a warning is shown.
* Receiving, batch waste and batch edits: Inventory > Receive / Batches. Every change is written to `stock_movements`.
* The unit of an ingredient is locked once it has stock, batches, history or recipes.

## POS integration (shared database, no API)
Insert one row in `orders` (unique `order_number`; leave `created_at` and `ready_at` alone) and one row per dish in
`order_items` (`order_id`, `menu_item_id`, `item_name`, `current_stock` = number of portions) in ONE transaction.
The queue is first come, first served on `created_at`.
