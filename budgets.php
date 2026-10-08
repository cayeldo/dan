<?php
declare(strict_types=1);
require_once __DIR__ . '/travel-fund.php';
require_once __DIR__ . '/vacation-fund.php';

function budget_month_valid(string $month): bool
{
    return (bool) preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', $month);
}

/** Use all earlier imported calendar months, never the current/in-progress month. */
function budget_history(PDO $db, int $user, string $month): array
{
    $cutoff = min($month, gmdate('Y-m'));
    $history = array_filter(spending_history($db, $user), fn($key) => $key < $cutoff, ARRAY_FILTER_USE_KEY);
    // Explicitly confirmed zero-activity months count; unobserved months do not.
    $confirmed = analyzer_query($db, 'SELECT month FROM analyzer_month_closures WHERE user_id = ? AND complete = 1 AND month < ? ORDER BY month', [$user, $cutoff])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($confirmed as $key) {
        if (!isset($history[$key]) && month_is_complete($db, $user, $key)) { $history[$key] = ['expenses' => 0, 'categories' => []]; }
    }
    ksort($history);
    $totals = [];
    foreach ($history as $period) {
        foreach ($period['categories'] as $id => $category) { $totals[$id] = ($totals[$id] ?? 0) + $category['amount']; }
    }
    return ['count' => count($history), 'months' => array_keys($history), 'totals' => $totals];
}

function budget_plan(PDO $db, int $user, string $month): array
{
    if (!budget_month_valid($month)) { throw new InvalidArgumentException('Choose a valid budget month.'); }
    $history = budget_history($db, $user, $month);
    $categories = analyzer_query($db, 'SELECT id, name FROM analyzer_categories WHERE user_id = ? ORDER BY name', [$user])->fetchAll();
    $saved = effective_budget($db, $user, $month);
    if ($saved) {
        $groups = json_decode($saved['groups_json'], true, 32, JSON_THROW_ON_ERROR);
        $targets = json_decode($saved['targets_json'], true, 32, JSON_THROW_ON_ERROR);
    } else {
        $groups = []; $targets = [];
        foreach ($categories as $category) {
            $id = (int) $category['id'];
            // Strictly greater than $50, before rounding to the nearest cent.
            if ($history['count'] && ($history['totals'][$id] ?? 0) > 5000 * $history['count']) {
                $groups['category_' . $id] = ['name' => $category['name'], 'category_id' => $id];
            }
        }
        $groups['misc'] = ['name' => 'Misc', 'category_id' => null];
    }
    $individualIds = array_column(array_filter($groups, fn($g) => $g['category_id'] !== null), 'category_id');
    $miscCategories = array_values(array_filter($categories, fn($c) => !in_array((int) $c['id'], $individualIds, true)));
    $miscTotal = array_sum(array_filter($history['totals'], fn($id) => !in_array((int) $id, $individualIds, true), ARRAY_FILTER_USE_KEY));
    $averages = [];
    foreach ($groups as $key => $group) {
        $sum = $key === 'misc' ? $miscTotal : ($history['totals'][$group['category_id']] ?? 0);
        $averages[$key] = $history['count'] ? (int) round($sum / $history['count']) : null;
    }
    $examples = array_fill_keys(array_keys($groups), []);
    $merchants = analyzer_query($db, "SELECT m.name, m.category_id, c.name AS category, SUM(ABS(t.amount_cents)) AS spending
        FROM analyzer_transactions t JOIN analyzer_merchants m ON m.id = t.merchant_id AND m.user_id = t.user_id
        JOIN analyzer_categories c ON c.id = m.category_id AND c.user_id = t.user_id
        WHERE t.user_id = ? AND t.kind = 'expense' AND t.transaction_date < ?
        GROUP BY m.id, m.name, m.category_id, c.name ORDER BY spending DESC, m.name ASC",
        [$user, min($month, gmdate('Y-m')) . '-01'])->fetchAll();
    foreach ($merchants as $merchant) {
        $key = in_array((int) $merchant['category_id'], $individualIds, true) ? 'category_' . $merchant['category_id'] : 'misc';
        if (count($examples[$key]) < 3) { $examples[$key][] = ['name' => $merchant['name'], 'category' => $merchant['category']]; }
    }
    return ['month' => $month, 'groups' => $groups, 'targets' => $targets, 'averages' => $averages, 'history' => $history, 'examples' => $examples,
        'misc_categories' => array_column($miscCategories, 'name'), 'source_month' => $saved['month'] ?? null, 'snapshot' => (bool) ($saved['snapshot'] ?? false), 'revision' => (int) ($saved['revision'] ?? 0), 'updated_at' => $saved['updated_at'] ?? null];
}

