<?php
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'hotel_kitchen';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

$dsn = getenv('DB_DSN') ?: sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

// One timezone for PHP and MySQL, so times shown on screen match the times the database stamps.
// Change it in one place here, or set the APP_TZ environment variable.
$appTz = getenv('APP_TZ') ?: 'Asia/Manila';
date_default_timezone_set($appTz);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("SET time_zone = '" . date('P') . "'");   // e.g. +08:00: NOW() and CURRENT_TIMESTAMP follow the app timezone
    }
} catch (PDOException $e) {
    error_log('[db] ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}
