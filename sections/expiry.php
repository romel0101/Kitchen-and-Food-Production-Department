<?php
// sections/expiry.php - every batch in stock that has an expiry date: metrics, filters, sorting and write-off.
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_perm('expiry');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);

    // The signed-in user's password is always required before a write-off.
    $pwd = (string) ($_POST['verify_password'] ?? '');
    $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
    $u->execute([':id' => $userId]);
    $hash = $u->fetchColumn();
    if (!$hash || $pwd === '' || !password_verify($pwd, (string) $hash)) {
        flash('error', 'Incorrect password. Write-off cancelled.');
        redirect('index?page=expiry');
    }

    if (($_POST['action'] ?? '') === 'write_off' && $batchId) {
        // Moves what is left of an expired batch out of stock and into the waste log.
        run_tx($pdo, function (PDO $pdo) use ($batchId, $userId) {
            $s = $pdo->prepare('SELECT r.ingredient_id, r.quantity_remaining, i.name, i.unit
                                FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id
                                WHERE r.id = :id AND r.quantity_remaining > 0 FOR UPDATE');
            $s->execute([':id' => $batchId]);
            $b = $s->fetch();
            if (!$b) throw new RuntimeException('That batch was not found or is already empty.');
            $left = (float) $b['quantity_remaining'];
            $pdo->prepare('UPDATE stock_receipts SET quantity_remaining = 0 WHERE id = :id')->execute([':id' => $batchId]);
            $pdo->prepare('UPDATE ingredients SET current_stock = GREATEST(0, current_stock - :q) WHERE id = :id')->execute([':q' => $left, ':id' => $b['ingredient_id']]);
            $pdo->prepare("INSERT INTO waste_logs (ingredient_id, batch_id, current_stock, reason, notes, logged_by) VALUES (:i, :b, :q, 'expired', :n, :u)")
                ->execute([':i' => $b['ingredient_id'], ':b' => $batchId, ':q' => $left, ':n' => "Written off from expiry tracking (batch #$batchId)", ':u' => $userId]);
            log_stock_movement($pdo, (int) $b['ingredient_id'], $batchId, 'write_off', -$left, "Expired batch #$batchId written off", $userId);
            log_activity('write_off', "Wrote off expired batch #$batchId ({$b['name']}, " . num($left) . " {$b['unit']})");
            return 'Batch #' . $batchId . ' (' . $b['name'] . ') written off and added to the waste log.';
        });
    } else {
        flash('error', 'Invalid request.');
    }
    redirect('index?page=expiry');
}

// Every batch that is tracked at all (used only to tell "nothing in stock" from "nothing matches your filter").
$trackedAll = (int) $pdo->query('SELECT COUNT(*) FROM stock_receipts WHERE quantity_remaining > 0 AND expiry_date IS NOT NULL')->fetchColumn();

// Fetch distinct storage locations for filter dropdown
$storages = $pdo->query('SELECT DISTINCT storage_location FROM ingredients WHERE is_active = 1 AND deleted_at IS NULL AND storage_location IS NOT NULL AND CHAR_LENGTH(storage_location) > 0 ORDER BY storage_location')->fetchAll(PDO::FETCH_COLUMN);

