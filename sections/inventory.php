<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('inventory');

$action = $_GET['action'] ?? 'list';

// True when changing the unit would change what recorded quantities mean.
function ingredient_unit_locked(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT
            (SELECT current_stock FROM ingredients WHERE id = :a1) <> 0
         OR (SELECT COUNT(*) FROM stock_receipts   WHERE ingredient_id = :a2) > 0
         OR (SELECT COUNT(*) FROM recipe_ingredients WHERE ingredient_id = :a3) > 0
         OR (SELECT COUNT(*) FROM usage_logs       WHERE ingredient_id = :a4) > 0
         OR (SELECT COUNT(*) FROM waste_logs       WHERE ingredient_id = :a5) > 0 AS locked');
    $s->execute([':a1' => $id, ':a2' => $id, ':a3' => $id, ':a4' => $id, ':a5' => $id]);
    return (bool) $s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['form_action'] ?? '') {
            case 'add_ingredient':
                $name      = trim($_POST['name'] ?? '');
                $sku       = trim($_POST['sku'] ?? '') ?: null;
                $unit      = unit_label($_POST['unit'] ?? 'kg');
                $reorder   = filter_var($_POST['reorder_level'] ?? 0, FILTER_VALIDATE_FLOAT);
                $alertDays = filter_var($_POST['expiry_alert_days'] ?? 3, FILTER_VALIDATE_INT) ?: 3;

                // Category Logic
                $catSelect = trim($_POST['category_select'] ?? '');
                $catNew    = trim($_POST['category_new'] ?? '');
                $category  = ($catSelect === '__NEW__' || $catSelect === '') ? $catNew : $catSelect;
                if ($category === '') $category = 'Uncategorized';

                // Storage Logic
                $storeSelect = trim($_POST['storage_select'] ?? '');
                $storeNew    = trim($_POST['storage_location_new'] ?? '');
                $storageLoc  = ($storeSelect === '__NEW__' || $storeSelect === '') ? $storeNew : $storeSelect;
                if ($storageLoc === '') $storageLoc = 'General';

                if ($name === '' || $reorder === false || $reorder < 0) {
                    flash('error', 'Enter a valid ingredient name and reorder level.');
                    break;
                }
                if (!in_array($unit, STOCK_UNITS, true)) {
                    flash('error', 'Choose a unit from the list.');
                    break;
                }

                $pdo->prepare('INSERT INTO ingredients (name, sku, category, unit, reorder_level, expiry_alert_days, storage_location)
                               VALUES (:name, :sku, :cat, :unit, :reorder, :alert, :storage)')
                    ->execute([
                        ':name'    => $name,
                        ':sku'     => $sku,
                        ':cat'     => $category,
                        ':unit'    => $unit,
                        ':reorder' => round($reorder, 3),
                        ':alert'   => $alertDays,
                        ':storage' => $storageLoc
                    ]);
                log_activity('create', "Added ingredient: $name (unit: $unit, category: $category, reorder: $reorder)");
                flash('success', "$name added successfully.");
                redirect('index.php?page=inventory');
                break;

            case 'edit_ingredient':
                $id        = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $name      = trim($_POST['name'] ?? '');
                $sku       = trim($_POST['sku'] ?? '') ?: null;
                $unit      = unit_label($_POST['unit'] ?? 'kg');
                $reorder   = filter_var($_POST['reorder_level'] ?? 0, FILTER_VALIDATE_FLOAT);
                $alertDays = filter_var($_POST['expiry_alert_days'] ?? 3, FILTER_VALIDATE_INT) ?: 3;

                // Category Logic
                $catSelect = trim($_POST['category_select'] ?? '');
                $catNew    = trim($_POST['category_new'] ?? '');
                $category  = ($catSelect === '__NEW__' || $catSelect === '') ? $catNew : $catSelect;
                if ($category === '') $category = 'Uncategorized';

                // Storage Logic
                $storeSelect = trim($_POST['storage_select'] ?? '');
                $storeNew    = trim($_POST['storage_location_new'] ?? '');
                $storageLoc  = ($storeSelect === '__NEW__' || $storeSelect === '') ? $storeNew : $storeSelect;
                if ($storageLoc === '') $storageLoc = 'General';

                if (!$id || $name === '' || $reorder === false || $reorder < 0 || !in_array($unit, STOCK_UNITS, true)) {
                    flash('error', 'Invalid input parameters for editing.');
                    break;
                }

                // The unit cannot change once quantities exist: 10 kg would silently become 10 g.
                $cur = $pdo->prepare('SELECT unit FROM ingredients WHERE id = :id');
                $cur->execute([':id' => $id]);
                $currentUnit = $cur->fetchColumn();
                if ($currentUnit === false) {
                    flash('error', 'Ingredient not found.');
                    break;
                }
                if ($currentUnit !== $unit && ingredient_unit_locked($pdo, $id)) {
                    flash('error', 'The unit of ' . $name . ' cannot be changed because it already has stock, batches, or is used in recipes. Add a new ingredient with the other unit instead.');
                    break;
                }

                $pdo->prepare('UPDATE ingredients SET name = :name, sku = :sku, category = :cat, unit = :unit, reorder_level = :reorder, expiry_alert_days = :alert, storage_location = :storage WHERE id = :id')
                    ->execute([
                        ':name'    => $name,
                        ':sku'     => $sku,
                        ':cat'     => $category,
                        ':unit'    => $unit,
                        ':reorder' => round($reorder, 3),
                        ':alert'   => $alertDays,
                        ':storage' => $storageLoc,
                        ':id'      => $id
                    ]);
                log_activity('update', "Updated ingredient: $name (ID: $id)");
                flash('success', "$name updated successfully.");
                redirect('index.php?page=inventory');
                break;

            case 'delete_ingredient':
                // Password verification for AJAX modal
                if (isset($_POST['verify_password'])) {
                    check_csrf();
                    $pwd = (string) ($_POST['verify_password'] ?? '');
                    $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
                    $u->execute([':id' => $userId]);
                    $hash = $u->fetchColumn();
                    if (!$hash || !password_verify($pwd, $hash)) {
                        flash('error', 'Incorrect password. Deletion cancelled.');
                        redirect('index.php?page=inventory');
                    }
                }

                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) {
                    flash('error', 'Invalid ingredient ID.');
                    break;
                }

                $check = $pdo->prepare('SELECT name, current_stock FROM ingredients WHERE id = :id');
                $check->execute([':id' => $id]);
                $ing = $check->fetch();

                if (!$ing) {
                    flash('error', 'Ingredient not found.');
                    break;
                }

                if ((float)$ing['current_stock'] > 0) {
                    flash('error', 'Unable to delete ' . $ing['name'] . ' because there is still stock on hand (' . num($ing['current_stock']) . ').');
                    break;
                }

                $fkCheck = $pdo->prepare('
                    SELECT
                        (SELECT COUNT(*) FROM recipe_ingredients WHERE ingredient_id = :id1) +
                        (SELECT COUNT(*) FROM stock_receipts WHERE ingredient_id = :id2) +
                        (SELECT COUNT(*) FROM usage_logs WHERE ingredient_id = :id3) +
                        (SELECT COUNT(*) FROM waste_logs WHERE ingredient_id = :id4) +
                        (SELECT COUNT(*) FROM stock_movements WHERE ingredient_id = :id5) AS total_links
                ');
                $fkCheck->execute([':id1' => $id, ':id2' => $id, ':id3' => $id, ':id4' => $id, ':id5' => $id]);

                if ((int)$fkCheck->fetchColumn() > 0) {
                    flash('error', 'Unable to delete ' . $ing['name'] . ' because it already has recipes, stock receipts, or stock history.');
                } else {
                    $pdo->prepare('DELETE FROM ingredients WHERE id = :id')->execute([':id' => $id]);
                    log_activity('delete', "Deleted ingredient: {$ing['name']} (ID: $id)");
                    flash('success', $ing['name'] . ' deleted successfully.');
                }
                redirect('index.php?page=inventory');
                break;

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        flash('error', 'Could not save. An ingredient with that name or SKU may already exist.');
    }
    // Post/redirect/get: a failed save returns to the same screen (add, edit or list) and a refresh will not re-post.
    redirect('index.php?' . http_build_query(array_intersect_key($_GET, array_flip(['page', 'action', 'id']))));
}

