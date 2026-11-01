<?php
// index.php - front controller. Every page is opened as index.php?page=NAME.
require __DIR__ . '/includes/auth.php';          // session, login check, helpers

// Sections refuse to run unless they are loaded through this file.
define('KAFPD_APP', true);

// Handle AJAX sidebar toggle before any output
if (($_GET['page'] ?? '') === 'ajax_sidebar_toggle') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $collapsed = filter_var($_POST['collapsed'] ?? 0, FILTER_VALIDATE_INT);
        $_SESSION['sidebar_collapsed'] = (bool) $collapsed;
        http_response_code(204);
        exit;
    }
    http_response_code(400);
    exit;
}

// 1. Allowed pages (a file in /sections that is not listed here can never be loaded).
$allowed_pages = [
    'dashboard', 'orders', 'inventory', 'recipes',
    'receiving', 'expiry', 'waste', 'reports', 'safety', 'users', 'user_activity',
    'dashboard_ajax'
];

// 2. Which page? Default to the dashboard.
$page = $_GET['page'] ?? 'dashboard';
if (!is_string($page) || !in_array($page, $allowed_pages, true)) {
    $page = 'dashboard';
}

// 3. Run the section and capture its HTML. A section may redirect (after a form post) before any output.
ob_start();
try {
    require __DIR__ . '/sections/' . $page . '.php';
    $page_content = ob_get_clean();
} catch (Throwable $ex) {
    ob_end_clean();
    error_log('[' . $page . '] ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);   // details only on this machine
    $page_content = '<div role="alert" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">'
                  . 'This page could not be loaded.'
                  . ($local ? '<br><code class="break-all">' . e($ex->getMessage()) . ' (' . e(basename($ex->getFile())) . ':' . (int) $ex->getLine() . ')</code>' : '')
                  . '</div>';
}

// A message left by the last form post (success or error), shown once.
$flash = take_flash();

// Fallback if the section did not set a title.
$pageTitle = $pageTitle ?? ucfirst($page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - KAFPD</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden text-slate-800">

    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col overflow-y-auto">

        <!-- Top Navbar -->
        <?php include __DIR__ . '/includes/navbar.php'; ?>

        <!-- Dynamic Page Content -->
        <main class="p-8">
            <?php if ($flash): ?>
                <div role="status" class="mb-6 rounded border px-4 py-3 text-sm <?= $flash['type'] === 'success'
                    ? 'border-green-300 bg-green-50 text-green-800'
                    : 'border-red-300 bg-red-50 text-red-800' ?>">
                    <?= e($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <?= $page_content ?>
        </main>
    </div>

    <script>
        // Menu button on small screens
        document.getElementById('menuBtn')?.addEventListener('click', function () {
            var s = document.getElementById('sidebar');
            s.classList.toggle('hidden');
            s.classList.toggle('flex');
        });
    </script>
</body>
</html>
