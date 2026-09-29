<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="panel comparison-panel" aria-labelledby="comparison-heading">
    <div class="section-heading"><div><div class="eyebrow">MONTH OVER MONTH</div><h2 id="comparison-heading"><?= escape(month_label($month)) ?> vs <?= escape(month_label($comparison['previous_month'])) ?></h2></div>
    <?php if ($comparison['previous']): ?><a href="<?= escape(analyzer_url($comparison['previous_month'], $accountFilter)) ?>">Open previous month ↗</a><?php endif; ?></div>
    <p class="hint">Compares imported purchases for the same card selection, before refunds. Share of bill = category purchases ÷ total purchases. Uploads may cover only part of a month.<?= $month === gmdate('Y-m') ? ' This month is still in progress; totals are so far.' : '' ?></p>
    <?php if ($comparison['available']): $change = $comparison['total']; ?>
    <div class="comparison-summary">
        <div><span><?= escape(month_label($comparison['previous_month'])) ?></span><strong><?= money($comparison['previous']['expenses']) ?></strong></div>
        <div><span><?= escape(month_label($month)) ?><?= $month === gmdate('Y-m') ? ' · so far' : '' ?></span><strong><?= money($comparison['current']['expenses']) ?></strong></div>
        <div class="<?= $change['delta'] > 0 ? 'change-up' : ($change['delta'] < 0 ? 'change-down' : '') ?>"><span>Change in purchases</span><strong><?= signed_money($change['delta']) ?></strong><small><?= escape(change_caption($change)) ?></small></div>
    </div>
    <?php if ($comparison['categories']): ?><div class="table-scroll"><table class="comparison-table"><caption>Category amounts and share of each month’s bill</caption><thead><tr><th>Category</th><th class="number">Previous month<small>Amount · share of bill</small></th><th class="number">Selected month<small>Amount · share of bill</small></th><th class="number">Spending change<small>Dollars · percent</small></th><th class="number">Share change<small>Percentage points</small></th></tr></thead><tbody>
    <?php foreach ($comparison['categories'] as $item): ?>
    <tr><th scope="row"><?= escape($item['name']) ?></th>
    <td class="number"><?= money($item['previous']) ?><small><?= $item['previous_share'] === null ? '—' : number_format($item['previous_share'], 1) . '%' ?></small></td>
    <td class="number"><?= money($item['current']) ?><small><?= $item['share'] === null ? '—' : number_format($item['share'], 1) . '%' ?></small></td>
    <td class="number <?= $item['delta'] > 0 ? 'change-up' : ($item['delta'] < 0 ? 'change-down' : '') ?>"><?= signed_money($item['delta']) ?><small><?= $item['percent'] === null ? ($item['current'] > 0 ? 'New spending' : '—') : signed_percent($item['percent']) ?></small></td>
    <td class="number"><?= signed_percent($item['share_delta'], ' pp') ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <p class="hint">A move from 10% to 15% of the bill is +5 percentage points (pp). A dash means there were no purchases to calculate a percentage from.</p>
    <?php else: ?><p class="empty-inline"><?= !$comparison['current'] ? 'No transactions have been imported for the selected month.' : 'No transactions have been imported for ' . escape(month_label($comparison['previous_month'])) . '. Upload that month to see a comparison.' ?> Missing months are not counted as zero spending.</p><?php endif; ?>
</section>
