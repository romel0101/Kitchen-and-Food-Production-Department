<header class="sticky top-0 z-20 flex h-16 w-full items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6" style="scrollbar-gutter: stable;">
    <div class="flex items-center md:hidden">
        <button type="button" id="menuBtn" aria-label="Open menu" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>
    </div>

    <h1 class="truncate text-xl font-extrabold tracking-tight sm:text-2xl"><?= htmlspecialchars($pageTitle) ?></h1>

    <div class="ml-auto flex items-center gap-4">
        <?= $pageActions ?? '' ?>

        <div x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)" class="hidden text-right sm:block">
            <p class="text-lg font-bold leading-none tabular-nums" x-text="now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })">
                <?= date('h:i A') ?>
            </p>
            <p class="mt-1 text-xs text-slate-500" x-text="now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })">
                <?= date('D, M j') ?>
            </p>
        </div>
    </div>
</header>