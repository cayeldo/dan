<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading"><div><div class="eyebrow">SPENDING, SORTED</div><h1>Credit card analyzer</h1><p class="page-intro">Your merchants, your categories, one month at a time.</p></div><a class="button secondary" href="#upload">Upload a statement</a></div>

<?php if ($preview !== null): ?>
<section class="panel preview-panel" aria-labelledby="preview-heading">
    <div class="section-heading"><div><div class="eyebrow">READY TO REVIEW</div><h2 id="preview-heading">Check your import</h2></div><span class="pill">Not saved yet</span></div>
    <p class="muted wrap"><?= escape($pending['filename']) ?> · <?= escape($pending['account_label']) ?></p>
    <div class="preview-stats"><div><strong><?= $preview['added'] ?></strong><span>new transactions</span></div><div><strong><?= $preview['skipped'] ?></strong><span>duplicates to skip</span></div><div><strong><?= money($preview['expenses']) ?></strong><span>new purchases</span></div><div><strong><?= money($preview['refunds']) ?></strong><span>refunds / credits</span></div></div>
    <p class="hint"><?= money($preview['payments']) ?> in card payments is excluded from spending. <?= $preview['months'] ? 'Months: ' . escape(implode(', ', array_map('month_label', array_keys($preview['months'])))) . '.' : '' ?></p>
    <?php if ($preview['fallback'] > 0): ?><p class="hint">Some rows have no bank reference. Matching uses the date, description, amount, and repeated-row count. Identical purchases in separate overlapping files may be indistinguishable; check the duplicate count.</p><?php endif; ?>
    <?php if ($preview['groups']): ?>
    <details class="preview-details"><summary>Review <?= count($preview['groups']) ?> merchant groups and suggested categories</summary>
        <div class="table-scroll"><table><thead><tr><th>Merchant</th><th>Category</th><th class="number">Transactions</th><th class="number">Net spending</th></tr></thead><tbody>
        <?php foreach ($preview['groups'] as $group): ?><tr><td><?= escape($group['name']) ?></td><td><?= escape($group['category']) ?><?= $group['needs_review'] ? ' · Auto-categorize after saving' : '' ?></td><td class="number"><?= $group['count'] ?></td><td class="number"><?= money($group['net']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php endif; ?>
    <div class="actions"><form method="post" action="/?page=analyzer"><?php csrf_field(); ?><input type="hidden" name="action" value="confirm_import"><input type="hidden" name="pending_token" value="<?= escape($pending['token']) ?>"><button type="submit"><?= $preview['added'] > 0 ? 'Save ' . $preview['added'] . ' transactions' : 'Finish — no new transactions' ?></button></form>
    <form method="post" action="/?page=analyzer"><?php csrf_field(); ?><input type="hidden" name="action" value="discard_import"><input type="hidden" name="pending_token" value="<?= escape($pending['token']) ?>"><button class="secondary" type="submit">Discard preview</button></form></div>
    <p class="hint">You can correct any merchant or category after saving. Credits are kept separately from purchases.</p>
</section>
<?php endif; ?>

<?php if ($report !== null && $months): ?>
<form class="report-filter" method="get" action="/">
    <input type="hidden" name="page" value="analyzer">
    <div><label for="month">Report month</label><select id="month" name="month"><?php if (!in_array($month, $months, true)): ?><option value="<?= escape($month) ?>"><?= escape(month_label($month)) ?></option><?php endif; ?><?php foreach ($months as $option): ?><option value="<?= escape($option) ?>" <?= $option === $month ? 'selected' : '' ?>><?= escape(month_label($option)) ?></option><?php endforeach; ?></select></div>
    <div><label for="account-filter">Card</label><select id="account-filter" name="account"><option value="0">All cards</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $accountFilter ? 'selected' : '' ?>><?= escape($account['label']) ?></option><?php endforeach; ?></select></div>
    <button type="submit" class="secondary">View report</button><span class="filter-caption">By transaction date · USD</span>
</form>
<section class="stat-grid" aria-label="<?= escape(month_label($month)) ?> totals">
    <div class="stat primary-stat"><span>Total purchases</span><strong><?= money($report['expenses']) ?></strong><small><?= $report['purchase_count'] ?> purchases in <?= escape(month_label($month)) ?></small></div>
    <div class="stat"><span>Refunds & credits</span><strong><?= money($report['refunds']) ?></strong><small>Excludes card payments</small></div>
    <div class="stat"><span>Net spending</span><strong><?= money($report['net']) ?></strong><small>Purchases less refunds & credits</small></div>
    <div class="stat"><span>Merchants</span><strong><?= count($report['merchants']) ?></strong><small><?= $report['review_count'] ? $report['review_count'] . ' categories not yet confirmed' : 'Grouped across store locations' ?></small></div>
</section>

<?php require __DIR__ . '/month-comparison.php'; ?>

<?php if ($report['ai_pending_count']): ?><div class="review-banner"><div><strong>Automatic categorization is in progress.</strong><p><?= $report['ai_pending_count'] ?> merchants are queued. Your transactions are saved; categories will update automatically. Refresh shortly to see the results.</p></div><a href="<?= escape(analyzer_url($month, $accountFilter)) ?>">Refresh report</a></div><?php elseif ($report['review_count']): ?><p class="hint">Some merchants could not be confidently categorized. Their current categories are preserved; you can edit them in the audit below at any time.</p><?php endif; ?>

<section class="panel category-panel" aria-labelledby="category-heading">
    <div class="section-heading"><div><div class="eyebrow"><?= escape(month_label($month)) ?></div><h2 id="category-heading">Where your money went</h2></div><span class="muted">Purchases by category</span></div>
    <?php if ($report['expenses'] > 0): ?>
    <div class="category-layout"><div class="donut-wrap">
        <svg class="donut" viewBox="0 0 240 240" role="img" aria-labelledby="chart-title chart-desc"><title id="chart-title">Spending by category for <?= escape(month_label($month)) ?></title><desc id="chart-desc"><?= escape(implode('; ', array_map(fn($name, $amount) => $name . ': ' . money($amount), array_keys($report['categories']), array_values($report['categories'])))) ?>. Payments and refunds are excluded.</desc>
        <circle cx="120" cy="120" r="90" fill="none" stroke="#edf1f7" stroke-width="32"/>
        <?php $offset = 0; $colorIndex = 0; $circumference = 2 * M_PI * 90; foreach ($report['categories'] as $category => $amount): $length = $amount / $report['expenses'] * $circumference; ?>
            <circle cx="120" cy="120" r="90" fill="none" stroke="<?= chart_color($colorIndex++) ?>" stroke-width="32" stroke-dasharray="<?= sprintf('%.5F %.5F', $length, $circumference - $length) ?>" stroke-dashoffset="<?= sprintf('%.5F', -$offset) ?>" transform="rotate(-90 120 120)"><title><?= escape($category) ?>: <?= money($amount) ?> (<?= number_format($amount / $report['expenses'] * 100, 1) ?>%)</title></circle>
        <?php $offset += $length; endforeach; ?>
        <text x="120" y="112" text-anchor="middle" class="donut-label">TOTAL PURCHASES</text><text x="120" y="141" text-anchor="middle" class="donut-total"><?= money($report['expenses']) ?></text>
        </svg>
    </div><ul class="category-legend"><?php $categoryIds = array_column($report['category_groups'], 'id', 'name'); $colorIndex = 0; foreach ($report['categories'] as $category => $amount): ?><li><svg width="12" height="12" aria-hidden="true"><circle cx="6" cy="6" r="5" fill="<?= chart_color($colorIndex++) ?>"/></svg><a href="<?= escape(analyzer_url($month, $accountFilter) . '&category=' . $categoryIds[$category] . '#category-' . $categoryIds[$category]) ?>"><?= escape($category) ?></a><span class="category-share"><?= number_format($amount / $report['expenses'] * 100, 1) ?>%</span><strong><?= money($amount) ?></strong></li><?php endforeach; ?></ul></div>
    <?php else: ?><p class="empty-inline">No purchases in this month. Choose another month or upload a statement.</p><?php endif; ?>
    <p class="hint">The chart shows purchases before refunds. Card payments are never counted as spending.</p>
</section>

<?php if ($report['merchants']): require __DIR__ . '/category-audit.php'; endif; ?>

<section class="panel merchant-panel" id="merchant-totals" aria-labelledby="merchant-heading">
    <div class="section-heading"><div><h2 id="merchant-heading">Merchant totals</h2><p class="hint">Store locations roll into one merchant. Changes to a merchant’s category are remembered for your account.</p></div><span class="muted"><?= count($report['merchants']) ?> merchants</span></div>
    <?php if ($report['merchants']): ?><div class="table-scroll"><table class="merchant-table"><thead><tr><th>Merchant</th><th>Category</th><th class="number">Purchases</th><th class="number">Credits</th><th class="number">Net spending</th><th><span class="sr-only">Edit and transaction details</span></th></tr></thead><tbody>
    <?php foreach ($report['merchants'] as $merchant): ?>
    <tr><td><strong><?= escape($merchant['name']) ?></strong><small><?= count($merchant['rows']) ?> transaction<?= count($merchant['rows']) === 1 ? '' : 's' ?></small></td><td><span class="category-tag"><?= escape($merchant['category']) ?></span><?php if (category_automation_note($merchant)): ?><small class="review-label"><?= escape(category_automation_note($merchant)) ?></small><?php endif; ?></td><td class="number"><?= money($merchant['expenses']) ?></td><td class="number"><?= $merchant['refunds'] ? money($merchant['refunds']) : '—' ?></td><td class="number"><strong><?= money($merchant['expenses'] - $merchant['refunds']) ?></strong></td><td><a href="<?= escape(analyzer_url($month, $accountFilter) . '&merchant=' . $merchant['id'] . '#merchant-' . $merchant['id']) ?>">Edit / details</a></td></tr>
    <?php endforeach; ?>
    </tbody><tfoot><tr><th colspan="2">Total</th><td class="number"><?= money($report['expenses']) ?></td><td class="number"><?= money($report['refunds']) ?></td><td class="number"><?= money($report['net']) ?></td><td></td></tr></tfoot></table></div>
    <?php else: ?><p class="empty-inline">No merchants to show for this month.</p><?php endif; ?>
</section>

<?php if ($report['payment_rows']): ?><details class="panel payments"><summary>Card payments · <?= money($report['payments']) ?> excluded from spending</summary><div class="table-scroll"><table><thead><tr><th>Date</th><th>Card</th><th class="number">Payment</th></tr></thead><tbody><?php foreach ($report['payment_rows'] as $payment): ?><tr><td><?= escape($payment['transaction_date']) ?></td><td><?= escape($payment['account']) ?></td><td class="number"><?= money((int) $payment['amount_cents']) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
<?php elseif ($report !== null): ?>
<section class="empty-state"><div class="eyebrow">A CLEARER PICTURE STARTS HERE</div><h2>Your first monthly report is one upload away.</h2><p>Upload your card’s CSV export. We’ll group repeat merchants, suggest categories, and show where your spending goes.</p><div class="empty-steps"><span><b>01</b> Upload a CSV</span><span><b>02</b> Review & save</span><span><b>03</b> Explore your month</span></div></section>
<?php endif; ?>

<section class="panel upload-panel" id="upload" aria-labelledby="upload-heading">
    <div><div class="eyebrow">ADD A STATEMENT</div><h2 id="upload-heading">Bring your spending into view.</h2><p class="muted">Use the same card for overlapping statements. Previously imported transactions will be skipped.</p><p class="hint">During automatic imports, CSV duplicates are matched by date, amount, and merchant. If a match is missing or ambiguous, the upload stops for review.</p><p class="hint">Supports Date, Name or Description, and Amount columns. Memo and Transaction columns improve matching. Amounts are in USD.</p></div>
    <form method="post" action="/?page=analyzer" enctype="multipart/form-data" class="upload-form">
        <?php csrf_field(); ?><input type="hidden" name="action" value="upload"><input type="hidden" name="MAX_FILE_SIZE" value="<?= ANALYZER_MAX_BYTES ?>">
        <?php if ($accounts): ?><label for="upload-account">Card</label><select name="account_id" id="upload-account"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $accountFilter ? 'selected' : '' ?>><?= escape($account['label']) ?></option><?php endforeach; ?><option value="0">Add a different card</option></select><?php else: ?><input type="hidden" name="account_id" value="0"><?php endif; ?>
        <label for="account-label"><?= $accounts ? 'New card name (only when adding a card)' : 'Give this card a name' ?></label><input id="account-label" name="account_label" maxlength="80" placeholder="e.g. My Visa · 9814" <?= $accounts ? '' : 'required' ?>><p class="hint">A nickname or last four digits is enough.</p>
        <label for="charge-sign">How are purchases shown in your CSV?</label><select id="charge-sign" name="charge_sign"><option value="negative">Negative amounts (e.g. −19.80)</option><option value="positive">Positive amounts (e.g. 19.80)</option></select>
        <label for="statement">CSV statement</label><input id="statement" name="statement" type="file" accept=".csv,text/csv" required><p class="hint">Up to 2 MB · 10,000 rows · Nothing is saved until you confirm the preview.</p><p class="hint">Unfamiliar merchant names and category hints are sent to OpenAI for automatic categorization. Amounts, dates, account details, and memos stay in this app.</p>
        <button type="submit">Preview statement</button>
    </form>
</section>

<?php if ($imports): ?><section class="panel import-history"><div class="section-heading"><h2>Recent imports</h2><span class="muted">Latest five · all cards</span></div><div class="table-scroll"><table><thead><tr><th>File</th><th>Card</th><th>Imported (UTC)</th><th class="number">Saved</th><th class="number">Skipped</th></tr></thead><tbody><?php foreach ($imports as $import): ?><tr><td class="wrap"><?= escape($import['filename']) ?></td><td><?= escape($import['account']) ?></td><td class="nowrap"><?= escape($import['created_at']) ?></td><td class="number"><?= (int) $import['added_count'] ?></td><td class="number"><?= (int) $import['skipped_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
