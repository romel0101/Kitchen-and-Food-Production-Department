<?php
const AUTH_OPTIONAL = true;
require __DIR__ . '/includes/auth.php';

const DUMMY_HASH = '$2b$10$qtc1ZIwVnIkMINEzL5qWRO3dqLHhJPKHwC8zniBsLLiCp1i7ke4LC';

if (isset($_SESSION['user_id'], $_SESSION['role']) && array_key_exists($_SESSION['role'], PERMISSIONS)) {
    redirect('index.php');
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter both your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        $passwordOk = password_verify($password, $user['password'] ?? DUMMY_HASH);

        if ($user && $passwordOk) {
            if (!array_key_exists($user['role'], PERMISSIONS)) {
                $error = 'This account has no valid role. Ask an administrator to fix it.';
            } else {
                session_regenerate_id(true);
                $_SESSION = [
                    'user_id'  => (int) $user['id'],
                    'username' => $user['username'],
                    'role'     => $user['role'],
                    'csrf'     => bin2hex(random_bytes(32)),
                ];
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password = :p WHERE id = :id')
                        ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }
                log_activity('login', 'User signed in');
                redirect('index.php');
            }
        } else {
            usleep(300000);
            log_activity('login_failed', "Failed login attempt for username: $username");
            $error = 'Wrong username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | Kitchen and Food Production</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['"IBM Plex Sans"', 'system-ui', 'sans-serif'] } } } };</script>
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 p-4 font-sans text-slate-800">
    <main class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-8">
        <h1 class="text-xl font-semibold">Kitchen and food production</h1>
        <p class="mt-1 text-sm text-slate-500">Sign in with your staff account.</p>

        <?php if ($error): ?>
            <div role="alert" class="mt-5 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="mt-6 space-y-5">
            <?= csrf_field() ?>
            <label class="block text-sm font-medium">Username
                <input type="text" name="username" required autofocus autocomplete="username" maxlength="50" value="<?= e($username) ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200">
            </label>
            <label class="block text-sm font-medium">Password
                <input type="password" name="password" required autocomplete="current-password"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200">
            </label>
            <button class="w-full rounded bg-orange-600 px-4 py-2.5 font-semibold text-white hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:ring-offset-2">Sign in</button>
        </form>
    </main>
</body>
</html>
