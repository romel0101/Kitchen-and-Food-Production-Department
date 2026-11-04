<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('users');

$page = $_GET['sub'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add_user':
            case 'update_user':
                $id         = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $pass       = (string) ($_POST['password'] ?? '');
                $r          = (string) ($_POST['role'] ?? '');
                $firstName  = trim($_POST['first_name'] ?? '') ?: null;
                $middleName = trim($_POST['middle_name'] ?? '') ?: null;
                $lastName   = trim($_POST['last_name'] ?? '') ?: null;
                $suffix     = trim($_POST['suffix'] ?? '') ?: null;
                $age        = filter_var($_POST['age'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $birthdate  = valid_date($_POST['birthdate'] ?? null);
                $address    = trim($_POST['address'] ?? '') ?: null;
                $contactNumber = trim($_POST['contact_number'] ?? '') ?: null;
                $email      = trim($_POST['email'] ?? '') ?: null;

                if ($_POST['action'] === 'add_user') {
                    if (strlen($pass) < 8) { flash('error', 'Password must be at least 8 characters.'); break; }
                    if ($firstName === null || $firstName === '') { flash('error', 'First name is required.'); break; }
                    if ($lastName === null || $lastName === '') { flash('error', 'Last name is required.'); break; }
                    if ($email === null || $email === '') { flash('error', 'Email address is required.'); break; }
                    if ($contactNumber === null || $contactNumber === '') { flash('error', 'Contact number is required.'); break; }
                    if ($address === null || $address === '') { flash('error', 'Address is required.'); break; }
                }
                if (!array_key_exists($r, ROLE_LABELS)) { flash('error', 'Choose a valid position/role.'); break; }
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Invalid email format.'); break; }

                if ($_POST['action'] === 'add_user') {
                    // Use email
                    $username = $email;
                    $stmt = $pdo->prepare('INSERT INTO users (username, password, role, first_name, middle_name, last_name, suffix, age, birthdate, address, contact_number, email)
                                           VALUES (:u, :p, :r, :fn, :mn, :ln, :s, :ag, :bd, :ad, :cn, :em)');
                    $stmt->execute([':u'=>$username, ':p'=>password_hash($pass, PASSWORD_DEFAULT), ':r'=>$r, ':fn'=>$firstName, ':mn'=>$middleName, ':ln'=>$lastName, ':s'=>$suffix, ':ag'=>$age, ':bd'=>$birthdate, ':ad'=>$address, ':cn'=>$contactNumber, ':em'=>$email]);
                    log_activity('create', "Created user account for $email ($firstName $lastName)");
                    flash('success', "User account $email created successfully.");
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET role = :r, first_name = :fn, middle_name = :mn, last_name = :ln, suffix = :s, age = :ag, birthdate = :bd, address = :ad, contact_number = :cn, email = :em WHERE id = :id');
                    $stmt->execute([':r'=>$r, ':fn'=>$firstName, ':mn'=>$middleName, ':ln'=>$lastName, ':s'=>$suffix, ':ag'=>$age, ':bd'=>$birthdate, ':ad'=>$address, ':cn'=>$contactNumber, ':em'=>$email, ':id'=>$id]);
                    log_activity('update', "Updated user account ID #$id ($firstName $lastName)");
                    flash('success', "User account updated successfully.");
                }
                redirect('index.php?page=users');

            case 'reset_password':
                $id   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $pass = (string) ($_POST['password'] ?? '');
                if (strlen($pass) < 8) { flash('error', 'Password must be at least 8 characters.'); break; }
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                log_activity('update', "Reset password for user ID #$id");
                flash('success', 'Password updated successfully.');
                redirect('index.php?page=users');

            case 'delete_user':
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === $userId) { flash('error', 'You cannot delete your own account.'); break; }
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                log_activity('delete', "Deleted user account ID #$id");
                flash('success', 'User account deleted.');
                redirect('index.php?page=users');
        }
    } catch (PDOException $e) {
        flash('error', 'Database error: ' . $e->getMessage());
    }
}

$pageTitle = $page === 'add' ? 'Create New User' : ($page === 'edit' ? 'Edit User' : 'Users Management');
$field = 'mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal shadow-sm focus:border-blue-500 focus:ring-blue-500';
$lbl = 'block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1';
?>

<?php if ($page === 'edit' || $page === 'add'):
    $editUserId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $user = null;
    if ($editUserId) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$editUserId]);
        $user = $stmt->fetch();
    }
?>
    <div class="mb-6 flex items-center justify-between">
        <nav class="flex items-center gap-2 text-sm" aria-label="Breadcrumb">
            <a href="index.php?page=users" class="font-semibold text-slate-600 hover:underline">Users Management</a>
            <span class="text-slate-400">&rsaquo;</span>
            <?php if ($user):
    $fullName = trim(implode(' ', array_filter([$user['first_name'], $user['middle_name'], $user['last_name'], $user['suffix']])));
