<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
?>
<section class="panel budget-progress" aria-labelledby="budget-progress-heading">
    <div class="section-heading"><div><div class="eyebrow"><?= escape(month_label($month)) ?> · ALL IMPORTED CARDS</div><h2 id="budget-progress-heading">Your budget at a glance</h2></div><a href="/?page=budget&amp;month=<?= escape($month) ?>"><?= $budgetProgress ? 'Edit budget' : 'Set a budget' ?> ↗</a></div>
    <?php if (!$budgetProgress): ?><p class="hint">Set a budget for this month to track your spending against a plan. Your targets will carry forward.</p>
    <?php else: $balance = $budgetProgress['total']; ?>
    <?php if ($accountFilter): ?><p class="hint">This budget includes all your imported cards, even while the spending report below is filtered to one card. Budget details open across all cards.</p><?php endif; ?>
    <div class="budget-progress-summary">
        <div><span class="muted">Budget</span><strong class="budget-figure budget-<?= escape($balance['status']) ?>"><?= money($balance['available_cents']) ?></strong><span><?= money($balance['spent_cents']) ?> spent of <?= money($balance['target_cents']) ?></span><small><?= $balance['status'] === 'over' ? 'Over budget' : ($balance['status'] === 'near' ? '25% or less available' : 'Within budget') ?></small></div>
        <div class="budget-pace"><h3>Spending pace</h3>
            <?php if ($budgetProgress['current']): ?>
                <?php if ($balance['projected_cents'] !== null): ?><strong><?= $balance['projected_cents'] > $balance['target_cents'] ? 'Trending over budget' : 'On track' ?></strong><p class="hint">Estimated month-end spending: <?= money($balance['projected_cents']) ?> · <?= money(abs($balance['target_cents'] - $balance['projected_cents'])) ?> <?= $balance['projected_cents'] > $balance['target_cents'] ? 'over' : 'within' ?> your target.</p>
                <?php else: ?><p class="hint">Not enough recent data for a useful projection yet. Pace appears after the first week with at least three purchases and recent spending data.</p><?php endif; ?>
                <p class="hint"><?= (int) ($budgetProgress['days_in_month'] - $budgetProgress['days_elapsed']) ?> days left after today. Estimates use average daily purchases imported through today; missing transactions and one-time purchases can skew the result.</p>
            <?php else: ?><p class="hint"><?= $budgetProgress['complete'] ? 'Month complete · actual results shown.' : ($month > gmdate('Y-m') ? 'Upcoming month · pace starts when spending data is available.' : 'Month ended · data not confirmed complete. Amounts may change as transactions arrive.') ?></p><?php endif; ?>
        </div>
    </div>
    <div class="budget-track-list">
    <?php foreach ($budgetProgress['groups'] as $key => $item): $percent = $item['target_cents'] > 0 ? min(100, $item['spent_cents'] / $item['target_cents'] * 100) : ($item['spent_cents'] > 0 ? 100 : 0); ?>
        <div class="budget-track-row">
            <div class="budget-track-name"><?php if ($key !== 'misc'): ?><a href="<?= escape(analyzer_url($month) . '&category=' . $item['category_id'] . '#category-' . $item['category_id']) ?>"><?= escape($item['name']) ?></a><?php else: ?><strong>Misc</strong><?php endif; ?><small><?= money($item['spent_cents']) ?> spent of <?= money($item['target_cents']) ?></small></div>
            <div><svg class="budget-meter budget-<?= escape($item['status']) ?>" viewBox="0 0 1000 8" preserveAspectRatio="none" role="img" aria-label="<?= escape($item['name'] . ': ' . money($item['spent_cents']) . ' spent of ' . money($item['target_cents'])) ?>"><rect width="1000" height="8" rx="4" fill="#edf2f0"/><rect width="<?= sprintf('%.2F', $percent * 10) ?>" height="8" rx="4" fill="currentColor"/></svg><?php if ($item['projected_cents'] !== null): ?><small>Pace: <?= money($item['projected_cents']) ?> estimated · <?= $item['projected_cents'] > $item['target_cents'] ? 'over target' : 'within target' ?></small><?php endif; ?></div>
            <div class="budget-track-value"><small>Budget</small><strong class="budget-<?= escape($item['status']) ?>"><?= money($item['available_cents']) ?></strong><small><?= $item['status'] === 'over' ? 'Over budget' : ($item['status'] === 'near' ? '25% or less available' : 'Within budget') ?></small></div>
            <?php if ($key === 'misc'): ?><details class="budget-misc"><summary>What’s in Misc?</summary><?php if (!$item['members']): ?><p class="hint">No purchases yet. Categories without an individual target are included here.</p><?php endif; ?><?php foreach ($item['members'] as $member): ?><p><a href="<?= escape(analyzer_url($month) . '&category=' . $member['id'] . '#category-' . $member['id']) ?>"><?= escape($member['name']) ?></a> · <?= money($member['amount']) ?></p><?php endforeach; ?></details><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <p class="hint">Budget figures show target minus purchases, before refunds; card payments are excluded. Unspent amounts in one category can offset overages elsewhere in the overall budget. Targets are not moved automatically.</p>
    <?php endif; ?>
</section>
