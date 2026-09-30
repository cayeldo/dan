<?php
declare(strict_types=1);
require_once __DIR__ . '/ai-categories.php';

const ANALYZER_MAX_BYTES = 2097152;
const ANALYZER_MAX_ROWS = 10000;

function analyzer_categories(): array
{
    return ['Groceries', 'Restaurants', 'Food Delivery', 'Transportation', 'Fuel', 'Utilities',
        'Shopping', 'Home Improvement', 'Health & Pharmacy', 'Entertainment', 'Subscriptions',
        'Alcohol', 'Government & Services', 'Travel', 'Fees & Interest', 'Uncategorized'];
}

function clean_label(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException("Enter a name between 1 and {$limit} characters.");
    }
    return $value;
}

function merchant_match_key(string $name): string
{
    return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name)));
}

/** Specific delivery/fuel rules must precede broader merchant rules. */
function classify_merchant(string $description, string $mcc = ''): array
{
    $text = strtoupper(preg_replace('/\s+/', ' ', trim($description)) ?? $description);
    $rules = [
        ['~\bUBER\s*\*?\s*EATS\b~', 'Uber Eats', 'Food Delivery'],
        ['~\bUBER\s*\*?\s*TRIP\b|\bLYFT\b~', str_contains($text, 'LYFT') ? 'Lyft' : 'Uber Trip', 'Transportation'],
        ['~\bDOORDASH\b|^DD\s*\*~', 'DoorDash', 'Food Delivery'],
        ['~GRUBHUB|GRHUB~', 'Grubhub', 'Food Delivery'],
        ['~\bINSTACART\b~', 'Instacart', 'Groceries'],
        ['~\bCOSTCO\s+(GAS|FUEL)\b~', 'Costco Gas', 'Fuel'],
        ['~\bCOSTCO\b~', 'Costco', 'Groceries'],
        ['~\bRED\s*ROBIN\b~', 'Red Robin', 'Restaurants'],
        ['~\bTRADER\s*JOE\b~', "Trader Joe’s", 'Groceries'],
        ['~\bWEGMANS\b~', 'Wegmans', 'Groceries'],
        ['~\bHARRIS\s*TEETER\b~', 'Harris Teeter', 'Groceries'],
        ['~^GIANT\b~', 'Giant', 'Groceries'],
        ['~\bWHOLE\s*FOODS\b~', 'Whole Foods', 'Groceries'],
        ['~\bTATTE\s*BAKERY\b~', 'Tatte Bakery', 'Restaurants'],
        ['~\bSWEETGREEN\b~', 'Sweetgreen', 'Restaurants'],
        ['~\bMCDONALD[\x27’]?S\b~u', "McDonald’s", 'Restaurants'],
        ['~\bBWW\b|BUFFALO\s*WILD\s*WINGS~', 'Buffalo Wild Wings', 'Restaurants'],
        ['~\bLONGHORN\b~', 'LongHorn Steakhouse', 'Restaurants'],
        ['~\bOLIVE\s*GARDEN\b~', 'Olive Garden', 'Restaurants'],
        ['~\bJERSEY\s*MIKES?\b~', 'Jersey Mike’s', 'Restaurants'],
        ['~\bPLAYA\s*BOWLS\b~', 'Playa Bowls', 'Restaurants'],
        ['~\bCHILI[\x27’]?S\b~u', 'Chili’s', 'Restaurants'],
        ['~\bBAR\s*TACO\b~', 'bartaco', 'Restaurants'],
        ['~\bCAPITAL\s*BRG\b~', 'Capital Burger', 'Restaurants'],
        ['~\bAMPHORA\b~', 'Amphora Diner', 'Restaurants'],
        ['~\bFRIENDS\s*KABOB\b~', 'Friends Kabob', 'Restaurants'],
        ['~\bGAR JACKSONS~', 'Jackson’s', 'Restaurants'],
        ['~\bJIMMY[\x27’]?S OLD TOWN~u', 'Jimmy’s Old Town Tavern', 'Restaurants'],
        ['~\bMAKERS\s*UNION~', 'Makers Union', 'Restaurants'],
        ['~\bCRAFTHOUSE\b~', 'Crafthouse', 'Restaurants'],
        ['~\bSILVER\s*DINER\b~', 'Silver Diner', 'Restaurants'],
        ['~\bTGI\s*FRIDAY~', 'TGI Fridays', 'Restaurants'],
        ['~\bPITANGO\b~', 'Pitango Gelato', 'Restaurants'],
        ['~\bTHE BULLPEN\b~', 'The Bullpen', 'Restaurants'],
        ['~\bWILLARDS\b~', 'Willard’s BBQ', 'Restaurants'],
        ['~\bLEVY@.*NATIONALS~', 'Nationals Park concessions', 'Restaurants'],
        ['~^WONDER\b~', 'Wonder', 'Food Delivery'],
        ['~\bEXXON\b~', 'Exxon', 'Fuel'],
        ['~\bSHELL\b~', 'Shell', 'Fuel'],
        ['~\bCOLUMBIA\s*GAS\b~', 'Columbia Gas', 'Utilities'],
        ['~\bLOWE[\x27’]?S\b~u', 'Lowe’s', 'Home Improvement'],
        ['~\bHOME\s*DEPOT\b~', 'Home Depot', 'Home Improvement'],
        ['~\bCVS\b~', 'CVS Pharmacy', 'Health & Pharmacy'],
        ['~\bWALGREENS\b~', 'Walgreens', 'Health & Pharmacy'],
        ['~\bBEST\s*BUY\b~', 'Best Buy', 'Shopping'],
        ['~\bTARGET\b~', 'Target', 'Shopping'],
        ['~\bPLAYSTATION\b~', 'PlayStation', 'Entertainment'],
        ['~\bSEATGEEK\b~', 'SeatGeek', 'Entertainment'],
        ['~\bARTLIST\b~', 'Artlist', 'Subscriptions'],
        ['~\bTOTAL\s*WINE\b~', 'Total Wine', 'Alcohol'],
        ['~\bSOLID\s*WASTE\b~', 'Solid Waste Disposal', 'Government & Services'],
    ];
    foreach ($rules as [$pattern, $name, $category]) {
        if (preg_match($pattern, $text)) {
            return ['name' => $name, 'category' => $category, 'needs_review' => false, 'key' => merchant_match_key($name)];
        }
    }
    // Keep unknown businesses separate; avoid fuzzy merging unrelated charges.
    $name = preg_split('/\s{2,}/', trim($description))[0];
    $name = preg_replace('/^(TST|SQ)\s*\*\s*/i', '', $name) ?? $name;
    $name = preg_replace('/\s+(?:#\s*|NO\.?\s*)\d+\s*$/i', '', $name) ?? $name;
    $name = mb_convert_case(mb_substr(trim($name), 0, 120), MB_CASE_TITLE, 'UTF-8');
    $mccCategories = ['5411' => 'Groceries', '5499' => 'Groceries', '5812' => 'Restaurants', '5813' => 'Restaurants',
        '5814' => 'Restaurants', '4121' => 'Transportation', '5541' => 'Fuel', '5542' => 'Fuel', '4900' => 'Utilities',
        '5912' => 'Health & Pharmacy', '5200' => 'Home Improvement', '5300' => 'Shopping', '5310' => 'Shopping',
        '5732' => 'Shopping', '7922' => 'Entertainment'];
    return ['name' => $name, 'category' => $mccCategories[ltrim($mcc, '0')] ?? 'Uncategorized',
        'needs_review' => true, 'key' => merchant_match_key($name)];
}

