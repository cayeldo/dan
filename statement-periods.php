<?php
declare(strict_types=1);

function statement_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
}

function statement_account(PDO $db, int $user, int $account = 0): ?array
{
    $rows = analyzer_query($db, 'SELECT s.*, a.label FROM analyzer_statement_settings s JOIN analyzer_accounts a ON a.id = s.account_id AND a.user_id = s.user_id WHERE s.user_id = ?' . ($account ? ' AND s.account_id = ?' : '') . ' ORDER BY s.account_id', $account ? [$user, $account] : [$user])->fetchAll();
    if ($account) { return $rows[0] ?? null; }
    // An all-card report can use a billing cycle only when it covers one card.
    return count(user_accounts($db, $user)) === 1 ? ($rows[0] ?? null) : null;
}

function statement_month_shift(string $month, int $offset): string
{
    return (new DateTimeImmutable($month . '-01'))->modify(sprintf('%+d months', $offset))->format('Y-m');
}

function statement_day(string $month, int $day): string
{
    return $month . '-' . sprintf('%02d', min($day, (int) (new DateTimeImmutable($month . '-01'))->format('t')));
}

/** Confirmed dates override the estimate; adjacent boundaries remain continuous. */
function statement_period(PDO $db, int $user, int $account, string $month): ?array
{
    if (!preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', $month)) { throw new InvalidArgumentException('Choose a valid statement month.'); }
    $settings = statement_account($db, $user, $account);
    if (!$settings) { return null; }
    $account = (int) $settings['account_id'];
    $previous = statement_month_shift($month, -1); $next = statement_month_shift($month, 1);
    $saved = analyzer_query($db, 'SELECT * FROM analyzer_statement_periods WHERE user_id = ? AND account_id = ? AND month >= ? AND month <= ?', [$user, $account, $previous, $next])->fetchAll();
    $saved = array_column($saved, null, 'month');
    $period = $saved[$month] ?? [
        'start_date' => (new DateTimeImmutable($saved[$previous]['end_date'] ?? statement_day($previous, (int) $settings['closing_day'])))->modify('+1 day')->format('Y-m-d'),
        'end_date' => isset($saved[$next]) ? (new DateTimeImmutable($saved[$next]['start_date']))->modify('-1 day')->format('Y-m-d') : statement_day($month, (int) $settings['closing_day']),
        'due_date' => statement_day($next, (int) $settings['due_day']),
        'scheduled_date' => null, 'scheduled_cents' => null,
    ];
    $period['estimated'] = !isset($saved[$month]);
    $period['account_id'] = $account; $period['label'] = $settings['label']; $period['month'] = $month;
    $today = statement_today();
    $period['open'] = $period['start_date'] <= $today && $today <= $period['end_date'];
    $period['future'] = $period['start_date'] > $today;
    return $period;
}

function statement_period_caption(array $period): string
{
    return (new DateTimeImmutable($period['start_date']))->format('M j, Y') . ' – ' . (new DateTimeImmutable($period['end_date']))->format('M j, Y') . ($period['estimated'] ? ' · Estimated dates' : '');
}

function statement_due_caption(array $period): string
{
    return $period['due_date'] ? ($period['estimated'] ? 'Estimated payment due ' : 'Payment due ') . (new DateTimeImmutable($period['due_date']))->format('M j, Y') : 'Payment due date not entered';
}

/** Save dates under the user lock, preventing overlapping confirmed billing periods. */
function save_statement_period(PDO $db, int $user, int $account, string $month, string $start, string $end, string $due, string $scheduled = '', string $amount = ''): void
{
    if (!preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', $month)) { throw new InvalidArgumentException('Choose a valid statement month.'); }
    foreach ([$start, $end, ...($due !== '' ? [$due] : []), ...($scheduled !== '' ? [$scheduled] : [])] as $date) {
        if (!preg_match('/\A20\d{2}-\d{2}-\d{2}\z/', $date) || parse_transaction_date($date) !== $date) { throw new InvalidArgumentException('Enter valid statement dates.'); }
    }
    $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;
    if ($start > $end || $days > 62 || substr($start, 0, 7) < statement_month_shift($month, -1) || (substr($start, 0, 7) === $month && substr($start, 8, 2) !== '01') || substr($end, 0, 7) !== $month || ($due !== '' && $due <= $end)) { throw new InvalidArgumentException('Use the closing month, an ordered period of at most 63 days, and a due date after closing.'); }
    if (($scheduled === '') !== ($amount === '')) { throw new InvalidArgumentException('Enter both the scheduled payment date and amount, or leave both blank.'); }
    $cents = $amount !== '' ? parse_cents($amount) : null;
    if ($cents !== null && $cents <= 0) { throw new InvalidArgumentException('Scheduled payment must be greater than zero.'); }
    $db->beginTransaction();
    try {
        analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
        if (!analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE user_id = ? AND id = ?', [$user, $account])->fetchColumn()) { throw new InvalidArgumentException('Choose one of your cards.'); }
        $others = analyzer_query($db, 'SELECT * FROM analyzer_statement_periods WHERE user_id = ? AND account_id = ? AND month <> ?', [$user, $account, $month])->fetchAll();
        foreach ($others as $other) {
            if ($start <= $other['end_date'] && $end >= $other['start_date']) { throw new InvalidArgumentException('This period overlaps another saved statement.'); }
            if (($other['month'] === statement_month_shift($month, -1) && (new DateTimeImmutable($other['end_date']))->modify('+1 day')->format('Y-m-d') !== $start)
                || ($other['month'] === statement_month_shift($month, 1) && (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d') !== $other['start_date'])) { throw new InvalidArgumentException('Adjacent saved statements must meet without a gap.'); }
        }
        if (!statement_account($db, $user, $account)) { analyzer_query($db, 'INSERT INTO analyzer_statement_settings (user_id, account_id, closing_day, due_day) VALUES (?, ?, ?, ?)', [$user, $account, (int) substr($end, 8, 2), $due !== '' ? (int) substr($due, 8, 2) : 3]); }
        analyzer_query($db, 'DELETE FROM analyzer_statement_periods WHERE user_id = ? AND account_id = ? AND month = ?', [$user, $account, $month]);
        analyzer_query($db, 'INSERT INTO analyzer_statement_periods (user_id, account_id, month, start_date, end_date, due_date, scheduled_date, scheduled_cents) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$user, $account, $month, $start, $end, $due ?: null, $scheduled ?: null, $cents]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
}

/** One-time configuration explicitly requested for Dan; never choose between ambiguous cards. */
function configure_dan_statement_periods(PDO $db): bool
{
    $user = analyzer_query($db, 'SELECT id FROM users WHERE LOWER(username) = ?', ['dan'])->fetchColumn();
    if (!$user) { return false; }
    $accounts = user_accounts($db, (int) $user);
    $fidelity = array_values(array_filter($accounts, fn($a) => stripos($a['label'], 'fidelity') !== false));
    $account = count($fidelity) === 1 ? $fidelity[0] : (count($accounts) === 1 ? $accounts[0] : null);
    if (!$account || statement_account($db, (int) $user, (int) $account['id'])) { return false; }
    $db->beginTransaction();
    try {
        analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $user]);
        if (statement_account($db, (int) $user, (int) $account['id'])) { $db->commit(); return false; }
        analyzer_query($db, 'INSERT INTO analyzer_statement_settings (user_id, account_id, closing_day, due_day) VALUES (?, ?, 6, 3)', [$user, $account['id']]);
        foreach ([['10','09-05','10-06','11-03'], ['09','08-07','09-04','10-03'], ['08','07-08','08-06','09-03']] as [$month, $start, $end, $due]) {
            analyzer_query($db, 'INSERT INTO analyzer_statement_periods (user_id, account_id, month, start_date, end_date, due_date) VALUES (?, ?, ?, ?, ?, ?)', [$user, $account['id'], '2026-' . $month, '2026-' . $start, '2026-' . $end, '2026-' . $due]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
    return true;
}

function statement_months(PDO $db, int $user, int $account = 0): array
{
    $settings = statement_account($db, $user, $account);
    if (!$settings) { return report_months($db, $user, $account); }
    $bounds = analyzer_query($db, 'SELECT MIN(transaction_date) AS first_date, MAX(transaction_date) AS last_date FROM analyzer_transactions WHERE user_id = ? AND account_id = ?', [$user, $settings['account_id']])->fetch();
    $saved = analyzer_query($db, 'SELECT month FROM analyzer_statement_periods WHERE user_id = ? AND account_id = ?', [$user, $settings['account_id']])->fetchAll(PDO::FETCH_COLUMN);
    $first = min(substr($bounds['first_date'] ?? statement_today(), 0, 7), ...($saved ?: [substr(statement_today(), 0, 7)]));
    $lastDate = max($bounds['last_date'] ?? statement_today(), statement_today());
    $last = substr($lastDate, 0, 7);
    if ($lastDate > statement_period($db, $user, (int) $settings['account_id'], $last)['end_date']) { $last = statement_month_shift($last, 1); }
    $last = max($last, ...($saved ?: [$last]));
    $months = [];
    for ($cursor = $first; $cursor <= $last; $cursor = statement_month_shift($cursor, 1)) { $months[] = $cursor; }
    return array_reverse($months);
}

/** The rhythm chart uses billing periods; budget and calendar analytics keep calendar months. */
function statement_rhythm(PDO $db, int $user): ?array
{
    $settings = statement_account($db, $user);
    if (!$settings) { return null; }
    $account = (int) $settings['account_id']; $series = []; $periods = []; $closed = [];
    foreach (array_reverse(array_slice(statement_months($db, $user, $account), 0, 12)) as $month) {
        $period = statement_period($db, $user, $account, $month);
        $totals = analyzer_query($db, "SELECT COUNT(*) AS n, SUM(CASE WHEN kind = 'expense' THEN ABS(amount_cents) ELSE 0 END) AS purchases FROM analyzer_transactions WHERE user_id = ? AND account_id = ? AND transaction_date >= ? AND transaction_date <= ?", [$user, $account, $period['start_date'], $period['end_date']])->fetch();
        $series[$month] = (int) $totals['n'] > 0 ? (int) $totals['purchases'] : null;
        $periods[$month] = $period;
        if ($period['end_date'] < statement_today() && $series[$month] !== null) { $closed[] = $series[$month]; }
    }
    return ['series' => $series, 'periods' => $periods, 'account' => $account, 'label' => $settings['label'], 'average' => $closed ? (int) round(array_sum($closed) / count($closed)) : null, 'closed_count' => count($closed)];
}
