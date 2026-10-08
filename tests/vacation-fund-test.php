<?php
declare(strict_types=1);
require __DIR__ . '/travel-fund-test.php';
require dirname(__DIR__) . '/overview-insights.php';

$normal = vacation_fund_month(40000, 20000, 105000, 105000);
check($normal['available_cents'] === 60000 && $normal['reallocated_cents'] === 0, '$50 Travel spending within a $1,050 expense budget leaves the $200 savings contribution intact');
$short = vacation_fund_month(40000, 20000, 130000, 105000);
check($short['available_cents'] === 35000 && $short['reserve_used_elsewhere_cents'] === 5000, 'net monthly shortages use contributions before accumulated savings');
$exhausted = vacation_fund_month(10000, 20000, 150000, 105000);
check($exhausted['available_cents'] === 0 && $exhausted['unfunded_cents'] === 15000, 'Vacation Fund never goes negative or conceals an uncovered shortage');
$cash = vacation_fund_month(40000, 20000, 105000, 105000, 110000);
check($cash['contribution_cents'] === 20000 && $cash['available_cents'] === 60000 && $cash['reallocated_cents'] === 0 && $cash['planning_gap_cents'] === 15000, 'a cash planning gap cannot reduce savings while spending is within the expense budget');
// Reproduce the reported $500 -> $496 bug without relying on the user's cash inputs.
$october = vacation_fund_month(0, 50000, 73944, 200400, 250000);
check($october['contribution_cents'] === 50000 && $october['available_cents'] === 50000 && $october['reallocated_cents'] === 0 && $october['planning_gap_cents'] === 400, '$739.44 spent below the overall budget keeps all $500 despite a $4 planning gap');
$atBudget = vacation_fund_month(0, 50000, 200400, 200400, 250000);
$aboveBudget = vacation_fund_month(0, 50000, 200401, 200400, 250000);
check($atBudget['reallocated_cents'] === 0 && $aboveBudget['reallocated_cents'] === 1 && $aboveBudget['available_cents'] === 49999, 'only the first cent beyond the combined expense budget draws from savings');
$noCash = vacation_fund_month(10000, 50000, 0, 200400, 0);
check($noCash['available_cents'] === 60000 && $noCash['planning_gap_cents'] === 250400 && $noCash['reallocated_cents'] === 0, 'cash input mismatches remain planning warnings and do not masquerade as spending');
$surplus = vacation_fund_month(40000, 20000, 120000, 105000, 150000);
check($surplus['available_cents'] === 60000 && $surplus['reallocated_cents'] === 0, 'unallocated monthly cash covers expenses before savings are drawn');