function parse_cents(string $value): int
{
    $value = trim($value);
    $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
    if ($negative) { $value = substr($value, 1, -1); }
    $value = str_replace(['$', ','], '', $value);
    if (!preg_match('/\A([+-]?)(\d{1,9})(?:\.(\d{1,2}))?\z/', $value, $parts)) {
        throw new InvalidArgumentException('Amount must be a number with at most two decimal places.');
    }
    $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');
    return ($negative || $parts[1] === '-') ? -$cents : $cents;
}

function parse_transaction_date(string $value): string
{
    foreach (['!n/j/y' => 'n/j/y', '!n/j/Y' => 'n/j/Y', '!Y-m-d' => 'Y-m-d'] as $format => $_) {
        $date = DateTimeImmutable::createFromFormat($format, trim($value), new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && (int) $date->format('Y') >= 2000 && (int) $date->format('Y') <= 2099) {
            return $date->format('Y-m-d');
        }
    }
    throw new InvalidArgumentException('Use dates in M/D/YY, M/D/YYYY, or YYYY-MM-DD format.');
}

/** Parse only data; CSV contents never become instructions or executable code. */
function parse_statement(string $contents, string $sign = 'negative'): array
{
    if (!in_array($sign, ['negative', 'positive'], true)) { throw new InvalidArgumentException('Choose the charge amount format.'); }
    if ($contents === '' || strlen($contents) > ANALYZER_MAX_BYTES) { throw new InvalidArgumentException('Choose a nonempty CSV smaller than 2 MB.'); }
    if (!mb_check_encoding($contents, 'UTF-8')) { throw new InvalidArgumentException('Save the CSV as UTF-8 and try again.'); }
    if (str_contains($contents, "\0")) { throw new InvalidArgumentException('The upload is not a text CSV.'); }
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $contents));
    rewind($handle);
    try {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($header)) { throw new InvalidArgumentException('The CSV needs a header row.'); }
        $columns = [];
        $aliases = ['date' => 'date', 'transaction date' => 'date', 'name' => 'name', 'description' => 'name', 'merchant' => 'name',
            'amount' => 'amount', 'memo' => 'memo', 'transaction' => 'type', 'type' => 'type', 'transaction type' => 'type',
            'transaction id' => 'reference', 'reference number' => 'reference'];
        foreach ($header as $i => $label) {
            $key = $aliases[strtolower(trim((string) $label))] ?? null;
            if ($key !== null) {
                if (isset($columns[$key])) { throw new InvalidArgumentException('The CSV has ambiguous duplicate columns.'); }
                $columns[$key] = $i;
            }
        }
        foreach (['date', 'name', 'amount'] as $required) {
            if (!isset($columns[$required])) { throw new InvalidArgumentException('CSV columns must include Date, Name (or Description), and Amount. Memo and Transaction are optional.'); }
        }
        $rows = []; $occurrences = []; $line = 1;
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn($v) => trim((string) $v) !== '')) === 0) { continue; }
            if (count($rows) >= ANALYZER_MAX_ROWS) { throw new InvalidArgumentException('Upload at most 10,000 transactions at a time.'); }
            try {
                if (count($cells) !== count($header)) { throw new InvalidArgumentException('The number of columns does not match the header.'); }
                $get = fn(string $key): string => isset($columns[$key]) ? trim((string) $cells[$columns[$key]]) : '';
                $date = parse_transaction_date($get('date'));
                // Preserve the original descriptor for later audit and matching.
                $description = $get('name');
                if ($description === '' || mb_strlen($description) > 500) { throw new InvalidArgumentException('A merchant description is missing or too long.'); }
                $memo = $get('memo');
                if (strlen($memo) > 4000) { throw new InvalidArgumentException('Memo is too long.'); }
                $amount = parse_cents($get('amount')) * ($sign === 'positive' ? -1 : 1);
                if ($amount === 0) { throw new InvalidArgumentException('Zero-dollar rows cannot be imported.'); }
                $isPayment = preg_match('/^(?:PAYMENT (?:MADE|RECEIVED|THANK)|AUTOPAY|AUTOMATIC PAYMENT|ONLINE PAYMENT|INTERNET PAYMENT|THANK YOU.*PAYMENT)/i', $description)
                    || in_array(strtoupper($get('type')), ['PAYMENT', 'CARD PAYMENT'], true);
                if ($isPayment && $amount < 0) { throw new InvalidArgumentException('A card payment has the sign of a purchase. Check the charge amount format.'); }
                $kind = $isPayment ? 'payment' : ($amount < 0 ? 'expense' : 'refund');
                $parts = array_map('trim', explode(';', $memo));
                $reference = $get('reference');
                if ($reference === '' && preg_match('/^\d{12,32}$/', $parts[0] ?? '')) { $reference = $parts[0]; }
                if (strlen($reference) > 100) { throw new InvalidArgumentException('Bank reference is too long.'); }
                $identity = $reference !== '' ? 'bank:' . $reference : 'fallback:' . json_encode([$date,
                    strtoupper(preg_replace('/\s+/', ' ', $description)), $amount, $kind], JSON_THROW_ON_ERROR);
                if ($reference === '') {
                    $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
                    $identity .= ':' . $occurrences[$identity];
                }
                $rows[] = ['date' => $date, 'description' => $description, 'memo' => $memo, 'amount' => $amount,
                    'kind' => $kind, 'reference' => $reference ?: null, 'dedupe_key' => hash('sha256', $identity),
                    'suggestion' => $kind === 'payment' ? null : classify_merchant($description, $parts[1] ?? '')];
            } catch (InvalidArgumentException $error) {
                throw new InvalidArgumentException('Row ' . $line . ': ' . $error->getMessage());
            }
        }
        if ($rows === []) { throw new InvalidArgumentException('The CSV has no transactions.'); }
        return $rows;
    } finally { fclose($handle); }
}

