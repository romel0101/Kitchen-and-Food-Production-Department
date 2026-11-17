<?php
// error.php - standalone 403 / 404 page. It needs no login and no database, so it can be shown to anyone who
// asks for a wrong URL. It is used by:
//   * .htaccess  (blocked folders and files -> 403, unknown paths -> 404)        error.php?code=403
//   * the guard at the top of every sections/*.php file when opened directly      define('ERROR_CODE', 403)
// Pages INSIDE the app (wrong ?page= or a role without permission) use sections/404.php and sections/403.php,
// which keep the sidebar and top bar.

$code = defined('ERROR_CODE') ? (int) ERROR_CODE : (int) ($_GET['code'] ?? 404);
if (!in_array($code, [403, 404], true)) $code = 404;
http_response_code($code);
header('Cache-Control: no-store');

// URL of the app folder, so the links work whether the app sits in the web root or in a subfolder.
$root = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$here = str_replace('\\', '/', __DIR__);
$base = ($root !== '' && str_starts_with($here, $root)) ? rtrim(substr($here, strlen($root)), '/') . '/' : '/';

$title = $code === 403 ? 'Access Forbidden' : 'Page Not Found';
$text  = $code === 403
    ? 'You are not allowed to open this address. Go back to the dashboard and use the menu.'
    : 'The page you are looking for does not exist, was moved, or the link is wrong.';
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $code ?> <?= $h($title) ?> - KAFPD</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center px-4 text-slate-800">
    <main class="max-w-md text-center">
        <p class="text-7xl font-black <?= $code === 403 ? 'text-red-500' : 'text-slate-400' ?>"><?= $code ?></p>
        <h1 class="mt-3 text-2xl font-extrabold text-slate-900"><?= $h($title) ?></h1>
        <p class="mt-2 mb-6 text-sm text-slate-500"><?= $h($text) ?></p>
        <a href="<?= $h($base) ?>index.php?page=dashboard" class="inline-block px-5 py-2.5 text-xs font-bold uppercase tracking-wider bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors">Return to Dashboard</a>
        <p class="mt-4 text-xs text-slate-400">Kitchen &amp; Food Production Department</p>
    </main>
</body>
</html>
