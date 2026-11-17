<?php
// sections/recipes.php - menu items, their recipes (per-portion quantities) and whether stock can make them.
//
// Access: 'recipes' lets a role OPEN this page (read only); 'recipes_edit' lets it add / edit / delete (see PERMISSIONS in
// includes/auth.php). The chef reads, the administrator edits. The server checks this on every POST, so hiding the
// buttons below is a convenience, not the protection.
//
// Units come from includes/stock_functions.php: a recipe line is written in a unit its ingredient allows
// (stocked in pcs -> pcs only, kg -> kg or g, L -> L or mL). get_menu_availability() converts everything
// to g / mL / pieces before comparing, so "250 g" against "5 kg" in stock is exact.
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_perm('recipes');
$canEdit = can('recipes_edit');

// Where to return after a form post: back to the same dish, still open (and the edited line briefly highlighted).
function recipes_url(int $openId = 0, string $show = '', int|string $highlight = 0): string {
    $url = 'index?page=recipes' . ($show !== '' ? '&show=' . rawurlencode($show) : '');
    if ($openId) $url .= '&open=' . $openId . ($highlight ? '&hl=' . preg_replace('/[^0-9,]/', '', (string) $highlight) : '') . '#item-' . $openId;
    return $url;
}

/** The canonical spelling of $unit if an ingredient stocked in $stockUnit may use it in a recipe, else null. Empty = the stock unit. */
function recipes_match_unit(string $stockUnit, string $unit): ?string {
    $unit = trim($unit);
    if ($unit === '') return unit_label($stockUnit);
    foreach (allowed_recipe_units($stockUnit) as $allowed) {
        if (strcasecmp($allowed, $unit) === 0) return $allowed;
    }
    return null;
}