function analyzer_query(PDO $db, string $sql, array $params = []): PDOStatement
{
    $query = $db->prepare($sql); $query->execute($params); return $query;
}

function user_accounts(PDO $db, int $userId): array
{
    return analyzer_query($db, 'SELECT id, label FROM analyzer_accounts WHERE user_id = ? ORDER BY label', [$userId])->fetchAll();
}

function get_category(PDO $db, int $userId, string $name): int
{
    $id = analyzer_query($db, 'SELECT id FROM analyzer_categories WHERE user_id = ? AND name = ?', [$userId, $name])->fetchColumn();
    if ($id !== false) { return (int) $id; }
    analyzer_query($db, 'INSERT INTO analyzer_categories (user_id, name) VALUES (?, ?)', [$userId, $name]);
    return (int) $db->lastInsertId();
}

function resolve_merchant(PDO $db, int $userId, array $suggestion): int
{
    $id = analyzer_query($db, 'SELECT merchant_id FROM analyzer_aliases WHERE user_id = ? AND match_key = ?', [$userId, $suggestion['key']])->fetchColumn();
    if ($id !== false) { enqueue_merchant_ai($db, $userId, (int) $id); return (int) $id; }
    $id = analyzer_query($db, 'SELECT id FROM analyzer_merchants WHERE user_id = ? AND name = ?', [$userId, $suggestion['name']])->fetchColumn();
    if ($id === false) {
        $categoryId = get_category($db, $userId, $suggestion['category']);
        analyzer_query($db, 'INSERT INTO analyzer_merchants (user_id, name, category_id, needs_review) VALUES (?, ?, ?, ?)',
            [$userId, $suggestion['name'], $categoryId, (int) $suggestion['needs_review']]);
        $id = (int) $db->lastInsertId();
    }
    analyzer_query($db, 'INSERT INTO analyzer_aliases (user_id, match_key, merchant_id) VALUES (?, ?, ?)', [$userId, $suggestion['key'], $id]);
    enqueue_merchant_ai($db, $userId, (int) $id);
    return (int) $id;
}

