<?php
declare(strict_types=1);
// Reuse the disposable database and import assertions, never a live account.
require __DIR__ . '/analyzer-test.php';
$db->exec("INSERT INTO users (id, username) VALUES (3, 'ai_test_user'), (4, 'other_ai_test_user')");
$config = ['enabled' => true, 'api_key' => 'test-placeholder-not-a-real-key', 'model' => 'gpt-6.1-sol'];
check(!isset(category_ai_payload([], [], 'gpt-4.1-mini')['reasoning']), 'legacy model overrides do not receive unsupported reasoning parameters');
$csv = "Date,Transaction,Name,Memo,Amount\n8/1/26,DEBIT,CHEWY.COM,100000000000001; ; confidential@example.test,-12.34\n8/2/26,DEBIT,CHEWY.COM,100000000000002; ; private memo,-4.56\n8/3/26,DEBIT,PLANET FITNESS 8005551212,100000000000003; ; private memo,-20.00\n8/4/26,DEBIT,RED ROBIN 23,100000000000004; 05812,-10.00\n";
save_import($db, 3, pending_csv($csv));
$accountId = (int) user_accounts($db, 3)[0]['id'];
check((int) analyzer_query($db, 'SELECT COUNT(*) FROM analyzer_ai_jobs WHERE user_id = 3')->fetchColumn() === 2, 'one AI job per unknown merchant, with no job for a known rule');
$calls = 0;
$transport = function (array $payload, array $settings) use ($db, &$calls): array {
    $calls++;
    check(!$db->inTransaction(), 'API request does not hold database locks');
    check($payload['store'] === false && $payload['text']['format']['strict'] === true, 'API uses non-stored structured responses');
    check($payload['model'] === $settings['model'] && $payload['reasoning']['effort'] === 'low' && $payload['max_output_tokens'] > 2400, 'categorization uses the configured reasoning model with room for reasoning and JSON');
    $input = json_decode($payload['input'][0]['content'], true);
    $encoded = json_encode($input);
    foreach (['12.34', '2026-08', 'confidential@example.test', 'private memo', '100000000000001', '8005551212', 'ai_test_user', 'Test card'] as $private) {
        check(!str_contains($encoded, $private), 'AI input excludes ' . (str_contains($private, 'memo') ? 'raw memo' : 'private transaction field'));
    }
    return ['results' => array_map(fn($m) => ['id' => $m['id'], 'category' => str_contains(strtoupper($m['merchant']), 'CHEWY') ? 'Pets' : 'Fitness', 'confidence' => 'high'], $input['merchants'])];
};
check(run_category_ai($db, 3, $transport, $config) === 2, 'AI categories are applied automatically without user confirmation');
$report = monthly_report($db, 3, '2026-08');
check($report['categories']['Pets'] === 1690 && $report['categories']['Fitness'] === 2000 && $report['categories']['Restaurants'] === 1000, 'AI creates new category records and updates stored report totals');
check($report['ai_pending_count'] === 0 && $report['review_count'] === 0, 'successful AI classifications do not prompt for review');
save_import($db, 3, pending_csv($csv . "9/1/26,DEBIT,CHEWY.COM,100000000000005; ;,-6\n", $accountId));
check(run_category_ai($db, 3, $transport, $config) === 0 && $calls === 1, 'learned categories are reused without repeat API calls');
check(monthly_report($db, 3, '2026-09')['categories']['Pets'] === 600, 'future matching transactions inherit AI categories');
save_import($db, 4, pending_csv($csv));
check(monthly_report($db, 4, '2026-08')['review_count'] === 2, 'AI decisions remain isolated to the owning user');
check(!analyzer_query($db, 'SELECT id FROM analyzer_categories WHERE user_id = 4 AND name = ?', ['Pets'])->fetchColumn(), 'new AI categories do not leak to other users');

