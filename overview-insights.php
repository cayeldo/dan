<?php
declare(strict_types=1);

/** Rounded prose only; ranking and budget comparisons always use exact cents. */
function insight_amount(int $cents): string
{
    if ($cents > 0 && $cents < 100) { return 'less than $1'; }
    $step = $cents >= 100000 ? 2500 : ($cents >= 10000 ? 500 : 100);
    return 'about $' . number_format(round($cents / $step) * $step / 100, 0);
}

/** Read-only, deterministic takeaways. Never ask AI to invent a positive or a habit. */
function overview_insights(PDO $db, int $user, array $history, ?string $today = null): array
{
    $today ??= (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
    $currentMonth = substr($today, 0, 7);
    $history = array_filter($history, fn($key) => $key <= $currentMonth, ARRAY_FILTER_USE_KEY);
    if (isset($history[$currentMonth])) { $history[$currentMonth] = spending_through_day($history[$currentMonth], (int) substr($today, 8, 2)); }
    ksort($history); $month = $history ? array_key_last($history) : null;
    if (!$month) { return ['month' => null, 'caption' => '', 'rows' => []]; }
    $current = $month === $currentMonth; $period = $history[$month];
    $caption = month_label($month) . ($current ? ' · through ' . (int) substr($today, 8, 2) . ' ' . (new DateTimeImmutable($today))->format('M') : ' · recorded purchases');
    $rows = []; $attentionIds = []; $overIds = [];
    $url = '/?page=analyzer&month=' . $month;
    $fund = travel_fund_balance($db, $user, $month, $history);
    $saved = effective_budget($db, $user, $month);
    if ($saved) {
        $groups = json_decode($saved['groups_json'], true, 32, JSON_THROW_ON_ERROR);
        $targets = json_decode($saved['targets_json'], true, 32, JSON_THROW_ON_ERROR);
        $individual = array_column(array_filter($groups, fn($g) => $g['category_id'] !== null), 'category_id');
        $candidates = [];
        foreach ($groups as $key => $group) {
            $members = array_filter($period['categories'], fn($c) => $key === 'misc' ? !in_array($c['id'], $individual, true) : $c['id'] === $group['category_id']);
            $spent = array_sum(array_column($members, 'amount')); $target = (int) $targets[$key];
            if ($fund && $group['category_id'] === $fund['category_id']) { $target += $fund['opening_cents'] - $fund['reallocated_cents']; }
            $left = $target - $spent;
            if ($left < 0) { $overIds = [...$overIds, ...array_keys($members)]; }
            if ($spent < 2500 || ($left < 0 && -$left < 2500)) { continue; }
            $elapsed = $current ? (int) substr($today, 8, 2) / (int) (new DateTimeImmutable($today))->format('t') : 1;
            if ($left >= 0 && (!$current || $target <= 0 || $spent / $target < max(.75, $elapsed + .15))) { continue; }
            $candidates[] = $group + ['spent' => $spent, 'target' => $target, 'left' => $left, 'key' => $key];
        }
        usort($candidates, fn($a, $b) => ($a['left'] <=> $b['left']) ?: strcmp($a['name'], $b['name']));
        foreach (array_slice($candidates, 0, 2) as $item) {
            $attentionIds[] = $item['category_id'];
            $rows[] = ['visual' => 'budget', 'name' => $item['name'], 'spent_cents' => $item['spent'], 'target_cents' => $item['target'], 'remaining_cents' => $item['left'], 'kind' => 'attention', 'label' => 'Needs attention', 'title' => $item['name'] . ($item['left'] < 0 ? ': over budget' : ': getting tight'),
                'detail' => $item['left'] < 0 ? ucfirst(insight_amount(-$item['left'])) . ' over ' . ($item['target'] === 0 ? 'a $0 target.' : 'the target.') : ucfirst(insight_amount($item['spent'])) . ' spent; ' . insight_amount($item['left']) . ' left this month.',
                'url' => $url . ($item['category_id'] !== null ? '&category=' . $item['category_id'] . '#category-' . $item['category_id'] : '#budget-progress')];
        }
    }
    $end = $current ? $today : (new DateTimeImmutable($month . '-01'))->format('Y-m-t');
    $merchants = analyzer_query($db, "SELECT m.id, m.name, COUNT(*) AS purchases, SUM(ABS(t.amount_cents)) AS amount FROM analyzer_transactions t JOIN analyzer_merchants m ON m.id = t.merchant_id AND m.user_id = t.user_id WHERE t.user_id = ? AND t.kind = 'expense' AND t.transaction_date >= ? AND t.transaction_date <= ? GROUP BY m.id, m.name HAVING COUNT(*) >= 5 AND SUM(ABS(t.amount_cents)) >= 2500 AND (COUNT(*) >= 8 OR SUM(ABS(t.amount_cents)) >= 10000) ORDER BY purchases DESC, amount DESC, m.id LIMIT 2", [$user, $month . '-01', $end])->fetchAll();
    foreach ($merchants as $merchant) {
        $rows[] = ['visual' => 'frequency', 'name' => $merchant['name'], 'count' => (int) $merchant['purchases'], 'amount_cents' => (int) $merchant['amount'], 'kind' => 'pattern', 'label' => 'Noteworthy pattern', 'title' => (int) $merchant['purchases'] . ' purchases at ' . $merchant['name'],
            'detail' => ucfirst(insight_amount((int) $merchant['amount'])) . ' in total' . ($current ? ' so far this month.' : ' during ' . month_label($month) . '.'),
            'url' => $url . '&merchant=' . (int) $merchant['id'] . '#merchant-' . (int) $merchant['id']];
    }
    if ($fund && $fund['available_cents'] >= 2500 && ($fund['contribution_cents'] + $fund['spent_cents'] + $fund['reallocated_cents'] >= 2500 || ($fund['start_month'] === $month && $fund['opening_cents'] > 0))) {
        $rows[] = ['visual' => 'fund', 'name' => 'Travel fund', 'fund' => $fund, 'kind' => 'positive', 'label' => 'Travel fund', 'title' => ucfirst(insight_amount($fund['available_cents'])) . ' left for travel',
            'detail' => $fund['reallocated_cents'] > 0 ? 'After ' . insight_amount($fund['reallocated_cents']) . ' covered other overages. Only the remainder can carry forward.' : 'Includes this month’s contribution and prior carryover, after recorded spending.',
            'url' => '/?page=budget&month=' . $month . '#travel-fund-heading'];
    }
    {
        $comparison = spending_comparison($history, $month, $today);
        $lower = array_filter($comparison['categories'], fn($c) => !in_array($c['id'], $attentionIds, true) && !in_array($c['id'], $overIds, true) && $c['current'] > 0 && $c['delta'] <= -2500 && $c['percent'] !== null && $c['percent'] <= -20);
        usort($lower, fn($a, $b) => ($a['delta'] <=> $b['delta']) ?: ($a['id'] <=> $b['id']));
        foreach (array_slice($lower, 0, 2) as $item) {
            $window = $current ? (new DateTimeImmutable($month . '-01'))->format('M') . ' 1–' . $comparison['current_day'] . ' vs ' . (new DateTimeImmutable($comparison['previous_month'] . '-01'))->format('M') . ' 1–' . $comparison['previous_day'] : month_label($comparison['previous_month']);
            $rows[] = ['visual' => 'comparison', 'name' => $item['name'], 'current_cents' => $item['current'], 'previous_cents' => $item['previous'], 'change_cents' => $item['delta'], 'current_label' => (new DateTimeImmutable($month . '-01'))->format('M') . ($current ? ' 1–' . $comparison['current_day'] : ''), 'previous_label' => (new DateTimeImmutable($comparison['previous_month'] . '-01'))->format('M') . ($current ? ' 1–' . $comparison['previous_day'] : ''), 'kind' => 'positive', 'label' => 'Spending eased', 'title' => $item['name'] . ' spending is ' . insight_amount(-$item['delta']) . ' lower',
                'detail' => 'Recorded purchases: ' . $window . '. ' . ($current ? 'A lighter start; the month is still in progress.' : 'A lower total, based on the transactions recorded.'),
                'url' => $url . '&category=' . $item['id'] . '#category-' . $item['id']];
        }
    }
    // Show one of each useful type first, then up to two additional strong signals.
    $primary = []; $extra = [];
    foreach ($rows as $row) {
        if (!isset($primary[$row['kind']])) { $primary[$row['kind']] = $row; }
        else { $extra[] = $row; }
    }
    $rows = array_slice([...array_values($primary), ...$extra], 0, 5);
    return ['month' => $month, 'caption' => $caption, 'rows' => $rows];
}

/** Short visual labels; approximation is explicit without a sentence. */
function insight_figure(int $cents): string
{
    $step = $cents >= 100000 ? 2500 : ($cents >= 10000 ? 500 : 100);
    return str_replace(['less than ', 'about '], ['< ', $cents % $step === 0 ? '' : '≈'], insight_amount($cents));
}
