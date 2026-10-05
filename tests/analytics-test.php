<?php
declare(strict_types=1);
require __DIR__ . '/analyzer-test.php';
require dirname(__DIR__) . '/analytics.php';
$db->exec("INSERT INTO users (id, username) VALUES (3, 'analytics_test'), (4, 'empty_analytics_test')");
$csv = "Date,Name,Amount\n12/1/25,COSTCO,-100\n12/2/25,UBER *EATS,-100\n12/3/25,RED ROBIN,-100\n12/4/25,PAYMENT THANK YOU,300\n1/1/26,COSTCO,-150\n1/2/26,UBER *EATS,-50\n1/3/26,SPOTIFY,-100\n1/4/26,COSTCO,20\n3/1/26,COSTCO,-50\n4/1/26,COSTCO,10\n";
save_import($db, 3, pending_csv($csv, 0, 'Analytics card'));
$account = (int) user_accounts($db, 3)[0]['id'];
$history = spending_history($db, 3, $account);
check(array_keys($history) === ['2025-12', '2026-01', '2026-03', '2026-04'], 'history is chronological across year boundaries');
foreach ($history as $month => $period) {
    $report = monthly_report($db, 3, $month, $account);
    check($period['expenses'] === $report['expenses'] && $period['refunds'] === $report['refunds'] && $period['net'] === $report['net'], 'aggregate matches audited report for ' . $month);
}
$c = spending_comparison($history, '2026-01');
check($c['previous_month'] === '2025-12' && $c['available'] && $c['total']['delta'] === 0, 'January compares with December and excludes refunds from purchase changes');
$byName = array_column($c['categories'], null, 'name');
check($byName['Groceries']['delta'] === 5000 && abs($byName['Groceries']['percent'] - 50) < .001, 'category dollar and relative spending changes calculated correctly');
check(abs($byName['Groceries']['share'] - 50) < .001 && abs($byName['Groceries']['share_delta'] - (50 - 100 / 3)) < .001, 'category bill share uses each month’s own purchase total');
check($byName['Restaurants']['current'] === 0 && abs($byName['Restaurants']['percent'] + 100) < .001, 'disappearing categories show a 100 percent decrease');
check($byName['Uncategorized']['previous'] === 0 && $byName['Uncategorized']['percent'] === null, 'new spending avoids division by zero or fabricated growth percentage');
check(!spending_comparison($history, '2026-03')['available'], 'missing February is not replaced with January or zero');
$zero = spending_comparison($history, '2026-04');
check($zero['available'] && $zero['current']['expenses'] === 0 && $zero['categories'][0]['share'] === null, 'credit-only months have zero purchases and undefined share');
$dash = spending_dashboard($history);
check(array_key_exists('2026-02', $dash['series']) && $dash['series']['2026-02'] === null, 'line chart preserves missing months as gaps');
check($dash['expenses'] === 65000 && $dash['refunds'] === 3000 && array_sum(array_column($dash['categories'], 'amount')) === 65000, 'cumulative chart reconciles exactly and separates refunds');
$latestTwo = array_slice($history, 0, 2, true);
$movers = spending_dashboard($latestTwo)['movers'];
check(count($movers) === 4 && count(array_filter($movers, fn($m) => $m['delta'] > 0)) === 2 && count(array_filter($movers, fn($m) => $m['delta'] < 0)) === 2, 'pronounced changes include both increases and decreases');
save_import($db, 3, pending_csv("Date,Name,Amount\n1/5/26,COSTCO,-400\n", 0, 'Second analytics card'));
check(spending_history($db, 3)['2026-01']['expenses'] === 70000 && spending_history($db, 3, $account)['2026-01']['expenses'] === 30000, 'selected-card comparisons exclude other cards');
check(spending_history($db, 4) === [] && spending_history($db, 4, $account) === [], 'dashboard and foreign card filters preserve user privacy');
check(spending_dashboard([])['latest'] === null && spending_dashboard([])['series'] === [], 'new users have an honest empty dashboard');
$long = [];
for ($i = 0; $i < 15; $i++) { $key = (new DateTimeImmutable('2024-01-01'))->modify("+$i months")->format('Y-m'); $long[$key] = $history['2026-01']; }
check(count(spending_dashboard($long)['series']) === 12 && spending_dashboard($long)['expenses'] === 450000, 'line chart caps at 12 months while cumulative chart retains all history');
check(signed_percent(-.00001) === '0.0%' && signed_percent(null) === '—', 'percentage labels avoid negative zero and undefined percentages');
check($dash['average'] === 16250 && count($dash['pastMonths']) === 4, 'average includes zero-purchase months and excludes missing months');
$currentHistory = [gmdate('Y-m', strtotime('first day of last month')) => $history['2026-01'], gmdate('Y-m') => $history['2026-03']];
check(spending_dashboard($currentHistory)['average'] === 30000, 'average excludes the in-progress current month');
check(substr_count(donut_slice_path(0, 1), ' A ') === 4 && !str_contains(donut_slice_path(.25, .5), 'NAN'), 'donut geometry supports one-category and partial wedges');
echo "All spending analytics checks passed.\n";

$partial = spending_comparison($history, '2026-01', '2026-01-02');
check($partial['current']['expenses'] === 20000 && $partial['previous']['expenses'] === 20000, 'in-progress comparison uses the same calendar cutoff on both months');
check($partial['current_day'] === 2 && $partial['previous_day'] === 2 && $partial['partial'], 'comparison exposes its actual cutoff days');
save_import($db, 3, pending_csv("Date,Name,Amount\n2/28/26,COSTCO,-10\n3/31/26,COSTCO,-20\n", $account));
$short = spending_comparison(spending_history($db, 3), '2026-03', '2026-03-31');
check($short['previous_day'] === 28 && $short['current_day'] === 31 && $short['previous']['expenses'] === 1000, 'short previous months cap the cutoff at their last day');
$early = spending_comparison(spending_history($db, 3), '2026-03', '2026-03-01');
check($early['available'] && $early['previous']['expenses'] === 0 && $early['total']['percent'] === null, 'a known month with only later transactions has zero in the compared window');
check(spending_comparison($history, '2026-01', '2026-02-05')['current']['expenses'] === 30000, 'ended months retain full-month comparisons');
