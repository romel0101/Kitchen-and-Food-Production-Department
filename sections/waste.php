<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('waste');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'edit_waste' && $id) {
        // Both Admin and Inventory can edit
        $qty    = filter_var($_POST['current_stock'] ?? null, FILTER_VALIDATE_FLOAT);
        $reason = $_POST['reason'] ?? '';
        $notes  = trim($_POST['notes'] ?? '') ?: null;

        if ($qty !== false && $qty > 0 && array_key_exists($reason, WASTE_REASONS)) {
            run_tx($pdo, function (PDO $pdo) use ($id, $qty, $reason, $notes, $userId) {
                $qty = round($qty, 3);
                // Get current waste log to calculate stock difference
                $old = $pdo->prepare('SELECT w.*, i.name, i.unit FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id WHERE w.id = ?');
                $old->execute([$id]);
                $oldRow = $old->fetch();
                if (!$oldRow) throw new RuntimeException('Waste log not found.');

                $diff = $qty - (float) $oldRow['current_stock'];

                // Update waste_logs
                $pdo->prepare('UPDATE waste_logs SET current_stock = ?, reason = ?, notes = ? WHERE id = ?')
                    ->execute([$qty, $reason, $notes, $id]);

                // Adjust ingredient stock and log movement if quantity changed
                if ($diff !== 0) {
                    deduct_stock($pdo, $oldRow['ingredient_id'], $diff);
                    log_stock_movement($pdo, $oldRow['ingredient_id'], null, 'waste', -$diff,
                        'Edit waste log: ' . (WASTE_REASONS[$reason] ?? $reason) . ($notes ? ': ' . $notes : ''), $userId);
                }
                log_activity('update', "Edited waste log ID #$id ({$oldRow['name']}: {$qty} {$oldRow['unit']})");
                return 'Waste log updated successfully.';
            });
        } else {
            flash('error', 'Invalid quantity or reason.');
        }
        redirect('index.php?page=waste');
    }

    if ($action === 'delete_waste' && $id) {
        // Admin only
        if ($_SESSION['role'] !== ROLE_ADMIN) {
            flash('error', 'Only administrators can delete waste logs.');
            redirect('index.php?page=waste');
        }
        run_tx($pdo, function (PDO $pdo) use ($id, $userId) {
            $old = $pdo->prepare('SELECT w.*, i.name, i.unit FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id WHERE w.id = ?');
            $old->execute([$id]);
            $oldRow = $old->fetch();
            if (!$oldRow) throw new RuntimeException('Waste log not found.');

            // Restore stock
            deduct_stock($pdo, $oldRow['ingredient_id'], -(float) $oldRow['current_stock']);
            log_stock_movement($pdo, $oldRow['ingredient_id'], null, 'waste', (float) $oldRow['current_stock'],
                'Deleted waste log: ' . (WASTE_REASONS[$oldRow['reason']] ?? $oldRow['reason']), $userId);

            $pdo->prepare('DELETE FROM waste_logs WHERE id = ?')->execute([$id]);
            log_activity('delete', "Deleted waste log ID #$id ({$oldRow['name']}: {$oldRow['current_stock']} {$oldRow['unit']})");
            return 'Waste log deleted and stock restored.';
        });
        redirect('index.php?page=waste');
    }
}

