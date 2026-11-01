<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add_user':
                $name = trim($_POST['username'] ?? '');
                $pass = (string) ($_POST['password'] ?? '');
                $r    = (string) ($_POST['role'] ?? '');
                if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $name)) { flash('error', 'Username must be 3 to 50 letters, numbers, dots, dashes or underscores.'); break; }
                if (strlen($pass) < 8)                              { flash('error', 'Password must be at least 8 characters.'); break; }
                if (!array_key_exists($r, ROLE_LABELS))             { flash('error', 'Choose a role.'); break; }
                $pdo->prepare('INSERT INTO users (username, password, role) VALUES (:u, :p, :r)')
                    ->execute([':u' => $name, ':p' => password_hash($pass, PASSWORD_DEFAULT), ':r' => $r]);
                log_activity('create', "Created user account: $name (role: " . (ROLE_LABELS[$r] ?? $r) . ")");
                flash('success', "Account $name created.");
                break;

            case 'reset_password':
                $id   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $pass = (string) ($_POST['password'] ?? '');
                if (!$id)               { flash('error', 'Invalid account.'); break; }
                if (strlen($pass) < 8)  { flash('error', 'Password must be at least 8 characters.'); break; }
                $u = $pdo->prepare('UPDATE users SET password = :p WHERE id = :id');
                $u->execute([':p' => password_hash($pass, PASSWORD_DEFAULT), ':id' => $id]);
                $target = $pdo->prepare('SELECT username FROM users WHERE id = :id');
                $target->execute([':id' => $id]);
                $uname = $target->fetchColumn();
                log_activity('update', "Reset password for user: $uname");
                flash('success', 'Password changed.');
                break;

            case 'delete_user':
                // Password verification for AJAX modal
                if (isset($_POST['verify_password'])) {
                    check_csrf();
                    $pwd = (string) ($_POST['verify_password'] ?? '');
                    $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
                    $u->execute([':id' => $userId]);
                    $hash = $u->fetchColumn();
                    if (!$hash || !password_verify($pwd, $hash)) {
                        flash('error', 'Incorrect password. Deletion cancelled.');
                        redirect('index.php?page=users');
                    }
                }

                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id)             { flash('error', 'Invalid account.'); break; }
                if ($id === $userId)  { flash('error', 'You cannot delete the account you are signed in with.'); break; }
                $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'A'")->fetchColumn();
                $t = $pdo->prepare('SELECT role FROM users WHERE id = :id');
                $t->execute([':id' => $id]);
                if ($t->fetchColumn() === 'A' && $admins <= 1) { flash('error', 'The last admin account cannot be deleted.'); break; }
                $target = $pdo->prepare('SELECT username FROM users WHERE id = :id');
                $target->execute([':id' => $id]);
                $uname = $target->fetchColumn();

                try {
                    $d = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $d->execute([':id' => $id]);
                    if ($d->rowCount()) {
                        log_activity('delete', "Deleted user account: $uname");
                        flash('success', 'Account deleted.');
                    } else {
                        flash('error', 'Account not found.');
                    }
                } catch (PDOException $ex) {
                    // The account has recorded stock, waste or safety entries: keep it, so that history stays correct.
                    flash('error', 'This account has recorded work (receipts, waste or safety logs), so it cannot be deleted. Change its password instead.');
                }
                break;

            default:
                flash('error', 'Unknown action.');
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        flash('error', 'Could not save. That username may already exist.');
    }
    redirect('index.php?page=users');
}

$users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY role, username')->fetchAll();

$pageTitle = 'Users';
$field = 'mt-1 block w-full rounded border border-slate-300 px-3 py-2 font-normal';
?>

<div class="grid gap-6 lg:grid-cols-3">
    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white lg:col-span-2">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Accounts</h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="px-4 py-2 font-medium">Username</th><th class="font-medium">Role</th><th class="font-medium">Created</th><th class="px-4 font-medium">New password</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="px-4 py-2 font-medium"><?= e($u['username']) ?><?= (int) $u['id'] === $userId ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?></td>
                        <td><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?></td>
                        <td class="whitespace-nowrap"><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>
                        <td class="px-4 py-2">
                            <form method="POST" class="flex gap-2">
                                <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="min. 8 characters" class="w-40 rounded border border-slate-300 px-2 py-1 text-sm">
                                <button class="rounded border border-slate-300 px-3 py-1 text-xs font-semibold hover:bg-slate-50">Change</button>
                            </form>
                        </td>
                        <td class="px-4 text-right">
                            <?php if ((int) $u['id'] !== $userId): ?>
                                <button type="button" class="rounded border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100" onclick="openDeleteModal('User: <?= e(addslashes($u['username'])) ?>', <?= (int) $u['id'] ?>, 'user')">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">Add account</h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
            <label class="block text-sm font-medium">Username
                <input type="text" name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+" autocomplete="off" class="<?= $field ?>">
            </label>
            <label class="block text-sm font-medium">Password
                <input type="password" name="password" required minlength="8" autocomplete="new-password" class="<?= $field ?>">
            </label>
            <label class="block text-sm font-medium">Role
                <select name="role" required class="<?= $field ?>">
                    <?php foreach (ROLE_LABELS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button class="w-full rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">Create account</button>
        </form>
    </section>
</div>

<script>
// Password confirmation modal for delete actions
let deleteModalTarget = null;
let deleteModalType = null;

function openDeleteModal(itemName, itemId, type = 'user') {
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
    if (type === 'user') {
        action = 'delete_user';
        formData.append('action', action);
        formData.append('id', itemId);
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
            btn.textContent = 'Delete';
        }
    })
    .catch(() => {
        document.getElementById('deleteModalError').textContent = 'An error occurred. Please try again.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Delete';
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
            <h3 class="text-lg font-semibold text-slate-900">Confirm Deletion</h3>
        </div>
        <div class="px-6 py-4 space-y-4">
            <p class="text-slate-700">Are you sure you want to delete <strong id="deleteModalItemName"></strong>?</p>
            <p class="text-xs text-slate-500">This action cannot be undone. Please enter your password to confirm.</p>
            <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" placeholder="Your password" autocomplete="current-password">
            <input type="hidden" id="deleteModalItemId">
            <input type="hidden" id="deleteModalType">
            <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>