function budget_category_explanation(string $name): string
{
    return [
        'Groceries' => 'Food and household essentials from grocery stores.',
        'Restaurants' => 'Meals, drinks, and takeout bought directly from restaurants or cafés.',
        'Food Delivery' => 'Meals ordered through delivery services.',
        'Transportation' => 'Getting around, such as rideshares, public transit, parking, and tolls.',
        'Fuel' => 'Gas and other vehicle fuel purchases.',
        'Utilities' => 'Household services such as electricity, water, phone, and internet.',
        'Shopping' => 'Retail purchases such as clothing, electronics, and household goods.',
        'Home Improvement' => 'Supplies and services for home repairs and improvements.',
        'Health & Pharmacy' => 'Medical care, pharmacy purchases, and health supplies.',
        'Entertainment' => 'Activities and amusements such as movies, games, and events.',
        'Subscriptions' => 'Recurring memberships and digital services.',
        'Alcohol' => 'Purchases categorized as beer, wine, or spirits.',
        'Government & Services' => 'Government charges and other service purchases.',
        'Travel' => 'Trip expenses such as flights, hotels, and rental cars.',
        'Fees & Interest' => 'Account fees and interest charges.',
        'Uncategorized' => 'Purchases still waiting for a category. Review them in the analyzer.',
        'Misc' => 'Smaller spending categories combined into one target. Saved budgets keep their original grouping.',
    ][$name] ?? 'Purchases assigned to this category in your analyzer.';
}

function budget_form_version(array $plan): string
{
    return hash('sha256', json_encode([$plan['month'], $plan['source_month'], $plan['snapshot'], $plan['revision'], $plan['groups'], $plan['targets']], JSON_THROW_ON_ERROR));
}

function budget_target_cents(string $value): int
{
    $value = trim($value);
    if (!preg_match('/\A(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?\z/', $value)) {
        throw new InvalidArgumentException('Enter a monthly amount for every category, including Misc. Use 0 if you do not plan to spend in a category.');
    }
    $value = str_replace(',', '', $value);
    if (strlen($value) > 12 || (float) $value > 9999999.99) { throw new InvalidArgumentException('Each monthly target must be $9,999,999.99 or less.'); }
    [$dollars, $cents] = array_pad(explode('.', $value), 2, '');
    return (int) $dollars * 100 + (int) str_pad($cents, 2, '0');
}

/** The client supplies amounts only. Membership and ownership come from the server. */
function save_budget(PDO $db, int $user, string $month, array $amounts, string $version): void
{
    $db->beginTransaction();
    try {
        if (!analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user])->fetchColumn()) { throw new InvalidArgumentException('Sign in again.'); }
        $plan = budget_plan($db, $user, $month);
        if (!hash_equals(budget_form_version($plan), $version)) { throw new InvalidArgumentException('This budget or its categories changed in another tab. Reload the Budget page before saving.'); }
        $expected = array_keys($plan['groups']); $supplied = array_keys($amounts); sort($expected); sort($supplied);
        if ($expected !== $supplied) { throw new InvalidArgumentException('Enter a target for each listed category and Misc.'); }
        $targets = [];
        foreach ($amounts as $key => $value) {
            if (!is_string($value)) { throw new InvalidArgumentException('Enter a valid amount for every target.'); }
            $targets[$key] = budget_target_cents($value);
        }
        persist_budget_targets($db, $user, $plan, $targets);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

