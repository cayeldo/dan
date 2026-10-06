<?php
declare(strict_types=1);
require __DIR__ . '/budgets-test.php';
require dirname(__DIR__) . '/budget-recommendations.php';
$db->exec("INSERT INTO users (id, username) VALUES (30, 'recommendation_test'), (31, 'recommendation_other'), (32, 'recommendation_empty')");
$month = recommendation_current_month(); $date = new DateTimeImmutable($month . '-01');
$csv = "Date,Name,Amount\n";
for ($i = 6; $i >= 1; $i--) {
    $past = $date->modify("-$i months")->format('Y-m');
    foreach (['COSTCO' => -600, 'RED ROBIN' => -450, 'UBER *EATS' => -200, 'UTILITY TEST' => -150, 'HEALTH TEST' => -100, 'SUBSCRIPTION TEST' => -50] as $name => $amount) { $csv .= "$past-05,$name,$amount\n"; }
    $csv .= "$past-06,COSTCO,100\n$past-07,PAYMENT THANK YOU,500\n";
}
save_import($db, 30, pending_csv($csv, 0, 'Private recommendation card'));
$merchants = monthly_report($db, 30, $date->modify('-1 month')->format('Y-m'))['merchants'];
foreach ($merchants as $merchant) {
    foreach (['UTILITY' => 'Utilities', 'HEALTH' => 'Health & Pharmacy', 'SUBSCRIPTION' => 'Subscriptions'] as $match => $category) {
        if (str_contains(strtoupper($merchant['name']), $match)) { update_merchant($db, 30, (int) $merchant['id'], $merchant['name'], 0, $category); }
    }
}
$plan = budget_plan($db, 30, $month); $byName = array_flip(array_column($plan['groups'], 'name'));
$keys = []; foreach ($plan['groups'] as $key => $group) { $keys[$group['name']] = $key; }
$priors = []; $minimums = []; $amounts = [];
foreach ($plan['groups'] as $key => $group) { $priors[$key] = budget_default_priority($group['name']); $minimums[$key] = ''; $amounts[$key] = '200'; }
$priorMonth = $date->modify('-1 month')->format('Y-m');
$oldPlan = budget_plan($db, 30, $priorMonth);
save_budget($db, 30, $priorMonth, array_fill_keys(array_keys($oldPlan['groups']), '200'), budget_form_version($oldPlan));
$plan = budget_plan($db, 30, $month); $originalTargets = $plan['targets'];
function recommend_for_test(PDO $db, int $user, string $month, string $cash, string $reserve, array $priorities, array $minimums): array {
    $plan = budget_plan($db, $user, $month); $resources = budget_resources($db, $user, $month);
    generate_budget_recommendation($db, $user, $month, $cash, $reserve, $priorities, $minimums, budget_form_version($plan), budget_resources_version($resources));
    return saved_budget_recommendation($db, $user, budget_plan($db, $user, $month), budget_resources($db, $user, $month));
}
$record = recommend_for_test($db, 30, $month, '1250', '100', $priors, $minimums); $proposal = $record['proposal'];
check($proposal['baseline_cents'] === 155000 && $proposal['confirmed_count'] === 0 && count($proposal['months']) === 6, 'six observed months produce a baseline without counting refunds/payments or certifying completeness');
check($proposal['status'] === 'ready' && $proposal['recommended_total_cents'] === 115000, 'recommended allocations exactly fit cash after the reserved cushion');
check($proposal['targets'][$keys['Groceries']] === 60000 && $proposal['targets'][$keys['Utilities']] === 15000 && $proposal['targets'][$keys['Health & Pharmacy']] === 10000, 'essential categories retain their protected baselines');
check($proposal['groups'][$keys['Restaurants']]['cut_cents'] > 0 && $proposal['groups'][$keys['Food Delivery']]['cut_cents'] > 0, 'discretionary categories receive explicit below-history cuts');
check(budget_plan($db, 30, $month)['targets'] === $originalTargets, 'generating a recommendation never changes active targets');
check(saved_budget_recommendation($db, 31, budget_plan($db, 31, $month), null) === null, 'proposals and cash inputs remain private to their owner');
rejects(fn() => apply_budget_recommendation($db, 31, $month, $proposal['fingerprint']), 'another user cannot apply the owner’s recommendation');
rejects(fn() => generate_budget_recommendation($db, 30, $month, '100', '101', $priors, $minimums, budget_form_version($plan), budget_resources_version(null)), 'reserve cannot exceed available cash');
rejects(fn() => generate_budget_recommendation($db, 30, $month, '-1', '0', $priors, $minimums, budget_form_version($plan), budget_resources_version(null)), 'negative available cash is rejected');
rejects(fn() => generate_budget_recommendation($db, 30, $month, '1250', '100', $priors + ['foreign' => 'cut_first'], $minimums, budget_form_version($plan), budget_resources_version(budget_resources($db, 30, $month))), 'client cannot introduce foreign category keys');
rejects(fn() => generate_budget_recommendation($db, 30, $month, '1250', '100', $priors, $minimums, 'stale', budget_resources_version(budget_resources($db, 30, $month))), 'stale budget forms cannot generate proposals');
$config = ['enabled' => true, 'model' => 'gpt-6.1-sol']; $calls = 0;
$transport = function ($payload) use (&$calls) {
    $calls++; $facts = json_decode($payload['input'][0]['content'], true);
    check(!str_contains(json_encode($facts), 'Private recommendation card') && !isset($facts['budget_version']), 'AI receives only scoped planning facts, not card labels or internal version tokens');
    check($facts['recommended_total_cents'] <= $facts['capacity_cents'] && $payload['store'] === false, 'AI explains an already reconciled plan using a non-stored request');
    return ['summary' => 'Dining needs a smaller share to keep this plan within your cash.', 'categories' => array_fill_keys(array_keys($facts['groups']), 'Review purchases in this category against the proposed amount.'), 'next_steps' => ['Pick one flexible purchase to skip.', 'Check your remaining amounts each week.']];
};
check(run_budget_advice($db, $transport, $config) === 1 && $calls === 1, 'background worker saves the optional AI explanation');
$again = recommend_for_test($db, 30, $month, '1250', '100', $priors, $minimums);
check($again['fingerprint'] === $proposal['fingerprint'] && $again['ai_status'] === 'completed' && run_budget_advice($db, $transport, $config) === 0, 'identical rebuilds reuse the calculation and explanation without repeat paid calls');
apply_budget_recommendation($db, 30, $month, $proposal['fingerprint']);
check(budget_plan($db, 30, $month)['targets'] === $proposal['targets'], 'one explicit apply replaces all current category targets with the verified proposal');
check(budget_plan($db, 30, $date->modify('+1 month')->format('Y-m'))['targets'] === $proposal['targets'], 'adopted targets carry forward');
check(budget_plan($db, 30, $priorMonth)['targets'] === $originalTargets, 'adopting a recommendation preserves historical budgets');
rejects(fn() => apply_budget_recommendation($db, 30, $month, $proposal['fingerprint']), 'an applied proposal cannot be replayed');
$tooTight = recommend_for_test($db, 30, $month, '500', '0', $priors, $minimums)['proposal'];
check($tooTight['status'] === 'shortfall' && $tooTight['shortfall_cents'] === 40000, 'cash below protected costs produces an honest shortfall rather than fictional cuts');
rejects(fn() => apply_budget_recommendation($db, 30, $month, $tooTight['fingerprint']), 'infeasible recommendations cannot be adopted');
$roomy = recommend_for_test($db, 30, $month, '2500', '200', $priors, $minimums)['proposal'];
check($roomy['recommended_total_cents'] === 155000 && $roomy['unallocated_cents'] === 75000, 'surplus stays unallocated rather than inflating spending');
$card = (int) user_accounts($db, 30)[0]['id'];
save_import($db, 30, pending_csv("Date,Name,Amount\n$month-01,RED ROBIN,-700\n", $card));
rejects(fn() => apply_budget_recommendation($db, 30, $month, $roomy['fingerprint']), 'a new transaction invalidates a pending recommendation');
$alreadySpent = recommend_for_test($db, 30, $month, '1250', '100', $priors, $minimums)['proposal'];
check($alreadySpent['targets'][$keys['Restaurants']] >= 70000 && $alreadySpent['status'] === 'shortfall', 'current purchases form a hard floor even in a discretionary category');
$minima = $minimums; $minima[$keys['Groceries']] = '400';
$future = $date->modify('+1 month')->format('Y-m');
$adjusted = recommend_for_test($db, 30, $future, '1000', '0', $priors, $minima)['proposal'];
check($adjusted['status'] === 'ready' && $adjusted['targets'][$keys['Groceries']] >= 40000 && $adjusted['recommended_total_cents'] <= 100000, 'user minimum overrides are honored and future months do not inherit current actual spending');
$emptyPlan = budget_plan($db, 32, $month);
$empty = recommend_for_test($db, 32, $month, '1500', '0', ['misc' => 'protect'], ['misc' => ''])['proposal'];
check($empty['status'] === 'needs_history', 'no history or starting targets does not produce an invented allocation');
rejects(fn() => apply_budget_recommendation($db, 32, $month, $empty['fingerprint']), 'empty recommendations cannot replace a budget');
$targets = ['a' => 101, 'b' => 202, 'c' => 303]; $floors = ['a' => 100, 'b' => 100, 'c' => 100];
check(reduce_budget_tier($targets, $floors, array_keys($targets), 205) === 205 && array_sum($targets) === 401 && min($targets) >= 100, 'cent allocation reconciles exactly while preserving every floor');
// Explanations cannot change amounts or overwrite a newer request.
$failed = fn() => throw new RuntimeException('transport');
check(run_budget_advice($db, $failed, $config) === 0, 'model failure leaves the calculated recommendation usable');
$before = (int) $db->query("SELECT SUM(attempts) FROM analyzer_budget_recommendations")->fetchColumn();
while (run_budget_advice($db, $failed, $config)) {}
check((int) $db->query("SELECT SUM(attempts) FROM analyzer_budget_recommendations WHERE ai_status = 'failed'")->fetchColumn() >= 1, 'failed explanations are retained without automatic retry');
echo "All budget recommendation checks passed.\n";

