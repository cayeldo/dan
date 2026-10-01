<?php
declare(strict_types=1);

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
    $saved = analyzer_query($db, 'SELECT * FROM analyzer_budgets WHERE user_id = ? AND month = ?', [$user, $month])->fetch();
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
        'misc_categories' => array_column($miscCategories, 'name'), 'revision' => (int) ($saved['revision'] ?? 0), 'updated_at' => $saved['updated_at'] ?? null];
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
    return hash('sha256', json_encode([$plan['month'], $plan['revision'], $plan['groups']], JSON_THROW_ON_ERROR));
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
        $json = json_encode($targets, JSON_THROW_ON_ERROR); $now = gmdate('Y-m-d H:i:s');
        if ($plan['revision']) {
            analyzer_query($db, 'UPDATE analyzer_budgets SET targets_json = ?, revision = revision + 1, updated_at = ? WHERE user_id = ? AND month = ?', [$json, $now, $user, $month]);
        } else {
            analyzer_query($db, 'INSERT INTO analyzer_budgets (user_id, month, groups_json, targets_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$user, $month, json_encode($plan['groups'], JSON_THROW_ON_ERROR), $json, $now, $now]);
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}
