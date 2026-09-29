<?php
declare(strict_types=1);

/** Bridge-only client: user-provided tokens cannot cause arbitrary server requests. */
function simplefin_url(string $url, bool $claim): array
{
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', ['bridge.simplefin.org', 'beta-bridge.simplefin.org'], true)
        || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
        throw new InvalidArgumentException('Use a setup token issued by SimpleFIN Bridge.');
    }
    if ($claim) {
        if (isset($parts['user']) || isset($parts['pass']) || !preg_match('~\A/simplefin/claim/[A-Za-z0-9_-]+\z~', $parts['path'] ?? '')) { throw new InvalidArgumentException('Invalid SimpleFIN setup token.'); }
    } elseif (empty($parts['user']) || empty($parts['pass']) || ($parts['path'] ?? '') !== '/simplefin') {
        throw new RuntimeException('Invalid SimpleFIN access response.');
    }
    return $parts;
}

function simplefin_http(string $url, bool $claim, array $query = []): string
{
    $p = simplefin_url($url, $claim);
    $safeUrl = 'https://' . $p['host'] . $p['path'] . ($claim ? '' : '/accounts?' . http_build_query($query));
    $curl = curl_init($safeUrl); $body = '';
    curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_POST => $claim,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 8388608) { return 0; } $body .= $chunk; return strlen($chunk);
        }]);
    if ($claim) { curl_setopt($curl, CURLOPT_POSTFIELDS, ''); }
    else { curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC); curl_setopt($curl, CURLOPT_USERPWD, rawurldecode($p['user']) . ':' . rawurldecode($p['pass'])); }
    try {
        $ok = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($ok === false) { throw new RuntimeException('SimpleFIN could not be reached. Try again later.'); }
        if ($code === 403) { throw new InvalidArgumentException($claim ? 'This setup token is invalid or already used. Revoke it in SimpleFIN if you did not use it, then generate a new token.' : 'SimpleFIN access was revoked or expired. Reconnect with a new setup token.'); }
        if ($code === 402) { throw new InvalidArgumentException('SimpleFIN requires an active subscription. Check your SimpleFIN account.'); }
        if ($code < 200 || $code >= 300) { throw new RuntimeException('SimpleFIN returned an error. Try again later.'); }
        return $body;
    } finally { curl_close($curl); }
}

function simplefin_directory(): string
{
    $path = getenv('DAN_SIMPLEFIN_STORAGE') ?: '/home/cayeldo/.config/dan/simplefin';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new RuntimeException('Private connection storage is unavailable.'); }
    $real = realpath($path); $web = realpath(__DIR__);
    if (!$real || $real === $web || str_starts_with($real, $web . '/') || (fileperms($real) & 0077) || !is_writable($real)) { throw new RuntimeException('Private connection storage is unavailable.'); }
    return $real;
}
function simplefin_path(int $user): string
{
    if ($user <= 0) { throw new InvalidArgumentException('Sign in again.'); }
    return simplefin_directory() . '/user-' . $user . '.json';
}
function simplefin_read(int $user): ?array
{
    $path = simplefin_path($user);
    if (!is_file($path)) { return null; }
    if (fileperms($path) & 0077) { throw new RuntimeException('Connection file permissions must be private.'); }
    $record = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (($record['user_id'] ?? null) !== $user) { throw new RuntimeException('Invalid connection record.'); }
    return $record;
}
function simplefin_write(int $user, array $record): void
{
    $path = simplefin_path($user); $temp = tempnam(dirname($path), '.sfin-');
    if (!$temp) { throw new RuntimeException('Connection could not be saved.'); }
    try {
        chmod($temp, 0600); $json = json_encode($record, JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $path)) { throw new RuntimeException('Connection could not be saved.'); }
    } finally { if (is_file($temp)) { unlink($temp); } }
}
function simplefin_locked(int $user, callable $action): mixed
{
    $old = umask(0077); try { $lock = fopen(simplefin_path($user) . '.lock', 'c'); } finally { umask($old); }
    if (!$lock) { throw new RuntimeException('Connection storage is unavailable.'); }
    try { if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new InvalidArgumentException('A sync is already running. Try again shortly.'); } return $action(); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}
function simplefin_remote_key(array $account): string { return hash('sha256', ($account['conn_id'] ?? '') . "\0" . $account['id']); }

