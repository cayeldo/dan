<?php
declare(strict_types=1);

/** Build a calendar-month spending record from the same ledger as the analyzer. */
function spending_statement(PDO $db, int $user, string $month, int $account, string $name): array
{
    if (!budget_month_valid($month)) { throw new InvalidArgumentException('Choose a valid statement month.'); }
    $accounts = user_accounts($db, $user);
    $labels = array_column($accounts, 'label', 'id');
    if ($account > 0 && !isset($labels[$account])) { throw new InvalidArgumentException('Choose one of your cards.'); }
    $report = monthly_report($db, $user, $month, $account);
    $rows = $report['payment_rows'];
    foreach ($report['merchants'] as $merchant) { $rows = [...$rows, ...$merchant['rows']]; }
    usort($rows, fn($a, $b) => strcmp($a['transaction_date'], $b['transaction_date']) ?: (int) $a['id'] <=> (int) $b['id']);
    $complete = month_is_complete($db, $user, $month);
    $current = $month === gmdate('Y-m');
    $review = ($account === 0 || count($accounts) === 1) ? saved_month_review($db, $user, $month) : null;
    return ['month' => $month, 'name' => $name, 'account' => $account,
        'show_cards' => $account === 0 && count($accounts) > 1,
        'scope' => $account ? $labels[$account] : 'All imported cards',
        'start' => $month . '-01', 'end' => (new DateTimeImmutable($month . '-01'))->format('Y-m-t'),
        'generated' => (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('M j, Y g:i a T'),
        'status' => $complete ? 'Confirmed complete' : ($current ? 'Month in progress' : ($month > gmdate('Y-m') ? 'Upcoming month' : 'Data not confirmed complete')),
        'report' => $report, 'rows' => $rows,
        'budget' => ($account === 0 || count($accounts) === 1) ? budget_progress($db, $user, $month) : null,
        'review' => ($review['status'] ?? '') === 'completed' ? $review : null];
}

/** Render locally, with no AI requests, remote resources, or stored statement files. */
function statement_pdf(array $statement): string
{
    require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
    $temporary = sys_get_temp_dir() . '/kle-statement-' . bin2hex(random_bytes(12));
    if (!mkdir($temporary, 0700)) { throw new RuntimeException('Unable to prepare PDF export.'); }
    try {
        $options = new Dompdf\Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(__DIR__ . '/assets');
        $options->setTempDir($temporary);
        $options->setFontCache($temporary);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf\Dompdf($options);
        $pdfMode = true;
        ob_start();
        try { require __DIR__ . '/views/statement-document.php'; $body = ob_get_contents(); }
        finally { ob_end_clean(); }
        $css = file_get_contents(__DIR__ . '/statement.css');
        $pdf->loadHtml('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>KLE Coin spending statement</title><style>' . $css . '</style></head><body class="statement-pdf">' . $body . '</body></html>', 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(40, 756, 'KLE Coin | ' . $statement['month'] . ' | Personal spending record', $font, 7, [.35, .4, .45]);
        $canvas->page_text(485, 756, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [.35, .4, .45]);
        return $pdf->output();
    } finally {
        foreach (glob($temporary . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($temporary);
    }
}