/** Is $pwd the signed-in user's password? Asked before anything is deleted. */
function recipes_password_ok(PDO $pdo, int $userId, string $pwd): bool {
    $st = $pdo->prepare('SELECT password FROM users WHERE id = :id');
    $st->execute([':id' => $userId]);
    $hash = $st->fetchColumn();
    return $hash && $pwd !== '' && password_verify($pwd, (string) $hash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('recipes_edit');          // a chef who forges a request gets the 403 page, not a changed recipe
    check_csrf();
    $back = recipes_url();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add_item':
                $name = trim((string) ($_POST['name'] ?? ''));
                $cat  = trim((string) ($_POST['category'] ?? '')) ?: 'Main';
                if ($name === '' || mb_strlen($name) > 120 || mb_strlen($cat) > 50) {
                    flash('error', 'Enter a menu item name (up to 120 characters) and a category (up to 50).');
                    break;
                }
                $pdo->prepare('INSERT INTO menu_items (name, category) VALUES (:n, :c)')->execute([':n' => $name, ':c' => $cat]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('create', "Added menu item: $name (ID: $newId)");
                flash('success', "$name added. Now add its ingredients.");
                $back = recipes_url($newId);
                break;

            case 'update_item':      // rename a dish / change its category
                $m    = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim((string) ($_POST['name'] ?? ''));
                $cat  = trim((string) ($_POST['category'] ?? '')) ?: 'Main';
                if (!$m) { flash('error', 'Invalid menu item.'); break; }
                $back = recipes_url($m);
                if ($name === '' || mb_strlen($name) > 120 || mb_strlen($cat) > 50) {
                    flash('error', 'Enter a menu item name (up to 120 characters) and a category (up to 50).');
                    break;
                }
                $old = $pdo->prepare('SELECT name, category FROM menu_items WHERE id = :id');
                $old->execute([':id' => $m]);
                $before = $old->fetch();
                if (!$before) { flash('error', 'That menu item no longer exists.'); $back = recipes_url(); break; }
                $pdo->prepare('UPDATE menu_items SET name = :n, category = :c WHERE id = :id')->execute([':n' => $name, ':c' => $cat, ':id' => $m]);
                log_activity('update', mb_substr('Edited menu item #' . $m . ': ' . $before['name'] . ' (' . $before['category'] . ') -> ' . $name . ' (' . $cat . ')', 0, 250));
                flash('success', 'Menu item updated.');
                break;

            case 'update_line':      // change the per-portion quantity / unit of one ingredient in a recipe
                $lineId = filter_var($_POST['line_id'] ?? null, FILTER_VALIDATE_INT);
                $m      = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
                $q      = filter_var($_POST['quantity_required'] ?? null, FILTER_VALIDATE_FLOAT);
                $back   = recipes_url($m);
                if (!$lineId) { flash('error', 'Invalid recipe line.'); break; }
                $st = $pdo->prepare('SELECT i.name, i.unit, ri.quantity_required, ri.unit AS recipe_unit
                                     FROM recipe_ingredients ri JOIN ingredients i ON i.id = ri.ingredient_id WHERE ri.id = :id');
                $st->execute([':id' => $lineId]);
                $line = $st->fetch();
                if (!$line) { flash('error', 'That recipe line no longer exists.'); break; }
                if ($q === false || round($q, 3) <= 0 || $q > 9999999) { flash('error', $line['name'] . ': enter a quantity above 0.'); break; }
                $u = recipes_match_unit((string) $line['unit'], (string) ($_POST['unit'] ?? ''));
                if ($u === null) {
                    flash('error', $line['name'] . ' is stocked in ' . unit_label($line['unit']) . ', so its recipe can only use ' . implode(' or ', allowed_recipe_units($line['unit'])) . '.');
                    break;
                }
                $pdo->prepare('UPDATE recipe_ingredients SET quantity_required = :q, unit = :u WHERE id = :id')
                    ->execute([':q' => round($q, 3), ':u' => $u, ':id' => $lineId]);
                log_activity('update', 'Recipe #' . $m . ' ' . $line['name'] . ': ' . num($line['quantity_required']) . ' ' . unit_label($line['recipe_unit'] ?: $line['unit']) . ' -> ' . num($q) . ' ' . $u . ' per portion');
                flash('success', $line['name'] . ' is now ' . num($q) . ' ' . $u . ' per portion.');
                $back = recipes_url($m, '', $lineId);
                break;

            case 'update_lines':     // "Edit quantities": every changed line of one dish, saved together (all or nothing)
                $m   = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                $ids = (array) ($_POST['line_id'] ?? []);
                $qs  = (array) ($_POST['quantity_required'] ?? []);
                $us  = (array) ($_POST['unit'] ?? []);
                if (!$m) { flash('error', 'Invalid menu item.'); break; }
                $back = recipes_url($m);

                $st = $pdo->prepare('SELECT ri.id, i.name, i.unit, ri.quantity_required, ri.unit AS recipe_unit
                                     FROM recipe_ingredients ri JOIN ingredients i ON i.id = ri.ingredient_id WHERE ri.menu_item_id = :m');
                $st->execute([':m' => $m]);
                $current = [];
                foreach ($st->fetchAll() as $r) $current[(int) $r['id']] = $r;

                $changes = [];                       // line id => [quantity, unit, human description]
                $error   = null;
                foreach ($ids as $k => $rawId) {
                    $lid = filter_var($rawId, FILTER_VALIDATE_INT);
                    if (!$lid || !isset($current[$lid])) { $error = 'A recipe line no longer exists. Reload the page and try again.'; break; }
                    $line = $current[$lid];
                    $q = filter_var(trim((string) ($qs[$k] ?? '')), FILTER_VALIDATE_FLOAT);
                    if ($q === false || round($q, 3) <= 0 || $q > 9999999) { $error = $line['name'] . ': enter a quantity above 0.'; break; }
                    $u = recipes_match_unit((string) $line['unit'], (string) ($us[$k] ?? ''));
                    if ($u === null) { $error = $line['name'] . ' is stocked in ' . unit_label($line['unit']) . ', so its recipe can only use ' . implode(' or ', allowed_recipe_units($line['unit'])) . '.'; break; }
                    $oldUnit = unit_label($line['recipe_unit'] ?: $line['unit']);
                    if (abs(round($q, 3) - (float) $line['quantity_required']) < 0.0005 && strcasecmp($u, $oldUnit) === 0) continue;   // untouched
                    $changes[$lid] = [round($q, 3), $u, $line['name'] . ' ' . num($line['quantity_required']) . ' ' . $oldUnit . ' -> ' . num($q) . ' ' . $u];
                }
                if ($error !== null) { flash('error', $error . ' Nothing was saved.'); break; }
                if (!$changes)       { flash('success', 'No changes to save.'); break; }

                run_tx($pdo, function (PDO $pdo) use ($m, $changes) {
                    $up = $pdo->prepare('UPDATE recipe_ingredients SET quantity_required = :q, unit = :u WHERE id = :id AND menu_item_id = :m');
                    foreach ($changes as $lid => [$q, $u]) $up->execute([':q' => $q, ':u' => $u, ':id' => $lid, ':m' => $m]);
                    log_activity('update', mb_substr('Recipe #' . $m . ': ' . implode('; ', array_column($changes, 2)), 0, 250));
                    return count($changes) === 1 ? 'Recipe line updated.' : count($changes) . ' recipe lines updated.';
                });
                $back = recipes_url($m, '', implode(',', array_keys($changes)));
                break;

            case 'save_line':        // add ingredients (an ingredient already in the recipe is updated)
                $m           = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                $ingredients = (array) ($_POST['ingredient_id'] ?? []);
                $quantities  = (array) ($_POST['quantity_required'] ?? []);
                $units       = (array) ($_POST['unit'] ?? []);
                if (!$m) { flash('error', 'Invalid menu item.'); break; }
                $back = recipes_url($m);

                $stockUnits = [];
                foreach ($pdo->query('SELECT id, unit FROM ingredients') as $r) $stockUnits[(int) $r['id']] = (string) $r['unit'];

                // Validate every row first: nothing is saved unless all rows are right.
                $clean = [];
                $error = null;
                foreach ($ingredients as $index => $ingId) {
                    $rawQty = trim((string) ($quantities[$index] ?? ''));
                    if (trim((string) $ingId) === '' && $rawQty === '') continue;                 // an untouched row
                    $i = filter_var($ingId, FILTER_VALIDATE_INT);
                    $q = filter_var($rawQty, FILTER_VALIDATE_FLOAT);
                    if (!$i || !isset($stockUnits[$i]))                          { $error = 'Choose an ingredient on every row.'; break; }
                    if ($q === false || round($q, 3) <= 0 || $q > 9999999)       { $error = 'Enter a quantity above 0 on every row.'; break; }
                    // pcs ingredient -> pcs only; kg ingredient -> kg or g; L ingredient -> L or mL.
                    $u = recipes_match_unit($stockUnits[$i], (string) ($units[$index] ?? ''));
                    if ($u === null) { $error = 'That unit cannot be used for this ingredient. Allowed: ' . implode(' or ', allowed_recipe_units($stockUnits[$i])) . '.'; break; }
                    $clean[$i] = [round($q, 3), $u];                                                // same ingredient twice: the last row wins
                }
                if ($error === null && !$clean) $error = 'Add at least one ingredient and quantity.';
                if ($error !== null) { flash('error', $error); break; }

                run_tx($pdo, function (PDO $pdo) use ($m, $clean) {
                    $stmt = $pdo->prepare('INSERT INTO recipe_ingredients (menu_item_id, ingredient_id, quantity_required, unit) VALUES (:m, :i, :q, :u)
                                           ON DUPLICATE KEY UPDATE quantity_required = VALUES(quantity_required), unit = VALUES(unit)');
                    foreach ($clean as $i => [$q, $u]) $stmt->execute([':m' => $m, ':i' => $i, ':q' => $q, ':u' => $u]);
                    log_activity('update', 'Recipe #' . $m . ': saved ' . count($clean) . ' ingredient line' . (count($clean) === 1 ? '' : 's'));
                    return count($clean) === 1 ? 'Recipe ingredient saved.' : count($clean) . ' recipe ingredients saved.';
                });
                break;

            case 'delete_line':
                $id = filter_var($_POST['line_id'] ?? null, FILTER_VALIDATE_INT);
                $m  = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
                $back = recipes_url($m);
                if (!recipes_password_ok($pdo, $userId, (string) ($_POST['verify_password'] ?? ''))) {
                    flash('error', 'Incorrect password. Nothing was deleted.');
                    break;
                }
                if ($id) {
                    $stmt = $pdo->prepare('SELECT i.name FROM recipe_ingredients ri JOIN ingredients i ON i.id = ri.ingredient_id WHERE ri.id = :id');
                    $stmt->execute([':id' => $id]);
                    $iname = $stmt->fetchColumn();
                    $pdo->prepare('DELETE FROM recipe_ingredients WHERE id = :id')->execute([':id' => $id]);
                    if ($iname) log_activity('delete', "Removed ingredient from recipe: $iname (line ID: $id, menu item: $m)");
                }
                flash('success', 'Ingredient removed from the recipe.');
                break;

            case 'delete_item':
                $id = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                if (!recipes_password_ok($pdo, $userId, (string) ($_POST['verify_password'] ?? ''))) {
                    flash('error', 'Incorrect password. Nothing was deleted.');
                    $back = recipes_url((int) $id);
                    break;
                }
                if ($id) {
                    $stmt = $pdo->prepare('SELECT name FROM menu_items WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    $iname = $stmt->fetchColumn();
                    $pdo->prepare('DELETE FROM menu_items WHERE id = :id')->execute([':id' => $id]);   // recipe lines go with it; past orders keep their item_name
                    if ($iname) log_activity('delete', "Deleted menu item: $iname (ID: $id)");
                }
                flash('success', 'Menu item deleted.');
                break;

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $ex) {
        error_log('[recipes] ' . $ex->getMessage());
        flash('error', (int) ($ex->errorInfo[1] ?? 0) === 1062 ? 'A menu item with that name already exists.' : 'Could not save. Nothing was changed.');
    }
    redirect($back);
}

// ---- Page data ----------------------------------------------------------------------------------------------
$items       = $pdo->query('SELECT id, name, category FROM menu_items ORDER BY category, name')->fetchAll();
$ingredients = $canEdit ? $pdo->query('SELECT id, name, unit FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name')->fetchAll() : [];

// One availability report per dish: the single source of truth (unit conversion happens inside it).
$report = [];
$state  = [];                                       // 'available' | 'out' | 'norecipe'
$counts = ['available' => 0, 'out' => 0, 'norecipe' => 0];
foreach ($items as $it) {
    $id = (int) $it['id'];
    $report[$id] = get_menu_availability($pdo, $id);
    $state[$id]  = !$report[$id]['has_recipe'] ? 'norecipe' : ($report[$id]['available'] ? 'available' : 'out');
    $counts[$state[$id]]++;
}

$show = $_GET['show'] ?? 'all';
if (!is_string($show) || !in_array($show, ['all', 'available', 'out', 'norecipe'], true)) $show = 'all';
$openId  = (int) ($_GET['open'] ?? 0);
$hlLines = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['hl'] ?? '')))));
$visible = array_filter($items, fn($it) => $show === 'all' || $state[(int) $it['id']] === $show || (int) $it['id'] === $openId);

