<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($accounts): ?>
<details class="panel compact-disclosure" id="complete-months" <?= ($_GET['reviews'] ?? '') === '1' ? 'open' : '' ?>><summary>Monthly AI reviews <span class="muted">Confirm complete months</span></summary>
    <div class="completion-content">
        <p>Once all posted transactions are imported, confirm the month here. We’ll prepare an upbeat spending review and save it for future visits.</p>
        <p class="hint">A past date alone doesn’t prove your history is complete. Confirmed months are compared with up to six earlier complete months. Only monthly totals and category totals go to OpenAI.</p>
        <form method="post" action="/?page=imports" class="completion-form"><?php csrf_field(); ?><input type="hidden" name="action" value="confirm_months">
            <div><label for="complete-from">From month</label><input id="complete-from" type="month" name="complete_from" max="<?= escape(previous_month(gmdate('Y-m'))) ?>" required></div>
            <div><label for="complete-through">Through month</label><input id="complete-through" type="month" name="complete_through" max="<?= escape(previous_month(gmdate('Y-m'))) ?>" required></div>
            <label class="coverage-confirm"><input type="checkbox" name="coverage_confirmed" value="1" required> All posted transactions for every card in this app are imported for these months, including any months with no activity.</label>
            <button type="submit">Confirm complete months</button>
        </form>
        <?php if ($closedMonths): ?><div class="table-scroll"><table><thead><tr><th>Month</th><th>Data</th><th>Review</th><th></th></tr></thead><tbody><?php foreach ($closedMonths as $closed): ?><tr><th scope="row"><a href="<?= escape(analyzer_url($closed['month']) . '#monthly-review') ?>"><?= escape(month_label($closed['month'])) ?></a></th><td><?= $closed['complete'] ? 'Complete' : 'Needs confirmation' ?></td><td><?= !$closed['complete'] ? 'Hidden' : escape(match ($closed['review_status']) { 'completed' => 'Saved', 'failed' => 'Retry available', 'processing' => 'Preparing', default => 'Queued' }) ?></td><td><?php if ($closed['complete']): ?><form method="post" action="/?page=imports"><?php csrf_field(); ?><input type="hidden" name="action" value="reopen_month"><input type="hidden" name="review_month" value="<?= escape($closed['month']) ?>"><button class="text-button" type="submit">Mark incomplete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <p class="hint">Incomplete months have no AI review. Adding or correcting transactions requires confirmation again. Existing saved reviews are kept, so confirming again does not pay for a replacement.</p>
    </div>
</details>
<?php endif; ?>
