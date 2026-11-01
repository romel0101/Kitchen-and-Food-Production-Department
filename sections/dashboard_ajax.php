<?php
if (!defined('KAFPD_APP')) { http_response_code(403); exit('Access denied.'); }
header('Content-Type: application/json');

// Handle both 'days' parameter (1, 7, 30) and custom 'from'/'to' dates
$isHourly = false;
if (isset($_GET['days'])) {
    $days = (int) $_GET['days'];
    if ($days === 1) {
        $isHourly = true;
        $from = date('Y-m-d 00:00:00');
        $to = date('Y-m-d 23:59:59');
    } else {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    }
} else {
    $from = valid_date($_GET['from'] ?? '') ?? date('Y-m-d', strtotime('-6 days'));
    $to   = valid_date($_GET['to'] ?? '')   ?? date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    // If custom range spans a single day, show hourly
    if ($from === $to) {
        $isHourly = true;
        $from .= ' 00:00:00';
        $to   .= ' 23:59:59';
    }
}

$chartDates = [];
$chartCounts = [];
$topIngredients = [];

if (can('inventory')) {
    if ($isHourly) {
        // Group by hour for 1D view
        $stmt = $pdo->prepare("SELECT HOUR(received_at) as restock_hour, COUNT(*) as count
                               FROM stock_receipts
                               WHERE received_at >= :from AND received_at <= :to
                               GROUP BY HOUR(received_at)
                               ORDER BY restock_hour ASC");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $restocks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        for ($h = 0; $h < 24; $h++) {
            $chartDates[] = date('g A', strtotime("$h:00"));
            $chartCounts[] = (int) ($restocks[$h] ?? 0);
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
