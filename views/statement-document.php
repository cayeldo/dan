<?php
if (!defined('DAN_PORTAL') && !isset($pdfMode)) { http_response_code(403); exit; }
$statementReport = $statement['report'];
$statementLogo = !empty($pdfMode) ? __DIR__ . '/../assets/kle-coin-logo-v2.png' : '/assets/kle-coin-logo-v2.png';
?>
<article class="spending-statement" aria-label="Monthly spending statement">
    <table class="statement-masthead" role="presentation"><tr><td class="statement-brand-cell"><img class="statement-logo" src="<?= escape($statementLogo) ?>" alt="KLE Coin - Plan | Spend | Save | Grow" width="150" height="100"></td><td class="statement-title-cell"><div class="statement-overline">PERSONAL SPENDING RECORD</div><h1>Monthly spending statement</h1><p><?= escape(month_label($statement['month'])) ?></p></td></tr></table>
    <table class="statement-meta" role="presentation"><tr><td><span>Prepared for</span><strong><?= escape($statement['name']) ?></strong><span>Card coverage</span><strong><?= escape($statement['scope']) ?></strong></td><td><span>Calendar period</span><strong><?= escape((new DateTimeImmutable($statement['start']))->format('M j')) ?> - <?= escape((new DateTimeImmutable($statement['end']))->format('M j, Y')) ?></strong><span>Data status</span><strong><?= escape($statement['status']) ?></strong></td></tr></table>
    <p class="statement-caption">Generated <?= escape($statement['generated']) ?>. Includes imported, posted transactions by their recorded transaction date.</p>
    <h2>Activity summary</h2>
    <table class="statement-totals" role="presentation"><tr><td><span>Purchases / charges</span><strong><?= money($statementReport['expenses']) ?></strong></td><td><span>Refunds / credits</span><strong><?= money($statementReport['refunds']) ?></strong></td><td><span>Card payments</span><strong><?= money($statementReport['payments']) ?></strong></td><td class="statement-net"><span>Net spending</span><strong><?= money($statementReport['net']) ?></strong></td></tr></table>
    <p class="statement-caption">Net spending = purchases minus refunds. Card payments are listed separately and do not reduce spending. This is not an amount due or an account balance.</p>
    <h2>Transaction detail <span><?= count($statement['rows']) ?> transactions · USD</span></h2>
    <table class="statement-ledger">
        <thead><tr><th>Date</th><th>Charge / description</th><th>Category</th><th class="amount">Charge</th><th class="amount">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($statement['rows'] as $row): $payment = $row['kind'] === 'payment'; ?>
        <tr><td class="ledger-date-value"><?= escape((new DateTimeImmutable($row['transaction_date']))->format('M j')) ?></td><td class="ledger-description"><strong><?= escape($payment ? 'Card payment' : ($row['merchant'] ?: $row['description'])) ?></strong><?php if (mb_strtolower(trim($row['description'])) !== mb_strtolower(trim($row['merchant'] ?? '')) || $payment): ?><small><?= escape($row['description']) ?></small><?php endif; ?><?php if ($statement['show_cards']): ?><small class="ledger-card"><?= escape($row['account']) ?></small><?php endif; ?></td><td><?= escape($payment ? 'Card payment' : ($row['category'] ?: 'Uncategorized')) ?><?= $row['kind'] === 'refund' ? '<small>Refund / credit</small>' : '' ?></td><td class="amount"><?= $row['kind'] === 'expense' ? money(abs((int) $row['amount_cents'])) : '-' ?></td><td class="amount"><?= $row['kind'] !== 'expense' ? money(abs((int) $row['amount_cents'])) : '-' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$statement['rows']): ?><tr><td colspan="5">No imported transactions in this period. Check the data status above before treating this as a zero-activity month.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <table class="statement-ledger-total" role="presentation"><tr><td>Transaction totals</td><td class="amount"><?= money($statementReport['expenses']) ?> charges</td><td class="amount"><?= money($statementReport['refunds'] + $statementReport['payments']) ?> credits &amp; payments</td></tr></table>
    <h2>Purchases by category</h2>
    <table class="statement-categories"><thead><tr><th>Category</th><th class="amount">Purchases</th></tr></thead><tbody><?php foreach ($statementReport['categories'] as $category => $amount): ?><tr><td><?= escape($category) ?></td><td class="amount"><?= money($amount) ?></td></tr><?php endforeach; ?><?php if (!$statementReport['categories']): ?><tr><td>No purchases imported</td><td class="amount">$0.00</td></tr><?php endif; ?></tbody></table>
    <?php if ($statement['budget']): $statementBudget = $statement['budget']['total']; ?>
    <div class="statement-budget-block"><h2>Budget results <span>All imported cards</span></h2>
    <?php if ($statement['budget']['travel_fund']): $fund = $statement['budget']['travel_fund']; ?><p>Includes <?= money($fund['opening_cents']) ?> carried forward for travel. <?= money($fund['reallocated_cents']) ?> used for other overages; <?= money($fund['available_cents']) ?> remains in the travel fund.</p><?php endif; ?>
    <table class="statement-categories"><thead><tr><th>Category</th><th class="amount">Target</th><th class="amount">Purchases</th><th class="amount">Budget available</th></tr></thead><tbody><?php foreach ($statement['budget']['groups'] as $group): ?><tr><td><?= escape($group['name']) ?></td><td class="amount"><?= money($group['target_cents']) ?></td><td class="amount"><?= money($group['spent_cents']) ?></td><td class="amount"><?= money($group['available_cents']) ?></td></tr><?php endforeach; ?><tr class="statement-total-row"><th>Total</th><td class="amount"><?= money($statementBudget['target_cents']) ?></td><td class="amount"><?= money($statementBudget['spent_cents']) ?></td><td class="amount"><?= money($statementBudget['available_cents']) ?></td></tr></tbody></table>
    </div>
    <?php endif; ?>
    <?php if ($statement['review']): $statementReview = $statement['review']; $reviewResult = $statementReview['result']; ?>
    <h2>KLE Coin’s Take <span>All imported cards · AI-generated</span></h2>
    <div class="statement-review"><h3><?= escape($reviewResult['headline']) ?></h3><p><?= escape($reviewResult['summary']) ?></p><p><strong>Bright spot.</strong> <?= escape($reviewResult['bright_spot']) ?></p><p><strong>Room to adjust.</strong> <?= escape($reviewResult['opportunity']) ?></p><h3>Try next month</h3><ul><?php foreach ($reviewResult['next_steps'] as $step): ?><li><?= escape($step) ?></li><?php endforeach; ?></ul><p class="statement-caption">Saved <?= escape(substr($statementReview['completed_at'], 0, 10)) ?>.<?= $statementReview['stale'] ? ' Data, categories, or budget changed after this review. This is the original saved analysis.' : '' ?><?= !$statementReview['budget_included'] ? ' This review did not include a budget.' : '' ?></p></div>
    <?php endif; ?>
    <div class="statement-disclaimer"><strong>KLE Coin spending statement</strong><p>A personal record of imported activity, not an issuer statement or payment notice. Calendar dates may differ from your card’s billing cycle. Consult your card issuer’s statement for balances, due dates, minimum payments, and complete account terms. Unconfirmed periods may be missing transactions.</p><p>klecoin.com · Plan | Spend | Save | Grow</p></div>
</article>
