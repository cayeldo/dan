<?php
declare(strict_types=1);
require __DIR__ . '/analyzer-test.php';
require dirname(__DIR__) . '/monthly-reviews.php';
$db->exec("INSERT INTO users (id, username) VALUES (10, 'review_test'), (11, 'review_other'), (12, 'review_failure')");
$csv = "Date,Name,Amount\n1/5/25,COSTCO,-100\n2/5/25,COSTCO,-200\n3/5/25,COSTCO,-300\n4/5/25,COSTCO,-400\n5/5/25,COSTCO,-500\n6/5/25,COSTCO,-250\n6/6/25,RED ROBIN,-50\n6/7/25,COSTCO,25\n6/8/25,PAYMENT THANK YOU,400\n";
save_import($db, 10, pending_csv($csv, 0, 'Secret card number'));
$card = (int) user_accounts($db, 10)[0]['id'];
$config = ['enabled' => true, 'model' => 'gpt-6.1-sol', 'api_key' => 'test-only'];
$response = ['headline' => 'A steady month, with room to save', 'summary' => 'Your purchases were $300 this month.', 'bright_spot' => 'Your spending is close to your recent typical month.', 'opportunity' => 'Dining out offers some flexibility.', 'next_steps' => ['Try planning one meal at home.', 'Check your grocery list before shopping.']];
$calls = [];
$fake = function ($payload, $config) use (&$calls, $response) {
    $input = json_decode($payload['input'][0]['content'], true);
    $calls[] = $input['month'];
    check($payload['store'] === false && $payload['text']['format']['strict'] === true, 'reviews use non-stored structured responses');
    check($payload['model'] === $config['model'] && $payload['reasoning']['effort'] === 'medium' && $payload['max_output_tokens'] > 1200, 'reviews use the shared model with room for reasoning before the short final analysis');
    check(!str_contains(json_encode($payload), 'Secret card') && !str_contains(json_encode($input), 'COSTCO') && !isset($input['target_fingerprint']), 'only compact aggregates leave the app');
    return $response;
};
check(saved_month_review($db, 10, '2025-06') === null && run_month_review($db, 10, $fake, $config) === 0 && !$calls, 'past transactions alone never trigger or show an AI review');
rejects(fn() => confirm_complete_months($db, 10, gmdate('Y-m'), gmdate('Y-m')), 'current months cannot be certified complete');
rejects(fn() => confirm_complete_months($db, 10, '2025-13', '2025-13'), 'invalid month ranges are rejected');
rejects(fn() => confirm_complete_months($db, 11, '2025-01', '2025-06'), 'a user with no imported cards cannot confirm another user’s history');
check(confirm_complete_months($db, 10, '2025-01', '2025-06') === 6, 'explicit full-data confirmation covers a range');
$input = month_review_input($db, 10, '2025-06');
check($input['purchases_cents'] === 30000 && $input['refunds_cents'] === 2500 && $input['net_cents'] === 27500, 'review totals separate purchases, refunds and card payments');
check($input['baseline_count'] === 5 && $input['typical_purchases_cents'] === 30000 && $input['difference_from_typical_cents'] === 0, 'typical spending uses the median of earlier complete months');
check($input['budget'] === null, 'no imaginary budget is supplied');
check(run_month_review($db, 10, $fake, $config) === 1 && $calls === ['2025-06'], 'background worker generates the latest complete month first');
$stored = saved_month_review($db, 10, '2025-06');
check($stored['status'] === 'completed' && $stored['result'] === $response && !$stored['stale'], 'validated review and input snapshot are persisted');
for ($i = 0; $i < 10; $i++) { saved_month_review($db, 10, '2025-06'); }
check(count($calls) === 1, 'reading a saved review repeatedly never calls the model');
for ($i = 0; $i < 8; $i++) { run_month_review($db, 10, $fake, $config); }
check(count($calls) === 6 && count(array_unique($calls)) === 6, 'worker revisits generate each completed month only once');
check(month_review_input($db, 10, '2025-01')['typical_purchases_cents'] === null, 'first complete month does not invent typical spending');
check(saved_month_review($db, 11, '2025-06') === null, 'saved reviews remain private to their owner');
analyzer_query($db, 'UPDATE analyzer_month_closures SET complete = 0 WHERE user_id = ? AND month = ?', [10, '2025-06']);
check(saved_month_review($db, 10, '2025-06') === null, 'reopening a month hides its saved message');
confirm_complete_months($db, 10, '2025-06', '2025-06');
check(saved_month_review($db, 10, '2025-06')['result'] === $response && run_month_review($db, 10, $fake, $config) === 0, 'reconfirming reuses the saved review without charging again');
save_import($db, 10, pending_csv("Date,Name,Amount\n6/20/25,COSTCO,-10\n", $card));
check(!month_is_complete($db, 10, '2025-06') && saved_month_review($db, 10, '2025-06') === null, 'late-arriving transactions invalidate completeness and hide the review');
confirm_complete_months($db, 10, '2025-06', '2025-06');
check(saved_month_review($db, 10, '2025-06')['stale'] && run_month_review($db, 10, $fake, $config) === 0, 'changed data leaves an explicitly dated original review and does not regenerate');
$merchant = monthly_report($db, 10, '2025-05')['merchants']; $merchant = array_values($merchant)[0];
update_merchant($db, 10, (int) $merchant['id'], $merchant['name'], 0, 'Updated category');
check(saved_month_review($db, 10, '2025-05')['stale'], 'category edits are detected without invalidating coverage or silently replacing the review');
// Refund-only and zero-activity months are valid only when explicitly confirmed.
confirm_complete_months($db, 10, '2025-07', '2025-08');
$zero = month_review_input($db, 10, '2025-08');
check($zero['purchases_cents'] === 0 && $zero['baseline_count'] === 6, 'confirmed zero months count and baseline is capped at six');