function simplefin_connect(int $user, string $token, ?callable $http = null): void
{
    $http ??= 'simplefin_http';
    $url = base64_decode(trim($token), true);
    if ($url === false || strlen($token) > 4096) { throw new InvalidArgumentException('Paste the SimpleFIN setup token.'); }
    simplefin_url($url, true);
    simplefin_locked($user, function () use ($user, $url, $http) {
        if (simplefin_read($user)) { throw new InvalidArgumentException('Disconnect the existing connection before replacing it.'); }
        $access = trim($http($url, true)); simplefin_url($access, false);
        simplefin_write($user, ['user_id' => $user, 'access_url' => $access, 'connected_at' => gmdate('c'), 'checked_at' => 0,
            'next_sync' => time(), 'last_success' => null, 'full_refresh' => true, 'snapshot' => null, 'message' => '', 'demo' => str_contains($url, '/DEMO-') || str_ends_with($url, '/demo')]);
    });
}

/** Escape at display time; redact secrets before retaining provider messages. */
function simplefin_errors(array $data, string $access): array
{
    $parts = simplefin_url($access, false); $messages = [];
    foreach ([...($data['errlist'] ?? []), ...($data['errors'] ?? [])] as $error) {
        $msg = is_array($error) ? ($error['msg'] ?? 'SimpleFIN reported an account error.') : $error;
        if (!is_string($msg)) { continue; }
        $msg = str_replace([$access, $parts['user'], $parts['pass'], rawurldecode($parts['pass'])], '[redacted]', $msg);
        $messages[] = mb_substr(preg_replace('~https?://\S+~', '[link omitted]', $msg), 0, 500);
    }
    return array_values(array_unique($messages));
}

function simplefin_rows(array $account, string $start): array
{
    if (($account['currency'] ?? '') !== 'USD') { throw new InvalidArgumentException('Only USD accounts are supported in the spending reports.'); }
    if (!is_array($account['transactions'] ?? null)) { throw new InvalidArgumentException('SimpleFIN did not provide a transaction list.'); }
    $rows = [];
    foreach ($account['transactions'] as $t) {
        if (!is_array($t) || !is_string($t['id'] ?? null) || $t['id'] === '' || !is_string($t['amount'] ?? null) || !is_string($t['description'] ?? null)) { throw new InvalidArgumentException('SimpleFIN returned an incomplete transaction.'); }
        if (($t['pending'] ?? false) === true) { continue; }
        if (!is_int($t['posted'] ?? null) || $t['posted'] <= 0) { throw new InvalidArgumentException('A posted transaction has no valid date.'); }
        $date = gmdate('Y-m-d', $t['posted']);
        if ($date < $start || $date > gmdate('Y-m-d')) { continue; }
        $description = clean_label($t['description'], 500); $amount = parse_cents($t['amount']);
        $payment = $amount > 0 && preg_match('/\b(?:PAYMENT|AUTOPAY|AUTO PAY|PYMT|PMT)\b/i', $description);
        $kind = $payment ? 'payment' : ($amount < 0 ? 'expense' : 'refund');
        $rows[] = ['date' => $date, 'description' => $description, 'memo' => '', 'amount' => $amount, 'kind' => $kind,
            'reference' => 'sfin:' . hash('sha256', $t['id']), 'dedupe_key' => hash('sha256', 'simplefin:' . simplefin_remote_key($account) . ':' . $t['id']),
            'suggestion' => $kind === 'payment' ? null : classify_merchant($description)];
    }
    return $rows;
}

function simplefin_link(PDO $db, int $user): ?array
{
    return analyzer_query($db, 'SELECT s.*, a.label FROM analyzer_simplefin_links s JOIN analyzer_accounts a ON a.id = s.account_id AND a.user_id = s.user_id WHERE s.user_id = ?', [$user])->fetch() ?: null;
}

