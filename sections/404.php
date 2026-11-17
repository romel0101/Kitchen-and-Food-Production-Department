<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 404); require __DIR__ . '/../error.php'; exit; }
http_response_code(404);
$pageTitle = 'Page not found';
?>
<div class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">
    <div class="bg-slate-100 text-slate-600 rounded-full p-4 mb-4">
        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Page Not Found (404)</h1>
    <p class="text-sm text-slate-500 max-w-md mb-6">The page you are looking for does not exist, was moved, or the link is wrong. Use the menu or go back to the dashboard.</p>
    <a href="index.php?page=dashboard" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wider bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors">Return to Dashboard</a>
</div>
