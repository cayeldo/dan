<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading"><h1>Imports &amp; monthly reviews</h1><a class="button secondary" href="/?page=connect">Automatic imports</a></div>
<?php require __DIR__ . '/import-preview.php'; ?>
<section class="panel upload-panel" id="upload" aria-labelledby="upload-heading">
    <div><div class="eyebrow">MANUAL IMPORT</div><h2 id="upload-heading">Bring your spending into view.</h2><p class="muted">Use the same card for overlapping statements. Previously imported transactions will be skipped.</p><p class="hint">During automatic imports, CSV duplicates are matched by date, amount, and merchant. If a match is missing or ambiguous, the upload stops for review.</p><p class="hint">Supports Date, Name or Description, and Amount columns. Memo and Transaction columns improve matching. Amounts are in USD.</p></div>
    <form method="post" action="/?page=imports" enctype="multipart/form-data" class="upload-form">
        <?php csrf_field(); ?><input type="hidden" name="action" value="upload"><input type="hidden" name="MAX_FILE_SIZE" value="<?= ANALYZER_MAX_BYTES ?>">
        <?php if ($accounts): ?><label for="upload-account">Card</label><select name="account_id" id="upload-account"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $accountFilter ? 'selected' : '' ?>><?= escape($account['label']) ?></option><?php endforeach; ?><option value="0">Add a different card</option></select><?php else: ?><input type="hidden" name="account_id" value="0"><?php endif; ?>
        <label for="account-label"><?= $accounts ? 'New card name (only when adding a card)' : 'Give this card a name' ?></label><input id="account-label" name="account_label" maxlength="80" placeholder="e.g. My Visa · 9814" <?= $accounts ? '' : 'required' ?>><p class="hint">A nickname or last four digits is enough.</p>
        <label for="charge-sign">How are purchases shown in your CSV?</label><select id="charge-sign" name="charge_sign"><option value="negative">Negative amounts (e.g. −19.80)</option><option value="positive">Positive amounts (e.g. 19.80)</option></select>
        <label for="statement">CSV statement</label><input id="statement" name="statement" type="file" accept=".csv,text/csv" required><p class="hint">Up to 2 MB · 10,000 rows · Nothing is saved until you confirm the preview.</p><p class="hint">Unfamiliar merchant names and category hints are sent to OpenAI for automatic categorization. Merchant categorization does not send amounts, dates, account details, or memos. Monthly reviews use month and category totals plus merchant names, purchase counts, and totals automatically on the 5th for the previous month, or earlier when you confirm a closed month is complete.</p>
        <button type="submit">Preview statement</button>
    </form>
</section>

<?php require __DIR__ . '/complete-months.php'; ?>
<?php require __DIR__ . '/import-history.php'; ?>
