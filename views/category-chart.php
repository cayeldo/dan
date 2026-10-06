<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="category-layout <?= $chartId === 'cumulative' ? 'overview-category-layout' : '' ?>">
    <div class="donut-wrap">
        <svg class="donut" viewBox="0 0 240 240" role="group" aria-labelledby="<?= $chartId ?>-title <?= $chartId ?>-desc">
            <title id="<?= $chartId ?>-title"><?= escape($chartTitle) ?></title>
            <desc id="<?= $chartId ?>-desc">Purchases by category. Hover or focus for values. Select a slice to explore its transactions.</desc>
            <circle cx="120" cy="120" r="90" fill="none" stroke="#edf1f7" stroke-width="24"/>
            <?php $offset = 0; foreach ($chartItems as $item): $share = $item['amount'] / max(1, $chartTotal); $tip = $item['name'] . "\n" . money($item['amount']) . ' · ' . number_format($share * 100, 1) . '%'; ?>
            <a class="chart-slice" href="<?= escape($item['url']) ?>" data-tooltip="<?= escape($tip) ?>" aria-label="<?= escape($tip . '. View transactions') ?>">
                <path d="<?= donut_slice_path($offset, $share) ?>" fill="<?= chart_color(max(0, (int) $item['id'] - 1)) ?>" stroke="white" stroke-width="1.5"/>
            </a>
            <?php $offset += $share; endforeach; ?>
            <text x="120" y="110" text-anchor="middle" class="donut-label">PURCHASES</text>
            <text x="120" y="137" text-anchor="middle" class="donut-total"><?= money($chartTotal) ?></text>
        </svg>
        <?php if ($chartId !== 'monthly'): ?><p class="chart-instruction">Select a slice to explore</p><?php endif; ?>
    </div>
    <ul class="category-legend <?= $chartId === 'cumulative' ? 'overview-legend' : '' ?>">
        <?php foreach ($chartItems as $item): $share = $item['amount'] / max(1, $chartTotal); ?>
        <li><svg width="12" height="12" aria-hidden="true"><circle cx="6" cy="6" r="5" fill="<?= chart_color(max(0, (int) $item['id'] - 1)) ?>"/></svg><a href="<?= escape($item['url']) ?>" data-tooltip="<?= escape($item['name'] . "\n" . money($item['amount']) . ' · ' . number_format($share * 100, 1) . '%') ?>"><?= escape($item['name']) ?></a><span class="category-share"><?= number_format($share * 100, 1) ?>%</span><strong><?= money($item['amount']) ?></strong></li>
        <?php endforeach; ?>
    </ul>
</div>
