<?php
declare(strict_types=1);

function vacation_fund_setting(PDO $db, int $user): ?array
{
    return analyzer_query($db, 'SELECT * FROM analyzer_vacation_funds WHERE user_id = ?', [$user])->fetch() ?: null;
}

function vacation_fund_version(PDO $db, int $user): string
{
    return hash('sha256', json_encode([vacation_fund_setting($db, $user),
        analyzer_query($db, 'SELECT * FROM analyzer_vacation_contributions WHERE user_id = ? ORDER BY month', [$user])->fetchAll()], JSON_THROW_ON_ERROR));
}

/** Legacy balances apply only before the owner switches to independent vacation savings. */
function budget_fund_balance(PDO $db, int $user, string $month, ?array $history = null): ?array
{
    return vacation_fund_balance($db, $user, $month, $history) ?? travel_fund_balance($db, $user, $month, $history);
}

function save_vacation_fund(PDO $db, int $user, string $month, string $contribution, string $opening, string $budgetVersion, string $fundVersion): void
{
    $current = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m');
    if (!budget_month_valid($month) || $month < $current) { throw new InvalidArgumentException('Set Vacation Fund contributions for this month or a future month.'); }
    $amount = budget_target_cents($contribution);
    $db->beginTransaction();
    try {
        if (!analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user])->fetchColumn()) { throw new InvalidArgumentException('Sign in again.'); }
        $plan = budget_plan($db, $user, $month);
        if (!$plan['source_month'] || !hash_equals(budget_form_version($plan), $budgetVersion) || !hash_equals(vacation_fund_version($db, $user), $fundVersion)) {
            throw new InvalidArgumentException('Your budget or Vacation Fund changed. Reload the Budget page before saving.');
        }
        $setting = vacation_fund_setting($db, $user);
        if ($setting && $month < $setting['start_month']) { throw new InvalidArgumentException('Choose the Vacation Fund start month or a later month.'); }
        if (!$setting) {
            $cents = budget_target_cents($opening);
            separate_travel_target($db, $user, $month, $plan);
            analyzer_query($db, 'INSERT INTO analyzer_vacation_funds (user_id, start_month, opening_cents, created_at) VALUES (?, ?, ?, ?)', [$user, $month, $cents, gmdate('Y-m-d H:i:s')]);
        }
        $existing = analyzer_query($db, 'SELECT month FROM analyzer_vacation_contributions WHERE user_id = ? AND month = ?', [$user, $month])->fetchColumn();
        if ($existing) {
            analyzer_query($db, 'UPDATE analyzer_vacation_contributions SET contribution_cents = ?, revision = revision + 1 WHERE user_id = ? AND month = ?', [$amount, $user, $month]);
        } else {
            analyzer_query($db, 'INSERT INTO analyzer_vacation_contributions (user_id, month, contribution_cents) VALUES (?, ?, ?)', [$user, $month, $amount]);
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

/** A contribution is savings, never a purchase. Only a net monthly shortage draws it down. */
function vacation_fund_month(int $opening, int $planned, int $spent, int $expenseTarget, ?int $cashCapacity = null): array
{
    $funded = $cashCapacity === null ? $planned : min($planned, max(0, $cashCapacity - $expenseTarget));
    $expenseCapacity = $cashCapacity === null ? $expenseTarget : max(0, $cashCapacity - $funded);
    $shortage = max(0, $spent - $expenseCapacity);
    $draw = min($opening + $funded, $shortage);
    return ['kind' => 'vacation', 'category_id' => -1, 'opening_cents' => $opening,
        'planned_contribution_cents' => $planned, 'contribution_cents' => $funded, 'spent_cents' => 0,
        'expense_capacity_cents' => $expenseCapacity, 'shortage_cents' => $shortage,
        'reallocated_cents' => $draw, 'reserve_used_elsewhere_cents' => max(0, $draw - $funded),
        'available_cents' => $opening + $funded - $draw, 'unfunded_cents' => $shortage - $draw];
}

function vacation_fund_balance(PDO $db, int $user, string $month, ?array $history = null): ?array
{
    $setting = vacation_fund_setting($db, $user);
    if (!$setting || $month < $setting['start_month']) { return null; }
    $history ??= spending_history($db, $user);
    $plans = analyzer_query($db, 'SELECT * FROM analyzer_budgets WHERE user_id = ? ORDER BY month', [$user])->fetchAll();
    $snapshots = array_column(analyzer_query($db, 'SELECT * FROM analyzer_budget_snapshots WHERE user_id = ?', [$user])->fetchAll(), null, 'month');
    $resources = analyzer_query($db, 'SELECT month, cash_cents, reserve_cents FROM analyzer_budget_resources WHERE user_id = ? ORDER BY month', [$user])->fetchAll();
    $contributions = analyzer_query($db, 'SELECT month, contribution_cents FROM analyzer_vacation_contributions WHERE user_id = ? ORDER BY month', [$user])->fetchAll();
    $today = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $current = $today->format('Y-m');
    $balance = (int) $setting['opening_cents']; $ledger = []; $missing = []; $unconfirmed = 0;
    for ($date = new DateTimeImmutable($setting['start_month'] . '-01'); $date->format('Y-m') <= $month; $date = $date->modify('+1 month')) {
        $key = $date->format('Y-m'); $saved = null; $cash = null; $planned = 0;
        foreach ($plans as $plan) { if ($plan['month'] <= $key) { $saved = $plan; } else { break; } }
        if ($key < $current && isset($snapshots[$key])) { $saved = $snapshots[$key]; }
        foreach ($resources as $resource) { if ($resource['month'] <= $key) { $cash = $resource; } else { break; } }
        foreach ($contributions as $row) { if ($row['month'] <= $key) { $planned = (int) $row['contribution_cents']; } else { break; } }
        $target = $saved ? array_sum(json_decode($saved['targets_json'], true, 32, JSON_THROW_ON_ERROR)) : 0;
        $complete = $key < $current && month_is_complete($db, $user, $key);
        $observed = isset($history[$key]) || $complete;
        $period = $history[$key] ?? ['expenses' => 0, 'categories' => [], 'daily' => []];
        if ($key === $current) { $period = spending_through_day($period, (int) $today->format('d')); }
        if ($key > $current) { $period['expenses'] = 0; }
        $entry = vacation_fund_month($balance, $saved && ($key >= $current || $observed) ? $planned : 0, $period['expenses'], $target,
            $cash ? max(0, (int) $cash['cash_cents'] - (int) $cash['reserve_cents']) : null);
        $entry['month'] = $key;
        $entry['planned_contribution_cents'] = $planned;
        $entry['provisional'] = !$complete;
        if (!$observed && $key < $current) { $missing[] = $key; }
        if ($key === $month) {
            return $entry + ['start_month' => $setting['start_month'], 'ledger' => $ledger,
                'missing_months' => $missing, 'unconfirmed_months' => $unconfirmed];
        }
        if ($key >= $current) {
            // An unfinished month can consume existing savings but cannot create future carryover.
            $balance = min($balance, $entry['available_cents']);
            continue;
        }
        if (!$observed) { continue; }
        if (!$complete) { $unconfirmed++; }
        $balance = $entry['available_cents']; $ledger[] = $entry;
    }
    return null;
}
