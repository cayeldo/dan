<?php
declare(strict_types=1);

/** No deposits or bank transfers: this is an earmarked budget balance from recorded purchases. */
function travel_fund_month(int $opening, int $contribution, int $travelSpent, int $otherSpent, int $otherBudget): array
{
    $afterTravel = max(0, $opening + $contribution - $travelSpent);
    $otherOverage = max(0, $otherSpent - $otherBudget);
    $reallocated = min($afterTravel, $otherOverage);
    $reserveUsedElsewhere = max(0, $reallocated - max(0, $contribution - $travelSpent));
    return ['opening_cents' => $opening, 'contribution_cents' => $contribution, 'spent_cents' => $travelSpent,
        'reallocated_cents' => $reallocated, 'reserve_used_elsewhere_cents' => $reserveUsedElsewhere,
        'available_cents' => $afterTravel - $reallocated,
        'unfunded_cents' => max(0, $travelSpent - $opening - $contribution) + max(0, $otherOverage - $reallocated)];
}

function start_travel_fund(PDO $db, int $user, string $month, string $opening, string $budgetVersion): void
{
    $current = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m');
    if (!budget_month_valid($month) || $month < $current) { throw new InvalidArgumentException('Start the travel fund this month or in a future month.'); }
    $cents = budget_target_cents($opening);
    $db->beginTransaction();
    try {
        analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
        if (analyzer_query($db, 'SELECT user_id FROM analyzer_travel_funds WHERE user_id = ?', [$user])->fetchColumn()) { throw new InvalidArgumentException('Your travel fund is already running. Its balance is calculated from your saved budgets and spending.'); }
        $plan = budget_plan($db, $user, $month);
        if (!$plan['source_month'] || !hash_equals($budgetVersion, budget_form_version($plan))) { throw new InvalidArgumentException('Save your budget first, then start the travel fund.'); }
        $category = separate_travel_target($db, $user, $month, $plan);
        analyzer_query($db, 'INSERT INTO analyzer_travel_funds (user_id, category_id, start_month, opening_cents, created_at) VALUES (?, ?, ?, ?, ?)', [$user, $category, $month, $cents, gmdate('Y-m-d H:i:s')]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

function travel_fund_balance(PDO $db, int $user, string $month, ?array $history = null): ?array
{
    $setting = analyzer_query($db, 'SELECT * FROM analyzer_travel_funds WHERE user_id = ?', [$user])->fetch();
    if (!$setting || $month < $setting['start_month']) { return null; }
    $history ??= spending_history($db, $user);
    $plans = analyzer_query($db, 'SELECT * FROM analyzer_budgets WHERE user_id = ? ORDER BY month', [$user])->fetchAll();
    $snapshots = array_column(analyzer_query($db, 'SELECT * FROM analyzer_budget_snapshots WHERE user_id = ?', [$user])->fetchAll(), null, 'month');
    $resources = analyzer_query($db, 'SELECT month, cash_cents, reserve_cents FROM analyzer_budget_resources WHERE user_id = ? ORDER BY month', [$user])->fetchAll();
    $current = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m');
    $balance = (int) $setting['opening_cents']; $ledger = []; $missing = []; $unconfirmed = 0;
    for ($date = new DateTimeImmutable($setting['start_month'] . '-01'); $date->format('Y-m') <= $month; $date = $date->modify('+1 month')) {
        $key = $date->format('Y-m'); $saved = null; $cash = null;
        foreach ($plans as $plan) { if ($plan['month'] <= $key) { $saved = $plan; } else { break; } }
        if ($key < $current && isset($snapshots[$key])) { $saved = $snapshots[$key]; }
        foreach ($resources as $resource) { if ($resource['month'] <= $key) { $cash = $resource; } else { break; } }
        $contribution = 0; $totalTarget = 0;
        if ($saved) {
            $groups = json_decode($saved['groups_json'], true, 32, JSON_THROW_ON_ERROR); $targets = json_decode($saved['targets_json'], true, 32, JSON_THROW_ON_ERROR);
            $totalTarget = array_sum($targets);
            foreach ($groups as $groupKey => $group) { if ($group['category_id'] === (int) $setting['category_id']) { $contribution = $targets[$groupKey]; } }
        }
        // If a known cash limit cannot fund the saved budget, don't invent a contribution.
        $limit = $cash ? min($totalTarget, max(0, (int) $cash['cash_cents'] - (int) $cash['reserve_cents'])) : $totalTarget;
        $contribution = min($contribution, max(0, $limit - ($totalTarget - $contribution)));
        $period = $history[$key] ?? ['expenses' => 0, 'categories' => []];
        $travelSpent = $period['categories'][(int) $setting['category_id']]['amount'] ?? 0;
        $entry = travel_fund_month($balance, $contribution, $travelSpent, $period['expenses'] - $travelSpent, $limit - $contribution);
        $entry['month'] = $key;
        if ($key === $month) {
            return $entry + ['category_id' => (int) $setting['category_id'], 'start_month' => $setting['start_month'],
                'ledger' => $ledger, 'missing_months' => $missing, 'unconfirmed_months' => $unconfirmed];
        }
        if ($key >= $current) {
            // Future plans cannot bank money from an unfinished month; only deduct already-used reserves.
            $balance = min($balance, $entry['available_cents']);
            continue;
        }
        $complete = month_is_complete($db, $user, $key);
        if (!isset($history[$key]) && !$complete) { $missing[] = $key; continue; }
        if (!$complete) { $unconfirmed++; }
        $balance = $entry['available_cents']; $ledger[] = $entry;
    }
    return null;
}

/** Keep ordinary Travel expenses visible even below the usual itemization threshold. */
function separate_travel_target(PDO $db, int $user, string $month, array $plan): int
{
    $groups = array_filter($plan['groups'], fn($g) => $g['name'] === 'Travel' && $g['category_id'] !== null);
    if (!$groups) {
        $category = (int) analyzer_query($db, 'SELECT id FROM analyzer_categories WHERE user_id = ? AND name = ?', [$user, 'Travel'])->fetchColumn();
        if (!$category) {
            analyzer_query($db, 'INSERT INTO analyzer_categories (user_id, name) VALUES (?, ?)', [$user, 'Travel']);
            $category = (int) $db->lastInsertId();
        }
        $key = 'category_' . $category;
        $plan['groups'] = [$key => ['name' => 'Travel', 'category_id' => $category]] + $plan['groups'];
        $targets = [$key => 0] + $plan['targets'];
        persist_budget_targets($db, $user, $plan, $targets);
        analyzer_query($db, 'UPDATE analyzer_budgets SET groups_json = ? WHERE user_id = ? AND month = ?', [json_encode($plan['groups'], JSON_THROW_ON_ERROR), $user, $month]);
    } else { $category = (int) array_values($groups)[0]['category_id']; }
    if (!analyzer_query($db, 'SELECT id FROM analyzer_categories WHERE id = ? AND user_id = ?', [$category, $user])->fetchColumn()) { throw new InvalidArgumentException('Travel category unavailable.'); }

    // Previously saved future plans must also keep Travel separate from Misc,
    // otherwise the reserve could disappear from category progress totals.
    foreach (analyzer_query($db, 'SELECT month, groups_json, targets_json FROM analyzer_budgets WHERE user_id = ? AND month > ?', [$user, $month])->fetchAll() as $future) {
        $groups = json_decode($future['groups_json'], true, 32, JSON_THROW_ON_ERROR);
        if (in_array($category, array_column($groups, 'category_id'), true)) { continue; }
        $key = 'category_' . $category;
        $groups = [$key => ['name' => 'Travel', 'category_id' => $category]] + $groups;
        $targets = [$key => 0] + json_decode($future['targets_json'], true, 32, JSON_THROW_ON_ERROR);
        analyzer_query($db, 'UPDATE analyzer_budgets SET groups_json = ?, targets_json = ?, revision = revision + 1, updated_at = ? WHERE user_id = ? AND month = ?',
            [json_encode($groups, JSON_THROW_ON_ERROR), json_encode($targets, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s'), $user, $future['month']]);
    }

    return $category;
}
