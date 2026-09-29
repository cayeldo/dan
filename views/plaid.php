<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } $snapshot = $plaidRecord['snapshot'] ?? null; ?>
<div id="plaid-sandbox" data-csrf="<?= escape($_SESSION['csrf']) ?>">
<div class="page-heading"><div><div class="eyebrow">CONNECTED CARDS · SANDBOX</div><h1>Connect a credit card.</h1><p class="page-intro">Try a sample card and bring its transactions into view.</p></div><span class="pill">Test data only</span></div>
<section class="panel plaid-connect" aria-labelledby="connect-heading">
    <div><h2 id="connect-heading"><?= $plaidRecord ? 'Your Sandbox card is connected.' : 'Start with a sample credit card.' ?></h2><p class="muted">This page uses Plaid Sandbox. Choose <strong>First Platypus Bank</strong> and use <code>user_good</code> / <code>pass_good</code> if asked to sign in. Select its credit-card account.</p><p class="hint">Use sample credentials here. The real Fidelity / Elan card connection comes later. These sample transactions are separate from your spending reports.</p></div>
    <div class="actions">
        <?php if (!$plaidRecord): ?><button type="button" id="connect-card" <?= $error ? 'disabled' : '' ?>>Connect Credit Card</button><?php endif; ?>
        <button type="button" id="refresh-plaid" class="secondary" <?= !$plaidRecord ? 'hidden' : '' ?>>Refresh transactions</button>
    </div>
    <p id="plaid-status" role="status" aria-live="polite" class="hint"><?= $plaidRecord && !$snapshot ? 'Connection saved. Refresh transactions to retrieve your sample activity.' : 'Credentials and access tokens stay on the server.' ?></p>
    <noscript><p class="error">Enable JavaScript to open Plaid Link and retrieve transactions.</p></noscript>
</section>
<?php if ($snapshot): ?>
<section class="panel" aria-labelledby="plaid-transactions-heading">
    <div class="section-heading"><div><div class="eyebrow">SAMPLE ACTIVITY</div><h2 id="plaid-transactions-heading">Credit-card transactions</h2><p class="hint"><?= count($snapshot['transactions']) ?> shown · <?= escape($snapshot['start']) ?> through <?= escape($snapshot['end']) ?></p></div><span class="pill">Sandbox</span></div>
    <p class="hint"><?php foreach ($snapshot['accounts'] as $i => $account): ?><?= $i ? ' · ' : '' ?><?= escape($account['name']) ?><?= $account['mask'] ? ' · ' . escape($account['mask']) : '' ?><?php endforeach; ?> · Retrieved <?= escape($snapshot['retrieved_at']) ?></p>
    <p class="hint">Positive amounts are charges; negative amounts are credits or payments. Pending charges can change before they post. Categories below come directly from Plaid.</p>
    <?php if ($snapshot['limited']): ?><p class="notice">Showing the most recent 1,000 transactions from this date range.</p><?php endif; ?>
    <?php if ($snapshot['transactions']): ?><div class="table-scroll plaid-table-wrap"><table><caption class="sr-only">Sample credit-card transactions from Plaid Sandbox</caption><thead><tr><th>Date</th><th>Merchant / description</th><th>Card</th><th class="number">Amount</th><th>Status</th><th>Plaid category</th></tr></thead><tbody>
    <?php foreach ($snapshot['transactions'] as $transaction): ?><tr>
        <td class="nowrap"><?= escape($transaction['date']) ?></td>
        <td><strong><?= escape($transaction['merchant']) ?></strong><?php if ($transaction['description'] !== $transaction['merchant']): ?><small><?= escape($transaction['description']) ?></small><?php endif; ?></td>
        <td><?= escape($transaction['account']) ?></td><td class="number nowrap"><?= number_format($transaction['amount'], 2) ?> <?= escape($transaction['currency']) ?></td>
        <td><span class="pill"><?= $transaction['pending'] ? 'Pending' : 'Posted' ?></span></td>
        <td><?= escape($transaction['category'] ?: 'Not provided') ?><?php if ($transaction['confidence']): ?><small>Confidence: <?= escape($transaction['confidence']) ?></small><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?><p class="empty-inline">No credit-card transactions in this date range yet. Refresh shortly if you just connected.</p><?php endif; ?>
</section>
<?php endif; ?>
</div>
<script nonce="<?= escape($plaidNonce) ?>" src="https://cdn.plaid.com/link/v2/stable/link-initialize.js" defer></script>
<script nonce="<?= escape($plaidNonce) ?>" src="/plaid-link.js" defer></script>
