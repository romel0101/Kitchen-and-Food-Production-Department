<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
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

                $pdo->prepare('INSERT INTO ingredients (name, sku, category, unit, reorder_level, storage_location)
                               VALUES (:name, :sku, :cat, :unit, :reorder, :storage)')
                    ->execute([
                        ':name'    => $name,
                        ':sku'     => $sku,
                        ':cat'     => $category,
                        ':unit'    => $unit,
                        ':reorder' => round($reorder, 3),
                        ':storage' => $storageLoc
                    ]);
                log_activity('create', "Added ingredient: $name (unit: $unit, category: $category, reorder: $reorder)");
                flash('success', "$name added successfully.");
                redirect('index?page=inventory');
                break;

            case 'edit_ingredient':
                $id        = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $name      = trim($_POST['name'] ?? '');
                $sku       = trim($_POST['sku'] ?? '') ?: null;
                $unit      = unit_label($_POST['unit'] ?? 'kg');
                $reorder   = filter_var($_POST['reorder_level'] ?? 0, FILTER_VALIDATE_FLOAT);

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

                $pdo->prepare('UPDATE ingredients SET name = :name, sku = :sku, category = :cat, unit = :unit, reorder_level = :reorder, storage_location = :storage WHERE id = :id')
                    ->execute([
                        ':name'    => $name,
                        ':sku'     => $sku,
                        ':cat'     => $category,
                        ':unit'    => $unit,
                        ':reorder' => round($reorder, 3),
                        ':storage' => $storageLoc,
                        ':id'      => $id
                    ]);
                log_activity('update', "Updated ingredient: $name (ID: $id)");
                flash('success', "$name updated successfully.");
                redirect('index?page=inventory');
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
                        redirect('index?page=inventory');
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
                redirect('index?page=inventory');
                break;

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        flash('error', 'Could not save. An ingredient with that name or SKU may already exist.');
    }
    redirect('index?' . http_build_query(array_intersect_key($_GET, array_flip(['page', 'action', 'id']))));
}

// Compute Dashboard Metrics using current_stock and active ingredients
$totalIngredients = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL')->fetchColumn();
$lowStockCount    = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND current_stock <= reorder_level AND current_stock > 0')->fetchColumn();
$outOfStockCount  = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND current_stock <= 0')->fetchColumn();

// Fetch distinct categories and storages for dropdowns
$categories = $pdo->query('SELECT DISTINCT category FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND category IS NOT NULL ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
$storages   = $pdo->query('SELECT DISTINCT storage_location FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND storage_location IS NOT NULL AND CHAR_LENGTH(storage_location) > 0 ORDER BY storage_location')->fetchAll(PDO::FETCH_COLUMN);

// Filtering, Search, Sorting & Pagination Parameters
$q           = trim($_GET['q'] ?? '');
$catFilter   = trim($_GET['category'] ?? 'All');
$stockFilter = trim($_GET['stock_status'] ?? 'All');
$sortBy      = trim($_GET['sort'] ?? 'name');
$sortDir     = strtolower(trim($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$pageNumber  = max(1, (int)($_GET['p'] ?? 1));
$limit       = 25;
$offset      = ($pageNumber - 1) * $limit;

// Allowed sorting columns mapping
$allowedSorts = [
    'name'     => 'i.name',
    'category' => 'i.category',
    'stock'    => 'i.current_stock',
    'reorder'  => 'i.reorder_level'
];
$sqlSortCol = $allowedSorts[$sortBy] ?? 'i.name';

// Base WHERE clause setup
$whereSql = 'WHERE i.is_active = 1 AND i.deleted_at IS NULL';
$params = [];

if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $whereSql .= ' AND (i.name LIKE :q1 OR i.sku LIKE :q2 OR i.category LIKE :q3)';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}

if (!in_array($stockFilter, ['All', 'Low', 'Out', 'OK'], true)) $stockFilter = 'All';
if ($catFilter !== 'All') {
    $whereSql .= ' AND i.category = :cat';
    $params[':cat'] = $catFilter;
}

if ($stockFilter === 'Low') {
    $whereSql .= ' AND i.current_stock <= i.reorder_level AND i.current_stock > 0';
} elseif ($stockFilter === 'Out') {
    $whereSql .= ' AND i.current_stock <= 0';
} elseif ($stockFilter === 'OK') {
    $whereSql .= ' AND i.current_stock > i.reorder_level';
}

// Total count matching filters for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ingredients i $whereSql");
$countStmt->execute($params);
$totalFilteredRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFilteredRows / $limit));
$pageNumber = min($pageNumber, $totalPages);          // a stale ?p= after the filter shrank the list: show the last page, not an empty one
$offset     = ($pageNumber - 1) * $limit;

