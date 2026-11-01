<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
$pageTitle = 'Dashboard';
$stats     = [];
$attention = [];

if (can('inventory')) {
    $low = $pdo->query('SELECT name, current_stock, unit, reorder_level FROM ingredients WHERE current_stock <= reorder_level ORDER BY name')->fetchAll();
    foreach ($low as $i) {
        $attention[] = ['red', sprintf('%s is low: %s %s left, reorder level is %s.', $i['name'], num($i['current_stock']), $i['unit'], num($i['reorder_level'])), 'index.php?page=inventory&stock_status=Low'];
    }
    $exp = $pdo->query('SELECT i.name, i.unit, r.quantity_remaining, DATEDIFF(r.expiry_date, CURDATE()) AS days_left
                        FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id
                        WHERE r.quantity_remaining > 0 AND r.expiry_date IS NOT NULL
                          AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) EXPIRY_WARN_DAYS . ' DAY)
                        ORDER BY r.expiry_date')->fetchAll();
    foreach ($exp as $b) {
        $d = (int) $b['days_left'];
        $when = $d < 0 ? 'expired ' . abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago' : ($d === 0 ? 'expires today' : "expires in $d day" . ($d === 1 ? '' : 's'));
        $attention[] = [$d <= 0 ? 'red' : 'amber', sprintf('%s %s %s %s.', num($b['quantity_remaining']), $b['unit'], $b['name'], $when), 'index.php?page=expiry'];
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
        $attention[] = ['red', (int) $s['failed'] . ' food safety check' . ((int) $s['failed'] === 1 ? '' : 's') . ' failed today. Confirm the corrective action was done.', 'index.php?page=safety'];
    } elseif ((int) $s['total'] === 0) {
        $attention[] = ['amber', 'No food safety checks have been logged today.', 'index.php?page=safety'];
    }
}
?>

<div class="grid gap-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ($stats as [$label, $value]): ?>
            <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow">
                <dt class="text-xs font-bold uppercase tracking-wider text-slate-400"><?= e($label) ?></dt>
                <dd class="text-3xl font-extrabold text-slate-900 mt-2 tabular-nums"><?= (int) $value ?></dd>
            </div>
        <?php endforeach; ?>
    </div>

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
</div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Needs attention</h2>
        <?php if (!$attention): ?>
            <p class="px-4 py-6 text-sm text-slate-500">Nothing needs attention right now.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($attention as [$tone, $text, $link]): ?>
                    <li>
                        <a href="<?= e($link) ?>" class="flex items-start gap-3 px-4 py-3 text-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-orange-500">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= $tone === 'red' ? 'bg-red-600' : 'bg-amber-500' ?>"></span>
                            <span><?= e($text) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let restockChart;

async function fetchDashboardData(params, btnElement) {
    // params can be { days: number } or { from: string, to: string }
    if (btnElement) {
        document.querySelectorAll('.range-btn').forEach(b => {
            b.classList.remove('bg-white', 'text-slate-900', 'shadow-sm');
            b.classList.add('text-slate-700');
        });
        btnElement.classList.add('bg-white', 'text-slate-900', 'shadow-sm');
        btnElement.classList.remove('text-slate-700');
    } else {
        // Custom date range - clear active button state
        document.querySelectorAll('.range-btn').forEach(b => {
            b.classList.remove('bg-white', 'text-slate-900', 'shadow-sm');
            b.classList.add('text-slate-700');
        });
    }

    let url = 'index.php?page=dashboard_ajax';
    if (params.days) {
        url += `&days=${params.days}`;
    } else if (params.from && params.to) {
        url += `&from=${params.from}&to=${params.to}`;
    }

    try {
        const response = await fetch(url);
        const data = await response.json();

        // Update Chart
        restockChart.data.labels = data.chartDates;
        restockChart.data.datasets[0].data = data.chartCounts;
        restockChart.update();

        // Reflect date range into From/To date inputs if returned
        if (data.from && data.to) {
            document.getElementById('dateFrom').value = data.from;
            document.getElementById('dateTo').value = data.to;
        }

        // Update Most Used Ingredients
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

    // Default 7 days click active on 7D button
    const defaultBtn = document.querySelector('button[onclick*="setDashboardRange(7"]');
    fetchDashboardData({ days: 7 }, defaultBtn);
});
</script>
