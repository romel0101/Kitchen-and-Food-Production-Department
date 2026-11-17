<?php
// sections/reports.php - Production and inventory reports with date filtering, summaries, and print support.
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_perm('reports');

// =====================================================================================================
// 1. The date filter
//    Quick buttons (?range=today|yesterday|7d|30d|month|last_month) or typed dates (?from=...&to=...).
//    Anything invalid falls back to the last 7 days AND tells the user, instead of silently ignoring it.
// =====================================================================================================
$today   = new DateTimeImmutable('today');
$presets = [
    'today'      => ['Today',        $today,                                   $today],
    'yesterday'  => ['Yesterday',    $today->modify('-1 day'),                 $today->modify('-1 day')],
    '7d'         => ['Last 7 days',  $today->modify('-6 days'),                $today],
    '30d'        => ['Last 30 days', $today->modify('-29 days'),               $today],
    'month'      => ['This month',   $today->modify('first day of this month'), $today],
    'last_month' => ['Last month',   $today->modify('first day of last month'), $today->modify('last day of last month')],
];
const REPORT_MAX_DAYS = 366;

$notices = [];
$rangeKey = $_GET['range'] ?? '';
if (is_string($rangeKey) && isset($presets[$rangeKey])) {
    [, $fromDt, $toDt] = $presets[$rangeKey];
} else {
    $rawFrom = trim((string) ($_GET['from'] ?? ''));
    $rawTo   = trim((string) ($_GET['to'] ?? ''));
    $fromDt  = ($d = valid_date($rawFrom)) ? new DateTimeImmutable($d) : null;
    $toDt    = ($d = valid_date($rawTo))   ? new DateTimeImmutable($d) : null;

    if (($rawFrom !== '' && !$fromDt) || ($rawTo !== '' && !$toDt)) {
        $notices[] = 'One of the dates was not valid, so the missing date was filled in.';
    }
    $toDt   ??= $today;                                   // only "from" given -> up to today
    $fromDt ??= $toDt->modify('-6 days');                 // nothing given -> the 7 days ending at "to"
}
if ($fromDt > $toDt) {
    [$fromDt, $toDt] = [$toDt, $fromDt];
    $notices[] = 'The "From" date was after the "To" date, so they were swapped.';
}
if ($toDt > $today) {
    $toDt = $today;
    $notices[] = 'Future dates have no data, so the report stops at today.';
}
if ($fromDt > $toDt) $fromDt = $toDt;
if ($fromDt->diff($toDt)->days + 1 > REPORT_MAX_DAYS) {
    $fromDt = $toDt->modify('-' . (REPORT_MAX_DAYS - 1) . ' days');
    $notices[] = 'A report can cover at most ' . REPORT_MAX_DAYS . ' days, so the start date was moved.';
}

$from   = $fromDt->format('Y-m-d');
$to     = $toDt->format('Y-m-d');
$days   = $fromDt->diff($toDt)->days + 1;
// Whole days as a half-open interval: [from 00:00:00, day after "to" 00:00:00). Includes all of the last day.
$range  = [':from' => $from . ' 00:00:00', ':to_end' => $toDt->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];

$activeKey = '';                                          // which quick button matches the dates shown
foreach ($presets as $key => [, $pf, $pt]) {
    if ($pf->format('Y-m-d') === $from && $pt->format('Y-m-d') === $to) { $activeKey = $key; break; }
}
$periodLabel = $from === $to ? $fromDt->format('j M Y') : $fromDt->format('j M Y') . ' to ' . $toDt->format('j M Y');

// =====================================================================================================
// 2. The numbers
// =====================================================================================================
if (!function_exists('report_rows')) {
    function report_rows(PDO $pdo, string $sql, array $params = []): array {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }
}

