<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($overviewInsights && $overviewInsights['rows']): ?>
<section class="panel overview-insights" aria-labelledby="insights-heading">
    <div class="section-heading"><h2 id="insights-heading">Worth a look</h2><span class="hint"><?= escape($overviewInsights['caption']) ?></span></div>
    <ul class="insight-visuals">
    <?php foreach ($overviewInsights['rows'] as $insight):
        $visual = $insight['visual']; $bar = null; $comparisonBar = null;
        $periodLabel = $overviewInsights['month'] === (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m') ? 'this month' : 'in ' . (new DateTimeImmutable($overviewInsights['month'] . '-01'))->format('M');
        if ($visual === 'budget') {
            $left = $insight['remaining_cents']; $metric = insight_figure(abs($left)); $metricLabel = $left < 0 ? 'over budget' : 'left';
            $support = insight_figure($insight['spent_cents']) . ' of ' . insight_figure($insight['target_cents']) . ' budget';
            $bar = $insight['target_cents'] > 0 ? min(100, $insight['spent_cents'] / $insight['target_cents'] * 100) : 100;
        } elseif ($visual === 'frequency') {
            $metric = $insight['count'] . '×'; $metricLabel = 'purchases';
            $support = insight_figure($insight['amount_cents']) . ' ' . $periodLabel . ' · ' . insight_figure((int) round($insight['amount_cents'] / $insight['count'])) . ' each';
        } elseif ($visual === 'fund') {
            $fund = $insight['fund']; $total = max(1, $fund['opening_cents'] + $fund['contribution_cents']);
            $metric = insight_figure($fund['available_cents']); $metricLabel = 'available';
            $support = $fund['reallocated_cents'] > 0 ? insight_figure($fund['reallocated_cents']) . ' used for other spending' : ($fund['contribution_cents'] > 0 ? insight_figure($fund['contribution_cents']) . ' set aside ' . $periodLabel : 'Ready for your next trip');
            $bar = min(100, $fund['available_cents'] / $total * 100);
        } elseif ($visual === 'comparison') {
            $metric = '↓ ' . insight_figure(abs($insight['change_cents'])); $metricLabel = 'less spent';
            $support = $insight['current_label'] . ' vs ' . $insight['previous_label'];
            $comparisonBar = min(100, $insight['current_cents'] / max(1, $insight['previous_cents']) * 100);
        } else { $metric = '↗'; $metricLabel = ''; $support = 'Explore your budget'; }
        $icon = $visual === 'frequency' ? (preg_match('/tatte|coffee|starbucks|bakery/i', $insight['name']) ? 'coffee' : 'shop') : ($visual === 'fund' ? 'travel' : ($visual === 'comparison' ? 'trend' : 'wallet'));
        if ($visual === 'budget' && preg_match('/grocer/i', $insight['name'])) { $icon = 'basket'; }
        if ($visual === 'budget' && preg_match('/restaurant|delivery/i', $insight['name'])) { $icon = 'dining'; }
        $paths = [
            'coffee' => '<path d="M7 11h18l-2 17H9Z M5 7h22v4H5Z M10 4h12l2 3H8Z"/><circle cx="16" cy="19" r="3"/>',
            'shop' => '<path d="M6 11h20l-2 17H8Z M11 12V8a5 5 0 0 1 10 0v4"/>',
            'travel' => '<path d="m5 16 10-3V5c0-3 4-3 4 0v8l9 3v3l-9-2v7l4 3v2l-6-2-6 2v-2l4-3v-7L5 19Z"/>',
            'trend' => '<path d="M5 7v20h23 M9 10l7 7 4-3 7 8 M21 22h6v-6"/>',
            'wallet' => '<path d="M25 10V6H7a3 3 0 0 0 0 6h20v15H7a3 3 0 0 1-3-3V9 M27 17h-8v6h8"/><circle cx="22" cy="20" r=".6"/>',
            'basket' => '<path d="M4 12h24l-3 15H7Z M10 12l5-8 M22 12l-5-8 M11 17v5 M16 17v5 M21 17v5"/>',
            'dining' => '<path d="M8 4v9m-4-9v6a4 4 0 0 0 8 0V4 M8 14v14 M24 4c-5 5-5 11 0 12V4Zm0 12v12"/>',
        ]; ?>
        <li><div class="insight-row insight-<?= escape($visual) ?> <?= $visual === 'budget' && $left < 0 ? 'insight-over' : '' ?>">
            <a class="insight-link" href="<?= escape($insight['url']) ?>" aria-label="<?= escape($insight['title'] . '. ' . $insight['detail'] . ' Open details.') ?>"></a>
            <span class="insight-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $paths[$icon] ?></svg></span>
            <span class="insight-content"><span class="insight-title"><strong class="insight-name"><?= escape($insight['name']) ?></strong><button type="button" class="insight-info" data-tooltip-toggle data-tooltip="<?= escape(insight_explanation($insight, $overviewInsights['month'])) ?>" aria-label="<?= escape('Explain ' . $insight['name']) ?>"><svg viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="7.3"/><path d="M10 9v5"/><circle cx="10" cy="6.5" r=".6" fill="currentColor" stroke="none"/></svg></button></span><span class="insight-support"><?= escape($support) ?></span>
            <?php if ($bar !== null): ?><svg class="insight-meter" viewBox="0 0 300 6" preserveAspectRatio="none" aria-hidden="true"><rect width="300" height="6" rx="3" class="meter-track"/><rect width="<?= sprintf('%.2F', $bar * 3) ?>" height="6" rx="3" class="meter-value"/></svg><?php endif; ?>
            <?php if ($comparisonBar !== null): ?><svg class="insight-comparison-bars" viewBox="0 0 300 15" preserveAspectRatio="none" aria-hidden="true"><rect width="300" height="5" rx="2.5" class="comparison-previous"/><rect y="10" width="<?= sprintf('%.2F', $comparisonBar * 3) ?>" height="5" rx="2.5" class="comparison-current"/></svg><?php endif; ?>
            </span>
            <span class="insight-value"><strong><?= escape($metric) ?></strong><span><?= escape($metricLabel) ?></span></span>
        </div></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
