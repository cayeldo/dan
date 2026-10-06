<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading statement-controls report-heading"><div><div class="eyebrow">YOUR MONTHLY RECORD</div><h1>Statements</h1></div>
<form class="report-filter statement-controls" method="get" action="/">
    <input type="hidden" name="page" value="statements">
    <?php $selectedMonth = $statement['month'] ?? (budget_month_valid($month) ? $month : gmdate('Y-m')); ?>
    <div><label for="statement-month">Statement month</label><select id="statement-month" name="month" required>
        <?php if (!in_array($selectedMonth, $months, true)): ?><option value="<?= escape($selectedMonth) ?>" selected><?= escape(month_label($selectedMonth)) ?></option><?php endif; ?>
        <?php foreach ($months as $option): ?><option value="<?= escape($option) ?>" <?= $option === $selectedMonth ? 'selected' : '' ?>><?= escape(month_label($option)) ?></option><?php endforeach; ?>
    </select></div>
    <div><label for="statement-card">Card</label><select id="statement-card" name="account"><option value="0">All imported cards</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $accountFilter ? 'selected' : '' ?>><?= escape($account['label']) ?></option><?php endforeach; ?></select></div>
    <button class="secondary" type="submit">View statement</button>
</form>
</div>
<div class="statement-controls statement-toolbar"><p class="page-intro">A complete view of your imported activity, ready to keep or share.</p><?php if ($statement): ?><a class="button secondary" href="/?<?= escape(http_build_query(['page' => 'statements', 'month' => $statement['month'], 'account' => $accountFilter, 'download' => 'pdf'])) ?>">Download PDF ↓</a><?php endif; ?></div>
<?php if ($statement): ?><p class="statement-scroll-hint">Swipe across the statement to see all columns.</p><div class="statement-paper-scroll"><?php $pdfMode = false; require __DIR__ . '/statement-document.php'; ?></div><?php endif; ?>
