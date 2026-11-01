<?php
declare(strict_types=1);

// includes/stock_functions.php
// Units, recipe maths and menu availability. Loaded by includes/auth.php, so every page has these.
//
// UNIT RULES (the whole point of this file)
//   * Every ingredient has ONE stock unit (ingredients.unit) and its stock (current_stock) and batches are
//     always kept in that unit.
//   * A recipe line has its own unit (recipe_ingredients.unit) chosen from the ingredient's "family":
//         pcs              -> pcs only (also pack / can / bottle: each only itself)
//         kg  or  g        -> kg or g        (weight family)
//         L   or  mL       -> L or mL        (volume family)
//   * Before any comparison or deduction, both sides are converted to the family's smallest unit (g or mL),
//     so 250 g in a recipe is correctly taken out of 10 kg of stock (10 kg = 10,000 g).
//   * Quantities are compared as whole numbers (thousandths of g / mL) to avoid float errors such as 0.1+0.2.

/** unit key (lowercase) => [family, size of one unit measured in the family's smallest unit] */
const UNIT_TABLE = [
    'kg' => ['weight', 1000.0], 'g'  => ['weight', 1.0],
    'l'  => ['volume', 1000.0], 'ml' => ['volume', 1.0],
    'pcs' => ['pcs', 1.0], 'pack' => ['pack', 1.0], 'can' => ['can', 1.0], 'bottle' => ['bottle', 1.0],
];
/** Units shown in the "Unit" dropdown when creating or editing an ingredient. */
const STOCK_UNITS = ['kg', 'g', 'L', 'mL', 'pcs', 'pack', 'can', 'bottle'];

function unit_key(?string $unit): string
{
    return strtolower(trim((string) $unit));
}

/** Canonical display form: "l" -> "L", "ml" -> "mL", "KG" -> "kg". Unknown units are returned trimmed. */
function unit_label(?string $unit): string
{
    $k = unit_key($unit);
    foreach (STOCK_UNITS as $u) {
        if (strtolower($u) === $k) return $u;
    }
    return trim((string) $unit);
}

/** Units a recipe may use for an ingredient kept in $stockUnit. pcs => ['pcs'], kg => ['kg','g'], L => ['L','mL']. */
function allowed_recipe_units(?string $stockUnit): array
{
    $k = unit_key($stockUnit);
    $family = UNIT_TABLE[$k][0] ?? null;
    if ($family === 'weight') return ['kg', 'g'];
    if ($family === 'volume') return ['L', 'mL'];
    return [unit_label($stockUnit)];        // pcs, pack, can, bottle, or an unknown custom unit: only itself
}

function units_compatible(?string $a, ?string $b): bool
{
    $ka = unit_key($a); $kb = unit_key($b);
    if ($ka === $kb) return true;
    return isset(UNIT_TABLE[$ka], UNIT_TABLE[$kb]) && UNIT_TABLE[$ka][0] === UNIT_TABLE[$kb][0];
}

/**
 * Converts $qty from one unit to another. Returns null when the units are not of the same family
 * (for example pcs -> kg). An empty $from means "the ingredient's own unit".
 */
function convert_qty(float $qty, ?string $from, ?string $to): ?float
{
    $kf = unit_key($from) === '' ? unit_key($to) : unit_key($from);
    $kt = unit_key($to);
    if ($kf === $kt) return $qty;
    if (!isset(UNIT_TABLE[$kf], UNIT_TABLE[$kt]) || UNIT_TABLE[$kf][0] !== UNIT_TABLE[$kt][0]) return null;
    return $qty * UNIT_TABLE[$kf][1] / UNIT_TABLE[$kt][1];
}

/** Quantity in thousandths of the family's smallest unit (g / mL / piece), as an integer. */
function to_base_milli(mixed $qty, ?string $unit): int
{
    $factor = UNIT_TABLE[unit_key($unit)][1] ?? 1.0;
    return (int) round((float) $qty * $factor * 1000);
}

/** Converts a DECIMAL value (string, int or float) to thousandths: "0.120" -> 120. */
function stock_to_milli(mixed $value): int
{
    return (int) round((float) $value * 1000);
}

