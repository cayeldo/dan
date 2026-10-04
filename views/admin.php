<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading"><div><div class="eyebrow">ADMINISTRATION</div><h1>Users &amp; setup codes.</h1><p class="page-intro">Give your family access to their own spending reports.</p></div></div>
<?php if ($isAdmin): ?>
<?php if ($issuedSetup): ?>
<section class="panel admin-code" role="status" aria-labelledby="setup-code-heading"><h2 id="setup-code-heading">Setup code for <?= escape($issuedSetup['username']) ?></h2>
<p>Share this code privately with <?= escape($issuedSetup['username']) ?>. They can <a href="/?mode=setup" target="_blank" rel="noopener noreferrer">open the password setup page</a> in their own signed-out browser, enter their username and this code, and choose their password.</p>
<label for="issued-code">One-time setup code</label><input id="issued-code" value="<?= escape($issuedSetup['code']) ?>" readonly autocomplete="off" spellcheck="false">
<p class="hint">Copy this code now; it is shown only on this visit. It expires <?= escape($issuedSetup['expires']) ?> UTC. Generating another code replaces this one.</p>
<p class="hint">Password setup address: https://klecoin.com/?mode=setup</p></section>
<?php endif; ?>
<section class="panel"><h2>Add a user</h2><p class="hint">Creates a regular user and generates their setup code automatically.</p>
<form method="post" action="/?page=admin" class="admin-create"><?php csrf_field(); ?><input type="hidden" name="action" value="create_user">
<label for="new-username">Username</label><input id="new-username" name="username" placeholder="e.g. emilia" pattern="[A-Za-z0-9_]{1,50}" maxlength="50" autocomplete="off" required>
<button type="submit">Add user &amp; generate code</button></form></section>
<section class="panel"><h2>Users</h2><p class="hint">Setup codes expire after 24 hours and work once. For someone still awaiting setup, generate a replacement whenever needed. Active users keep their existing passwords.</p>
<div class="table-scroll"><table><thead><tr><th>Username</th><th>Access</th><th>Status</th><th>Last sign-in (UTC)</th><th>Setup</th></tr></thead><tbody>
<?php foreach ($adminUsers as $member): ?><tr><td><strong><?= escape($member['username']) ?></strong></td><td><?= $member['is_admin'] ? 'Admin' : 'Member' ?></td><td><?= $member['activated'] ? 'Active' : 'Awaiting password' ?></td><td><?= $member['last_login_at'] ? escape($member['last_login_at']) : 'Not yet' ?></td><td>
<?php if (!$member['activated']): ?><form method="post" action="/?page=admin"><?php csrf_field(); ?><input type="hidden" name="action" value="generate_setup_code"><input type="hidden" name="username" value="<?= escape($member['username']) ?>"><button class="secondary" type="submit">Generate setup code</button></form>
<?php if ($member['setup_expires_at']): ?><p class="hint"><?= strtotime($member['setup_expires_at'] . ' UTC') > time() ? 'Code expires ' : 'Code expired ' ?><?= escape($member['setup_expires_at']) ?> UTC</p><?php endif; ?>
<?php else: ?><span class="hint">Password already created</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
