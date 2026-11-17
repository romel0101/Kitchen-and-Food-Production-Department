<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
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
                $isActive   = isset($_POST['is_active']) ? 1 : 0;
                $firstName  = trim($_POST['first_name'] ?? '') ?: null;
                $middleName = trim($_POST['middle_name'] ?? '') ?: null;
                $lastName   = trim($_POST['last_name'] ?? '') ?: null;
                $suffix     = trim($_POST['suffix'] ?? '') ?: null;
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
                    $stmt = $pdo->prepare('INSERT INTO users (password, role, is_active, first_name, middle_name, last_name, suffix, birthdate, address, contact_number, email)
                                           VALUES (:p, :r, :ia, :fn, :mn, :ln, :s, :bd, :ad, :cn, :em)');
                    $stmt->execute([':p'=>password_hash($pass, PASSWORD_DEFAULT), ':r'=>$r, ':ia'=>$isActive, ':fn'=>$firstName, ':mn'=>$middleName, ':ln'=>$lastName, ':s'=>$suffix, ':bd'=>$birthdate, ':ad'=>$address, ':cn'=>$contactNumber, ':em'=>$email]);
                    log_activity('create', "Created user account for $email ($firstName $lastName)");
                    flash('success', "User account $email created successfully.");
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET role = :r, is_active = :ia, first_name = :fn, middle_name = :mn, last_name = :ln, suffix = :s, birthdate = :bd, address = :ad, contact_number = :cn, email = :em WHERE id = :id');
                    $stmt->execute([':r'=>$r, ':ia'=>$isActive, ':fn'=>$firstName, ':mn'=>$middleName, ':ln'=>$lastName, ':s'=>$suffix, ':bd'=>$birthdate, ':ad'=>$address, ':cn'=>$contactNumber, ':em'=>$email, ':id'=>$id]);
                    log_activity('update', "Updated user account ID #$id ($firstName $lastName)");
                    flash('success', "User account updated successfully.");
                }
                redirect('index?page=users');

            case 'admin_reset_password':
                $id   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $pass = (string) ($_POST['new_password'] ?? '');
                
                $verifyPass = (string) ($_POST['admin_password'] ?? '');
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->execute([$userId]); 
                $adminHash = $stmt->fetchColumn();

                if (!$adminHash || !password_verify($verifyPass, $adminHash)) {
                    flash('error', 'Incorrect admin password. Cannot reset.');
                    redirect('index?page=users&sub=edit&id=' . $id);
                }
                if (strlen($pass) < 8) { 
                    flash('error', 'New password must be at least 8 characters.'); 
                    redirect('index?page=users&sub=edit&id=' . $id);
                }

                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                log_activity('update', "Admin reset password for user ID #$id");
                flash('success', 'User password has been reset successfully.');
                redirect('index?page=users&sub=edit&id=' . $id);

            case 'delete_user':
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === $userId) { flash('error', 'You cannot delete your own account.'); break; }
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                log_activity('delete', "Deleted user account ID #$id");
                flash('success', 'User account deleted.');
                redirect('index?page=users');
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
            <a href="index?page=users" class="font-semibold text-slate-600 hover:underline">Users Management</a>
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

    <div class="w-full mx-auto rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-6">
            <form method="POST" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $user ? 'update_user' : 'add_user' ?>">
                <?php if ($user): ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><?php endif; ?>

                <!-- Combined Account Security Panel -->
                <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-sm">
                    <div>
                        <p class="font-bold text-slate-900">Account Access & Security</p>
                        <p class="text-xs text-slate-500 mt-0.5">Manage login authorization <?= $user ? 'and passwords.' : 'for this new account.' ?></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <?php if ($user): ?>
                            <button type="button" onclick="openResetModal()" class="rounded-md border border-red-200 bg-red-50 px-5 py-2.5 text-sm font-bold text-red-700 shadow-sm hover:bg-red-100 transition-colors">Reset Password</button>
                            <div class="h-6 w-px bg-slate-300 hidden sm:block"></div>
                        <?php endif; ?>
                        
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" name="is_active" class="peer sr-only" <?= (!$user || ($user['is_active'] ?? 1)) ? 'checked' : '' ?>>
                            <div class="h-6 w-11 rounded-full bg-slate-300 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-slate-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-blue-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300"></div>
                            <span class="ml-3 text-sm font-bold text-slate-700">Authorized</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="<?= $lbl ?>">Position <span class="text-red-600">*</span></label>
                    <select name="role" required class="<?= $field ?>">
                        <option value="">Select position</option>
                        <?php foreach(ROLE_LABELS as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= ($user && $user['role'] === $k) ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-9">
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
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-1">
                        <label class="<?= $lbl ?>">Suffix</label>
                        <input type="text" name="suffix" maxlength="10" placeholder="Jr., III" value="<?= e($user['suffix'] ?? '') ?>" class="<?= $field ?>">
                    </div>
                    <div>
                        <label class="<?= $lbl ?>">Birthdate</label>
                        <input type="date" name="birthdate" value="<?= e($user['birthdate'] ?? '') ?>" max="<?= date('Y-m-d') ?>" class="<?= $field ?>">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="<?= $lbl ?>">Email Address <span class="text-red-600">*</span></label>
                        <input type="email" name="email" maxlength="100" placeholder="user@example.com" value="<?= e($user['email'] ?? '') ?>" class="<?= $field ?>" <?= !$user ? 'required' : '' ?>>
                    </div>
                    <div>
                        <label class="<?= $lbl ?>">Contact Number <span class="text-red-600">*</span></label>
                        <input type="tel" name="contact_number" maxlength="25" pattern="[0-9]*" oninput="this.value = this.value.replace(/[^0-9]/g, '')" placeholder="09123456789" value="<?= e($user['contact_number'] ?? '') ?>" class="<?= $field ?>" <?= !$user ? 'required' : '' ?>>
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

                <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5 mt-6">
                    <a href="index?page=users" class="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-sm transition-colors">Cancel</a>
                    <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700 shadow-sm transition-colors">Save User Profile</button>
                </div>
            </form>

            <?php if ($user): ?>
                <!-- Admin Password Reset Modal -->
                <div id="resetModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
                    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
                            <h3 class="text-lg font-semibold text-slate-900">Reset User Password</h3>
                        </div>
                        <form method="POST">
                            <div class="px-6 py-5 space-y-4">
                                <p class="text-xs text-slate-600 mb-2">Set a new password for this user. You must verify your own admin password to authorize this change.</p>
                                
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="admin_reset_password">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                
                                <div>
                                    <label class="<?= $lbl ?>">New Password for User</label>
                                    <input type="password" name="new_password" required minlength="8" placeholder="Min 8 characters" class="<?= $field ?>">
                                </div>
                                <div>
                                    <label class="<?= $lbl ?>">Your Admin Password</label>
                                    <input type="password" name="admin_password" required placeholder="Verify your identity" class="<?= $field ?>">
                                </div>
                            </div>
                            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex justify-end gap-3">
                                <button type="button" onclick="closeResetModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-sm transition-colors">Cancel</button>
                                <button type="submit" class="rounded-md bg-red-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-red-800 shadow-sm transition-colors">Force Reset</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function openResetModal() {
                    const modal = document.getElementById('resetModal');
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    setTimeout(() => document.querySelector('input[name="new_password"]').focus(), 50);
                }

                function closeResetModal() {
                    const modal = document.getElementById('resetModal');
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    modal.querySelector('form').reset();
                }

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && !document.getElementById('resetModal').classList.contains('hidden')) {
                        closeResetModal();
                    }
                });
                </script>
            <?php endif; ?>
        </div>
    </div>

