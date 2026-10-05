<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="panel comparison-panel" aria-labelledby="comparison-heading">
    <div class="section-heading"><div><div class="eyebrow">MONTH OVER MONTH</div><h2 id="comparison-heading"><?= escape(month_label($month)) ?> vs <?= escape(month_label($comparison['previous_month'])) ?></h2></div>
    <?php if ($comparison['available']): $change = $comparison['total']; ?><span class="change-pill <?= $change['delta'] > 0 ? 'change-up' : ($change['delta'] < 0 ? 'change-down' : '') ?>" tabindex="0" data-tooltip="<?= escape(signed_money($change['delta']) . ' in purchases. ' . change_caption($change)) ?>"><?= $change['delta'] > 0 ? '↑ ' : ($change['delta'] < 0 ? '↓ ' : '') ?><?= $change['percent'] === null ? ($change['delta'] > 0 ? 'New spending' : 'No change') : number_format(abs($change['percent']), 1) . '%' ?></span><?php endif; ?></div>
    <?php if ($comparison['partial']): ?><p class="hint">Through day <?= (int) $comparison['current_day'] ?> this month vs day <?= (int) $comparison['previous_day'] ?> last month. Imported transactions only; posting delays may affect the comparison.</p><?php elseif (!($comparison['current']['complete'] ?? false) || !($comparison['previous']['complete'] ?? false)): ?><p class="hint">Full-month date ranges · data not confirmed complete for both months.</p><?php endif; ?>
    <?php if ($comparison['available']): $barMax = max(1, $comparison['previous']['expenses'], $comparison['current']['expenses']); ?>
    <div class="comparison-visual">
        <?php foreach (['previous' => $comparison['previous_month'], 'current' => $month] as $key => $barMonth): $value = $comparison[$key]['expenses']; $tip = month_label($barMonth) . ($comparison['partial'] ? ' · through day ' . $comparison[$key . '_day'] : '') . "
Purchases " . money($value) . "
Refunds " . money($comparison[$key]['refunds']); ?>
        <a class="comparison-bar" href="<?= escape(analyzer_url($barMonth, $accountFilter)) ?>" data-tooltip="<?= escape($tip) ?>" aria-label="<?= escape($tip) ?>">
            <span><?= escape((new DateTimeImmutable($barMonth . '-01'))->format('M')) ?><?= $comparison['partial'] ? ' · 1–' . (int) $comparison[$key . '_day'] : '' ?></span>
            <svg viewBox="0 0 500 38" preserveAspectRatio="none" aria-hidden="true"><rect width="500" height="38" rx="8" fill="#f0f3f8"/><rect width="<?= sprintf('%.2F', $value / $barMax * 500) ?>" height="38" rx="8" fill="<?= $key === 'previous' ? '#a0d8c7' : '#00796b' ?>"/></svg><strong><?= money($value) ?></strong>
        </a><?php endforeach; ?>
    </div>
    <p class="comparison-caption <?= $change['delta'] > 0 ? 'change-up' : ($change['delta'] < 0 ? 'change-down' : '') ?>"><?= signed_money($change['delta']) ?> in purchases<?= $month === gmdate('Y-m') ? ' · month in progress' : '' ?></p>
    <details class="chart-data"><summary>Category breakdown &amp; exact figures</summary>
    <?php if ($comparison['categories']): ?><div class="table-scroll"><table class="comparison-table"><caption>Category amounts and share of purchases in the compared periods</caption><thead><tr><th>Category</th><th class="number">Previous month<small>Amount · share of bill</small></th><th class="number">Selected month<small>Amount · share of bill</small></th><th class="number">Spending change<small>Dollars · percent</small></th><th class="number">Share change<small>Percentage points</small></th></tr></thead><tbody>
    <?php foreach ($comparison['categories'] as $item): ?>
    <tr><th scope="row"><?= escape($item['name']) ?></th>
    <td class="number"><?= money($item['previous']) ?><small><?= $item['previous_share'] === null ? '—' : number_format($item['previous_share'], 1) . '%' ?></small></td>
    <td class="number"><?= money($item['current']) ?><small><?= $item['share'] === null ? '—' : number_format($item['share'], 1) . '%' ?></small></td>
    <td class="number <?= $item['delta'] > 0 ? 'change-up' : ($item['delta'] < 0 ? 'change-down' : '') ?>"><?= signed_money($item['delta']) ?><small><?= $item['percent'] === null ? ($item['current'] > 0 ? 'New spending' : '—') : signed_percent($item['percent']) ?></small></td>
    <td class="number"><?= signed_percent($item['share_delta'], ' pp') ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>

    <p class="hint">Purchases before refunds. Share change is in percentage points. Imported history may cover partial months.</p>
    </details>
    <?php else: ?><p class="empty-inline"><?= !$comparison['current'] ? 'No transactions have been imported for the selected month.' : 'No transactions have been imported for ' . escape(month_label($comparison['previous_month'])) . '.' ?></p><?php endif; ?>
</section>
