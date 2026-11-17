<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
$pageTitle = 'Dashboard';
$stats     = [];
$attention = [];

if (can('inventory')) {
    $nostock = $pdo->query('SELECT name, current_stock, unit, reorder_level FROM ingredients WHERE current_stock = 0 ORDER BY name')->fetchAll();
    foreach ($nostock as $i) {
        $attention[] = ['red', sprintf('%s is out of stock.', $i['name']), 'index?page=inventory&stock_status=Out'];
    }
    $low = $pdo->query('SELECT name, current_stock, unit, reorder_level FROM ingredients WHERE current_stock > 0 AND current_stock <= reorder_level ORDER BY name')->fetchAll();
    foreach ($low as $i) {
        $attention[] = ['red', sprintf('%s is low: %s %s left, reorder level is %s.', $i['name'], num($i['current_stock']), $i['unit'], num($i['reorder_level'])), 'index?page=inventory&stock_status=Low'];
    }
    $exp = $pdo->query('SELECT i.name, i.unit, r.quantity_remaining, DATEDIFF(r.expiry_date, CURDATE()) AS days_left
                        FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id
                        WHERE r.quantity_remaining > 0 AND r.expiry_date IS NOT NULL
                          AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) EXPIRY_WARN_DAYS . ' DAY)
                        ORDER BY r.expiry_date')->fetchAll();
    foreach ($exp as $b) {
        $d = (int) $b['days_left'];
        $when = $d < 0 ? 'expired ' . abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago' : ($d === 0 ? 'expires today' : "expires in $d day" . ($d === 1 ? '' : 's'));
        $attention[] = [$d <= 0 ? 'red' : 'amber', sprintf('%s %s %s %s.', num($b['quantity_remaining']), $b['unit'], $b['name'], $when), 'index?page=expiry'];
    }
    array_push($stats, ['Low-stock items', count($low)], ['Batches near expiry', count($exp)]);
}

if (can('waste')) {
    $stats[] = ['Waste entries today', (int) $pdo->query('SELECT COUNT(*) FROM waste_logs WHERE logged_at >= CURDATE()')->fetchColumn()];
}

if (can('safety')) {
    $s = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(passed = 0), 0) AS failed FROM safety_logs WHERE logged_at >= CURDATE()')->fetch();
    $stats[] = ['Safety checks today', (int) $s['total']];
    if ((int) $s['failed'] > 0) {
        $attention[] = ['red', (int) $s['failed'] . ' food safety check' . ((int) $s['failed'] === 1 ? '' : 's') . ' failed today. Confirm the corrective action was done.', 'index?page=safety'];
    } elseif ((int) $s['total'] === 0) {
        $attention[] = ['amber', 'No food safety checks have been logged today.', 'index?page=safety'];
    }
}

// Menu availability: what the kitchen can make with the stock on hand. Shown to every role that may open Recipes
// (the chef gets this as the main part of the dashboard; the numbers come from the same function the Recipes page uses).
$menu = null;
$lowPortions = 5;                                      // "running low" = this many portions or fewer
if (can('recipes')) {
    /** The ingredient that runs out first (the one that limits how many portions can be made). */
    function dash_limiter(array $ings): ?string {
        $best = null; $name = null;
        foreach ($ings as $l) {
            if (!$l['unit_ok']) return $l['name'];
            $per = to_base_milli($l['per_portion'], $l['recipe_unit']);
            if ($per <= 0) continue;
            $n = intdiv(max(0, to_base_milli($l['in_stock'], $l['unit'])), $per);
            if ($best === null || $n < $best) { $best = $n; $name = $l['name']; }
        }
        return $name;
    }
    $menu = ['available' => 0, 'out' => 0, 'norecipe' => 0, 'out_list' => [], 'low_list' => []];
    foreach ($pdo->query('SELECT id, name FROM menu_items ORDER BY name')->fetchAll() as $dish) {
        $rep = get_menu_availability($pdo, (int) $dish['id']);
        if (!$rep['has_recipe']) { $menu['norecipe']++; continue; }
        if (!$rep['available']) {
            $menu['out']++;
            $menu['out_list'][] = ['id' => (int) $dish['id'], 'name' => $dish['name'],
                                   'short' => array_values(array_map(fn($l) => $l['name'], array_filter($rep['ingredients'], fn($l) => $l['is_short'])))];
        } else {
            $menu['available']++;
            if ($rep['max_portions'] !== null && $rep['max_portions'] <= $lowPortions) {
                $menu['low_list'][] = ['id' => (int) $dish['id'], 'name' => $dish['name'], 'portions' => (int) $rep['max_portions'], 'limiter' => dash_limiter($rep['ingredients'])];
            }
        }
    }
    usort($menu['low_list'], fn($a, $b) => $a['portions'] <=> $b['portions']);
}

