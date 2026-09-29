<?php
declare(strict_types=1);
require dirname(__DIR__) . '/analyzer.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS {$message}\n";
}
function rejects(callable $action, string $message): void
{
    try { $action(); } catch (InvalidArgumentException $error) { check(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
final class AnalyzerTestDatabase extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
// A disposable MySQL DSN may be supplied to run these same assertions against
// the actual production dialect. Never point this test at the app database.
$testDsn = getenv('ANALYZER_TEST_DSN');
if ($testDsn) {
    if (!preg_match('/dbname=dan_test_[a-z0-9_]+(?:;|$)/', $testDsn)) { throw new RuntimeException('Tests require a disposable dan_test_ database.'); }
    $db = new PDO($testDsn, getenv('ANALYZER_TEST_USER') ?: 'root', getenv('ANALYZER_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE users (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50)) ENGINE=InnoDB');
    foreach (explode(';', file_get_contents(dirname(__DIR__) . '/database/analyzer.sql')) as $sql) { if (trim($sql) !== '') { $db->exec($sql); } }
} else {
    $db = new AnalyzerTestDatabase('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
    // Derive the SQLite schema from the shipped migration so columns/FKs cannot drift.
    $sql = file_get_contents(dirname(__DIR__) . '/database/analyzer.sql');
    $sql = str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
    $sql = preg_replace('/^\s*KEY \w+ \([^\n]+\),\n/m', '', $sql);
    $sql = preg_replace('/\) ENGINE=InnoDB[^;]+;/', ');', $sql);
    $sql = preg_replace('/VARCHAR\((\d+)\) NOT NULL/', 'VARCHAR($1) COLLATE NOCASE NOT NULL', $sql);
    $db->exec($sql);
}
$db->exec("INSERT INTO users (id, username) VALUES (1, 'first_test_user'), (2, 'second_test_user')");

check(parse_cents('-19.8') === -1980 && parse_cents('($1,234.56)') === -123456, 'money parsed in integer cents');
rejects(fn() => parse_cents('12.345'), 'fractional cents rejected');
rejects(fn() => parse_transaction_date('2/30/26'), 'invalid calendar dates rejected');
foreach ([['UBER   *EATS HELP.UBER.COM CA', 'Uber Eats', 'Food Delivery'], ['UBER *TRIP HELP.UBER.COM CA', 'Uber Trip', 'Transportation'],
    ['RED ROBIN 23 STERLING VA', 'Red Robin', 'Restaurants'], ['RED ROBIN NO 360 CHANTILLY VA', 'Red Robin', 'Restaurants'],
    ['COSTCO GAS #0334', 'Costco Gas', 'Fuel'], ['COSTCO WHSE #0218', 'Costco', 'Groceries'],
    ['DD *MCDONALDS', 'DoorDash', 'Food Delivery'], ['WONDER-GRHUB*BUFFALOWI', 'Grubhub', 'Food Delivery'],
    ['BWW GO ECOM 4159', 'Buffalo Wild Wings', 'Restaurants'], ['PlayStation Network', 'PlayStation', 'Entertainment']] as [$input, $name, $category]) {
    $result = classify_merchant($input); check($result['name'] === $name && $result['category'] === $category, 'merchant rule: ' . $name);
}
check(classify_merchant('UNFAMILIAR SHOP  LOCATION VA', '05812')['needs_review'], 'unknown merchants need confirmation even with an MCC hint');
$csv = "Date,Transaction,Name,Memo,Amount\n8/1/26,DEBIT,RED ROBIN 23,111111111111111; 05812,-10.25\n8/1/26,DEBIT,RED ROBIN NO 360,222222222222222; 05812,-10.25\n8/2/26,DEBIT,UBER *EATS,333333333333333; 05812,-20.00\n8/3/26,DEBIT,UBER *TRIP,444444444444444; 04121,-15.00\n8/4/26,CREDIT,RED ROBIN 23,555555555555555; 05812,5.00\n8/5/26,CREDIT,PAYMENT MADE BY ACCOUNT ENDING IN:1234,INTERNET,100.00\n";
function pending_csv(string $csv, int $accountId = 0, string $label = 'Test card'): array
{
    return ['rows' => parse_statement($csv), 'account_id' => $accountId, 'account_label' => $label, 'filename' => 'synthetic.csv', 'file_hash' => hash('sha256', $csv)];
}
$pending = pending_csv($csv);
$preview = preview_import($db, 1, $pending);
check($preview['added'] === 6 && $preview['expenses'] === 5550 && $preview['refunds'] === 500 && $preview['payments'] === 10000, 'preview reconciles purchases, credits, and excluded payments');
check((int) $db->query('SELECT COUNT(*) FROM analyzer_transactions')->fetchColumn() === 0, 'preview does not save transactions');
$saved = save_import($db, 1, $pending);
check($saved['added'] === 6, 'first statement saved');
$accountId = (int) user_accounts($db, 1)[0]['id'];
$pending['account_id'] = $accountId;
$saved = save_import($db, 1, $pending);
check($saved['added'] === 0 && $saved['skipped'] === 6, 'identical file is idempotent');
check(preview_import($db, 1, $pending)['skipped'] === 6, 'preview identifies all existing rows');
$overlap = $csv . "8/6/26,DEBIT,COSTCO WHSE #0334,666666666666666; 05300,-50.00\n";
$saved = save_import($db, 1, pending_csv($overlap, $accountId));
check($saved['added'] === 1 && $saved['skipped'] === 6, 'overlapping file adds only new bank references');
$report = monthly_report($db, 1, '2026-08');
check($report['expenses'] === 10550 && $report['refunds'] === 500 && $report['payments'] === 10000 && $report['net'] === 10050, 'monthly totals reconcile without counting card payments');
$redRobin = array_values(array_filter($report['merchants'], fn($m) => $m['name'] === 'Red Robin'))[0];
check($redRobin['expenses'] === 2050 && count($redRobin['rows']) === 3, 'same-day equal-value purchases with separate references are preserved');
check(array_sum($report['categories']) === $report['expenses'], 'chart and spending totals reconcile exactly');
check(array_sum(array_column($report['category_groups'], 'expenses')) === $report['expenses']
    && array_sum(array_column($report['category_groups'], 'refunds')) === $report['refunds'], 'category audit totals reconcile purchases and credits');
$restaurantGroup = $report['category_groups'][$redRobin['category_id']];
check($restaurantGroup['merchant_ids'] === [$redRobin['id']] && $restaurantGroup['count'] === 3
    && $restaurantGroup['expenses'] === 2050 && $restaurantGroup['refunds'] === 500, 'category audit links merchant totals to every underlying transaction');
check(monthly_report($db, 2, '2026-08')['count'] === 0 && user_accounts($db, 2) === [], 'another user cannot see uploaded data');
rejects(fn() => save_import($db, 2, $pending), 'another user cannot import into a foreign account');
rejects(fn() => update_merchant($db, 2, $redRobin['id'], 'Stolen', $redRobin['category_id'], ''), 'another user cannot edit a foreign merchant');
update_merchant($db, 1, $redRobin['id'], 'Red Robin', 0, 'Dining out');
$again = $overlap . "9/1/26,DEBIT,RED ROBIN NO 111,777777777777777; 05812,-30.00\n";
save_import($db, 1, pending_csv($again, $accountId));
$september = monthly_report($db, 1, '2026-09');
check($september['categories']['Dining out'] === 3000, 'custom categories are remembered on future imports');
check(monthly_report($db, 1, '2026-08')['categories']['Dining out'] === 2050, 'category corrections update earlier reports');
$foreignCategory = get_category($db, 2, 'Private category');
rejects(fn() => update_merchant($db, 1, $redRobin['id'], 'Red Robin', $foreignCategory, ''), 'foreign categories cannot be assigned');
$costco = array_values(array_filter($report['merchants'], fn($m) => $m['name'] === 'Costco'))[0];
update_merchant($db, 1, $costco['id'], 'Red Robin', $redRobin['category_id'], 'Merged category');
check(count(monthly_report($db, 1, '2026-08')['merchants']) === 3, 'explicit merchant merge combines groups without losing transactions');
check(monthly_report($db, 1, '2026-08')['expenses'] === 10550, 'merchant merge preserves total spending');
rejects(fn() => save_import($db, 1, pending_csv(str_replace('-10.25', '-99.25', $csv), $accountId)), 'conflicting bank reference aborts the entire import');
check(monthly_report($db, 1, '2026-08')['expenses'] === 10550, 'failed import rolls back all changes');
$fallbackCsv = "Date,Name,Amount\n8/1/26,Unfamiliar Shop,-7\n8/1/26,Unfamiliar Shop,-7\n";
$fallback = pending_csv($fallbackCsv, $accountId);
check(save_import($db, 1, $fallback)['added'] === 2, 'identical rows without IDs retain their within-file multiplicity');
check(save_import($db, 1, pending_csv($fallbackCsv . "8/2/26,Unfamiliar Shop,-8\n", $accountId))['added'] === 1, 'fallback matching deduplicates overlapping exports');
check(classify_merchant('Unfamiliar Shop')['category'] === 'Uncategorized', 'unrecognized business stays uncategorized');
rejects(fn() => parse_statement("Date,Name,Amount\n8/1/26,Shop,-5\ninvalid,Shop,-4\n"), 'malformed row rejects entire CSV');
rejects(fn() => parse_statement($csv, 'positive'), 'wrong sign convention is detected when payments are present');
check(parse_statement("Date,Description,Amount\n2026-08-01,Shop,12.34\n", 'positive')[0]['amount'] === -1234, 'positive-charge exports supported');
check(parse_statement("\xEF\xBB\xBFDate,Description,Amount\r\n8/1/26,\"Shop, Inc.\",-10\r\n")[0]['description'] === 'Shop, Inc.', 'BOM, CRLF, and quoted commas supported');
check(save_import($db, 2, pending_csv($csv))['added'] === 6, 'each user can independently import their own copy');
check(monthly_report($db, 2, '2026-08')['categories']['Restaurants'] === 2050, 'one user’s category rules do not affect another user');
check(monthly_report($db, 2, '2026-08', $accountId)['count'] === 0, 'foreign card filter cannot expose another user’s data');
save_import($db, 1, pending_csv("Date,Name,Amount\n10/1/26,Refund Only Shop,12.00\n", $accountId));
$creditOnly = monthly_report($db, 1, '2026-10');
check($creditOnly['categories'] === [] && count($creditOnly['category_groups']) === 1
    && array_sum(array_column($creditOnly['category_groups'], 'refunds')) === 1200, 'credit-only categories remain auditable even without a pie slice');

if (isset($argv[1])) {
    $sample = parse_statement(file_get_contents($argv[1]));
    check(count($sample) === 136, 'provided sample has 136 transactions');
    $totals = [];
    foreach ($sample as $row) {
        $month = substr($row['date'], 0, 7);
        $totals[$month][$row['kind']] = ($totals[$month][$row['kind']] ?? 0) + abs($row['amount']);
    }
    check($totals['2026-07']['expense'] === 13859 && $totals['2026-08']['expense'] === 221746 && $totals['2026-09']['expense'] === 227745, 'sample monthly purchases match independent CSV totals');
    check($totals['2026-08']['payment'] === 245497 && $totals['2026-09']['payment'] === 229945, 'all six sample payments excluded from purchases');
    $samplePending = ['rows' => $sample, 'account_id' => 0, 'account_label' => 'Sample validation', 'filename' => 'sample.csv', 'file_hash' => hash_file('sha256', $argv[1])];
    check(save_import($db, 1, $samplePending)['added'] === 136, 'full sample imports successfully in test database');
    $sampleAccount = (int) analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE label = ?', ['Sample validation'])->fetchColumn();
    $sampleReport = monthly_report($db, 1, '2026-08', $sampleAccount);
    check($sampleReport['expenses'] === 221746 && array_sum($sampleReport['categories']) === 221746, 'sample database report and chart reconcile');
}
echo $testDsn ? "All analyzer checks passed against MySQL.\n" : "All analyzer checks passed (SQLite adapter; row-lock behavior requires MySQL).\n";
