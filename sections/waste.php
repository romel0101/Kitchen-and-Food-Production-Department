<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('waste');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $ing    = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
    $qty    = filter_var($_POST['current_stock'] ?? null, FILTER_VALIDATE_FLOAT);
    $reason = $_POST['reason'] ?? '';
    $notes  = trim($_POST['notes'] ?? '') ?: null;

    if (!$ing || $qty === false || $qty <= 0 || !array_key_exists($reason, WASTE_REASONS)) {
        flash('error', 'Choose an ingredient, a reason, and a quantity above 0.');
    } else {
        run_tx($pdo, function (PDO $pdo) use ($ing, $qty, $reason, $notes, $userId) {
            $qty = round($qty, 3);
            $s = $pdo->prepare('SELECT name, unit, current_stock FROM ingredients WHERE id = :id FOR UPDATE');
            $s->execute([':id' => $ing]);
            $row = $s->fetch();
            if (!$row) throw new RuntimeException('That ingredient was not found.');
            if ($qty > (float) $row['current_stock']) {
                throw new RuntimeException('Only ' . num($row['current_stock']) . ' ' . $row['unit'] . ' of ' . $row['name'] . ' is recorded in stock, so you cannot log more waste than that.');
            }
            deduct_stock($pdo, $ing, $qty);
            log_stock_movement($pdo, $ing, null, 'waste', -$qty, (WASTE_REASONS[$reason] ?? $reason) . ($notes ? ': ' . $notes : ''), $userId);
            $pdo->prepare('INSERT INTO waste_logs (ingredient_id, current_stock, reason, notes, logged_by) VALUES (:i, :q, :r, :n, :u)')
                ->execute([':i' => $ing, ':q' => $qty, ':r' => $reason, ':n' => $notes, ':u' => $userId]);
            log_activity('waste', "Logged waste: {$qty} {$row['unit']} of {$row['name']} (" . (WASTE_REASONS[$reason] ?? $reason) . ($notes ? ": $notes" : '') . ")");
            return 'Waste logged and removed from stock.';
        });
    }
    redirect('index.php?page=waste');
}

$ingredients = $pdo->query('SELECT id, name, unit FROM ingredients ORDER BY name')->fetchAll();
$recent = $pdo->query('SELECT w.logged_at, w.current_stock, w.reason, w.notes, i.name, i.unit, u.username
                       FROM waste_logs w JOIN ingredients i ON i.id = w.ingredient_id JOIN users u ON u.id = w.logged_by
                       ORDER BY w.logged_at DESC, w.id DESC LIMIT 25')->fetchAll();
$today = (int) $pdo->query('SELECT COUNT(*) FROM waste_logs WHERE logged_at >= CURDATE()')->fetchColumn();

$pageTitle = 'Food waste';
?>

<div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">Log waste</h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <label class="block text-sm font-medium">Ingredient
                <select name="ingredient_id" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                    <?php foreach ($ingredients as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach; ?>
                </select>
            </label>
            <label class="block text-sm font-medium">Quantity wasted
                <input type="number" name="current_stock" step="0.001" min="0.001" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-medium">Reason
                <select name="reason" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                    <?php foreach (WASTE_REASONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="block text-sm font-medium">Details <span class="font-normal text-slate-500">(optional)</span>
                <input type="text" name="notes" maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
            <button class="w-full rounded bg-orange-600 px-4 py-2 font-semibold text-white hover:bg-orange-700">Log waste</button>
        </form>
    </section>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white lg:col-span-2">
        <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Recent waste <span class="font-normal text-slate-500">(<?= $today ?> logged today)</span></h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500"><tr><th class="px-4 py-2 font-medium">When</th><th class="font-medium">Ingredient</th><th class="font-medium">Quantity</th><th class="font-medium">Reason</th><th class="font-medium">Details</th><th class="font-medium">By</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap"><?= e(date('j M, H:i', strtotime($r['logged_at']))) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td class="tabular-nums"><?= e(num($r['current_stock'])) ?> <?= e($r['unit']) ?></td>
                        <td><?= badge(WASTE_REASONS[$r['reason']] ?? $r['reason'], $r['reason'] === 'expired' ? 'red' : 'gray') ?></td>
                        <td class="max-w-xs text-slate-600"><?= e($r['notes'] ?? '') ?></td>
                        <td><?= e($r['username']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?><tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No waste has been logged yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
