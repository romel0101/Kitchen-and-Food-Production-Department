<?php
// sections/receiving.php  -  Batch-based receiving for ONE ingredient.
//
// Rules this page follows:
//   * Every delivery becomes a batch (stock_receipts row). Supplier, invoice and expiry are recorded on the batch.
//   * ingredients.current_stock is always changed by the same amount as the batch change, in the same
//     transaction, so "On Hand" is correct the moment the page reloads.
//   * Every change (receive, waste, batch edit, kitchen use, write-off) writes a stock_movements row.
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('receiving');

$ingredientId = filter_var($_GET['ingredient'] ?? null, FILTER_VALIDATE_INT);
if (!$ingredientId) {
    flash('error', 'Please select an ingredient first.');
    redirect('index.php?page=inventory');
}

$stmt = $pdo->prepare('SELECT * FROM ingredients WHERE id = :id');
$stmt->execute([':id' => $ingredientId]);
$ingredient = $stmt->fetch();
if (!$ingredient) {
    flash('error', 'Ingredient not found.');
    redirect('index.php?page=inventory');
}
$unit    = (string) $ingredient['unit'];
$baseUrl = 'index.php?page=receiving&ingredient=' . $ingredientId;

// =====================================================================================================
// POST handling: add_batch | log_waste | edit_batch
// Each handler runs in ONE transaction: batch row, ingredient total and ledger row all save together,
// or none of them do. Rows are locked with FOR UPDATE so two people cannot overwrite each other.
// =====================================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $formAction = $_POST['form_action'] ?? '';
    $goBack     = $baseUrl;
    $handled    = false;

    // ---- 1. ADD BATCH --------------------------------------------------------------------------------------
    if ($formAction === 'add_batch') {
        $handled = true;
        $qty     = filter_var($_POST['received_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $costRaw = trim((string) ($_POST['cost_per_unit'] ?? ''));
        $cost    = $costRaw === '' ? 0.0 : filter_var($costRaw, FILTER_VALIDATE_FLOAT);
        $expRaw  = trim((string) ($_POST['expiry_date'] ?? ''));
        $expiry  = valid_date($expRaw);
        $invoice = trim((string) ($_POST['invoice_number'] ?? '')) ?: null;
        $supplier = trim((string) ($_POST['supplier'] ?? '')) ?: null;
        $notes   = trim((string) ($_POST['notes'] ?? '')) ?: null;

        run_tx($pdo, function (PDO $pdo) use ($ingredientId, $ingredient, $qty, $cost, $expRaw, $expiry, $invoice, $supplier, $notes, $userId, $unit) {
            if ($qty === false || $qty <= 0)            throw new RuntimeException('Enter a received quantity above 0.');
            if ($cost === false || $cost < 0)           throw new RuntimeException('Cost per unit must be 0 or more.');
            if ($expRaw !== '' && $expiry === null)     throw new RuntimeException('That expiry date is not valid.');
            if ($expiry !== null && $expiry < date('Y-m-d')) throw new RuntimeException('That expiry date has already passed. Check the date on the delivery.');
            if (mb_strlen((string) $supplier) > 100)    throw new RuntimeException('Supplier is too long (100 characters max).');
            $qty = round($qty, 3);

            $pdo->prepare('INSERT INTO stock_receipts (ingredient_id, current_stock, quantity_remaining, cost_per_unit, expiry_date, invoice_number, supplier, notes, received_by)
                           VALUES (:i, :q, :r, :c, :e, :inv, :s, :n, :u)')
                ->execute([':i' => $ingredientId, ':q' => $qty, ':r' => $qty, ':c' => $cost, ':e' => $expiry, ':inv' => $invoice, ':s' => $supplier, ':n' => $notes, ':u' => $userId]);
            $batchId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE ingredients SET current_stock = current_stock + :q WHERE id = :id')
                ->execute([':q' => $qty, ':id' => $ingredientId]);

            log_stock_movement($pdo, $ingredientId, $batchId, 'receive', $qty,
                'New batch #' . $batchId . ($invoice ? ' | Invoice ' . $invoice : '') . ($expiry ? ' | Expires ' . $expiry : ''), $userId);
            log_activity('receive', "Received batch #$batchId: +{$qty} {$unit} of {$ingredient['name']}" . ($invoice ? " (Invoice: $invoice)" : '') . ($expiry ? " (Expires: $expiry)" : ''));
            return 'Batch #' . $batchId . ' received: +' . num($qty) . ' added to stock.';
        });

    // ---- 2. LOG WASTE against one specific batch --------------------------------------------------------
    } elseif ($formAction === 'log_waste') {
        $handled = true;
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $qty     = filter_var($_POST['waste_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $reason  = (string) ($_POST['reason'] ?? '');
        $notes   = trim((string) ($_POST['waste_notes'] ?? '')) ?: null;

        run_tx($pdo, function (PDO $pdo) use ($ingredientId, $ingredient, $batchId, $qty, $reason, $notes, $userId, $unit) {
            if (!$batchId)                                   throw new RuntimeException('Choose which batch the waste came from.');
            if ($qty === false || $qty <= 0)                 throw new RuntimeException('Enter a waste quantity above 0.');
            if (!array_key_exists($reason, WASTE_REASONS))   throw new RuntimeException('Choose a waste reason.');
            $qty = round($qty, 3);

            $b = $pdo->prepare('SELECT id, quantity_remaining FROM stock_receipts WHERE id = :id AND ingredient_id = :ing FOR UPDATE');
            $b->execute([':id' => $batchId, ':ing' => $ingredientId]);
            $batch = $b->fetch();
            if (!$batch) throw new RuntimeException('That batch does not belong to this ingredient.');
            $left = (float) $batch['quantity_remaining'];
            if ($qty > $left + 0.0005) {
                throw new RuntimeException('Batch #' . $batchId . ' only has ' . num($left) . ' ' . $unit . ' left, so you cannot waste ' . num($qty) . '.');
            }
            $qty = min($qty, $left);

            $g = $pdo->prepare('SELECT current_stock FROM ingredients WHERE id = :id FOR UPDATE');
            $g->execute([':id' => $ingredientId]);
            $oldTotal = (float) $g->fetchColumn();
            $newTotal = max(0.0, round($oldTotal - $qty, 3));

            $pdo->prepare('UPDATE stock_receipts SET quantity_remaining = :r WHERE id = :id')
                ->execute([':r' => round($left - $qty, 3), ':id' => $batchId]);
            $pdo->prepare('UPDATE ingredients SET current_stock = :s WHERE id = :id')
                ->execute([':s' => $newTotal, ':id' => $ingredientId]);
            $pdo->prepare('INSERT INTO waste_logs (ingredient_id, batch_id, current_stock, reason, notes, logged_by) VALUES (:i, :b, :q, :r, :n, :u)')
                ->execute([':i' => $ingredientId, ':b' => $batchId, ':q' => $qty, ':r' => $reason, ':n' => $notes, ':u' => $userId]);

            log_stock_movement($pdo, $ingredientId, $batchId, 'waste', $newTotal - $oldTotal,
                WASTE_REASONS[$reason] . ' | batch #' . $batchId . ' ' . num($left) . ' → ' . num($left - $qty) . ($notes ? ' | ' . $notes : ''), $userId);
            log_activity('waste', "Waste from batch #$batchId: -{$qty} {$unit} of {$ingredient['name']} (" . WASTE_REASONS[$reason] . ($notes ? ": $notes" : '') . ")");
            return 'Waste logged: -' . num($qty) . ' ' . $unit . ' removed from batch #' . $batchId . '.';
        });

    // ---- 3. EDIT BATCH (fix a mistake; the ingredient total follows any quantity change) -----------------
    } elseif ($formAction === 'edit_batch') {
        $handled = true;
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $newRecv = filter_var($_POST['received_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $expRaw  = trim((string) ($_POST['expiry_date'] ?? ''));
        $expiry  = valid_date($expRaw);
        $supplier = trim((string) ($_POST['supplier'] ?? '')) ?: null;
        $invoice  = trim((string) ($_POST['invoice_number'] ?? '')) ?: null;
        $costRaw  = trim((string) ($_POST['cost_per_unit'] ?? ''));
        $cost     = $costRaw === '' ? 0.0 : filter_var($costRaw, FILTER_VALIDATE_FLOAT);
        $goBack   = $baseUrl . ($batchId ? '&edit_batch=' . $batchId : '');   // stay on the form if it fails

        run_tx($pdo, function (PDO $pdo) use ($ingredientId, $ingredient, $batchId, $newRecv, $expRaw, $expiry, $supplier, $invoice, $cost, $userId, $unit) {
            if (!$batchId)                              throw new RuntimeException('Batch not found.');
            if ($newRecv === false || $newRecv <= 0)    throw new RuntimeException('Received quantity must be above 0.');
            if ($cost === false || $cost < 0)           throw new RuntimeException('Cost per unit must be 0 or more.');
            if ($expRaw !== '' && $expiry === null)     throw new RuntimeException('That expiry date is not valid.');
            if (mb_strlen((string) $supplier) > 100)    throw new RuntimeException('Supplier is too long (100 characters max).');
            $newRecv = round($newRecv, 3);

            $b = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = :id AND ingredient_id = :ing FOR UPDATE');
            $b->execute([':id' => $batchId, ':ing' => $ingredientId]);
            $old = $b->fetch();
            if (!$old) throw new RuntimeException('That batch does not belong to this ingredient.');

            $oldRecv  = (float) $old['current_stock'];          // quantity originally received
            $oldLeft  = (float) $old['quantity_remaining'];
            $consumed = round($oldRecv - $oldLeft, 3);          // already cooked with / wasted, cannot be undone
            $newLeft  = round($newRecv - $consumed, 3);
            if ($newLeft < 0) {
                throw new RuntimeException('Batch #' . $batchId . ' has already had ' . num($consumed) . ' ' . $unit . ' used or wasted, so the received quantity cannot be lower than that.');
            }
            $delta = round($newRecv - $oldRecv, 3);             // this is exactly how much the ingredient total must move

            $g = $pdo->prepare('SELECT current_stock FROM ingredients WHERE id = :id FOR UPDATE');
            $g->execute([':id' => $ingredientId]);
            $oldTotal = (float) $g->fetchColumn();
            $newTotal = round($oldTotal + $delta, 3);
            if ($newTotal < 0) {
                throw new RuntimeException('That change would take total stock below 0. Check the other batches and recorded stock first.');
            }

            // Describe what actually changed, for the ledger.
            $changes = [];
            if ($delta != 0.0)                                        $changes[] = 'Received ' . num($oldRecv) . ' → ' . num($newRecv);
            if ((string) $old['expiry_date'] !== (string) $expiry)    $changes[] = 'Expiry ' . ($old['expiry_date'] ?: 'none') . ' → ' . ($expiry ?: 'none');
            if ((string) $old['supplier'] !== (string) $supplier)     $changes[] = 'Supplier "' . ($old['supplier'] ?? '') . '" → "' . ($supplier ?? '') . '"';
            if ((string) $old['invoice_number'] !== (string) $invoice) $changes[] = 'Invoice "' . ($old['invoice_number'] ?? '') . '" → "' . ($invoice ?? '') . '"';
            if (abs((float) $old['cost_per_unit'] - $cost) > 0.00499) $changes[] = 'Cost ' . number_format((float) $old['cost_per_unit'], 2) . ' → ' . number_format($cost, 2);
            if (!$changes) throw new RuntimeException('Nothing was changed.');

            $pdo->prepare('UPDATE stock_receipts SET current_stock = :recv, quantity_remaining = :left, expiry_date = :e,
                                  supplier = :s, invoice_number = :inv, cost_per_unit = :c WHERE id = :id')
                ->execute([':recv' => $newRecv, ':left' => $newLeft, ':e' => $expiry, ':s' => $supplier, ':inv' => $invoice, ':c' => $cost, ':id' => $batchId]);
            if ($delta != 0.0) {
                $pdo->prepare('UPDATE ingredients SET current_stock = :s WHERE id = :id')
                    ->execute([':s' => $newTotal, ':id' => $ingredientId]);
            }
            log_stock_movement($pdo, $ingredientId, $batchId, 'batch_edit', $delta,
                'Batch #' . $batchId . ': ' . implode('; ', $changes) . ($delta != 0.0 ? ' | batch left ' . num($oldLeft) . ' → ' . num($newLeft) : ''), $userId);
            log_activity('batch_edit', "Edited batch #$batchId of {$ingredient['name']}: " . implode('; ', $changes) . ($delta != 0.0 ? " (stock change: " . ($delta > 0 ? '+' : '') . num($delta) . " {$unit})" : ''));
            return 'Batch #' . $batchId . ' updated' . ($delta != 0.0 ? ' and total stock adjusted by ' . ($delta > 0 ? '+' : '') . num($delta) . ' ' . $unit : '') . '.';
        });
        if (($_SESSION['flash']['type'] ?? '') === 'success') $goBack = $baseUrl;

    // ---- 4. DELETE BATCH (only if nothing has been consumed from it) -----------------------------------
    } elseif ($formAction === 'delete_batch') {
        $handled = true;
        // Password verification for AJAX modal
        if (isset($_POST['verify_password'])) {
            check_csrf();
            $pwd = (string) ($_POST['verify_password'] ?? '');
            $u = $pdo->prepare('SELECT password FROM users WHERE id = :id');
            $u->execute([':id' => $userId]);
            $hash = $u->fetchColumn();
            if (!$hash || !password_verify($pwd, $hash)) {
                flash('error', 'Incorrect password. Deletion cancelled.');
                redirect($baseUrl);
            }
        }

        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$batchId) {
            flash('error', 'Invalid batch ID.');
            redirect($baseUrl);
        }

        run_tx($pdo, function (PDO $pdo) use ($ingredientId, $ingredient, $batchId, $userId, $unit) {
            $b = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = :id AND ingredient_id = :ing FOR UPDATE');
            $b->execute([':id' => $batchId, ':ing' => $ingredientId]);
            $batch = $b->fetch();
            if (!$batch) throw new RuntimeException('Batch not found.');

            $received = (float) $batch['current_stock'];
            $left     = (float) $batch['quantity_remaining'];
            $consumed = round($received - $left, 3);

            if ($consumed > 0.0005) {
                throw new RuntimeException('Batch #' . $batchId . ' has already had ' . num($consumed) . ' ' . $unit . ' used or wasted and cannot be deleted. Use the Edit button to correct it instead.');
            }

            // Nothing consumed: safe to delete. Reverse the stock addition.
            $g = $pdo->prepare('SELECT current_stock FROM ingredients WHERE id = :id FOR UPDATE');
            $g->execute([':id' => $ingredientId]);
            $oldTotal = (float) $g->fetchColumn();
            $newTotal = max(0.0, round($oldTotal - $received, 3));

            $pdo->prepare('DELETE FROM stock_receipts WHERE id = :id')->execute([':id' => $batchId]);
            $pdo->prepare('UPDATE ingredients SET current_stock = :s WHERE id = :id')
                ->execute([':s' => $newTotal, ':id' => $ingredientId]);

            log_stock_movement($pdo, $ingredientId, null, 'batch_edit', -$received,   // null: the batch row no longer exists
                'Batch #' . $batchId . ' deleted (no consumption recorded)', $userId);
            log_activity('batch_delete', "Deleted batch #$batchId of {$ingredient['name']} (received: {$received} {$unit})");
            return 'Batch #' . $batchId . ' deleted and total stock adjusted by -' . num($received) . ' ' . $unit . '.';
        });
    }
    if ($handled) {
        redirect($goBack ?? $baseUrl);
    }
}

// =====================================================================================================
// Page data (re-read after any POST, so On Hand is always the freshly saved value)
// =====================================================================================================
$b = $pdo->prepare('SELECT * FROM stock_receipts WHERE ingredient_id = :id AND quantity_remaining > 0 ORDER BY received_at ASC, id ASC');
$b->execute([':id' => $ingredientId]);
$stockBatches = $b->fetchAll();

$editBatch = null;
$editId = filter_var($_GET['edit_batch'] ?? null, FILTER_VALIDATE_INT);
if ($editId) {
    foreach ($stockBatches as $row) if ((int) $row['id'] === $editId) { $editBatch = $row; break; }
}

$m = $pdo->prepare('SELECT m.*, u.first_name, u.middle_name, u.last_name, u.suffix FROM stock_movements m LEFT JOIN users u ON u.id = m.created_by
                    WHERE m.ingredient_id = :id ORDER BY m.created_at DESC, m.id DESC LIMIT 25');
$m->execute([':id' => $ingredientId]);
$recentMovements = $m->fetchAll();

$batchTotal = 0.0;
foreach ($stockBatches as $row) $batchTotal += (float) $row['quantity_remaining'];
$unbatched = round((float) $ingredient['current_stock'] - $batchTotal, 3);   // stock that exists but is not in any batch

$isLow = (float) $ingredient['current_stock'] <= (float) $ingredient['reorder_level'];
$isOut = (float) $ingredient['current_stock'] <= 0;
$pageTitle = 'Receive stock: ' . $ingredient['name'];

$moveTypes = [
    'receive'    => ['Receiving',  'green'],
    'waste'      => ['Waste',      'red'],
    'batch_edit' => ['Batch edit', 'blue'],
    'usage'      => ['Kitchen use', 'gray'],
    'write_off'  => ['Write-off',  'red'],
];
$inputCls = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm';
$lblCls   = 'block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1';
?>

<div class="mb-6 flex items-center justify-between">
    <nav class="flex items-center gap-2 text-sm" aria-label="Breadcrumb"><a href="index.php?page=inventory" class="font-semibold text-slate-600 hover:underline">Inventory</a><span class="text-slate-400">&rsaquo;</span><span class="font-semibold text-slate-900"><?= e($ingredient['name']) ?></span></nav>
</div>

<!-- Metric cards -->
<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 mb-6">
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Current Stock</p>
        <p class="mt-1 text-2xl font-black text-slate-900"><?= e(num($ingredient['current_stock'])) ?> <span class="text-sm font-normal text-slate-500"><?= e($unit) ?></span></p>
        <?php if ($isOut): ?>
            <span class="mt-2 inline-block rounded bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">OUT OF STOCK</span>
        <?php elseif ($isLow): ?>
            <span class="mt-2 inline-block rounded bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">LOW STOCK</span>
        <?php else: ?>
            <span class="mt-2 inline-block rounded bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">OK</span>
        <?php endif; ?>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Reorder At</p>
        <p class="mt-1 text-2xl font-black text-slate-900"><?= e(num($ingredient['reorder_level'])) ?> <span class="text-sm font-normal text-slate-500"><?= e($unit) ?></span></p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Details</p>
        <p class="mt-1 text-sm font-semibold text-slate-800"><?= e($ingredient['storage_location'] ?? 'General Storage') ?></p>
        <p class="text-xs text-slate-500">Warn before expiry: <?= (int) $ingredient['expiry_alert_days'] ?> days</p>
    </div>
</div>

<?php if (abs($unbatched) > 0.0005): ?>
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Current Stock is <strong><?= e(num($ingredient['current_stock'])) ?></strong> but the batches below add up to
        <strong><?= e(num($batchTotal)) ?></strong> <?= e($unit) ?>. The <?= e(num(abs($unbatched))) ?> <?= $unbatched > 0 ? 'extra is stock that was never entered as a batch (for example opening stock)' : 'shortfall means batches hold more than the total records' ?>.
        It is left untouched: receiving, waste and batch edits only ever change stock by the amount you enter.
    </div>
<?php endif; ?>

<?php if ($editBatch): ?>
<!-- Edit Batch Modal -->
<?php $consumed = round((float) $editBatch['current_stock'] - (float) $editBatch['quantity_remaining'], 3); ?>
<div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm sm:p-6">
    <div class="w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-slate-200">
        
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4">
            <h2 class="font-bold uppercase tracking-wide text-sm text-blue-700">Edit batch #<?= (int) $editBatch['id'] ?></h2>
            <a href="<?= e($baseUrl) ?>" class="text-2xl leading-none text-slate-400 hover:text-slate-700">&times;</a>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <p class="mb-6 text-xs text-slate-500">
                <?= e(num($editBatch['quantity_remaining'])) ?> <?= e($unit) ?> left of <?= e(num($editBatch['current_stock'])) ?> received<?= $consumed > 0 ? '; ' . e(num($consumed)) . ' already used or wasted, so the received quantity cannot go below ' . e(num($consumed)) : '' ?>.
                Changing the received quantity also changes Current Stock by the same amount.
            </p>
            <form method="POST" action="<?= e($baseUrl) ?>" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="edit_batch">
                <input type="hidden" name="batch_id" value="<?= (int) $editBatch['id'] ?>">
                
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label class="<?= $lblCls ?>">Received quantity (<?= e($unit) ?>)</label>
                        <input type="number" name="received_quantity" step="0.001" min="<?= $consumed > 0 ? e(num($consumed + 0.001)) : '0.001' ?>" required value="<?= e(num($editBatch['current_stock'])) ?>" class="<?= $inputCls ?>">
                    </div>
                    <div>
                        <label class="<?= $lblCls ?>">Expiry date</label>
                        <input type="date" name="expiry_date" value="<?= e($editBatch['expiry_date'] ?? '') ?>" class="<?= $inputCls ?>">
                    </div>
                    <div>
                        <label class="<?= $lblCls ?>">Supplier</label>
                        <input type="text" name="supplier" maxlength="100" value="<?= e($editBatch['supplier'] ?? '') ?>" placeholder="e.g. Fresh Farms Co." class="<?= $inputCls ?>">
                    </div>
                    <div>
                        <label class="<?= $lblCls ?>">Cost per <?= e($unit) ?></label>
                        <input type="number" name="cost_per_unit" step="0.01" min="0" value="<?= e(number_format((float) $editBatch['cost_per_unit'], 2, '.', '')) ?>" class="<?= $inputCls ?>">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="<?= $lblCls ?>">Invoice number</label>
                        <input type="text" name="invoice_number" maxlength="50" value="<?= e($editBatch['invoice_number'] ?? '') ?>" class="<?= $inputCls ?>">
                    </div>
                </div>
                
                <!-- Modal Footer -->
                <div class="mt-6 flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
                    <a href="<?= e($baseUrl) ?>" class="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-sm">Cancel</a>
                    <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700 shadow-sm">Save changes</button>
                </div>
            </form>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- Forms: Receive new batch | Log waste from a batch -->
<div class="grid gap-6 lg:grid-cols-2 mb-6">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-bold uppercase tracking-wide text-sm text-slate-900 border-b border-slate-100 pb-2">Receive New Batch</h2>
        <form method="POST" action="<?= e($baseUrl) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_batch">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="<?= $lblCls ?>">Received quantity (<?= e($unit) ?>)</label>
                    <input type="number" name="received_quantity" step="0.001" min="0.001" required class="<?= $inputCls ?>">
                </div>
                <div>
                    <label class="<?= $lblCls ?>">Cost per <?= e($unit) ?></label>
                    <input type="number" name="cost_per_unit" step="0.01" min="0" placeholder="0.00" class="<?= $inputCls ?>">
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="<?= $lblCls ?>">Expiry date</label>
                    <input type="date" name="expiry_date" min="<?= e(date('Y-m-d')) ?>" class="<?= $inputCls ?>">
                </div>
                <div>
                    <label class="<?= $lblCls ?>">Supplier</label>
                    <input type="text" name="supplier" maxlength="100" placeholder="Optional, e.g. Fresh Farms Co." class="<?= $inputCls ?>">
                </div>
            </div>
            <div>
                <label class="<?= $lblCls ?>">Invoice number</label>
                <input type="text" name="invoice_number" maxlength="50" placeholder="Optional" class="<?= $inputCls ?>">
            </div>
            <div>
                <label class="<?= $lblCls ?>">Notes</label>
                <textarea name="notes" rows="2" maxlength="255" placeholder="Optional delivery notes..." class="<?= $inputCls ?>"></textarea>
            </div>
            <button class="w-full rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition-colors shadow-sm">Add batch to stock</button>
        </form>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-bold uppercase tracking-wide text-sm text-red-600 border-b border-slate-100 pb-2">Log Waste</h2>
        <?php if (!$stockBatches): ?>
            <p class="py-8 text-center text-sm text-slate-500">There are no active batches to take waste from. Receive a batch first.</p>
        <?php else: ?>
        <form method="POST" action="<?= e($baseUrl) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="log_waste">
            <div>
                <label class="<?= $lblCls ?>">Batch</label>
                <select name="batch_id" required class="<?= $inputCls ?>">
                    <option value="">Select a batch…</option>
                    <?php foreach ($stockBatches as $sb): ?>
                        <option value="<?= (int) $sb['id'] ?>">
                            #<?= (int) $sb['id'] ?> · <?= e(num($sb['quantity_remaining'])) ?> <?= e($unit) ?> left
                            <?= $sb['expiry_date'] ? '· exp ' . e(date('j M Y', strtotime($sb['expiry_date']))) : '' ?>
                            <?= $sb['supplier'] ? '· ' . e($sb['supplier']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="<?= $lblCls ?>">Quantity (<?= e($unit) ?>)</label>
                    <input type="number" name="waste_quantity" step="0.001" min="0.001" required class="<?= $inputCls ?>">
                </div>
                <div>
                    <label class="<?= $lblCls ?>">Reason</label>
                    <select name="reason" required class="<?= $inputCls ?>">
                        <?php foreach (WASTE_REASONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="<?= $lblCls ?>">Notes</label>
                <textarea name="waste_notes" rows="2" maxlength="255" placeholder="Describe reason for waste..." class="<?= $inputCls ?>"></textarea>
            </div>
            <button class="w-full rounded-md bg-red-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-red-700 transition-colors shadow-sm">Write off from batch</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Batches in Stock -->
<div class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
    <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="font-bold text-slate-900 uppercase tracking-wide text-sm">Batches in Stock</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-6 py-3">Batch ID</th>
                    <th class="px-6 py-3">Left</th>
                    <th class="px-6 py-3">Received</th>
                    <th class="px-6 py-3">Expiry</th>
                    <th class="px-6 py-3">Supplier</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($stockBatches as $sb): ?>
                    <tr class="<?= $editBatch && (int) $editBatch['id'] === (int) $sb['id'] ? 'bg-blue-50/60' : '' ?>">
                        <td class="px-6 py-3 font-semibold text-slate-900">#<?= (int) $sb['id'] ?></td>
                        <td class="px-6 py-3 font-bold text-slate-900"><?= e(num($sb['quantity_remaining'])) ?> <span class="font-normal text-xs text-slate-500">of <?= e(num($sb['current_stock'])) ?> <?= e($unit) ?></span></td>
                        <td class="px-6 py-3 text-slate-600"><?= e(date('j M Y', strtotime($sb['received_at']))) ?></td>
                        <td class="px-6 py-3 text-slate-600"><?= $sb['expiry_date'] ? e(date('j M Y', strtotime($sb['expiry_date']))) : '—' ?></td>
                        <td class="px-6 py-3 text-slate-600"><?= e($sb['supplier'] ?? '—') ?></td>
                        <td class="px-6 py-3 text-right space-x-2">
                            <a href="<?= e($baseUrl . '&edit_batch=' . (int) $sb['id']) ?>#edit" class="rounded border border-blue-300 px-3 py-1 text-xs font-bold text-blue-700 hover:bg-blue-50">Edit</a>
                            <button type="button" class="rounded border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors shadow-sm" onclick="openDeleteModal('Batch #<?= (int) $sb['id'] ?> (<?= e(num($sb['quantity_remaining'])) ?> <?= e($unit) ?> left)', <?= (int) $sb['id'] ?>, 'batch')">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$stockBatches): ?>
                    <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No active stock batches recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Stock Movements -->
<div class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="border-b border-slate-200 px-6 py-4">
        <h2 class="font-bold text-slate-900 uppercase tracking-wide text-sm">Recent Stock Movements</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-bold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-6 py-3">When</th>
                    <th class="px-6 py-3">Action</th>
                    <th class="px-6 py-3">Batch</th>
                    <th class="px-6 py-3">Change (<?= e($unit) ?>)</th>
                    <th class="px-6 py-3">Stock after</th>
                    <th class="px-6 py-3">By</th>
                    <th class="px-6 py-3">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recentMovements as $mv):
                    $chg = (float) $mv['quantity_change'];
                    [$label, $tone] = $moveTypes[$mv['movement_type']] ?? [$mv['movement_type'], 'gray'];
                ?>
                    <tr>
                        <td class="px-6 py-3 text-slate-600 whitespace-nowrap"><?= e(date('j M, H:i', strtotime($mv['created_at']))) ?></td>
                        <td class="px-6 py-3"><?= badge($label, $tone) ?></td>
                        <td class="px-6 py-3 text-slate-600"><?= $mv['batch_id'] ? '#' . (int) $mv['batch_id'] : '—' ?></td>
                        <td class="px-6 py-3 font-bold tabular-nums <?= $chg > 0 ? 'text-emerald-600' : ($chg < 0 ? 'text-red-600' : 'text-slate-500') ?>">
                            <?= ($chg > 0 ? '+' : '') . e(num($chg)) ?>
                        </td>
                        <td class="px-6 py-3 tabular-nums text-slate-600"><?= $mv['stock_after'] !== null ? e(num($mv['stock_after'])) : '—' ?></td>
                        <td class="px-6 py-3 text-slate-600"><?php $fn = trim(implode(' ', array_filter([$mv['first_name'], $mv['middle_name'], $mv['last_name'], $mv['suffix']]))); echo e($fn ?: '—'); ?></td>
                        <td class="px-6 py-3 text-slate-500"><?= e($mv['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentMovements): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No movements recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Password confirmation modal for delete actions
let deleteModalTarget = null;
let deleteModalType = null;

function openDeleteModal(itemName, itemId, type = 'batch') {
    deleteModalTarget = itemId;
    deleteModalType = type;
    document.getElementById('deleteModalItemName').textContent = itemName;
    document.getElementById('deleteModalItemId').value = itemId;
    document.getElementById('deleteModalType').value = type;
    document.getElementById('deleteModalPassword').value = '';
    document.getElementById('deleteModalError').textContent = '';
    document.getElementById('deleteModalError').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
    setTimeout(() => document.getElementById('deleteModalPassword').focus(), 50);
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    deleteModalTarget = null;
    deleteModalType = null;
}

function submitDeleteModal() {
    const password = document.getElementById('deleteModalPassword').value;
    const itemId   = document.getElementById('deleteModalItemId').value;

    if (!password) {
        document.getElementById('deleteModalError').textContent = 'Please enter your password.';
        document.getElementById('deleteModalError').classList.remove('hidden');
        return;
    }
    const btn = document.getElementById('deleteModalConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Deleting...';

    // A normal form post: the page reloads and shows the success or "incorrect password" message.
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.pathname + window.location.search;
    [['csrf', document.querySelector('input[name="csrf"]').value], ['form_action', 'delete_batch'],
     ['batch_id', itemId], ['verify_password', password]].forEach(function (kv) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}

// Handle Enter key in password field
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('deleteModalPassword');
    if (passwordInput) {
        passwordInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitDeleteModal();
            }
        });
    }

    // Close modal on backdrop click
    document.getElementById('deleteModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeleteModal();
    });
});
</script>

<!-- Password Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-slate-200">
            <h3 class="text-lg font-semibold text-slate-900">Confirm Deletion</h3>
        </div>
        <div class="px-6 py-4 space-y-4">
            <p class="text-slate-700">Are you sure you want to delete <strong id="deleteModalItemName"></strong>?</p>
            <p class="text-xs text-slate-500">This action cannot be undone. Please enter your password to confirm.</p>
            <input type="password" id="deleteModalPassword" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" placeholder="Your password" autocomplete="current-password">
            <input type="hidden" id="deleteModalItemId">
            <input type="hidden" id="deleteModalType">
            <p id="deleteModalError" class="text-red-600 text-sm hidden"></p>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" id="deleteModalConfirmBtn" onclick="submitDeleteModal()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>
