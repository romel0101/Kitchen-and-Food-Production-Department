<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_once __DIR__ . '/../includes/stock_functions.php';
require_perm('orders');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action  = $_POST['action'] ?? '';
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);

    switch ($action) {
        case 'mark_ready':
            if (!$orderId) { flash('error', 'Invalid order.'); break; }
            // Marking a ticket ready is the moment its ingredients leave stock (for POS tickets and test tickets alike).
            run_tx($pdo, function (PDO $pdo) use ($orderId, $userId) {
                $u = $pdo->prepare('UPDATE orders SET ready_at = NOW() WHERE id = :id AND ready_at IS NULL');
                $u->execute([':id' => $orderId]);
                if (!$u->rowCount()) throw new RuntimeException('That order could not be marked ready (it may already be ready).');
                $short = consume_order_stock($pdo, $orderId, $userId);
                log_activity('order_ready', "Marked order #$orderId as ready" . ($short ? ' (stock ran short: ' . implode('; ', $short) . ')' : ''));
                return 'Order marked ready and its ingredients were taken out of stock.'
                     . ($short ? ' Warning: stock ran short for ' . implode('; ', $short) . '. Check the stock count.' : '');
            });
            break;

        case 'create_order':
            require_perm('users');
            $orderType = trim($_POST['order_type'] ?? '') ?: 'Dine-In';
            $tableNum  = trim($_POST['table_number'] ?? '') ?: null;
            $custName  = trim($_POST['customer_name'] ?? '') ?: 'Guest';

            $menuItemIds  = $_POST['menu_item_id'] ?? [];
            $quantities   = $_POST['current_stock'] ?? [];
            $instructions = $_POST['special_instructions'] ?? [];

            // Compile and validate the list of items
            $itemsToOrder = [];
            foreach ($menuItemIds as $index => $mId) {
                $m    = (int) $mId;
                $qty  = (int) ($quantities[$index] ?? 0);
                $note = trim($instructions[$index] ?? '') ?: null;

                if ($m > 0 && $qty > 0 && $qty <= 50) {
                    $s = $pdo->prepare('SELECT name FROM menu_items WHERE id = :id');
                    $s->execute([':id' => $m]);
                    $item = $s->fetchColumn();
                    if ($item) {
                        $itemsToOrder[] = [
                            'm'    => $m,
                            'qty'  => $qty,
                            'item' => $item,
                            'note' => $note
                        ];
                    }
                }
            }

            if (empty($itemsToOrder)) {
                flash('error', 'Please add at least one valid menu item and quantity.');
                break;
            }

            // Refuse a test ticket for a dish the kitchen cannot make (all lines of the same dish added together).
            $portionsByDish = [];
            foreach ($itemsToOrder as $oi) $portionsByDish[$oi['m']] = ($portionsByDish[$oi['m']] ?? 0) + $oi['qty'];
            $blocked = [];
            foreach ($portionsByDish as $mid => $portions) {
                $av = get_menu_availability($pdo, (int) $mid, (int) $portions);
                if (!$av['available']) $blocked[] = implode(', ', $av['missing']) . ' for ' . $portions . ' portion(s)';
            }
            if ($blocked) {
                flash('error', 'Not enough stock: ' . implode('; ', $blocked) . '.');
                break;
            }

            try {
                run_tx($pdo, function (PDO $pdo) use ($orderType, $tableNum, $custName, $itemsToOrder, $userId) {
                    $posOrderId = 'POS-' . rand(1000, 9999);

                    // 1. Insert parent ticket with a temporary UUID to satisfy the UNIQUE constraint
                    $pdo->prepare('INSERT INTO orders (order_number, pos_order_id, order_type, table_number, customer_name)
                                   VALUES (UUID(), :pos_id, :type, :tbl, :cust)')
                        ->execute([
                            ':pos_id'  => $posOrderId,
                            ':type'    => $orderType,
                            ':tbl'     => $tableNum,
                            ':cust'    => $custName,
                        ]);
                    
                    // 2. Grab the newly generated Auto-Increment ID
                    $orderId = (int) $pdo->lastInsertId();

                    // 3. Update the order_number to just be the raw ID (e.g., 1, 2, 3...)
                    $pdo->prepare('UPDATE orders SET order_number = :ord_num WHERE id = :id')
                        ->execute([':ord_num' => (string) $orderId, ':id' => $orderId]);

                    // 4. Process and insert each item
                    foreach ($itemsToOrder as $orderItem) {
                        $m    = $orderItem['m'];
                        $qty  = $orderItem['qty'];
                        $item = $orderItem['item'];
                        $note = $orderItem['note'];

                        $pdo->prepare('INSERT INTO order_items (order_id, pos_order_id, menu_item_id, item_name, current_stock, special_instructions)
                                       VALUES (:oid, :posid, :mid, :iname, :qty, :spec)')
                            ->execute([
                                ':oid'   => $orderId,
                                ':posid' => $posOrderId,
                                ':mid'   => $m,
                                ':iname' => $item,
                                ':qty'   => $qty,
                                ':spec'  => $note,
                            ]);
                    }

                    // run_tx() shows this message only after the commit succeeds. If anything above
                    // throws, run_tx() rolls everything back and shows that error instead.
                    $itemSummary = implode(', ', array_map(fn($x) => "{$x['qty']}x {$x['item']}", $itemsToOrder));
                    log_activity('create_order', "Created order #$orderId: $itemSummary ({$orderType}" . ($tableNum ? ", Table $tableNum" : '') . ", {$custName})");
                    return 'Ticket ' . $orderId . ' sent to the kitchen: ' . count($itemsToOrder) . ' item(s) in the queue.';
                });
            } catch (Throwable $ex) {
                error_log($ex->getMessage());
                flash('error', 'Could not create the ticket. Nothing was saved.');
            }
            break;

        default:
            flash('error', 'Unknown action.');
    }
    redirect('index?page=orders');
}

// Fetch all parent orders
$orders = $pdo->query("SELECT o.*, TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS wait_sec
                       FROM orders o
                       WHERE o.ready_at IS NULL
                       ORDER BY o.created_at ASC, o.id ASC")->fetchAll(PDO::FETCH_ASSOC);

$activeOrders = [];
$orderIds     = [];
foreach ($orders as $o) {
    if ($o['ready_at'] === null) {
        $o['items'] = [];
        $activeOrders[$o['id']] = $o;
        $orderIds[] = (int) $o['id'];
    }
}

// Fetch grouped items if there are active orders
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $items = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders) ORDER BY id ASC");
    $items->execute($orderIds);
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $i) {
        if (isset($activeOrders[$i['order_id']])) {
            $activeOrders[$i['order_id']]['items'][] = $i;
        }
    }
}

