<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('reports');

$from = valid_date($_GET['from'] ?? '') ?? date('Y-m-d', strtotime('-6 days'));
$to   = valid_date($_GET['to'] ?? '')   ?? date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$range = [':from' => $from, ':to' => $to];

function rows(PDO $pdo, string $sql, array $params): array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

$prepared = rows($pdo, "SELECT oi.item_name AS item, SUM(oi.current_stock) AS portions, COUNT(DISTINCT o.id) AS orders
                        FROM order_items oi JOIN orders o ON o.id = oi.order_id
                        WHERE o.ready_at IS NOT NULL AND o.ready_at >= :from AND o.ready_at < DATE_ADD(:to, INTERVAL 1 DAY)
                        GROUP BY oi.item_name ORDER BY portions DESC", $range);
$usage = rows($pdo, 'SELECT i.name, i.unit, SUM(u.current_stock) AS used FROM usage_logs u JOIN ingredients i ON i.id = u.ingredient_id
                     WHERE u.used_at >= :from AND u.used_at < DATE_ADD(:to, INTERVAL 1 DAY)
                     GROUP BY i.id, i.name, i.unit ORDER BY used DESC', $range);
$waste = rows($pdo, 'SELECT i.name, i.unit, SUM(w.current_stock) AS qty, COUNT(*) AS entries FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id
                     WHERE w.logged_at >= :from AND w.logged_at < DATE_ADD(:to, INTERVAL 1 DAY)
                     GROUP BY i.id, i.name, i.unit ORDER BY entries DESC, qty DESC', $range);
$wasteByReason = rows($pdo, 'SELECT reason, COUNT(*) AS entries FROM waste_logs
                             WHERE logged_at >= :from AND logged_at < DATE_ADD(:to, INTERVAL 1 DAY)
                             GROUP BY reason ORDER BY entries DESC', $range);
$safety = rows($pdo, 'SELECT COUNT(*) AS total, COALESCE(SUM(passed = 0), 0) AS failed FROM safety_logs
                      WHERE logged_at >= :from AND logged_at < DATE_ADD(:to, INTERVAL 1 DAY)', $range)[0];
$stock  = $pdo->query('SELECT name, unit, current_stock, reorder_level, storage_location FROM ingredients ORDER BY (current_stock <= reorder_level) DESC, name')->fetchAll();

$totalPortions = array_sum(array_column($prepared, 'portions'));

$pageTitle = 'Production reports';

$th = 'px-4 py-2 font-medium';
?>

<form method="GET" class="flex flex-wrap items-end gap-3 print:hidden">
    <input type="hidden" name="page" value="reports">
    <label class="text-sm font-medium">From <input type="date" name="from" value="<?= e($from) ?>" class="mt-1 block rounded border border-slate-300 px-3 py-2 font-normal"></label>
    <label class="text-sm font-medium">To <input type="date" name="to" value="<?= e($to) ?>" class="mt-1 block rounded border border-slate-300 px-3 py-2 font-normal"></label>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">Update report</button>
    <button type="button" onclick="window.print()" class="rounded border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">Print</button>
</form>

<dl class="flex flex-wrap gap-x-10 gap-y-4 rounded-lg border border-slate-200 bg-white px-5 py-4">
    <div><dt class="text-sm text-slate-500">Portions prepared</dt><dd class="text-2xl font-semibold tabular-nums"><?= (int) $totalPortions ?></dd></div>
    <div><dt class="text-sm text-slate-500">Ingredients used</dt><dd class="text-2xl font-semibold tabular-nums"><?= count($usage) ?></dd></div>
    <div><dt class="text-sm text-slate-500">Waste entries</dt><dd class="text-2xl font-semibold tabular-nums"><?= array_sum(array_column($waste, 'entries')) ?></dd></div>
    <div><dt class="text-sm text-slate-500">Safety checks (failed)</dt><dd class="text-2xl font-semibold tabular-nums"><?= (int) $safety['total'] ?> <span class="text-base font-normal text-slate-500">(<?= (int) $safety['failed'] ?>)</span></dd></div>
</dl>

<div class="grid gap-6 lg:grid-cols-2">
    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Food prepared</h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="<?= $th ?>">Menu item</th><th class="font-medium">Orders</th><th class="font-medium">Portions</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($prepared as $r): ?><tr><td class="px-4 py-2"><?= e($r['item']) ?></td><td class="tabular-nums"><?= (int) $r['orders'] ?></td><td class="tabular-nums"><?= (int) $r['portions'] ?></td></tr><?php endforeach; ?>
                <?php if (!$prepared): ?><tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">No orders were completed in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Ingredient usage</h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="<?= $th ?>">Ingredient</th><th class="font-medium">Used</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($usage as $r): ?><tr><td class="px-4 py-2"><?= e($r['name']) ?></td><td class="tabular-nums"><?= e(num($r['used'])) ?> <?= e($r['unit']) ?></td></tr><?php endforeach; ?>
                <?php if (!$usage): ?><tr><td colspan="2" class="px-4 py-6 text-center text-slate-500">No ingredients were used in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Waste</h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="<?= $th ?>">Ingredient</th><th class="font-medium">Wasted</th><th class="font-medium">Entries</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($waste as $r): ?><tr><td class="px-4 py-2"><?= e($r['name']) ?></td><td class="tabular-nums"><?= e(num($r['qty'])) ?> <?= e($r['unit']) ?></td><td class="tabular-nums"><?= (int) $r['entries'] ?></td></tr><?php endforeach; ?>
                <?php if (!$waste): ?><tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">No waste was logged in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ($wasteByReason): ?>
            <p class="flex flex-wrap gap-2 border-t border-slate-200 px-4 py-3 text-sm text-slate-600">By reason:
                <?php foreach ($wasteByReason as $r): ?><?= badge((WASTE_REASONS[$r['reason']] ?? $r['reason']) . ': ' . (int) $r['entries']) ?><?php endforeach; ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Stock levels now</h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="<?= $th ?>">Ingredient</th><th class="font-medium">In stock</th><th class="font-medium">Reorder at</th><th class="font-medium">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($stock as $r): $low = (float) $r['current_stock'] <= (float) $r['reorder_level']; ?>
                    <tr>
                        <td class="px-4 py-2"><?= e($r['name']) ?></td>
                        <td class="tabular-nums"><?= e(num($r['current_stock'])) ?> <?= e($r['unit']) ?></td>
                        <td class="tabular-nums"><?= e(num($r['reorder_level'])) ?></td>
                        <td><?= $low ? badge('Low', 'red') : badge('OK', 'green') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
