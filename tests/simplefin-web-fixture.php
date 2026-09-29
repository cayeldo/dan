<?php
function simplefin_http(string $url, bool $claim, array $query = []): string
{
    if ($claim) { return 'https://test-web-user:test-web-private-password@beta-bridge.simplefin.org/simplefin'; }
    return json_encode(['accounts' => [['id' => 'credit-test', 'conn_id' => 'elan-test', 'name' => 'Test Elan card', 'conn_name' => 'Test institution', 'currency' => 'USD', 'transactions' => [
        ['id' => 'posted-test', 'posted' => time() - 60, 'amount' => '-18.25', 'description' => 'COSTCO WHSE #0334'],
        ['id' => 'pending-test', 'posted' => 0, 'amount' => '-5.50', 'description' => '<script>provider text</script>', 'pending' => true]
    ]]]]);
}