// Fetch paginated dataset along with subqueries checking for expired batches or batches expiring soon
$dataSql = "SELECT i.*, 
                   (SELECT COUNT(*) FROM stock_receipts r WHERE r.ingredient_id = i.id AND r.quantity_remaining > 0 AND r.expiry_date < CURDATE()) AS expired_batches_count,
                   (SELECT COUNT(*) FROM stock_receipts r WHERE r.ingredient_id = i.id AND r.quantity_remaining > 0 AND r.expiry_date = CURDATE()) AS expires_today_count,
                   (SELECT COUNT(*) FROM stock_receipts r WHERE r.ingredient_id = i.id AND r.quantity_remaining > 0 AND r.expiry_date > CURDATE() AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . (int)EXPIRY_WARN_DAYS . " DAY)) AS expiring_soon_count
            FROM ingredients i 
            $whereSql 
            ORDER BY $sqlSortCol $sortDir, i.name ASC 
            LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($dataSql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Fetch ALL matching rows for print report (ignoring pagination limits)
$printSql = "SELECT i.*, 
                    (SELECT COUNT(*) FROM stock_receipts r WHERE r.ingredient_id = i.id AND r.quantity_remaining > 0 AND r.expiry_date < CURDATE()) AS expired_batches_count,
                    (SELECT COUNT(*) FROM stock_receipts r WHERE r.ingredient_id = i.id AND r.quantity_remaining > 0 AND r.expiry_date = CURDATE()) AS expires_today_count,
                    (SELECT COUNT(*) FROM stock_receipts r WHERE r.ingredient_id = i.id AND r.quantity_remaining > 0 AND r.expiry_date > CURDATE() AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . (int)EXPIRY_WARN_DAYS . " DAY)) AS expiring_soon_count
             FROM ingredients i 
             $whereSql 
             ORDER BY $sqlSortCol $sortDir, i.name ASC";
$printStmt = $pdo->prepare($printSql);
foreach ($params as $key => $val) {
    $printStmt->bindValue($key, $val);
}
$printStmt->execute();
$printRows = $printStmt->fetchAll();

// Helper builder function for table header sorting URLs
function sort_url(string $col): string {
    $params = $_GET;
    $currentSort = $_GET['sort'] ?? 'name';
    $currentDir  = strtolower($_GET['dir'] ?? 'asc');
    
    $nextDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    $params['sort'] = $col;
    $params['dir']  = $nextDir;
    unset($params['p']); // Reset to page 1 on sort change
    return 'index?' . http_build_query($params);
}

// Helper builder function for pagination URLs
function page_url(int $p): string {
    $params = $_GET;
    $params['p'] = $p;
    return 'index?' . http_build_query($params);
}

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

            <div class="grid gap-6 sm:grid-cols-2">
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

            <div class="sm:grid-cols-2">
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
                <a href="index?page=inventory" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
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
            redirect('index?page=inventory');
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
                <a href="index?page=inventory" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                <button class="rounded-md bg-slate-900 px-6 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save changes</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- MAIN INVENTORY LIST VIEW -->
    
    <!-- Top Stat Cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6 print:hidden">
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
    </div>

    <!-- Search & Filter Bar -->
    <form id="filterForm" method="GET" data-live="#invResults" class="mb-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm flex flex-wrap items-end gap-4 print:hidden">
        <input type="hidden" name="page" value="inventory">
        <?php if (!empty($sortBy)): ?><input type="hidden" name="sort" value="<?= e($sortBy) ?>"><?php endif; ?>
        <?php if (!empty($sortDir)): ?><input type="hidden" name="dir" value="<?= e(strtolower($sortDir)) ?>"><?php endif; ?>
        
        <div class="w-full flex flex-col md:flex-row gap-3">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Search</label>
                <input type="search" name="q" id="searchInput" value="<?= e($q) ?>" placeholder="Name, SKU or category..." autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
            </div>

            <div class="w-full md:w-auto lg:w-40">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Category</label>
                <select name="category" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                    <option value="All">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-full md:w-auto lg:w-40">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Stock</label>
                <select name="stock_status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                    <option value="All">All Status</option>
                    <option value="Low" <?= $stockFilter === 'Low' ? 'selected' : '' ?>>Low stock</option>
                    <option value="Out" <?= $stockFilter === 'Out' ? 'selected' : '' ?>>Out of stock</option>
                    <option value="OK" <?= $stockFilter === 'OK' ? 'selected' : '' ?>>OK</option>
                </select>
            </div>

            <div class="flex items-end gap-2">
                <a href="index?page=inventory" class="w-full md:w-auto lg:w-52 h-10 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            </div>
        </div>
    </form>

    <!-- Ingredients Table Section -->
    <div id="invResults" class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <div>
                <h2 class="font-bold text-slate-900 uppercase tracking-wide text-sm">Inventory Stock</h2>
                <p class="text-xs text-slate-500 hidden print:block">Full Inventory Report — Total <?= count($printRows) ?> items matching current filters</p>
            </div>
            <div class="flex gap-5">
                <button type="button" onclick="window.print()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 print:hidden flex items-center gap-1.5 shadow-sm">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print Full Report
                </button>
                <a href="index?page=inventory&action=new" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition-colors shadow-sm print:hidden">+ New Ingredient</a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <!-- Screen View Table (Paginated) -->
            <table class="w-full text-left text-sm print:hidden">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-6 py-3 w-[16%]">
                            <a href="<?= sort_url('name') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                                Ingredient
                                <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'name' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                            </a>
                        </th>
                        <th class="px-6 py-3 w-[16%]">
                            <a href="<?= sort_url('category') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                                Category
                                <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'category' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                            </a>
                        </th>
                        <th class="px-6 py-3 w-[16%]">
                            <a href="<?= sort_url('stock') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                                On Hand
                                <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'stock' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                            </a>
                        </th>
                        <th class="px-6 py-3 w-[16%]">
                            <a href="<?= sort_url('reorder') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                                Reorder At
                                <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'reorder' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                            </a>
                        </th>
                        <th class="px-6 py-3 w-[16%]">Status</th>
                        <th class="px-6 py-3 w-[16%] print:hidden">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $r):
                        $out   = (float) $r['current_stock'] <= 0;
                        $low   = (float) $r['current_stock'] <= (float) $r['reorder_level'];
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
                            <td class="px-6 py-4 space-y-1">
                                <div>
                                    <?= $out ? badge('Out of stock', 'red') : ($low ? badge('Low stock', 'amber') : badge('OK', 'green')) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 space-x-2 whitespace-nowrap print:hidden">
                                <a href="index?page=receiving&ingredient=<?= (int) $r['id'] ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-sm">Receive / Batches</a>
                                <a href="index?page=inventory&action=edit&id=<?= (int) $r['id'] ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-sm">Edit</a>
                                <?php if ($_SESSION['role'] === ROLE_ADMIN): ?>
                                    <button type="button" class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors shadow-sm" onclick="openDeleteModal('<?= e(addslashes($r['name'])) ?>', <?= (int)$r['id'] ?>)">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                                <?php if ($totalIngredients === 0): ?>
                                    No ingredients added yet. <a href="index?page=inventory&action=new" class="font-semibold underline text-orange-600">Add your first ingredient</a>
                                <?php else: ?>
                                    No ingredients match your current search or filters. <a href="index?page=inventory" class="font-semibold underline">Clear filters</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Print View Table (Unpaginated / Full Report) -->
            <table class="w-full text-left text-sm hidden print:table">
                <thead class="border-b border-slate-300 bg-slate-100 text-xs font-bold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-4 py-2">Ingredient / SKU</th>
                        <th class="px-4 py-2">Category</th>
                        <th class="px-4 py-2">On Hand</th>
                        <th class="px-4 py-2">Reorder At</th>
                        <th class="px-4 py-2">Status & Expiry</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($printRows as $pr):
                        $pOut   = (float) $pr['current_stock'] <= 0;
                        $pLow   = (float) $pr['current_stock'] <= (float) $pr['reorder_level'];
                        $pHasExp = (int) $pr['expired_batches_count'] > 0;
                    ?>
                        <tr>
                            <td class="px-4 py-2">
                                <div class="flex print:flex-col gap-0.5">
                                    <span class="font-bold text-slate-900"><?= e($pr['name']) ?></span>
                                    <?php if (!empty($pr['sku'])): ?><span class="text-xs text-slate-500">(SKU: <?= e($pr['sku']) ?>)</span><?php endif; ?>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-slate-700"><?= e($pr['category']) ?></td>
                            <td class="px-4 py-2 font-bold"><?= e(num($pr['current_stock'])) ?> <?= e($pr['unit']) ?></td>
                            <td class="px-4 py-2"><?= e(num($pr['reorder_level'])) ?> <?= e($pr['unit']) ?></td>
                            <td class="px-4 py-2 text-xs">
                                <?= $pOut ? 'Out of stock' : ($pLow ? 'Low stock' : 'OK') ?>
                                <?= $pHasExp ? ' — [Has Expired Batches]' : '' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>


        <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-3 print:hidden">
            <div class="text-xs text-slate-500">
                                <p class="text-xs text-slate-500 print:hidden"><?php if ($totalFilteredRows): ?>Showing <strong><?= $offset + 1 ?>&ndash;<?= $offset + count($rows) ?></strong> of <strong><?= $totalFilteredRows ?></strong> item<?= $totalFilteredRows === 1 ? '' : 's' ?> (page <?= $pageNumber ?> of <?= $totalPages ?>)<?php else: ?>0 items<?php endif; ?></p>
            </div>
            <div class="flex items-center gap-1">
                <?php if ($pageNumber > 1): ?>
                    <a href="<?= page_url($pageNumber - 1) ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-sm">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === 1 || $i === $totalPages || abs($i - $pageNumber) <= 2): ?>
                        <a href="<?= page_url($i) ?>" class="rounded-md px-3 py-1.5 text-xs font-semibold <?= $i === $pageNumber ? 'bg-slate-900 text-white shadow-sm' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 shadow-sm' ?>"><?= $i ?></a>
                    <?php elseif ($i === $pageNumber - 3 || $i === $pageNumber + 3): ?>
                        <span class="px-1 text-slate-400">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($pageNumber < $totalPages): ?>
                    <a href="<?= page_url($pageNumber + 1) ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-sm">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="assets/js/live-filter.js" defer></script>