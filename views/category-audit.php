<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
$expandedCategory = is_scalar($_GET['category'] ?? null) ? (int) $_GET['category'] : 0;
$expandedMerchant = is_scalar($_GET['merchant'] ?? null) ? (int) $_GET['merchant'] : 0;
$auditAll = ($_GET['audit'] ?? '') === 'all';
$auditGroups = array_filter($report['category_groups'], fn($group) => $auditAll || $expandedCategory === $group['id'] || in_array($expandedMerchant, $group['merchant_ids'], true));
if (!$auditGroups) { return; }
?>
<section class="panel category-audit-panel" aria-labelledby="audit-heading">
    <div class="section-heading"><div><h2 id="audit-heading">Audit your categories</h2><p class="hint">Select a merchant to review or edit.</p></div><a href="<?= escape(analyzer_url($month, $accountFilter) . '#spending-chart') ?>">Close details ×</a></div>
    <?php foreach ($auditGroups as $group): ?>
    <details class="category-audit" id="category-<?= $group['id'] ?>" <?= $expandedCategory === $group['id'] || in_array($expandedMerchant, $group['merchant_ids'], true) ? 'open' : '' ?>>
        <summary class="audit-summary">
            <span class="audit-chevron" aria-hidden="true">›</span>
            <span class="audit-name"><strong><?= escape($group['name']) ?></strong><small><?= count($group['merchant_ids']) ?> merchant<?= count($group['merchant_ids']) === 1 ? '' : 's' ?> · <?= $group['count'] ?> transaction<?= $group['count'] === 1 ? '' : 's' ?><?= $group['review_count'] ? ' · ' . $group['review_count'] . ' not confirmed' : '' ?></small></span>
            <span class="audit-amount"><small>Purchases</small><strong><?= money($group['expenses']) ?></strong></span>
            <span class="audit-amount"><small>Credits</small><strong><?= money($group['refunds']) ?></strong></span>
            <span class="audit-amount"><small>Net spending</small><strong><?= money($group['expenses'] - $group['refunds']) ?></strong></span>
        </summary>
        <div class="audit-merchants">
        <?php foreach ($group['merchant_ids'] as $merchantId): $merchant = $report['merchants'][$merchantId]; ?>
            <details class="audit-merchant" id="merchant-<?= $merchant['id'] ?>" <?= $expandedMerchant === $merchant['id'] ? 'open' : '' ?>>
                <summary class="audit-summary">
                    <span class="audit-chevron" aria-hidden="true">›</span>
                    <span class="audit-name"><strong><?= escape($merchant['name']) ?></strong><small><?= count($merchant['rows']) ?> transaction<?= count($merchant['rows']) === 1 ? '' : 's' ?><?= category_automation_note($merchant) ? ' · ' . escape(category_automation_note($merchant)) : '' ?></small></span>
                    <span class="audit-amount"><small>Purchases</small><strong><?= money($merchant['expenses']) ?></strong></span>
                    <span class="audit-amount"><small>Credits</small><strong><?= money($merchant['refunds']) ?></strong></span>
                    <span class="audit-amount"><small>Net spending</small><strong><?= money($merchant['expenses'] - $merchant['refunds']) ?></strong></span>
                </summary>
                <div class="editor-content">
                    <div class="table-scroll audit-transactions"><table class="transaction-table"><caption><?= escape($merchant['name']) ?> transactions · <?= escape(month_label($month)) ?></caption><thead><tr><th scope="col">Date</th><th scope="col">Original description</th><th scope="col">Card</th><th scope="col">Type</th><th scope="col" class="number">Amount</th></tr></thead><tbody>
                    <?php foreach ($merchant['rows'] as $transaction): ?><tr><td class="nowrap"><?= escape($transaction['transaction_date']) ?></td><td class="description-cell"><?= escape($transaction['description']) ?></td><td><?= escape($transaction['account']) ?></td><td><?= $transaction['kind'] === 'expense' ? 'Purchase' : 'Credit' ?></td><td class="number"><?= money(abs((int) $transaction['amount_cents'])) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                    <form method="post" action="<?= escape(analyzer_url($month, $accountFilter)) ?>" class="merchant-form">
                        <?php csrf_field(); ?><input type="hidden" name="action" value="save_merchant"><input type="hidden" name="merchant_id" value="<?= $merchant['id'] ?>"><input type="hidden" name="month" value="<?= escape($month) ?>"><input type="hidden" name="account_filter" value="<?= $accountFilter ?>">
                        <div><label for="name-<?= $merchant['id'] ?>">Merchant name</label><input id="name-<?= $merchant['id'] ?>" name="merchant_name" value="<?= escape($merchant['name']) ?>" maxlength="120" required></div>
                        <div><label for="category-<?= $merchant['id'] ?>-select">Category</label><select id="category-<?= $merchant['id'] ?>-select" name="category_id"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === $merchant['category_id'] ? 'selected' : '' ?>><?= escape($category['name']) ?></option><?php endforeach; ?></select></div>
                        <div><label for="custom-<?= $merchant['id'] ?>">Or add a category</label><input id="custom-<?= $merchant['id'] ?>" name="custom_category" maxlength="80" placeholder="Your own category"></div>
                        <button type="submit">Save<?= $merchant['needs_review'] ? ' & confirm' : ' changes' ?></button>
                    </form>
                    <p class="hint">A new category overrides the dropdown. Using another existing merchant’s name merges both groups and applies this category across your reports.</p>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>
    <p class="hint">Category changes apply across your reports.</p>
</section>
