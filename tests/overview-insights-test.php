<?php
declare(strict_types=1);
require __DIR__ . '/budgets-test.php';
require dirname(__DIR__) . '/overview-insights.php';
$db->exec("INSERT INTO users (id, username) VALUES (50, 'insights_test'), (51, 'insights_other')");
$csv = "Date,Name,Amount\n2025-03-01,COSTCO,-400\n2025-03-02,RED ROBIN,-100\n2025-03-03,UBER *EATS,-200\n2025-04-01,COSTCO,-320\n2025-04-02,UBER *EATS,-50\n2025-04-03,PAYMENT THANK YOU,1000\n2025-04-04,TATTE BAKERY,500\n";
for ($i = 0; $i < 18; $i++) { $csv .= "2025-04-05,TATTE BAKERY,-5\n"; }
for ($i = 0; $i < 20; $i++) { $csv .= "2025-04-20,FUTURE SHOP,-10\n"; }
save_import($db, 50, pending_csv($csv));
$merchants = monthly_report($db, 50, '2025-04')['merchants'];
foreach ($merchants as $merchant) {
    if (str_contains(strtoupper($merchant['name']), 'TATTE')) { update_merchant($db, 50, (int) $merchant['id'], 'Tatte <script>bad()</script>', 0, 'Restaurants'); $tatte = (int) $merchant['id']; }
}
$plan = budget_plan($db, 50, '2025-04'); $targets = [];
foreach ($plan['groups'] as $key => $group) { $targets[$key] = ['Groceries' => '350', 'Restaurants' => '200', 'Food Delivery' => '100', 'Misc' => '200'][$group['name']]; }
save_budget($db, 50, '2025-04', $targets, budget_form_version($plan));
$history = spending_history($db, 50);
$result = overview_insights($db, 50, $history, '2025-04-06');
check(count($result['rows']) === 3 && array_column($result['rows'], 'kind') === ['attention', 'pattern', 'positive'], 'overview chooses three distinct evidence-backed takeaways');
check($result['rows'][0]['title'] === 'Groceries: getting tight' && str_contains($result['rows'][0]['detail'], '$30 left'), 'near-budget category shows the actual amount left');
check(str_contains($result['rows'][1]['title'], '18 purchases at Tatte') && str_contains($result['rows'][1]['detail'], '$90') && str_contains($result['rows'][1]['url'], '&merchant=' . $tatte), 'frequent merchant count and gross total exclude refunds, payments and future purchases');
check(str_contains($result['rows'][2]['title'], 'Food Delivery') && str_contains($result['rows'][2]['detail'], 'Apr 1–6 vs Mar 1–6'), 'positive comparisons name matched date windows and do not repeat the attention category');
$yearHistory = $history; $yearHistory['2024-04'] = $history['2025-03'];
$delivery = array_key_first($yearHistory['2024-04']['daily'][3]['categories']);
$yearHistory['2024-04']['daily'][3]['categories'][$delivery]['amount'] = 12567;
$yearHistory['2024-04']['daily'][20] = $yearHistory['2024-04']['daily'][3];
$yearHistory['2024-04']['daily'][20]['categories'][$delivery]['amount'] = 90000;
$yearRow = overview_insights($db, 50, $yearHistory, '2025-04-06')['rows'][2];
check($yearRow['year_ago_cents'] === 12567 && str_contains(insight_explanation($yearRow, '2025-04'), 'Apr 1–6 2024: about $125'), 'year-ago tooltip uses rounded category totals for the matching days only');
check(str_contains(insight_explanation($result['rows'][2], '2025-04'), 'Apr 1–6 2025: about $50 (dark bar)') && str_contains(insight_explanation($result['rows'][2], '2025-04'), 'Mar 1–6 2025: about $200 (light bar)'), 'tooltip labels both actual bar periods and their rounded totals');
check(!str_contains(json_encode($result), 'FUTURE SHOP'), 'future-dated transactions do not become current insights');
check(overview_insights($db, 51, spending_history($db, 51), '2025-04-06')['rows'] === [], 'a different user cannot see insight facts');
check(insight_amount(234691) === 'about $2,350' && insight_amount(50) === 'less than $1', 'prose rounds larger amounts without misrepresenting small balances as zero');
$historyNoPrior = ['2025-04' => $history['2025-04']];
check(count(overview_insights($db, 50, $historyNoPrior, '2025-04-06')['rows']) === 2, 'missing prior history cannot produce an invented positive comparison');
$noYear = $history; unset($noYear['2024-04']);
check(!str_contains(insight_explanation(overview_insights($db, 50, $noYear, '2025-04-06')['rows'][2], '2025-04'), 'last year'), 'missing year-ago history is omitted rather than presented as zero');
$futureOnly = ['2025-05' => $history['2025-04']];
check(overview_insights($db, 50, $futureOnly, '2025-04-06')['rows'] === [], 'future months are not shown as current overview facts');
// Include Travel in the saved plan, with $400 accumulated and a $100 contribution.
$category = (int) analyzer_query($db, "SELECT id FROM analyzer_categories WHERE user_id = 50 AND name = 'Travel'")->fetchColumn();
$plan = budget_plan($db, 50, '2025-04');
$key = 'category_' . $category;
$groups = $plan['groups'] + [$key => ['name' => 'Travel', 'category_id' => $category]];
$exactTargets = $plan['targets'] + [$key => 10000];
analyzer_query($db, 'UPDATE analyzer_budgets SET groups_json = ?, targets_json = ? WHERE user_id = 50 AND month = ?', [json_encode($groups), json_encode($exactTargets), '2025-04']);
analyzer_query($db, 'INSERT INTO analyzer_travel_funds (user_id, category_id, start_month, opening_cents, created_at) VALUES (50, ?, ?, 40000, ?)', [$category, '2025-04', gmdate('Y-m-d H:i:s')]);
$funded = overview_insights($db, 50, $history, '2025-04-06');
check($funded['rows'][2]['label'] === 'Travel fund' && str_contains($funded['rows'][2]['title'], '$500') && str_contains($funded['rows'][2]['detail'], 'contribution'), 'travel row distinguishes planned contributions from prior carryover');
$card = (int) user_accounts($db, 50)[0]['id'];
save_import($db, 50, pending_csv("Date,Name,Amount\n2025-04-06,COSTCO,-500\n", $card));
$over = overview_insights($db, 50, spending_history($db, 50), '2025-04-06');
check($over['rows'][0]['title'] === 'Groceries: over budget' && str_contains($over['rows'][2]['detail'], '$110 covered other overages'), 'travel amount excludes the money needed for other net category overages');
// Render the actual overview view to verify that merchant strings cannot become HTML.
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
define('DAN_PORTAL', true); $overviewInsights = $result; $displayName = 'Test'; $latestReview = null;
$dashboard = spending_dashboard($history);
ob_start(); require dirname(__DIR__) . '/views/overview-insights.php'; $html = ob_get_clean();
check(str_contains($html, 'Worth a look') && !str_contains($html, 'Category shifts'), 'compact rows replace the old category-shift graphic');
check(str_contains($html, '&lt;script&gt;bad()&lt;/script&gt;') && !str_contains($html, '<script>bad()</script>'), 'merchant names are safely escaped in overview rows');
check(str_contains($html, 'class="insight-meter"') && str_contains($html, '18×'), 'budget meter and prominent merchant count render without long prose');
check(str_contains($html, 'comparison-bars') && !str_contains($html, 'insight-detail'), 'comparison uses labeled bars instead of explanatory paragraphs');
check(str_contains($html, 'aria-label="Explain Food Delivery"') && !preg_match('/<a[^>]*>[^<]*<button/s', $html), 'dedicated explanation control is separate from the details link');
check(!array_filter(overview_insights($db, 50, $history, '2025-04-30')['rows'], fn($r) => $r['kind'] === 'attention'), 'a nearly used target at month end is not presented as an early warning');
save_import($db, 51, pending_csv("Date,Name,Amount\n2025-04-01,SMALL SHOP,-5\n"));
check(overview_insights($db, 51, spending_history($db, 51), '2025-04-06')['rows'] === [], 'routine spending without a strong signal produces no filler panel');
$more = "Date,Name,Amount\n";
for ($i = 0; $i < 8; $i++) { $more .= "2025-04-05,BOOK SHOP,-10\n"; }
save_import($db, 50, pending_csv($more, $card));
$expanded = overview_insights($db, 50, spending_history($db, 50), '2025-04-06');
check(count($expanded['rows']) === 5 && count(array_filter($expanded['rows'], fn($r) => $r['kind'] === 'pattern')) === 2, 'additional meaningful signals can fill up to five slots');
$monthly = monthly_insights($db, 50, spending_history($db, 50), '2025-04', 0, '2025-04-06');
check(count($monthly['rows']) === 6 && $monthly['rows'][0]['name'] === 'Month over month', 'monthly insights put the total comparison first and cap useful cards at six');
check($monthly['rows'][0]['current_cents'] === 104000 && $monthly['rows'][0]['previous_cents'] === 70000 && str_contains(insight_explanation($monthly['rows'][0], '2025-04'), 'more spent'), 'month comparison uses actual purchase totals and correctly describes increases');
check(str_contains($monthly['rows'][0]['url'], '&compare=1#month-comparison') && $monthly['rows'][0]['drivers'][0]['name'] === 'Groceries', 'total insight opens exact details and explains the biggest category changes');
$older = monthly_insights($db, 50, spending_history($db, 50), '2025-03', 0, '2025-05-06');
check($older['month'] === '2025-03' && !str_contains(json_encode($older), 'Tatte'), 'selecting an earlier month cannot surface the latest month’s merchant patterns');
check(monthly_insights($db, 50, spending_history($db, 50), '2025-06', 0, '2025-05-06')['rows'] === [] && monthly_insights($db, 50, spending_history($db, 50), '2025-02', 0, '2025-05-06')['rows'] === [], 'future and missing selected months never fall back to latest-month insights');
$noBaseline = monthly_insights($db, 50, ['2025-04' => $history['2025-04']], '2025-04', 0, '2025-04-06');
check(!in_array('Month over month', array_column($noBaseline['rows'], 'name'), true), 'missing prior-month history does not become a zero baseline');
save_import($db, 50, pending_csv("Date,Name,Amount\n2025-03-01,COSTCO,-25\n2025-04-02,COSTCO,-100\n", 0, 'Separate card'));
$separate = (int) analyzer_query($db, "SELECT id FROM analyzer_accounts WHERE user_id = 50 AND label = 'Separate card'")->fetchColumn();
$filtered = monthly_insights($db, 50, spending_history($db, 50, $separate), '2025-04', $separate, '2025-04-06');
check($filtered['rows'][0]['current_cents'] === 10000 && $filtered['rows'][0]['previous_cents'] === 2500 && !array_filter($filtered['rows'], fn($r) => in_array($r['visual'], ['budget', 'fund', 'frequency'], true)), 'card filter scopes amounts and merchants without applying whole-budget targets to one of multiple cards');
check(count($filtered['rows']) === 2 && $filtered['rows'][1]['name'] === 'Groceries' && str_contains($filtered['rows'][1]['url'], '&account=' . $separate), 'meaningful category increases appear with card-specific drill-down links');
check(monthly_insights($db, 51, spending_history($db, 51), '2025-04', 0, '2025-04-06')['rows'] === [], 'monthly cards stay private and suppress routine spending');
$overviewInsights = $monthly;
ob_start(); require dirname(__DIR__) . '/views/overview-insights.php'; $monthlyHtml = ob_get_clean();
check(str_contains($monthlyHtml, 'Explain Month over month') && str_contains($monthlyHtml, 'insight-increase') && str_contains($monthlyHtml, 'more spent'), 'monthly graphics and explanation buttons support upward comparisons');
echo "All overview insight checks passed.\n";

if (getenv('DAN_INSIGHTS_PREVIEW')) {
    $overviewInsights = $funded;
    foreach ($overviewInsights['rows'] as &$previewRow) { if ($previewRow['visual'] === 'comparison') { $previewRow = $yearRow; } } unset($previewRow);
    $overviewInsights['rows'][1]['title'] = '18 purchases at Tatte Bakery';
    $overviewInsights['rows'][1]['name'] = 'Tatte Bakery';
    ob_start(); require dirname(__DIR__) . '/views/overview-insights.php'; $preview = ob_get_clean();
    file_put_contents(getenv('DAN_INSIGHTS_PREVIEW'), '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/portal.css"></head><body class="workspace"><main class="workspace-main">' . $preview . '</main><script src="/charts.js"></script></body></html>');
}
