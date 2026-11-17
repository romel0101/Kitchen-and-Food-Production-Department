<?php
const AUTH_OPTIONAL = true;
require __DIR__ . '/includes/auth.php';

const DUMMY_HASH = '$2b$10$qtc1ZIwVnIkMINEzL5qWRO3dqLHhJPKHwC8zniBsLLiCp1i7ke4LC';

if (isset($_SESSION['user_id'], $_SESSION['role']) && array_key_exists($_SESSION['role'], PERMISSIONS)) {
    redirect('index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter both your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, email, password, role, is_active FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        $passwordOk = password_verify($password, $user['password'] ?? DUMMY_HASH);

        if ($user && $passwordOk) {
            if (isset($user['is_active']) && $user['is_active'] == 0) {
                redirect('login.php?error=disabled');
            }
            
            if (!array_key_exists($user['role'], PERMISSIONS)) {
                $error = 'This account has no valid role. Ask an administrator to fix it.';
            } else {
                session_regenerate_id(true);
                $_SESSION = [
                    'user_id'  => (int) $user['id'],
                    'email'    => $user['email'],
                    'role'     => $user['role'],
                    'csrf'     => bin2hex(random_bytes(32)),
                ];
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password = :p WHERE id = :id')
                        ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }
                // Update last_login timestamp
                $pdo->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute([':id' => $user['id']]);
                log_activity('login', 'User signed in');
                
                // Slight simulated delay server-side to guarantee min load time sync if needed, 
                // but handled cleanly via JavaScript below.
                redirect('index.php');
            }
        } else {
            usleep(300000);
            log_activity('login_failed', "Failed login attempt for email: $email");
            $error = 'Wrong email or password.';
        }
    }
}

// Catch session flash errors / URL error parameters
$urlError = '';
if (isset($_GET['error']) && $_GET['error'] === 'disabled') {
    $urlError = 'Access denied. Your account is currently disabled. Please contact the system administrator to regain access.';
}

$displayError = $error ?: $urlError;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | KAFPD</title>

    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="relative flex min-h-screen items-center justify-center bg-slate-100 p-4 font-sans text-slate-800 selection:bg-orange-200 selection:text-orange-900">
    
    <!-- Full-Screen Loading Screen Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 z-50 hidden flex-col items-center justify-center bg-white/80 backdrop-blur-sm transition-all">
        <div class="flex flex-row items-center gap-5 rounded-2xl border border-slate-200 bg-white p-8 shadow-2xl">
            <svg version="1.1" id="L6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" xml:space="preserve" style="width: 60px; height: 60px; display: inline-block;">
            <rect fill="none" stroke="#fff" stroke-width="4" x="25" y="25" width="50" height="50">
                <animateTransform attributeName="transform" dur="0.5s" from="0 50 50" to="180 50 50" type="rotate" id="strokeBox" attributeType="XML" begin="rectBox.end" />
            </rect>
            <rect x="27" y="27" fill="#fff" width="46" height="50">
                <animate attributeName="height" dur="1.3s" attributeType="XML" from="50" to="0" id="rectBox" fill="freeze" begin="0s;strokeBox.end" />
            </rect>
            </svg>
            <div class="text-start">
                <p class="text-base font-bold text-slate-900">Signing you in...</p>
                <p class="text-xs text-slate-500 mt-0.5">Please wait while we prepare your workspace.</p>
            </div>
        </div>
    </div>

    <main class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-xl sm:p-10">
        <div class="mb-6 text-center">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-900">Kitchen & Food Production</h1>
            <p class="text-sm text-slate-500">Sign in with your staff account to continue.</p>
        </div>

        <?php if ($displayError): ?>
            <div role="alert" class="mt-5 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 shadow-sm">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span class="font-medium"><?= e($displayError) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="loginForm" class="mt-6 space-y-5">
            <?= csrf_field() ?>
            
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Email Address</label>
                <input type="email" name="email" required autofocus autocomplete="email" maxlength="100" value="<?= e($email) ?>"
                       class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-sm text-slate-900 shadow-sm transition-all focus:border-orange-500 focus:outline-none focus:ring-4 focus:ring-orange-500/10 placeholder:text-slate-400"
                       placeholder="you@example.com">
            </div>
            
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">Password</label>
                </div>
                <div class="relative">
                    <input type="password" id="passwordInput" name="password" required autocomplete="current-password"
                           class="block w-full rounded-lg border border-slate-300 px-4 py-3 pr-10 text-sm text-slate-900 shadow-sm transition-all focus:border-orange-500 focus:outline-none focus:ring-4 focus:ring-orange-500/10 placeholder:text-slate-400"
                           placeholder="••••••••">
                    <button type="button" id="togglePassword" aria-label="Toggle password visibility" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 focus:outline-none">
                        <svg id="eyeIcon" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </div>
            
            <button type="submit" id="submitBtn" class="flex w-full items-center justify-center rounded-lg bg-orange-600 px-4 py-3.5 text-sm font-bold text-white shadow-md shadow-orange-600/20 transition-all hover:bg-orange-700 focus:outline-none focus:ring-4 focus:ring-orange-500/20 active:scale-[0.98]">Sign In</button>
        </form>
    </main>

    <script>
    // Password visibility toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');
    
    togglePassword.addEventListener('click', function () {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.classList.toggle('text-orange-600');
    });

    // Form submission with forced 3-second minimum loading duration
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const emailVal = document.querySelector('input[name="email"]').value.trim();
        const passVal = document.querySelector('input[name="password"]').value;

        if (emailVal !== '' && passVal !== '') {
            e.preventDefault(); // Stop immediate standard form submission

            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;

            // Wait 3 seconds (3000ms) before actually submitting the form to the server
            setTimeout(() => {
                e.target.submit();
            }, 2500);
        }
    });
    </script>
</body>
</html>