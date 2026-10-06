<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<div class="page-heading"><div><div class="eyebrow">MAKE IT YOURS</div><h1>Admin / Setup</h1><p class="page-intro">Manage your spending plan, connected cards, and imported history.</p></div></div>
<div class="setup-grid">
<a class="panel setup-card" href="/?page=budget"><h2>Budget</h2><p>Set your category targets. Your plan carries forward until you change it.</p><span>Manage targets ↗</span></a>
<a class="panel setup-card" href="/?page=connect"><h2>Connect card</h2><p>Connect SimpleFIN, check synchronization, or manage automatic imports.</p><span>Manage connection ↗</span></a>
<a class="panel setup-card" href="/?page=imports"><h2>Imports &amp; AI reviews</h2><p>Upload CSV files, see recent imports, and confirm complete months for your saved AI reviews.</p><span>Manage imports ↗</span></a>
<a class="panel setup-card" href="/?page=uncategorized"><h2>Uncategorized expenses</h2><p>Sort uncategorized purchases from every month. Choose once per merchant.</p><span>Sort expenses ↗</span></a>
<?php if ($isAdmin): ?><a class="panel setup-card" href="/?page=admin"><div class="eyebrow">ADMINISTRATOR ONLY</div><h2>User management</h2><p>Add family members and generate their one-time setup codes.</p><span>Manage users ↗</span></a><?php endif; ?>
</div>