<?php else:
    $roleFilter   = $_GET['role'] ?? '';
    $statusFilter = $_GET['status'] ?? '';
    $search       = trim($_GET['q'] ?? '');
    
    // Sorting parameters
    $sortBy       = trim($_GET['sort'] ?? 'name');
    $sortDir      = strtolower(trim($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

    // Allowed sorting column mapping
    $allowedSorts = [
        'name'   => 'first_name',
        'role'   => 'role',
        'status' => 'is_active'
    ];
    $sqlSortCol = $allowedSorts[$sortBy] ?? 'first_name';

    // Pagination parameters
    $pageNumber = max(1, (int)($_GET['p'] ?? 1));
    $limit      = 25;
    $offset     = ($pageNumber - 1) * $limit;

    // Metrics computation queries
    $totalUsersCount  = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeUsersCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $disabledUsersCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
    $adminUsersCount  = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $chefUsersCount   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'chef'")->fetchColumn();
    $inventoryUsersCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'inventory'")->fetchColumn();

    // Base WHERE clause setup with named parameters
    $where = ['1=1'];
    $params = [];

    if ($roleFilter !== '') {
        $where[] = 'role = :role';
        $params[':role'] = $roleFilter;
    }
    if ($statusFilter !== '') {
        $where[] = 'is_active = :status';
        $params[':status'] = $statusFilter === 'active' ? 1 : 0;
    }
    if ($search !== '') {
        $where[] = '(email LIKE :q1 OR CONCAT_WS(" ", first_name, middle_name, last_name, suffix) LIKE :q2 OR contact_number LIKE :q3)';
        $like = '%' . $search . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }
    $whereSql = implode(' AND ', $where);

    // Total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
    $countStmt->execute($params);
    $totalFilteredRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalFilteredRows / $limit));

    // Fetch paginated & sorted dataset using named parameters only
    $dataSql = "SELECT * FROM users WHERE $whereSql ORDER BY $sqlSortCol $sortDir, last_name ASC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();

    // Helper builder function for sort URLs
    function user_sort_url(string $col): string {
        $params = $_GET;
        $currentSort = $_GET['sort'] ?? 'name';
        $currentDir  = strtolower($_GET['dir'] ?? 'asc');
        
        $nextDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
        $params['sort'] = $col;
        $params['dir']  = $nextDir;
        unset($params['p']); // Reset to page 1 on sort change
        return 'index?' . http_build_query($params);
    }

    // Helper builder function for pagination URLs
    function user_page_url(int $p): string {
        $params = $_GET;
        $params['p'] = $p;
        return 'index?' . http_build_query($params);
    }