// Irregular travel, outliers, missing months and no-history fallbacks.
$db->exec("INSERT INTO users (id, username) VALUES (33, 'irregular_test'), (34, 'initial_plan_test')");
$csv = "Date,Name,Amount\n";
for ($i = 6; $i >= 1; $i--) {
    $past = $date->modify("-$i months")->format('Y-m');
    $amount = $i === 1 ? 5000 : 100;
    $csv .= "$past-05,COSTCO,-$amount\n";
    if ($i === 3) { $csv .= "$past-06,TRAVEL TEST,-1200\n"; }
}
save_import($db, 33, pending_csv($csv));
$travelMonth = $date->modify('-3 months')->format('Y-m');
foreach (monthly_report($db, 33, $travelMonth)['merchants'] as $merchant) {
    if (str_contains(strtoupper($merchant['name']), 'TRAVEL')) { update_merchant($db, 33, (int) $merchant['id'], 'Travel test', 0, 'Travel'); }
}
$irregularPlan = budget_plan($db, 33, $month); $stats = recommendation_history($db, 33, $irregularPlan);
$travelStats = array_values(array_filter($stats['groups'], fn($g) => $g['name'] === 'Travel'))[0];
$groceryStats = array_values(array_filter($stats['groups'], fn($g) => $g['name'] === 'Groceries'))[0];
check($travelStats['baseline_cents'] === 20000 && $travelStats['irregular'], 'one trip becomes a monthly set-aside across available history');
check($groceryStats['smoothed'] && $groceryStats['baseline_cents'] < $groceryStats['average_cents'], 'one unusually large grocery month is smoothed instead of becoming the ongoing target');
$initial = budget_plan($db, 34, $month);
save_budget($db, 34, $month, ['misc' => '400'], budget_form_version($initial));
$initialRec = recommend_for_test($db, 34, $month, '500', '0', ['misc' => 'protect'], ['misc' => ''])['proposal'];
check($initialRec['status'] === 'ready' && $initialRec['targets']['misc'] === 40000 && !$initialRec['months'], 'without history an explicit starting budget is preserved rather than fabricated');
// A response generated for an older proposal must never replace a newer explanation.
$db->exec("UPDATE analyzer_budget_recommendations SET ai_status = 'unavailable' WHERE user_id <> 34");
$changedDuringCall = function ($payload) use ($db, $month) {
    $facts = json_decode($payload['input'][0]['content'], true);
    recommend_for_test($db, 34, $month, '600', '0', ['misc' => 'protect'], ['misc' => '']);
    return ['summary' => 'Old response', 'categories' => array_fill_keys(array_keys($facts['groups']), 'Old category explanation'), 'next_steps' => ['Old first step', 'Old second step']];
};
check(run_budget_advice($db, $changedDuringCall, $config) === 0 && analyzer_query($db, 'SELECT ai_json FROM analyzer_budget_recommendations WHERE user_id = ?', [34])->fetchColumn() === null, 'an in-flight response cannot overwrite a replacement proposal');
echo "All budget history and worker checks passed.\n";
