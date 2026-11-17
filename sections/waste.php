<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_perm('waste');

// ---------------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------------
// URL of this page keeping the current filters; $override changes or (null) removes parameters.
if (!function_exists('waste_url')) {
    function waste_url(array $override = []): string {
        $params = array_merge(array_intersect_key($_GET, array_flip(['q', 'reason', 'from', 'to', 'p'])), $override);
        $params = array_filter($params, fn($v) => $v !== null && $v !== '' && $v !== 1);
        return 'index.php?' . http_build_query(['page' => 'waste'] + $params);
    }
}
// Puts quantity back into stock (a waste entry was reduced or deleted): into its own batch when it has one
// (never above what that batch originally received), and always into the ingredient's total.
if (!function_exists('waste_restore_stock')) {
    function waste_restore_stock(PDO $pdo, int $ingredientId, ?array $batch, float $qty): void {
        if ($batch) {
            $room = max(0.0, round((float) $batch['current_stock'] - (float) $batch['quantity_remaining'], 3));
            $back = min($qty, $room);
            if ($back > 0) {
                $pdo->prepare('UPDATE stock_receipts SET quantity_remaining = quantity_remaining + :q WHERE id = :id')
                    ->execute([':q' => $back, ':id' => $batch['id']]);
            }
        }
        $pdo->prepare('UPDATE ingredients SET current_stock = current_stock + :q WHERE id = :id')
            ->execute([':q' => $qty, ':id' => $ingredientId]);
    }
}
const WASTE_TONES = ['expired' => 'red', 'spoiled' => 'amber', 'damaged' => 'amber', 'unused' => 'blue', 'other' => 'gray'];

$listUrl = waste_url();

