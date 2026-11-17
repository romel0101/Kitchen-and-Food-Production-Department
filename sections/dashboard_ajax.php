<?php
if (!defined('KAFPD_APP')) { define('ERROR_CODE', 403); require __DIR__ . '/../error.php'; exit; }
require_perm('inventory');                      // restock and usage figures are inventory data: chefs get a 403, not an empty chart
header('Content-Type: application/json');

$isHourly = false;
$now = date('Y-m-d H:i:s', strtotime('+1 hour'));
$today = date('Y-m-d', strtotime('+1 hour'));

if (isset($_GET['days'])) {
    $days = (int) $_GET['days'];
    if ($days === 1) {
        $isHourly = true;
        $from = date('Y-m-d H:i:s', strtotime('-24 hours', strtotime($now)));
        $to = $now;
    } else {
        $from = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days", strtotime($now)));
        $to = $now;
    }
} else {
    $from = valid_date($_GET['from'] ?? '') ?? date('Y-m-d', strtotime('-6 days', strtotime($now)));
    $to   = valid_date($_GET['to'] ?? '')   ?? $today;
    
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    
    // If From and To are the exact same date (e.g., 2026-11-12 to 2026-11-12), switch to hourly time mode!
    if ($from === $to) {
        $isHourly = true;
        $from .= ' 00:00:00';
        $to = ($to === substr($now, 0, 10)) ? $now : $to . ' 23:59:59';
    } else {
        $to = ($to === substr($now, 0, 10)) ? $now : $to . ' 23:59:59';
    }
}

$chartDates = [];
$chartCounts = [];
$topIngredients = [];

if (can('inventory')) {
    if ($isHourly) {
        // Group by exact Y-m-d H hour block
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H') as restock_hour, COUNT(*) as count
                               FROM stock_receipts
                               WHERE received_at >= :from AND received_at <= :to
                               GROUP BY restock_hour
                               ORDER BY restock_hour ASC");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $restocks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // If it's a custom single-day filter, generate hours from 00:00 to 23:00 for that day
        if (isset($_GET['from']) && $_GET['from'] === $_GET['to']) {
            $startHourTs = strtotime($_GET['from'] . ' 00:00:00');
            $endHourTs   = strtotime($_GET['from'] . ' 23:00:00');
        } else {
            // Otherwise use rolling 24-hour window
            $endHourTs   = strtotime(date('Y-m-d H:00:00', strtotime($now)));
            $startHourTs = strtotime('-23 hours', $endHourTs);
        }
        
        $curr = $startHourTs;
        while ($curr <= $endHourTs) {
            $key = date('Y-m-d H', $curr);           
            $chartDates[] = date('g:i A', $curr);    // Displays minute-precise labels (e.g., "6:30 PM", "6:00 PM")
            $chartCounts[] = (int) ($restocks[$key] ?? 0);
            $curr = strtotime('+1 hour', $curr);
        }
    } else {
        // Group by day for multi-day view
        $stmt = $pdo->prepare("SELECT DATE(received_at) as restock_date, COUNT(*) as count
                               FROM stock_receipts
                               WHERE received_at >= :from AND received_at < DATE_ADD(DATE(:to), INTERVAL 1 DAY)
                               GROUP BY DATE(received_at)
                               ORDER BY restock_date ASC");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $restocks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $curr = strtotime(substr($from, 0, 10));
        $end = strtotime(substr($to, 0, 10));

        while ($curr <= $end) {
            $dStr = date('Y-m-d', $curr);
            $chartDates[] = date('M j', $curr);
            $chartCounts[] = (int) ($restocks[$dStr] ?? 0);
            $curr = strtotime('+1 day', $curr);
        }
    }

    // Top ingredients usage for period
    $stmtIng = $pdo->prepare('SELECT i.name, i.unit, SUM(u.current_stock) AS total_used
                              FROM usage_logs u
                              JOIN ingredients i ON i.id = u.ingredient_id
                              WHERE u.used_at >= :from AND u.used_at <= :to
                              GROUP BY i.id, i.name, i.unit
                              ORDER BY total_used DESC
                              LIMIT 5');
    $stmtIng->execute([':from' => $from, ':to' => $to]);
    $topIngredients = $stmtIng->fetchAll();
}

echo json_encode([
    'chartDates' => $chartDates,
    'chartCounts' => $chartCounts,
    'ingredients' => $topIngredients,
    'from' => substr($from, 0, 10),
    'to' => substr($to, 0, 10)
]);
exit;