// Filtering, Search, Sorting & Pagination Parameters
$q           = trim($_GET['q'] ?? '');
$status      = trim($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'expired', 'today', 'soon', 'good'], true)) $status = 'all';
$storage     = trim($_GET['storage'] ?? '');
$sortBy      = trim($_GET['sort'] ?? 'expiry');
$sortDir     = strtolower(trim($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$pageNumber  = max(1, (int)($_GET['p'] ?? 1));
$limit       = 25;
$offset      = ($pageNumber - 1) * $limit;

// Allowed sorting columns mapping
$allowedSorts = [
    'name'   => 'i.name',
    'qty'    => 'r.quantity_remaining',
    'days'   => 'days_left',
    'expiry' => 'r.expiry_date'
];
$sqlSortCol = $allowedSorts[$sortBy] ?? 'r.expiry_date';

// Base WHERE clause setup
$whereSql = 'WHERE r.quantity_remaining > 0 AND r.expiry_date IS NOT NULL';
$params = [];

if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $whereSql .= ' AND (i.name LIKE :q1 OR r.supplier LIKE :q2 OR CAST(r.id AS CHAR) LIKE :q3 OR i.storage_location LIKE :q4)';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

if ($storage !== '') {
    $whereSql .= ' AND i.storage_location = :storage';
    $params[':storage'] = $storage;
}

// Metric cards: counted with the search and storage filter applied but NOT the status filter, so the number on a card
// is exactly how many rows you get when you click it.
$warn = (int) EXPIRY_WARN_DAYS;
$cardStmt = $pdo->prepare("SELECT COALESCE(SUM(t.d < 0), 0) AS expired, COALESCE(SUM(t.d = 0), 0) AS today,
                                  COALESCE(SUM(t.d > 0 AND t.d <= $warn), 0) AS soon, COALESCE(SUM(t.d > $warn), 0) AS good
                           FROM (SELECT DATEDIFF(r.expiry_date, CURDATE()) AS d
                                 FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id $whereSql) t");
$cardStmt->execute($params);
$counts = array_map('intval', $cardStmt->fetch());
$total  = array_sum($counts);

if ($status === 'expired') {
    $whereSql .= ' AND DATEDIFF(r.expiry_date, CURDATE()) < 0';
} elseif ($status === 'today') {
    $whereSql .= ' AND DATEDIFF(r.expiry_date, CURDATE()) = 0';
} elseif ($status === 'soon') {
    $whereSql .= ' AND DATEDIFF(r.expiry_date, CURDATE()) > 0 AND DATEDIFF(r.expiry_date, CURDATE()) <= ' . (int)EXPIRY_WARN_DAYS;
} elseif ($status === 'good') {
    $whereSql .= ' AND DATEDIFF(r.expiry_date, CURDATE()) > ' . (int)EXPIRY_WARN_DAYS;
}

// Total count matching filters for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id $whereSql");
$countStmt->execute($params);
$totalFilteredRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFilteredRows / $limit));
$pageNumber = min($pageNumber, $totalPages);          // a stale ?p= after the filter shrank the list: show the last page, not an empty one
$offset     = ($pageNumber - 1) * $limit;

// Fetch paginated dataset
$dataSql = "SELECT r.id, r.ingredient_id, r.quantity_remaining, r.expiry_date, r.supplier, i.name, i.unit, i.storage_location,
                   DATEDIFF(r.expiry_date, CURDATE()) AS days_left
            FROM stock_receipts r 
            JOIN ingredients i ON i.id = r.ingredient_id 
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
$printSql = "SELECT r.id, r.ingredient_id, r.quantity_remaining, r.expiry_date, r.supplier, i.name, i.unit, i.storage_location,
                    DATEDIFF(r.expiry_date, CURDATE()) AS days_left
             FROM stock_receipts r 
             JOIN ingredients i ON i.id = r.ingredient_id 
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
    $currentSort = $_GET['sort'] ?? 'expiry';
    $currentDir  = strtolower($_GET['dir'] ?? 'asc');
    
    $nextDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    $params['sort'] = $col;
    $params['dir']  = $nextDir;
    unset($params['p']); // Reset to page 1 on sort change
    return 'index?' . http_build_query($params);
}

// Address for a metric card: same search, storage and sort, different status, back on page 1.
function status_url(string $status): string {
    $params = $_GET;
    unset($params['p']);
    if ($status === 'all') unset($params['status']); else $params['status'] = $status;
    return 'index?' . http_build_query($params);
}

// Helper builder function for pagination URLs
function page_url(int $p): string {
    $params = $_GET;
    $params['p'] = $p;
    return 'index?' . http_build_query($params);
}