$menu = $menuAvail = [];
if (can('users')) {
    $menu = $pdo->query('SELECT id, name FROM menu_items ORDER BY name')->fetchAll();
    foreach ($menu as $m) $menuAvail[(int) $m['id']] = check_menu_availability($pdo, (int) $m['id']);
}
$pageTitle = 'Order Queue';
?>

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Order Queue</h1>
        <p class="text-sm text-slate-500">Live kitchen production board</p>
    </div>
    <div class="flex items-center gap-2 rounded-full bg-white px-4 py-1.5 shadow-sm border border-slate-200">
        <span class="relative flex h-3 w-3">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-3 w-3 bg-orange-500"></span>
        </span>
        <span class="text-sm font-bold text-slate-700"><?= count($activeOrders) ?> Active</span>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
    <?php if (!$activeOrders): ?>
        <div class="col-span-full rounded-xl border border-slate-200 bg-white p-12 text-center shadow-sm">
            <svg class="mx-auto h-12 w-12 text-slate-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            <h3 class="text-lg font-medium text-slate-900">No active orders</h3>
            <p class="mt-1 text-sm text-slate-500">The kitchen queue is empty. Waiting for new tickets...</p>
        </div>
    <?php endif; ?>

    <?php 
    foreach ($activeOrders as $o):
        $totalSec = max(0, (int)($o['wait_sec'] ?? 0));
        $late     = $totalSec >= (WAIT_LIMIT_MIN['preparing'] ?? 15) * 60;

        $cardClasses = $late 
            ? 'rounded-xl border-2 border-red-500 bg-white shadow-lg overflow-hidden flex flex-col relative' 
            : 'rounded-xl border border-slate-200 bg-white shadow-sm hover:shadow-md transition-shadow overflow-hidden flex flex-col';
            
        $headerClasses = $late ? 'bg-red-50/80 px-5 py-4 border-b border-red-100' : 'bg-slate-50/80 px-5 py-4 border-b border-slate-100';
        $locationStr = $o['table_number'] ? 'Table ' . e($o['table_number']) : e($o['order_type']);
    ?>
        <article class="<?= $cardClasses ?>">
            <?php if ($late): ?>
                <div class="absolute top-0 left-0 right-0 h-1 bg-red-600 animate-pulse"></div>
            <?php endif; ?>

            <!-- Ticket Header -->
            <div class="<?= $headerClasses ?>">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h2 class="text-xl font-black text-slate-900">#<?= e($o['order_number']) ?></h2>
                            <span class="rounded bg-slate-200 px-2 py-0.5 text-xs font-bold uppercase text-slate-700"><?= $locationStr ?></span>
                        </div>
                        <p class="text-sm font-medium text-slate-600 truncate"><?= e($o['customer_name']) ?></p>
                        <p class="text-xs text-slate-400 mt-0.5">In: <?= e(date('H:i', strtotime($o['created_at']))) ?> <?php if ($o['pos_order_id']): ?>· Ref: <?= e($o['pos_order_id']) ?><?php endif; ?></p>
                    </div>
                    
                    <div class="text-right shrink-0">
                        <div class="inline-flex items-center justify-center rounded-lg <?= $late ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700' ?> px-3 py-1.5 min-w-[70px]">
                            <span class="text-xl font-bold tabular-nums" data-timer="<?= (int) $totalSec ?>">
                                <?= sprintf('%02d:%02d', intdiv($totalSec, 60), $totalSec % 60) ?>
                            </span>
                        </div>
                        <?php if ($late): ?>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-red-600">Late</p>
                        <?php else: ?>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Wait</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Items List -->
            <div class="flex-1 p-5">
                <ul class="space-y-4">
                    <?php foreach ($o['items'] as $item): ?>
                        <li class="flex gap-4">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 border border-slate-200 text-sm font-black text-slate-700">
                                <?= (int) $item['current_stock'] ?>
                            </div>
                            <div class="min-w-0 flex-1 pt-1">
                                <p class="text-base font-bold text-slate-900 leading-tight"><?= e($item['item_name']) ?></p>
                                
                                <?php if ($item['special_instructions']): ?>
                                    <p class="mt-1 text-sm font-medium text-amber-700 bg-amber-50 rounded px-2 py-1 inline-block border border-amber-100">
                                        <?= e($item['special_instructions']) ?>
                                    </p>
                                <?php endif; ?>

                                <?php if ($item['menu_item_id']):
                                    $av = get_menu_availability($pdo, (int) $item['menu_item_id'], max(1, (int) $item['current_stock']));
                                    if ($av['has_recipe'] && !$av['available']): ?>
                                    <p class="mt-1.5 text-xs font-semibold text-red-600 flex items-center gap-1">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        Short: <?= e(implode(', ', $av['missing'])) ?>
                                    </p>
                                <?php endif; endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Action Footer -->
            <div class="p-4 bg-slate-50 border-t border-slate-100">
                <form method="POST" action="index?page=orders" class="w-full">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_ready">
                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition-colors hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                        Order Up
                    </button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php