?>
    <!-- User Metrics Summary Cards -->
    <div id="userSummary" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Users</p>
            <p class="mt-2 text-3xl font-black tabular-nums text-slate-900"><?= $totalUsersCount ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Administrators</p>
            <p class="mt-2 text-3xl font-black tabular-nums text-blue-600"><?= $adminUsersCount ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Chef</p>
            <p class="mt-2 text-3xl font-black tabular-nums text-blue-600"><?= $adminUsersCount ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Inventory</p>
            <p class="mt-2 text-3xl font-black tabular-nums text-blue-600"><?= $adminUsersCount ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Accounts</p>
            <p class="mt-2 text-3xl font-black tabular-nums text-green-600"><?= $activeUsersCount ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Disabled Accounts</p>
            <p class="mt-2 text-3xl font-black tabular-nums text-slate-500"><?= $disabledUsersCount ?></p>
        </div>
    </div>

    <!-- Search and Filter Form -->
    <form id="filterForm" method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm flex flex-col md:flex-row gap-3">
        <input type="hidden" name="page" value="users">
        <input type="hidden" name="sub" value="list">
        <?php if (!empty($sortBy)): ?><input type="hidden" name="sort" value="<?= e($sortBy) ?>"><?php endif; ?>
        <?php if (!empty($sortDir)): ?><input type="hidden" name="dir" value="<?= e(strtolower($sortDir)) ?>"><?php endif; ?>

        <div class="flex-1 w-full md:w-auto">
            <label class="<?= $lbl ?>">Search Users</label>
            <input type="text" id="searchInput" name="q" value="<?= e($search) ?>" placeholder="Search by name, email, or phone..." autocomplete="off" class="<?= $field ?>">
        </div>

        <div class="w-full md:w-40">
            <label class="<?= $lbl ?>">Position</label>
            <select id="roleSelect" name="role" onchange="performFilter()" class="<?= $field ?>">
                <option value="">All Roles</option>
                <?php foreach(ROLE_LABELS as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $roleFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="w-full md:w-40">
            <label class="<?= $lbl ?>">Status</label>
            <select id="statusSelect" name="status" onchange="performFilter()" class="<?= $field ?>">
                <option value="">All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
            </select>
        </div>

        <a href="index?page=users&sub=list" class="w-full md:w-auto inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm shrink-0">Reset</a>
    </form>

    <script>
    function performFilter() {
        const filterForm = document.getElementById('filterForm');
        const formData = new URLSearchParams(new FormData(filterForm));
        const url = `index?${formData.toString()}`;

        fetch(url)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newTableContainer = doc.querySelector('#userTableContainer');
                const currentTableContainer = document.querySelector('#userTableContainer');
                if (newTableContainer && currentTableContainer) {
                    currentTableContainer.innerHTML = newTableContainer.innerHTML;
                }
                window.history.replaceState({}, '', url);
            });
    }

    (function() {
        const searchInput = document.getElementById('searchInput');
        let timeout = null;

        const debounce = (fn, delay) => {
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn(...args), delay);
            };
        };

        if (searchInput) {
            searchInput.addEventListener('input', debounce(performFilter, 300));
            if (searchInput.value !== '' && new URLSearchParams(location.search).has('q')) {
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }
        }
    })();
    </script>


    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-6 py-4 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 uppercase tracking-wide text-xs">Accounts Directory</h3>
            <a href="index?page=users&sub=add" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700 transition-colors shadow-sm">+ Add New User</a>
        </div>
        <div id="userTableContainer" class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-6 py-3">
                            <a href="<?= user_sort_url('name') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">Name<span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'name' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span></a>
                        </th>
                        <th class="px-6 py-3">
                            <a href="<?= user_sort_url('role') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">Position<span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'role' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span></a>
                        </th>
                        <th class="px-6 py-3">
                            <a href="<?= user_sort_url('status') ?>" class="group inline-flex items-center gap-1 hover:text-slate-900">Status<span class="text-slate-400 group-hover:text-slate-600"><?= $sortBy === 'status' ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕' ?></span></a>
                        </th>
                        <th class="px-6 py-3">Contact Info</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($users as $u):
                        $fullName = trim(implode(' ', array_filter([$u['first_name'], $u['middle_name'], $u['last_name'], $u['suffix']])));
                        $active = ($u['is_active'] ?? 1);
                    ?>
                        <tr class="hover:bg-slate-50/80 transition-colors <?= !$active ? 'opacity-75 bg-slate-50/50' : '' ?>">
                            <td class="px-6 py-4">
                                <div class="font-bold text-slate-900 flex items-center gap-2">
                                    <?= e($fullName) ?>
                                    <?php if ((int)$u['id'] === $userId): ?>
                                        <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700">YOU</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-500 mt-1">
                                    <?php if ($u['last_login']): ?>
                                        Last login: <?= e(date('j M Y, H:i', strtotime($u['last_login']))) ?>
                                    <?php else: ?>
                                        Never logged in
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700 border border-slate-200 shadow-sm">
                                    <?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($active): ?>
                                    <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-bold text-green-700 border border-green-200">Active</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-500 border border-slate-200">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600 space-y-1">
                                <?php if ($u['email']): ?><div><a href="mailto:<?= e($u['email']) ?>" class="hover:text-blue-600 hover:underline"><?= e($u['email']) ?></a></div><?php endif; ?>
                                <?php if ($u['contact_number']): ?><div><?= e($u['contact_number']) ?></div><?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                <a href="index?page=users&sub=edit&id=<?= (int)$u['id'] ?>" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm">Edit</a>
                                <?php if ((int)$u['id'] !== $userId): ?>
                                    <button type="button" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors shadow-sm" onclick="openDeleteModal('<?= e(addslashes($fullName ?: $u['username'])) ?>', <?= (int)$u['id'] ?>, 'user')">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">No users found matching your filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination Bar -->
            <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-3">
                <div class="text-xs text-slate-500">
                    Showing <strong><?= count($users) ? $offset + 1 : 0 ?>–<?= $offset + count($users) ?></strong> of <strong><?= $totalFilteredRows ?></strong> entries (Page <?= $pageNumber ?> of <?= $totalPages ?>)
                </div>
                <div class="flex items-center gap-1">
                    <?php if ($pageNumber > 1): ?>
                        <a href="<?= user_page_url($pageNumber - 1) ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-sm">Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $pageNumber) <= 2): ?>
                            <a href="<?= user_page_url($i) ?>" class="rounded-md px-3 py-1.5 text-xs font-semibold <?= $i === $pageNumber ? 'bg-slate-900 text-white shadow-sm' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 shadow-sm' ?>"><?= $i ?></a>
                        <?php elseif ($i === $pageNumber - 3 || $i === $pageNumber + 3): ?>
                            <span class="px-1 text-slate-400">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pageNumber < $totalPages): ?>
                        <a href="<?= user_page_url($pageNumber + 1) ?>" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-sm">Next</a>
                    <?php endif; ?>
                </div>
            </div>
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
    <div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
                <h3 class="text-lg font-semibold text-slate-900">Confirm Deletion</h3>
            </div>
            <div class="px-6 py-5 space-y-4">
                <p class="text-slate-700">Are you sure you want to delete <strong id="deleteModalItemName"></strong>?</p>
                <p class="text-xs text-slate-500">This action cannot be undone.</p>
                <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" placeholder="Your password to confirm">
                <input type="hidden" id="deleteModalItemId">
                <input type="hidden" id="deleteModalType">
                <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
            </div>
            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex justify-end gap-3">
                <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm">Cancel</button>
                <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 transition-colors shadow-sm">Delete</button>
            </div>
        </div>
    </div>
<?php endif; ?>