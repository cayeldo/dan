<?php
declare(strict_types=1);

final class PlaidFailure extends RuntimeException {}

function plaid_config(): array
{
    $path = getenv('DAN_PLAID_CONFIG') ?: '/home/cayeldo/.config/dan/plaid.env';
    if (!is_readable($path)) { throw new PlaidFailure('CONFIG_UNAVAILABLE'); }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('/^\s*(?:export\s+)?(PLAID_CLIENT_ID|PLAID_SECRET|PLAID_ENV)\s*=\s*(.*?)\s*$/', $line, $match)) { continue; }
        $value = $match[2];
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) { $value = substr($value, 1, -1); }
        $values[$match[1]] = $value;
    }
    // Deliberately fail closed: switching the env file cannot enable Production.
    if (($values['PLAID_ENV'] ?? '') !== 'sandbox') { throw new PlaidFailure('SANDBOX_REQUIRED'); }
    if (empty($values['PLAID_CLIENT_ID']) || empty($values['PLAID_SECRET'])) { throw new PlaidFailure('CONFIG_UNAVAILABLE'); }
    return ['client_id' => $values['PLAID_CLIENT_ID'], 'secret' => $values['PLAID_SECRET']];
}

function plaid_api(string $endpoint, array $payload): array
{
    $allowed = ['/link/token/create', '/item/public_token/exchange', '/accounts/get', '/transactions/get', '/item/remove'];
    if (!in_array($endpoint, $allowed, true)) { throw new PlaidFailure('INVALID_ENDPOINT'); }
    $config = plaid_config();
    $curl = curl_init('https://sandbox.plaid.com' . $endpoint);
    $body = '';
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Plaid-Version: 2020-09-14'],
        CURLOPT_POSTFIELDS => json_encode([...$payload, ...$config], JSON_THROW_ON_ERROR),
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 4194304) { return 0; }
            $body .= $chunk; return strlen($chunk);
        }]);
    try {
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($ok === false) { throw new PlaidFailure('CONNECTION_FAILED'); }
        $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if ($status < 200 || $status >= 300) {
            $code = $result['error_code'] ?? '';
            // Never surface or log API bodies, tokens, or credential values.
            throw new PlaidFailure(in_array($code, ['PRODUCT_NOT_READY', 'ITEM_LOGIN_REQUIRED', 'INVALID_PUBLIC_TOKEN', 'RATE_LIMIT_EXCEEDED', 'INVALID_CREDENTIALS', 'INSTITUTION_DOWN'], true) ? $code : 'API_FAILED');
        }
        if (!is_array($result)) { throw new PlaidFailure('INVALID_RESPONSE'); }
        return $result;
    } finally { curl_close($curl); }
}

function plaid_directory(): string
{
    $path = getenv('DAN_PLAID_STORAGE') ?: '/home/cayeldo/.config/dan/plaid-items';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
    $real = realpath($path);
    $web = realpath(__DIR__);
    if (!$real || $real === $web || str_starts_with($real, $web . DIRECTORY_SEPARATOR)
        || (fileperms($real) & 0077) !== 0 || !is_writable($real)) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
    return $real;
}

function plaid_path(int $userId): string
{
    if ($userId <= 0) { throw new PlaidFailure('AUTH_REQUIRED'); }
    return plaid_directory() . '/sandbox-user-' . $userId . '.json';
}

function plaid_read(int $userId): ?array
{
    $path = plaid_path($userId);
    if (!is_file($path)) { return null; }
    if ((fileperms($path) & 0077) !== 0) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
    $record = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (($record['user_id'] ?? null) !== $userId || ($record['environment'] ?? '') !== 'sandbox') { throw new PlaidFailure('INVALID_RECORD'); }
    return $record;
}

function plaid_write(int $userId, array $record): void
{
    $path = plaid_path($userId);
    $temp = tempnam(dirname($path), '.plaid-');
    if ($temp === false) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
    try {
        if (!chmod($temp, 0600)) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $path)) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
    } finally { if (is_file($temp)) { unlink($temp); } }
}

/** Separate sessions for the same user cannot overwrite each other's connection. */
function plaid_locked(int $userId, callable $callback): mixed
{
    $old = umask(0077);
    try { $lock = fopen(plaid_path($userId) . '.lock', 'c'); } finally { umask($old); }
    if (!$lock) { throw new PlaidFailure('STORAGE_UNAVAILABLE'); }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new PlaidFailure('BUSY'); }
        return $callback();
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function plaid_link_payload(int $userId): array
{
    return ['user' => ['client_user_id' => 'dan-sandbox-' . $userId], 'client_name' => 'Dan Spending Sandbox',
        'products' => ['transactions'], 'country_codes' => ['US'], 'language' => 'en',
        'account_filters' => ['credit' => ['account_subtypes' => ['credit card']]],
        'transactions' => ['days_requested' => 90]];
}

function plaid_exchange(int $userId, string $publicToken, ?callable $api = null): void
{
    $api ??= 'plaid_api';
    if (!preg_match('/\Apublic-sandbox-[A-Za-z0-9-]{20,100}\z/', $publicToken)) { throw new PlaidFailure('INVALID_PUBLIC_TOKEN'); }
    plaid_locked($userId, function () use ($userId, $publicToken, $api) {
        $existing = plaid_read($userId);
        if ($existing) {
            if (hash_equals($existing['public_token_hash'], hash('sha256', $publicToken))) { return; }
            throw new PlaidFailure('ALREADY_CONNECTED');
        }
        $result = $api('/item/public_token/exchange', ['public_token' => $publicToken]);
        if (!is_string($result['access_token'] ?? null) || !str_starts_with($result['access_token'], 'access-sandbox-') || !is_string($result['item_id'] ?? null)) { throw new PlaidFailure('INVALID_RESPONSE'); }
        try {
            // Persist immediately, before requesting transactions (which may not be ready).
            plaid_write($userId, ['user_id' => $userId, 'environment' => 'sandbox', 'access_token' => $result['access_token'],
                'item_id' => $result['item_id'], 'public_token_hash' => hash('sha256', $publicToken), 'connected_at' => gmdate('c'), 'snapshot' => null]);
        } catch (Throwable $error) {
            try { $api('/item/remove', ['access_token' => $result['access_token']]); } catch (Throwable $ignored) {}
            throw $error;
        }
    });
}