/*
<?php if (can('users')): ?>
<details class="rounded-xl border border-slate-200 bg-white mt-8 shadow-sm overflow-hidden group">
    <summary class="flex cursor-pointer items-center justify-between bg-slate-50 px-6 py-4 font-bold text-slate-800 transition-colors hover:bg-slate-100 group-open:border-b group-open:border-slate-100">
        Simulate POS Order Entry (Shared DB)
        <span class="text-slate-400 group-open:rotate-180 transition-transform">▼</span>
    </summary>
    <form method="POST" action="index?page=orders" class="p-6">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_order">
        
        <p class="text-sm text-slate-500 mb-6">Simulates an external POS inserting a complete multi-item ticket directly into the shared database.</p>

        <!-- Shared Ticket Details -->
        <div class="grid gap-4 sm:grid-cols-3 mb-6 bg-slate-50/50 p-5 rounded-lg border border-slate-100">
            <label class="block text-sm font-semibold text-slate-700">Order Type
                <select name="order_type" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="Dine-In">Dine-In</option>
                    <option value="Room Service">Room Service</option>
                    <option value="Takeout">Takeout</option>
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Table or Room Number
                <input type="text" name="table_number" maxlength="20" placeholder="e.g. T-12 or Room 302" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            </label>
            <label class="block text-sm font-semibold text-slate-700">Guest Name
                <input type="text" name="customer_name" required maxlength="100" value="Guest" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            </label>
        </div>

        <!-- Dynamic Menu Items -->
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-bold text-slate-800">Ticket Items</h3>
            <button type="button" onclick="addPosItem()" class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 focus:outline-none flex items-center gap-1">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add another item
            </button>
        </div>

        <div id="posItemsList" class="space-y-3">
            <div class="pos-item-row flex flex-wrap items-end gap-3 rounded-lg border border-slate-100 bg-white p-3 shadow-sm transition-all">
                <label class="flex-1 min-w-[200px] block text-sm font-semibold text-slate-700">Menu item
                    <select name="menu_item_id[]" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">-- Select --</option>
                        <?php foreach ($menu as $m): $ok = $menuAvail[(int) $m['id']]; ?>
                            <option value="<?= (int) $m['id'] ?>" <?= $ok ? '' : 'disabled' ?>><?= e($m['name']) ?><?= $ok ? '' : ' (Out of stock)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="w-24 block text-sm font-semibold text-slate-700">Qty
                    <input type="number" name="current_stock[]" value="1" min="1" max="50" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 tabular-nums">
                </label>
                <label class="flex-1 min-w-[200px] block text-sm font-semibold text-slate-700">Instructions
                    <input type="text" name="special_instructions[]" maxlength="255" placeholder="e.g. Extra spicy, no onions" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                </label>
                <button type="button" onclick="removePosItem(this)" class="mb-1 rounded p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors focus:outline-none" title="Remove item">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                </button>
            </div>
        </div>
        
        <div class="mt-6 border-t border-slate-100 pt-5 text-right">
            <button type="submit" class="rounded-lg bg-emerald-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Push Ticket to Kitchen</button>
        </div>
    </form>
</details>
<?php endif; 
*/?>

