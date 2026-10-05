<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<nav class="setup-nav" aria-label="Setup navigation">
<?php foreach (['setup' => 'Setup overview', 'budget' => 'Budget', 'connect' => 'Connect card', 'imports' => 'Imports & AI reviews'] as $destination => $label): ?>
<a href="/?page=<?= $destination ?>" <?= $page === $destination ? 'aria-current="page"' : '' ?>><?= escape($label) ?></a>
<?php endforeach; ?>
<?php if ($isAdmin): ?><a href="/?page=admin" <?= $page === 'admin' ? 'aria-current="page"' : '' ?>>User management</a><?php endif; ?>
</nav>