$db->exec("INSERT INTO users (id, username) VALUES (37, 'vacation_test'), (38, 'vacation_other')");
$initial = budget_plan($db, 37, $month);
save_budget($db, 37, $month, ['misc' => '1000'], budget_form_version($initial));
$initial = budget_plan($db, 37, $month);
$version = vacation_fund_version($db, 37);
rejects(fn() => save_vacation_fund($db, 37, $priorMonth, '200', '400', budget_form_version($initial), $version), 'Vacation Fund cannot invent retroactive savings');
rejects(fn() => save_vacation_fund($db, 37, $month, '-1', '400', budget_form_version($initial), $version), 'negative contributions are rejected');
rejects(fn() => save_vacation_fund($db, 37, $month, '200', '-1', budget_form_version($initial), $version), 'negative opening savings are rejected');
$futurePlan = budget_plan($db, 37, $future);
save_budget($db, 37, $future, ['misc' => '900'], budget_form_version($futurePlan));
save_vacation_fund($db, 37, $month, '200', '400', budget_form_version($initial), $version);
$plan = budget_plan($db, 37, $month);
$travelKey = array_key_first($plan['groups']); $travelId = $plan['groups'][$travelKey]['category_id'];
check($plan['groups'][$travelKey]['name'] === 'Travel' && $plan['targets'][$travelKey] === 0 && $plan['targets']['misc'] === 100000, 'Vacation Fund has no category and Travel is a distinct ordinary expense target');
check(count(budget_plan($db, 37, $future)['groups']) === 2, 'already saved future plans keep Travel itemized');
save_budget($db, 37, $month, [$travelKey => '50', 'misc' => '1000'], budget_form_version($plan));
$plan = budget_plan($db, 37, $month);
rejects(fn() => save_vacation_fund($db, 37, $month, '200', '400', budget_form_version($plan), $version), 'stale forms cannot start or overwrite a fund again');
check(vacation_fund_balance($db, 38, $month) === null, 'Vacation Fund balances are private to their owner');
save_import($db, 37, pending_csv("Date,Name,Amount\n$month-01,COSTCO,-1000\n$month-01,UBER *TRIP,-50\n"));
foreach (monthly_report($db, 37, $month)['merchants'] as $merchant) {
    if (str_contains($merchant['name'], 'Uber')) { update_merchant($db, 37, (int) $merchant['id'], 'Uber Trip', $travelId, ''); }
}
$balance = budget_fund_balance($db, 37, $month);
check($balance['kind'] === 'vacation' && $balance['available_cents'] === 60000 && $balance['spent_cents'] === 0, 'an imported Uber assigned to Travel does not directly spend Vacation Fund savings');
$insights = overview_insights($db, 37, spending_history($db, 37));
$fundRows = array_values(array_filter($insights['rows'], fn($row) => $row['visual'] === 'fund'));
check(count($fundRows) === 1 && $fundRows[0]['name'] === 'Vacation Fund' && str_contains(insight_explanation($fundRows[0], $month), 'Travel purchases stay in the expense budget'), 'overview names Vacation Fund separately and explains the balance');
$progress = budget_progress($db, 37, $month);
check($progress['groups'][$travelKey]['spent_cents'] === 5000 && $progress['groups'][$travelKey]['target_cents'] === 5000 && $progress['total']['target_cents'] === 105000, 'category and total expense targets exclude all vacation savings');
check(array_sum(array_column($progress['groups'], 'available_cents')) === $progress['total']['available_cents'], 'expense totals reconcile independently of the fund');
check(vacation_fund_balance($db, 37, $future)['opening_cents'] === 40000, 'unfinished monthly contributions cannot become future accumulated savings');
$offsetPlan = budget_plan($db, 37, $month);
save_budget($db, 37, $month, [$travelKey => '25', 'misc' => '1025'], budget_form_version($offsetPlan));
$offsetProgress = budget_progress($db, 37, $month);
check($offsetProgress['groups'][$travelKey]['available_cents'] === -2500 && $offsetProgress['groups']['misc']['available_cents'] === 2500 && vacation_fund_balance($db, 37, $month)['reallocated_cents'] === 0, 'unused Misc budget fully offsets a Travel overage before Vacation Fund is touched');
save_budget($db, 37, $month, [$travelKey => '50', 'misc' => '1000'], budget_form_version(budget_plan($db, 37, $month)));
$priorities = [$travelKey => 'cut_first', 'misc' => 'protect']; $minimums = [$travelKey => '', 'misc' => ''];
$rec = recommend_for_test($db, 37, $month, '1250', '0', $priorities, $minimums)['proposal'];
check($rec['capacity_cents'] === 105000 && $rec['vacation_contribution_cents'] === 20000 && $rec['recommended_total_cents'] === 105000, 'recommendation reserves the separate contribution exactly once');
check($rec['groups'][$travelKey]['rollover_cents'] === 0 && $rec['groups'][$travelKey]['floor_cents'] === 5000, 'accumulated vacation savings do not inflate or erase the Travel expense target');
$card = (int) user_accounts($db, 37)[0]['id'];
save_import($db, 37, pending_csv("Date,Name,Amount\n$month-02,COSTCO,-250\n", $card));
$balance = vacation_fund_balance($db, 37, $month);
check($balance['reallocated_cents'] === 25000 && $balance['available_cents'] === 35000 && $balance['provisional'], 'current-month shortage is a provisional draw against independent savings');
check(vacation_fund_balance($db, 37, $future)['opening_cents'] === 35000, 'future plans cannot reuse already needed accumulated savings');
$oldVersion = vacation_fund_version($db, 37);
save_vacation_fund($db, 37, $month, '0', '9999', budget_form_version(budget_plan($db, 37, $month)), $oldVersion);
check((int) vacation_fund_setting($db, 37)['opening_cents'] === 40000 && vacation_fund_balance($db, 37, $month)['planned_contribution_cents'] === 0, 'pausing contributions preserves opening savings and ignores replacement opening inputs');
rejects(fn() => apply_budget_recommendation($db, 37, $month, $rec['fingerprint']), 'changing the fund invalidates an older budget recommendation');
rejects(fn() => save_vacation_fund($db, 37, $month, '100', '0', budget_form_version(budget_plan($db, 37, $month)), $oldVersion), 'concurrent contribution changes require a fresh form');

