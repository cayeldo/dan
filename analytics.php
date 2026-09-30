<?php
declare(strict_types=1);

function previous_month(string $month): string
{
    return (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
}

/** Aggregate in SQL: dashboard requests never load raw descriptions or memos. */
function spending_history(PDO $db, int $userId, int $accountId = 0): array
{
    $sql = "SELECT SUBSTR(t.transaction_date, 1, 7) AS month, t.kind, c.id AS category_id, c.name AS category, SUM(ABS(t.amount_cents)) AS amount, COUNT(*) AS row_count FROM analyzer_transactions t LEFT JOIN analyzer_merchants m ON m.id = t.merchant_id LEFT JOIN analyzer_categories c ON c.id = m.category_id WHERE t.user_id = ?";
    $params = [$userId];
    if ($accountId > 0) { $sql .= ' AND t.account_id = ?'; $params[] = $accountId; }
    $rows = analyzer_query($db, $sql . ' GROUP BY SUBSTR(t.transaction_date, 1, 7), t.kind, c.id, c.name ORDER BY month', $params)->fetchAll();
    $history = [];
    foreach ($rows as $row) {
        $month = $row['month'];
        $history[$month] ??= ['expenses' => 0, 'refunds' => 0, 'net' => 0, 'count' => 0, 'categories' => []];
        $history[$month]['count'] += (int) $row['row_count'];
        if ($row['kind'] === 'payment') { continue; }
        $bucket = $row['kind'] === 'expense' ? 'expenses' : 'refunds';
        $history[$month][$bucket] += (int) $row['amount'];
        if ($bucket === 'expenses') {
            $id = (int) $row['category_id'];
            $history[$month]['categories'][$id] = ['id' => $id, 'name' => $row['category'], 'amount' => (int) $row['amount']];
        }
    }
    foreach ($history as &$period) { $period['net'] = $period['expenses'] - $period['refunds']; }
    unset($period);
    return $history;
}

function spending_change(int $current, int $previous): array
{
    return ['delta' => $current - $previous, 'percent' => $previous > 0 ? ($current - $previous) / $previous * 100 : null];
}

function spending_comparison(array $history, string $month): array
{
    $previousMonth = previous_month($month);
    $current = $history[$month] ?? null;
    $previous = $history[$previousMonth] ?? null;
    $result = ['month' => $month, 'previous_month' => $previousMonth, 'current' => $current, 'previous' => $previous,
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
    return compact('categories', 'expenses', 'refunds', 'latest', 'comparison', 'movers', 'series');
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
