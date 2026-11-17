<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_perm('users');

// Filtering
$q        = trim($_GET['q'] ?? '');
$type     = trim($_GET['type'] ?? 'all');
$dateFrom = valid_date($_GET['date_from'] ?? '');
$dateTo   = valid_date($_GET['date_to'] ?? '');

$sql = 'SELECT a.*, u.first_name, u.middle_name, u.last_name, u.suffix, u.role
        FROM user_activity_log a
        JOIN users u ON u.id = a.user_id';
$params = [];

$where = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where[] = '(u.first_name LIKE ? OR u.middle_name LIKE ? OR u.last_name LIKE ? OR u.suffix LIKE ? OR a.description LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($type !== 'all' && $type !== '') {
    $where[] = 'a.activity_type = ?';
    $params[] = $type;
}
if ($dateFrom) {
    $where[] = 'DATE(a.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'DATE(a.created_at) <= ?';
    $params[] = $dateTo;
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get distinct activity types for filter dropdown
$types = $pdo->query('SELECT DISTINCT activity_type FROM user_activity_log ORDER BY activity_type')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'User Activity';
?>
<div class="rounded-lg border border-slate-200 bg-white p-4 mb-6">
    <form id="filterForm" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="user_activity">

        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, description..." class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
        </div>

        <div class="w-40">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Activity Type</label>
            <select name="type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                <option value="all">All Types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $t))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="w-40">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">From Date</label>
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
        </div>

        <div class="w-40">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">To Date</label>
            <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
        </div>

        <div class="flex gap-2">
            <a href="index?page=user_activity" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear Filters</a>
        </div>
    </form>
</div>

<div id="tableContainer">
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">When</th>
                    <th class="font-medium">User</th>
                    <th class="font-medium">Role</th>
                    <th class="font-medium">Activity</th>
                    <th class="font-medium">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap"><?= e(date('j M Y, H:i:s', strtotime($log['created_at']))) ?></td>
                        <td class="font-medium"><?php $fn = trim(implode(' ', array_filter([$log['first_name'], $log['middle_name'], $log['last_name'], $log['suffix']]))); echo e($fn ?: '—'); ?></td>
                        <td><?= e(ROLE_LABELS[$log['role']] ?? $log['role']) ?></td>
                        <td>
                            <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide bg-blue-100 text-blue-800">
                                <?= e(ucfirst(str_replace('_', ' ', $log['activity_type']))) ?>
                            </span>
                        </td>
                        <td class="max-w-md text-slate-600"><?= e($log['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No activity found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (count($logs) >= 200): ?>
        <p class="mt-4 text-sm text-slate-500">Showing latest 200 entries. Use filters to narrow results.</p>
    <?php endif; ?>
</div>

<script>
function debounce(fn, delay) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn.apply(this, args), delay);
    };
}

function applyFilters() {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    const url = 'index?' + params.toString();

    // Update URL without reload
    window.history.replaceState({}, '', url);

    // Fetch and replace table container
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newContainer = doc.getElementById('tableContainer');
            if (newContainer) {
                document.getElementById('tableContainer').replaceWith(newContainer);
            }
        })
        .catch(err => console.error('Filter error:', err));
}

// Debounced filter on input/change
const debouncedFilter = debounce(applyFilters, 300);
document.getElementById('filterForm').addEventListener('input', debouncedFilter);
document.getElementById('filterForm').addEventListener('change', debouncedFilter);

// Also trigger on Enter key in search input
document.querySelector('input[name="q"]').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        applyFilters();
    }
});
</script>