// ---------------------------------------------------------------------------------------------------
// POST: edit or delete a waste entry. Stock, batch and the stock ledger change together or not at all.
// ---------------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id     = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'edit_waste' && $id) {
        $qty    = filter_var($_POST['current_stock'] ?? null, FILTER_VALIDATE_FLOAT);
        $reason = (string) ($_POST['reason'] ?? '');
        $notes  = trim((string) ($_POST['notes'] ?? '')) ?: null;

        if ($qty === false || $qty <= 0 || !array_key_exists($reason, WASTE_REASONS)) {
            flash('error', 'Enter a quantity above 0 and choose a reason.');
        } else {
            run_tx($pdo, function (PDO $pdo) use ($id, $qty, $reason, $notes, $userId) {
                $qty = round($qty, 3);
                $s = $pdo->prepare('SELECT w.*, i.name, i.unit FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id WHERE w.id = :id FOR UPDATE');
                $s->execute([':id' => $id]);
                $old = $s->fetch();
                if (!$old) throw new RuntimeException('That waste entry no longer exists.');

                $ing    = (int) $old['ingredient_id'];
                $oldQty = (float) $old['current_stock'];
                $diff   = round($qty - $oldQty, 3);               // > 0: more was wasted, < 0: some was not wasted after all

                $batch = null;
                if ($old['batch_id']) {
                    $b = $pdo->prepare('SELECT id, current_stock, quantity_remaining FROM stock_receipts WHERE id = :id FOR UPDATE');
                    $b->execute([':id' => $old['batch_id']]);
                    $batch = $b->fetch() ?: null;
                }

                if ($diff > 0.0005) {
                    $g = $pdo->prepare('SELECT current_stock FROM ingredients WHERE id = :id FOR UPDATE');
                    $g->execute([':id' => $ing]);
                    if ($diff > (float) $g->fetchColumn() + 0.0005) {
                        throw new RuntimeException('Not enough ' . $old['name'] . ' in stock to waste ' . num($diff) . ' ' . $old['unit'] . ' more.');
                    }
                    if ($batch) {
                        if ($diff > (float) $batch['quantity_remaining'] + 0.0005) {
                            throw new RuntimeException('Batch #' . $batch['id'] . ' only has ' . num($batch['quantity_remaining']) . ' ' . $old['unit'] . ' left, so this entry can grow by at most that.');
                        }
                        $pdo->prepare('UPDATE stock_receipts SET quantity_remaining = quantity_remaining - :q WHERE id = :id')
                            ->execute([':q' => $diff, ':id' => $batch['id']]);
                        $pdo->prepare('UPDATE ingredients SET current_stock = GREATEST(0, current_stock - :q) WHERE id = :id')
                            ->execute([':q' => $diff, ':id' => $ing]);
                    } else {
                        deduct_stock($pdo, $ing, $diff);          // older entry with no batch: oldest-expiry batches first
                    }
                } elseif ($diff < -0.0005) {
                    waste_restore_stock($pdo, $ing, $batch, -$diff);
                }

                $pdo->prepare('UPDATE waste_logs SET current_stock = :q, reason = :r, notes = :n WHERE id = :id')
                    ->execute([':q' => $qty, ':r' => $reason, ':n' => $notes, ':id' => $id]);

                if (abs($diff) > 0.0005) {
                    log_stock_movement($pdo, $ing, $batch ? (int) $batch['id'] : null, 'waste', -$diff,
                        'Waste entry #' . $id . ' edited: ' . num($oldQty) . ' → ' . num($qty) . ' ' . $old['unit'] . ' | ' . (WASTE_REASONS[$reason] ?? $reason), $userId);
                }
                log_activity('update', "Edited waste log ID #$id ({$old['name']}: " . num($qty) . " {$old['unit']})");
                return 'Waste entry #' . $id . ' updated' . (abs($diff) > 0.0005 ? ' and stock adjusted by ' . ($diff < 0 ? '+' : '-') . num(abs($diff)) . ' ' . $old['unit'] : '') . '.';
            });
        }
        redirect($listUrl);
    }

    if ($action === 'delete_waste' && $id) {
        if (($_SESSION['role'] ?? '') !== ROLE_ADMIN) {
            flash('error', 'Only administrators can delete waste entries.');
            redirect($listUrl);
        }
        // The admin must confirm with their own password (checked here on the server, not only in the browser).
        $h = $pdo->prepare('SELECT password FROM users WHERE id = :id');
        $h->execute([':id' => $userId]);
        if (!password_verify((string) ($_POST['verify_password'] ?? ''), (string) $h->fetchColumn())) {
            usleep(300000);
            flash('error', 'Your password was wrong, so nothing was deleted.');
            redirect($listUrl);
        }
        run_tx($pdo, function (PDO $pdo) use ($id, $userId) {
            $s = $pdo->prepare('SELECT w.*, i.name, i.unit FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id WHERE w.id = :id FOR UPDATE');
            $s->execute([':id' => $id]);
            $old = $s->fetch();
            if (!$old) throw new RuntimeException('That waste entry no longer exists.');

            $batch = null;
            if ($old['batch_id']) {
                $b = $pdo->prepare('SELECT id, current_stock, quantity_remaining FROM stock_receipts WHERE id = :id FOR UPDATE');
                $b->execute([':id' => $old['batch_id']]);
                $batch = $b->fetch() ?: null;
            }
            $qty = (float) $old['current_stock'];
            waste_restore_stock($pdo, (int) $old['ingredient_id'], $batch, $qty);
            log_stock_movement($pdo, (int) $old['ingredient_id'], $batch ? (int) $batch['id'] : null, 'waste', $qty,
                'Waste entry #' . $id . ' deleted (' . (WASTE_REASONS[$old['reason']] ?? $old['reason']) . '): stock restored', $userId);
            $pdo->prepare('DELETE FROM waste_logs WHERE id = :id')->execute([':id' => $id]);
            log_activity('delete', "Deleted waste log ID #$id ({$old['name']}: " . num($qty) . " {$old['unit']})");
            return 'Waste entry deleted and ' . num($qty) . ' ' . $old['unit'] . ' of ' . $old['name'] . ' put back in stock.';
        });
        redirect($listUrl);
    }
    flash('error', 'Unknown action.');
    redirect($listUrl);
}

// ---------------------------------------------------------------------------------------------------
// Filters and data
// ---------------------------------------------------------------------------------------------------
$q       = trim((string) ($_GET['q'] ?? ''));
$fReason = (string) ($_GET['reason'] ?? '');
if (!array_key_exists($fReason, WASTE_REASONS)) $fReason = '';
$from    = valid_date((string) ($_GET['from'] ?? ''));
$to      = valid_date((string) ($_GET['to'] ?? ''));
if ($from && $to && $from > $to) [$from, $to] = [$to, $from];

