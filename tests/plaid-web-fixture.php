<?php
// Used only in the disposable web-test copy; never loaded by the application.
function plaid_api(string $endpoint, array $payload): array
{
    if ($endpoint === '/link/token/create') { return ['link_token' => 'link-sandbox-test-only-token']; }
    if ($endpoint === '/item/public_token/exchange') { return ['access_token' => 'access-sandbox-test-only-private-token', 'item_id' => 'test-only-item']; }
    if ($endpoint === '/accounts/get') { return ['accounts' => [['account_id' => 'credit', 'name' => 'Sandbox Visa', 'type' => 'credit', 'subtype' => 'credit card', 'mask' => '3333']]]; }
    if ($endpoint === '/transactions/get') { return ['total_transactions' => 1, 'transactions' => [[
        'transaction_id' => 'test-tx', 'account_id' => 'credit', 'date' => gmdate('Y-m-d'), 'name' => '<script>unsafe</script>',
        'merchant_name' => 'Sandbox merchant', 'amount' => 18.25, 'iso_currency_code' => 'USD', 'pending' => true,
        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANTS', 'confidence_level' => 'HIGH']
    ]]]; }
    throw new PlaidFailure('INVALID_ENDPOINT');
}
