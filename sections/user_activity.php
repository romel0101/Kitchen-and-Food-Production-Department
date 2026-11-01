<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'clear_logs') {
        $pdo->exec('DELETE FROM user_activity_log');
        flash('success', 'Activity log cleared.');
        redirect('index.php?page=user_activity');
    }
}

// Filtering
$q        = trim($_GET['q'] ?? '');
$type     = trim($_GET['type'] ?? 'all');
$dateFrom = valid_date($_GET['date_from'] ?? '');
$dateTo   = valid_date($_GET['date_to'] ?? '');

$sql = 'SELECT a.*, u.username, u.role
        FROM user_activity_log a
        JOIN users u ON u.id = a.user_id';
$params = [];

$where = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where[] = '(u.username LIKE :q OR a.description LIKE :q OR a.ip_address LIKE :q)';
    $params[':q'] = $like;
}
if ($type !== 'all' && $type !== '') {
    $where[] = 'a.activity_type = :type';
    $params[':type'] = $type;
}
if ($dateFrom) {
    $where[] = 'DATE(a.created_at) >= :df';
    $params[':df'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'DATE(a.created_at) <= :dt';
    $params[':dt'] = $dateTo;
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
    <h2 class="mb-3 font-semibold">Filters</h2>
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="user_activity">

        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Username, description, IP..." class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
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
            <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
            <?php if ($q !== '' || $type !== 'all' || $dateFrom || $dateTo): ?>
                <a href="index.php?page=user_activity" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Clear the entire activity log? This cannot be undone.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_logs">
                <button class="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Clear Log</button>
            </form>
        </div>
    </form>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">When</th>
                <th class="font-medium">User</th>
                <th class="font-medium">Role</th>
                <th class="font-medium">Activity</th>
                <th class="font-medium">Description</th>
                <th class="font-medium">IP Address</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="px-4 py-2 whitespace-nowrap"><?= e(date('j M Y, H:i:s', strtotime($log['created_at']))) ?></td>
                    <td class="font-medium"><?= e($log['username']) ?></td>
                    <td><?= e(ROLE_LABELS[$log['role']] ?? $log['role']) ?></td>
                    <td>
                        <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide bg-blue-100 text-blue-800">
                            <?= e(ucfirst(str_replace('_', ' ', $log['activity_type']))) ?>
                        </span>
                    </td>
                    <td class="max-w-md text-slate-600"><?= e($log['description']) ?></td>
                    <td class="whitespace-nowrap font-mono text-xs text-slate-500"><?= e($log['ip_address'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No activity found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (count($logs) >= 200): ?>
    <p class="mt-4 text-sm text-slate-500">Showing latest 200 entries. Use filters to narrow results.</p>
<?php endif; ?>