$cards = [
    'all'     => ['All batches',  $total,              $total ? 'batches tracked' : 'nothing tracked',
                  'border-slate-200 text-slate-900', 'ring-slate-400', 'bg-slate-400'],
    'good'    => ['Good',         $counts['good'],     'more than ' . EXPIRY_WARN_DAYS . ' days left',
                  'border-green-200 text-green-800',  'ring-green-400', 'bg-green-500'],
    'soon'    => ['Expiring soon',$counts['soon'],     'within ' . EXPIRY_WARN_DAYS . ' days',
                  'border-amber-200 text-amber-800',  'ring-amber-400', 'bg-amber-400'],
    'today'   => ['Expires today',$counts['today'],    $counts['today'] ? 'use or discard today' : 'none today',
                  'border-orange-200 text-orange-800', 'ring-orange-400', 'bg-orange-500'],
    'expired' => ['Expired',      $counts['expired'],  $counts['expired'] ? 'batches to write off' : 'none, good',
                  'border-red-200 text-red-800',     'ring-red-400',    'bg-red-500'],
];
$statusLabel = ['expired' => 'expired', 'today' => 'expiring today', 'soon' => 'expiring soon', 'good' => 'Good'];
$canReceive  = can('receiving');

$pageTitle = 'Expiry tracking';
?>

<!-- Metric cards: click one to filter the table -->
<div id="expCards" class="grid grid-cols-2 gap-3 lg:grid-cols-5 print:hidden">
    <?php foreach ($cards as $key => [$label, $n, $sub, $tone, $ring, $bar]):
        $on = $status === $key; ?>
        <a href="<?= e(status_url($key)) ?>" <?= $on ? 'aria-current="page"' : '' ?>
           class="rounded-lg border bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow focus:outline-none focus:ring-2 <?= $tone ?> <?= $on ? 'ring-2 ' . $ring : 'focus:' . $ring ?> <?= $key === 'all' ? 'col-span-2 lg:col-span-1' : '' ?>">
            <p class="text-xs font-bold uppercase tracking-wider opacity-70"><?= e($label) ?></p>
            <p class="mt-1 text-3xl font-black tabular-nums"><?= (int) $n ?></p>
            <p class="mt-0.5 text-xs opacity-70"><?= e($sub) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<!-- Toolbar -->
<form id="filterForm" method="GET" data-live="#expCards,#expResults" class="mt-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-white shadow-sm p-4 print:hidden">
    <input type="hidden" name="page" value="expiry">
    <?php if (!empty($sortBy)): ?><input type="hidden" name="sort" value="<?= e($sortBy) ?>"><?php endif; ?>
    <?php if (!empty($sortDir)): ?><input type="hidden" name="dir" value="<?= e(strtolower($sortDir)) ?>"><?php endif; ?>

    <div class="flex flex-col md:flex-row gap-3">
        <div class="min-w-[220px] flex-1">
            <label for="searchInput" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Search</label>
            <input type="search" name="q" id="searchInput" value="<?= e($q) ?>" placeholder="Ingredient, supplier or batch number..." autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500">
        </div>
        <?php if ($storages): ?>
            <div class=""w-full md:w-auto lg:w-52">
                <label for="expStorage" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Stored in</label>
                <select name="storage" id="expStorage" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                    <option value="">All storage</option>
                    <?php foreach ($storages as $s): ?>
                        <option value="<?= e($s) ?>" <?= $storage === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="w-full md:w-auto lg:w-52 ">
            <label for="Status" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Status</label>
            <select name="status" id="Status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="good" <?= $status === 'good' ? 'selected' : '' ?>>Good</option>
                <option value="soon" <?= $status === 'soon' ? 'selected' : '' ?>>Expiring soon</option>
                <option value="today" <?= $status === 'today' ? 'selected' : '' ?>>Expires today</option>
                <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <a href="index?page=expiry" class="w-full md:w-auto lg:w-52 h-10 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 flex items-center">Clear</a>
        </div>
    </div>
</form>

