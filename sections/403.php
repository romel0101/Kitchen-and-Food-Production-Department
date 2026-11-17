<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
http_response_code(403);
$pageTitle = 'Access forbidden';
?>
<div class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">
    <div class="bg-red-50 text-red-600 rounded-full p-4 mb-4">
        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
    </div>
    <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Access Forbidden (403)</h1>
    <p class="text-sm text-slate-500 max-w-md mb-6">Your account (<?= e(ROLE_LABELS[$role] ?? 'unknown role') ?>) does not have permission to open this part of the kitchen system. Ask an admin if you need access.</p>
    <a href="index.php?page=dashboard" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wider bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors">Return to Dashboard</a>
</div>
