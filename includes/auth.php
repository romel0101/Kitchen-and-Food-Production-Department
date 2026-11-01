<?php
// includes/auth.php
// The one file every page loads first. It starts the session, loads the database connection,
// blocks anyone who is not logged in, and defines the role rules and shared helpers.
//
// Usage in a protected page:   require __DIR__ . '/includes/auth.php';
// Usage in login.php:          const AUTH_OPTIONAL = true;  (before the require, so no redirect loop)

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// ---- Roles. These are the values stored in users.role ----
const ROLE_ADMIN     = 'A';
const ROLE_CHEF      = 'C';
const ROLE_INVENTORY = 'I';

const ROLE_LABELS = [ROLE_ADMIN => 'Admin', ROLE_CHEF => 'Chef Staff', ROLE_INVENTORY => 'Inventory Staff'];

// Access table: change who can use what here and nowhere else.
// The sidebar, every page guard and every form handler read from this table.
const PERMISSIONS = [
    ROLE_ADMIN     => ['orders', 'recipes', 'inventory', 'receiving', 'expiry', 'waste', 'safety', 'reports', 'users'],
    ROLE_CHEF      => ['orders', 'recipes'],
    ROLE_INVENTORY => ['inventory', 'receiving', 'expiry'],
];

// ---- Business rules you can tune ----
const WAIT_LIMIT_MIN   = ['pending' => 10, 'preparing' => 25]; // minutes before an order counts as waiting too long
const EXPIRY_WARN_DAYS = 3;                                    // flag batches expiring within this many days
const TEMP_RULES = [                                           // safe ranges in degrees Celsius
    'Walk-in chiller'              => ['max' => 5.0],
    'Freezer'                      => ['max' => -18.0],
    'Hot holding'                  => ['min' => 63.0],
    'Cooked food core temperature' => ['min' => 75.0],
];
const WASTE_REASONS = [
    'spoiled' => 'Spoiled', 'damaged' => 'Damaged', 'expired' => 'Expired',
    'unused'  => 'Unused or overproduced', 'other' => 'Other',
];
const SANITATION_CHECKS = [
    'Work surfaces sanitized', 'Hand-wash station stocked', 'Equipment cleaned',
    'Floors and drains clear', 'Pest check done',
];

// ---- Database ----
require_once __DIR__ . '/../config/db.php';

// ---- Menu availability (recipe vs. ingredient stock): check_menu_availability() and friends ----
require_once __DIR__ . '/stock_functions.php';

// ---- Login gate ----
if (!defined('AUTH_OPTIONAL')) {
    if (!isset($_SESSION['user_id'], $_SESSION['role']) || !array_key_exists($_SESSION['role'], PERMISSIONS)) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    header('Cache-Control: no-store'); // the back button must not show a page after logout
}

$role   = $_SESSION['role'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function can(string $permission): bool {
    return in_array($permission, PERMISSIONS[$_SESSION['role'] ?? ''] ?? [], true);
}
function deny(): never {
    http_response_code(403);
    exit('403 Forbidden: your role is not allowed to do this.');
}
function require_perm(string $permission): void {
    if (!can($permission)) deny();
}
function e($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and reload the page.');
    }
}
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function take_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// 3 -> "3", 0.120 -> "0.12"
function num($v, int $decimals = 3): string {
    return rtrim(rtrim(number_format((float) $v, $decimals, '.', ''), '0'), '.');
}
function fmt_wait(int $min): string {
    return $min < 60 ? $min . ' min' : intdiv($min, 60) . ' h ' . ($min % 60) . ' min';
}
function valid_date(?string $d): ?string {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}
function badge(string $text, string $tone = 'gray'): string {
    $tones = [
        'gray'  => 'bg-slate-100 text-slate-700',
        'red'   => 'bg-red-100 text-red-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'green' => 'bg-green-100 text-green-800',
        'blue'  => 'bg-blue-100 text-blue-800',
    ];
    // Only the chosen tone's colours are applied, so a green badge is green and a red one is red.
    return '<span class="inline-block whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide '
         . ($tones[$tone] ?? $tones['gray']) . '">' . e($text) . '</span>';
}

// Runs $work inside a transaction. $work returns the success message, or throws RuntimeException
// with a user-facing message to cancel everything it changed.
function run_tx(PDO $pdo, callable $work): void {
    try {
        $pdo->beginTransaction();
        $msg = $work($pdo);
        $pdo->commit();
        flash('success', $msg);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($ex->getMessage());
        flash('error', 'Something went wrong and nothing was saved. Try again.');
    }
}

// ---- User Activity Logging ----
function log_activity(string $type, string $description, ?int $userId = null): void {
    global $pdo;
    $uid = $userId ?? $GLOBALS['userId'] ?? 0;
    if (!$uid) return;
    try {
        $pdo->prepare('INSERT INTO user_activity_log (user_id, activity_type, description, ip_address, user_agent)
                       VALUES (:u, :t, :d, :ip, :ua)')
            ->execute([
                ':u'  => $uid,
                ':t'  => $type,
                ':d'  => $description,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
    } catch (Throwable) {
        // Silently fail - activity logging should never break the main flow
    }
}

// Takes stock out of an ingredient and out of its batches, soonest expiry first.
// Call inside a transaction.
function deduct_stock(PDO $pdo, int $ingredientId, float $qty): void {
    $pdo->prepare('UPDATE ingredients SET current_stock = GREATEST(0, current_stock - :q) WHERE id = :id')
        ->execute([':q' => round($qty, 3), ':id' => $ingredientId]);

    $sel = $pdo->prepare('SELECT id, quantity_remaining FROM stock_receipts
                          WHERE ingredient_id = :id AND quantity_remaining > 0
                          ORDER BY expiry_date IS NULL, expiry_date, received_at FOR UPDATE');
    $sel->execute([':id' => $ingredientId]);
    $upd  = $pdo->prepare('UPDATE stock_receipts SET quantity_remaining = :r WHERE id = :id');
    $left = $qty;
    foreach ($sel->fetchAll() as $batch) {
        if ($left <= 0) break;
        $have = (float) $batch['quantity_remaining'];
        $take = min($left, $have);
        $upd->execute([':r' => round($have - $take, 3), ':id' => $batch['id']]);
        $left -= $take;
    }
}

// Writes one row to the stock ledger (the "Recent Stock Movements" table). Call inside the same
// transaction as the stock change so the two can never disagree.
//   $change = signed change to the ingredient's global current_stock (waste = negative, receive = positive)
function log_stock_movement(PDO $pdo, int $ingredientId, ?int $batchId, string $type, float $change, ?string $details, ?int $userId): void {
    $s = $pdo->prepare('SELECT current_stock FROM ingredients WHERE id = :id');
    $s->execute([':id' => $ingredientId]);
    $after = $s->fetchColumn();
    $pdo->prepare('INSERT INTO stock_movements (ingredient_id, batch_id, movement_type, quantity_change, stock_after, details, created_by)
                   VALUES (:i, :b, :t, :c, :a, :d, :u)')
        ->execute([':i' => $ingredientId, ':b' => $batchId, ':t' => $type, ':c' => round($change, 3),
                   ':a' => $after === false ? null : $after, ':d' => $details, ':u' => $userId ?: null]);
}
