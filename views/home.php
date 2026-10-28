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
<?php $useStatementRhythm = !empty($rhythm) && ($_GET['rhythm'] ?? '') !== 'calendar'; $rhythmSeries = $useStatementRhythm ? $rhythm['series'] : $dashboard['series']; $rhythmAverage = $useStatementRhythm ? $rhythm['average'] : $dashboard['average']; ?>
<section class="panel year-panel" aria-labelledby="year-heading">
    <div class="section-heading"><div><h2 id="year-heading">Your spending rhythm</h2><p class="hint"><?= escape(month_label(array_key_first($rhythmSeries))) ?>–<?= escape(month_label(array_key_last($rhythmSeries))) ?></p><?php if (!empty($rhythm)): ?><p class="hint"><?= $useStatementRhythm ? 'Spending by statement period · ' . escape($rhythm['label']) : 'Calendar-month spending' ?> · <a href="/?rhythm=<?= $useStatementRhythm ? 'calendar' : 'statement' ?>#year-heading"><?= $useStatementRhythm ? 'View calendar months' : 'View statement periods' ?></a></p><?php endif; ?></div>
        <?php if ($rhythmAverage !== null): ?><div class="average-metric" tabindex="0" data-tooltip="<?= escape($useStatementRhythm ? 'Average of ' . $rhythm['closed_count'] . ' ended periods with imported activity. Estimated dates and partial history may affect totals.' : 'Average of past calendar months with imported activity; missing months are excluded.') ?>"><span><?= $useStatementRhythm ? 'Average statement' : 'Average month' ?></span><strong><?= money($rhythmAverage) ?></strong></div><?php endif; ?>
    </div>
    <div class="month-grid">
        <?php $peak = max(1, ...array_values(array_filter($rhythmSeries, fn($v) => $v !== null))); foreach ($rhythmSeries as $key => $value):
            $label = (new DateTimeImmutable($key . '-01'))->format('M'); $ratio = $value === null ? 0 : $value / $peak;
            $tilePeriod = $useStatementRhythm ? $rhythm['periods'][$key] : null; $tileOpen = $tilePeriod ? $tilePeriod['open'] : $key === gmdate('Y-m');
            $tileUrl = $tilePeriod ? '/?' . http_build_query(['page' => 'statements', 'month' => $key, 'account' => $rhythm['account']]) : analyzer_url($key);
            $tip = month_label($key) . ($tileOpen ? ' · so far' : '') . "\n" . ($value === null ? 'No imported data' : money($value) . ' in purchases') . ($tilePeriod ? "\n" . statement_period_caption($tilePeriod) . "\n" . statement_due_caption($tilePeriod) : ''); ?>
        <a class="month-tile <?= $tileOpen ? 'in-progress' : '' ?>" href="<?= escape($tileUrl) ?>" data-tooltip="<?= escape($tip) ?>" aria-label="<?= escape($tip) ?>">
            <span><?= escape($label) ?></span><svg viewBox="0 0 64 72" aria-hidden="true"><rect width="64" height="72" rx="10" fill="#eff3fa"/><?php if ($value !== null): ?><rect x="0" y="<?= sprintf('%.2F', 72 - max(3, $ratio * 72)) ?>" width="64" height="<?= sprintf('%.2F', max(3, $ratio * 72)) ?>" rx="8" fill="<?= $value === $peak ? '#005f55' : '#5fbaa0' ?>"/><?php else: ?><text x="32" y="42" text-anchor="middle" fill="#56657b">—</text><?php endif; ?></svg>
            <small><?= $tileOpen ? 'so far' : ($tilePeriod && $tilePeriod['estimated'] ? 'estimated' : substr($key, 0, 4)) ?></small>
        </a><?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/overview-insights.php'; ?>
<details class="data-note"><summary>About these numbers</summary><p>Based on imported purchases, before refunds. Missing months stay empty. Current-month totals are still in progress; other months may also contain partial statement history. Category links open their latest month with purchases. All-time net spending: <?= money($dashboard['expenses'] - $dashboard['refunds']) ?> after <?= money($dashboard['refunds']) ?> in credits.</p></details>
<?php if ($latestReview && !$latestReview['stale']): ?>
<section class="panel monthly-review-teaser"><div><div class="eyebrow"><?= escape(month_label($latestReview['month'])) ?> · KLE Coin’s Take</div><h2><?= escape($latestReview['result']['headline']) ?></h2><p class="hint"><?= escape(month_review_teaser($latestReview['result']['summary'])) ?></p></div><a class="button secondary" href="<?= escape(analyzer_url($latestReview['month']) . '#monthly-review') ?>">Read review ↗</a></section>
<?php endif; ?>
<?php elseif ($dashboard !== null): ?>
<section class="empty-state"><h2>A few charts. A clearer picture.</h2><p>Upload your first statement to begin.</p><a class="button" href="/?page=imports">Upload a statement</a></section>
<?php endif; ?>
