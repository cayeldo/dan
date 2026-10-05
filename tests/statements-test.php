<?php
declare(strict_types=1);
require __DIR__ . '/analyzer-test.php';
require dirname(__DIR__) . '/analytics.php';
require dirname(__DIR__) . '/monthly-reviews.php';
require dirname(__DIR__) . '/budgets.php';
require dirname(__DIR__) . '/statements.php';
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$db->exec("INSERT INTO users (id, username) VALUES (30, 'statement_test'), (31, 'statement_other')");
$csv = "Date,Name,Amount\n8/15/26,COSTCO,-40\n8/01/26,RED ROBIN,-10\n8/20/26,COSTCO,5\n8/25/26,PAYMENT THANK YOU,30\n8/01/26,Café & <script>example</script>,-2.50\n";
save_import($db, 30, pending_csv($csv, 0, 'Visa · 1234'));
$card = (int) user_accounts($db, 30)[0]['id'];
$s = spending_statement($db, 30, '2026-08', 0, 'Statement Test');
check(array_column($s['rows'], 'transaction_date') === ['2026-08-01','2026-08-01','2026-08-15','2026-08-20','2026-08-25'], 'statement transactions are chronological with deterministic same-day ordering');
check($s['report']['expenses'] === 5250 && $s['report']['refunds'] === 500 && $s['report']['payments'] === 3000 && $s['report']['net'] === 4750, 'statement totals separate charges, refunds, payments, and net spending');
check($s['status'] === 'Data not confirmed complete', 'ended month with imported rows is not assumed complete');
check(spending_statement($db, 31, '2026-08', 0, 'Other')['rows'] === [], 'statement data is tenant isolated');
rejects(fn() => spending_statement($db, 31, '2026-08', $card, 'Other'), 'foreign card cannot be exported');
rejects(fn() => spending_statement($db, 30, '2026-13', 0, 'Test'), 'invalid statement month is rejected');
check(spending_statement($db, 30, gmdate('Y-m'), 0, 'Test')['status'] === 'Month in progress', 'current month is visibly in progress');
$plan=budget_plan($db,30,'2026-08');
$targets=array_fill_keys(array_keys($plan['groups']),'100');
save_budget($db,30,'2026-08',$targets,budget_form_version($plan));
$s=spending_statement($db,30,'2026-08',0,'Statement Test');
check($s['budget']['total']['spent_cents'] === 5250, 'statement budget reconciles to its all-card purchases');
save_import($db,30,pending_csv("Date,Name,Amount\n8/10/26,COSTCO,-7\n",0,'Second card'));
check(spending_statement($db,30,'2026-08',$card,'Test')['budget'] === null, 'single-card statement does not present an all-card budget as card-specific');
$statement=$s; $pdfMode=true;
ob_start(); require dirname(__DIR__) . '/views/statement-document.php'; $html=ob_get_clean();
check(str_contains(strtolower($html),'&lt;script&gt;') && !str_contains(strtolower($html),'<script>example'), 'untrusted transaction descriptions are escaped in the document');
$pdf=statement_pdf($s);
check(str_starts_with($pdf,'%PDF-'), 'server renders a real PDF without external resources');
file_put_contents('/tmp/kle-statement-sample.pdf',$pdf);
// Stress pagination with lengthy descriptions and multiple pages of activity.
$many="Date,Name,Amount\n";
for($i=0;$i<95;$i++) { $many .= '8/' . (1 + $i % 28) . '/26,"Café Market ' . $i . ' - ' . str_repeat('Long description ',6) . '",-12.34' . "\n"; }
save_import($db,30,pending_csv($many,$card));
$long=spending_statement($db,30,'2026-08',0,'Statement Test');
file_put_contents('/tmp/kle-statement-long.pdf',statement_pdf($long));
check(count($long['rows']) === 101, 'multipage statement retains every transaction');
check((int) $db->query('SELECT COUNT(*) FROM analyzer_month_reviews WHERE user_id=30')->fetchColumn() === 0, 'viewing and exporting statements never queues or generates an AI review');
echo "All statement checks passed.\n";
