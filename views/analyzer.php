<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading analyzer-heading"><h1>Credit card analyzer</h1><span class="muted">Select a slice to explore.</span></div>
<?php if ($pending): ?><p class="notice"><a href="/?page=statements">Finish reviewing your statement</a></p><?php endif; ?>
<?php if ($report !== null && $months): ?>
<form class="report-filter" method="get" action="/">
    <input type="hidden" name="page" value="analyzer">
    <div><label for="month">Report month</label><select id="month" name="month"><?php if (!in_array($month, $months, true)): ?><option value="<?= escape($month) ?>"><?= escape(month_label($month)) ?></option><?php endif; ?><?php foreach ($months as $option): ?><option value="<?= escape($option) ?>" <?= $option === $month ? 'selected' : '' ?>><?= escape(month_label($option)) ?></option><?php endforeach; ?></select></div>
    <div><label for="account-filter">Card</label><select id="account-filter" name="account"><option value="0">All cards</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $accountFilter ? 'selected' : '' ?>><?= escape($account['label']) ?></option><?php endforeach; ?></select></div>
    <button type="submit" class="secondary">View report</button><span class="filter-caption">By transaction date · USD</span>
</form>
<?php require __DIR__ . '/budget-progress.php'; ?>
<section class="panel category-panel analyzer-hero" aria-labelledby="category-heading" id="spending-chart">
    <div class="section-heading"><div><div class="eyebrow"><?= escape(month_label($month)) ?><?= $month === gmdate('Y-m') ? ' · SO FAR' : '' ?></div><h2 id="category-heading">Where your money went</h2></div><span class="pill"><?= $report['purchase_count'] ?> purchases</span></div>
    <?php if ($report['expenses'] > 0):
        $chartId = 'monthly'; $chartTitle = 'Spending by category for ' . month_label($month); $chartTotal = $report['expenses']; $chartItems = [];
        $categoryIds = array_column($report['category_groups'], 'id', 'name');
        foreach ($report['categories'] as $name => $amount) { $chartItems[] = ['id' => $categoryIds[$name], 'name' => $name, 'amount' => $amount, 'url' => analyzer_url($month, $accountFilter) . '&category=' . $categoryIds[$name] . '#category-' . $categoryIds[$name]]; }
        require __DIR__ . '/category-chart.php';
    else: ?><p class="empty-inline">No purchases in this month.</p><?php endif; ?>
    <div class="chart-footer"><span>Refunds &amp; credits <strong class="change-down"><?= money($report['refunds']) ?></strong></span><span>Net spending <strong><?= money($report['net']) ?></strong></span><a href="<?= escape(analyzer_url($month, $accountFilter) . '&audit=all#audit-heading') ?>">Browse categories</a></div>
</section>
<?php if ($report['ai_pending_count']): ?><p class="subtle-status">Categorizing <?= $report['ai_pending_count'] ?> merchants… <a href="<?= escape(analyzer_url($month, $accountFilter)) ?>">Refresh report</a><span class="sr-only">Automatic categorization is in progress.</span></p><?php endif; ?>
<?php require __DIR__ . '/monthly-review.php'; ?>
<?php require __DIR__ . '/category-audit.php'; ?>
<details class="panel compact-disclosure" id="merchant-totals"><summary>Merchant totals <span class="muted"><?= count($report['merchants']) ?> merchants</span></summary>
    <?php if ($report['merchants']): ?><div class="table-scroll"><table class="merchant-table"><thead><tr><th>Merchant</th><th>Category</th><th class="number">Purchases</th><th class="number">Credits</th><th class="number">Net spending</th><th><span class="sr-only">Edit and transaction details</span></th></tr></thead><tbody>
    <?php foreach ($report['merchants'] as $merchant): ?>
    <tr><td><strong><?= escape($merchant['name']) ?></strong><small><?= count($merchant['rows']) ?> transaction<?= count($merchant['rows']) === 1 ? '' : 's' ?></small></td><td><a class="category-tag" href="<?= escape(analyzer_url($month, $accountFilter) . '&merchant=' . $merchant['id'] . '#merchant-' . $merchant['id']) ?>" aria-label="<?= escape('Edit category for ' . $merchant['name'] . ': ' . $merchant['category']) ?>"><?= escape($merchant['category']) ?></a><?php if (category_automation_note($merchant)): ?><small class="review-label"><?= escape(category_automation_note($merchant)) ?></small><?php endif; ?></td><td class="number"><?= money($merchant['expenses']) ?></td><td class="number"><?= $merchant['refunds'] ? money($merchant['refunds']) : '—' ?></td><td class="number"><strong><?= money($merchant['expenses'] - $merchant['refunds']) ?></strong></td><td><a href="<?= escape(analyzer_url($month, $accountFilter) . '&merchant=' . $merchant['id'] . '#merchant-' . $merchant['id']) ?>">Edit / details</a></td></tr>
    <?php endforeach; ?>
    </tbody><tfoot><tr><th colspan="2">Total</th><td class="number"><?= money($report['expenses']) ?></td><td class="number"><?= money($report['refunds']) ?></td><td class="number"><?= money($report['net']) ?></td><td></td></tr></tfoot></table></div>
    <?php else: ?><p class="empty-inline">No merchants to show for this month.</p><?php endif; ?>
</details>

<?php if ($report['payment_rows']): ?><details class="panel payments"><summary>Card payments · <?= money($report['payments']) ?> excluded from spending</summary><div class="table-scroll"><table><thead><tr><th>Date</th><th>Card</th><th class="number">Payment</th></tr></thead><tbody><?php foreach ($report['payment_rows'] as $payment): ?><tr><td><?= escape($payment['transaction_date']) ?></td><td><?= escape($payment['account']) ?></td><td class="number"><?= money((int) $payment['amount_cents']) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
<?php require __DIR__ . '/month-comparison.php'; ?>
<?php elseif ($report !== null): ?>
<?php require __DIR__ . '/budget-progress.php'; ?>
<section class="empty-state"><h2>Your first monthly report is one upload away.</h2><p>Add a statement or connect your card.</p><a class="button" href="/?page=statements">Upload a statement</a></section>
<?php endif; ?>
<?php require __DIR__ . '/import-history.php'; ?>
