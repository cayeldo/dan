<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="page-heading"><div><div class="eyebrow">A PLAN FOR YOUR MONTH</div><h1>Monthly budget</h1><p class="page-intro">Set your targets. Make room for what matters.</p></div></section>
<form class="report-filter budget-month-filter" method="get" action="/"><input type="hidden" name="page" value="budget"><div><label for="budget-month-picker">Budget month</label><input type="month" name="month" id="budget-month-picker" value="<?= escape($budgetMonth) ?>" required></div><button type="submit" class="secondary">View budget</button></form>
<?php if ($budget): $budgetHistory = $budget['history']; ?>
<section class="panel budget-panel" aria-labelledby="budget-heading">
    <div class="section-heading"><div><div class="eyebrow"><?= escape(month_label($budget['month'])) ?></div><h2 id="budget-heading">Your monthly targets</h2></div><span class="pill"><?= $budget['revision'] ? 'Saved budget' : 'New budget' ?></span></div>
    <p class="hint">Hover or tap ⓘ for historical averages. Enter your own amount in every field; 0 is welcome.</p>
    <form method="post" action="/?page=budget" class="budget-form" data-budget-form>
        <?php csrf_field(); ?><input type="hidden" name="action" value="save_budget"><input type="hidden" name="budget_month" value="<?= escape($budget['month']) ?>"><input type="hidden" name="budget_version" value="<?= escape(budget_form_version($budget)) ?>">
        <div class="budget-column-head"><span>Category</span><span>Monthly target · USD</span></div>
        <?php foreach ($budget['groups'] as $key => $group):
            $amount = $budgetValues !== null ? ($budgetValues[$key] ?? '') : (isset($budget['targets'][$key]) ? number_format($budget['targets'][$key] / 100, 2, '.', '') : '');
            $amount = is_string($amount) ? $amount : '';
            $average = $budget['averages'][$key]; ?>
        <div class="budget-row">
            <div class="budget-category"><label for="target-<?= escape($key) ?>"><?= escape($group['name']) ?></label>
                <span class="budget-help"><button class="text-button budget-info" type="button" aria-label="<?= escape('Historical average for ' . $group['name']) ?>" aria-expanded="false" aria-controls="budget-info-<?= escape($key) ?>" data-budget-info><span aria-hidden="true">i</span></button>
                    <span class="budget-popover" id="budget-info-<?= escape($key) ?>" role="note" hidden>
                        <strong><?= $average === null ? 'No past-month history yet' : money($average) . ' / month' ?></strong>
                        <?php if ($average !== null): ?><span>Average purchases across <?= $budgetHistory['count'] ?> imported past month<?= $budgetHistory['count'] === 1 ? '' : 's' ?>, <?= escape(month_label($budgetHistory['months'][0])) ?>–<?= escape(month_label($budgetHistory['months'][array_key_last($budgetHistory['months'])])) ?>.</span><span>Use this as a starting point, then choose a target that works for you.</span><?php else: ?><span>Choose a starting amount. Suggestions will appear as you build history.</span><?php endif; ?>
                        <?php if ($key === 'misc'): ?><span>Combined average for categories not itemized above.<?= $budget['misc_categories'] ? ' Includes ' . escape(implode(', ', $budget['misc_categories'])) . '.' : '' ?></span><?php endif; ?>
                    </span>
                </span>
            </div>
            <div class="budget-amount"><span aria-hidden="true">$</span><input type="text" inputmode="decimal" name="targets[<?= escape($key) ?>]" id="target-<?= escape($key) ?>" value="<?= escape($amount) ?>" placeholder="Enter amount" maxlength="16" autocomplete="off" required data-budget-target></div>
        </div>
        <?php endforeach; ?>
        <div class="budget-total"><span>Total monthly target</span><output data-budget-total aria-live="polite"><?= $budget['revision'] && $budgetValues === null ? money(array_sum($budget['targets'])) : 'Enter your targets' ?></output></div>
        <div class="budget-actions"><button type="submit">Save monthly budget</button><?php if ($budget['updated_at']): ?><span class="hint">Last saved <?= escape(substr($budget['updated_at'], 0, 10)) ?></span><?php endif; ?></div>
    </form>
</section>
<details class="data-note"><summary>How categories and suggestions work</summary><p>Categories averaging more than $50 per month get an individual target. The rest share Misc. Averages use all earlier imported months before the budget month, excluding the current month. Months with no activity in a category count as zero when that month has other imported records or is confirmed complete. Missing months are excluded; imported history may be partial. Refunds and card payments are excluded.</p><p>Saved budgets keep their category grouping for that month, so later imports don’t move your targets. Misc also covers new categories that weren’t itemized when you saved. Each month is saved separately.</p></details>
<?php endif; ?>