<!-- Table Section -->
<div id="expResults" class="mt-4 rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
            <h2 class="font-bold text-slate-900 uppercase tracking-wide text-sm">Expiry Tracking</h2>
            <p class="text-xs text-slate-500 hidden print:block">Full Expiry Report — Total <?= count($printRows) ?> batches matching current filters</p>
        </div>
        <button type="button" onclick="window.print()" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 print:hidden flex items-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Full Report
        </button>
    </div>

    <div class="overflow-x-auto">
        <!-- Screen View Table (Paginated) -->
        <table class="w-full text-left text-sm print:hidden">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-3">
                        <a href="<?= sort_url('name') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                            Ingredient
                            <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'name' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                        </a>
                    </th>
                    <th class="px-4 py-3">
                        <a href="<?= sort_url('qty') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                            Left in batch
                            <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'qty' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                        </a>
                    </th>
                    <th class="px-4 py-3">
                        <a href="<?= sort_url('expiry') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">
                            Expiry date
                            <span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'expiry' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                        </a>
                    </th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Stored in</th>
                    <th class="px-4 py-3 print:hidden"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($rows as $b):
                    $d  = (int) $b['days_left'];
                    if ($d < 0)                 $bk = 'expired';
                    elseif ($d === 0)           $bk = 'today';
                    elseif ($d <= EXPIRY_WARN_DAYS) $bk = 'soon';
                    else                        $bk = 'good';
                ?>
                    <tr class="transition-colors hover:bg-slate-50 <?= $bk === 'expired' ? 'bg-red-50/40' : ($bk === 'today' ? 'bg-orange-50/40' : '') ?>">
                        <td class="px-4 py-3">
                            <?php if ($canReceive): ?>
                                <p class="font-semibold text-slate-900" title="Open batches and receiving for <?= e($b['name']) ?>"><?= e($b['name']) ?></p>
                            <?php else: ?><span class="font-semibold text-slate-900"><?= e($b['name']) ?></span><?php endif; ?>
                            <p class="text-xs text-slate-500">Batch #<?= (int) $b['id'] ?><?= !empty($b['supplier']) ? ' &middot; ' . e($b['supplier']) : '' ?></p>
                        </td>
                        <td class="px-4 py-3 font-semibold tabular-nums"><?= e(num($b['quantity_remaining'])) ?> <span class="text-xs font-normal text-slate-500"><?= e($b['unit']) ?></span></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= e(date('j M Y', strtotime($b['expiry_date']))) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($bk === 'expired'): ?><?= badge('Expired ' . abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago', 'red') ?>
                            <?php elseif ($bk === 'today'): ?><?= badge('Expires today', 'red') ?>
                            <?php elseif ($bk === 'soon'): ?><?= badge("Expires in $d day" . ($d === 1 ? '' : 's'), 'amber') ?>
                            <?php else: ?><?= badge("$d days left", 'green') ?><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600"><?= e($b['storage_location']) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right print:hidden">
                            <?php if ($bk === 'expired'): ?>
                                <button type="button" class="rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-800 shadow-sm hover:bg-red-50" onclick="openDeleteModal(<?= e(json_encode('Expired batch #' . $b['id'] . ' (' . $b['name'] . ', ' . num($b['quantity_remaining']) . ' ' . $b['unit'] . ')', JSON_UNESCAPED_UNICODE)) ?>, <?= (int) $b['id'] ?>, 'expiry_writeoff')">Write off</button>
                            <?php elseif ($canReceive): ?>
                                <a href="index?page=receiving&ingredient=<?= (int) $b['ingredient_id'] ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">View batches</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            <?php if ($trackedAll === 0): ?>No batches with expiry dates are in stock. Add an expiry date when you record a delivery.
                            <?php else: ?>No batches match your search or filters. <a href="index?page=expiry" class="font-semibold underline">Clear filters</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Print View Table (Unpaginated / Full Report) -->
        <table class="w-full text-left text-sm hidden print:table">
            <thead class="border-b border-slate-300 bg-slate-100 text-xs font-bold uppercase tracking-wider text-slate-700">
                <tr>
                    <th class="px-4 py-2">Ingredient / Batch</th>
                    <th class="px-4 py-2">Quantity Left</th>
                    <th class="px-4 py-2">Expiry Date</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Storage</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php foreach ($printRows as $pb):
                    $pd = (int) $pb['days_left'];
                    if ($pd < 0)                 $pbk = 'Expired ' . abs($pd) . ' days ago';
                    elseif ($pd === 0)           $pbk = 'Expires today';
                    elseif ($pd <= EXPIRY_WARN_DAYS) $pbk = "Expires in $pd days";
                    else                        $pbk = "$pd days left";
                ?>
                    <tr>
                        <td class="px-4 py-2">
                            <span class="font-bold text-slate-900"><?= e($pb['name']) ?></span>
                            <span class="text-xs text-slate-500">(Batch #<?= (int)$pb['id'] ?><?= !empty($pb['supplier']) ? ' &middot; ' . e($pb['supplier']) : '' ?>)</span>
                        </td>
                        <td class="px-4 py-2 font-bold"><?= e(num($pb['quantity_remaining'])) ?> <?= e($pb['unit']) ?></td>
                        <td class="px-4 py-2"><?= e(date('j M Y', strtotime($pb['expiry_date']))) ?></td>
                        <td class="px-4 py-2 text-xs font-semibold"><?= e($pbk) ?></td>
                        <td class="px-4 py-2 text-slate-600"><?= e($pb['storage_location']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Bar -->
    <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-3 print:hidden">
        <div class="text-xs text-slate-500">
            <p class="text-xs text-slate-500 print:hidden"><?php if ($totalFilteredRows): ?>Showing <strong><?= $offset + 1 ?>&ndash;<?= $offset + count($rows) ?></strong> of <strong><?= $totalFilteredRows ?></strong> batch<?= $totalFilteredRows === 1 ? '' : 'es' ?> (page <?= $pageNumber ?> of <?= $totalPages ?>)<?php else: ?>0 batches<?php endif; ?></p>
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

<script src="assets/js/live-filter.js" defer></script>
<script>
// Password confirmation before a write-off.
function openDeleteModal(itemName, itemId, type) {
    document.getElementById('deleteModalItemName').textContent = itemName;
    document.getElementById('deleteModalItemId').value = itemId;
    document.getElementById('deleteModalPassword').value = '';
    document.getElementById('deleteModalError').classList.add('hidden');
    document.getElementById('deleteModalConfirmBtn').disabled = false;
    var modal = document.getElementById('deleteModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(function () { document.getElementById('deleteModalPassword').focus(); }, 50);
}
function closeDeleteModal() {
    var modal = document.getElementById('deleteModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function submitDeleteModal() {
    var password = document.getElementById('deleteModalPassword').value;
    if (!password) {
        var err = document.getElementById('deleteModalError');
        err.textContent = 'Please enter your password.';
        err.classList.remove('hidden');
        return;
    }
    document.getElementById('deleteModalConfirmBtn').disabled = true;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index?page=expiry';
    [['csrf', document.querySelector('input[name="csrf"]').value], ['action', 'write_off'],
     ['batch_id', document.getElementById('deleteModalItemId').value], ['verify_password', password]].forEach(function (kv) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}
document.addEventListener('DOMContentLoaded', function () {
    const pwdInput = document.getElementById('deleteModalPassword');
    if (pwdInput) {
        pwdInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitDeleteModal(); }
        });
    }
    document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDeleteModal(); });
});
</script>

<!-- Password confirmation modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4 print:hidden backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
        <div class="border-b border-slate-200 px-6 py-4"><h3 id="deleteModalTitle" class="text-lg font-semibold text-slate-900">Confirm write-off</h3></div>
        <div class="space-y-4 px-6 py-4">
            <p class="text-slate-700">Are you sure you want to write off <strong id="deleteModalItemName"></strong>?</p>
            <p class="text-xs text-slate-500">What is left of the batch leaves stock and goes into the waste log. This cannot be undone. Enter your password to confirm.</p>
            <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500" placeholder="Your password" autocomplete="current-password">
            <input type="hidden" id="deleteModalItemId">
            <p id="deleteModalError" class="hidden text-sm text-red-600"></p>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
            <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Write off</button>
        </div>
    </div>
</div>