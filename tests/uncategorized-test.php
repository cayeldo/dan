<?php
declare(strict_types=1);
require __DIR__ . '/analyzer-test.php';
require dirname(__DIR__) . '/uncategorized.php';
$db->exec("INSERT INTO users (id, username) VALUES (70, 'uncategorized_test'), (71, 'uncategorized_other')");
save_import($db, 70, pending_csv("Date,Name,Amount\n2025-01-03,MYSTERY BOUTIQUE,-10\n2026-08-03,MYSTERY BOUTIQUE,-20\n2026-08-04,MYSTERY BOUTIQUE,5\n2026-08-04,COSTCO,-100\n2026-08-05,PAYMENT THANK YOU,130\n"));
$list = uncategorized_expenses($db, 70);
check($list['purchase_count'] === 2 && $list['total_cents'] === 3000 && $list['merchant_count'] === 1, 'all-month list includes only uncategorized purchases, excluding credits and payments');
$merchant = (int) $list['merchants'][0]['id'];
check(count($list['transactions'][$merchant]) === 2 && $list['merchants'][0]['first_date'] === '2025-01-03', 'purchase details retain individual dates across years');
check(uncategorized_expenses($db, 71)['merchants'] === [], 'uncategorized history is scoped to the signed-in user');
$foreignCategory = get_category($db, 71, 'Private category');
rejects(fn() => categorize_uncategorized($db, 70, $merchant, $foreignCategory, ''), 'foreign categories cannot be assigned');
rejects(fn() => categorize_uncategorized($db, 71, $merchant, $foreignCategory, ''), 'another user cannot categorize this merchant');
rejects(fn() => categorize_uncategorized($db, 70, $merchant, 0, ''), 'saving requires an actual category choice');
categorize_uncategorized($db, 70, $merchant, 0, 'Local shops');
check(uncategorized_expenses($db, 70)['merchants'] === [] && monthly_report($db, 70, '2025-01')['categories']['Local shops'] === 1000 && monthly_report($db, 70, '2026-08')['categories']['Local shops'] === 2000, 'one category choice updates all earlier purchases and removes the merchant from the list');
check(analyzer_query($db, 'SELECT status FROM analyzer_ai_jobs WHERE merchant_id = ?', [$merchant])->fetchColumn() === 'overridden', 'manual categorization prevents a pending AI result from replacing it');
rejects(fn() => categorize_uncategorized($db, 70, $merchant, 0, 'Another category'), 'stale forms cannot silently replace a category already chosen');
$card = (int) user_accounts($db, 70)[0]['id'];
save_import($db, 70, pending_csv("Date,Name,Amount\n2026-09-03,MYSTERY BOUTIQUE,-15\n", $card));
check(monthly_report($db, 70, '2026-09')['categories']['Local shops'] === 1500, 'future matching purchases inherit the chosen category');
$csv = "Date,Name,Amount\n";
foreach (range('A', 'Z') as $letter) { $csv .= "2026-08-01,UNKNOWN COMPANY $letter,-10\n"; }
save_import($db, 71, pending_csv($csv));
$first = uncategorized_expenses($db, 71, 1); $second = uncategorized_expenses($db, 71, 2);
check($first['pages'] === 2 && count($first['merchants']) === 25 && count($second['merchants']) === 1 && $first['total_cents'] === 26000, 'pagination keeps every merchant accessible and totals cover all pages');
check(uncategorized_expenses($db, 71, 999)['page'] === 2, 'stale pagination clamps to the remaining pages');
echo "All uncategorized checks passed.\n";
