<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
$series = $dashboard['series'];
$peak = max(100, ...array_values(array_filter($series, fn($v) => $v !== null)));
$points = []; $segments = []; $segment = []; $i = 0; $size = count($series);
foreach ($series as $key => $value) {
    $x = $size === 1 ? 325 : 76 + $i / ($size - 1) * 494;
    if ($value === null) {
        if ($segment) { $segments[] = $segment; $segment = []; }
    } else {
        $y = 208 - $value / $peak * 160;
        $points[$key] = ['x' => $x, 'y' => $y, 'value' => $value];
        $segment[] = sprintf('%.2F,%.2F', $x, $y);
    }
    $i++;
}
if ($segment) { $segments[] = $segment; }
?>
<svg class="spending-line" viewBox="0 0 600 270" role="group" aria-labelledby="line-title line-desc">
<title id="line-title">Monthly purchases over time</title><desc id="line-desc"><?= escape(implode('; ', array_map(fn($key, $value) => month_label($key) . ': ' . ($value === null ? 'no uploaded data' : money($value)), array_keys($series), array_values($series)))) ?>. Points link to monthly reports.</desc>
<?php for ($tick = 0; $tick <= 2; $tick++): $y = 208 - $tick * 80; ?><line x1="76" x2="570" y1="<?= $y ?>" y2="<?= $y ?>" stroke="#e1e7f0"/><text x="64" y="<?= $y + 4 ?>" text-anchor="end" class="axis-label"><?= '$' . ($peak * $tick / 2 >= 100000 ? number_format($peak * $tick / 200000, 1) . 'k' : number_format($peak * $tick / 200, 0)) ?></text><?php endfor; ?>
<?php foreach ($segments as $segment): if (count($segment) > 1): ?><polyline points="<?= implode(' ', $segment) ?>" fill="none" stroke="#2855d9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><?php endif; endforeach; ?>
<?php foreach ($points as $key => $point): $tip = month_label($key) . ($key === gmdate('Y-m') ? ' · so far' : '') . "\n" . money($point['value']) . ' in purchases'; ?><a class="trend-point" href="<?= escape(analyzer_url($key)) ?>" data-tooltip="<?= escape($tip) ?>" aria-label="<?= escape($tip . '. Open report') ?>"><rect x="<?= sprintf('%.2F', max(64, $point['x'] - ($size > 1 ? 247 / ($size - 1) : 32))) ?>" y="35" width="<?= sprintf('%.2F', min(582, $point['x'] + ($size > 1 ? 247 / ($size - 1) : 32)) - max(64, $point['x'] - ($size > 1 ? 247 / ($size - 1) : 32))) ?>" height="185" fill="transparent"/><circle cx="<?= sprintf('%.2F', $point['x']) ?>" cy="<?= sprintf('%.2F', $point['y']) ?>" r="5" fill="#2855d9" stroke="white" stroke-width="2"/></a><?php endforeach; ?>
<?php $i = 0; foreach ($series as $key => $value): $x = $size === 1 ? 325 : 76 + $i / ($size - 1) * 494; if ($size <= 6 || $i % 2 === 0 || $i === $size - 1): ?><text x="<?= sprintf('%.2F', $x) ?>" y="236" text-anchor="middle" class="axis-label"><?= escape((new DateTimeImmutable($key . '-01'))->format('M')) ?></text><text x="<?= sprintf('%.2F', $x) ?>" y="253" text-anchor="middle" class="axis-year"><?= substr($key, 0, 4) ?></text><?php endif; $i++; endforeach; ?>
</svg>