/** Shows the same amount in the unit people also think in: 2.5 kg -> "2,500 g", 1.5 L -> "1,500 mL". Null if none. */
function alt_unit_text(mixed $qty, ?string $unit): ?string
{
    $k = unit_key($unit);
    if ($k === 'kg') return number_format((float) $qty * 1000, 0, '.', ',') . ' g';
    if ($k === 'l')  return number_format((float) $qty * 1000, 0, '.', ',') . ' mL';
    return null;
}

/**
 * Full availability report for one menu item (and $portions portions of it).
 *
 * @return array{
 *   exists: bool, available: bool, has_recipe: bool, max_portions: ?int,
 *   ingredients: list<array{line_id:?int,ingredient_id:int,name:string,unit:string,recipe_unit:string,
 *                           per_portion:float,required:float,in_stock:float,short_by:float,is_short:bool,is_out:bool,unit_ok:bool}>,
 *   missing: list<string>
 * }
 *   unit        = the ingredient's stock unit        in_stock = stock, in that unit
 *   recipe_unit = the unit the recipe is written in  per_portion / required / short_by = in the recipe unit
 *   max_portions = how many portions the current stock can make (null when there is no recipe)
 *   A recipe line whose unit cannot be converted to the stock unit (unit_ok = false) counts as short.
 */
function get_menu_availability(PDO $pdo, int $menuItemId, int $portions = 1): array
{
    $portions = max(1, $portions);

    $stmt = $pdo->prepare(
        'SELECT m.id AS menu_item_id, ri.id AS line_id, i.id AS ingredient_id, i.name, i.unit,
                ri.quantity_required, ri.unit AS recipe_unit, i.current_stock AS in_stock
         FROM menu_items m
         LEFT JOIN recipe_ingredients ri ON ri.menu_item_id = m.id
         LEFT JOIN ingredients i         ON i.id = ri.ingredient_id
         WHERE m.id = :id
         ORDER BY i.name'
    );
    $stmt->execute([':id' => $menuItemId]);
    $rows = $stmt->fetchAll();

    $report = [
        'exists' => $rows !== [], 'available' => false, 'has_recipe' => false,
        'max_portions' => null, 'ingredients' => [], 'missing' => [],
    ];
    if (!$report['exists']) {
        return $report;
    }

    $maxPortions = null;
    foreach ($rows as $row) {
        if ($row['ingredient_id'] === null) {
            continue;                               // menu item without any recipe line
        }
        $stockUnit  = (string) $row['unit'];
        $recipeUnit = trim((string) ($row['recipe_unit'] ?? '')) !== '' ? (string) $row['recipe_unit'] : $stockUnit;
        $unitOk     = units_compatible($recipeUnit, $stockUnit);

        $perPortionBase = $unitOk ? to_base_milli($row['quantity_required'], $recipeUnit) : PHP_INT_MAX;
        $requiredBase   = $unitOk ? $perPortionBase * $portions : PHP_INT_MAX;
        $stockBase      = to_base_milli($row['in_stock'], $stockUnit);
        $isShort        = !$unitOk || $stockBase < $requiredBase;

        if ($unitOk && $perPortionBase > 0) {
            $canMake     = intdiv(max(0, $stockBase), $perPortionBase);
            $maxPortions = $maxPortions === null ? $canMake : min($maxPortions, $canMake);
        } elseif (!$unitOk) {
            $maxPortions = 0;
        }

        $perPortion = (float) $row['quantity_required'];
        $required   = $perPortion * $portions;
        // How far short, expressed in the recipe's own unit.
        $stockInRecipeUnit = convert_qty((float) $row['in_stock'], $stockUnit, $recipeUnit) ?? 0.0;
        $report['ingredients'][] = [
            'line_id'       => $row['line_id'] !== null ? (int) $row['line_id'] : null,
            'ingredient_id' => (int) $row['ingredient_id'],
            'name'          => (string) $row['name'],
            'unit'          => unit_label($stockUnit),
            'recipe_unit'   => unit_label($recipeUnit),
            'per_portion'   => $perPortion,
            'required'      => $required,
            'in_stock'      => (float) $row['in_stock'],
            'short_by'      => $isShort ? max(0.0, round($required - max(0.0, $stockInRecipeUnit), 3)) : 0.0,
            'is_short'      => $isShort,
            'is_out'        => $stockBase <= 0,
            'unit_ok'       => $unitOk,
        ];
        if ($isShort) {
            $report['missing'][] = (string) $row['name'];
        }
    }

    $report['has_recipe']   = $report['ingredients'] !== [];
    $report['max_portions'] = $maxPortions;
    // NO RECIPE: with no ingredient lines nothing can be short, so the dish counts as available.
    // To block dishes that have no recipe, use:  $report['available'] = $report['has_recipe'] && $report['missing'] === [];
    $report['available']    = $report['missing'] === [];
    return $report;
}