// Kitchen view (chef; also shown to the administrator): queue numbers, and which ingredients are holding dishes back.
$queue = null;
$hold  = [];
if ($menu !== null) {
    $menu['total'] = $menu['available'] + $menu['out'] + $menu['norecipe'];
    foreach ($menu['out_list'] as $d) foreach ($d['short'] as $n) $hold[$n]['out'] = ($hold[$n]['out'] ?? 0) + 1;
    foreach ($menu['low_list'] as $d) if ($d['limiter']) $hold[$d['limiter']]['low'] = ($hold[$d['limiter']]['low'] ?? 0) + 1;
    uasort($hold, fn($a, $b) => [($b['out'] ?? 0), ($b['low'] ?? 0)] <=> [($a['out'] ?? 0), ($a['low'] ?? 0)]);

    if (can('orders')) {
        $lateSec = (WAIT_LIMIT_MIN['preparing'] ?? 15) * 60;
        $tickets = $pdo->query('SELECT o.id, o.order_number, o.order_type, o.table_number, o.customer_name,
                                       TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS wait_sec
                                FROM orders o WHERE o.ready_at IS NULL ORDER BY o.created_at ASC, o.id ASC')->fetchAll();
        $itemsBy = [];
        if ($tickets) {
            $ids = array_map(fn($t) => (int) $t['id'], $tickets);
            $st  = $pdo->prepare('SELECT order_id, item_name, current_stock AS qty FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) $itemsBy[(int) $r['order_id']][] = $r;
        }
        $queue = [
            'open'   => count($tickets),
            'dishes' => array_sum(array_map(fn($l) => array_sum(array_map(fn($i) => (int) $i['qty'], $l)), $itemsBy)),
            'oldest' => $tickets ? (int) $tickets[0]['wait_sec'] : 0,
            'late'   => count(array_filter($tickets, fn($t) => (int) $t['wait_sec'] >= $lateSec)),
            'ready'  => (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE ready_at >= CURDATE()')->fetchColumn(),
            'list'   => array_slice($tickets, 0, 6),
            'items'  => $itemsBy,
            'lateSec'=> $lateSec,
        ];
    }
}
$chefOnly = $menu !== null && !can('inventory');

/** "7 min", "1 h 05 min" */
function dash_wait(int $sec): string {
    $m = intdiv(max(0, $sec), 60);
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . ' min';
}
$hour     = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// The "Critical Attention" panel is built from inventory and safety data; a role with neither (the chef) has nothing to show there.
$showAttention = $attention || can('inventory') || can('safety');

// Fetch current user info for sidebar header
$stmt = $pdo->prepare('SELECT first_name FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$currentUser = $stmt->fetch();
$name = $currentUser ? $currentUser['first_name'] : 'Error';
?>

<div class="grid gap-6">
    <?php if ($menu !== null): ?>
    <div id="chefLive" class="grid gap-6">

        <!-- Welcome banner -->
        <div class="flex flex-col items-start justify-between gap-4 rounded-2xl bg-gradient-to-r from-slate-900 to-slate-800 text-slate-900 shadow-md sm:flex-row sm:items-center sm:p-8">
            <div>
                <h1 class="text-2xl font-black tracking-tight sm:text-3xl"><?= e($greeting) ?>, Chef!</h1>
                <p class="mt-2 flex items-center gap-1.5 text-xs text-slate-400">
                    Welcome to the kitchen dashboard.
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <?php if (can('orders')): ?><a href="index?page=orders" class="rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-orange-700">Open Order Queue</a><?php endif; ?>
            </div>
        </div>


        <?php if ($chefOnly): ?>
        <?php if ($queue): ?>
        <!-- Queue -->
        <section>
            <h2 class="mb-3 text-lg font-bold text-slate-900">Order Queue</h2>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <dt class="text-xs font-bold uppercase tracking-wider text-slate-400">Open tickets</dt>
                    <dd class="mt-2 text-3xl font-extrabold tabular-nums text-slate-900"><?= (int) $queue['open'] ?></dd>
                    <p class="mt-1 text-xs text-slate-500"><?= (int) $queue['dishes'] ?> dish<?= $queue['dishes'] === 1 ? '' : 'es' ?> to prepare</p>
                </div>
                <div class="rounded-xl border p-5 shadow-sm <?= $queue['late'] ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white' ?>">
                    <dt class="text-xs font-bold uppercase tracking-wider <?= $queue['late'] ? 'text-red-600' : 'text-slate-400' ?>">Running late</dt>
                    <dd class="mt-2 text-3xl font-extrabold tabular-nums <?= $queue['late'] ? 'text-red-700' : 'text-slate-900' ?>"><?= (int) $queue['late'] ?></dd>
                    <p class="mt-1 text-xs <?= $queue['late'] ? 'text-red-600' : 'text-slate-500' ?>">over <?= (int) (WAIT_LIMIT_MIN['preparing'] ?? 15) ?> min waiting</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <dt class="text-xs font-bold uppercase tracking-wider text-slate-400">Longest wait</dt>
                    <dd class="mt-2 text-3xl font-extrabold tabular-nums text-slate-900"><?= $queue['open'] ? e(dash_wait($queue['oldest'])) : '&mdash;' ?></dd>
                    <p class="mt-1 text-xs text-slate-500">oldest open ticket</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <dt class="text-xs font-bold uppercase tracking-wider text-slate-400">Ready today</dt>
                    <dd class="mt-2 text-3xl font-extrabold tabular-nums text-green-700"><?= (int) $queue['ready'] ?></dd>
                    <p class="mt-1 text-xs text-slate-500">tickets marked ready</p>
                </div>
            </div>

            <?php if ($queue['list']): ?>
            <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-6 py-3">
                    <h3 class="text-sm font-bold text-slate-900">Next up</h3>
                    <a href="index?page=orders" class="text-xs font-semibold text-orange-700 hover:underline">View all<?= $queue['open'] > count($queue['list']) ? ' ' . (int) $queue['open'] : '' ?> &rsaquo;</a>
                </div>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($queue['list'] as $t):
                        $late = (int) $t['wait_sec'] >= $queue['lateSec'];
                        $its  = $queue['items'][(int) $t['id']] ?? []; ?>
                        <li class="flex items-start gap-4 px-6 py-3.5 text-sm">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= $late ? 'bg-red-500' : 'bg-amber-400' ?>"></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900"><?= e($t['customer_name']) ?>
                                    <span class="ml-1 text-xs font-medium text-slate-400"><?= e($t['order_type']) ?><?= $t['table_number'] !== null && $t['table_number'] !== '' ? ' &middot; Table ' . e($t['table_number']) : '' ?></span></p>
                                <p class="truncate text-xs text-slate-500"><?= $its ? e(implode(', ', array_map(fn($i) => (int) $i['qty'] . ' × ' . $i['item_name'], $its))) : 'No items' ?></p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums <?= $late ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700' ?>"><?= e(dash_wait((int) $t['wait_sec'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($stats): ?>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ($stats as [$label, $value]): ?>
            <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow">
                <dt class="text-xs font-bold uppercase tracking-wider text-slate-400"><?= e($label) ?></dt>
                <dd class="text-3xl font-extrabold text-slate-900 mt-2 tabular-nums"><?= (int) $value ?></dd>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (can('inventory')): ?>
    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Restock Line Chart -->
        <section class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm" id="dashboardFilters">
                <div class="flex flex-col md:flex-row md:items-center gap-4 justify-between">
                    <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-lg w-full md:w-auto">
                        <button type="button" onclick="setDashboardRange(1, this)" class="range-btn flex-1 md:flex-none px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-lg text-slate-600 hover:bg-white hover:shadow-sm transition-all" data-days="1">1 Day</button>
                        <button type="button" onclick="setDashboardRange(7, this)" class="range-btn active-range flex-1 md:flex-none px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-lg bg-white text-slate-900 shadow-sm transition-all" data-days="7">7 Days</button>
                        <button type="button" onclick="setDashboardRange(30, this)" class="range-btn flex-1 md:flex-none px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-lg text-slate-600 hover:bg-white hover:shadow-sm transition-all" data-days="30">30 Days</button>
                    </div>
                    <!-- Custom Date Range -->
                    <div class="flex items-center gap-3 w-full md:w-auto">
                        <div class="flex items-center gap-2 flex-1 md:flex-none">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">From</span>
                            <input type="date" id="dateFrom" class="flex-1 md:flex-none px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-slate-400 bg-slate-50" onchange="setCustomDateRange()">
                        </div>
                        <div class="flex items-center gap-2 flex-1 md:flex-none">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">To</span>
                            <input type="date" id="dateTo" class="flex-1 md:flex-none px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-slate-400 bg-slate-50" onchange="setCustomDateRange()">
                        </div>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="font-bold text-lg text-slate-900">Inventory Activity</h2>
                        <p class="text-sm text-slate-500">Restock batches trend over time</p>
                    </div>
                </div>
                <div class="relative h-64 w-full">
                    <canvas id="restockTrendChart"></canvas>
                </div>
            </div>
        </section>

        <!-- Most Used Ingredients -->
        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-lg text-slate-900 mb-6">Top Used Ingredients</h2>
            <div id="topIngredientsContainer" class="space-y-5">
                <p class="text-sm text-slate-400 text-center py-6">Loading data...</p>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <?php if ($showAttention): ?>
    <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <h2 class="border-b border-slate-100 bg-slate-50 px-6 py-4 font-bold text-slate-900">Critical Attention Needed</h2>
        <?php if (!$attention): ?>
            <p class="px-6 py-8 text-sm text-slate-500 text-center">All systems operational. No immediate attention needed.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($attention as [$tone, $text, $link]): ?>
                    <li>
                        <a href="<?= e($link) ?>" class="flex items-center gap-4 px-6 py-4 text-sm hover:bg-slate-50 transition-colors">
                            <span class="h-2 w-2 rounded-full shrink-0 <?= $tone === 'red' ? 'bg-red-500' : 'bg-amber-500' ?>"></span>
                            <span class="text-slate-700"><?= e($text) ?></span>
                            <span class="ml-auto text-slate-400 font-medium text-xs">View</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php if ($menu !== null && $chefOnly): ?>
<script>
// Keeps the kitchen view current without a reload: every 15 s the page is fetched again and the live area swapped in.
(function () {
    var box = document.getElementById('chefLive');
    if (!box) return;
    var busy = false;
    function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        fetch('index.php?page=dashboard', {credentials: 'same-origin'})
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var next = new DOMParser().parseFromString(html, 'text/html').getElementById('chefLive');
                if (!next) { location.reload(); return; }
                if (box.innerHTML !== next.innerHTML) box.innerHTML = next.innerHTML;
            })
            .catch(function () {
                var d = document.getElementById('chefDot'), t = document.getElementById('chefStamp');
                if (d) d.className = 'h-2 w-2 rounded-full bg-red-400';
                if (t) t.textContent = 'Offline \u00b7 retrying';
            })
            .finally(function () { busy = false; });
    }
    setInterval(refresh, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
</script>
<?php endif; ?>

<?php if (can('inventory')): ?>
<script>
let restockChart;

async function fetchDashboardData(params, btnElement) {
    if (btnElement) {
        document.querySelectorAll('.range-btn').forEach(b => {
            b.classList.remove('bg-white', 'text-slate-900', 'shadow-sm');
            b.classList.add('text-slate-700');
        });
        btnElement.classList.add('bg-white', 'text-slate-900', 'shadow-sm');
        btnElement.classList.remove('text-slate-700');
    } else {
        document.querySelectorAll('.range-btn').forEach(b => {
            b.classList.remove('bg-white', 'text-slate-900', 'shadow-sm');
            b.classList.add('text-slate-700');
        });
    }

    let url = 'index?page=dashboard_ajax';
    if (params.days) {
        url += `&days=${params.days}`;
    } else if (params.from && params.to) {
        url += `&from=${params.from}&to=${params.to}`;
    }

    try {
        const response = await fetch(url);
        const data = await response.json();

        restockChart.data.labels = data.chartDates;
        restockChart.data.datasets[0].data = data.chartCounts;
        restockChart.update();

        if (data.from && data.to) {
            document.getElementById('dateFrom').value = data.from;
            document.getElementById('dateTo').value = data.to;
        }

        const ingContainer = document.getElementById('topIngredientsContainer');
        if (!data.ingredients || data.ingredients.length === 0) {
            ingContainer.innerHTML = '<p class="text-sm text-slate-500 py-6 text-center">No ingredient usage recorded in this period.</p>';
            return;
        }

        const maxUsed = Math.max(...data.ingredients.map(i => parseFloat(i.total_used)));
        ingContainer.innerHTML = data.ingredients.map((ing, idx) => {
            const pct = maxUsed > 0 ? Math.round((parseFloat(ing.total_used) / maxUsed) * 100) : 0;
            return `
                <div class="group">
                    <div class="flex justify-between text-sm font-bold text-slate-700 mb-2">
                        <span class="truncate pr-4 group-hover:text-slate-900 transition-colors">${idx + 1}. ${escapeHtml(ing.name)}</span>
                        <span class="shrink-0 tabular-nums text-slate-500">${Number(ing.total_used).toLocaleString()} <span class="text-[10px] uppercase">${escapeHtml(ing.unit)}</span></span>
                    </div>
                    <div class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden border border-slate-50">
                        <div class="bg-gradient-to-r from-slate-700 to-slate-900 h-full rounded-full transition-all duration-700 ease-out shadow-sm" style="width: ${pct}%;"></div>
                    </div>
                </div>
            `;
        }).join('');
    } catch (err) {
        console.error('Failed to load dashboard ajax data', err);
    }
}

function setDashboardRange(days, btn) {
    fetchDashboardData({ days }, btn);
}

function setCustomDateRange() {
    const from = document.getElementById('dateFrom').value;
    const to = document.getElementById('dateTo').value;
    if (!from || !to) return;
    fetchDashboardData({ from, to }, null);
}

function escapeHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('restockTrendChart');
    if (!ctx) return;

    restockChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Restock Batches',
                data: [],
                borderColor: '#1e293b',
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    gradient.addColorStop(0, 'rgba(30, 41, 59, 0.0)');
                    gradient.addColorStop(1, 'rgba(30, 41, 59, 0.15)');
                    return gradient;
                },
                borderWidth: 3,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#1e293b',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index',
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleFont: { size: 13, weight: 'bold', family: 'system-ui' },
                    bodyFont: { size: 12, family: 'system-ui' },
                    padding: 12,
                    displayColors: false,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.parsed.y + ' restock batch' + (context.parsed.y === 1 ? '' : 'es');
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { size: 11, weight: '500' }, color: '#94a3b8' },
                    grid: { color: 'rgba(0, 0, 0, 0.03)', drawBorder: false }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11, weight: '500' }, color: '#94a3b8' }
                }
            }
        }
    });

    const defaultBtn = document.querySelector('button[onclick*="setDashboardRange(7"]');
    if (defaultBtn) {
        fetchDashboardData({ days: 7 }, defaultBtn);
    }
});
</script>
<?php endif; ?>