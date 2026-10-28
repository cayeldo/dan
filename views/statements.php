<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading statement-controls report-heading"><div><div class="eyebrow">YOUR MONTHLY RECORD</div><h1>Statements</h1></div>
<form class="report-filter statement-controls" method="get" action="/">
    <input type="hidden" name="page" value="statements">
    <?php $selectedMonth = $statement['month'] ?? (budget_month_valid($month) ? $month : gmdate('Y-m')); ?>
    <div><label for="statement-month">Statement month</label><select id="statement-month" name="month" required>
        <?php if (!in_array($selectedMonth, $months, true)): ?><option value="<?= escape($selectedMonth) ?>" selected><?= escape(month_label($selectedMonth)) ?></option><?php endif; ?>
        <?php foreach ($months as $option): ?><option value="<?= escape($option) ?>" <?= $option === $selectedMonth ? 'selected' : '' ?>><?= escape(month_label($option)) ?></option><?php endforeach; ?>
    </select></div>
    <div><label for="statement-card">Card</label><select id="statement-card" name="account"><option value="0">All imported cards</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $accountFilter ? 'selected' : '' ?>><?= escape($account['label']) ?></option><?php endforeach; ?></select></div>
    <div><label for="statement-basis">Period</label><select id="statement-basis" name="basis"><option value="statement">Statement period</option><option value="calendar" <?= !empty($calendarStatement) ? 'selected' : '' ?>>Calendar month</option></select></div>
    <button class="secondary" type="submit">Rebuild statement</button>
</form>
</div>
<?php if ($statement && !$statement['period'] && empty($calendarStatement)): ?><p class="notice statement-controls">Showing a calendar month because billing dates are not configured for this selection. Select a configured card or save its statement dates below.</p><?php endif; ?>
<div class="statement-controls statement-toolbar"><p class="page-intro">A complete view of your imported activity, ready to keep or share.</p><?php if ($statement): ?><a class="button secondary" href="/?<?= escape(http_build_query(['page' => 'statements', 'month' => $statement['month'], 'account' => $accountFilter, 'download' => 'pdf', 'basis' => !empty($calendarStatement) ? 'calendar' : 'statement'])) ?>">Download PDF ↓</a><?php endif; ?></div>
<?php if ($statement): ?><p class="statement-scroll-hint">Swipe across the statement to see all columns.</p><div class="statement-paper-scroll"><?php $pdfMode = false; require __DIR__ . '/statement-document.php'; ?></div><?php endif; ?>

<?php if ($accounts): $editAccount = $accountFilter ?: (count($accounts) === 1 ? (int) $accounts[0]['id'] : 0); $editPeriod = $statement['period'] ?? null; ?>
<details class="panel statement-controls"><summary>Statement dates &amp; payment timing</summary>
<p class="hint">Name statements for the month they close. Saved dates are retained every time you rebuild. Other months use estimated dates. Enter a scheduled payment only after confirming it with the card issuer; saving here does not schedule a bank payment.</p>
<form method="post" action="/?page=statements" class="report-filter">
<?php csrf_field(); ?><input type="hidden" name="action" value="save_statement_period">
<div><label for="period-account">Card</label><select id="period-account" name="period_account" required><option value="">Choose card</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $editAccount === (int) $a['id'] ? 'selected' : '' ?>><?= escape($a['label']) ?></option><?php endforeach; ?></select></div>
<div><label for="period-month">Closing month</label><input id="period-month" type="month" name="period_month" value="<?= escape($selectedMonth) ?>" required></div>
<div><label for="period-start">Start date</label><input id="period-start" type="date" name="period_start" value="<?= escape($editPeriod['start_date'] ?? '') ?>" required></div>
<div><label for="period-end">Closing date</label><input id="period-end" type="date" name="period_end" value="<?= escape($editPeriod['end_date'] ?? '') ?>" required></div>
<div><label for="period-due">Payment due</label><input id="period-due" type="date" name="period_due" value="<?= escape($editPeriod['due_date'] ?? '') ?>"></div>
<div><label for="scheduled-date">Scheduled payment date</label><input id="scheduled-date" type="date" name="scheduled_date" value="<?= escape($editPeriod['scheduled_date'] ?? '') ?>"></div>
<div><label for="scheduled-amount">Scheduled amount ($)</label><input id="scheduled-amount" type="number" min="0.01" step="0.01" name="scheduled_amount" value="<?= $editPeriod && $editPeriod['scheduled_cents'] !== null ? escape(number_format((int) $editPeriod['scheduled_cents'] / 100, 2, '.', '')) : '' ?>"></div>
<button type="submit">Save confirmed dates</button>
</form></details>
<?php endif; ?>

<?php if ($statement && $statement['rows']): ?>
<details class="panel statement-controls"><summary>Correct a transaction type</summary>
<p class="hint">Payments and payment reversals are excluded from spending. Fees remain spending. This correction is retained during future imports.</p>
<form method="post" action="/?page=statements" class="report-filter"><?php csrf_field(); ?><input type="hidden" name="action" value="save_transaction_type"><input type="hidden" name="month" value="<?= escape($statement['month']) ?>"><input type="hidden" name="account_filter" value="<?= (int) $accountFilter ?>"><input type="hidden" name="basis" value="<?= !empty($calendarStatement) ? 'calendar' : 'statement' ?>">
<div><label for="transaction-type-row">Transaction</label><select id="transaction-type-row" name="transaction_id"><?php foreach ($statement['rows'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= escape($row['transaction_date'] . ' · ' . $row['description'] . ' · ' . money((int) $row['amount_cents'])) ?></option><?php endforeach; ?></select></div>
<div><label for="transaction-type">Type</label><select id="transaction-type" name="transaction_type"><option value="payment">Payment / payment reversal</option><option value="spending">Purchase / refund</option></select></div><button type="submit">Save correction</button></form>
</details>
<?php endif; ?>
