<?php
require __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
check_csrf();

log_activity('logout', 'User signed out');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('login.php');