<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="welcome dashboard-welcome">
    <div><div class="eyebrow">YOUR PORTAL</div><h1>Welcome back, <?= escape($displayName) ?>.</h1><p class="page-intro">Your spending, in perspective.</p></div>
    <a class="button secondary" href="/?page=analyzer">Open analyzer ↗</a>
</section>
<?php if ($dashboard && $dashboard['latest']): $latest = $dashboard['latest']; $latestPeriod = $history[$latest]; ?>
<div class="section-heading overview-heading"><div><h2>Your spending at a glance</h2><p class="hint">All your cards · USD · Imported transactions only. Purchases exclude refunds and card payments; uploads may cover partial months.</p></div><a href="<?= escape(analyzer_url($latest)) ?>">View <?= escape(month_label($latest)) ?> ↗</a></div>
<section class="stat-grid" aria-label="Spending overview">
    <div class="stat primary-stat"><span><?= escape(month_label($latest)) ?><?= $latest === gmdate('Y-m') ? ' · so far' : '' ?></span><strong><?= money($latestPeriod['expenses']) ?></strong><small>Total purchases · latest imported month</small></div>
    <div class="stat"><span>Compared with <?= escape((new DateTimeImmutable(previous_month($latest) . '-01'))->format('M Y')) ?></span><strong><?= $dashboard['comparison']['available'] ? signed_money($dashboard['comparison']['total']['delta']) : '—' ?></strong><small><?= $dashboard['comparison']['available'] ? escape(change_caption($dashboard['comparison']['total'])) : 'Previous month has not been imported' ?></small></div>
    <div class="stat"><span>All-time purchases</span><strong><?= money($dashboard['expenses']) ?></strong><small><?= count($history) ?> imported month<?= count($history) === 1 ? '' : 's' ?> · all cards</small></div>
    <div class="stat"><span>All-time net spending</span><strong><?= money($dashboard['expenses'] - $dashboard['refunds']) ?></strong><small>After <?= money($dashboard['refunds']) ?> in refunds & credits</small></div>
