<?php
declare(strict_types=1);

function previous_month(string $month): string
{
    return (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
}

/** Aggregate in SQL: dashboard requests never load raw descriptions or memos. */
function spending_history(PDO $db, int $userId, int $accountId = 0): array
{
    $sql = "SELECT SUBSTR(t.transaction_date, 1, 7) AS month, SUBSTR(t.transaction_date, 9, 2) AS day, t.kind, c.id AS category_id, c.name AS category, SUM(ABS(t.amount_cents)) AS amount, COUNT(*) AS row_count FROM analyzer_transactions t LEFT JOIN analyzer_merchants m ON m.id = t.merchant_id LEFT JOIN analyzer_categories c ON c.id = m.category_id WHERE t.user_id = ?";
    $params = [$userId];
    if ($accountId > 0) { $sql .= ' AND t.account_id = ?'; $params[] = $accountId; }
    $rows = analyzer_query($db, $sql . ' GROUP BY SUBSTR(t.transaction_date, 1, 7), SUBSTR(t.transaction_date, 9, 2), t.kind, c.id, c.name ORDER BY month', $params)->fetchAll();
    $history = [];
    foreach ($rows as $row) {
        $month = $row['month'];
        $day = (int) $row['day'];
        $history[$month] ??= ['daily' => []];
        $history[$month]['daily'][$day] ??= ['expenses' => 0, 'refunds' => 0, 'net' => 0, 'count' => 0, 'purchase_count' => 0, 'categories' => []];
        $period =& $history[$month]['daily'][$day];
        $period['count'] += (int) $row['row_count'];
        if ($row['kind'] !== 'payment') {
            $bucket = $row['kind'] === 'expense' ? 'expenses' : 'refunds';
            $period[$bucket] += (int) $row['amount'];
            if ($bucket === 'expenses') {
                $period['purchase_count'] += (int) $row['row_count'];
                $id = (int) $row['category_id'];
                $period['categories'][$id] = ['id' => $id, 'name' => $row['category'] ?? 'Uncategorized', 'amount' => (int) $row['amount']];
            }
        }
        unset($period);
    }
    foreach ($history as $key => &$period) {
        $period = spending_through_day($period, 31);
        $period['complete'] = function_exists('month_is_complete') && month_is_complete($db, $userId, $key);
    }
    unset($period);
    return $history;
}

/** Keep known months with no purchases in the window as zero, missing months as missing. */
function spending_through_day(array $period, int $day): array
{
    $result = ['expenses' => 0, 'refunds' => 0, 'net' => 0, 'count' => 0, 'purchase_count' => 0, 'categories' => [], 'daily' => [], 'complete' => $period['complete'] ?? false];
    $sums = [];
    foreach ($period['daily'] ?? [] as $key => $daily) {
        if ($key > $day) { continue; }
        $result['daily'][$key] = $daily;
        foreach (['expenses', 'refunds', 'count', 'purchase_count'] as $field) { $result[$field] += $daily[$field]; }
        foreach ($daily['categories'] as $id => $category) {
            $result['categories'][$id] ??= $category;
            $sums[$id] = ($sums[$id] ?? 0) + $category['amount'];
            $result['categories'][$id]['amount'] = $sums[$id];
        }
    }
    $result['net'] = $result['expenses'] - $result['refunds'];
    return $result;
}

function spending_change(int $current, int $previous): array
{
    return ['delta' => $current - $previous, 'percent' => $previous > 0 ? ($current - $previous) / $previous * 100 : null];
}

function spending_comparison(array $history, string $month, ?string $today = null): array
{
    $today ??= gmdate('Y-m-d');
    $previousMonth = previous_month($month);
    $current = $history[$month] ?? null;
    $previous = $history[$previousMonth] ?? null;
    $partial = $month === substr($today, 0, 7);
    $currentDay = $partial ? (int) substr($today, 8, 2) : null;
    $previousDay = $partial ? min($currentDay, (int) (new DateTimeImmutable($previousMonth . '-01'))->format('t')) : null;
    if ($partial) {
        if ($current !== null) { $current = spending_through_day($current, $currentDay); }
        if ($previous !== null) { $previous = spending_through_day($previous, $previousDay); }
    }
    $result = ['partial' => $partial, 'current_day' => $currentDay, 'previous_day' => $previousDay, 'month' => $month, 'previous_month' => $previousMonth, 'current' => $current, 'previous' => $previous,
        'available' => $current !== null && $previous !== null, 'categories' => [], 'total' => null];
    if (!$result['available']) { return $result; }
    $result['total'] = spending_change($current['expenses'], $previous['expenses']);
    foreach (array_unique([...array_keys($current['categories']), ...array_keys($previous['categories'])]) as $id) {
        $a = $current['categories'][$id] ?? null;
        $b = $previous['categories'][$id] ?? null;
        $currentShare = $current['expenses'] > 0 ? ($a['amount'] ?? 0) / $current['expenses'] * 100 : null;
        $previousShare = $previous['expenses'] > 0 ? ($b['amount'] ?? 0) / $previous['expenses'] * 100 : null;
        $result['categories'][] = ['id' => $id, 'name' => ($a ?? $b)['name'], 'current' => $a['amount'] ?? 0,
            'previous' => $b['amount'] ?? 0, 'share' => $currentShare, 'previous_share' => $previousShare,
            'share_delta' => $currentShare !== null && $previousShare !== null ? $currentShare - $previousShare : null,
            ...spending_change($a['amount'] ?? 0, $b['amount'] ?? 0)];
    }
    usort($result['categories'], fn($a, $b) => (max($b['current'], $b['previous']) <=> max($a['current'], $a['previous'])) ?: strcasecmp($a['name'], $b['name']));
    return $result;
}

function spending_dashboard(array $history): array
{
    $categories = []; $expenses = 0; $refunds = 0;
    foreach ($history as $month => $period) {
        $expenses += $period['expenses']; $refunds += $period['refunds'];
        foreach ($period['categories'] as $id => $category) {
            $categories[$id] ??= ['id' => $id, 'name' => $category['name'], 'amount' => 0];
            $categories[$id]['amount'] += $category['amount'];
            $categories[$id]['latest_month'] = max($categories[$id]['latest_month'] ?? '', $month);
        }
    }
    uasort($categories, fn($a, $b) => ($b['amount'] <=> $a['amount']) ?: strcasecmp($a['name'], $b['name']));
    $latest = $history ? array_key_last($history) : null;
    $comparison = $latest ? spending_comparison($history, $latest) : null;
    $movers = [];
    if ($comparison && $comparison['available']) {
        $eligible = array_filter($comparison['categories'], fn($c) => abs($c['delta']) >= 2500
            && ($c['percent'] === null || abs($c['percent']) >= 20 || abs($c['share_delta'] ?? 0) >= 5));
        usort($eligible, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));
        // Include both directions when present, with at most two in each direction.
        foreach ([1, -1] as $direction) {
            $movers = [...$movers, ...array_slice(array_values(array_filter($eligible, fn($c) => ($c['delta'] > 0 ? 1 : -1) === $direction)), 0, 2)];
        }
    }
    $series = [];
    if ($latest) {
        $start = max(array_key_first($history), (new DateTimeImmutable($latest . '-01'))->modify('-11 months')->format('Y-m'));
        for ($cursor = new DateTimeImmutable($start . '-01'); $cursor->format('Y-m') <= $latest; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m');
            $series[$key] = $history[$key]['expenses'] ?? null; // A missing upload is not zero spending.
        }
    }
    $pastMonths = array_filter($series, fn($value, $key) => $value !== null && $key < gmdate('Y-m'), ARRAY_FILTER_USE_BOTH);
    $average = $pastMonths ? (int) round(array_sum($pastMonths) / count($pastMonths)) : null;
    return compact('categories', 'expenses', 'refunds', 'latest', 'comparison', 'movers', 'series', 'average', 'pastMonths');
}

