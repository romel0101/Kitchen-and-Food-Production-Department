<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
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
    redirect('index.php?page=orders');
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

<div class="grid gap-6">
    <section class="rounded-lg border-t-4 bg-slate-200/70 p-3 border-amber-500">
        <div class="mb-3 flex items-center justify-between px-1">
            <h2 class="text-lg font-extrabold uppercase tracking-wide">Active Orders</h2>
            <span class="rounded-full bg-slate-900 px-3 py-1 text-sm font-bold text-white"><?= count($activeOrders) ?></span>
        </div>

        <div class="space-y-4">
            <?php 
            foreach ($activeOrders as $o):
                $totalSec = max(0, (int)($o['wait_sec'] ?? 0));
                $late     = $totalSec >= (WAIT_LIMIT_MIN['preparing'] ?? 15) * 60;

                $articleClasses = $late ? 'rounded-md border-2 p-4 shadow-sm border-red-500 bg-red-50 ring-4 ring-red-200' : 'rounded-lg border bg-white p-4 shadow-sm';
                $timerClasses   = $late ? 'rounded-md px-3 py-1.5 text-2xl font-black tabular-nums bg-red-600 text-white' : 'rounded-md px-3 py-1.5 text-2xl font-black tabular-nums bg-emerald-700 text-white';
                
                $locationStr = $o['table_number'] ? 'Table ' . e($o['table_number']) . ' (' . e($o['order_type']) . ')' : e($o['order_type']);
            ?>
                <article class="<?= $articleClasses ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-2xl font-black leading-none tracking-tight" title="Order Number">
                                <?= e($o['order_number']) ?>
                            </p>
                            <p class="mt-1 font-normal text-slate-500"><?= $locationStr ?> · Guest: <?= e($o['customer_name']) ?></p>
                        </div>
                        
                        <div class="shrink-0 text-right">
                            <p class="<?= $timerClasses ?>">
                                <span data-timer="<?= (int) $totalSec ?>">
                                    <?= sprintf('%02d:%02d', intdiv($totalSec, 60), $totalSec % 60) ?>
                                </span>
                            </p>
                            <p class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">Waiting</p>
                        </div>
                    </div>

                    <?php if ($late): ?>
                        <div class="mt-2 flex gap-2">
                            <?= badge('Waiting too long', 'amber') ?>
                        </div>
                    <?php endif; ?>

                    <ul class="mt-4 divide-y divide-slate-200/80 border-y border-slate-200/80">
                        <?php foreach ($o['items'] as $item): ?>
                            <li class="flex gap-3 py-2.5">
                                <span class="flex h-9 min-w-[2.25rem] shrink-0 items-center justify-center rounded-md bg-slate-900 px-2 text-lg font-black text-white"><?= (int) $item['current_stock'] ?><span class="relative top-[0.15rem] text-[0.75rem]">x</span></span>
                                <div class="min-w-0">
                                    <p class="text-lg font-bold leading-snug"><?= e($item['item_name']) ?></p>
                                    <?php if ($item['menu_item_id']):
                                        $av = get_menu_availability($pdo, (int) $item['menu_item_id'], max(1, (int) $item['current_stock']));
                                        if ($av['has_recipe'] && !$av['available']): ?>
                                        <p class="mt-0.5 text-sm font-semibold text-red-700">Stock short: <?= e(implode(', ', $av['missing'])) ?></p>
                                    <?php endif; endif; ?>
                                    <?php if ($item['special_instructions']): ?>
                                        <p class="mt-0.5 text-base font-semibold italic text-amber-800">⚠ <?= e($item['special_instructions']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="mt-2 text-xs text-slate-500">
                        Ordered at <?= e(date('H:i', strtotime($o['created_at']))) ?>
                        <?php if ($o['pos_order_id']): ?> · POS Ref: <?= e($o['pos_order_id']) ?><?php endif; ?>
                    </p>

                    <div class="mt-4 flex items-center gap-3">
                        <form method="POST" action="index.php?page=orders" class="flex-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_ready">
                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                            <button class="flex min-h-[3.5rem] w-full items-center justify-center rounded-md px-4 text-lg font-extrabold shadow-sm transition focus:outline-none focus-visible:ring-4 disabled:cursor-wait disabled:opacity-60 bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-300">
                                <span>Mark Ready</span>
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (!$activeOrders): ?>
                <div class="rounded bg-white p-8 text-center text-slate-500 shadow-sm">No active orders in the kitchen queue.</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if (can('users')): ?>
<details class="rounded-lg border border-slate-200 bg-white mt-8 shadow-sm">
    <summary class="cursor-pointer px-4 py-3 font-semibold text-slate-800">Simulate POS Order Entry (Shared DB)</summary>
    <form method="POST" action="index.php?page=orders" class="p-4 border-t border-slate-200">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_order">
        
        <p class="text-sm text-slate-500 mb-6">Simulates an external POS inserting a complete multi-item ticket directly into the shared database.</p>

        <!-- Shared Ticket Details -->
        <div class="grid gap-4 sm:grid-cols-3 mb-6 bg-slate-50 p-4 rounded-md border border-slate-200">
            <label class="block text-sm font-medium">Order Type
                <select name="order_type" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                    <option value="Dine-In">Dine-In</option>
                    <option value="Room Service">Room Service</option>
                    <option value="Takeout">Takeout</option>
                </select>
            </label>
            <label class="block text-sm font-medium">Table or Room Number
                <input type="text" name="table_number" maxlength="20" placeholder="e.g. T-12 or Room 302" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-medium">Guest Name
                <input type="text" name="customer_name" required maxlength="100" value="Guest" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
        </div>

        <!-- Dynamic Menu Items -->
        <div class="mb-3 flex items-center justify-between">
            <h3 class="font-semibold text-slate-800">Ticket Items</h3>
            <button type="button" onclick="addPosItem()" class="text-sm font-semibold text-orange-600 hover:text-orange-700 focus:outline-none">+ Add another item</button>
        </div>

        <div id="posItemsList" class="space-y-3">
            <div class="pos-item-row flex flex-wrap items-start gap-3 rounded-md bg-white p-1">
                <label class="flex-1 min-w-[200px] block text-sm font-medium">Menu item
                    <select name="menu_item_id[]" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                        <option value="">-- Select --</option>
                        <?php foreach ($menu as $m): $ok = $menuAvail[(int) $m['id']]; ?>
                            <option value="<?= (int) $m['id'] ?>" <?= $ok ? '' : 'disabled' ?>><?= e($m['name']) ?><?= $ok ? '' : ' (Out of stock)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="w-24 block text-sm font-medium">Qty
                    <input type="number" name="current_stock[]" value="1" min="1" max="50" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="flex-1 min-w-[200px] block text-sm font-medium">Instructions
                    <input type="text" name="special_instructions[]" maxlength="255" placeholder="e.g. Extra spicy, no onions" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                </label>
                <button type="button" onclick="removePosItem(this)" class="mt-7 text-slate-400 hover:text-red-600 transition-colors focus:outline-none" title="Remove item">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                </button>
            </div>
        </div>
        
        <div class="mt-6 border-t border-slate-200 pt-4">
            <button class="rounded bg-orange-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-orange-700 transition-colors shadow-sm">Push Ticket to Kitchen</button>
        </div>
    </form>
</details>
<?php endif; ?>

<script>
    function addPosItem() {
        const list = document.getElementById('posItemsList');
        const firstRow = list.querySelector('.pos-item-row');
        if (!firstRow) return;
        
        const newRow = firstRow.cloneNode(true);
        
        // Reset inputs
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