function add_ai_merchant(PDO $db, int $accountId, string $name, string $reference): int
{
    save_import($db, 3, pending_csv("Date,Transaction,Name,Memo,Amount\n8/8/26,DEBIT,{$name},{$reference}; ;,-9.99\n", $accountId));
    return (int) analyzer_query($db, 'SELECT merchant_id FROM analyzer_transactions WHERE user_id = 3 AND bank_reference = ?', [$reference])->fetchColumn();
}
$humanId = add_ai_merchant($db, $accountId, 'Unknown Human Choice', '200000000000001');
$jobs = claim_category_jobs($db, 3);
update_merchant($db, 3, $humanId, 'Unknown Human Choice', 0, 'My choice');
$answer = ['results' => [['id' => (string) $humanId, 'category' => 'Shopping', 'confidence' => 'high']]];
check(apply_category_results($db, $jobs, $answer, $config['model']) === 0, 'a human edit made during an AI request wins');
check(monthly_report($db, 3, '2026-08')['categories']['My choice'] === 999, 'AI cannot overwrite a manual category');

$uncertainId = add_ai_merchant($db, $accountId, 'Unidentifiable Merchant', '200000000000002');
$lowConfidence = fn($payload) => ['results' => [['id' => (string) $uncertainId, 'category' => 'Uncategorized', 'confidence' => 'low']]];
check(run_category_ai($db, 3, $lowConfidence, $config) === 0, 'uncertain AI guesses are not stored as confirmed categories');
check(analyzer_query($db, 'SELECT status FROM analyzer_ai_jobs WHERE merchant_id = ?', [$uncertainId])->fetchColumn() === 'unresolved', 'uncertain result is retained as nonblocking unresolved status');

$failedId = add_ai_merchant($db, $accountId, 'Temporary API Failure', '200000000000003');
$failedCalls = 0;
$failed = function () use (&$failedCalls): array { $failedCalls++; throw new RuntimeException('http_429'); };
for ($attempt = 1; $attempt <= 3; $attempt++) {
    analyzer_query($db, "UPDATE analyzer_ai_jobs SET available_at = '2000-01-01 00:00:00' WHERE merchant_id = ?", [$failedId]);
    check(run_category_ai($db, 3, $failed, $config) === 0, 'API failure does not fail an import');
}
check(analyzer_query($db, 'SELECT status FROM analyzer_ai_jobs WHERE merchant_id = ?', [$failedId])->fetchColumn() === 'failed', 'repeated API failures stop after three attempts');
run_category_ai($db, 3, $failed, $config);
check($failedCalls === 3, 'retry limit prevents unbounded API spending');
check((int) analyzer_query($db, 'SELECT COUNT(*) FROM analyzer_transactions WHERE merchant_id = ?', [$failedId])->fetchColumn() === 1, 'transaction remains saved through API outages');

$leaseId = add_ai_merchant($db, $accountId, 'Lease Test Business', '200000000000004');
$oldLease = claim_category_jobs($db, 3);
check(claim_category_jobs($db, 3) === [], 'active batch cannot be claimed twice');
analyzer_query($db, "UPDATE analyzer_ai_jobs SET available_at = '2000-01-01 00:00:00' WHERE merchant_id = ?", [$leaseId]);
$newLease = claim_category_jobs($db, 3);
$answer = ['results' => [['id' => (string) $leaseId, 'category' => 'Shopping', 'confidence' => 'medium']]];
check(apply_category_results($db, $oldLease, $answer, $config['model']) === 0, 'expired worker cannot overwrite a newer lease');
check(apply_category_results($db, $newLease, $answer, $config['model']) === 1, 'reclaimed job applies once');

$invalidId = add_ai_merchant($db, $accountId, 'Invalid Result Test', '200000000000005');
$invalid = fn() => ['results' => [['id' => '999999999', 'category' => 'Shopping', 'confidence' => 'high']]];
check(run_category_ai($db, 3, $invalid, $config) === 0, 'unexpected model IDs are rejected');
check((int) analyzer_query($db, 'SELECT needs_review FROM analyzer_merchants WHERE id = ?', [$invalidId])->fetchColumn() === 1, 'invalid response leaves original category untouched');
update_merchant($db, 3, $invalidId, 'Invalid Result Test', 0, 'Manual category');
queue_existing_unknowns($db);
check(analyzer_query($db, 'SELECT status FROM analyzer_ai_jobs WHERE merchant_id = ?', [$invalidId])->fetchColumn() === 'overridden', 'background scans never reopen manually corrected jobs');
check(run_category_ai($db, 4, $transport, ['enabled' => false, 'api_key' => '', 'model' => 'gpt-4.1-mini']) === 0, 'missing credentials do not consume pending work');
echo "All AI categorization checks passed with a fake transport; no real API calls were made.\n";