$where  = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(i.name LIKE :q1 OR w.notes LIKE :q2)';
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
}
if ($fReason !== '') { $where[] = 'w.reason = :reason'; $params[':reason'] = $fReason; }
if ($from)           { $where[] = 'w.logged_at >= :from'; $params[':from'] = $from . ' 00:00:00'; }
if ($to)             { $where[] = 'w.logged_at < :to_end'; $params[':to_end'] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
$whereSql = implode(' AND ', $where);
$filtered = $q !== '' || $fReason !== '' || $from || $to;

$count = $pdo->prepare("SELECT COUNT(*) FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id WHERE $whereSql");
$count->execute($params);
$totalRows  = (int) $count->fetchColumn();
$limit      = 25;
$totalPages = max(1, (int) ceil($totalRows / $limit));
$pageNumber = min($totalPages, max(1, (int) ($_GET['p'] ?? 1)));
$offset     = ($pageNumber - 1) * $limit;

$stmt = $pdo->prepare("SELECT w.*, i.name, i.unit, u.first_name, u.middle_name, u.last_name, u.suffix, u.email,
                              b.quantity_remaining AS batch_left, b.supplier AS batch_supplier
                       FROM waste_logs w
                       JOIN ingredients i ON i.id = w.ingredient_id
                       LEFT JOIN users u ON u.id = w.logged_by
                       LEFT JOIN stock_receipts b ON b.id = w.batch_id
                       WHERE $whereSql
                       ORDER BY w.logged_at DESC, w.id DESC
                       LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$recent = $stmt->fetchAll();

// Summary cards: today and the last 7 days ignore the filters; "most common reason" follows them.
$todayN = (int) $pdo->query('SELECT COUNT(*) FROM waste_logs WHERE logged_at >= CURDATE()')->fetchColumn();
$weekStart = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d 00:00:00');
$weekQ = $pdo->prepare('SELECT COUNT(*) FROM waste_logs WHERE logged_at >= :d');
$weekQ->execute([':d' => $weekStart]);
$weekN = (int) $weekQ->fetchColumn();
$topQ = $pdo->prepare("SELECT w.reason, COUNT(*) AS n FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id WHERE $whereSql GROUP BY w.reason ORDER BY n DESC LIMIT 1");
$topQ->execute($params);
$top = $topQ->fetch();

$isAdmin   = ($_SESSION['role'] ?? '') === ROLE_ADMIN;
$pageTitle = 'Food waste';
$ctl = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500';
$lbl = 'block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1';
?>

<!-- Summary -->
<div id="wasteSummary" class="mb-6 grid grid-cols-1 md:grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Logged today</p>
        <p class="mt-2 text-3xl font-black tabular-nums text-slate-900"><?= $todayN ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Last 7 days</p>
        <p class="mt-2 text-3xl font-black tabular-nums text-slate-900"><?= $weekN ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400"><?= $filtered ? 'Matching entries' : 'All entries' ?></p>
        <p class="mt-2 text-3xl font-black tabular-nums text-slate-900"><?= $totalRows ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Most common reason</p>
        <p class="mt-2 text-xl font-black text-slate-900"><?= $top ? e(WASTE_REASONS[$top['reason']] ?? $top['reason']) : '—' ?>
            <?php if ($top): ?><span class="text-sm font-medium text-slate-400">(<?= (int) $top['n'] ?>)</span><?php endif; ?></p>
    </div>
</div>

<!-- Filters (Real-time) -->
<form id="filterForm" method="GET" action="index.php" data-live="#wasteSummary,#wasteResults" class="mb-4 grid grid-cols-1 md:grid-cols-9 md:flex md:flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
    <input type="hidden" name="page" value="waste">
    <div class="w-full lg:min-w-[200px] lg:flex-1">
        <label for="w-q" class="<?= $lbl ?>">Search</label>
        <input id="w-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Ingredient or details..." autocomplete="off" class="<?= $ctl ?>">
    </div>
    <div class="w-full md:w-auto lg:w-48">
        <label for="w-reason" class="<?= $lbl ?>">Reason</label>
        <select id="w-reason" name="reason" class="<?= $ctl ?>">
            <option value="">All reasons</option>
            <?php foreach (WASTE_REASONS as $k => $label): ?><option value="<?= e($k) ?>" <?= $fReason === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="w-full md:w-auto lg:w-44">
        <label for="w-from" class="<?= $lbl ?>">From</label>
        <input id="w-from" type="date" name="from" value="<?= e($from ?? '') ?>" class="<?= $ctl ?>">
    </div>
    <div class="w-full md:w-auto lg:w-44">
        <label for="w-to" class="<?= $lbl ?>">To</label>
        <input id="w-to" type="date" name="to" value="<?= e($to ?? '') ?>" class="<?= $ctl ?>">
    </div>

    <div class="w-full md:w-auto flex">
        <a href="index.php?page=waste" class="w-full md:w-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 flex items-center justify-center">Clear</a>
    </div>
</form>

<!-- Table -->
<div id="wasteResults" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Ingredient</th>
                    <th class="px-4 py-3 text-right">Quantity</th>
                    <th class="px-4 py-3">Batch</th>
                    <th class="px-4 py-3">Reason</th>
                    <th class="px-4 py-3">Details</th>
                    <th class="px-4 py-3">By</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recent as $r):
                    $ts   = strtotime($r['logged_at']);
                    $by   = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name'], $r['suffix']]))) ?: ($r['email'] ?? '—');
                    $max  = $r['batch_id'] && $r['batch_left'] !== null ? (float) $r['current_stock'] + (float) $r['batch_left'] : null; ?>
                    <tr class="hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="block font-medium text-slate-900"><?= e(date('j M Y', $ts)) ?></span>
                            <span class="text-xs text-slate-400"><?= e(date('H:i', $ts)) ?></span>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-900"><?= e($r['name']) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-bold tabular-nums text-red-600">−<?= e(num($r['current_stock'])) ?> <span class="font-normal text-xs text-slate-500"><?= e($r['unit']) ?></span></td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            <?php if ($r['batch_id']): ?>
                                <a href="index.php?page=receiving&amp;ingredient=<?= (int) $r['ingredient_id'] ?>" class="font-semibold text-blue-700 hover:underline" title="<?= e($r['batch_supplier'] ?? 'Open batches') ?>">#<?= (int) $r['batch_id'] ?></a>
                            <?php else: ?><span class="text-slate-400" title="Logged before waste was tied to a batch">—</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><?= badge(WASTE_REASONS[$r['reason']] ?? $r['reason'], WASTE_TONES[$r['reason']] ?? 'gray') ?></td>
                        <td class="max-w-xs px-4 py-3 text-slate-600"><?= $r['notes'] ? e($r['notes']) : '<span class="text-slate-300">—</span>' ?></td>
                        <td class="px-4 py-3 text-slate-600"><?= e($by) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" class="js-edit rounded border border-blue-300 bg-white px-3 py-1 text-xs font-bold text-blue-700 shadow-sm hover:bg-blue-50 transition-colors"
                                    data-id="<?= (int) $r['id'] ?>" data-name="<?= e($r['name']) ?>" data-unit="<?= e($r['unit']) ?>" data-qty="<?= e(num($r['current_stock'])) ?>"
                                    data-reason="<?= e($r['reason']) ?>" data-notes="<?= e($r['notes'] ?? '') ?>"
                                    data-batch="<?= $r['batch_id'] ? (int) $r['batch_id'] : '' ?>" data-max="<?= $max !== null ? e(num($max)) : '' ?>">Edit</button>
                            <?php if ($isAdmin): ?>
                                <button type="button" class="js-delete ml-1 rounded border border-red-200 bg-red-50 px-3 py-1 text-xs font-bold text-red-700 shadow-sm hover:bg-red-100 transition-colors"
                                        data-id="<?= (int) $r['id'] ?>" data-label="<?= e($r['name'] . ': ' . num($r['current_stock']) . ' ' . $r['unit']) ?>">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                    <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">
                        <?= $filtered ? 'No waste entries match these filters.' : 'No waste has been logged yet.' ?>
                        <?php if ($filtered): ?><a href="index.php?page=waste" class="ml-1 font-semibold text-blue-700 hover:underline">Clear filters</a><?php endif; ?>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
        <p class="text-xs text-slate-500">
            <?php if ($totalRows): ?>Showing <strong><?= $offset + 1 ?>–<?= $offset + count($recent) ?></strong> of <strong><?= $totalRows ?></strong> (page <?= $pageNumber ?> of <?= $totalPages ?>)
            <?php else: ?>0 entries<?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <nav class="flex items-center gap-1" aria-label="Pages">
                <?php if ($pageNumber > 1): ?><a href="<?= e(waste_url(['p' => $pageNumber - 1])) ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-100">Previous</a><?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === 1 || $i === $totalPages || abs($i - $pageNumber) <= 2): ?>
                        <a href="<?= e(waste_url(['p' => $i])) ?>" <?= $i === $pageNumber ? 'aria-current="page"' : '' ?>
                           class="rounded-md px-3 py-1.5 text-xs font-semibold shadow-sm <?= $i === $pageNumber ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100' ?>"><?= $i ?></a>
                    <?php elseif ($i === $pageNumber - 3 || $i === $pageNumber + 3): ?><span class="px-1 text-slate-400">…</span><?php endif; ?>
                <?php endfor; ?>
                <?php if ($pageNumber < $totalPages): ?><a href="<?= e(waste_url(['p' => $pageNumber + 1])) ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-100">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal Overlay -->
