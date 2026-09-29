<?php
declare(strict_types=1);
require dirname(__DIR__) . '/plaid.php';
function plaid_check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } echo "PASS $message\n"; }
$base = sys_get_temp_dir() . '/dan-plaid-test-' . bin2hex(random_bytes(8));
mkdir($base, 0700); mkdir($base . '/items', 0700);
putenv('DAN_PLAID_STORAGE=' . $base . '/items'); putenv('DAN_PLAID_CONFIG=' . $base . '/plaid.env');
file_put_contents($base . '/plaid.env', "PLAID_CLIENT_ID=test-client\nPLAID_SECRET=\"test-secret\"\nPLAID_ENV=sandbox\n");
chmod($base . '/plaid.env', 0600);
$public = 'public-sandbox-00000000-0000-0000-0000-000000000001';
$access = 'access-sandbox-00000000-0000-0000-0000-000000000002';
$exchanges = 0; $calls = [];
$mock = function ($endpoint, $payload) use (&$calls, &$exchanges, $access) {
    $calls[] = [$endpoint, $payload];
    if ($endpoint === '/item/public_token/exchange') { $exchanges++; return ['access_token' => $access, 'item_id' => 'test-item']; }
    if ($endpoint === '/accounts/get') { return ['accounts' => [
        ['account_id' => 'cc', 'name' => 'Sample credit', 'mask' => '3333', 'type' => 'credit', 'subtype' => 'credit card'],
        ['account_id' => 'checking', 'name' => 'Checking', 'mask' => '0000', 'type' => 'depository', 'subtype' => 'checking']]]; }
    if ($endpoint === '/transactions/get') {
        plaid_check($payload['options']['account_ids'] === ['cc'], 'only credit-card accounts requested');
        return ['total_transactions' => 3, 'transactions' => $payload['options']['offset'] === 0 ? [
            ['transaction_id' => 'p', 'account_id' => 'cc', 'date' => '2026-09-20', 'name' => 'Sample pending', 'amount' => 23.50, 'iso_currency_code' => 'USD', 'pending' => true,
                'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANTS', 'confidence_level' => 'HIGH'], 'unneeded_private_field' => 'excluded'],
            ['transaction_id' => 'other', 'account_id' => 'checking', 'name' => 'not a card']
        ] : [['transaction_id' => 'r', 'account_id' => 'cc', 'date' => '2026-09-19', 'name' => 'Credit', 'amount' => -12.00, 'iso_currency_code' => 'USD', 'pending' => false, 'category' => ['Payment']]]];
    }
    throw new RuntimeException('Unexpected endpoint');
};
try {
    plaid_check(plaid_config()['secret'] === 'test-secret', 'private dotenv loaded without evaluating shell code');
    $payload = plaid_link_payload(1);
    plaid_check($payload['products'] === ['transactions'] && $payload['account_filters']['credit']['account_subtypes'] === ['credit card'], 'Link requests Transactions and credit cards only');
    plaid_exchange(1, $public, $mock); plaid_exchange(1, $public, $mock);
    plaid_check($exchanges === 1, 'retrying exchange is idempotent');
    plaid_check(plaid_read(2) === null, 'connections are isolated per user');
    plaid_check((fileperms(plaid_path(1)) & 0777) === 0600, 'access token file has owner-only permissions');
    $record = plaid_read(1);
    plaid_check($record['access_token'] === $access && !str_contains(file_get_contents(plaid_path(1)), $public), 'access token is persisted privately and public token is only hashed');
    $result = plaid_refresh(1, $mock); $record = plaid_read(1); $rows = $record['snapshot']['transactions'];
    plaid_check($result['ready'] && count($rows) === 2 && $rows[0]['pending'] && (float) $rows[1]['amount'] === -12.0, 'pagination, pending state, credit sign, and account filtering preserved');
    plaid_check($rows[0]['category'] === 'FOOD_AND_DRINK_RESTAURANTS' && $rows[1]['category'] === 'Payment', 'personal finance and legacy categories supported');
    plaid_check(!str_contains(json_encode($record['snapshot']), $access) && !str_contains(json_encode($record['snapshot']), 'unneeded_private_field'), 'display snapshot contains only allowlisted transaction fields');
    $record['checked_at'] = 0; plaid_write(1, $record);
    $waiting = function ($endpoint, $payload) use ($mock) { if ($endpoint === '/transactions/get') { throw new PlaidFailure('PRODUCT_NOT_READY'); } return $mock($endpoint, $payload); };
    plaid_check(plaid_refresh(1, $waiting)['ready'] === false && plaid_read(1)['access_token'] === $access, 'initial data delay preserves saved token and previous snapshot');
    $record = plaid_read(1); $record['checked_at'] = 0; plaid_write(1, $record);
    try { plaid_refresh(1, fn() => throw new PlaidFailure('CONNECTION_FAILED')); } catch (PlaidFailure $e) {}
    plaid_check(count(plaid_read(1)['snapshot']['transactions']) === 2, 'API failure preserves earlier transaction snapshot');
    try { plaid_exchange(1, str_replace('000001', '000003', $public), $mock); throw new RuntimeException('Replacement permitted'); } catch (PlaidFailure $e) { plaid_check($e->getMessage() === 'ALREADY_CONNECTED', 'existing connection is not overwritten'); }
    try { plaid_exchange(3, str_replace('sandbox', 'production', $public), $mock); throw new RuntimeException('Production permitted'); } catch (PlaidFailure $e) { plaid_check($e->getMessage() === 'INVALID_PUBLIC_TOKEN', 'production tokens are rejected'); }
    file_put_contents($base . '/plaid.env', "PLAID_CLIENT_ID=test\nPLAID_SECRET=test\nPLAID_ENV=production\n");
    try { plaid_config(); throw new RuntimeException('Production permitted'); } catch (PlaidFailure $e) { plaid_check($e->getMessage() === 'SANDBOX_REQUIRED', 'configuration cannot silently switch to Production'); }
    plaid_check(!str_contains(plaid_error_message(new RuntimeException($access)), $access), 'exception details never disclose tokens');
    echo "All Plaid checks passed with synthetic data and no network calls.\n";
} finally {
    foreach (glob($base . '/items/*') as $file) { unlink($file); }
    rmdir($base . '/items'); unlink($base . '/plaid.env'); rmdir($base);
}
