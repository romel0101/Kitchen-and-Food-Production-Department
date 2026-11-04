<?php
$counts = ['orders' => 0, 'low' => 0, 'expiring' => 0];
if (can('orders')) {
    $counts['orders'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE ready_at IS NULL")->fetchColumn();
}
if (can('inventory')) {
    $counts['low'] = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE current_stock <= reorder_level')->fetchColumn();
}
if (can('expiry')) {
    $counts['expiring'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_receipts WHERE quantity_remaining > 0 AND expiry_date IS NOT NULL
         AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) EXPIRY_WARN_DAYS . ' DAY)'
    )->fetchColumn();
}

$currentPage = $_GET['page'] ?? 'dashboard';
if ($currentPage === 'receiving') $currentPage = 'inventory';

// Fetch current user info for sidebar header
$stmt = $pdo->prepare('SELECT first_name, middle_name, last_name, suffix, role FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$currentUser = $stmt->fetch();
$fullName = $currentUser ? trim(implode(' ', array_filter([$currentUser['first_name'], $currentUser['middle_name'], $currentUser['last_name'], $currentUser['suffix']]))) : '';
$name = $fullName ?: 'Unknown';
$userRoleLabel = $currentUser ? e(ROLE_LABELS[$currentUser['role']] ?? $currentUser['role']) : '';

$groups = [
    ''               => [[null, 'dashboard', 'index.php', 'Dashboard', null]],
    'Kitchen'        => [['orders', 'orders', 'index.php?page=orders', 'Order queue', 'orders'],
                         ['recipes', 'recipes', 'index.php?page=recipes', 'Recipes', null]],
    'Stock'          => [['inventory', 'inventory', 'index.php?page=inventory', 'Inventory', 'low'],
                         ['expiry', 'expiry', 'index.php?page=expiry', 'Expiry tracking', 'expiring']],
    'Records'        => [['waste', 'waste', 'index.php?page=waste', 'Food waste', null],
                         ['safety', 'safety', 'index.php?page=safety', 'Food safety logs', null],
                         ['reports', 'reports', 'index.php?page=reports', 'Production reports', null]],
    'Administration' => [['users', 'users', 'index.php?page=users', 'Users', null],
                         ['users', 'user_activity', 'index.php?page=user_activity', 'User Activity', null]],
];
$countTone = ['orders' => 'bg-blue-500', 'low' => 'bg-red-500', 'expiring' => 'bg-amber-500'];

$sidebarCollapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
?>

<!-- Mobile Backdrop Overlay (Dims main content and exits on click) -->
<div id="sidebarBackdrop"
     onclick="closeMobileSidebar()"
     class="fixed inset-0 z-30 bg-black/25 backdrop-blur-sm hidden md:hidden transition-opacity duration-300">
</div>

<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 hidden w-full shrink-0 flex-col bg-dusky text-black md:static md:flex print:hidden transition-all duration-300 ease-in-out <?= $sidebarCollapsed ? 'md:w-20' : 'md:w-64' ?>">
    
    <!-- Top Header & Scrollable User Info -->
    <div class="shrink-0 border-b border-slate-800/20 p-4">
        <!-- Header bar with Title, Minimize button & Close (X) button for mobile -->
        <div class="flex items-center justify-between gap-2">
            <div class="leading-tight min-w-0 flex-1 sidebar-header-title">
                <p class="text-base font-extrabold tracking-tight text-slate-900 truncate">Kitchen & Food</p>
                <p class="text-xs font-semibold text-slate-700/80 truncate">Production Department</p>
            </div>

            <div class="flex items-center gap-1 shrink-0">
                <!-- Minimize / Toggle Sidebar Button (Desktop Only) -->
                <button type="button"
                        id="sidebarToggleBtn"
                        onclick="toggleDesktopSidebar()"
                        class="hidden md:flex p-4 rounded-lg text-slate-800 hover:bg-black/10 transition-colors"
                        title="<?= $sidebarCollapsed ? 'Expand sidebar' : 'Minimize sidebar' ?>">
                    <svg id="sidebarToggleIcon" class="h-5 w-5 transition-transform duration-300 <?= $sidebarCollapsed ? 'rotate-180' : '' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                    </svg>
                </button>

                <!-- Close (X) Button (Mobile Only) -->
                <button type="button"
                        onclick="closeMobileSidebar()"
                        class="flex md:hidden p-1.5 rounded-lg text-slate-800 hover:bg-black/10 transition-colors"
                        title="Close sidebar">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Navigation - Scrollable on mobile -->
    <nav class="flex-1 space-y-4 px-3 py-4 text-sm overflow-y-auto md:overflow-visible" aria-label="Main navigation">
        <?php foreach ($groups as $title => $items):
            $items = array_filter($items, fn($i) => $i[0] === null || can($i[0]));
            if (!$items) continue; ?>
            <div class="space-y-2">
                <?php if ($title !== ''): ?>
                    <p class="mb-1.5 px-3 text-[11px] font-bold uppercase tracking-wider text-slate-600/80 sidebar-section-title">
                        <?= e($title) ?>
                    </p>
                <?php endif; ?>
                <?php foreach ($items as [$perm, $pageKey, $href, $label, $countKey]):
                    $active = ($currentPage === $pageKey); ?>
                    <a href="<?= e($href) ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-150 group sidebar-nav-link <?= $active ? 'bg-khaki text-slate-900 shadow-sm border border-black/5' : 'text-slate-900 hover:bg-white/30' ?>"
                       title="<?= e($label) ?>">
                        <span class="shrink-0 flex items-center justify-center relative">
                            <?php
                            $icons = [
                                'dashboard' => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>',
                                'orders'    => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>',
                                'recipes'   => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
                                'inventory' => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
                                'expiry'    => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                                'waste'     => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
                                'safety'    => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
                                'reports'   => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
                                'users'     => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
                                'user_activity' => '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                            ];
                            echo $icons[$pageKey] ?? '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                            ?>

                            <!-- Hover label for collapsed state -->
                            <div class="sidebar-hover-label hidden absolute left-full ml-5 px-3 py-1 !text-base bg-khaki text-black text-xs font-semibold rounded shadow-lg whitespace-nowrap z-50 pointer-events-none">
                                <?= e($label) ?>
                            </div>
                        </span>
                        <span class="flex-1 truncate sidebar-nav-label">
                            <?= e($label) ?>
                        </span>
                        <?php if ($countKey && $counts[$countKey] > 0): ?>
                            <span class="shrink-0 min-w-[1.25rem] h-5 px-1.5 rounded-full flex items-center justify-center text-[11px] font-bold text-white <?= $countTone[$countKey] ?> sidebar-nav-count">
                                <?= (int) $counts[$countKey] ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- Scrollable User Profile & Logout Box inside Header -->
    <div class="mt-auto max-h-24 py-1 custom-scrollbar sidebar-user-profile">
        <!-- Profile container for collapsed hover -->
        <div class="sidebar-user-container flex items-center justify-between gap-2 min-w-max bg-white/40 p-2.5">
            <div class="flex items-center gap-2.5 min-w-0">
                <!-- User Profile Avatar/Logo -->
                <div class="w-8 h-8 rounded-full bg-khaki flex items-center justify-center text-slate-900 font-bold text-xs shrink-0 border border-black/10 shadow-sm relative group/avatar">
                    <?= strtoupper($name[0] ?? 'U') ?>

                    <!-- Identity Tooltip when Collapsed -->
                    <div class="sidebar-hover-label hidden absolute left-full ml-4 px-2 py-1 bg-slate-900 text-white text-xs font-semibold rounded shadow-lg whitespace-nowrap z-50 pointer-events-none">
                        <p class="font-bold"><?= $name ?></p>
                        <p class="text-[10px] text-slate-300 font-normal"><?= $userRoleLabel ?></p>
                    </div>
                </div>
                <div class="min-w-0 sidebar-user-info">
                    <p class="text-xs font-bold text-slate-900 truncate max-w-[110px]" title="<?= $name ?>"> <?= $name ?></p>
                    <p class="text-[10px] font-semibold text-slate-600 truncate max-w-[110px]" title="<?= $userRoleLabel ?>"><?= $userRoleLabel ?></p>
                </div>
            </div>

            <!-- Logout Icon-Only Button placed right after Username/Name and Role -->
            <form method="POST" action="logout.php" class="shrink-0 sidebar-logout-btn">
                <?= csrf_field() ?>
                <button type="submit"
                        class="p-1.5 rounded-lg text-red-700 hover:bg-red-500/20 hover:text-red-900 transition-colors flex items-center justify-center"
                        title="Sign out / Logout">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>

<script>
function openMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar && backdrop) {
        sidebar.classList.remove('hidden');
        sidebar.classList.add('flex');
        backdrop.classList.remove('hidden');
    }
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar && backdrop) {
        sidebar.classList.add('hidden');
        sidebar.classList.remove('flex');
        backdrop.classList.add('hidden');
    }
}

function toggleDesktopSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    // Don't toggle on mobile
    if (window.innerWidth < 768) return;

    const isCollapsed = sidebar.classList.contains('md:w-20');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const toggleIcon = document.getElementById('sidebarToggleIcon');

    if (isCollapsed) {
        sidebar.classList.remove('md:w-20');
        sidebar.classList.add('md:w-64');
        if (toggleIcon) toggleIcon.classList.remove('rotate-180');
        if (toggleBtn) toggleBtn.title = 'Minimize sidebar';
    } else {
        sidebar.classList.remove('md:w-64');
        sidebar.classList.add('md:w-20');
        if (toggleIcon) toggleIcon.classList.add('rotate-180');
        if (toggleBtn) toggleBtn.title = 'Expand sidebar';
    }

    // Persist via AJAX call
    const formData = new FormData();
    formData.append('csrf', '<?= e($_SESSION['csrf'] ?? '') ?>');
    formData.append('collapsed', isCollapsed ? '0' : '1');

    fetch('index.php?page=ajax_sidebar_toggle', {
        method: 'POST',
        body: formData
    }).catch(() => {});
}

// Reset sidebar state on window resize
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    if (window.innerWidth < 768) {
        // Mobile: ensure full width and reset collapsed classes
        sidebar.classList.remove('md:w-20', 'md:w-64');
        sidebar.style.width = '100%';
    } else {
        // Desktop: restore appropriate width
        const isCollapsed = <?= $sidebarCollapsed ? 'true' : 'false' ?>;
        if (isCollapsed) {
            sidebar.classList.add('md:w-20');
            sidebar.classList.remove('md:w-64');
        } else {
            sidebar.classList.add('md:w-64');
            sidebar.classList.remove('md:w-20');
        }
        sidebar.style.width = '';
    }
});
</script>