<div id="editModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
        <form method="POST" action="<?= e($listUrl) ?>" class="space-y-4 p-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_waste">
            <input type="hidden" name="id" id="editId">
            <div>
                <h3 class="text-lg font-bold text-slate-900">Edit waste entry <span id="editNo" class="text-slate-400"></span></h3>
                <p id="editInfo" class="mt-1 text-sm text-slate-500"></p>
            </div>
            <div>
                <label class="<?= $lbl ?>">Quantity <span id="editUnit" class="normal-case text-slate-400"></span> <span class="text-red-600">*</span></label>
                <input type="number" name="current_stock" id="editQty" step="0.001" min="0.001" required class="<?= $ctl ?>">
                <p id="editHint" class="mt-1 text-xs text-slate-500"></p>
            </div>
            <div>
                <label class="<?= $lbl ?>">Reason <span class="text-red-600">*</span></label>
                <select name="reason" id="editReason" required class="<?= $ctl ?>">
                    <?php foreach (WASTE_REASONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="<?= $lbl ?>">Details</label>
                <input type="text" name="notes" id="editNotes" maxlength="255" class="<?= $ctl ?>">
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeEditModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal Overlay (admin password required) -->
<?php if ($isAdmin): ?>
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
        <form method="POST" action="<?= e($listUrl) ?>" class="space-y-4 p-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_waste">
            <input type="hidden" name="id" id="deleteId">
            <h3 class="text-lg font-bold text-red-700">Delete waste entry</h3>
            <p class="text-sm text-slate-700">Delete <strong id="deleteLabel"></strong>? The quantity goes back into stock. This cannot be undone.</p>
            <div>
                <label class="<?= $lbl ?>">Your password</label>
                <input type="password" name="verify_password" id="deletePass" required autocomplete="current-password" class="<?= $ctl ?>">
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button class="rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-red-700">Delete</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="assets/js/live-filter.js" defer></script>
<script>
(function () {
    const $ = id => document.getElementById(id);

    window.openEditModal = function() {
        const modal = $('editModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => $('editQty').focus(), 50);
    };

    window.closeEditModal = function() {
        const modal = $('editModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    window.openDeleteModal = function() {
        const modal = $('deleteModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => $('deletePass').focus(), 50);
    };

    window.closeDeleteModal = function() {
        const modal = $('deleteModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    // Event delegation for dynamically updated table rows
    document.addEventListener('click', e => {
        const edit = e.target.closest('.js-edit');
        if (edit) {
            const btn = edit;
            const d = btn.dataset;
            $('editId').value = d.id;
            $('editNo').textContent = '#' + d.id;
            $('editInfo').textContent = d.name + (d.batch ? ' · batch #' + d.batch : ' · not tied to a batch');
            $('editUnit').textContent = '(' + d.unit + ')';
            $('editQty').value = d.qty;
            $('editQty').max = d.max || '';
            $('editHint').textContent = d.max
                ? 'Up to ' + d.max + ' ' + d.unit + ' (what was wasted plus what is left in the batch).'
                : 'Changing the quantity adjusts stock by the difference.';
            $('editReason').value = d.reason;
            $('editNotes').value = d.notes;
            openEditModal();
            return;
        }
        const del = e.target.closest('.js-delete');
        if (del) {
            $('deleteId').value = del.dataset.id;
            $('deleteLabel').textContent = del.dataset.label;
            $('deletePass').value = '';
            openDeleteModal();
        }
    });
})();
</script>