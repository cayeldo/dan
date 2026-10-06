<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="page-heading dashboard-heading">
    <div><div class="eyebrow">YOUR OVERVIEW</div><h1>Welcome back, <?= escape($displayName) ?>.</h1></div>
    <a class="button secondary" href="/?page=analyzer">Open analyzer ↗</a>
</section>
<?php if ($dashboard && $dashboard['latest']): $latest = $dashboard['latest']; $latestPeriod = $history[$latest]; ?>
<section class="overview-metrics" aria-label="Spending overview">
    <div><span><?= escape(month_label($latest)) ?><?= $latest === gmdate('Y-m') ? ' · so far' : '' ?></span><strong><?= money($latestPeriod['expenses']) ?></strong></div>
    <div><span>vs <?= escape(month_label(previous_month($latest))) ?><?= $dashboard['comparison']['partial'] ? ' · through day ' . (int) $dashboard['comparison']['previous_day'] : '' ?></span><strong class="<?= $dashboard['comparison']['available'] ? ($dashboard['comparison']['total']['delta'] > 0 ? 'change-up' : 'change-down') : '' ?>"><?= $dashboard['comparison']['available'] ? signed_money($dashboard['comparison']['total']['delta']) : '—' ?></strong></div>
    <div><span>All-time purchases</span><strong><?= money($dashboard['expenses']) ?></strong></div>
</section>
<?php if ($latestReview && !$latestReview['stale']): ?>
<section class="panel monthly-review-teaser"><div><div class="eyebrow"><?= escape(month_label($latestReview['month'])) ?> · KLE Coin’s Take</div><h2><?= escape($latestReview['result']['headline']) ?></h2><p class="hint"><?= escape(month_review_teaser($latestReview['result']['summary'])) ?></p></div><a class="button secondary" href="<?= escape(analyzer_url($latestReview['month']) . '#monthly-review') ?>">Read review ↗</a></section>
<?php endif; ?>
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
            <span><?= escape($label) ?></span><svg viewBox="0 0 64 72" aria-hidden="true"><rect width="64" height="72" rx="10" fill="#eff3fa"/><?php if ($value !== null): ?><rect x="0" y="<?= sprintf('%.2F', 72 - max(3, $ratio * 72)) ?>" width="64" height="<?= sprintf('%.2F', max(3, $ratio * 72)) ?>" rx="8" fill="<?= $value === $peak ? '#005f55' : '#5fbaa0' ?>"/><?php else: ?><text x="32" y="42" text-anchor="middle" fill="#56657b">—</text><?php endif; ?></svg>
            <small><?= $key === gmdate('Y-m') ? 'so far' : substr($key, 0, 4) ?></small>
        </a><?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/overview-insights.php'; ?>
<details class="data-note"><summary>About these numbers</summary><p>Based on imported purchases, before refunds. Missing months stay empty. Current-month totals are still in progress; other months may also contain partial statement history. Category links open their latest month with purchases. All-time net spending: <?= money($dashboard['expenses'] - $dashboard['refunds']) ?> after <?= money($dashboard['refunds']) ?> in credits.</p></details>
<?php elseif ($dashboard !== null): ?>
<section class="empty-state"><h2>A few charts. A clearer picture.</h2><p>Upload your first statement to begin.</p><a class="button" href="/?page=imports">Upload a statement</a></section>
<?php endif; ?>
