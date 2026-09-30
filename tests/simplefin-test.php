<?php
declare(strict_types=1);
// Reuse the shipped-schema test adapter and regression suite; never a production DB.
require __DIR__ . '/analyzer-test.php';
require dirname(__DIR__) . '/simplefin.php';
$storage = sys_get_temp_dir() . '/dan-simplefin-test-' . bin2hex(random_bytes(8));
mkdir($storage, 0700); putenv('DAN_SIMPLEFIN_STORAGE=' . $storage);
$access = 'https://test-user:test-private-password@beta-bridge.simplefin.org/simplefin';
$claim = base64_encode('https://beta-bridge.simplefin.org/simplefin/claim/test-token');
$today = gmdate('Y-m-d'); $yesterday = gmdate('Y-m-d', time() - 86400);
$account = ['id' => 'card-1', 'conn_id' => 'bank-1', 'name' => 'Elan test card', 'currency' => 'USD', 'transactions' => [
    ['id' => '1', 'posted' => time() - 3600, 'amount' => '-19.25', 'description' => 'COSTCO WHSE #0334'],
    ['id' => '2', 'posted' => time() - 3600, 'amount' => '-19.25', 'description' => 'COSTCO WHSE #0334'],
    ['id' => '3', 'posted' => 0, 'amount' => '-9.50', 'description' => 'UBER *EATS', 'pending' => true],
    ['id' => '4', 'posted' => time() - 3600, 'amount' => '100.00', 'description' => 'AUTOPAY PAYMENT'],
    ['id' => '5', 'posted' => time() - 3600, 'amount' => '2.00', 'description' => 'COSTCO WHSE #0334']
]];
try {
    foreach (['http://beta-bridge.simplefin.org/simplefin/claim/test', 'https://localhost/simplefin/claim/test', 'https://beta-bridge.simplefin.org.evil.test/simplefin/claim/test', 'https://beta-bridge.simplefin.org:443/simplefin/claim/test', 'https://beta-bridge.simplefin.org/simplefin/claim/test?redirect=evil', 'https://user:pw@beta-bridge.simplefin.org/simplefin/claim/test'] as $bad) {
        rejects(fn() => simplefin_connect(1, base64_encode($bad)), 'unsafe claim URL rejected');
    }
    simplefin_connect(1, $claim, fn() => $access);
    check((fileperms(simplefin_path(1)) & 0777) === 0600 && (fileperms($storage) & 0777) === 0700, 'connection credentials stored outside web root with private permissions');
    check(simplefin_read(2) === null, 'connection state isolated per user');
    rejects(fn() => simplefin_connect(1, $claim, fn() => $access), 'duplicate connect cannot replace existing credentials');
    $record = simplefin_fetch($db, 1, fn() => json_encode(['accounts' => [$account]]));
    check($record['next_sync'] > time() + 21600 && simplefin_link($db, 1) === null, 'initial retrieval schedules next refresh without importing accounts');
    rejects(fn() => simplefin_fetch($db, 1, fn() => throw new RuntimeException('network must not run')), 'manual refresh cooldown enforced');
    $foreign = (int) analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE user_id = 2 LIMIT 1')->fetchColumn();
    if (!$foreign) { analyzer_query($db, 'INSERT INTO analyzer_accounts (user_id, label) VALUES (2, ?)', ['Other private card']); $foreign = (int) $db->lastInsertId(); }
    rejects(fn() => simplefin_enable($db, 1, simplefin_remote_key($account), $foreign, '', $yesterday), 'foreign local account cannot be selected');
    simplefin_enable($db, 1, simplefin_remote_key($account), 0, 'SimpleFIN test credit card', $yesterday);
    $link = simplefin_link($db, 1); $card = (int) $link['account_id'];
    $report = monthly_report($db, 1, gmdate('Y-m'), $card);
    check($report['count'] === 4 && $report['expenses'] === 3850 && $report['refunds'] === 200 && $report['payments'] === 10000, 'imports distinct purchases and refunds, excludes pending and separates payments');
    $counts = simplefin_import($db, 1, $link, $account);
    check($counts === ['added' => 0, 'updated' => 0, 'skipped' => 4], 'overlapping refresh deduplicates stable IDs without collapsing separate equal purchases');
    $merchant = $report['merchants'][array_key_first($report['merchants'])];
    update_merchant($db, 1, $merchant['id'], $merchant['name'], 0, 'My groceries');
    $account['transactions'][0]['amount'] = '-21.25';
    $account['transactions'][2]['posted'] = time() - 3600; $account['transactions'][2]['pending'] = false;
    $counts = simplefin_import($db, 1, $link, $account);
    check($counts['updated'] === 1 && $counts['added'] === 1, 'posted correction updates existing transaction and pending-to-posted transition imports once');
    check(monthly_report($db, 1, gmdate('Y-m'), $card)['categories']['My groceries'] === 4050, 'source correction preserves user category choices');
    $sparse = $account; $sparse['transactions'] = [];
    simplefin_import($db, 1, $link, $sparse);
    check(monthly_report($db, 1, gmdate('Y-m'), $card)['count'] === 5, 'partial responses never delete history');
    $csvPending = pending_csv("Date,Description,Amount\n" . $today . ",Different shop,-21.25\n", $card);
    rejects(fn() => preview_import($db, 1, $csvPending), 'CSV preview rejects feed overlap');
    rejects(fn() => save_import($db, 1, $csvPending), 'CSV confirmation also rejects feed overlap');
    $postedDate = gmdate('Y-m-d', $account['transactions'][0]['posted']);
    $matchedCsv = "Date,Description,Amount,Transaction ID\n{$postedDate},Costco,-21.25,csv-costco-1\n";
    $matched = pending_csv($matchedCsv, $card);
    check(preview_import($db, 1, $matched)['skipped'] === 1, 'CSV recognizes a uniquely matched bank charge despite a different source description and ID');
    check((int) $db->query('SELECT COUNT(*) FROM analyzer_csv_feed_matches')->fetchColumn() === 0, 'preview does not persist cross-source matches');
    $oldDate = gmdate('Y-m-d', time() - 3 * 86400);
    $mixed = pending_csv($matchedCsv . "{$oldDate},History shop,-7.50,history-1\n", $card);
    $mixedPreview = preview_import($db, 1, $mixed);
    check($mixedPreview['added'] === 1 && $mixedPreview['skipped'] === 1, 'mixed history and feed overlap previews only the new historical charge');
    $mixedSaved = save_import($db, 1, $mixed);
    check($mixedSaved['added'] === 1 && $mixedSaved['skipped'] === 1, 'mixed CSV saves history and skips its bank-feed duplicate');
    check(save_import($db, 1, $mixed)['added'] === 0, 'repeating the mixed file is idempotent');
    check(preview_import($db, 1, $matched)['skipped'] === 1, 'matched CSV identity remains recognized in a different file');
    rejects(fn() => save_import($db, 1, pending_csv(str_replace('csv-costco-1', 'csv-costco-2', $matchedCsv), $card)), 'a second distinct CSV reference cannot reuse the same bank charge');
    rejects(fn() => preview_import($db, 1, pending_csv(str_replace('-21.25', '-22.25', $matchedCsv), $card)), 'a remembered CSV reference with changed financial data is rejected');
    $doubleCsv = "Date,Description,Amount,Transaction ID\n{$postedDate},Costco,-19.25,new-1\n{$postedDate},Costco,-19.25,new-2\n";
    rejects(fn() => save_import($db, 1, pending_csv($doubleCsv, $card)), 'two CSV purchases cannot collapse into one equal bank charge');
    $ambiguous = $account;
    $ambiguous['transactions'][] = ['id' => 'equal-other', 'posted' => $account['transactions'][1]['posted'], 'amount' => '-19.25', 'description' => 'COSTCO WHSE #0334'];
    simplefin_import($db, 1, $link, $ambiguous);
    rejects(fn() => preview_import($db, 1, pending_csv("Date,Description,Amount\n{$postedDate},Costco,-19.25\n", $card)), 'ambiguous equal bank charges require review instead of an arbitrary match');
    check(preview_import($db, 2, $matched)['skipped'] === 0, 'another user cannot match against this user’s bank charge');
    rejects(fn() => save_import($db, 2, $matched), 'another user cannot persist a match to a foreign card');
    $account['transactions'][0]['amount'] = '-22.25';
    simplefin_import($db, 1, $link, $account);
    rejects(fn() => preview_import($db, 1, $matched), 'provider corrections invalidate outdated CSV matches');
    $account['transactions'][0]['amount'] = '-21.25';
    simplefin_import($db, 1, $link, $account);
    $badCurrency = $account; $badCurrency['currency'] = 'EUR';
    rejects(fn() => simplefin_import($db, 1, $link, $badCurrency), 'foreign currency rejected before any import');
    check(monthly_report($db, 2, gmdate('Y-m'), $card)['count'] === 0, 'other user cannot read connected card transactions');
    $record = simplefin_read(1); $record['checked_at'] = 0; $record['next_sync'] = 0; simplefin_write(1, $record);
    $failed = simplefin_fetch($db, 1, fn() => json_encode(['accounts' => [$account], 'errors' => ['Invalid ' . $access]]));
    check(!str_contains(implode('', $failed['snapshot']['errors']), 'test-private-password') && str_contains($failed['message'], 'issue'), 'provider errors stop imports and redact access credentials');
    simplefin_pause($db, 1, true);
    rejects(fn() => simplefin_import($db, 1, $link, $account), 'paused connection cannot import');
    rejects(fn() => simplefin_enable($db, 1, simplefin_remote_key($account), $card, '', $today), 'unsuccessful retrieval cannot enable imports');
    $record = simplefin_read(1); $record['snapshot']['errors'] = []; simplefin_write(1, $record);
    rejects(fn() => simplefin_enable($db, 1, simplefin_remote_key($account), $card, '', $yesterday), 'existing transaction history cannot overlap a new connection start date');
    simplefin_pause($db, 1, false);
    check(simplefin_read(1)['full_refresh'] === true && simplefin_link($db, 1)['enabled'], 'resume requests full available history to recover paused period');
    simplefin_disconnect($db, 1);
    check(simplefin_read(1) === null && !simplefin_link($db, 1)['enabled'] && (int) analyzer_query($db, 'SELECT COUNT(*) FROM analyzer_transactions WHERE account_id = ?', [$card])->fetchColumn() === 7, 'disconnect removes credential, disables polling, and preserves history');
    check(preview_import($db, 1, $matched)['skipped'] === 1, 'remembered CSV duplicates remain recognized after disconnect');
    rejects(fn() => save_import($db, 1, $csvPending), 'CSV overlap remains protected after disconnect');
    simplefin_connect(2, base64_encode('https://beta-bridge.simplefin.org/simplefin/claim/DEMO-test'), fn() => $access);
    simplefin_fetch($db, 2, fn() => json_encode(['accounts' => [$account]]));
    rejects(fn() => simplefin_enable($db, 2, simplefin_remote_key($account), 0, 'Demo card', $yesterday), 'demo transactions cannot enter real reports');
    echo "All SimpleFIN checks passed.\n";
} finally {
    foreach (glob($storage . '/*') as $file) { unlink($file); }
    rmdir($storage);
}
