<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('recipes');

// Where to return after a form post: back to the same dish, still open.
function recipes_url(int $openId = 0, string $show = ''): string {
    return 'index.php?page=recipes' . ($show !== '' ? '&show=' . rawurlencode($show) : '') . ($openId ? '&open=' . $openId . '#item-' . $openId : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $back = recipes_url();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add_item':
                $name = trim($_POST['name'] ?? '');
                $cat  = trim($_POST['category'] ?? '') ?: 'Main';
                if ($name === '') { flash('error', 'Enter a menu item name.'); break; }
                $pdo->prepare('INSERT INTO menu_items (name, category) VALUES (:n, :c)')->execute([':n' => $name, ':c' => $cat]);
                flash('success', "$name added. Now add its ingredients.");
                $back = recipes_url((int) $pdo->lastInsertId());
                break;

            case 'save_line':
                $m           = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                $ingredients = (array) ($_POST['ingredient_id'] ?? []);
                $quantities  = (array) ($_POST['quantity_required'] ?? []);
                $units       = (array) ($_POST['unit'] ?? []);
                if (!$m) { flash('error', 'Invalid menu item.'); break; }
                $back = recipes_url($m);

                $stockUnits = [];
                foreach ($pdo->query('SELECT id, unit FROM ingredients') as $r) $stockUnits[(int) $r['id']] = $r['unit'];

                // Validate every row first: nothing is saved unless all rows are right.
                $clean = [];
                $error = null;
                foreach ($ingredients as $index => $ingId) {
                    $i = filter_var($ingId, FILTER_VALIDATE_INT);
                    $q = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_FLOAT);
                    $u = trim((string) ($units[$index] ?? ''));
                    if (!$i || !isset($stockUnits[$i])) { $error = 'Choose an ingredient on every row.'; break; }
                    if ($q === false || $q <= 0)       { $error = 'Enter a quantity above 0 on every row.'; break; }
                    // pcs ingredient -> pcs only; kg ingredient -> kg or g; L ingredient -> L or mL.
                    $ok = array_values(array_filter(allowed_recipe_units($stockUnits[$i]), fn($a) => strtolower($a) === strtolower($u)));
                    if (!$ok) { $error = 'That unit cannot be used for this ingredient. Allowed: ' . implode(' or ', allowed_recipe_units($stockUnits[$i])) . '.'; break; }
                    $clean[$i] = [round($q, 3), $ok[0]];          // same ingredient twice: the last row wins
                }
                if ($error === null && !$clean) $error = 'Add at least one ingredient and quantity.';
                if ($error !== null) { flash('error', $error); break; }

                run_tx($pdo, function (PDO $pdo) use ($m, $clean) {
                    $stmt = $pdo->prepare('INSERT INTO recipe_ingredients (menu_item_id, ingredient_id, quantity_required, unit) VALUES (:m, :i, :q, :u)
                                           ON DUPLICATE KEY UPDATE quantity_required = VALUES(quantity_required), unit = VALUES(unit)');
                    foreach ($clean as $i => [$q, $u]) $stmt->execute([':m' => $m, ':i' => $i, ':q' => $q, ':u' => $u]);
                    return count($clean) === 1 ? 'Recipe ingredient saved.' : count($clean) . ' recipe ingredients saved.';
                });
                break;

            case 'delete_line':
                // Password verification for AJAX modal
                if (isset($_POST['verify_password'])) {
                    check_csrf();
                    $pwd = (string) ($_POST['verify_password'] ?? '');
                    $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
                    $u->execute([':id' => $userId]);
                    $hash = $u->fetchColumn();
                    if (!$hash || !password_verify($pwd, $hash)) {
                        flash('error', 'Incorrect password. Deletion cancelled.');
                        redirect(recipes_url());
                    }
                }

                $id = filter_var($_POST['line_id'] ?? null, FILTER_VALIDATE_INT);
                $m  = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
                if ($id) {
                    $stmt = $pdo->prepare('SELECT i.name FROM recipe_ingredients ri JOIN ingredients i ON i.id = ri.ingredient_id WHERE ri.id = :id');
                    $stmt->execute([':id' => $id]);
                    $iname = $stmt->fetchColumn();
                    $pdo->prepare('DELETE FROM recipe_ingredients WHERE id = :id')->execute([':id' => $id]);
                    if ($iname) log_activity('delete', "Removed ingredient from recipe: $iname (line ID: $id, menu item: $m)");
                }
                flash('success', 'Ingredient removed from the recipe.');
                $back = recipes_url($m);
                break;

            case 'delete_item':
                // Password verification for AJAX modal
                if (isset($_POST['verify_password'])) {
                    check_csrf();
                    $pwd = (string) ($_POST['verify_password'] ?? '');
                    $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
                    $u->execute([':id' => $userId]);
                    $hash = $u->fetchColumn();
                    if (!$hash || !password_verify($pwd, $hash)) {
                        flash('error', 'Incorrect password. Deletion cancelled.');
                        redirect(recipes_url());
                    }
                }

                $id = filter_var($_POST['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                if ($id) {
                    $stmt = $pdo->prepare('SELECT name FROM menu_items WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    $iname = $stmt->fetchColumn();
                    $pdo->prepare('DELETE FROM menu_items WHERE id = :id')->execute([':id' => $id]);
                    if ($iname) log_activity('delete', "Deleted menu item: $iname (ID: $id)");
                }
                flash('success', 'Menu item deleted.');
                break;

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        flash('error', 'Could not save. That menu item may already exist.');
    }
    redirect($back);
}

$items       = $pdo->query('SELECT id, name, category FROM menu_items ORDER BY category, name')->fetchAll();
$ingredients = $pdo->query('SELECT id, name, unit FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name')->fetchAll();

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
if (!in_array($show, ['all', 'available', 'out'], true)) $show = 'all';
$visible = array_filter($items, fn($it) => $show === 'all' || $state[(int) $it['id']] === $show);
$openId  = (int) ($_GET['open'] ?? 0);

$tabs = [
    'all'       => ['All items',    count($items)],
    'available' => ['Available',    $counts['available']],
    'out'       => ['Out of stock', $counts['out']],
];

$pageTitle = 'Recipes and menu availability';
?>

<nav class="mb-4 flex flex-wrap items-center gap-2 text-sm" aria-label="Filter menu items">
    <?php foreach ($tabs as $key => [$label, $n]):
        $on = $show === $key; ?>
        <a href="index.php?page=recipes<?= $key === 'all' ? '' : '&show=' . $key ?>" <?= $on ? 'aria-current="page"' : '' ?>
           class="rounded border px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-orange-500 <?= $on ? 'border-slate-800 bg-slate-800 font-medium text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>">
            <?= e($label) ?> <span class="<?= $on ? 'text-slate-300' : 'text-slate-500' ?>">(<?= (int) $n ?>)</span>
        </a>
    <?php endforeach; ?>
    <?php if ($counts['norecipe'] > 0): ?>
        <span class="px-2 text-slate-500"><?= (int) $counts['norecipe'] ?> without a recipe</span>
    <?php endif; ?>
</nav>

<div class="space-y-3">
    <?php foreach ($visible as $it):
        $id  = (int) $it['id'];
        $rep = $report[$id];
        $ls  = $rep['ingredients'];
        $st  = $state[$id];
        $p   = $rep['max_portions'];
        $short = array_values(array_filter($ls, fn($l) => $l['is_short'])); ?>
        <details id="item-<?= $id ?>" class="rounded-lg border border-slate-200 bg-white" <?= ($st === 'out' || $id === $openId) ? 'open' : '' ?>>
            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3">
                <span><span class="font-semibold"><?= e($it['name']) ?></span> <span class="text-sm text-slate-500"><?= e($it['category']) ?></span></span>
                <span class="flex items-center gap-2">
                    <?php if ($p !== null): ?><span class="text-xs text-slate-500">Stock for <?= (int) $p ?> portion<?= $p === 1 ? '' : 's' ?></span><?php endif; ?>
                    <?php if ($st === 'out'): ?><?= badge('Out of stock', 'red') ?>
                    <?php elseif ($st === 'available'): ?><?= badge('Available', 'green') ?>
                    <?php else: ?><?= badge('No recipe: stock not checked', 'gray') ?><?php endif; ?>
                </span>
            </summary>
            <div class="space-y-4 border-t border-slate-200 p-4">
                <?php if ($st === 'out'): ?>
                    <p class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        Cannot be ordered. Short on:
                        <?= e(implode(', ', array_map(
                            fn($l) => $l['unit_ok']
                                ? $l['name'] . ' (needs ' . num($l['per_portion']) . ' ' . $l['recipe_unit'] . ' per portion, have ' . num($l['in_stock']) . ' ' . $l['unit'] . ')'
                                : $l['name'] . ' (recipe unit ' . $l['recipe_unit'] . ' does not match stock unit ' . $l['unit'] . ': remove the line and add it again)',
                            $short))) ?>.
                    </p>
                <?php endif; ?>

                <?php if ($ls): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="py-2 font-medium">Ingredient</th><th class="font-medium">Per portion</th><th class="font-medium">In stock</th><th class="font-medium">Status</th><th></th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($ls as $l): $alt = alt_unit_text($l['in_stock'], $l['unit']); ?>
                                    <tr>
                                        <td class="py-2"><?= e($l['name']) ?></td>
                                        <td class="tabular-nums"><?= e(num($l['per_portion'])) ?> <?= e($l['recipe_unit']) ?></td>
                                        <td class="tabular-nums <?= $l['is_short'] ? 'text-red-700' : '' ?>">
                                            <?= e(num($l['in_stock'])) ?> <?= e($l['unit']) ?>
                                            <?php if ($alt): ?><span class="text-xs text-slate-400">(<?= e($alt) ?>)</span><?php endif; ?>
                                        </td>
                                        <td><?= $l['is_out'] ? badge('Out of stock', 'red') : ($l['is_short'] ? badge($l['unit_ok'] ? 'Not enough' : 'Unit mismatch', 'amber') : badge('In stock', 'green')) ?></td>
                                        <td class="text-right">
                                            <button type="button" class="text-slate-600 underline hover:text-red-700" onclick="openDeleteModal('<?= e(addslashes($l['name'])) ?>', <?= (int) $l['line_id'] ?>, 'recipe_line')">Remove</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Add ingredients below. Until then, stock is not checked for this dish.</p>
                <?php endif; ?>

                <!-- Add / update ingredients. Saving an ingredient that is already in the recipe updates its quantity and unit. -->
                <form method="POST" action="<?= e(recipes_url()) ?>" class="space-y-3 border-t border-slate-100 pt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_line">
                    <input type="hidden" name="menu_item_id" value="<?= $id ?>">

                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Add Ingredients</p>

                    <div id="ingredient-rows-<?= $id ?>" class="space-y-2">
                        <div class="flex flex-wrap items-end gap-3 ingredient-row">
                            <label class="text-sm font-medium flex-1 min-w-[200px]">Ingredient
                                <select name="ingredient_id[]" required class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 font-normal">
                                    <option value="" disabled selected>Select ingredient...</option>
                                    <?php foreach ($ingredients as $i): ?>
                                        <option value="<?= (int) $i['id'] ?>" data-unit="<?= e($i['unit']) ?>"><?= e($i['name']) ?> (stock in <?= e($i['unit']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="text-sm font-medium">Quantity per portion
                                <input type="number" name="quantity_required[]" step="0.001" min="0.001" required class="mt-1 block w-32 rounded border border-slate-300 px-3 py-2 font-normal">
                            </label>
                            <label class="text-sm font-medium">Unit
                                <select name="unit[]" required class="mt-1 block w-28 rounded border border-slate-300 px-3 py-2 font-normal">
                                    <option value="" disabled selected>Unit</option>
                                </select>
                            </label>
                            <button type="button" onclick="removeIngredientRow(this)" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 hover:bg-red-100 hidden remove-row-btn" title="Remove this row">✕</button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <button type="button" onclick="addIngredientRow(<?= $id ?>)" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">+ Add another ingredient</button>
                        <button class="rounded bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Save ingredients</button>
                    </div>
                </form>

                <form method="POST" action="<?= e(recipes_url()) ?>" class="pt-2 border-t border-slate-100">
                    <button type="button" class="text-sm text-red-700 underline" onclick="openDeleteModal('<?= e(addslashes($it['name'])) ?> and its recipe', <?= $id ?>, 'menu_item')">Delete this menu item</button>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
    <?php if (!$items): ?>
        <p class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">No menu items yet. Add the first one below.</p>
    <?php elseif (!$visible): ?>
        <p class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">
            <?= $show === 'out' ? 'Nothing is out of stock right now.' : 'No menu items are fully available right now.' ?>
        </p>
    <?php endif; ?>
</div>

<section class="rounded-lg border border-slate-200 bg-white p-4 mt-6">
    <h2 class="mb-3 font-semibold">Add a menu item</h2>
    <form method="POST" action="<?= e(recipes_url()) ?>" class="flex flex-wrap items-end gap-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_item">
        <label class="text-sm font-medium">Name
            <input type="text" name="name" required maxlength="120" class="mt-1 block w-64 rounded border border-slate-300 px-3 py-2 font-normal">
        </label>
        <label class="text-sm font-medium">Category
            <input type="text" name="category" list="categories" maxlength="50" placeholder="Main" class="mt-1 block w-40 rounded border border-slate-300 px-3 py-2 font-normal">
            <datalist id="categories"><option>Starter</option><option>Main</option><option>Side</option><option>Dessert</option><option>Drink</option></datalist>
        </label>
        <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">Add menu item</button>
    </form>
</section>

<script>
// Units a recipe may use for an ingredient kept in a given stock unit (mirrors allowed_recipe_units() in PHP,
// which is what the server actually enforces): pcs -> pcs only, kg or g -> kg/g, L or mL -> L/mL.
function unitsFor(stockUnit) {
    const k = (stockUnit || '').toLowerCase();
    if (k === 'kg' || k === 'g')  return ['kg', 'g'];
    if (k === 'l'  || k === 'ml') return ['L', 'mL'];
    return [stockUnit];
}
function syncUnits(row) {
    const ing  = row.querySelector('select[name="ingredient_id[]"]');
    const unit = row.querySelector('select[name="unit[]"]');
    const stockUnit = ing.options[ing.selectedIndex] ? ing.options[ing.selectedIndex].dataset.unit : '';
    unit.innerHTML = '';
    if (!stockUnit) { unit.innerHTML = '<option value="" disabled selected>Unit</option>'; return; }
    unitsFor(stockUnit).forEach(function (u) {
        const o = document.createElement('option');
        o.value = u; o.textContent = u;
        if (u.toLowerCase() === stockUnit.toLowerCase()) o.selected = true;   // default = the stock unit
        unit.appendChild(o);
    });
}
document.addEventListener('change', function (e) {
    if (e.target.matches('select[name="ingredient_id[]"]')) syncUnits(e.target.closest('.ingredient-row'));
});
function updateRemoveButtons(container) {
    const rows = container.querySelectorAll('.ingredient-row');
    rows.forEach(function (row) {
        row.querySelector('.remove-row-btn').classList.toggle('hidden', rows.length < 2);
    });
}
function addIngredientRow(itemId) {
    const container = document.getElementById('ingredient-rows-' + itemId);
    const newRow = container.querySelector('.ingredient-row').cloneNode(true);
    newRow.querySelector('select[name="ingredient_id[]"]').selectedIndex = 0;
    newRow.querySelector('input[type="number"]').value = '';
    syncUnits(newRow);
    container.appendChild(newRow);
    updateRemoveButtons(container);
}
function removeIngredientRow(btn) {
    const container = btn.closest('[id^="ingredient-rows-"]');
    btn.closest('.ingredient-row').remove();
    updateRemoveButtons(container);
}

// Password confirmation modal for delete actions
let deleteModalTarget = null;
let deleteModalType = null;

function openDeleteModal(itemName, itemId, type = 'menu_item') {
    deleteModalTarget = itemId;
    deleteModalType = type;
    document.getElementById('deleteModalItemName').textContent = itemName;
    document.getElementById('deleteModalItemId').value = itemId;
    document.getElementById('deleteModalType').value = type;
    document.getElementById('deleteModalPassword').value = '';
    document.getElementById('deleteModalError').textContent = '';
    document.getElementById('deleteModalError').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
    setTimeout(() => document.getElementById('deleteModalPassword').focus(), 50);
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    deleteModalTarget = null;
    deleteModalType = null;
}

function submitDeleteModal() {
    const password = document.getElementById('deleteModalPassword').value;
    const itemId = document.getElementById('deleteModalItemId').value;
    const type = document.getElementById('deleteModalType').value;

    if (!password) {
        document.getElementById('deleteModalError').textContent = 'Please enter your password.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        return;
    }

    // Disable button and show loading
    const btn = document.getElementById('deleteModalConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Verifying...';

    const formData = new FormData();
    formData.append('csrf', document.querySelector('input[name="csrf"]').value);
    formData.append('verify_password', password);

    let action = '';
    if (type === 'menu_item') {
        action = 'delete_item';
        formData.append('action', action);
        formData.append('menu_item_id', itemId);
    } else if (type === 'recipe_line') {
        action = 'delete_line';
        formData.append('action', action);
        formData.append('line_id', itemId);
    }

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        // Check if the response indicates success (redirect) or error
        if (html.includes('flash-success') || html.includes('success') || !html.includes('flash-error')) {
            // Reload page to show updated list
            window.location.reload();
        } else {
            // Extract error message from response
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const errorEl = doc.querySelector('[role="alert"]');
            if (errorEl) {
                document.getElementById('deleteModalError').textContent = errorEl.textContent.trim();
            } else {
                document.getElementById('deleteModalError').textContent = 'Password verification failed.';
            }
            document.getElementById('deleteModalError').classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Delete';
        }
    })
    .catch(() => {
        document.getElementById('deleteModalError').textContent = 'An error occurred. Please try again.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Delete';
    });
}

// Handle Enter key in password field
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('deleteModalPassword');
    if (passwordInput) {
        passwordInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitDeleteModal();
            }
        });
    }

    // Close modal on backdrop click
    document.getElementById('deleteModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeleteModal();
    });
});
</script>

<!-- Password Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-slate-200">
            <h3 class="text-lg font-semibold text-slate-900">Confirm Deletion</h3>
        </div>
        <div class="px-6 py-4 space-y-4">
            <p class="text-slate-700">Are you sure you want to delete <strong id="deleteModalItemName"></strong>?</p>
            <p class="text-xs text-slate-500">This action cannot be undone. Please enter your password to confirm.</p>
            <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" placeholder="Your password" autocomplete="current-password">
            <input type="hidden" id="deleteModalItemId">
            <input type="hidden" id="deleteModalType">
            <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>