// Units each ingredient allows in a recipe, keyed by its stock unit. Handed to the page script.
$unitMap = [];
foreach ($ingredients as $i) $unitMap[$i['unit']] = allowed_recipe_units($i['unit']);

$filters = [
    'all'       => ['All dishes',   count($items),         'border-slate-200 text-slate-900',  'ring-slate-400'],
    'available' => ['Available',    $counts['available'],  'border-green-200 text-green-800',  'ring-green-400'],
    'out'       => ['Out of stock', $counts['out'],        'border-red-200 text-red-800',      'ring-red-400'],
    'norecipe'  => ['No recipe',    $counts['norecipe'],   'border-slate-200 text-slate-500',  'ring-slate-400'],
];
$dot = ['available' => 'bg-green-500', 'out' => 'bg-red-500', 'norecipe' => 'bg-slate-300'];
$fld = 'mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500';
$btnIcon = 'inline-flex h-8 w-8 items-center justify-center rounded-md border text-slate-500 transition focus:outline-none focus:ring-2 focus:ring-orange-400';

// Small inline icons (no extra files).
$icoPencil = '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>';
$icoTrash  = '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>';
$icoCheck  = '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
$icoX      = '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';

$pageTitle = 'Recipes and menu availability';
?>

<style>
    @keyframes rowSaved { from { background-color: #dcfce7; } to { background-color: transparent; } }
    .row-saved { animation: rowSaved 2.8s ease-out; }
</style>

<!-- Filter cards -->
<div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
    <?php foreach ($filters as $key => [$label, $n, $tone, $ring]):
        $on = $show === $key; ?>
        <a href="index?page=recipes<?= $key === 'all' ? '' : '&show=' . $key ?>" <?= $on ? 'aria-current="page"' : '' ?>
           class="rounded-lg border bg-white p-4 shadow-sm transition hover:shadow <?= $tone ?> <?= $on ? 'ring-2 ' . $ring : '' ?>">
            <p class="text-xs font-bold uppercase tracking-wider opacity-70"><?= e($label) ?></p>
            <p class="mt-1 text-3xl font-black tabular-nums"><?= (int) $n ?></p>
        </a>
    <?php endforeach; ?>
</div>

<!-- Search + new dish -->
<div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div class="relative min-w-[220px] flex-1">
        <input type="search" id="dishSearch" placeholder="Search dishes or categories..." autocomplete="off" aria-label="Search dishes"
               class="w-full rounded-md border border-slate-300 bg-white py-2 pl-3 pr-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500">
    </div>
    <?php if ($canEdit): ?>
        <button type="button" id="newItemBtn" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">+ New menu item</button>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<section id="newItemPanel" class="mt-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm<?= $items ? ' hidden' : '' ?>">
    <h2 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-900">New menu item</h2>
    <form method="POST" action="<?= e(recipes_url()) ?>" class="flex flex-wrap items-end gap-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_item">
        <label class="text-sm font-medium">Name
            <input type="text" name="name" required maxlength="120" class="<?= $fld ?> w-64">
        </label>
        <label class="text-sm font-medium">Category
            <input type="text" name="category" list="categories" maxlength="50" placeholder="Main" class="<?= $fld ?> w-40">
            <datalist id="categories"><option>Starter</option><option>Main</option><option>Side</option><option>Dessert</option><option>Drink</option></datalist>
        </label>
        <button class="rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Add menu item</button>
    </form>
</section>
<?php endif; ?>

<!-- Dishes -->
<div id="dishList" class="mt-4 space-y-3">
    <?php foreach ($visible as $it):
        $id    = (int) $it['id'];
        $rep   = $report[$id];
        $ls    = $rep['ingredients'];
        $st    = $state[$id];
        $p     = $rep['max_portions'];
        $short = array_values(array_filter($ls, fn($l) => $l['is_short']));
        $search = mb_strtolower($it['name'] . ' ' . $it['category']); ?>
        <details id="item-<?= $id ?>" data-search="<?= e($search) ?>" class="group rounded-lg border border-slate-200 bg-white shadow-sm" <?= ($id === $openId) ? 'open' : '' ?>>
            <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full <?= $dot[$st] ?>" aria-hidden="true"></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-semibold text-slate-900"><?= e($it['name']) ?></span>
                    <span class="block text-xs text-slate-500"><?= e($it['category']) ?> &middot; <?= count($ls) ?> ingredient<?= count($ls) === 1 ? '' : 's' ?></span>
                </span>
                <span class="hidden text-right sm:block">
                    <?php if ($p !== null && $st !== 'norecipe'): ?>
                        <span class="block text-lg font-black leading-none tabular-nums <?= $st === 'out' ? 'text-red-700' : 'text-slate-900' ?>"><?= (int) $p ?></span>
                        <span class="block text-[11px] uppercase tracking-wide text-slate-500">portion<?= $p === 1 ? '' : 's' ?> in stock</span>
                    <?php else: ?>
                        <span class="block text-lg font-black leading-none text-slate-300">&mdash;</span>
                        <span class="block text-[11px] uppercase tracking-wide text-slate-400">no check</span>
                    <?php endif; ?>
                </span>
                <?php if ($st === 'out'): ?><?= badge('Out of stock', 'red') ?>
                <?php elseif ($st === 'available'): ?><?= badge('Available', 'green') ?>
                <?php else: ?><?= badge('No recipe', 'gray') ?><?php endif; ?>
                <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
            </summary>

            <div class="space-y-5 border-t border-slate-200 p-4 sm:p-5">

                <!-- Dish details: name / category, with the edit and delete actions -->
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Menu item</p>
                            <p class="text-base font-semibold text-slate-900"><?= e($it['name']) ?> <span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= e($it['category']) ?></span></p>
                        </div>
                        <?php if ($canEdit): ?>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="toggleDishEdit(<?= $id ?>)" id="dish-edit-btn-<?= $id ?>"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-400"><?= $icoPencil ?> Edit details</button>
                                <button type="button" onclick="openDeleteDialog('dish', <?= $id ?>, <?= $id ?>, <?= e(json_encode($it['name'], JSON_UNESCAPED_UNICODE)) ?>, <?= count($ls) ?>)"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400"><?= $icoTrash ?> Delete</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                        <form id="dish-form-<?= $id ?>" method="POST" action="<?= e(recipes_url()) ?>" data-orig-name="<?= e($it['name']) ?>" data-orig-cat="<?= e($it['category']) ?>"
                              class="mt-3 hidden flex-wrap items-end gap-3 rounded-lg border border-orange-200 bg-orange-50/50 p-3">
                            <?= csrf_field() ?><input type="hidden" name="action" value="update_item"><input type="hidden" name="menu_item_id" value="<?= $id ?>">
                            <label class="text-sm font-medium">Name
                                <input type="text" name="name" required maxlength="120" value="<?= e($it['name']) ?>" oninput="dishDirty(<?= $id ?>)" class="<?= $fld ?> w-64">
                            </label>
                            <label class="text-sm font-medium">Category
                                <input type="text" name="category" list="categories" maxlength="50" value="<?= e($it['category']) ?>" oninput="dishDirty(<?= $id ?>)" class="<?= $fld ?> w-40">
                            </label>
                            <button id="dish-save-<?= $id ?>" disabled class="rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-40">Save changes</button>
                            <button type="button" onclick="toggleDishEdit(<?= $id ?>)" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($st === 'out'): ?>
                    <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        <strong>Cannot be ordered.</strong> Short on:
                        <?= e(implode(', ', array_map(
                            fn($l) => $l['unit_ok']
                                ? $l['name'] . ' (needs ' . num($l['per_portion']) . ' ' . $l['recipe_unit'] . ' per portion, have ' . num($l['in_stock']) . ' ' . $l['unit'] . ')'
                                : $l['name'] . ' (recipe unit ' . $l['recipe_unit'] . ' does not match stock unit ' . $l['unit'] . ': edit the line to fix it)',
                            $short))) ?>.
                    </p>
                <?php endif; ?>

                <!-- Ingredients: administrators edit every quantity of the recipe in one go, then save once -->
                <?php if ($ls): ?>
                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Ingredients per portion</p>
                            <?php if ($canEdit): ?>
                                <button type="button" id="recipe-toggle-<?= $id ?>" onclick="toggleRecipeEdit(<?= $id ?>)"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-400"><?= $icoPencil ?> <span class="toggle-label">Edit quantities</span></button>
                            <?php endif; ?>
                        </div>
                        <form id="recipe-form-<?= $id ?>" method="POST" action="<?= e(recipes_url()) ?>" data-recipe-form data-item="<?= $id ?>" class="overflow-hidden rounded-lg border border-slate-200 transition-shadow">
                            <?php if ($canEdit): ?><?= csrf_field() ?><input type="hidden" name="action" value="update_lines"><input type="hidden" name="menu_item_id" value="<?= $id ?>"><?php endif; ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                        <tr><th class="px-3 py-2 font-semibold">Ingredient</th><th class="px-3 font-semibold">Per portion</th><th class="px-3 font-semibold">In stock</th><th class="px-3 font-semibold">Portions</th><th class="px-3 font-semibold">Status</th><?php if ($canEdit): ?><th class="px-3 text-right font-semibold">Actions</th><?php endif; ?></tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($ls as $l):
                                            $lid     = (int) $l['line_id'];
                                            $alt     = alt_unit_text($l['in_stock'], $l['unit']);
                                            $conv    = null;                                   // "= 0.25 kg" when the recipe unit differs from the stock unit
                                            if ($l['unit_ok'] && unit_key($l['recipe_unit']) !== unit_key($l['unit'])) {
                                                $cv = convert_qty((float) $l['per_portion'], $l['recipe_unit'], $l['unit']);
                                                if ($cv !== null) $conv = num($cv, 6) . ' ' . $l['unit'];
                                            }
                                            // Whole portions this ingredient alone can cover (same whole-number maths as the dish total).
                                            $perBase = $l['unit_ok'] ? to_base_milli($l['per_portion'], $l['recipe_unit']) : 0;
                                            $cover   = !$l['unit_ok'] ? 0 : ($perBase > 0 ? intdiv(max(0, to_base_milli($l['in_stock'], $l['unit'])), $perBase) : null);
                                            $isLimit = $p !== null && $cover === $p && count($ls) > 1;
                                            $opts    = allowed_recipe_units($l['unit']); ?>
                                            <tr class="align-top transition-colors <?= in_array($lid, $hlLines, true) ? 'row-saved' : '' ?>" data-line="<?= $lid ?>"
                                                data-orig-qty="<?= e(num($l['per_portion'])) ?>" data-orig-unit="<?= e($l['recipe_unit']) ?>" data-stock-unit="<?= e($l['unit']) ?>"
                                                data-stock-qty="<?= e(num($l['in_stock'], 6)) ?>" data-orig-cover="<?= $cover === null ? '' : (int) $cover ?>">
                                                <td class="px-3 py-2.5 font-medium text-slate-900">
                                                    <?= e($l['name']) ?>
                                                    <span class="chg-chip ml-1 hidden rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800">changed</span>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <span class="view-mode tabular-nums"><?= e(num($l['per_portion'])) ?> <?= e($l['recipe_unit']) ?>
                                                        <?php if ($conv): ?><span class="block text-xs text-slate-400">= <?= e($conv) ?></span><?php endif; ?></span>
                                                    <?php if ($canEdit): ?>
                                                        <span class="edit-mode hidden">
                                                            <input type="hidden" name="line_id[]" value="<?= $lid ?>">
                                                            <span class="inline-flex items-center gap-1">
                                                                <input type="number" name="quantity_required[]" step="any" min="0.001" required value="<?= e(num($l['per_portion'])) ?>" data-role="qty" inputmode="decimal" aria-label="Quantity per portion of <?= e($l['name']) ?>"
                                                                       class="w-24 rounded-md border border-orange-300 bg-white px-2 py-1.5 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-orange-400">
                                                                <select name="unit[]" data-role="unit" aria-label="Unit for <?= e($l['name']) ?>" class="rounded-md border border-orange-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400<?= count($opts) === 1 ? ' bg-slate-100 pointer-events-none' : '' ?>">
                                                                    <?php foreach ($opts as $u): ?>
                                                                        <option value="<?= e($u) ?>" <?= strcasecmp($u, (string) $l['recipe_unit']) === 0 ? 'selected' : '' ?>><?= e($u) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </span>
                                                            <span class="line-hint mt-1 block max-w-[16rem] text-xs text-slate-500"></span>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5 tabular-nums <?= $l['is_short'] ? 'text-red-700' : 'text-slate-700' ?>">
                                                    <?= e(num($l['in_stock'])) ?> <?= e($l['unit']) ?>
                                                    <?php if ($alt): ?><span class="block text-xs text-slate-400"><?= e($alt) ?></span><?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5 tabular-nums <?= $isLimit ? 'font-bold text-slate-900' : 'text-slate-600' ?>">
                                                    <span class="cover-now"><?= $cover === null ? '&mdash;' : (int) $cover ?></span>
                                                    <?php if ($isLimit): ?><span class="block text-[10px] font-semibold uppercase text-amber-700">limits dish</span><?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5"><?= $l['is_out'] ? badge('Out of stock', 'red') : ($l['is_short'] ? badge($l['unit_ok'] ? 'Not enough' : 'Unit mismatch', 'amber') : badge('In stock', 'green')) ?></td>
                                                <?php if ($canEdit): ?>
                                                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                                        <button type="button" onclick="startLineEdit(this)" title="Edit quantity" aria-label="Edit <?= e($l['name']) ?> quantity" class="view-mode <?= $btnIcon ?> border-slate-200 bg-white hover:border-orange-300 hover:bg-orange-50 hover:text-orange-700"><?= $icoPencil ?></button>
                                                        <button type="button" onclick="openDeleteDialog('line', <?= $lid ?>, <?= $id ?>, <?= e(json_encode($l['name'], JSON_UNESCAPED_UNICODE)) ?>, <?= e(json_encode($it['name'], JSON_UNESCAPED_UNICODE)) ?>)" title="Remove from recipe" aria-label="Remove <?= e($l['name']) ?> from recipe" class="<?= $btnIcon ?> border-slate-200 bg-white hover:border-red-300 hover:bg-red-50 hover:text-red-700"><?= $icoTrash ?></button>
                                                        <button type="button" onclick="resetLine(this.closest('tr'))" title="Undo changes to this line" aria-label="Undo changes to <?= e($l['name']) ?>" class="edit-mode hidden undo-btn <?= $btnIcon ?> border-slate-200 bg-white hover:bg-slate-100 disabled:opacity-30"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 11-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($canEdit): ?>
                                <div class="edit-bar sticky bottom-0 hidden flex-wrap items-center justify-between gap-3 border-t border-orange-200 bg-orange-50 px-4 py-3">
                                    <p class="bar-text text-sm text-orange-900" aria-live="polite">Change a quantity or unit, then save.</p>
                                    <div class="flex items-center gap-2">
                                        <button type="button" onclick="cancelRecipeEdit(<?= $id ?>)" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Discard</button>
                                        <button class="save-all rounded-md bg-orange-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-40" disabled>Save changes</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="rounded-md border border-dashed border-slate-300 px-3 py-4 text-center text-sm text-slate-500">
                        <?= $canEdit ? 'No ingredients yet. Add them below; until then, stock is not checked for this dish.' : 'No recipe has been written for this dish yet, so its stock is not checked.' ?>
                    </p>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <!-- Add ingredients (an ingredient already in the recipe is updated) -->
                    <details class="rounded-lg border border-slate-200 bg-slate-50" <?= $ls ? '' : 'open' ?>>
                        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-100 [&::-webkit-details-marker]:hidden">
                            <span class="flex h-5 w-5 items-center justify-center rounded bg-slate-800 text-xs leading-none text-white">+</span> Add ingredients
                            <span class="font-normal text-slate-500">&middot; quantity is per one portion</span>
                        </summary>
                        <form method="POST" action="<?= e(recipes_url()) ?>" class="space-y-3 border-t border-slate-200 p-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_line">
                            <input type="hidden" name="menu_item_id" value="<?= $id ?>">

                            <div id="ingredient-rows-<?= $id ?>" class="space-y-3">
                                <div class="ingredient-row flex flex-wrap items-end gap-3">
                                    <label class="min-w-[200px] flex-1 text-sm font-medium">Ingredient
                                        <select name="ingredient_id[]" class="<?= $fld ?>">
                                            <option value="" selected>Select ingredient...</option>
                                            <?php foreach ($ingredients as $i): ?>
                                                <option value="<?= (int) $i['id'] ?>" data-unit="<?= e($i['unit']) ?>"><?= e($i['name']) ?> (stock in <?= e(unit_label($i['unit'])) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="text-sm font-medium">Quantity
                                        <input type="number" name="quantity_required[]" step="any" min="0.001" class="<?= $fld ?> w-28">
                                    </label>
                                    <label class="text-sm font-medium">Unit
                                        <select name="unit[]" class="<?= $fld ?> w-24 pointer-events-none bg-slate-100"><option value="">Unit</option></select>
                                    </label>
                                    <button type="button" onclick="removeIngredientRow(this)" title="Remove this row" class="remove-row-btn hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 hover:bg-red-100">&#10005;</button>
                                    <p class="row-hint w-full text-xs text-slate-500"></p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                                <button type="button" onclick="addIngredientRow(<?= $id ?>)" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">+ Add another ingredient</button>
                                <button class="rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Save ingredients</button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>

    <p id="dishNone" class="hidden rounded-lg border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500">No dishes match your search.</p>
    <?php if (!$items): ?>
        <p class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500">No menu items yet.<?= $canEdit ? ' Use "+ New menu item" to add the first one.' : ' An administrator can add them.' ?></p>
    <?php elseif (!$visible): ?>
        <p class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500">
            <?= ['out' => 'Nothing is out of stock right now.', 'available' => 'No menu items are fully available right now.', 'norecipe' => 'Every dish has a recipe.'][$show] ?? 'Nothing to show.' ?>
            <a href="index?page=recipes" class="font-semibold underline">Show all dishes</a>
        </p>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<!-- Delete confirmation (one dialog for both a recipe line and a whole dish). Posts normally, so the result message shows. -->
<dialog id="deleteDialog" class="flex items-center justify-center w-full max-w-md rounded-xl p-0 shadow-2xl backdrop:bg-slate-900/50" aria-labelledby="delTitle">
    <form method="POST" action="<?= e(recipes_url()) ?>" class="p-6">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="delAction" value="">
        <input type="hidden" name="line_id" id="delLineId" value="">
        <input type="hidden" name="menu_item_id" id="delItemId" value="">
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600"><?= $icoTrash ?></span>
            <div class="min-w-0">
                <h3 id="delTitle" class="text-lg font-bold text-slate-900">Delete</h3>
                <p id="delMessage" class="mt-1 text-sm text-slate-700"></p>
                <p id="delDetail" class="mt-1 text-xs text-slate-500"></p>
            </div>
        </div>
        <div class="mt-5">
            <label for="delPass" class="block text-xs font-bold uppercase tracking-wider text-slate-500">Your password</label>
            <div class="relative mt-1">
                <input type="password" name="verify_password" id="delPass" required autocomplete="current-password" placeholder="Enter your password to confirm"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 pr-16 text-sm shadow-sm focus:border-red-500 focus:outline-none focus:ring-1 focus:ring-red-500">
                <button type="button" id="delShow" class="absolute inset-y-0 right-0 px-3 text-xs font-semibold text-slate-500 hover:text-slate-800" aria-label="Show or hide password">Show</button>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" data-close class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button id="delConfirm" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script>
// ---- Search (everyone) -------------------------------------------------------------------------------------------
(function () {
    var input = document.getElementById('dishSearch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('#dishList > details[data-search]'));
    var none  = document.getElementById('dishNone');
    function apply() {
        var q = input.value.trim().toLowerCase(), shown = 0;
        cards.forEach(function (c) {
            var ok = q === '' || c.getAttribute('data-search').indexOf(q) !== -1;
            c.classList.toggle('hidden', !ok);
            if (ok) shown++;
        });
        none.classList.toggle('hidden', shown !== 0 || cards.length === 0);
    }
    input.addEventListener('input', apply);
})();
</script>

<?php if ($canEdit): ?>
<script>
// ---- Units an ingredient allows in a recipe, keyed by its stock unit: kg -> kg, g | pcs -> pcs | L -> L, mL ... -------
var UNIT_OPTIONS = <?= json_encode($unitMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var UNIT_FACTORS = { kg: 1000, g: 1, l: 1000, ml: 1 };   // grams / millilitres in one unit

function fmtNum(n) { return String(parseFloat(n.toFixed(6))); }
function convNote(qty, unit, stockUnit) {                 // "= 0.25 kg of stock per portion." or ''
    var fu = UNIT_FACTORS[(unit || '').toLowerCase()], fs = UNIT_FACTORS[(stockUnit || '').toLowerCase()];
    if (qty > 0 && fu && fs && unit.toLowerCase() !== stockUnit.toLowerCase()) return '= ' + fmtNum(qty * fu / fs) + ' ' + stockUnit + ' of stock per portion.';
    return '';
}

// ---- "Add ingredients" rows: the unit list follows the chosen ingredient ----------------------------------------------
function syncRow(row) {
    var ingSel  = row.querySelector('select[name="ingredient_id[]"]');
    var unitSel = row.querySelector('select[name="unit[]"]');
    var qtyInp  = row.querySelector('input[name="quantity_required[]"]');
    var hint    = row.querySelector('.row-hint');
    var opt     = ingSel.options[ingSel.selectedIndex];
    var stockUnit = opt ? (opt.getAttribute('data-unit') || '') : '';

    if (!stockUnit) {
        unitSel.innerHTML = '<option value="">Unit</option>';
        unitSel.setAttribute('data-for', '');
        unitSel.classList.add('pointer-events-none', 'bg-slate-100');   // never disabled: a disabled select is not posted and the rows would shift
        hint.textContent = '';
        return;
    }
    var options = UNIT_OPTIONS[stockUnit] || [stockUnit];
    if (unitSel.getAttribute('data-for') !== stockUnit) {          // a different ingredient was picked: rebuild the list
        unitSel.innerHTML = '';
        options.forEach(function (u) {
            var o = document.createElement('option');
            o.value = u; o.textContent = u;
            unitSel.appendChild(o);
        });
        unitSel.setAttribute('data-for', stockUnit);
    }
    unitSel.classList.toggle('bg-slate-100', options.length === 1);       // one choice only (e.g. pcs): nothing to pick
    unitSel.classList.toggle('pointer-events-none', options.length === 1);
    hint.textContent = convNote(parseFloat(qtyInp.value), unitSel.value, stockUnit) ||
        ('Stock is counted in ' + stockUnit + (options.length === 1 ? ', so this line uses ' + stockUnit + ' only.' : '; write the recipe in ' + options.join(' or ') + '.'));
}
document.addEventListener('change', function (ev) {
    var row = ev.target.closest && ev.target.closest('.ingredient-row');
    if (row) syncRow(row);
});
document.addEventListener('input', function (ev) {
    var row = ev.target.closest && ev.target.closest('.ingredient-row');
    if (row && ev.target.matches('input[name="quantity_required[]"]')) syncRow(row);
});
function updateRemoveButtons(container) {
    var rows = container.querySelectorAll('.ingredient-row');
    rows.forEach(function (row) { row.querySelector('.remove-row-btn').classList.toggle('hidden', rows.length < 2); });
}
function addIngredientRow(itemId) {
    var container = document.getElementById('ingredient-rows-' + itemId);
    var row = container.querySelector('.ingredient-row').cloneNode(true);
    row.querySelector('select[name="ingredient_id[]"]').selectedIndex = 0;
    row.querySelector('input[name="quantity_required[]"]').value = '';
    var unitSel = row.querySelector('select[name="unit[]"]');
    unitSel.innerHTML = '<option value="">Unit</option>';
    unitSel.setAttribute('data-for', '');
    unitSel.classList.add('pointer-events-none', 'bg-slate-100');
    container.appendChild(row);
    syncRow(row);
    updateRemoveButtons(container);
}
function removeIngredientRow(btn) {
    var container = btn.closest('[id^="ingredient-rows-"]');
    btn.closest('.ingredient-row').remove();
    updateRemoveButtons(container);
}

// ---- Edit a dish's name / category: Save stays disabled until something actually changed ------------------------------
function toggleDishEdit(id) {
    var form = document.getElementById('dish-form-' + id);
    var opening = form.classList.contains('hidden');
    form.classList.toggle('hidden', !opening);
    form.classList.toggle('flex', opening);
    if (opening) { form.querySelector('input[name="name"]').focus(); form.querySelector('input[name="name"]').select(); }
    else { form.elements['name'].value = form.dataset.origName; form.elements['category'].value = form.dataset.origCat; dishDirty(id); }
}
function dishDirty(id) {
    var form = document.getElementById('dish-form-' + id);
    var nm = form.elements['name'].value.trim(), cat = form.elements['category'].value.trim();
    document.getElementById('dish-save-' + id).disabled = !(nm !== form.dataset.origName || cat !== form.dataset.origCat) || nm === '';
}

// ---- Edit a recipe: every quantity at once, one Save. Changed lines are marked, with a live preview of the portions stock would cover ----
var editingForm = null;
function unitFactor(u) { var f = UNIT_FACTORS[(u || '').toLowerCase()]; return f || 1; }   // pcs / pack / can ... count as 1
function recipeForm(id) { return document.getElementById('recipe-form-' + id); }
// The unit stored in the database may differ in case from the dropdown's spelling (ml / mL): start from what the dropdown shows.
document.querySelectorAll('tr[data-line]').forEach(function (tr) {
    var u = tr.querySelector('[data-role="unit"]');
    if (u) tr.dataset.origUnit = u.value;
});
function setRowMode(tr, editing) {
    tr.querySelectorAll('.view-mode').forEach(function (el) { el.classList.toggle('hidden', editing); });
    tr.querySelectorAll('.edit-mode').forEach(function (el) { el.classList.toggle('hidden', !editing); });
    tr.classList.toggle('bg-orange-50/40', editing);
}
function startRecipeEdit(id, focusRow) {
    var f = recipeForm(id);
    if (editingForm && editingForm !== f) cancelRecipeEdit(editingForm.dataset.item);
    editingForm = f;
    f.querySelectorAll('tr[data-line]').forEach(function (tr) { setRowMode(tr, true); });
    var bar = f.querySelector('.edit-bar'); bar.classList.remove('hidden'); bar.classList.add('flex');
    f.classList.add('ring-1', 'ring-orange-300');
    var t = document.getElementById('recipe-toggle-' + id); if (t) t.querySelector('.toggle-label').textContent = 'Cancel editing';
    recipeRefresh(f);
    var q = (focusRow || f.querySelector('tr[data-line]')).querySelector('[data-role="qty"]'); q.focus(); q.select();
}
function cancelRecipeEdit(id) {
    var f = recipeForm(id);
    f.querySelectorAll('tr[data-line]').forEach(function (tr) { resetLine(tr, true); setRowMode(tr, false); tr.classList.remove('bg-amber-50'); });
    var bar = f.querySelector('.edit-bar'); bar.classList.add('hidden'); bar.classList.remove('flex');
    f.classList.remove('ring-1', 'ring-orange-300');
    var t = document.getElementById('recipe-toggle-' + id); if (t) t.querySelector('.toggle-label').textContent = 'Edit quantities';
    if (editingForm === f) editingForm = null;
}
function toggleRecipeEdit(id) { var f = recipeForm(id); (editingForm === f) ? cancelRecipeEdit(id) : startRecipeEdit(id); }
function startLineEdit(btn) { var tr = btn.closest('tr'); startRecipeEdit(tr.closest('form').dataset.item, tr); }
function resetLine(tr, quiet) {
    tr.querySelector('[data-role="qty"]').value = tr.dataset.origQty;
    tr.querySelector('[data-role="unit"]').value = tr.dataset.origUnit;
    if (!quiet) recipeRefresh(tr.closest('form'));
}
function lineState(tr) {
    var qtyEl = tr.querySelector('[data-role="qty"]'), unitEl = tr.querySelector('[data-role="unit"]');
    var qty = parseFloat(qtyEl.value);
    var valid = qty > 0;
    var changed = !(qty === parseFloat(tr.dataset.origQty) && unitEl.value === tr.dataset.origUnit);
    return { qty: qty, unit: unitEl.value, valid: valid, changed: changed };
}
function recipeRefresh(f) {
    var dirty = 0, bad = 0;
    f.querySelectorAll('tr[data-line]').forEach(function (tr) {
        var st = lineState(tr), hint = tr.querySelector('.line-hint');
        var notes = [];
        if (!st.valid) { bad++; notes.push('Enter a quantity above 0.'); }
        else {
            var conv = convNote(st.qty, st.unit, tr.dataset.stockUnit);
            if (conv) notes.push(conv.replace(' of stock per portion.', ' of stock.'));
            var per = Math.round(st.qty * unitFactor(st.unit) * 1000), have = Math.round(parseFloat(tr.dataset.stockQty) * unitFactor(tr.dataset.stockUnit) * 1000);
            if (st.changed && per > 0) {
                var now = Math.floor(have / per), was = tr.dataset.origCover;
                notes.push('Stock covers ' + now + ' portion' + (now === 1 ? '' : 's') + (was !== '' ? ' (was ' + was + ')' : '') + '.');
            }
        }
        hint.textContent = notes.join(' ');
        hint.classList.toggle('text-red-600', !st.valid);
        tr.querySelector('[data-role="qty"]').classList.toggle('border-red-400', !st.valid);
        tr.querySelector('.chg-chip').classList.toggle('hidden', !st.changed);
        tr.querySelector('.undo-btn').disabled = !st.changed;
        tr.classList.toggle('bg-amber-50', st.changed);
        if (st.changed) dirty++;
    });
    var btn = f.querySelector('.save-all'), txt = f.querySelector('.bar-text');
    btn.disabled = dirty === 0 || bad > 0;
    btn.textContent = dirty > 1 ? 'Save ' + dirty + ' changes' : 'Save changes';
    txt.textContent = bad ? 'Fix the highlighted quantity before saving.' : (dirty ? dirty + ' line' + (dirty === 1 ? '' : 's') + ' changed. Nothing is saved until you press Save.' : 'Change a quantity or unit, then save.');
    f.dataset.dirty = dirty;
}
document.addEventListener('input', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr[data-line]');
    if (tr && ev.target.matches('[data-role="qty"]')) recipeRefresh(tr.closest('form'));
});
document.addEventListener('change', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr[data-line]');
    if (tr && ev.target.matches('[data-role="unit"]')) recipeRefresh(tr.closest('form'));
});
document.addEventListener('keydown', function (ev) {
    var dlg = document.getElementById('deleteDialog');
    if (ev.key === 'Escape' && editingForm && !(dlg && dlg.open)) cancelRecipeEdit(editingForm.dataset.item);
});
window.addEventListener('beforeunload', function (ev) {          // unsaved quantity changes: let the browser ask first
    if (editingForm && editingForm.dataset.dirty > 0 && !editingForm.dataset.saving) { ev.preventDefault(); ev.returnValue = ''; }
});
document.querySelectorAll('form[data-recipe-form]').forEach(function (f) {
    f.addEventListener('submit', function () { f.dataset.saving = '1'; });
    f.addEventListener('keydown', function (ev) {                  // Enter inside a quantity box saves (when there is something to save)
        if (ev.key === 'Enter' && ev.target.matches('[data-role="qty"]') && f.querySelector('.save-all').disabled) ev.preventDefault();
    });
});

// ---- New menu item panel -----------------------------------------------------------------------------------------------
(function () {
    var btn = document.getElementById('newItemBtn'), panel = document.getElementById('newItemPanel');
    if (!btn || !panel) return;
    btn.addEventListener('click', function () {
        panel.classList.toggle('hidden');
        if (!panel.classList.contains('hidden')) panel.querySelector('input[name="name"]').focus();
    });
})();

// ---- Delete confirmation dialog (recipe line or whole dish). Needs the user's password; posts as a normal form. ----------
function openDeleteDialog(kind, id, itemId, name, extra) {
    var dlg = document.getElementById('deleteDialog');
    document.getElementById('delItemId').value = itemId;
    document.getElementById('delPass').value = '';
    document.getElementById('delPass').type = 'password';
    document.getElementById('delShow').textContent = 'Show';
    var msg = document.getElementById('delMessage');
    msg.textContent = '';
    var strong = document.createElement('strong');
    strong.textContent = name;
    if (kind === 'line') {                                   // extra = the dish's name
        document.getElementById('delAction').value = 'delete_line';
        document.getElementById('delLineId').value = id;
        document.getElementById('delTitle').textContent = 'Remove ingredient';
        msg.append('Remove ', strong, ' from the recipe of ' + extra + '?');
        document.getElementById('delDetail').textContent = 'That dish will stop checking stock for this ingredient. The ingredient itself stays in inventory.';
        document.getElementById('delConfirm').textContent = 'Remove ingredient';
    } else {                                                 // extra = how many ingredient lines it has
        document.getElementById('delAction').value = 'delete_item';
        document.getElementById('delLineId').value = '';
        document.getElementById('delTitle').textContent = 'Delete menu item';
        msg.append('Delete ', strong, '?');
        document.getElementById('delDetail').textContent = 'This also removes its ' + extra + ' ingredient line' + (extra === 1 ? '' : 's') + '. Past orders keep their history. This cannot be undone.';
        document.getElementById('delConfirm').textContent = 'Delete menu item';
    }
    dlg.showModal();
    document.getElementById('delPass').focus();
}
(function () {
    var dlg = document.getElementById('deleteDialog');
    if (!dlg) return;
    dlg.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
    // Backdrop click-to-close and Escape key shortcut removed intentionally so modals require explicit action buttons.
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && dlg.open) {
            e.preventDefault(); // Prevent native ESC from closing the delete dialog
        }
    });
    document.getElementById('delShow').addEventListener('click', function () {
        var p = document.getElementById('delPass'), show = p.type === 'password';
        p.type = show ? 'text' : 'password';
        this.textContent = show ? 'Hide' : 'Show';
    });
})();
</script>
<?php endif; ?>