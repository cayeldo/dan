<?php
declare(strict_types=1);
require __DIR__ . '/analyzer-test.php';
require dirname(__DIR__) . '/analytics.php';
require dirname(__DIR__) . '/monthly-reviews.php';
require dirname(__DIR__) . '/budgets.php';
require dirname(__DIR__) . '/statements.php';
require dirname(__DIR__) . '/simplefin.php';
require dirname(__DIR__) . '/uncategorized.php';
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$db->exec("INSERT INTO users (id, username) VALUES (40, 'Dan'), (41, 'Period other')");
$csv = "Date,Name,Amount\n9/4/26,COSTCO,-10\n9/5/26,COSTCO,-20\n10/6/26,COSTCO,-30\n10/7/26,COSTCO,-40\n10/15/26,PAYMENT THANK YOU,700\n10/16/26,Payment Reversal,-700\n10/16/26,Returned Payment Fee,-25\n";
save_import($db, 40, pending_csv($csv, 0, 'Fidelity'));
$card = (int) user_accounts($db, 40)[0]['id'];
check(configure_dan_statement_periods($db), 'Dan receives the three supplied 2026 periods');
check(!configure_dan_statement_periods($db), 'configuration is idempotent');
$oct = spending_statement($db, 40, '2026-10', 0, 'Dan');
check($oct['start'] === '2026-09-05' && $oct['end'] === '2026-10-06' && $oct['period']['due_date'] === '2026-11-03' && !$oct['period']['estimated'], 'October closes October 6 and is payable November 3');
check($oct['report']['expenses'] === 5000 && array_column($oct['rows'], 'transaction_date') === ['2026-09-05', '2026-10-06'], 'rebuild includes both exact boundaries and excludes adjacent transactions');
check($oct['budget'] === null && $oct['review'] === null, 'calendar budgets and reviews are not mislabeled as statement results');
$nov = spending_statement($db, 40, '2026-11', $card, 'Dan');
check($nov['start'] === '2026-10-07' && $nov['end'] === '2026-11-06' && $nov['period']['due_date'] === '2026-12-03' && $nov['period']['estimated'], 'November extrapolates from October 7 and labels its closing and due dates estimated');
check($nov['report']['expenses'] === 6500 && $nov['report']['payments'] === 0 && $nov['report']['payment_reversals'] === 70000 && $nov['report']['payment_credits'] === 70000, 'payment reversal cancels payment without inflating spending; separate fee stays spending');
$jul = statement_period($db, 40, $card, '2026-07');
check($jul['end_date'] === '2026-07-07' && $jul['estimated'], 'July meets the confirmed August start without a gap');
$prev = null;
for ($m = '2025-01'; $m <= '2028-03'; $m = statement_month_shift($m, 1)) {
    $p = statement_period($db, 40, $card, $m);
    if ($prev) { check((new DateTimeImmutable($prev['end_date']))->modify('+1 day')->format('Y-m-d') === $p['start_date'], 'continuous period boundaries ' . $m); }
    $prev = $p;
}
$calendar = spending_statement($db, 40, '2026-10', $card, 'Dan', true);
check($calendar['start'] === '2026-10-01' && $calendar['end'] === '2026-10-31' && $calendar['report']['expenses'] === 9500, 'explicit calendar view preserves calendar budgets and dates');
check(statement_period($db, 41, $card, '2026-10') === null, 'period lookup is tenant isolated');
rejects(fn() => save_statement_period($db, 41, $card, '2026-11', '2026-10-07', '2026-11-05', '2026-12-03'), 'foreign card dates cannot be changed');
rejects(fn() => save_statement_period($db, 40, $card, '2026-11', '2026-10-06', '2026-11-05', '2026-12-03'), 'overlapping dates are rejected');
rejects(fn() => save_statement_period($db, 40, $card, '2026-11', '2026-10-08', '2026-11-05', '2026-12-03'), 'gaps between confirmed adjacent periods are rejected');
rejects(fn() => save_statement_period($db, 40, $card, '2026-11', '2026-10-07', '2026-11-05', '2026-12-03', '2026-12-01'), 'incomplete payment schedule is rejected');
save_statement_period($db, 40, $card, '2026-11', '2026-10-07', '2026-11-05', '2026-12-03', '2026-12-01', '725.00');
$nov = spending_statement($db, 40, '2026-11', $card, 'Dan');
check(!$nov['period']['estimated'] && $nov['period']['scheduled_cents'] === 72500 && statement_period($db, 40, $card, '2026-12')['start_date'] === '2026-11-06', 'confirmed correction survives rebuild and adjusts following estimate');
$legacyId = (int) analyzer_query($db, "SELECT id FROM analyzer_transactions WHERE user_id = 40 AND description = 'Payment Reversal'")->fetchColumn();
$legacyMerchant = resolve_merchant($db, 40, classify_merchant('Payment Reversal'));
analyzer_query($db, "UPDATE analyzer_transactions SET kind = 'expense', merchant_id = ? WHERE id = ?", [$legacyMerchant, $legacyId]);
check(uncategorized_expenses($db, 40)['total_cents'] === 70000, 'legacy fixture reproduces the $700 uncategorized expense');
check(repair_payment_reversals($db, 40) === 1 && repair_payment_reversals($db, 40) === 0, 'legacy payment reversal repair is idempotent');
check(uncategorized_expenses($db, 40)['total_cents'] === 0, 'repair removes the existing $700 reversal from uncategorized expenses');
check(preview_import($db, 40, pending_csv("Date,Name,Amount\n10/16/26,Payment Reversal,-700\n", $card))['skipped'] === 1, 'repaired fallback CSV identity still deduplicates');
check(monthly_report($db, 40, '2026-10')['expenses'] === 9500 && spending_history($db, 40)['2026-10']['expenses'] === 9500, 'calendar reports and history exclude repaired reversal');
$parsed = parse_statement("Date,Transaction,Name,Amount\n10/16/26,PAYMENT,Payment Reversal,700\n", 'positive');
check($parsed[0]['kind'] === 'payment' && $parsed[0]['amount'] === -70000, 'positive-charge CSV and payment type support reversals');
check(!is_payment_reversal('Payment Reversal Fee') && !is_payment_reversal('Merchant reversal') && is_payment_reversal('RETURNED PAYMENT'), 'only explicit payment principal descriptors are excluded');
$today = statement_today();
$feed = ['id' => 'fixture-card', 'currency' => 'USD', 'transactions' => [['id' => 'reversal', 'posted' => strtotime($today . ' 12:00 UTC'), 'amount' => '-700.00', 'description' => 'Payment Reversal']]];
check(simplefin_rows($feed, $today)[0]['kind'] === 'payment', 'SimpleFIN classifies a negative payment reversal as an adjustment');
rejects(fn() => save_transaction_type($db, 41, $legacyId, 'spending'), 'manual correction rejects foreign transaction');
save_transaction_type($db, 40, $legacyId, 'spending');
check(repair_payment_reversals($db, 40) === 0, 'explicit manual classification is not overwritten by repair');
save_transaction_type($db, 40, $legacyId, 'payment');
check(preview_import($db, 40, pending_csv("Date,Name,Amount\n10/16/26,Payment Reversal,-700\n", $card))['skipped'] === 1, 'manual correction preserves source identity');
$rhythm = statement_rhythm($db, 40);
if (isset($rhythm['series']['2026-10'])) { check($rhythm['series']['2026-10'] === 5000 && !$rhythm['periods']['2026-10']['estimated'], 'rhythm chart matches October statement totals'); }
$statement = $nov; $pdfMode = true;
ob_start(); require dirname(__DIR__) . '/views/statement-document.php'; $html = ob_get_clean();
check(str_contains($html, 'Payment due Dec 3, 2026') && str_contains($html, 'Scheduled payment: $725.00') && str_contains($html, 'Payment reversal'), 'statement displays payment timing and reversal label');
file_put_contents('/tmp/kle-billing-statement.pdf', statement_pdf($nov));
file_put_contents('/tmp/kle-billing-statement.html', '<link rel="stylesheet" href="' . dirname(__DIR__) . '/statement.css">' . $html);
check(spending_statement($db, 40, '2026-11', $card, 'Dan')['period'] === $nov['period'], 'repeated rebuilds preserve saved period and scheduled payment');
echo "All billing-period and reversal checks passed.\n";
