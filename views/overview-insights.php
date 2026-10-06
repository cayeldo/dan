<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($overviewInsights && $overviewInsights['rows']): ?>
<section class="panel overview-insights" aria-labelledby="insights-heading">
    <div class="section-heading"><div><h2 id="insights-heading">Worth a look</h2><p class="hint"><?= escape($overviewInsights['caption']) ?></p></div></div>
    <ul class="insight-list">
    <?php foreach ($overviewInsights['rows'] as $insight): ?>
        <li><a class="insight-row insight-<?= escape($insight['kind']) ?>" href="<?= escape($insight['url']) ?>">
            <span class="insight-icon" aria-hidden="true"><?= ['attention' => '!', 'pattern' => '↻', 'positive' => '✓', 'neutral' => '·'][$insight['kind']] ?></span>
            <span class="insight-copy"><span class="insight-label"><?= escape($insight['label']) ?></span><strong><?= escape($insight['title']) ?></strong><span class="insight-detail"><?= escape($insight['detail']) ?></span></span>
            <span class="insight-arrow" aria-hidden="true">↗</span>
        </a></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