save_import($db, 12, pending_csv("Date,Name,Amount\n1/5/25,COSTCO,-40\n", 0, 'Failure test'));
confirm_complete_months($db, 12, '2025-01', '2025-01');
$failedCalls = 0;
$fail = function () use (&$failedCalls) { $failedCalls++; throw new RuntimeException('transport'); };
check(run_month_review($db, 12, $fail, $config) === 0 && run_month_review($db, 12, $fail, $config) === 0 && $failedCalls === 1, 'uncertain network failures do not cause automatic paid retries');
check(saved_month_review($db, 12, '2025-01')['status'] === 'failed', 'failure remains visible without fake analysis');
analyzer_query($db, "UPDATE analyzer_month_reviews SET status = 'pending' WHERE user_id = 12", []);
$nestedCalls = 0;
$nested = function () use ($db, $config, $response, &$nestedCalls) {
    $nestedCalls++;
    check(run_month_review($db, 12, function () { throw new RuntimeException('should_not_call'); }, $config) === 0, 'a competing worker cannot claim a processing review');
    return $response;
};
check(run_month_review($db, 12, $nested, $config) === 1 && $nestedCalls === 1, 'explicit failure retry can save the result once');
try { validate_month_review($response + []); validate_month_review(['headline' => 'Incomplete']); throw new Exception('invalid result accepted'); }
catch (RuntimeException $error) { check($error->getMessage() === 'invalid_response', 'malformed output is never displayed as a completed review'); }
echo "All monthly review checks passed.\n";

// New completed reviews include saved targets and retrospective pacing; old reviews stay saved.
$plan = budget_plan($db, 10, '2025-06');
$amounts = array_fill_keys(array_keys($plan['groups']), '200');
save_budget($db, 10, '2025-06', $amounts, budget_form_version($plan));
$input = month_review_input($db, 10, '2025-06');
check($input['budget']['total']['spent_cents'] === month_review_facts($db, 10, '2025-06')['purchases_cents'], 'AI budget facts use the same purchases as the monthly analysis');
check($input['budget']['total']['available_cents'] === $input['budget']['total']['target_cents'] - $input['budget']['total']['spent_cents'], 'AI receives calculated overall budget variance');
check($input['budget']['pace_checkpoint']['day'] === 15 && isset($input['budget']['categories'][0]['halfway_spent_cents']), 'final review receives retrospective halfway pace for the overall budget and categories');
$beforeAttempts = analyzer_query($db, "SELECT attempts FROM analyzer_month_reviews WHERE user_id = ? AND month = ?", [10, '2025-06'])->fetchColumn();
check(!saved_month_review($db, 10, '2025-06')['budget_included'], 'old saved reviews honestly disclose that no budget was included');
run_month_review($db, 10, $fake, $config);
check(analyzer_query($db, "SELECT attempts FROM analyzer_month_reviews WHERE user_id = ? AND month = ?", [10, '2025-06'])->fetchColumn() === $beforeAttempts, 'adding a budget never regenerates an existing review');

$db->exec("INSERT INTO users (id, username) VALUES (14, 'budget_review_test')");
save_import($db, 14, pending_csv("Date,Name,Amount\n1/5/25,COSTCO,-40\n1/22/25,COSTCO,-10\n", 0, 'Review test card'));
$plan = budget_plan($db, 14, '2025-01');
save_budget($db, 14, '2025-01', ['misc' => '100'], budget_form_version($plan));
confirm_complete_months($db, 14, '2025-01', '2025-01');
$newReviewCalls = 0;
$budgetFake = function ($payload) use (&$newReviewCalls, $response) {
    $newReviewCalls++;
    $facts = json_decode($payload['input'][0]['content'], true);
    check($facts['budget']['total']['available_cents'] === 5000 && $facts['budget']['pace_checkpoint']['spent_cents'] === 4000, 'worker sends actual budget results and halfway spending to the model');
    return $response;
};
check(run_month_review($db, 14, $budgetFake, $config) === 1 && saved_month_review($db, 14, '2025-01')['budget_included'], 'a new saved review records that it included the budget');
$plan = budget_plan($db, 14, '2025-01');
save_budget($db, 14, '2025-01', ['misc' => '120'], budget_form_version($plan));
check(saved_month_review($db, 14, '2025-01')['stale'], 'changed targets flag a saved budget-aware review as original');
check(run_month_review($db, 14, $budgetFake, $config) === 0 && $newReviewCalls === 1, 'budget adjustments cannot trigger repeat AI generation');
