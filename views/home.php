<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<section class="welcome">
    <div class="eyebrow">YOUR PORTAL</div>
    <h1>Welcome back, <?= escape($displayName) ?>.</h1>
    <p class="page-intro">A little clarity for your everyday finances.</p>
    <div class="welcome-caption">Your uploads and categories are private to your account.</div>
</section>
<section class="module-section" aria-labelledby="modules-heading">
    <div class="section-heading"><h2 id="modules-heading">Your tools</h2><span class="muted">Start with a statement.</span></div>
    <a class="module-card" href="/?page=analyzer">
        <div class="module-icon" aria-hidden="true"><svg viewBox="0 0 32 32" width="36" height="36"><rect x="3" y="6" width="26" height="20" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 13h26M8 21h7" stroke="currentColor" stroke-width="2"/></svg></div>
        <div><span class="eyebrow">SPENDING</span><h2>Credit card analyzer</h2><p>Turn a CSV statement into clear merchant totals and a monthly spending breakdown.</p><span class="module-link">Open analyzer <span aria-hidden="true">↗</span></span></div>
    </a>
</section>