// Compute Dashboard Metrics using current_stock and active ingredients
$totalIngredients = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL')->fetchColumn();
$lowStockCount    = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND current_stock <= reorder_level AND current_stock > 0')->fetchColumn();
$outOfStockCount  = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND current_stock <= 0')->fetchColumn();
$expiringCount    = (int) $pdo->query('SELECT COUNT(DISTINCT r.ingredient_id) FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id WHERE i.is_active = 1 AND i.deleted_at IS NULL AND r.quantity_remaining > 0 AND r.expiry_date IS NOT NULL AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL i.expiry_alert_days DAY)')->fetchColumn();

// Fetch distinct categories and storages for the dropdowns
$categories = $pdo->query('SELECT DISTINCT category FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND category IS NOT NULL ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
$storages   = $pdo->query('SELECT DISTINCT storage_location FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND storage_location IS NOT NULL AND CHAR_LENGTH(storage_location) > 0 ORDER BY storage_location')->fetchAll(PDO::FETCH_COLUMN);

// Filtering & Search
$q           = trim($_GET['q'] ?? '');
$catFilter   = trim($_GET['category'] ?? 'All');
$stockFilter = trim($_GET['stock_status'] ?? 'All');

$sql = 'SELECT * FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL';
$params = [];

if ($q !== '') {
    // One placeholder per use: with real prepared statements a named parameter may appear only once.
    // % and _ typed by the user are escaped so they match literally.
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $sql .= ' AND (name LIKE :q1 OR sku LIKE :q2 OR category LIKE :q3)';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}

if (!in_array($stockFilter, ['All', 'Low', 'Out', 'OK'], true)) $stockFilter = 'All';
if ($catFilter !== 'All') {
    $sql .= ' AND category = :cat';
    $params[':cat'] = $catFilter;
}

if ($stockFilter === 'Low') {
    $sql .= ' AND current_stock <= reorder_level AND current_stock > 0';
} elseif ($stockFilter === 'Out') {
    $sql .= ' AND current_stock <= 0';
} elseif ($stockFilter === 'OK') {
    $sql .= ' AND current_stock > reorder_level';
}

$sql .= ' ORDER BY name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Inventory';
?>

<?php if ($action === 'new'): ?>
    <!-- NEW INGREDIENT FORM VIEW -->
    <?php $pageTitle = 'Add Ingredient'; ?>

    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" class="space-y-6">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_ingredient">

            <div>
                <label class="block text-sm font-medium text-slate-700">Name</label>
                <input type="text" name="name" required maxlength="100" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-orange-500 focus:ring-orange-500">
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">SKU <span class="font-normal text-slate-400">(optional)</span></label>
                    <input type="text" name="sku" maxlength="50" placeholder="e.g. ING-001" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Category</label>
                    <?php $hasCategories = !empty($categories); ?>
                    <select name="category_select" onchange="const inp = document.getElementById('new_cat_input'); if(this.value === '__NEW__'){ inp.classList.remove('hidden'); inp.disabled = false; inp.focus(); } else { inp.classList.add('hidden'); inp.disabled = true; }" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                        <?php if ($hasCategories): ?>
                            <option value="" disabled selected>Select category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <option value="__NEW__" <?= !$hasCategories ? 'selected' : '' ?> class="font-bold text-orange-600">+ Add New Category</option>
                    </select>
                    <input type="text" name="category_new" id="new_cat_input" maxlength="50" placeholder="Type new category..." class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-orange-500 focus:ring-orange-500 <?= $hasCategories ? 'hidden' : '' ?>" <?= $hasCategories ? 'disabled' : '' ?> required>
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Unit</label>
                    <select name="unit" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                        <?php foreach (STOCK_UNITS as $u): ?>
                            <option value="<?= e($u) ?>"><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Reorder at</label>
                    <input type="number" name="reorder_level" step="0.001" min="0" value="0" required class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Warn before expiry (days)</label>
                    <input type="number" name="expiry_alert_days" min="1" value="3" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Usual storage</label>
                    <?php $hasStorages = !empty($storages); ?>
                    <select name="storage_select" onchange="const inp = document.getElementById('new_storage_input'); if(this.value === '__NEW__'){ inp.classList.remove('hidden'); inp.disabled = false; inp.focus(); } else { inp.classList.add('hidden'); inp.disabled = true; }" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                        <?php if ($hasStorages): ?>
                            <option value="" disabled selected>Select storage...</option>
                            <?php foreach ($storages as $loc): ?>
                                <option value="<?= e($loc) ?>"><?= e($loc) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <option value="__NEW__" <?= !$hasStorages ? 'selected' : '' ?> class="font-bold text-orange-600">+ Add New Storage</option>
                    </select>
                    <input type="text" name="storage_location_new" id="new_storage_input" maxlength="80" placeholder="Type new storage location..." class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-orange-500 focus:ring-orange-500 <?= $hasStorages ? 'hidden' : '' ?>" <?= $hasStorages ? 'disabled' : '' ?> required>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <a href="index.php?page=inventory" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                <button class="rounded-md bg-slate-900 px-6 py-2 text-sm font-semibold text-white hover:bg-slate-800">Add ingredient</button>
            </div>
        </form>
    </div>

<?php elseif ($action === 'edit' && isset($_GET['id'])): ?>
    <!-- EDIT INGREDIENT FORM VIEW -->
    <?php 
        $editId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        $editStmt = $pdo->prepare('SELECT * FROM ingredients WHERE id = :id AND deleted_at IS NULL');
        $editStmt->execute([':id' => $editId]);
        $itemToEdit = $editStmt->fetch();
        if (!$itemToEdit) {
            flash('error', 'Ingredient not found.');
            redirect('index.php?page=inventory');
        }
        $pageTitle = 'Edit Ingredient: ' . $itemToEdit['name'];
    ?>

    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" class="space-y-6">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="edit_ingredient">
            <input type="hidden" name="id" value="<?= (int)$itemToEdit['id'] ?>">

            <div>
                <label class="block text-sm font-medium text-slate-700">Name</label>
                <input type="text" name="name" value="<?= e($itemToEdit['name']) ?>" required maxlength="100" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-orange-500 focus:ring-orange-500">
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">SKU <span class="font-normal text-slate-400">(optional)</span></label>
                    <input type="text" name="sku" value="<?= e($itemToEdit['sku'] ?? '') ?>" maxlength="50" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Category</label>
                    <select name="category_select" onchange="const inp = document.getElementById('edit_cat_input'); if(this.value === '__NEW__'){ inp.classList.remove('hidden'); inp.disabled = false; inp.focus(); } else { inp.classList.add('hidden'); inp.disabled = true; }" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                        <?php 
                        $hasCat = false;
                        foreach ($categories as $cat): 
                            $isSelected = ($itemToEdit['category'] === $cat);
                            if ($isSelected) $hasCat = true;
                        ?>
                            <option value="<?= e($cat) ?>" <?= $isSelected ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                        <?php if (!$hasCat && !empty($itemToEdit['category'])): ?>
                            <option value="<?= e($itemToEdit['category']) ?>" selected><?= e($itemToEdit['category']) ?></option>
                        <?php endif; ?>
                        <option value="__NEW__" class="font-bold text-orange-600">+ Add New Category</option>
                    </select>
                    <input type="text" name="category_new" id="edit_cat_input" maxlength="50" placeholder="Type new category..." class="mt-2 hidden w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-orange-500 focus:ring-orange-500" disabled required>
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Unit</label>
                    <?php $unitLocked = ingredient_unit_locked($pdo, (int) $itemToEdit['id']); ?>
                    <select name="unit" <?= $unitLocked ? 'disabled' : '' ?> class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm <?= $unitLocked ? 'bg-slate-100 text-slate-500' : '' ?>">
                        <?php foreach (STOCK_UNITS as $u): ?>
                            <option value="<?= e($u) ?>" <?= unit_key($itemToEdit['unit']) === unit_key($u) ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($unitLocked): ?>
                        <input type="hidden" name="unit" value="<?= e(unit_label($itemToEdit['unit'])) ?>">
                        <p class="mt-1 text-xs text-slate-500">Locked: this ingredient already has stock, batches or recipes.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Reorder at</label>
                    <input type="number" name="reorder_level" value="<?= e(num($itemToEdit['reorder_level'])) ?>" step="0.001" min="0" required class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Warn before expiry (days)</label>
                    <input type="number" name="expiry_alert_days" value="<?= (int)$itemToEdit['expiry_alert_days'] ?>" min="1" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Usual storage</label>
                    <select name="storage_select" onchange="const inp = document.getElementById('edit_storage_input'); if(this.value === '__NEW__'){ inp.classList.remove('hidden'); inp.disabled = false; inp.focus(); } else { inp.classList.add('hidden'); inp.disabled = true; }" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm">
                        <?php 
                        $hasStorage = false;
                        foreach ($storages as $loc): 
                            $isSelected = ($itemToEdit['storage_location'] === $loc);
                            if ($isSelected) $hasStorage = true;
                        ?>
                            <option value="<?= e($loc) ?>" <?= $isSelected ? 'selected' : '' ?>><?= e($loc) ?></option>
                        <?php endforeach; ?>
                        <?php if (!$hasStorage && !empty($itemToEdit['storage_location'])): ?>
                            <option value="<?= e($itemToEdit['storage_location']) ?>" selected><?= e($itemToEdit['storage_location']) ?></option>
                        <?php endif; ?>
                        <option value="__NEW__" class="font-bold text-orange-600">+ Add New Storage</option>
                    </select>
                    <input type="text" name="storage_location_new" id="edit_storage_input" maxlength="80" placeholder="Type new storage location..." class="mt-2 hidden w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-orange-500 focus:ring-orange-500" disabled required>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <a href="index.php?page=inventory" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                <button class="rounded-md bg-slate-900 px-6 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save changes</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- MAIN INVENTORY LIST VIEW -->
    
    <!-- Top Stat Cards -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Ingredients</p>
            <p class="mt-1 text-3xl font-black text-slate-900"><?= $totalIngredients ?></p>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-amber-700">Low Stock</p>
            <p class="mt-1 text-3xl font-black text-amber-900"><?= $lowStockCount ?></p>
        </div>
        <div class="rounded-lg border border-red-200 bg-red-50/50 p-4 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-red-700">Out of Stock</p>
            <p class="mt-1 text-3xl font-black text-red-900"><?= $outOfStockCount ?></p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Expired / Expiring</p>
            <p class="mt-1 text-3xl font-black text-slate-900"><?= $expiringCount ?></p>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <form id="filterForm" method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="inventory">
        
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Search</label>
            <input type="search" name="q" id="searchInput" value="<?= e($q) ?>" placeholder="Name, SKU or category..." oninput="debounceSubmit()" autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
        </div>

        <div class="w-40">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Category</label>
            <select name="category" onchange="document.getElementById('filterForm').submit()" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                <option value="All">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="w-40">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Stock</label>
            <select name="stock_status" onchange="document.getElementById('filterForm').submit()" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                <option value="All">All Status</option>
                <option value="Low" <?= $stockFilter === 'Low' ? 'selected' : '' ?>>Low stock</option>
                <option value="Out" <?= $stockFilter === 'Out' ? 'selected' : '' ?>>Out of stock</option>
                <option value="OK" <?= $stockFilter === 'OK' ? 'selected' : '' ?>>OK</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Search</button>
            <?php if ($q !== '' || $catFilter !== 'All' || $stockFilter !== 'All'): ?>
                <a href="index.php?page=inventory" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Ingredients Table Section -->
    <div class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <div>
                <h2 class="font-bold text-slate-900 uppercase tracking-wide text-sm">All Ingredients</h2>
                <p class="text-xs text-slate-500"><?= count($rows) ?> total</p>
            </div>
            <a href="index.php?page=inventory&action=new" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition-colors shadow-sm">+ New Ingredient</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Ingredient</th>
                        <th class="px-6 py-3">Category</th>
                        <th class="px-6 py-3">On Hand</th>
                        <th class="px-6 py-3">Reorder At</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $r):
                        $out = (float) $r['current_stock'] <= 0;
                        $low = (float) $r['current_stock'] <= (float) $r['reorder_level'];
                    ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-900"><?= e($r['name']) ?></p>
                                <?php if (!empty($r['sku'])): ?>
                                    <p class="text-xs text-slate-500">SKU: <?= e($r['sku']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-slate-600"><?= e($r['category']) ?></td>
                            <td class="px-6 py-4 font-black tabular-nums text-slate-900"><?= e(num($r['current_stock'])) ?> <span class="font-normal text-xs text-slate-500"><?= e($r['unit']) ?></span>
                                <?php if ($alt = alt_unit_text($r['current_stock'], $r['unit'])): ?><span class="block text-xs font-normal text-slate-400"><?= e($alt) ?></span><?php endif; ?></td>
                            <td class="px-6 py-4 tabular-nums text-slate-700"><?= e(num($r['reorder_level'])) ?> <span class="text-xs text-slate-500"><?= e($r['unit']) ?></span></td>
                            <td class="px-6 py-4">
                                <?= $out ? badge('Out of stock', 'red') : ($low ? badge('Low', 'amber') : badge('OK', 'green')) ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                <a href="index.php?page=receiving&ingredient=<?= (int) $r['id'] ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-sm">Receive / Batches</a>
                                <a href="index.php?page=inventory&action=edit&id=<?= (int) $r['id'] ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-sm">Edit</a>
                                <?php if ($_SESSION['role'] === ROLE_ADMIN): ?>
                                    <button type="button" class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors shadow-sm" onclick="openDeleteModal('<?= e(addslashes($r['name'])) ?>', <?= (int)$r['id'] ?>)">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">No ingredients found matching your filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
    // Live search: submits 400 ms after you stop typing, then puts the cursor back at the end of the box.
    let debounceTimer;
    function debounceSubmit() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 400);
    }
    (function () {
        const box = document.getElementById('searchInput');
        if (box && box.value !== '' && new URLSearchParams(location.search).has('q')) {
            box.focus();
            box.setSelectionRange(box.value.length, box.value.length);
        }
    })();

    // Password confirmation modal for delete actions
    let deleteModalTarget = null;
    let deleteModalType = null;

    function openDeleteModal(itemName, itemId, type = 'ingredient') {
        deleteModalTarget = itemId;
        deleteModalType = type;
        document.getElementById('deleteModalItemName').textContent = itemName;
        document.getElementById('deleteModalItemId').value = itemId;
        document.getElementById('deleteModalType').value = type;
        document.getElementById('deleteModalPassword').value = '';
        document.getElementById('deleteModalError').textContent = '';
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
        if (type === 'ingredient') {
            action = 'delete_ingredient';
            formData.append('form_action', action);
            formData.append('id', itemId);
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
                btn.disabled = false;
                btn.textContent = 'Delete';
            }
        })
        .catch(() => {
            document.getElementById('deleteModalError').textContent = 'An error occurred. Please try again.';
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