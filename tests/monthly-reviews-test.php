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
    check(!str_contains(json_encode($payload), 'Secret card') && !isset($input['transactions']) && !isset($input['target_fingerprint']), 'only compact aggregates leave the app');
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

// Automatic scheduling uses Eastern time, catches up after the 5th, and never certifies coverage.
$db->exec("INSERT INTO users (id, username) VALUES (15, 'scheduled_review'), (16, 'scheduled_empty'), (17, 'scheduled_reopened')");
save_import($db, 15, pending_csv("Date,Name,Amount\n9/20/25,COSTCO,-40\n", 0, 'Scheduled card'));
save_import($db, 17, pending_csv("Date,Name,Amount\n9/20/25,COSTCO,-20\n", 0, 'Reopened card'));
confirm_complete_months($db, 17, '2025-09', '2025-09');
analyzer_query($db, 'UPDATE analyzer_month_closures SET complete = 0 WHERE user_id = ? AND month = ?', [17, '2025-09']);
$beforeFifth = new DateTimeImmutable('2025-10-05T03:59:59Z');
$onFifth = new DateTimeImmutable('2025-10-05T04:00:00Z');
check(queue_scheduled_month_reviews($db, 15, $beforeFifth) === 0, 'automatic reviews wait until the 5th in Eastern time');
check(queue_scheduled_month_reviews($db, 15, $onFifth) === 1, 'the 5th queues the preceding calendar month without confirmation');
check(queue_scheduled_month_reviews($db, 15, $onFifth) === 0, 'repeated scheduler runs cannot duplicate a month');
check(queue_scheduled_month_reviews($db, 16, $onFifth) === 0 && queue_scheduled_month_reviews($db, 17, $onFifth) === 0, 'empty accounts and explicitly reopened months are not auto-queued');
check(!month_is_complete($db, 15, '2025-09') && month_review_is_ready($db, 15, '2025-09'), 'scheduled snapshots are reviewable without certifying complete bank coverage');
$autoInput = month_review_input($db, 15, '2025-09');
check($autoInput['coverage'] === 'imported_transactions_only' && $autoInput['purchases_cents'] === 4000, 'automatic review receives honest coverage and aggregate facts');
check(run_month_review($db, 15, fn() => $response, $config) === 1, 'the existing worker generates the scheduled snapshot');
check(run_month_review($db, 15, fn() => throw new RuntimeException('duplicate_call'), $config) === 0, 'scheduled completed reviews are never generated twice');
$scheduled = saved_month_review($db, 15, '2025-09');
check($scheduled['coverage'] === 'imported_transactions_only' && !$scheduled['stale'] && (int) $scheduled['prompt_version'] === 3, 'saved automatic review retains coverage and prompt version');
$autoCard = (int) user_accounts($db, 15)[0]['id'];
save_import($db, 15, pending_csv("Date,Name,Amount\n9/25/25,COSTCO,-10\n", $autoCard));
check(saved_month_review($db, 15, '2025-09')['stale'], 'late imports keep an automatic review visible with a changed-data notice');
save_import($db, 15, pending_csv("Date,Name,Amount\n10/20/25,COSTCO,-20\n", $autoCard));
check(queue_scheduled_month_reviews($db, 15, new DateTimeImmutable('2025-11-08T12:00:00-05:00')) === 1, 'a worker returning after the 5th catches up');
check(month_review_input($db, 15, '2025-10')['baseline_count'] === 0, 'unconfirmed snapshots do not become complete comparison history');
save_import($db, 15, pending_csv("Date,Name,Amount\n12/20/25,COSTCO,-20\n", $autoCard));
check(queue_scheduled_month_reviews($db, 15, new DateTimeImmutable('2026-01-05T00:00:00-05:00')) === 1 && month_review_is_ready($db, 15, '2025-12'), 'January schedules December of the previous year');
check(month_review_teaser('Purchases were $50.25. Dining led the month. A third sentence.') === 'Purchases were $50.25. Dining led the month.', 'teasers retain decimal amounts and show at most two sentences');
check(mb_strlen(month_review_teaser(str_repeat('A useful observation ', 30))) <= 240, 'older long reviews have bounded previews without regeneration');
echo "All automatic review and teaser checks passed.\n";