function plaid_transaction_view(array $transaction, array $accounts): array
{
    $category = $transaction['personal_finance_category'] ?? [];
    $primary = $category['primary'] ?? null;
    $detailed = $category['detailed'] ?? null;
    return ['date' => (string) ($transaction['date'] ?? ''), 'merchant' => (string) ($transaction['merchant_name'] ?? $transaction['name'] ?? 'Unknown merchant'),
        'description' => (string) ($transaction['name'] ?? ''), 'amount' => (float) ($transaction['amount'] ?? 0),
        'currency' => (string) ($transaction['iso_currency_code'] ?? $transaction['unofficial_currency_code'] ?? 'Unknown currency'),
        'pending' => (bool) ($transaction['pending'] ?? false), 'account' => $accounts[$transaction['account_id']]['name'],
        'category' => $detailed ?: ($primary ?: implode(' / ', $transaction['category'] ?? [])),
        'category_primary' => $primary, 'confidence' => $category['confidence_level'] ?? null];
}

function plaid_refresh(int $userId, ?callable $api = null): array
{
    $api ??= 'plaid_api';
    return plaid_locked($userId, function () use ($userId, $api) {
        $record = plaid_read($userId);
        if (!$record) { throw new PlaidFailure('NOT_CONNECTED'); }
        if (time() - ($record['checked_at'] ?? 0) < 5) { return ['ready' => !empty($record['snapshot']), 'retry_after' => 5]; }
        $record['checked_at'] = time(); plaid_write($userId, $record);
        $response = $api('/accounts/get', ['access_token' => $record['access_token']]);
        $accounts = [];
        foreach ($response['accounts'] ?? [] as $account) {
            if (($account['type'] ?? '') === 'credit' && ($account['subtype'] ?? '') === 'credit card') {
                $accounts[$account['account_id']] = ['name' => (string) $account['name'], 'mask' => $account['mask'] ?? null];
            }
        }
        if (!$accounts) { throw new PlaidFailure('NO_CREDIT_CARD'); }
        $start = gmdate('Y-m-d', strtotime('-90 days')); $end = gmdate('Y-m-d');
        $transactions = []; $offset = 0; $total = 0;
        // A fresh bounded snapshot avoids pending/posted duplicates across refreshes.
        do {
            try {
                $response = $api('/transactions/get', ['access_token' => $record['access_token'], 'start_date' => $start, 'end_date' => $end,
                    'options' => ['account_ids' => array_keys($accounts), 'count' => 500, 'offset' => $offset]]);
            } catch (PlaidFailure $error) {
                if ($error->getMessage() === 'PRODUCT_NOT_READY') { return ['ready' => false, 'retry_after' => 5]; }
                throw $error;
            }
            if (!is_array($response['transactions'] ?? null) || !isset($response['total_transactions'])) { throw new PlaidFailure('INVALID_RESPONSE'); }
            $batch = $response['transactions']; $total = (int) $response['total_transactions'];
            foreach ($batch as $transaction) {
                if (isset($accounts[$transaction['account_id'] ?? '']) && is_string($transaction['transaction_id'] ?? null)) {
                    $transactions[$transaction['transaction_id']] = plaid_transaction_view($transaction, $accounts);
                }
            }
            $offset += count($batch);
        } while ($offset < $total && $offset < 1000 && count($batch) > 0);
        $transactions = array_values($transactions);
        usort($transactions, fn($a, $b) => strcmp($b['date'], $a['date']));
        $record['snapshot'] = ['transactions' => $transactions, 'accounts' => array_values($accounts), 'total' => $total,
            'limited' => $offset < $total, 'start' => $start, 'end' => $end, 'retrieved_at' => gmdate('c')];
        plaid_write($userId, $record);
        return ['ready' => true, 'count' => count($transactions)];
    });
}

function plaid_error_message(Throwable $error): string
{
    return match ($error instanceof PlaidFailure ? $error->getMessage() : '') {
        'CONFIG_UNAVAILABLE', 'SANDBOX_REQUIRED' => 'Plaid Sandbox is not configured. Please contact the app owner.',
        'ALREADY_CONNECTED' => 'A Sandbox card is already connected. Refresh its transactions below.',
        'NOT_CONNECTED' => 'Connect a Sandbox credit card first.',
        'NO_CREDIT_CARD' => 'The connection contains no credit-card account. Please contact the app owner to reset this Sandbox connection.',
        'ITEM_LOGIN_REQUIRED' => 'Plaid needs this Sandbox connection to be relinked. Please contact the app owner.',
        'INVALID_PUBLIC_TOKEN' => 'The connection token expired or was invalid. Open Connect Credit Card and try again.',
        'BUSY', 'RATE_LIMIT_EXCEEDED' => 'A request is already running or Plaid is busy. Please try again shortly.',
        default => 'Plaid could not complete this request. Your saved connection is preserved. Please try again.',
    };
}
