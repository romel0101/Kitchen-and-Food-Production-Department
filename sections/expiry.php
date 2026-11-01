<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('expiry');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);

    // Password verification for AJAX modal
    if (isset($_POST['verify_password'])) {
        $pwd = (string) ($_POST['verify_password'] ?? '');
        $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
        $u->execute([':id' => $userId]);
        $hash = $u->fetchColumn();
        if (!$hash || !password_verify($pwd, $hash)) {
            flash('error', 'Incorrect password. Write-off cancelled.');
            redirect('index.php?page=expiry');
        }
    }

    if (($_POST['action'] ?? '') === 'write_off' && $batchId) {
        // Moves what is left of an expired batch out of stock and into the waste log.
        run_tx($pdo, function (PDO $pdo) use ($batchId, $userId) {
            $s = $pdo->prepare('SELECT ingredient_id, quantity_remaining FROM stock_receipts WHERE id = :id AND quantity_remaining > 0 FOR UPDATE');
            $s->execute([':id' => $batchId]);
            $b = $s->fetch();
            if (!$b) throw new RuntimeException('That batch was not found or is already empty.');
            $left = (float) $b['quantity_remaining'];
            $pdo->prepare('UPDATE stock_receipts SET quantity_remaining = 0 WHERE id = :id')->execute([':id' => $batchId]);
            $pdo->prepare('UPDATE ingredients SET current_stock = GREATEST(0, current_stock - :q) WHERE id = :id')->execute([':q' => $left, ':id' => $b['ingredient_id']]);
            $pdo->prepare("INSERT INTO waste_logs (ingredient_id, batch_id, current_stock, reason, notes, logged_by) VALUES (:i, :b, :q, 'expired', :n, :u)")
                ->execute([':i' => $b['ingredient_id'], ':b' => $batchId, ':q' => $left, ':n' => "Written off from expiry tracking (batch #$batchId)", ':u' => $userId]);
            log_stock_movement($pdo, (int) $b['ingredient_id'], $batchId, 'write_off', -$left, "Expired batch #$batchId written off", $userId);
            log_activity('write_off', "Wrote off expired batch #$batchId ({$b['ingredient_id']}, {$left} units)");
            return 'Batch written off and added to the waste log.';
        });
    } else {
        flash('error', 'Invalid request.');
    }
    redirect('index.php?page=expiry');
}

$batches = $pdo->query('SELECT r.id, r.quantity_remaining, r.expiry_date, r.supplier, i.name, i.unit, i.storage_location,
                               DATEDIFF(r.expiry_date, CURDATE()) AS days_left
                        FROM stock_receipts r JOIN ingredients i ON i.id = r.ingredient_id
                        WHERE r.quantity_remaining > 0 AND r.expiry_date IS NOT NULL
                        ORDER BY r.expiry_date, i.name')->fetchAll();

$pageTitle = 'Expiry tracking';
?>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500">
            <tr><th class="px-4 py-3 font-medium">Ingredient</th><th class="font-medium">Left in batch</th><th class="font-medium">Expiry date</th><th class="font-medium">Status</th><th class="font-medium">Stored in</th><th class="px-4"></th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($batches as $b):
                $d = (int) $b['days_left']; ?>
                <tr class="<?= $d < 0 ? 'bg-red-50/40' : '' ?>">
                    <td class="px-4 py-2 font-medium"><?= e($b['name']) ?></td>
                    <td class="tabular-nums"><?= e(num($b['quantity_remaining'])) ?> <?= e($b['unit']) ?></td>
                    <td class="whitespace-nowrap"><?= e(date('j M Y', strtotime($b['expiry_date']))) ?></td>
                    <td>
                        <?php if ($d < 0): ?><?= badge('Expired ' . abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago', 'red') ?>
                        <?php elseif ($d === 0): ?><?= badge('Expires today', 'red') ?>
                        <?php elseif ($d <= EXPIRY_WARN_DAYS): ?><?= badge("Expires in $d day" . ($d === 1 ? '' : 's'), 'amber') ?>
                        <?php else: ?><?= badge("$d days left", 'green') ?><?php endif; ?>
                    </td>
                    <td><?= e($b['storage_location']) ?></td>
                    <td class="px-4 text-right">
                        <?php if ($d < 0): ?>
                            <button type="button" class="rounded border border-red-300 px-3 py-1 text-red-800 hover:bg-red-50" onclick="openDeleteModal('Expired batch #<?= (int) $b['id'] ?> (<?= e($b['name']) ?>, <?= e(num($b['quantity_remaining'])) ?> <?= e($b['unit']) ?>)', <?= (int) $b['id'] ?>, 'expiry_writeoff')">Write off</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$batches): ?><tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No batches with expiry dates are in stock. Add an expiry date when you record a delivery.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Password confirmation modal for write-off actions
let deleteModalTarget = null;
let deleteModalType = null;

function openDeleteModal(itemName, itemId, type = 'expiry_writeoff') {
    deleteModalTarget = itemId;
    deleteModalType = type;
    document.getElementById('deleteModalItemName').textContent = itemName;
    document.getElementById('deleteModalItemId').value = itemId;
    document.getElementById('deleteModalType').value = type;
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
    deleteModalTarget = null;
    deleteModalType = null;
}

function submitDeleteModal() {
    const password = document.getElementById('deleteModalPassword').value;
    const itemId = document.getElementById('deleteModalItemId').value;
    const type = document.getElementById('deleteModalType').value;

    if (!password) {
        document.getElementById('deleteModalError').textContent = 'Please enter your password.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        return;
    }

    // Disable button and show loading
    const btn = document.getElementById('deleteModalConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Verifying...';

    const formData = new FormData();
    formData.append('csrf', document.querySelector('input[name="csrf"]').value);
    formData.append('verify_password', password);

    let action = '';
    if (type === 'expiry_writeoff') {
        action = 'write_off';
        formData.append('action', action);
        formData.append('batch_id', itemId);
    }

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        // Check if the response indicates success (redirect) or error
        if (html.includes('flash-success') || html.includes('success') || !html.includes('flash-error')) {
            // Reload page to show updated list
            window.location.reload();
        } else {
            // Extract error message from response
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const errorEl = doc.querySelector('[role="alert"]');
            if (errorEl) {
                document.getElementById('deleteModalError').textContent = errorEl.textContent.trim();
            } else {
                document.getElementById('deleteModalError').textContent = 'Password verification failed.';
            }
            document.getElementById('deleteModalError').classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Write off';
        }
    })
    .catch(() => {
        document.getElementById('deleteModalError').textContent = 'An error occurred. Please try again.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Write off';
    });
}

// Handle Enter key in password field
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('deleteModalPassword');
    if (passwordInput) {
        passwordInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitDeleteModal();
            }
        });
    }

    // Close modal on backdrop click
    document.getElementById('deleteModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeleteModal();
    });
});
</script>

<!-- Password Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-slate-200">
            <h3 class="text-lg font-semibold text-slate-900">Confirm Write-off</h3>
        </div>
        <div class="px-6 py-4 space-y-4">
            <p class="text-slate-700">Are you sure you want to write off <strong id="deleteModalItemName"></strong>?</p>
            <p class="text-xs text-slate-500">This action cannot be undone. Please enter your password to confirm.</p>
            <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" placeholder="Your password" autocomplete="current-password">
            <input type="hidden" id="deleteModalItemId">
            <input type="hidden" id="deleteModalType">
            <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Write off</button>
        </div>
    </div>
</div>