// A frequent small merchant must survive alongside much larger one-off purchases.
$db->exec("INSERT INTO users (id, username) VALUES (18, 'merchant_review'), (19, 'merchant_other')");
foreach ([5, 7] as $price) {
    $csv = "Date,Name,Memo,Amount\n";
    for ($day = 1; $day <= 9; $day++) { $csv .= "8/$day/25,TATTE BAKERY,Private memo,-$price\n"; }
    $csv .= "8/10/25,TATTE BAKERY,Private refund,5\n8/11/25,PAYMENT THANK YOU,Private payment,100\n9/1/25,TATTE BAKERY,Other month,-20\n";
    save_import($db, 18, pending_csv($csv, 0, "Private card $price"));
}
$merchants = monthly_report($db, 18, '2025-08')['merchants'];
$tatte = array_values($merchants)[0];
update_merchant($db, 18, (int) $tatte['id'], 'Tatte Bakery', 0, 'Restaurants');
$csv = "Date,Name,Amount\n";
for ($i = 1; $i <= 22; $i++) { $csv .= "8/20/25,Large purchase merchant $i,-1000\n"; }
save_import($db, 18, pending_csv($csv, 0, 'Large purchase card'));
save_import($db, 19, pending_csv("Date,Name,Amount\n8/1/25,Other tenant merchant,-8000\n"));
$merchantFacts = month_review_merchant_facts($db, 18, '2025-08');
$tatteFacts = $merchantFacts['items'][0];
check($tatteFacts['name'] === 'Tatte Bakery' && $tatteFacts['purchase_count'] === 18 && $tatteFacts['purchase_days'] === 9, '18 purchases across two cards are counted as transactions, with distinct dates reported separately');
check($tatteFacts['purchases_cents'] === 10800 && $tatteFacts['average_purchase_cents'] === 600 && $tatteFacts['category'] === 'Restaurants', 'merchant totals and average exclude refunds, payments and other months');
check(count($merchantFacts['items']) <= 20 && $merchantFacts['merchants_omitted'] > 0 && count(array_filter($merchantFacts['items'], fn($m) => $m['purchases_cents'] === 100000)) >= 10, 'bounded selection includes frequent small purchases and the largest merchants');
check(!str_contains(json_encode($merchantFacts), 'Other tenant'), 'merchant summaries are scoped to the review owner');
confirm_complete_months($db, 18, '2025-08', '2025-08');
analyzer_query($db, "UPDATE analyzer_ai_jobs SET status = 'unresolved' WHERE user_id = ?", [18]);
$merchantTransport = function ($payload) use ($response) {
    $input = json_decode($payload['input'][0]['content'], true);
    check($input['merchants']['items'][0]['name'] === 'Tatte Bakery' && $input['merchants']['items'][0]['purchase_count'] === 18, 'worker actually sends the noteworthy merchant pattern to the model');
    check(!str_contains(json_encode($input), 'Private') && !str_contains(json_encode($input), '2025-08-01') && !isset($input['merchants']['items'][0]['id']), 'merchant context excludes raw memos, card labels, transaction dates and internal IDs');
    return $response;
};
check(run_month_review($db, 18, $merchantTransport, $config) === 1 && !saved_month_review($db, 18, '2025-08')['stale'], 'merchant-aware review is saved and remains fresh');
update_merchant($db, 18, (int) $tatte['id'], 'Tatte Bakery 212-555-0199 test@example.com', 0, 'Restaurants');
$redacted = month_review_merchant_facts($db, 18, '2025-08')['items'][0]['name'];
check($redacted === 'Tatte Bakery', 'phone-like numbers and email addresses are removed from review merchant labels');
update_merchant($db, 18, (int) $tatte['id'], 'Renamed bakery', 0, 'Restaurants');
check(saved_month_review($db, 18, '2025-08')['stale'], 'merchant renames flag new saved reviews as changed without regenerating them');
$row = analyzer_query($db, 'SELECT input_json FROM analyzer_month_reviews WHERE user_id = ? AND month = ?', [18, '2025-08'])->fetchColumn();
$legacySnapshot = json_decode($row, true); unset($legacySnapshot['merchants']);
analyzer_query($db, 'UPDATE analyzer_month_reviews SET input_json = ? WHERE user_id = ? AND month = ?', [json_encode($legacySnapshot), 18, '2025-08']);
check(!saved_month_review($db, 18, '2025-08')['stale'], 'legacy reviews without merchant context still load without false stale notices');
check(month_review_merchant_facts($db, 19, '2025-07')['items'] === [], 'empty months do not invent merchant activity');
echo "All merchant context checks passed.\n";