function duplicate_matches(PDO $db, int $userId, int $accountId, array $rows): array
{
    $existing = [];
    foreach (array_chunk(array_column($rows, 'dedupe_key'), 400) as $keys) {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $found = analyzer_query($db, "SELECT dedupe_key, transaction_date, amount_cents, kind FROM analyzer_transactions WHERE user_id = ? AND account_id = ? AND dedupe_key IN ($placeholders)", [$userId, $accountId, ...$keys])->fetchAll();
        foreach ($found as $row) { $existing[$row['dedupe_key']] = $row; }
        $aliases = analyzer_query($db, "SELECT a.dedupe_key, t.transaction_date, t.amount_cents, t.kind FROM analyzer_csv_feed_matches a JOIN analyzer_transactions t ON t.id = a.transaction_id AND t.user_id = a.user_id AND t.account_id = a.account_id WHERE a.user_id = ? AND a.account_id = ? AND a.dedupe_key IN ($placeholders)", [$userId, $accountId, ...$keys])->fetchAll();
        foreach ($aliases as $row) { $existing[$row['dedupe_key']] = $row; }
    }
    return $existing;
}

/** Duplicate bank references with changed financial data need human review, not silent skipping. */
function check_duplicate(array $row, array $existing): void
{
    if ($row['date'] !== $existing['transaction_date'] || $row['amount'] !== (int) $existing['amount_cents'] || $row['kind'] !== $existing['kind']) {
        throw new InvalidArgumentException('A bank reference matches an existing transaction with a different date or amount. Nothing was imported. Check the original statement or charge amount format.');
    }
}

