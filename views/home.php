<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="page-heading dashboard-heading">
    <div><div class="eyebrow">YOUR OVERVIEW</div><h1>Welcome back, <?= escape($displayName) ?>.</h1></div>
    <a class="button secondary" href="/?page=analyzer">Open analyzer ↗</a>
</section>
<?php if ($dashboard && $dashboard['latest']): $latest = $dashboard['latest']; $latestPeriod = $history[$latest]; ?>
<section class="overview-metrics" aria-label="Spending overview">
    <div><span><?= escape(month_label($latest)) ?><?= $latest === gmdate('Y-m') ? ' · so far' : '' ?></span><strong><?= money($latestPeriod['expenses']) ?></strong></div>
    <div><span>vs <?= escape(month_label(previous_month($latest))) ?></span><strong class="<?= $dashboard['comparison']['available'] ? ($dashboard['comparison']['total']['delta'] > 0 ? 'change-up' : 'change-down') : '' ?>"><?= $dashboard['comparison']['available'] ? signed_money($dashboard['comparison']['total']['delta']) : '—' ?></strong></div>
    <div><span>All-time purchases</span><strong><?= money($dashboard['expenses']) ?></strong></div>
</section>
<div class="dashboard-grid">
<section class="panel trend-panel" aria-labelledby="trend-heading">
    <div class="section-heading"><h2 id="trend-heading">Monthly spending</h2><span class="pill"><?= count($dashboard['series']) ?> months</span></div>
    <?php require __DIR__ . '/spending-line.php'; ?>
    <details class="chart-data"><summary>Exact figures</summary><div class="table-scroll"><table><thead><tr><th>Month</th><th class="number">Purchases</th><th class="number">Refunds</th><th class="number">Net</th></tr></thead><tbody><?php foreach (array_reverse($dashboard['series'], true) as $key => $value): ?><tr><th scope="row"><a href="<?= escape(analyzer_url($key)) ?>"><?= escape(month_label($key)) ?></a></th><td class="number"><?= $value === null ? 'No data' : money($value) ?></td><td class="number"><?= $value === null ? '—' : money($history[$key]['refunds']) ?></td><td class="number"><?= $value === null ? '—' : money($history[$key]['net']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
</section>
<section class="panel cumulative-panel" aria-labelledby="cumulative-heading">
    <div class="section-heading"><h2 id="cumulative-heading">Cumulative expenses</h2><span class="pill">All time</span></div>
    <?php if ($dashboard['expenses'] > 0):
        $chartId = 'cumulative'; $chartTitle = 'All-time purchases by category'; $chartTotal = $dashboard['expenses']; $chartItems = [];
        foreach ($dashboard['categories'] as $item) { $chartItems[] = $item + ['url' => analyzer_url($item['latest_month']) . '&category=' . $item['id'] . '#category-' . $item['id']]; }
        require __DIR__ . '/category-chart.php';
    else: ?><p class="empty-inline">No purchases yet.</p><?php endif; ?>
</section>
</div>
<section class="panel year-panel" aria-labelledby="year-heading">
    <div class="section-heading"><div><h2 id="year-heading">Your spending rhythm</h2><p class="hint"><?= escape(month_label(array_key_first($dashboard['series']))) ?>–<?= escape(month_label($latest)) ?></p></div>
        <?php if ($dashboard['average'] !== null): ?><div class="average-metric" tabindex="0" data-tooltip="<?= escape('Average of ' . count($dashboard['pastMonths']) . ' imported months in this chart, excluding the current month. Missing months are excluded; uploaded history may be partial.') ?>"><span>Average month</span><strong><?= money($dashboard['average']) ?></strong></div><?php endif; ?>
    </div>
    <div class="month-grid">
        <?php $peak = max(1, ...array_values(array_filter($dashboard['series'], fn($v) => $v !== null))); foreach ($dashboard['series'] as $key => $value):
            $label = (new DateTimeImmutable($key . '-01'))->format('M'); $ratio = $value === null ? 0 : $value / $peak;
            $tip = month_label($key) . ($key === gmdate('Y-m') ? ' · so far' : '') . "\n" . ($value === null ? 'No imported data' : money($value) . ' in purchases'); ?>
        <a class="month-tile <?= $key === gmdate('Y-m') ? 'in-progress' : '' ?>" href="<?= escape(analyzer_url($key)) ?>" data-tooltip="<?= escape($tip) ?>" aria-label="<?= escape($tip) ?>">
            <span><?= escape($label) ?></span><svg viewBox="0 0 64 72" aria-hidden="true"><rect width="64" height="72" rx="10" fill="#eff3fa"/><?php if ($value !== null): ?><rect x="0" y="<?= sprintf('%.2F', 72 - max(3, $ratio * 72)) ?>" width="64" height="<?= sprintf('%.2F', max(3, $ratio * 72)) ?>" rx="8" fill="<?= $value === $peak ? '#193d9c' : '#7799ef' ?>"/><?php else: ?><text x="32" y="42" text-anchor="middle" fill="#56657b">—</text><?php endif; ?></svg>
            <small><?= $key === gmdate('Y-m') ? 'so far' : substr($key, 0, 4) ?></small>
        </a><?php endforeach; ?>
    </div>
</section>
<section class="panel shifts-panel" aria-labelledby="movers-heading">
    <div class="section-heading"><div><h2 id="movers-heading">Category shifts</h2><p class="hint"><?= escape((new DateTimeImmutable($latest . '-01'))->format('M')) ?><?= $latest === gmdate('Y-m') ? ' so far' : '' ?> vs <?= escape((new DateTimeImmutable(previous_month($latest) . '-01'))->format('M')) ?></p></div><a href="<?= escape(analyzer_url($latest) . '#comparison-heading') ?>">Compare ↗</a></div>
    <?php if ($dashboard['movers']): $largestChange = max(1, ...array_map(fn($item) => abs($item['delta']), $dashboard['movers'])); ?>
    <div class="shift-grid">
    <?php foreach ($dashboard['movers'] as $item): $length = abs($item['delta']) / $largestChange * 135;
        $tip = $item['name'] . "\n" . month_label(previous_month($latest)) . ': ' . money($item['previous']) . "\n" . month_label($latest) . ($latest === gmdate('Y-m') ? ' so far' : '') . ': ' . money($item['current']) . "\n" . signed_money($item['delta']) . ($item['percent'] === null ? ' · new spending' : ' · ' . signed_percent($item['percent']));
        $targetMonth = $item['current'] > 0 ? $latest : previous_month($latest); ?>
    <a class="shift-card" href="<?= escape(analyzer_url($targetMonth) . '&category=' . $item['id'] . '#category-' . $item['id']) ?>" data-tooltip="<?= escape($tip) ?>">
        <span><?= escape($item['name']) ?></span><strong class="<?= $item['delta'] > 0 ? 'change-up' : 'change-down' ?>"><?= $item['delta'] > 0 ? '↑ ' : '↓ ' ?><?= money(abs($item['delta'])) ?></strong>
        <svg viewBox="0 0 300 30" aria-hidden="true"><line x1="150" x2="150" y1="0" y2="30" stroke="#c9d3e5"/><rect x="<?= sprintf('%.2F', $item['delta'] > 0 ? 150 : 150 - $length) ?>" y="6" width="<?= sprintf('%.2F', $length) ?>" height="18" rx="5" fill="<?= $item['delta'] > 0 ? '#c84b51' : '#128169' ?>"/></svg>
    </a><?php endforeach; ?>
    </div>
    <?php else: ?><p class="hint"><?= $dashboard['comparison']['available'] ? 'No large changes this month.' : 'Add the previous month to see changes.' ?></p><?php endif; ?>
</section>
<details class="data-note"><summary>About these numbers</summary><p>Based on imported purchases, before refunds. Missing months stay empty. Current-month totals are still in progress; other months may also contain partial statement history. Category links open their latest month with purchases. All-time net spending: <?= money($dashboard['expenses'] - $dashboard['refunds']) ?> after <?= money($dashboard['refunds']) ?> in credits.</p></details>
<?php elseif ($dashboard !== null): ?>
<section class="empty-state"><h2>A few charts. A clearer picture.</h2><p>Upload your first statement to begin.</p><a class="button" href="/?page=statements">Upload a statement</a></section>
<?php endif; ?>