/** Filled donut wedges have precise hit areas for hover, focus, and click. */
function donut_slice_path(float $offset, float $fraction): string
{
    $point = static fn(float $r, float $angle): string => sprintf('%.4F %.4F', 120 + $r * cos($angle), 120 + $r * sin($angle));
    $start = $offset * 2 * M_PI - M_PI / 2;
    $end = $start + $fraction * 2 * M_PI;
    if ($fraction >= .99999999) {
        return 'M 120 18 A 102 102 0 1 1 120 222 A 102 102 0 1 1 120 18 L 120 42 A 78 78 0 1 0 120 198 A 78 78 0 1 0 120 42 Z';
    }
    $large = $fraction > .5 ? 1 : 0;
    return 'M ' . $point(102, $start) . ' A 102 102 0 ' . $large . ' 1 ' . $point(102, $end)
        . ' L ' . $point(78, $end) . ' A 78 78 0 ' . $large . ' 0 ' . $point(78, $start) . ' Z';
}

function signed_money(int $cents): string { return ($cents > 0 ? '+' : '') . money($cents); }
function signed_percent(?float $value, string $suffix = '%'): string
{
    if ($value === null) { return '—'; }
    $rounded = round($value, 1);
    return ($rounded > 0 ? '+' : ($rounded < 0 ? '−' : '')) . number_format(abs($rounded), 1) . $suffix;
}
function change_caption(array $change): string
{
    if ($change['percent'] === null) { return $change['delta'] > 0 ? 'New spending · no prior purchases' : 'No purchases in either month'; }
    return signed_percent($change['percent']) . ' vs previous month';
}