/** Update only stable source IDs; never delete absent rows from a partial provider response. */
function simplefin_import(PDO $db, int $user, array $link, array $account): array
{
    $added = 0; $updated = 0; $skipped = 0;
    $db->beginTransaction();
    try {
        analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
        $current = simplefin_link($db, $user);
        if (!$current || !(bool) $current['enabled'] || $current['remote_key'] !== simplefin_remote_key($account)) { throw new InvalidArgumentException('This account is no longer enabled.'); }
        $rows = simplefin_rows($account, $current['start_date']);
        $accountId = (int) $current['account_id'];
        $hash = hash('sha256', 'simplefin:' . random_bytes(24));
        analyzer_query($db, 'INSERT INTO analyzer_imports (user_id, account_id, filename, file_hash, row_count) VALUES (?, ?, ?, ?, ?)', [$user, $accountId, 'SimpleFIN automatic sync', $hash, count($rows)]);
        $importId = (int) $db->lastInsertId();
        foreach ($rows as $row) {
            $existing = analyzer_query($db, 'SELECT * FROM analyzer_transactions WHERE user_id = ? AND account_id = ? AND dedupe_key = ?', [$user, $accountId, $row['dedupe_key']])->fetch();
            if ($existing && $existing['transaction_date'] === $row['date'] && (int) $existing['amount_cents'] === $row['amount'] && $existing['description'] === $row['description'] && $existing['kind'] === $row['kind']) { $skipped++; continue; }
            // Preserve manual merchant/category choices when the provider corrects amount/date/description.
            $merchant = $row['kind'] === 'payment' ? null : (($existing['merchant_id'] ?? null) ?: resolve_merchant($db, $user, $row['suggestion']));
            if ($existing) {
                analyzer_query($db, 'UPDATE analyzer_transactions SET transaction_date = ?, description = ?, amount_cents = ?, kind = ?, merchant_id = ? WHERE id = ? AND user_id = ?', [$row['date'], $row['description'], $row['amount'], $row['kind'], $merchant, $existing['id'], $user]); $updated++;
            } else {
                analyzer_query($db, 'INSERT INTO analyzer_transactions (user_id, account_id, import_id, merchant_id, transaction_date, description, memo, amount_cents, kind, bank_reference, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$user, $accountId, $importId, $merchant, $row['date'], $row['description'], '', $row['amount'], $row['kind'], $row['reference'], $row['dedupe_key']]); $added++;
            }
        }
        if (!$added && !$updated) { analyzer_query($db, 'DELETE FROM analyzer_imports WHERE id = ?', [$importId]); }
        else { analyzer_query($db, 'UPDATE analyzer_imports SET added_count = ?, skipped_count = ? WHERE id = ?', [$added, $skipped, $importId]); }
        $db->commit(); return compact('added', 'updated', 'skipped');
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
}

function simplefin_fetch(PDO $db, int $user, ?callable $http = null, bool $automatic = false): array
{
    $http ??= 'simplefin_http';
    return simplefin_locked($user, function () use ($db, $user, $http, $automatic) {
        $record = simplefin_read($user);
        if (!$record) { throw new InvalidArgumentException('Connect SimpleFIN first.'); }
        $link = simplefin_link($db, $user);
        if ($automatic && (!$link || !$link['enabled'] || $record['next_sync'] > time())) { return $record; }
        if (time() - $record['checked_at'] < 3600) { throw new InvalidArgumentException('A refresh was requested recently. Try again after ' . gmdate('H:i', $record['checked_at'] + 3600) . ' UTC.'); }
        $record['checked_at'] = time(); $record['next_sync'] = time() + 21600 + random_int(60, 1800); simplefin_write($user, $record);
        $end = time(); $start = $end - 44 * 86400;
        // Always overlap recent data; after long gaps the provider's recommended 45-day window still applies.
        if ($record['last_success'] && $link && empty($record['full_refresh'])) { $start = max($start, strtotime($record['last_success']) - 5 * 86400); }
        try {
            $query = ['version' => 2, 'start-date' => $start, 'end-date' => $end, 'pending' => 1];
            if ($link) { $query['account'] = $link['remote_id']; }
            $data = json_decode($http($record['access_url'], false, $query), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data['accounts'] ?? null)) { throw new RuntimeException('SimpleFIN returned an invalid response.'); }
            $errors = simplefin_errors($data, $record['access_url']);
            $record['snapshot'] = ['accounts' => $data['accounts'], 'errors' => $errors, 'retrieved_at' => gmdate('c')];
            if ($errors) { $record['message'] = 'SimpleFIN reported an issue. Imports are paused for this refresh; see the messages below.'; simplefin_write($user, $record); return $record; }
            if ($link && $link['enabled']) {
                $selected = array_values(array_filter($data['accounts'], fn($a) => simplefin_remote_key($a) === $link['remote_key']));
                if (count($selected) !== 1) { throw new InvalidArgumentException('The selected card was not returned. Check its connection in SimpleFIN.'); }
                $counts = simplefin_import($db, $user, $link, $selected[0]);
                $record['message'] = $counts['added'] . ' transactions added; ' . $counts['updated'] . ' updated; ' . $counts['skipped'] . ' already saved.';
            } else { $record['message'] = 'Accounts retrieved. Select your credit card to enable automatic imports.'; }
            $record['last_success'] = gmdate('c'); $record['full_refresh'] = false; simplefin_write($user, $record); return $record;
        } catch (Throwable $error) {
            $record['message'] = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Refresh failed. Previously imported transactions are preserved; try again later.';
            simplefin_write($user, $record); throw $error;
        }
    });
}

function simplefin_enable(PDO $db, int $user, string $remoteKey, int $accountId, string $label, string $start): void
{
    simplefin_locked($user, function () use ($db, $user, $remoteKey, $accountId, $label, $start) {
        $record = simplefin_read($user);
        if (!$record || !$record['snapshot'] || $record['snapshot']['errors']) { throw new InvalidArgumentException('Retrieve accounts successfully before enabling imports.'); }
        if ($record['demo']) { throw new InvalidArgumentException('Demo accounts are preview-only. Use your own SimpleFIN setup token to enable imports.'); }
        $selected = array_values(array_filter($record['snapshot']['accounts'], fn($a) => simplefin_remote_key($a) === $remoteKey));
        if (count($selected) !== 1 || ($selected[0]['currency'] ?? '') !== 'USD') { throw new InvalidArgumentException('Select one of your USD credit-card accounts.'); }
        if (!preg_match('/\A20\d{2}-\d{2}-\d{2}\z/', $start) || (new DateTimeImmutable($start))->format('Y-m-d') !== $start
            || $start < gmdate('Y-m-d', time() - 44 * 86400) || $start > gmdate('Y-m-d', time() + 86400)) { throw new InvalidArgumentException('Choose a start date within the last 44 days or tomorrow.'); }
        // Validate all the selected rows before changing any settings.
        simplefin_rows($selected[0], $start);
        $db->beginTransaction();
        try {
            analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
            $existingLink = simplefin_link($db, $user);
            if ($existingLink && $existingLink['enabled']) { throw new InvalidArgumentException('Pause the current connection before changing its settings.'); }
            if ($accountId > 0) {
                if (!analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE id = ? AND user_id = ?', [$accountId, $user])->fetchColumn()) { throw new InvalidArgumentException('Choose one of your cards.'); }
            } else {
                $label = clean_label($label, 80);
                if (analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE user_id = ? AND label = ?', [$user, $label])->fetchColumn()) { throw new InvalidArgumentException('That card name already exists. Select the existing card instead.'); }
                analyzer_query($db, 'INSERT INTO analyzer_accounts (user_id, label) VALUES (?, ?)', [$user, $label]); $accountId = (int) $db->lastInsertId();
            }
            $last = analyzer_query($db, 'SELECT MAX(transaction_date) FROM analyzer_transactions WHERE user_id = ? AND account_id = ?', [$user, $accountId])->fetchColumn();
            if ($last && $start <= $last) { throw new InvalidArgumentException('To avoid overlap with existing history, choose a start date after ' . $last . '.'); }
            if ($existingLink) { analyzer_query($db, 'DELETE FROM analyzer_simplefin_links WHERE user_id = ?', [$user]); }
            analyzer_query($db, 'INSERT INTO analyzer_simplefin_links (user_id, account_id, remote_key, remote_id, start_date) VALUES (?, ?, ?, ?, ?)', [$user, $accountId, $remoteKey, $selected[0]['id'], $start]);
            $cutoff = analyzer_query($db, 'SELECT start_date FROM analyzer_simplefin_history WHERE user_id = ? AND account_id = ?', [$user, $accountId])->fetchColumn();
            if (!$cutoff) {
                analyzer_query($db, 'INSERT INTO analyzer_simplefin_history (user_id, account_id, start_date) VALUES (?, ?, ?)', [$user, $accountId, $start]);
            } elseif ($start < $cutoff) {
                analyzer_query($db, 'UPDATE analyzer_simplefin_history SET start_date = ? WHERE user_id = ? AND account_id = ?', [$start, $user, $accountId]);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
        $link = simplefin_link($db, $user);
        $counts = simplefin_import($db, $user, $link, $selected[0]);
        $record['message'] = 'Automatic imports enabled. ' . $counts['added'] . ' posted transactions imported.';
        // Ensure the next fetch includes the new cutoff even after an older connection was replaced.
        $record['full_refresh'] = true;
        simplefin_write($user, $record);
    });
}

function simplefin_pause(PDO $db, int $user, bool $pause): void
{
    simplefin_locked($user, function () use ($db, $user, $pause) {
        if (!$pause && !simplefin_read($user)) { throw new InvalidArgumentException('Reconnect SimpleFIN first.'); }
        analyzer_query($db, 'UPDATE analyzer_simplefin_links SET enabled = ? WHERE user_id = ?', [(int) !$pause, $user]);
        if (!$pause) {
            $record = simplefin_read($user); $record['full_refresh'] = true;
            simplefin_write($user, $record);
        }
    });
}

function simplefin_disconnect(PDO $db, int $user): void
{
    simplefin_locked($user, function () use ($db, $user) {
        analyzer_query($db, 'UPDATE analyzer_simplefin_links SET enabled = 0 WHERE user_id = ?', [$user]);
        $path = simplefin_path($user); if (is_file($path) && !unlink($path)) { throw new RuntimeException('Connection could not be removed.'); }
        // Historical records and the CSV cutoff remain. Revoke the token at SimpleFIN too.
    });
}
