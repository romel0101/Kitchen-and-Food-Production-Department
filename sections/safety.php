<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Open this page through index.php.'); }
require_perm('safety');

function temp_passes(string $subject, float $reading): bool {
    $r = TEMP_RULES[$subject];
    return (!isset($r['max']) || $reading <= $r['max']) && (!isset($r['min']) || $reading >= $r['min']);
}
function temp_rule_text(string $subject): string {
    $r = TEMP_RULES[$subject];
    return isset($r['max']) ? 'must be ' . num($r['max'], 1) . ' °C or colder' : 'must be ' . num($r['min'], 1) . ' °C or hotter';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $notes = trim($_POST['notes'] ?? '') ?: null;

    switch ($_POST['action'] ?? '') {
        case 'temperature':
            $subject = $_POST['subject'] ?? '';
            $reading = filter_var($_POST['reading'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!array_key_exists($subject, TEMP_RULES) || $reading === false || $reading < -60 || $reading > 300) {
                flash('error', 'Choose where you measured and enter a valid temperature in °C.');
                break;
            }
            $ok = temp_passes($subject, (float) $reading);
            if (!$ok && !$notes) {
                flash('error', 'That reading is outside the safe range. Describe the corrective action you took, then save again.');
                break;
            }
            $pdo->prepare("INSERT INTO safety_logs (log_type, subject, reading, passed, notes, logged_by) VALUES ('temperature', :s, :r, :p, :n, :u)")
                ->execute([':s' => $subject, ':r' => round($reading, 1), ':p' => $ok ? 1 : 0, ':n' => $notes, ':u' => $userId]);
            log_activity('temperature_check', "Logged temperature: {$subject} = " . round($reading, 1) . '°C' . ($ok ? ' (PASS)' : ' (FAIL)') . ($notes ? ": $notes" : ''));
            $ok ? flash('success', 'Temperature logged. It is within the safe range.')
                : flash('error', 'Logged as failed. Make sure the corrective action is carried out.');
            break;

        case 'sanitation':
            $results = is_array($_POST['check'] ?? null) ? $_POST['check'] : [];
            $rows = [];
            $complete = true;
            foreach (SANITATION_CHECKS as $idx => $label) {
                $v = $results[$idx] ?? '';
                if ($v !== 'pass' && $v !== 'fail') { $complete = false; break; }
                $rows[] = [$label, $v === 'pass' ? 1 : 0];
            }
            if (!$complete) { flash('error', 'Mark every checklist item as pass or fail.'); break; }
            $anyFail = count(array_filter($rows, fn($r) => $r[1] === 0)) > 0;
            if ($anyFail && !$notes) { flash('error', 'Something failed. Describe the corrective action, then save again.'); break; }

            $ins = $pdo->prepare("INSERT INTO safety_logs (log_type, subject, passed, notes, logged_by) VALUES ('sanitation', :s, :p, :n, :u)");
            foreach ($rows as [$label, $passed]) {
                $ins->execute([':s' => $label, ':p' => $passed, ':n' => $passed ? null : $notes, ':u' => $userId]);
            }
            $failedItems = array_filter($rows, fn($r) => $r[1] === 0);
            log_activity('sanitation_check', 'Sanitation checklist: ' . count($rows) . ' items, ' . count($failedItems) . ' failed' . ($anyFail ? " (FAIL)" : ' (PASS)') . ($notes ? ": $notes" : ''));
            $anyFail ? flash('error', 'Checklist logged with failed items. Make sure the corrective action is carried out.')
                     : flash('success', 'Sanitation checklist logged. Everything passed.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
    redirect('index.php?page=safety');
}

$recent = $pdo->query('SELECT s.logged_at, s.log_type, s.subject, s.reading, s.passed, s.notes, u.first_name, u.middle_name, u.last_name, u.suffix
                       FROM safety_logs s JOIN users u ON u.id = s.logged_by
                       ORDER BY s.logged_at DESC, s.id DESC LIMIT 30')->fetchAll();
$t = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(passed = 0), 0) AS failed FROM safety_logs WHERE logged_at >= CURDATE()')->fetch();

$pageTitle = 'Food safety logs';
?>

<div class="grid gap-6 lg:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">Temperature check</h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="temperature">
            <label class="block text-sm font-medium">Where did you measure?
                <select name="subject" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
                    <?php foreach (TEMP_RULES as $name => $rule): ?><option value="<?= e($name) ?>"><?= e($name) ?>, <?= e(temp_rule_text($name)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="block text-sm font-medium">Reading (°C)
                <input type="number" name="reading" step="0.1" min="-60" max="300" required class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-medium">Corrective action <span class="font-normal text-slate-500">(required if the reading is outside the safe range)</span>
                <input type="text" name="notes" maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
            <button class="rounded bg-orange-600 px-4 py-2 font-semibold text-white hover:bg-orange-700">Log temperature</button>
        </form>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">Sanitation checklist</h2>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="sanitation">
            <?php foreach (SANITATION_CHECKS as $idx => $label): ?>
                <fieldset class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 text-sm">
                    <legend class="sr-only"><?= e($label) ?></legend>
                    <span><?= e($label) ?></span>
                    <span class="flex gap-4">
                        <label class="flex items-center gap-1"><input type="radio" name="check[<?= $idx ?>]" value="pass" required> Pass</label>
                        <label class="flex items-center gap-1"><input type="radio" name="check[<?= $idx ?>]" value="fail"> Fail</label>
                    </span>
                </fieldset>
            <?php endforeach; ?>
            <label class="block text-sm font-medium">Corrective action <span class="font-normal text-slate-500">(required if anything failed)</span>
                <input type="text" name="notes" maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-normal">
            </label>
            <button class="rounded bg-slate-800 px-4 py-2 font-semibold text-white hover:bg-slate-900">Log checklist</button>
        </form>
    </section>
</div>

<section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">Recent checks</h2>
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500"><tr><th class="px-4 py-2 font-medium">When</th><th class="font-medium">Type</th><th class="font-medium">Check</th><th class="font-medium">Reading</th><th class="font-medium">Result</th><th class="font-medium">Corrective action</th><th class="font-medium">By</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="px-4 py-2 whitespace-nowrap"><?= e(date('j M, H:i', strtotime($r['logged_at']))) ?></td>
                    <td><?= $r['log_type'] === 'temperature' ? 'Temperature' : 'Sanitation' ?></td>
                    <td><?= e($r['subject']) ?></td>
                    <td class="tabular-nums"><?= $r['reading'] !== null ? e(num($r['reading'], 1)) . ' °C' : '' ?></td>
                    <td><?= $r['passed'] ? badge('Pass', 'green') : badge('Fail', 'red') ?></td>
                    <td class="max-w-xs text-slate-600"><?= e($r['notes'] ?? '') ?></td>
                    <td><?php $fn = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name'], $r['suffix']]))); echo e($fn ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No checks logged yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