?>
    <span class="font-semibold text-slate-900"><?= e($fullName ?: 'Edit User #' . (int)$user['id']) ?></span>
<?php else: ?>
    <span class="font-semibold text-slate-900">Create New User</span>
<?php endif; ?>
        </nav>
    </div>

    <div class="w-fullmx-auto rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-6">
            <form method="POST" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $user ? 'update_user' : 'add_user' ?>">
                <?php if ($user): ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><?php endif; ?>

                <div>
                    <label class="<?= $lbl ?>">Position <span class="text-red-600">*</span></label>
                    <select name="role" required class="<?= $field ?>">
                        <option value="">Select position</option>
                        <?php foreach(ROLE_LABELS as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= ($user && $user['role'] === $k) ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-10">
                    <div class="sm:col-span-3">
                        <label class="<?= $lbl ?>">First Name <span class="text-red-600">*</span></label>
                        <input type="text" name="first_name" maxlength="50" value="<?= e($user['first_name'] ?? '') ?>" class="<?= $field ?>" <?= !$user ? 'required' : '' ?>>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="<?= $lbl ?>">Middle Name</label>
                        <input type="text" name="middle_name" maxlength="50" value="<?= e($user['middle_name'] ?? '') ?>" class="<?= $field ?>">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="<?= $lbl ?>">Last Name <span class="text-red-600">*</span></label>
                        <input type="text" name="last_name" maxlength="50" value="<?= e($user['last_name'] ?? '') ?>" class="<?= $field ?>" <?= !$user ? 'required' : '' ?>>
                    </div>
                    <div class="sm:col-span-1">
                        <label class="<?= $lbl ?>">Suffix</label>
                        <input type="text" name="suffix" maxlength="10" placeholder="Jr., III" value="<?= e($user['suffix'] ?? '') ?>" class="<?= $field ?>">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="<?= $lbl ?>">Age</label>
                        <input type="number" name="age" min="1" max="120" value="<?= e($user['age'] ?? '') ?>" class="<?= $field ?>">
                    </div>
                    <div>
                        <label class="<?= $lbl ?>">Birthdate</label>
                        <input type="date" name="birthdate" value="<?= e($user['birthdate'] ?? '') ?>" class="<?= $field ?>">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="<?= $lbl ?>">Email Address <span class="text-red-600">*</span></label>
                        <input type="email" name="email" maxlength="100" placeholder="user@example.com" value="<?= e($user['email'] ?? '') ?>" class="<?= $field ?>" <?= !$user ? 'required' : '' ?>>
                    </div>
                    <div>
                        <label class="<?= $lbl ?>">Contact Number <span class="text-red-600">*</span></label>
                        <input type="text" name="contact_number" maxlength="25" placeholder="09123456789" value="<?= e($user['contact_number'] ?? '') ?>" class="<?= $field ?>" <?= !$user ? 'required' : '' ?>>
                    </div>
                </div>

                <div>
                    <label class="<?= $lbl ?>">Address <span class="text-red-600">*</span></label>
                    <textarea name="address" rows="2" maxlength="255" placeholder="Home address..." class="<?= $field ?>" <?= !$user ? 'required' : '' ?>><?= e($user['address'] ?? '') ?></textarea>
                </div>

                <?php if (!$user): ?>
                    <div>
                        <label class="<?= $lbl ?>">Password (min. 8 chars) <span class="text-red-600">*</span></label>
                        <input type="password" name="password" required minlength="8" placeholder="••••••••" class="<?= $field ?>">
                    </div>
                <?php endif; ?>

                <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
                    <a href="index.php?page=users" class="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-sm">Cancel</a>
                    <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700 shadow-sm">Save User</button>
                </div>
            </form>
        </div>
    </div>

<?php else:
    $roleFilter = $_GET['role'] ?? '';
    $search     = trim($_GET['q'] ?? '');

    $query = 'SELECT * FROM users WHERE 1=1';
    $params = [];

    if ($roleFilter) {
        $query .= ' AND role = ?';
        $params[] = $roleFilter;
    }
    if ($search) {
        $like = '%' . $search . '%';
        $query .= ' AND (email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $query .= ' ORDER BY role, first_name, last_name';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
?>
    <!-- Search and Filter Form -->
    <form id="filterForm" method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm flex flex-col sm:flex-row items-end gap-3">
        <input type="hidden" name="page" value="users">
        <input type="hidden" name="sub" value="list">

        <div class="flex-1 w-full sm:w-auto">
            <label class="<?= $lbl ?>">Search Users</label>
            <input type="text" id="searchInput" name="q" value="<?= e($search) ?>" placeholder="Search by name, email, or phone..." class="<?= $field ?>">
        </div>

        <div class="w-full sm:w-48">
            <label class="<?= $lbl ?>">Position</label>
            <select id="roleSelect" name="role" class="<?= $field ?>">
                <option value="">All</option>
                <?php foreach(ROLE_LABELS as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $roleFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <a href="index.php?page=users&sub=list" class="w-full sm:w-auto inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm shrink-0">Reset</a>
    </form>

    <script>
    (function() {
        const searchInput = document.getElementById('searchInput');
        const roleSelect = document.getElementById('roleSelect');
        const filterForm = document.getElementById('filterForm');
        let timeout = null;

        const performFilter = () => {
            const formData = new URLSearchParams(new FormData(filterForm));
            const url = `index.php?${formData.toString()}`;

            fetch(url)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    // Use a more specific selector for the table container
                    const newTableContainer = doc.querySelector('#userTableContainer');
                    const currentTableContainer = document.querySelector('#userTableContainer');
                    if (newTableContainer && currentTableContainer) {
                        currentTableContainer.innerHTML = newTableContainer.innerHTML;
                    }
                    // Update URL without page reload
                    window.history.replaceState({}, '', url);
                });
        };

        const debounce = (fn, delay) => {
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn(...args), delay);
            };
        };

        searchInput.addEventListener('input', debounce(performFilter, 300));
        roleSelect.addEventListener('change', performFilter);
    })();
    </script>


    <div class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-6 py-4 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 uppercase tracking-wide text-xs">Accounts</h3>
            <a href="index.php?page=users&sub=add" class="inline-flex items-center rounded border bg-blue-700 px-3 py-1 text-xs font-bold text-white hover:bg-white hover:border-blue-300 hover:text-blue-700 transition-colors shadow-sm">+ Add New User</a>
        </div>
        <div id="userTableContainer" class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Name</th>
                        <th class="px-6 py-3">Position</th>
                        <th class="px-6 py-3">Email</th>
                        <th class="px-6 py-3">Contact Number</th>
                        <th class="px-6 py-3">Last Login</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($users as $u):
                        $fullName = trim(implode(' ', array_filter([$u['first_name'], $u['middle_name'], $u['last_name'], $u['suffix']])));
                    ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-bold text-slate-900">
                                    <?= e($fullName) ?>
                                    <?php if ((int)$u['id'] === $userId): ?>
                                        <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 ml-2">YOU</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-800 border border-slate-200">
                                    <?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600">
                                <?php if ($u['email']): ?><div><?= e($u['email']) ?></div><?php endif; ?>
                                <?php if (!$u['email']): ?><span class="text-slate-400">—</span><?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600">
                                <?php if ($u['contact_number']): ?><div><?= e($u['contact_number']) ?></div><?php endif; ?>
                                <?php if (!$u['contact_number']): ?><span class="text-slate-400">—</span><?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500 whitespace-nowrap">
                                <?php if ($u['last_login']): ?>
                                    <?= e(date('j M Y, H:i', strtotime($u['last_login']))) ?>
                                <?php else: ?>
                                    <span class="text-slate-400">Never logged in</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                <a href="index.php?page=users&sub=edit&id=<?= (int)$u['id'] ?>" class="inline-flex items-center rounded border border-blue-300 bg-white px-3 py-1 text-xs font-bold text-blue-700 hover:bg-blue-50 transition-colors shadow-sm">Edit</a>
                                <?php if ((int)$u['id'] !== $userId): ?>
                                    <button type="button" class="inline-flex items-center rounded border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors shadow-sm" onclick="openDeleteModal('User: <?= e(addslashes($fullName ?: $u['username'])) ?>', <?= (int)$u['id'] ?>, 'user')">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-slate-500">No users found matching your search.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Password Confirmation Modal for Deletion -->
    <script>
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
        [['csrf', document.querySelector('input[name="csrf"]').value], ['action', 'delete_user'],
         ['id', itemId], ['verify_password', password]].forEach(kv => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
            form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
    }
    </script>

    <!-- Modal HTML -->
    <div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200">
                <h3 class="text-lg font-semibold text-slate-900">Confirm Deletion</h3>
            </div>
            <div class="px-6 py-4 space-y-4">
                <p class="text-slate-700">Are you sure you want to delete <strong id="deleteModalItemName"></strong>?</p>
                <p class="text-xs text-slate-500">This action cannot be undone.</p>
                <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" placeholder="Your password to confirm">
                <input type="hidden" id="deleteModalItemId">
                <input type="hidden" id="deleteModalType">
                <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
            </div>
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-end gap-3">
                <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
            </div>
        </div>
    </div>
<?php endif; ?>