/**
 * Can the kitchen make this dish right now? True when every recipe ingredient (converted to its stock unit)
 * is in stock for $portions portions. False when anything is short or the dish does not exist.
 */
function check_menu_availability(PDO $pdo, int $menuItemId, int $portions = 1): bool
{
    return get_menu_availability($pdo, $menuItemId, $portions)['available'];
}

/**
 * Takes a finished ticket's ingredients out of stock. Call it inside a transaction, once, when the ticket is
 * marked ready. It is safe to call twice: a ticket that already has usage_logs is skipped.
 *
 * Needs are added up per ingredient across the whole ticket in the recipe's unit, converted to the stock unit,
 * and rounded once. If stock has run short (food was cooked anyway) it takes what is there and reports it.
 *
 * @return list<string> shortage messages (empty when stock covered everything)
 */
function consume_order_stock(PDO $pdo, int $orderId, ?int $userId): array
{
    $done = $pdo->prepare('SELECT COUNT(*) FROM usage_logs WHERE order_id = :o');
    $done->execute([':o' => $orderId]);
    if ((int) $done->fetchColumn() > 0) return [];

    $q = $pdo->prepare(
        'SELECT i.id, i.name, i.unit, ri.quantity_required, ri.unit AS recipe_unit, oi.current_stock AS portions
         FROM order_items oi
         JOIN recipe_ingredients ri ON ri.menu_item_id = oi.menu_item_id
         JOIN ingredients i         ON i.id = ri.ingredient_id
         WHERE oi.order_id = :o AND oi.menu_item_id IS NOT NULL'
    );
    $q->execute([':o' => $orderId]);

    $need = [];     // ingredient id => [name, stock unit, quantity needed in the stock unit]
    foreach ($q->fetchAll() as $r) {
        $stockUnit  = (string) $r['unit'];
        $recipeUnit = trim((string) ($r['recipe_unit'] ?? '')) !== '' ? (string) $r['recipe_unit'] : $stockUnit;
        $inStockUnit = convert_qty((float) $r['quantity_required'] * (int) $r['portions'], $recipeUnit, $stockUnit);
        if ($inStockUnit === null) continue;        // incompatible unit: shown as an error on the Recipes page
        $id = (int) $r['id'];
        $need[$id] = [$r['name'], $stockUnit, ($need[$id][2] ?? 0.0) + $inStockUnit];
    }

    $short = [];
    $lock  = $pdo->prepare('SELECT current_stock FROM ingredients WHERE id = :id FOR UPDATE');
    $log   = $pdo->prepare('INSERT INTO usage_logs (order_id, ingredient_id, current_stock) VALUES (:o, :i, :q)');
    foreach ($need as $ingId => [$name, $unit, $qty]) {
        $qty = round($qty, 3);
        if ($qty <= 0) continue;
        $lock->execute([':id' => $ingId]);
        $have = (float) $lock->fetchColumn();
        $take = min($qty, max(0.0, $have));
        if ($qty > $have + 0.0005) {
            $short[] = $name . ' (needed ' . num($qty) . ' ' . $unit . ', only ' . num($have) . ' was in stock)';
        }
        if ($take <= 0) continue;
        deduct_stock($pdo, $ingId, $take);
        $log->execute([':o' => $orderId, ':i' => $ingId, ':q' => $take]);
        log_stock_movement($pdo, $ingId, null, 'usage', -$take, 'Used for ticket ' . $orderId, $userId);
    }
    if ($short) {
        log_activity('order_shortage', "Order #$orderId marked ready with shortages: " . implode('; ', $short));
    } else {
        log_activity('order_ready', "Order #$orderId marked ready - all ingredients consumed from stock");
    }
    return $short;
}