$recent = $pdo->query('SELECT w.*, i.name, i.unit, u.first_name, u.middle_name, u.last_name, u.suffix
                       FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id JOIN users u ON u.id = w.logged_by
                       ORDER BY w.logged_at DESC, w.id DESC LIMIT 25')->fetchAll();
$today = (int) $pdo->query('SELECT COUNT(*) FROM waste_logs WHERE logged_at >= CURDATE()')->fetchColumn();

$pageTitle = 'Food waste';
?>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Recent waste</h2>
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500"><tr><th class="px-4 py-2 font-medium">When</th><th class="font-medium">Ingredient</th><th class="font-medium">Quantity</th><th class="font-medium">Reason</th><th class="font-medium">Details</th><th class="font-medium">By</th><th class="px-4 py-2 font-medium text-right">Actions</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="px-4 py-2 whitespace-nowrap"><?= e(date('j M, H:i', strtotime($r['logged_at']))) ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td class="tabular-nums"><?= e(num($r['current_stock'])) ?> <?= e($r['unit']) ?></td>
                    <td><?= badge(WASTE_REASONS[$r['reason']] ?? $r['reason'], $r['reason'] === 'expired' ? 'red' : 'gray') ?></td>
                    <td class="max-w-xs text-slate-600"><?= e($r['notes'] ?? '') ?></td>
                    <td><?php $fn = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name'], $r['suffix']]))); echo e($fn ?: '—'); ?></td>
                    <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                        <button type="button" class="inline-flex items-center rounded border border-blue-300 bg-white px-3 py-1 text-xs font-bold text-blue-700 hover:bg-blue-50 transition-colors shadow-sm" onclick="openEditModal(<?= e(json_encode($r)) ?>)">Edit</button>
                        <?php if ($_SESSION['role'] === ROLE_ADMIN): ?>
                            <button type="button" class="inline-flex items-center rounded border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors shadow-sm" onclick="openDeleteModal('Waste: <?= e(addslashes($r['name'] . ': ' . num($r['current_stock']) . ' ' . $r['unit'])) ?>', <?= (int)$r['id'] ?>)">Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No waste has been logged yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200">
            <h3 class="text-lg font-semibold text-slate-900">Edit Waste Log</h3>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4" id="editForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_waste">
            <input type="hidden" name="id" id="editId">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Ingredient</label>
                <input type="text" id="editIngredient" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm bg-slate-50" readonly>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Quantity <span class="text-red-600">*</span></label>
                <input type="number" name="current_stock" step="0.001" min="0.001" required id="editQty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Reason <span class="text-red-600">*</span></label>
                <select name="reason" required id="editReason" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                    <?php foreach (WASTE_REASONS as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Details</label>
                <input type="text" name="notes" maxlength="255" id="editNotes" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeEditModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700 shadow-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal (reuse existing pattern) -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200">
            <h3 class="text-lg font-semibold text-slate-900">Confirm Deletion</h3>
        </div>
        <div class="px-6 py-4 space-y-4">
            <p class="text-slate-700">Are you sure you want to delete <strong id="deleteModalItemName"></strong>?</p>
            <p class="text-xs text-slate-500">This will restore the stock quantity and cannot be undone.</p>
            <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" placeholder="Your password to confirm">
            <input type="hidden" id="deleteModalItemId">
            <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
        </div>
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>

<script>
function openEditModal(row) {
    document.getElementById('editId').value = row.id;
    document.getElementById('editIngredient').value = row.name + ' (' + row.unit + ')';
    document.getElementById('editQty').value = row.current_stock;
    document.getElementById('editReason').value = row.reason;
    document.getElementById('editNotes').value = row.notes || '';
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
    setTimeout(() => document.getElementById('editQty').focus(), 50);
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('editModal').classList.remove('flex');
}

function openDeleteModal(itemName, itemId) {
    document.getElementById('deleteModalItemName').textContent = itemName;
    document.getElementById('deleteModalItemId').value = itemId;
    document.getElementById('deleteModalPassword').value = '';
    document.getElementById('deleteModalError').textContent = '';
    document.getElementById('deleteModalError').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
    setTimeout(() => document.getElementById('deleteModalPassword').focus(), 50);
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
}

function submitDeleteModal() {
    const password = document.getElementById('deleteModalPassword').value;
    const itemId = document.getElementById('deleteModalItemId').value;

    if (!password) {
        document.getElementById('deleteModalError').textContent = 'Please enter your password.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        return;
    }

    const btn = document.getElementById('deleteModalConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Deleting...';

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.pathname + window.location.search;
    [['csrf', document.querySelector('input[name="csrf"]').value], ['action', 'delete_waste'],
     ['id', itemId], ['verify_password', password]].forEach(kv => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}
</script>