</section>
<div class="dashboard-grid">
<section class="panel trend-panel" aria-labelledby="trend-heading">
    <div class="section-heading"><div><div class="eyebrow">THE BIG PICTURE</div><h2 id="trend-heading">Monthly spending</h2></div><span class="pill">Purchases</span></div>
    <?php require __DIR__ . '/spending-line.php'; ?>
    <p class="hint">Up to 12 calendar months through <?= escape(month_label($latest)) ?>. Select a point to explore that month. Gaps mean no uploaded data.<?= $latest === gmdate('Y-m') ? ' The current month shows spending so far.' : '' ?></p>
    <details class="chart-data"><summary>View monthly amounts</summary><div class="table-scroll"><table><thead><tr><th>Month</th><th class="number">Purchases</th><th class="number">Refunds</th><th class="number">Net</th></tr></thead><tbody><?php foreach (array_reverse($dashboard['series'], true) as $key => $value): ?><tr><th scope="row"><a href="<?= escape(analyzer_url($key)) ?>"><?= escape(month_label($key)) ?></a></th><td class="number"><?= $value === null ? 'No data' : money($value) ?></td><td class="number"><?= $value === null ? '—' : money($history[$key]['refunds']) ?></td><td class="number"><?= $value === null ? '—' : money($history[$key]['net']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
</section>
<section class="panel cumulative-panel" aria-labelledby="cumulative-heading">
    <div class="section-heading"><div><div class="eyebrow">ACROSS EVERY MONTH</div><h2 id="cumulative-heading">Cumulative expenses</h2></div><span class="pill">All time</span></div>
    <?php if ($dashboard['expenses'] > 0): ?>
    <div class="overview-donut"><svg viewBox="0 0 240 240" role="img" aria-labelledby="cumulative-title cumulative-description"><title id="cumulative-title">All-time purchases by category</title><desc id="cumulative-description">Category amounts and percentages are listed below. Total <?= money($dashboard['expenses']) ?>.</desc><circle cx="120" cy="120" r="90" fill="none" stroke="#edf1f7" stroke-width="30"/>
    <?php $offset = 0; $index = 0; $circumference = 2 * M_PI * 90; foreach ($dashboard['categories'] as $item): $length = $item['amount'] / $dashboard['expenses'] * $circumference; ?>
    <circle cx="120" cy="120" r="90" fill="none" stroke="<?= chart_color($index++) ?>" stroke-width="30" stroke-dasharray="<?= sprintf('%.5F %.5F', $length, $circumference - $length) ?>" stroke-dashoffset="<?= sprintf('%.5F', -$offset) ?>" transform="rotate(-90 120 120)"><title><?= escape($item['name']) ?>: <?= money($item['amount']) ?> · <?= number_format($item['amount'] / $dashboard['expenses'] * 100, 1) ?>%</title></circle>
    <?php $offset += $length; endforeach; ?><text x="120" y="111" text-anchor="middle" class="donut-label">TOTAL PURCHASES</text><text x="120" y="140" text-anchor="middle" class="donut-total"><?= money($dashboard['expenses']) ?></text></svg></div>
    <ul class="category-legend overview-legend"><?php $index = 0; foreach ($dashboard['categories'] as $item): ?><li><svg width="12" height="12" aria-hidden="true"><circle cx="6" cy="6" r="5" fill="<?= chart_color($index++) ?>"/></svg><a href="<?= escape(analyzer_url($item['latest_month']) . '&category=' . $item['id'] . '#category-' . $item['id']) ?>"><?= escape($item['name']) ?></a><span class="category-share"><?= number_format($item['amount'] / $dashboard['expenses'] * 100, 1) ?>%</span><strong><?= money($item['amount']) ?></strong></li><?php endforeach; ?></ul>
    <?php else: ?><p class="empty-inline">No purchases yet. Refunds and card payments do not contribute to this chart.</p><?php endif; ?>
    <p class="hint"><?= escape(month_label(array_key_first($history))) ?>–<?= escape(month_label($latest)) ?> · All imported purchases before refunds.</p>
    <p class="hint">Select a category to review and edit its merchants in the latest month containing purchases in that category.</p>
</section>
</div>
<section aria-labelledby="movers-heading">
    <div class="section-heading"><div><div class="eyebrow">WHAT CHANGED</div><h2 id="movers-heading">Category shifts</h2><p class="hint"><?= escape(month_label($latest)) ?><?= $latest === gmdate('Y-m') ? ' so far' : '' ?> vs <?= escape(month_label(previous_month($latest))) ?> · Same imported data as your monthly report.</p></div><a href="<?= escape(analyzer_url($latest) . '#comparison-heading') ?>">Compare all categories ↗</a></div>
    <?php if ($dashboard['movers']): ?><div class="mover-grid">
    <?php foreach ($dashboard['movers'] as $item): $max = max($item['current'], $item['previous'], 1); ?>
    <article class="panel mover-card"><div class="mover-top"><h3><?= escape($item['name']) ?></h3><span class="pill <?= $item['delta'] > 0 ? 'change-up' : 'change-down' ?>"><?= $item['delta'] > 0 ? '↑ Increased' : '↓ Decreased' ?></span></div>
        <strong class="mover-delta <?= $item['delta'] > 0 ? 'change-up' : 'change-down' ?>"><?= signed_money($item['delta']) ?></strong><p class="hint"><?= $item['percent'] === null ? 'New spending in this category' : signed_percent($item['percent']) . ' in purchases' ?></p>
        <div class="mini-bars"><?php foreach (['previous' => previous_month($latest), 'current' => $latest] as $key => $barMonth): ?><div><span><?= escape((new DateTimeImmutable($barMonth . '-01'))->format('M')) ?></span><svg viewBox="0 0 160 14" preserveAspectRatio="none" aria-hidden="true"><rect width="160" height="14" rx="4" fill="#edf1f7"/><rect width="<?= sprintf('%.2F', $item[$key] / $max * 160) ?>" height="14" rx="4" fill="<?= $key === 'previous' ? '#a5b6d6' : '#2855d9' ?>"/></svg><strong><?= money($item[$key]) ?></strong></div><?php endforeach; ?></div>
        <p class="hint">Share of bill: <?= $item['previous_share'] === null ? '—' : number_format($item['previous_share'], 1) . '%' ?> → <?= $item['share'] === null ? '—' : number_format($item['share'], 1) . '%' ?> <strong>(<?= signed_percent($item['share_delta'], ' pp') ?>)</strong></p>
    </article><?php endforeach; ?></div>
    <?php else: ?><div class="panel"><p class="hint"><?= $dashboard['comparison']['available'] ? 'No pronounced category changes in the latest comparison.' : 'Import the previous calendar month to reveal category increases and decreases.' ?></p></div><?php endif; ?>
    <p class="hint">Highlights up to two increases and two decreases: at least $25 in change, plus 20% in spending or 5 percentage points in bill share. New categories qualify at $25. These reflect imported transactions, not necessarily complete statements.</p>
</section>
<?php elseif ($dashboard !== null): ?>
<section class="empty-state"><div class="eyebrow">YOUR OVERVIEW STARTS HERE</div><h2>A few charts. A clearer picture.</h2><p>Upload your first statement to see monthly spending, a cumulative category breakdown, and the changes worth a closer look.</p><a class="button" href="/?page=analyzer#upload">Upload a statement</a></section>
<?php endif; ?>
