<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($preview !== null): ?>
<section class="panel preview-panel" aria-labelledby="preview-heading">
    <div class="section-heading"><div><div class="eyebrow">READY TO REVIEW</div><h2 id="preview-heading">Check your import</h2></div><span class="pill">Not saved yet</span></div>
    <p class="muted wrap"><?= escape($pending['filename']) ?> · <?= escape($pending['account_label']) ?></p>
    <div class="preview-stats"><div><strong><?= $preview['added'] ?></strong><span>new transactions</span></div><div><strong><?= $preview['skipped'] ?></strong><span>duplicates to skip</span></div><div><strong><?= money($preview['expenses']) ?></strong><span>new purchases</span></div><div><strong><?= money($preview['refunds']) ?></strong><span>refunds / credits</span></div></div>
    <p class="hint"><?= money($preview['payments']) ?> in card payments is excluded from spending. <?= $preview['months'] ? 'Months: ' . escape(implode(', ', array_map('month_label', array_keys($preview['months'])))) . '.' : '' ?></p>
    <?php if ($preview['fallback'] > 0): ?><p class="hint">Some rows have no bank reference. Matching uses the date, description, amount, and repeated-row count. Identical purchases in separate overlapping files may be indistinguishable; check the duplicate count.</p><?php endif; ?>
    <?php if ($preview['groups']): ?>
    <details class="preview-details"><summary>Review <?= count($preview['groups']) ?> merchant groups and suggested categories</summary>
        <div class="table-scroll"><table><thead><tr><th>Merchant</th><th>Category</th><th class="number">Transactions</th><th class="number">Net spending</th></tr></thead><tbody>
        <?php foreach ($preview['groups'] as $group): ?><tr><td><?= escape($group['name']) ?></td><td><?= escape($group['category']) ?><?= $group['needs_review'] ? ' · Auto-categorize after saving' : '' ?></td><td class="number"><?= $group['count'] ?></td><td class="number"><?= money($group['net']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php endif; ?>
    <div class="actions"><form method="post" action="/?page=statements"><?php csrf_field(); ?><input type="hidden" name="action" value="confirm_import"><input type="hidden" name="pending_token" value="<?= escape($pending['token']) ?>"><button type="submit"><?= $preview['added'] > 0 ? 'Save ' . $preview['added'] . ' transactions' : 'Finish — no new transactions' ?></button></form>
    <form method="post" action="/?page=statements"><?php csrf_field(); ?><input type="hidden" name="action" value="discard_import"><input type="hidden" name="pending_token" value="<?= escape($pending['token']) ?>"><button class="secondary" type="submit">Discard preview</button></form></div>
    <p class="hint">You can correct any merchant or category after saving. Credits are kept separately from purchases.</p>
</section>
<?php endif; ?>