/** Caller holds the user lock and has validated target membership and amounts. */
function persist_budget_targets(PDO $db, int $user, array $plan, array $targets): void
{
    $month = $plan['month'];
    $json = json_encode($targets, JSON_THROW_ON_ERROR); $now = gmdate('Y-m-d H:i:s');
    // Freeze elapsed inherited months before changing their source budget.
    freeze_past_budgets($db, $user, $month);
    if ($plan['source_month'] === $month && !$plan['snapshot']) {
        analyzer_query($db, 'UPDATE analyzer_budgets SET targets_json = ?, revision = revision + 1, updated_at = ? WHERE user_id = ? AND month = ?', [$json, $now, $user, $month]);
    } else {
        analyzer_query($db, 'INSERT INTO analyzer_budgets (user_id, month, groups_json, targets_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$user, $month, json_encode($plan['groups'], JSON_THROW_ON_ERROR), $json, $now, $now]);
    }
    analyzer_query($db, 'DELETE FROM analyzer_budget_snapshots WHERE user_id = ? AND month = ?', [$user, $month]);
}

/** Effective-dated targets: an explicit later change supersedes an earlier plan. */
function effective_budget(PDO $db, int $user, string $month): ?array
{
    if ($month < gmdate('Y-m')) {
        $snapshot = analyzer_query($db, 'SELECT * FROM analyzer_budget_snapshots WHERE user_id = ? AND month = ?', [$user, $month])->fetch();
        if ($snapshot) { return $snapshot + ['snapshot' => true, 'revision' => 1, 'updated_at' => $snapshot['created_at']]; }
    }
    $row = analyzer_query($db, 'SELECT * FROM analyzer_budgets WHERE user_id = ? AND month <= ? ORDER BY month DESC LIMIT 1', [$user, $month])->fetch();
    return $row ?: null;
}

/** Called only inside the user's save lock; never mutate history while rendering. */
function freeze_past_budgets(PDO $db, int $user, string $editing): void
{
    $rows = analyzer_query($db, 'SELECT * FROM analyzer_budgets WHERE user_id = ? ORDER BY month', [$user])->fetchAll();
    if (!$rows) { return; }
    $byMonth = array_column($rows, null, 'month'); $source = null;
    $frozen = analyzer_query($db, 'SELECT month FROM analyzer_budget_snapshots WHERE user_id = ?', [$user])->fetchAll(PDO::FETCH_COLUMN);
    $now = gmdate('Y-m-d H:i:s');
    for ($date = new DateTimeImmutable($rows[0]['month'] . '-01'); $date->format('Y-m') < gmdate('Y-m'); $date = $date->modify('+1 month')) {
        $key = $date->format('Y-m');
        if (isset($byMonth[$key])) { $source = $byMonth[$key]; continue; }
        if ($key === $editing || in_array($key, $frozen, true)) { continue; }
        analyzer_query($db, 'INSERT INTO analyzer_budget_snapshots (user_id, month, groups_json, targets_json, created_at) VALUES (?, ?, ?, ?, ?)',
            [$user, $key, $source['groups_json'], $source['targets_json'], $now]);
    }
}

function budget_balance(int $target, int $spent): array
{
    $available = $target - $spent;
    return ['target_cents' => $target, 'spent_cents' => $spent, 'available_cents' => $available,
        'status' => $available < 0 ? 'over' : ($available * 4 <= $target ? 'near' : 'within')];
}

function budget_progress(PDO $db, int $user, string $month, ?string $today = null): ?array
{
    $saved = effective_budget($db, $user, $month);
    if (!$saved) { return null; }
    $today ??= gmdate('Y-m-d');
    $current = $month === substr($today, 0, 7);
    $groups = json_decode($saved['groups_json'], true, 32, JSON_THROW_ON_ERROR);
    $targets = json_decode($saved['targets_json'], true, 32, JSON_THROW_ON_ERROR);
    $history = spending_history($db, $user);
    $rollover = budget_fund_balance($db, $user, $month, $history);
    $period = $history[$month] ?? ['expenses' => 0, 'categories' => [], 'daily' => []];
    if ($current) { $period = spending_through_day($period, (int) substr($today, 8, 2)); }
    $individual = array_column(array_filter($groups, fn($g) => $g['category_id'] !== null), 'category_id');
    $days = (int) (new DateTimeImmutable($month . '-01'))->format('t');
    $elapsed = (int) substr($today, 8, 2);
    $halfDay = (int) floor($days / 2);
    $halfway = spending_through_day($period, $halfDay);
    $daily = array_filter($period['daily'] ?? [], fn($d) => $d['expenses'] > 0);
    $lastDay = $daily ? max(array_keys($daily)) : 0;
    $purchaseCount = array_sum(array_column($daily, 'purchase_count'));
    $paceReady = $current && $elapsed >= 7 && $purchaseCount >= 3 && $lastDay >= $elapsed - 7;
    $items = [];
    foreach ($groups as $key => $group) {
        $members = array_filter($period['categories'], fn($c) => $key === 'misc' ? !in_array($c['id'], $individual, true) : $c['id'] === $group['category_id']);
        $spent = array_sum(array_column($members, 'amount'));
        $halfMembers = array_filter($halfway['categories'], fn($c) => $key === 'misc' ? !in_array($c['id'], $individual, true) : $c['id'] === $group['category_id']);
        $carry = $rollover && $group['category_id'] === $rollover['category_id'] ? $rollover['opening_cents'] : 0;
        $items[$key] = $group + ['monthly_target_cents' => (int) $targets[$key], 'rollover_cents' => $carry] + budget_balance((int) $targets[$key] + $carry, $spent) + ['members' => array_values($members), 'halfway_spent_cents' => array_sum(array_column($halfMembers, 'amount')),
            'projected_cents' => $paceReady ? (int) round($spent / $elapsed * $days) : null];
    }
    // Move the tracked travel drawdown into over-budget categories for display only.
    // Saved monthly targets stay intact, and category balances still sum to the total.
    if ($rollover && ($rollover['kind'] ?? '') !== 'vacation' && $rollover['reallocated_cents'] > 0) {
        $remaining = $rollover['reallocated_cents'];
        foreach ($items as $key => &$item) {
            if ($item['category_id'] === $rollover['category_id']) {
                $item = array_replace($item, budget_balance($item['target_cents'] - $rollover['reallocated_cents'], $item['spent_cents']));
                $item['travel_transfer_cents'] = -$rollover['reallocated_cents'];
            }
        } unset($item);
        foreach ($items as &$item) {
            if ($item['category_id'] === $rollover['category_id']) { continue; }
            $transfer = min($remaining, max(0, -$item['available_cents']));
            if ($transfer > 0) {
                $item = array_replace($item, budget_balance($item['target_cents'] + $transfer, $item['spent_cents']));
                $item['travel_transfer_cents'] = $transfer;
                $remaining -= $transfer;
            }
        } unset($item);
        // A cash limit below the saved targets can create a shortfall even before
        // a category reaches its target. Keep that reserve transfer visible too.
        if ($remaining > 0) {
            foreach ($items as &$item) {
                if ($item['category_id'] !== $rollover['category_id'] && $item['spent_cents'] > 0) {
                    $item = array_replace($item, budget_balance($item['target_cents'] + $remaining, $item['spent_cents']));
                    $item['travel_transfer_cents'] = ($item['travel_transfer_cents'] ?? 0) + $remaining;
                    break;
                }
            } unset($item);
        }
    }
    return ['month' => $month, 'source_month' => $saved['month'], 'current' => $current,
        'halfway_day' => $halfDay, 'halfway_spent_cents' => $halfway['expenses'],
        'complete' => month_is_complete($db, $user, $month), 'days_elapsed' => $elapsed, 'days_in_month' => $days,
        'groups' => $items, 'travel_fund' => $rollover, 'monthly_target_cents' => array_sum($targets), 'total' => budget_balance(array_sum($targets) + (($rollover['kind'] ?? '') === 'vacation' ? 0 : ($rollover['opening_cents'] ?? 0)), $period['expenses']) +
            ['projected_cents' => $paceReady ? (int) round($period['expenses'] / $elapsed * $days) : null]];
}