/** Use source descriptions, not editable categories or merchant names, for matching. */
function csv_feed_signature(string $date, int $amount, string $kind, string $description): string
{
    $merchant = classify_merchant($description);
    $name = $kind !== 'payment' && !$merchant['needs_review'] ? $merchant['name'] : $description;
    return hash('sha256', json_encode([$date, $amount, $kind, merchant_match_key($name)], JSON_THROW_ON_ERROR));
}

/** Match overlap one-to-one; never silently discard an unmatched or ambiguous charge. */
function csv_import_matches(PDO $db, int $userId, int $accountId, array $rows): array
{
    $matches = duplicate_matches($db, $userId, $accountId, $rows);
    if (!$accountId) { return $matches; }
    $cutoff = analyzer_query($db, 'SELECT start_date FROM analyzer_simplefin_history WHERE user_id = ? AND account_id = ?', [$userId, $accountId])->fetchColumn();
    if (!$cutoff) { return $matches; }
    $groups = [];
    foreach ($rows as $row) {
        if (isset($matches[$row['dedupe_key']])) { check_duplicate($row, $matches[$row['dedupe_key']]); continue; }
        if ($row['date'] < $cutoff) { continue; }
        $signature = csv_feed_signature($row['date'], $row['amount'], $row['kind'], $row['description']);
        $groups[$signature][$row['dedupe_key']] = $row;
    }
    if (!$groups) { return $matches; }
    $dates = array_column($rows, 'date');
    $feed = analyzer_query($db, "SELECT t.id, t.transaction_date, t.amount_cents, t.kind, t.description, a.dedupe_key AS csv_key FROM analyzer_transactions t LEFT JOIN analyzer_csv_feed_matches a ON a.transaction_id = t.id AND a.user_id = t.user_id AND a.account_id = t.account_id WHERE t.user_id = ? AND t.account_id = ? AND t.bank_reference LIKE 'sfin:%' AND t.transaction_date >= ? AND t.transaction_date <= ?", [$userId, $accountId, $cutoff, max($dates)])->fetchAll();
    $candidates = [];
    foreach ($feed as $transaction) {
        $signature = csv_feed_signature($transaction['transaction_date'], (int) $transaction['amount_cents'], $transaction['kind'], $transaction['description']);
        $candidates[$signature][] = $transaction;
    }
    foreach ($groups as $signature => $group) {
        $row = reset($group);
        $found = $candidates[$signature] ?? [];
        if (count($group) !== 1 || count($found) !== 1 || $found[0]['csv_key'] !== null) {
            throw new InvalidArgumentException('A CSV transaction dated ' . $row['date'] . ' could not be uniquely matched to a saved automatic import. This card uses automatic imports from ' . $cutoff . '. Nothing was imported. Refresh the bank feed, or upload only earlier CSV history.');
        }
        $matches[$row['dedupe_key']] = $found[0] + ['new_alias' => true];
    }
    return $matches;
}