// Reconstruct ended months to verify carryover, late imports, and missing history.
$db->exec('DELETE FROM analyzer_budget_resources WHERE user_id = 37');
$db->exec('DELETE FROM analyzer_transactions WHERE user_id = 37');
$db->exec('DELETE FROM analyzer_budget_snapshots WHERE user_id = 37');
analyzer_query($db, 'DELETE FROM analyzer_budgets WHERE user_id = 37 AND month = ?', [$future]);
analyzer_query($db, 'UPDATE analyzer_budgets SET month = ? WHERE user_id = 37', [$start]);
analyzer_query($db, 'UPDATE analyzer_vacation_funds SET start_month = ?, opening_cents = 0 WHERE user_id = 37', [$start]);
analyzer_query($db, 'UPDATE analyzer_vacation_contributions SET month = ?, contribution_cents = 20000 WHERE user_id = 37', [$start]);
save_import($db, 37, pending_csv("Date,Name,Amount\n$start-01,COSTCO,-1050\n$second-01,COSTCO,-1150\n$third-01,COSTCO,-1050\n$month-01,UBER *TRIP,-50\n", $card));
$balance = vacation_fund_balance($db, 37, $month);
check($balance['opening_cents'] === 50000 && $balance['available_cents'] === 70000 && $balance['ledger'][1]['reallocated_cents'] === 10000, 'three observed months accumulate contributions less only net shortages');
save_import($db, 37, pending_csv("Date,Name,Amount\n$start-02,COSTCO,-200\n", $card));
check(vacation_fund_balance($db, 37, $month)['opening_cents'] === 30000, 'late imported shortages recalculate later vacation balances');
analyzer_query($db, 'DELETE FROM analyzer_transactions WHERE user_id = 37 AND transaction_date LIKE ?', [$third . '%']);
check(vacation_fund_balance($db, 37, $month)['opening_cents'] === 10000, 'missing months cannot create vacation savings');
confirm_complete_months($db, 37, $third, $third);
check(vacation_fund_balance($db, 37, $month)['opening_cents'] === 30000, 'confirmed empty months can accumulate their funded contribution');
save_vacation_fund($db, 37, $month, '100', '', budget_form_version(budget_plan($db, 37, $month)), vacation_fund_version($db, 37));
check(vacation_fund_balance($db, 37, $month)['opening_cents'] === 30000 && vacation_fund_balance($db, 37, $month)['contribution_cents'] === 10000, 'changing this month’s contribution leaves historical contributions intact');
$rec = recommend_for_test($db, 37, $month, '50', '0', $priorities, $minimums)['proposal'];
check($rec['status'] === 'shortfall' && $rec['shortfall_cents'] >= 5000, 'a contribution exceeding all monthly cash cannot produce an applicable recommendation');

save_vacation_fund($db, 37, $future, '75.25', '', budget_form_version(budget_plan($db, 37, $future)), vacation_fund_version($db, 37));
check(vacation_fund_balance($db, 37, $month)['planned_contribution_cents'] === 10000 && vacation_fund_balance($db, 37, $future)['planned_contribution_cents'] === 7525, 'future contribution changes keep exact cents and leave this month unchanged');
$next = (new DateTimeImmutable($future . '-01'))->modify('+1 month')->format('Y-m');
check(vacation_fund_balance($db, 37, $next)['planned_contribution_cents'] === 7525, 'new contributions carry forward until another explicit monthly change');

// Existing travel rollover stops at the explicit switch without rewriting past balances.
$legacy = travel_fund_balance($db, 35, $month);
$legacyPast = budget_fund_balance($db, 35, $start);
$legacyPlan = budget_plan($db, 35, $month);
save_vacation_fund($db, 35, $month, '100', number_format($legacy['opening_cents'] / 100, 2, '.', ''), budget_form_version($legacyPlan), vacation_fund_version($db, 35));
check(budget_fund_balance($db, 35, $start) === $legacyPast, 'switching from legacy travel rollover preserves historical results');
check(budget_fund_balance($db, 35, $month)['opening_cents'] === $legacy['opening_cents'] && budget_fund_balance($db, 35, $month)['kind'] === 'vacation', 'switch replaces rather than adds to legacy savings');
echo "All Vacation Fund checks passed.\n";
