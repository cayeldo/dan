<?php
declare(strict_types=1);
require __DIR__ . '/analyzer-test.php';
$first = 'AMERICAN 0012359018146 FORT WORTH';
$second = 'AMERICAN 0012359018147 FORT WORTH';
foreach ([$first, $second, 'American Airlines 0012359018148'] as $description) {
    $result = classify_merchant($description);
    check($result['name'] === 'American Airlines' && $result['category'] === 'Travel' && !$result['needs_review'], 'airline descriptors share one recognized merchant');
}
foreach (['AMERICAN EXPRESS', 'AMERICAN EAGLE 1234567890123', 'AMERICAN 1234567890123 HARDWARE', 'AMERICAN 0012359018146 OTHER SHOP'] as $description) {
    check(classify_merchant($description)['name'] !== 'American Airlines', 'unrelated American businesses stay separate');
}
check(csv_feed_signature('2026-07-01', -100303, 'expense', $first) !== csv_feed_signature('2026-07-01', -100303, 'expense', $second), 'separate ticket numbers keep distinct cross-source identities');
check(csv_feed_signature('2026-07-01', -100303, 'expense', $first) === csv_feed_signature('2026-07-01', -100303, 'expense', 'American Airlines 0012359018146'), 'same ticket matches across known airline descriptor formats');
$db->exec("INSERT INTO users (id, username) VALUES (30, 'airline_test')");
$pending = pending_csv("Date,Name,Amount\n7/1/26,$first,-1003.03\n7/1/26,$second,-1003.03\n", 0, 'Airline card');
// Simulate the old importer so the existing-merchant repair uses real legacy aliases.
foreach ($pending['rows'] as &$row) {
    $name = mb_convert_case($row['description'], MB_CASE_TITLE, 'UTF-8');
    $row['suggestion'] = ['name' => $name, 'category' => 'Uncategorized', 'needs_review' => true, 'key' => merchant_match_key($name)];
}
unset($row);
save_import($db, 30, $pending);
$card = (int) user_accounts($db, 30)[0]['id'];
foreach (monthly_report($db, 30, '2026-07')['merchants'] as $merchant) {
    update_merchant($db, 30, $merchant['id'], 'American Airlines', 0, 'Travel');
}
$report = monthly_report($db, 30, '2026-07');
$merchant = array_values($report['merchants'])[0];
check(count($report['merchants']) === 1 && count($merchant['rows']) === 2 && $report['expenses'] === 200606, 'legacy merge retains both equal charges and total');
check(count(array_unique(array_column($merchant['rows'], 'description'))) === 2, 'original ticket descriptions remain available');
check(save_import($db, 30, pending_csv("Date,Name,Amount\n7/1/26,$first,-1003.03\n7/1/26,$second,-1003.03\n", $card))['skipped'] === 2, 'reimport after merchant merge skips only true duplicates');
update_merchant($db, 30, $merchant['id'], 'American Airlines', 0, 'Work travel');
save_import($db, 30, pending_csv("Date,Name,Amount\n8/1/26,AMERICAN 0012359018199 FORT WORTH,-50.00\n", $card));
check(monthly_report($db, 30, '2026-08')['categories']['Work travel'] === 5000, 'new ticket numbers inherit the user’s saved category');
echo "All merchant identity checks passed.\n";