function preview_import(PDO $db, int $userId, array $pending): array
{
    $matches = csv_import_matches($db, $userId, (int) $pending['account_id'], $pending['rows']);
    $savedRules = analyzer_query($db, 'SELECT a.match_key, m.name, c.name AS category, m.needs_review FROM analyzer_aliases a JOIN analyzer_merchants m ON m.id = a.merchant_id JOIN analyzer_categories c ON c.id = m.category_id WHERE a.user_id = ?', [$userId])->fetchAll();
    $rules = array_column($savedRules, null, 'match_key');
    $result = ['added' => 0, 'skipped' => 0, 'expenses' => 0, 'refunds' => 0, 'payments' => 0, 'fallback' => 0, 'groups' => [], 'months' => []];
    foreach ($pending['rows'] as $row) {
        if (isset($matches[$row['dedupe_key']])) { check_duplicate($row, $matches[$row['dedupe_key']]); $result['skipped']++; continue; }
        $matches[$row['dedupe_key']] = ['transaction_date' => $row['date'], 'amount_cents' => $row['amount'], 'kind' => $row['kind']];
        $result['added']++;
        $result['fallback'] += (int) ($row['reference'] === null);
        $result['months'][substr($row['date'], 0, 7)] = true;
        $result[$row['kind'] === 'expense' ? 'expenses' : ($row['kind'] === 'refund' ? 'refunds' : 'payments')] += abs($row['amount']);
        if ($row['kind'] === 'payment') { continue; }
        $suggestion = $rules[$row['suggestion']['key']] ?? $row['suggestion'];
        $key = merchant_match_key($suggestion['name']);
        $result['groups'][$key] ??= ['name' => $suggestion['name'], 'category' => $suggestion['category'], 'needs_review' => $suggestion['needs_review'], 'count' => 0, 'net' => 0];
        $result['groups'][$key]['count']++;
        $result['groups'][$key]['net'] -= $row['amount'];
    }
    usort($result['groups'], fn($a, $b) => $b['net'] <=> $a['net']);
    ksort($result['months']);
    return $result;
}