<script>
    // Live timer update script
    setInterval(function() {
        document.querySelectorAll('[data-timer]').forEach(function(el) {
            let sec = parseInt(el.getAttribute('data-timer'), 10) + 1;
            el.setAttribute('data-timer', sec);

            let mins = Math.floor(sec / 60);
            let remSec = sec % 60;
            
            let formattedMins = String(mins).padStart(2, '0');
            let formattedSecs = String(remSec).padStart(2, '0');

            el.textContent = `${formattedMins}:${formattedSecs}`;
        });
    }, 1000);

    function addPosItem() {
        const list = document.getElementById('posItemsList');
        const firstRow = list.querySelector('.pos-item-row');
        if (!firstRow) return;
        
        const newRow = firstRow.cloneNode(true);
        
        // Reset inputs in the cloned row
        newRow.querySelectorAll('input').forEach(input => {
            if(input.type === 'number') input.value = '1';
            else input.value = '';
        });
        newRow.querySelector('select').selectedIndex = 0;
        
        list.appendChild(newRow);
    }

    function removePosItem(btn) {
        const list = document.getElementById('posItemsList');
        if (list.querySelectorAll('.pos-item-row').length > 1) {
            btn.closest('.pos-item-row').remove();
        } else {
            alert('A ticket must have at least one item.');
        }
    }

    // Live timer update script (keeps counting without page refresh)
    setInterval(function() {
        document.querySelectorAll('[data-timer]').forEach(function(el) {
            let sec = parseInt(el.getAttribute('data-timer'), 10) + 1;
            el.setAttribute('data-timer', sec);

            let mins = Math.floor(sec / 60);
            let remSec = sec % 60;
            
            let formattedMins = String(mins).padStart(2, '0');
            let formattedSecs = String(remSec).padStart(2, '0');

            el.textContent = `${formattedMins}:${formattedSecs}`;
        });
    }, 1000);
</script>