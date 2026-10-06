<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($accounts): ?>
<details class="panel compact-disclosure" id="complete-months" <?= ($_GET['reviews'] ?? '') === '1' ? 'open' : '' ?>><summary>Monthly AI reviews <span class="muted">Automatic on the 5th</span></summary>
    <div class="completion-content">
        <p>On the 5th of each month (Eastern time), we automatically prepare a review of the previous month’s imported transactions. No monthly confirmation is needed. Later imports can change the picture.</p>
        <p class="hint">You can also confirm complete months below to request an earlier review or review older history. Confirmed months provide a reliable comparison baseline. AI reviews use monthly and category totals, plus merchant names, purchase counts, and totals. Raw transaction details are not sent.</p>
        <form method="post" action="/?page=imports" class="completion-form"><?php csrf_field(); ?><input type="hidden" name="action" value="confirm_months">
            <div><label for="complete-from">From month</label><input id="complete-from" type="month" name="complete_from" max="<?= escape(previous_month(gmdate('Y-m'))) ?>" required></div>
            <div><label for="complete-through">Through month</label><input id="complete-through" type="month" name="complete_through" max="<?= escape(previous_month(gmdate('Y-m'))) ?>" required></div>
            <label class="coverage-confirm"><input type="checkbox" name="coverage_confirmed" value="1" required> All posted transactions for every card in this app are imported for these months, including any months with no activity.</label>
            <button type="submit">Confirm complete months</button>
        </form>
        <?php if ($closedMonths): ?><div class="table-scroll"><table><thead><tr><th>Month</th><th>Data</th><th>Review</th><th></th></tr></thead><tbody><?php foreach ($closedMonths as $closed): ?><tr><th scope="row"><a href="<?= escape(analyzer_url($closed['month']) . '#monthly-review') ?>"><?= escape(month_label($closed['month'])) ?></a></th><td><?= $closed['complete'] ? ($closed['source'] === 'scheduled_snapshot' ? 'Imported snapshot' : 'Complete') : 'Needs confirmation' ?></td><td><?= !$closed['complete'] ? 'Hidden' : escape(match ($closed['review_status']) { 'completed' => 'Saved', 'failed' => 'Retry available', 'processing' => 'Preparing', default => 'Queued' }) ?></td><td><?php if ($closed['complete']): ?><form method="post" action="/?page=imports"><?php csrf_field(); ?><input type="hidden" name="action" value="reopen_month"><input type="hidden" name="review_month" value="<?= escape($closed['month']) ?>"><button class="text-button" type="submit">Mark incomplete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <p class="hint">Marking a month incomplete hides its review and prevents automatic scheduling for that month. Changes to a confirmed month require confirmation again. Automatic snapshots stay visible with a changed-data notice if transactions change. Saved reviews are kept; they are not automatically regenerated.</p>
    </div>
</details>
<?php endif; ?>