/** Account locking and a database unique key make overlapping/concurrent imports idempotent. */
function save_import(PDO $db, int $userId, array $pending): array
{
    $db->beginTransaction();
    try {
        if (!analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId])->fetchColumn()) { throw new InvalidArgumentException('Sign in again.'); }
        $accountId = (int) $pending['account_id'];
        if ($accountId > 0) {
            if (!analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE id = ? AND user_id = ?', [$accountId, $userId])->fetchColumn()) { throw new InvalidArgumentException('Choose one of your cards.'); }
        } else {
            $label = clean_label($pending['account_label'], 80);
            $existing = analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE user_id = ? AND label = ?', [$userId, $label])->fetchColumn();
            if ($existing !== false) { $accountId = (int) $existing; }
            else {
                analyzer_query($db, 'INSERT INTO analyzer_accounts (user_id, label) VALUES (?, ?)', [$userId, $label]);
                $accountId = (int) $db->lastInsertId();
            }
        }
        $matches = csv_import_matches($db, $userId, $accountId, $pending['rows']);
        $previous = analyzer_query($db, 'SELECT id FROM analyzer_imports WHERE account_id = ? AND file_hash = ?', [$accountId, $pending['file_hash']])->fetchColumn();
        if ($previous !== false) {
            $db->commit();
            return ['added' => 0, 'skipped' => count($pending['rows']), 'month' => max(array_map(fn($r) => substr($r['date'], 0, 7), $pending['rows']))];
        }
        foreach (analyzer_categories() as $name) { get_category($db, $userId, $name); }
        analyzer_query($db, 'INSERT INTO analyzer_imports (user_id, account_id, filename, file_hash, row_count) VALUES (?, ?, ?, ?, ?)',
            [$userId, $accountId, $pending['filename'], $pending['file_hash'], count($pending['rows'])]);
        $importId = (int) $db->lastInsertId();
        $added = 0; $skipped = 0;
        foreach ($pending['rows'] as $row) {
            if (isset($matches[$row['dedupe_key']])) {
                check_duplicate($row, $matches[$row['dedupe_key']]);
                if (!empty($matches[$row['dedupe_key']]['new_alias'])) {
                    analyzer_query($db, 'INSERT INTO analyzer_csv_feed_matches (user_id, account_id, transaction_id, dedupe_key) VALUES (?, ?, ?, ?)', [$userId, $accountId, $matches[$row['dedupe_key']]['id'], $row['dedupe_key']]);
                    unset($matches[$row['dedupe_key']]['new_alias']);
                }
                $skipped++; continue;
            }
            $merchantId = $row['kind'] === 'payment' ? null : resolve_merchant($db, $userId, $row['suggestion']);
            analyzer_query($db, 'INSERT INTO analyzer_transactions (user_id, account_id, import_id, merchant_id, transaction_date, description, memo, amount_cents, kind, bank_reference, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $accountId, $importId, $merchantId, $row['date'], $row['description'], $row['memo'], $row['amount'], $row['kind'], $row['reference'], $row['dedupe_key']]);
            $matches[$row['dedupe_key']] = ['transaction_date' => $row['date'], 'amount_cents' => $row['amount'], 'kind' => $row['kind']];
            $added++;
        }
        analyzer_query($db, 'UPDATE analyzer_imports SET added_count = ?, skipped_count = ? WHERE id = ? AND user_id = ?', [$added, $skipped, $importId, $userId]);
        $db->commit();
        return ['added' => $added, 'skipped' => $skipped, 'month' => max(array_map(fn($r) => substr($r['date'], 0, 7), $pending['rows']))];
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}

/** Editing a merchant applies to past reports and future matched descriptions for this user. */
function update_merchant(PDO $db, int $userId, int $merchantId, string $name, int $categoryId, string $customCategory): void
{
    $name = clean_label($name, 120);
    $db->beginTransaction();
    try {
        analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if (!analyzer_query($db, 'SELECT id FROM analyzer_merchants WHERE id = ? AND user_id = ?', [$merchantId, $userId])->fetchColumn()) { throw new InvalidArgumentException('Merchant not found.'); }
        if (trim($customCategory) !== '') { $categoryId = get_category($db, $userId, clean_label($customCategory, 80)); }
        if (!analyzer_query($db, 'SELECT id FROM analyzer_categories WHERE id = ? AND user_id = ?', [$categoryId, $userId])->fetchColumn()) { throw new InvalidArgumentException('Choose one of your categories or enter a new one.'); }
        $target = analyzer_query($db, 'SELECT id FROM analyzer_merchants WHERE user_id = ? AND name = ? AND id <> ?', [$userId, $name, $merchantId])->fetchColumn();
        if ($target !== false) {
            // Keep every original descriptor and all future alias matches when merging.
            analyzer_query($db, 'UPDATE analyzer_transactions SET merchant_id = ? WHERE merchant_id = ? AND user_id = ?', [$target, $merchantId, $userId]);
            analyzer_query($db, 'UPDATE analyzer_aliases SET merchant_id = ? WHERE merchant_id = ? AND user_id = ?', [$target, $merchantId, $userId]);
            analyzer_query($db, 'DELETE FROM analyzer_merchants WHERE id = ? AND user_id = ?', [$merchantId, $userId]);
            $merchantId = (int) $target;
        }
        analyzer_query($db, 'UPDATE analyzer_merchants SET name = ?, category_id = ?, needs_review = 0 WHERE id = ? AND user_id = ?', [$name, $categoryId, $merchantId, $userId]);
        analyzer_query($db, "UPDATE analyzer_ai_jobs SET status = 'overridden', lease_token = NULL WHERE merchant_id = ? AND user_id = ?", [$merchantId, $userId]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

function report_months(PDO $db, int $userId, int $accountId = 0): array
{
    $sql = 'SELECT DISTINCT SUBSTR(transaction_date, 1, 7) AS month FROM analyzer_transactions WHERE user_id = ?';
    $params = [$userId];
    if ($accountId > 0) { $sql .= ' AND account_id = ?'; $params[] = $accountId; }
    return analyzer_query($db, $sql . ' ORDER BY month DESC', $params)->fetchAll(PDO::FETCH_COLUMN);
}

function monthly_report(PDO $db, int $userId, string $month, int $accountId = 0): array
{
    if (!preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', $month)) { throw new InvalidArgumentException('Choose a valid month.'); }
    $next = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m-d');
    $sql = 'SELECT t.*, m.name AS merchant, m.category_id, m.needs_review, j.status AS ai_status, c.name AS category, a.label AS account FROM analyzer_transactions t JOIN analyzer_accounts a ON a.id = t.account_id LEFT JOIN analyzer_merchants m ON m.id = t.merchant_id LEFT JOIN analyzer_categories c ON c.id = m.category_id LEFT JOIN analyzer_ai_jobs j ON j.merchant_id = m.id WHERE t.user_id = ? AND t.transaction_date >= ? AND t.transaction_date < ?';
    $params = [$userId, $month . '-01', $next];
    if ($accountId > 0) { $sql .= ' AND t.account_id = ?'; $params[] = $accountId; }
    $rows = analyzer_query($db, $sql . ' ORDER BY t.transaction_date DESC, t.id DESC', $params)->fetchAll();
    $report = ['expenses' => 0, 'refunds' => 0, 'payments' => 0, 'count' => count($rows), 'purchase_count' => 0,
        'merchants' => [], 'categories' => [], 'category_groups' => [], 'payment_rows' => [], 'review_count' => 0];
    foreach ($rows as $row) {
        $amount = abs((int) $row['amount_cents']);
        if ($row['kind'] === 'payment') { $report['payments'] += $amount; $report['payment_rows'][] = $row; continue; }
        $bucket = $row['kind'] === 'expense' ? 'expenses' : 'refunds';
        $report[$bucket] += $amount;
        if ($bucket === 'expenses') { $report['purchase_count']++; }
        $id = (int) $row['merchant_id'];
        $report['merchants'][$id] ??= ['id' => $id, 'name' => $row['merchant'], 'category' => $row['category'],
            'category_id' => (int) $row['category_id'], 'needs_review' => (bool) $row['needs_review'], 'ai_status' => $row['ai_status'], 'expenses' => 0, 'refunds' => 0, 'rows' => []];
        $report['merchants'][$id][$bucket] += $amount;
        $report['merchants'][$id]['rows'][] = $row;
        if ($bucket === 'expenses') { $report['categories'][$row['category']] = ($report['categories'][$row['category']] ?? 0) + $amount; }
    }
    uasort($report['merchants'], fn($a, $b) => $b['expenses'] <=> $a['expenses']);
    arsort($report['categories']);
    // Include credit-only merchants so every non-payment row can be audited.
    foreach ($report['merchants'] as $merchant) {
        $categoryId = $merchant['category_id'];
        $report['category_groups'][$categoryId] ??= ['id' => $categoryId, 'name' => $merchant['category'],
            'expenses' => 0, 'refunds' => 0, 'count' => 0, 'review_count' => 0, 'merchant_ids' => []];
        $report['category_groups'][$categoryId]['expenses'] += $merchant['expenses'];
        $report['category_groups'][$categoryId]['refunds'] += $merchant['refunds'];
        $report['category_groups'][$categoryId]['count'] += count($merchant['rows']);
        $report['category_groups'][$categoryId]['review_count'] += (int) $merchant['needs_review'];
        $report['category_groups'][$categoryId]['merchant_ids'][] = $merchant['id'];
    }
    uasort($report['category_groups'], fn($a, $b) => ($b['expenses'] <=> $a['expenses']) ?: strcasecmp($a['name'], $b['name']));
    $report['review_count'] = count(array_filter($report['merchants'], fn($m) => $m['needs_review']));
    $report['ai_pending_count'] = count(array_filter($report['merchants'], fn($m) => in_array($m['ai_status'], ['pending', 'processing', 'retry'], true)));
    $report['net'] = $report['expenses'] - $report['refunds'];
    return $report;
}

function money(int $cents): string
{
    return ($cents < 0 ? '−' : '') . '$' . number_format(abs($cents) / 100, 2);
}

function month_label(string $month): string
{
    return (new DateTimeImmutable($month . '-01'))->format('F Y');
}

function chart_color(int $index): string
{
    $palette = ['#2855d9', '#087e8b', '#9a43bd', '#dc7925', '#d23d69', '#32764b', '#7263d8', '#8b622d', '#00649c', '#c34734', '#637126', '#8e4877', '#4e6a86', '#747b87', '#b37708', '#27384d'];
    return $palette[$index % count($palette)];
}
