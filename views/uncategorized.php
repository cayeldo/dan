<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading"><div><div class="eyebrow">GIVE EVERY PURCHASE A HOME</div><h1>Uncategorized expenses</h1><p class="page-intro">All months, in one place. Pick a category once per merchant to update every matching purchase.</p></div></div>
<?php if ($uncategorized): ?>
<section class="panel uncategorized-panel" aria-labelledby="uncategorized-heading">
    <div class="section-heading"><h2 id="uncategorized-heading"><?= $uncategorized['merchant_count'] ?> merchant<?= $uncategorized['merchant_count'] === 1 ? '' : 's' ?> to sort</h2><span class="pill"><?= $uncategorized['purchase_count'] ?> purchases · <?= money($uncategorized['total_cents']) ?></span></div>
    <?php if (!$uncategorized['merchants']): ?><div class="empty-inline"><strong>All sorted ✓</strong><p>No uncategorized purchases right now.</p></div><?php endif; ?>
    <?php foreach ($uncategorized['merchants'] as $merchant): $id = (int) $merchant['id']; ?>
    <article class="uncategorized-merchant" id="uncategorized-<?= $id ?>">
        <div class="section-heading"><div><h3><?= escape($merchant['name']) ?></h3><span class="hint"><?= (int) $merchant['purchases'] ?> purchases · <?= escape($merchant['first_date']) ?>–<?= escape($merchant['last_date']) ?></span></div><strong><?= money((int) $merchant['amount']) ?></strong></div>
        <form class="uncategorized-form" method="post" action="/?page=uncategorized&amp;list_page=<?= $uncategorized['page'] ?>">
            <?php csrf_field(); ?><input type="hidden" name="action" value="categorize_uncategorized"><input type="hidden" name="merchant_id" value="<?= $id ?>">
            <div><label for="sort-category-<?= $id ?>">Category</label><select name="category_id" id="sort-category-<?= $id ?>"><option value="0">Choose a category</option><?php foreach ($categoryChoices as $category): ?><option value="<?= (int) $category['id'] ?>"><?= escape($category['name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="sort-custom-<?= $id ?>">Or create a category</label><input id="sort-custom-<?= $id ?>" name="custom_category" maxlength="80" placeholder="New category"></div>
            <button type="submit">Save category</button>
        </form>
        <details class="uncategorized-purchases"><summary>View purchases (<?= (int) $merchant['purchases'] ?>)</summary><div class="table-scroll"><table><thead><tr><th>Date</th><th>Purchase</th><th>Card</th><th class="number">Amount</th></tr></thead><tbody><?php foreach ($uncategorized['transactions'][$id] ?? [] as $transaction): ?><tr><td><?= escape($transaction['transaction_date']) ?></td><td><?= escape($transaction['description']) ?></td><td><?= escape($transaction['account']) ?></td><td class="number"><?= money(abs((int) $transaction['amount_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
    </article>
    <?php endforeach; ?>
    <?php if ($uncategorized['pages'] > 1): ?><nav class="uncategorized-pagination" aria-label="Uncategorized merchant pages"><?php if ($uncategorized['page'] > 1): ?><a href="/?page=uncategorized&amp;list_page=<?= $uncategorized['page'] - 1 ?>">← Previous</a><?php endif; ?><span>Page <?= $uncategorized['page'] ?> of <?= $uncategorized['pages'] ?></span><?php if ($uncategorized['page'] < $uncategorized['pages']): ?><a href="/?page=uncategorized&amp;list_page=<?= $uncategorized['page'] + 1 ?>">Next →</a><?php endif; ?></nav><?php endif; ?>
</section>
<?php endif; ?>