$prepared = report_rows($pdo, 'SELECT oi.item_name AS item, SUM(oi.current_stock) AS portions, COUNT(DISTINCT o.id) AS orders
                               FROM order_items oi JOIN orders o ON o.id = oi.order_id
                               WHERE o.ready_at IS NOT NULL AND o.ready_at >= :from AND o.ready_at < :to_end
                               GROUP BY oi.item_name ORDER BY portions DESC, item', $range);
$usage = report_rows($pdo, 'SELECT i.name, i.unit, SUM(u.current_stock) AS used FROM usage_logs u JOIN ingredients i ON i.id = u.ingredient_id
                            WHERE u.used_at >= :from AND u.used_at < :to_end
                            GROUP BY i.id, i.name, i.unit ORDER BY used DESC, i.name', $range);
$waste = report_rows($pdo, 'SELECT i.name, i.unit, SUM(w.current_stock) AS qty, COUNT(*) AS entries FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id
                            WHERE w.logged_at >= :from AND w.logged_at < :to_end
                            GROUP BY i.id, i.name, i.unit ORDER BY entries DESC, qty DESC', $range);
$wasteByReason = report_rows($pdo, 'SELECT reason, COUNT(*) AS entries FROM waste_logs
                                    WHERE logged_at >= :from AND logged_at < :to_end
                                    GROUP BY reason ORDER BY entries DESC', $range);
$safety = report_rows($pdo, 'SELECT COUNT(*) AS total, COALESCE(SUM(passed = 0), 0) AS failed FROM safety_logs
                             WHERE logged_at >= :from AND logged_at < :to_end', $range)[0];
$stock  = $pdo->query('SELECT name, unit, current_stock, reorder_level, storage_location FROM ingredients
                       ORDER BY (current_stock <= reorder_level) DESC, name')->fetchAll();

// Ingredient receiving: every batch received in the period (the batch ledger), and what is still in stock.
$received = report_rows($pdo, 'SELECT r.id, r.received_at, r.current_stock AS qty, r.quantity_remaining AS remaining, r.cost_per_unit,
                                      r.supplier, r.invoice_number, r.expiry_date, i.name, i.unit,
                                      u.email, u.first_name, u.last_name
                               FROM stock_receipts r
                               JOIN ingredients i ON i.id = r.ingredient_id
                               LEFT JOIN users u  ON u.id = r.received_by
                               WHERE r.received_at >= :from AND r.received_at < :to_end
                               ORDER BY r.received_at DESC, r.id DESC', $range);
$receivedBy = [];                                    // per ingredient: total received and cost (units differ, so never add across ingredients)
$receivedCost = 0.0;
foreach ($received as $r) {
    $cost = (float) $r['qty'] * (float) $r['cost_per_unit'];
    $receivedCost += $cost;
    $k = $r['name'];
    $receivedBy[$k] ??= ['unit' => $r['unit'], 'qty' => 0.0, 'cost' => 0.0, 'batches' => 0];
    $receivedBy[$k]['qty']     += (float) $r['qty'];
    $receivedBy[$k]['cost']    += $cost;
    $receivedBy[$k]['batches'] += 1;
}
ksort($receivedBy);
$activeBatches = $pdo->query('SELECT r.id, r.received_at, r.current_stock AS qty, r.quantity_remaining AS remaining, r.supplier, r.expiry_date,
                                     i.name, i.unit, i.id AS ingredient_id
                              FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id
                              WHERE r.quantity_remaining > 0
                              ORDER BY i.name, r.expiry_date IS NULL, r.expiry_date, r.received_at')->fetchAll();

$totalPortions = (int) array_sum(array_column($prepared, 'portions'));
$totalWaste    = (int) array_sum(array_column($waste, 'entries'));

$pageTitle = 'Production reports';
$th = 'px-4 py-2.5 font-semibold text-slate-700';
?>

<style>
    /* Print PDF styling */
    @media print {
        body { display: block !important; height: auto !important; overflow: visible !important; background: #fff !important; }
        body > div { overflow: visible !important; }
        header, #sidebar { display: none !important; }
        main { padding: 0 !important; }
        section { break-inside: avoid; }
    }
</style>

<!-- Header / Filter Bar -->
<div class="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm print:hidden">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-end justify-start gap-4">
            <div class="flex flex-col gap-1">
                <label for="rep-from" class="text-xs font-bold uppercase tracking-wider text-slate-500">Filter by Date</label>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Quick date ranges">
                        <?php foreach ($presets as $key => [$label]): $on = $activeKey === $key; ?>
                            <a href="index.php?page=reports&amp;range=<?= e($key) ?>" <?= $on ? 'aria-current="true"' : '' ?>
                            class="rounded-md border px-3 py-1.5 text-sm font-medium transition-colors <?= $on ? 'border-slate-900 bg-slate-900 text-white shadow-sm' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' ?>">
                                <?= e($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Real-time filtering form (submits automatically on date change) -->
            <form method="GET" action="index.php" id="customDateForm" class="flex flex-wrap items-end gap-4 pt-2">
                <input type="hidden" name="page" value="reports">
                <div class="flex flex-col gap-1">
                    <label for="rep-from" class="text-xs font-bold uppercase tracking-wider text-slate-500">From Date</label>
                    <input id="rep-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e($today->format('Y-m-d')) ?>" required onchange="this.form.submit()" class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                </div>
                <div class="flex flex-col gap-1">
                    <label for="rep-to" class="text-xs font-bold uppercase tracking-wider text-slate-500">To Date</label>
                    <input id="rep-to" type="date" name="to" value="<?= e($to) ?>" max="<?= e($today->format('Y-m-d')) ?>" required onchange="this.form.submit()" class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                </div>
            </form>
        </div>

        <button type="button" onclick="window.print()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors flex items-center gap-1.5">
            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print PDF Report
        </button>
    </div>

    <?php foreach ($notices as $n): ?>
        <p role="status" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"><?= e($n) ?></p>
    <?php endforeach; ?>
</div>

<!-- Report Summary Heading -->
<div class="mb-6 rounded-xl border border-slate-200 bg-white px-5 py-3.5 text-sm text-slate-600 shadow-sm flex items-center justify-between">
    <div>
        Showing Report Range: <strong class="text-slate-900"><?= e($periodLabel) ?></strong>
        <span class="text-slate-400">(<?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?>)</span>
    </div>
    <div class="text-xs text-slate-400 hidden sm:block">Note: Current stock reflects real-time levels regardless of date filter.</div>
</div>

<!-- Key Metrics Grid -->
<div class="grid grid-cols-2 gap-4 lg:grid-cols-4 mb-6">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Portions Prepared</p>
        <p class="mt-2 text-3xl font-black text-slate-900 tabular-nums"><?= $totalPortions ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Ingredients Used</p>
        <p class="mt-2 text-3xl font-black text-slate-900 tabular-nums"><?= count($usage) ?></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Waste Entries</p>
        <p class="mt-2 text-3xl font-black text-slate-900 tabular-nums"><?= $totalWaste ?></p>
    </div>
    <div class="rounded-xl border <?= (int) $safety['failed'] > 0 ? 'border-red-200 bg-red-50/50' : 'border-slate-200 bg-white' ?> p-5 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider <?= (int) $safety['failed'] > 0 ? 'text-red-700' : 'text-slate-400' ?>">Safety Checks Failed</p>
        <p class="mt-2 text-3xl font-black <?= (int) $safety['failed'] > 0 ? 'text-red-900' : 'text-slate-900' ?> tabular-nums">
            <?= (int) $safety['failed'] ?> <span class="text-sm font-medium text-slate-500">of <?= (int) $safety['total'] ?></span>
        </p>
    </div>
</div>

<!-- Tables Grid Section 1 -->
<div class="grid gap-6 lg:grid-cols-2 mb-6">
    <!-- Food Prepared -->
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5"><h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Food prepared</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr><th class="<?= $th ?>">Menu item</th><th class="px-4 py-2.5 font-semibold">Orders</th><th class="px-4 py-2.5 font-semibold">Portions</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($prepared as $r): ?>
                        <tr class="hover:bg-slate-50/50"><td class="px-4 py-2.5 font-medium text-slate-900"><?= e($r['item']) ?></td><td class="px-4 py-2.5 tabular-nums text-slate-600"><?= (int) $r['orders'] ?></td><td class="px-4 py-2.5 tabular-nums text-slate-600"><?= (int) $r['portions'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$prepared): ?><tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No orders were completed in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Ingredient Usage -->
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5"><h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Ingredient usage</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr><th class="<?= $th ?>">Ingredient</th><th class="px-4 py-2.5 font-semibold">Used</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($usage as $r): ?>
                        <tr class="hover:bg-slate-50/50"><td class="px-4 py-2.5 font-medium text-slate-900"><?= e($r['name']) ?></td><td class="px-4 py-2.5 tabular-nums text-slate-600"><?= e(num($r['used'])) ?> <?= e($r['unit']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$usage): ?><tr><td colspan="2" class="px-4 py-8 text-center text-slate-500">No ingredients were used in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Waste Log Summary -->
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5"><h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Waste</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr><th class="<?= $th ?>">Ingredient</th><th class="px-4 py-2.5 font-semibold">Wasted</th><th class="px-4 py-2.5 font-semibold">Entries</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($waste as $r): ?>
                        <tr class="hover:bg-slate-50/50"><td class="px-4 py-2.5 font-medium text-slate-900"><?= e($r['name']) ?></td><td class="px-4 py-2.5 tabular-nums text-slate-600"><?= e(num($r['qty'])) ?> <?= e($r['unit']) ?></td><td class="px-4 py-2.5 tabular-nums text-slate-600"><?= (int) $r['entries'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$waste): ?><tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No waste was logged in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($wasteByReason): ?>
            <div class="flex flex-wrap gap-2 border-t border-slate-200 bg-slate-50/50 px-5 py-3 text-sm text-slate-600 items-center">
                <span class="font-semibold text-slate-700">By reason:</span>
                <?php foreach ($wasteByReason as $r): ?><?= badge((WASTE_REASONS[$r['reason']] ?? $r['reason']) . ': ' . (int) $r['entries']) ?><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Current Stock Levels -->
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5"><h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Stock levels now</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr><th class="<?= $th ?>">Ingredient</th><th class="px-4 py-2.5 font-semibold">In stock</th><th class="px-4 py-2.5 font-semibold">Reorder at</th><th class="px-4 py-2.5 font-semibold">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($stock as $r): $low = (float) $r['current_stock'] <= (float) $r['reorder_level']; ?>
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-4 py-2.5 font-medium text-slate-900"><?= e($r['name']) ?></td>
                            <td class="px-4 py-2.5 tabular-nums text-slate-600"><?= e(num($r['current_stock'])) ?> <?= e($r['unit']) ?></td>
                            <td class="px-4 py-2.5 tabular-nums text-slate-600"><?= e(num($r['reorder_level'])) ?></td>
                            <td class="px-4 py-2.5"><?= $low ? badge('Low', 'red') : badge('OK', 'green') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$stock): ?><tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No ingredients yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- Ingredient receiving / batches -->
<section class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5">
        <h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Ingredients received <span class="font-normal text-slate-500 lowercase">(batches, <?= e($periodLabel) ?>)</span></h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="<?= $th ?>">Batch</th><th class="px-4 py-2.5 font-semibold">Received</th><th class="px-4 py-2.5 font-semibold">Ingredient</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Quantity</th><th class="px-4 py-2.5 font-semibold text-right">Left</th>
                    <th class="px-4 py-2.5 font-semibold">Supplier</th><th class="px-4 py-2.5 font-semibold">Invoice</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Cost / unit</th><th class="px-4 py-2.5 font-semibold text-right">Total cost</th>
                    <th class="px-4 py-2.5 font-semibold">Expiry</th><th class="px-4 py-2.5 font-semibold">By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($received as $r):
                    $by = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['email'] ?? '—'); ?>
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-4 py-2.5 font-semibold text-slate-900">#<?= (int) $r['id'] ?></td>
                        <td class="px-4 py-2.5 whitespace-nowrap text-slate-600"><?= e(date('j M Y, H:i', strtotime($r['received_at']))) ?></td>
                        <td class="px-4 py-2.5 font-medium text-slate-900"><?= e($r['name']) ?></td>
                        <td class="px-4 py-2.5 tabular-nums text-right text-slate-600"><?= e(num($r['qty'])) ?> <?= e($r['unit']) ?></td>
                        <td class="px-4 py-2.5 tabular-nums text-right <?= (float) $r['remaining'] <= 0 ? 'text-slate-400' : 'text-slate-600' ?>"><?= e(num($r['remaining'])) ?></td>
                        <td class="px-4 py-2.5 text-slate-600"><?= e($r['supplier'] ?? '—') ?></td>
                        <td class="px-4 py-2.5 text-slate-600"><?= e($r['invoice_number'] ?? '—') ?></td>
                        <td class="px-4 py-2.5 tabular-nums text-right text-slate-600"><?= e(number_format((float) $r['cost_per_unit'], 2)) ?></td>
                        <td class="px-4 py-2.5 tabular-nums text-right font-medium text-slate-900"><?= e(number_format((float) $r['qty'] * (float) $r['cost_per_unit'], 2)) ?></td>
                        <td class="px-4 py-2.5 whitespace-nowrap text-slate-600"><?= $r['expiry_date'] ? e(date('j M Y', strtotime($r['expiry_date']))) : '—' ?></td>
                        <td class="px-4 py-2.5 text-slate-600"><?= e($by) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$received): ?><tr><td colspan="11" class="px-4 py-8 text-center text-slate-500">No ingredient batches were received in this period.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($received): ?>
                <tfoot class="border-t border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                    <tr><td colspan="8" class="px-4 py-3 text-right">Total cost of batches received</td><td class="px-4 py-3 tabular-nums text-right"><?= e(number_format($receivedCost, 2)) ?></td><td colspan="2"></td></tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<!-- Bottom Grid Section 2 -->
<div class="grid gap-6 lg:grid-cols-2 mb-6">
    <!-- Received Per Ingredient -->
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5">
            <h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Received per ingredient <span class="font-normal text-slate-500 lowercase">(<?= e($periodLabel) ?>)</span></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr><th class="<?= $th ?>">Ingredient</th><th class="px-4 py-2.5 font-semibold text-right">Batches</th><th class="px-4 py-2.5 font-semibold text-right">Quantity</th><th class="px-4 py-2.5 font-semibold text-right">Cost</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($receivedBy as $name => $g): ?>
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-4 py-2.5 font-medium text-slate-900"><?= e($name) ?></td>
                            <td class="px-4 py-2.5 tabular-nums text-right text-slate-600"><?= (int) $g['batches'] ?></td>
                            <td class="px-4 py-2.5 tabular-nums text-right text-slate-600"><?= e(num($g['qty'])) ?> <?= e($g['unit']) ?></td>
                            <td class="px-4 py-2.5 tabular-nums text-right font-medium text-slate-900"><?= e(number_format($g['cost'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$receivedBy): ?><tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Nothing received in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Active Batches in Stock -->
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50/50 px-5 py-3.5">
            <h2 class="font-bold text-slate-900 text-sm uppercase tracking-wider">Batches in stock now <span class="font-normal text-slate-500 lowercase">(not date-filtered)</span></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr><th class="<?= $th ?>">Ingredient</th><th class="px-4 py-2.5 font-semibold">Batch</th><th class="px-4 py-2.5 font-semibold text-right">Left</th><th class="px-4 py-2.5 font-semibold">Supplier</th><th class="px-4 py-2.5 font-semibold">Expiry</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($activeBatches as $b):
                        $daysLeft = $b['expiry_date'] ? (int) floor((strtotime($b['expiry_date']) - strtotime('today')) / 86400) : null; ?>
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-4 py-2.5 font-medium text-slate-900"><?= e($b['name']) ?></td>
                            <td class="px-4 py-2.5 text-slate-600">#<?= (int) $b['id'] ?></td>
                            <td class="px-4 py-2.5 tabular-nums text-right text-slate-600"><?= e(num($b['remaining'])) ?> <span class="text-xs text-slate-400">of <?= e(num($b['qty'])) ?> <?= e($b['unit']) ?></span></td>
                            <td class="px-4 py-2.5 text-slate-600"><?= e($b['supplier'] ?? '—') ?></td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">
                                <?php if ($daysLeft === null): ?>—
                                <?php else: ?><?= e(date('j M Y', strtotime($b['expiry_date']))) ?>
                                    <?php if ($daysLeft < 0): ?><?= badge('Expired', 'red') ?><?php elseif ($daysLeft <= EXPIRY_WARN_DAYS): ?><?= badge($daysLeft === 0 ? 'Today' : $daysLeft . ' d', 'amber') ?><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$activeBatches): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No batches are in stock.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>