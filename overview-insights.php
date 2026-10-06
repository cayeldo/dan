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
    $rows = []; $attentionId = null; $overIds = [];
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
            if ($spent <= 0 || ($left >= 0 && (!$current || $target <= 0 || $spent * 4 < $target * 3))) { continue; }
            $candidates[] = $group + ['spent' => $spent, 'target' => $target, 'left' => $left, 'key' => $key];
        }
        usort($candidates, fn($a, $b) => ($a['left'] <=> $b['left']) ?: strcmp($a['name'], $b['name']));
        if ($candidates) {
            $item = $candidates[0]; $attentionId = $item['category_id'];
            $rows[] = ['kind' => 'attention', 'label' => 'Needs attention', 'title' => $item['name'] . ($item['left'] < 0 ? ': over budget' : ': getting tight'),
                'detail' => $item['left'] < 0 ? ucfirst(insight_amount(-$item['left'])) . ' over ' . ($item['target'] === 0 ? 'a $0 target.' : 'the target.') : ucfirst(insight_amount($item['spent'])) . ' spent; ' . insight_amount($item['left']) . ' left this month.',
                'url' => $url . ($item['category_id'] !== null ? '&category=' . $item['category_id'] . '#category-' . $item['category_id'] : '#budget-progress')];
        }
    }
    $end = $current ? $today : (new DateTimeImmutable($month . '-01'))->format('Y-m-t');
    $merchant = analyzer_query($db, "SELECT m.id, m.name, COUNT(*) AS purchases, SUM(ABS(t.amount_cents)) AS amount FROM analyzer_transactions t JOIN analyzer_merchants m ON m.id = t.merchant_id AND m.user_id = t.user_id WHERE t.user_id = ? AND t.kind = 'expense' AND t.transaction_date >= ? AND t.transaction_date <= ? GROUP BY m.id, m.name HAVING COUNT(*) >= 5 AND SUM(ABS(t.amount_cents)) >= 2500 ORDER BY purchases DESC, amount DESC, m.id LIMIT 1", [$user, $month . '-01', $end])->fetch();
    if ($merchant) {
        $rows[] = ['kind' => 'pattern', 'label' => 'Noteworthy pattern', 'title' => (int) $merchant['purchases'] . ' purchases at ' . $merchant['name'],
            'detail' => ucfirst(insight_amount((int) $merchant['amount'])) . ' in total' . ($current ? ' so far this month.' : ' during ' . month_label($month) . '.'),
            'url' => $url . '&merchant=' . (int) $merchant['id'] . '#merchant-' . (int) $merchant['id']];
    }
    if ($fund && $fund['available_cents'] > 0) {
        $rows[] = ['kind' => 'positive', 'label' => 'Travel fund', 'title' => ucfirst(insight_amount($fund['available_cents'])) . ' left for travel',
            'detail' => $fund['reallocated_cents'] > 0 ? 'After ' . insight_amount($fund['reallocated_cents']) . ' covered other overages. Only the remainder can carry forward.' : 'Includes this month’s contribution and prior carryover, after recorded spending.',
            'url' => '/?page=budget&month=' . $month . '#travel-fund-heading'];
    } else {
        $comparison = spending_comparison($history, $month, $today);
        $lower = array_filter($comparison['categories'], fn($c) => $c['id'] !== $attentionId && !in_array($c['id'], $overIds, true) && $c['current'] > 0 && $c['delta'] <= -2500 && $c['percent'] !== null && $c['percent'] <= -20);
        usort($lower, fn($a, $b) => ($a['delta'] <=> $b['delta']) ?: ($a['id'] <=> $b['id']));
        if ($lower) {
            $item = $lower[0];
            $window = $current ? (new DateTimeImmutable($month . '-01'))->format('M') . ' 1–' . $comparison['current_day'] . ' vs ' . (new DateTimeImmutable($comparison['previous_month'] . '-01'))->format('M') . ' 1–' . $comparison['previous_day'] : month_label($comparison['previous_month']);
            $rows[] = ['kind' => 'positive', 'label' => 'Spending eased', 'title' => $item['name'] . ' spending is ' . insight_amount(-$item['delta']) . ' lower',
                'detail' => 'Recorded purchases: ' . $window . '. ' . ($current ? 'A lighter start; the month is still in progress.' : 'A lower total, based on the transactions recorded.'),
                'url' => $url . '&category=' . $item['id'] . '#category-' . $item['id']];
        }
    }
    if (!$rows) {
        $rows[] = ['kind' => 'neutral', 'label' => 'Keep an eye on things', 'title' => 'No standout patterns yet',
            'detail' => $saved ? 'Check your category balances as more purchases come in.' : 'Set category targets to see which spending needs attention.',
            'url' => $saved ? $url . '#budget-progress' : '/?page=budget&month=' . $month];
    }
    return ['month' => $month, 'caption' => $caption, 'rows' => $rows];
}
