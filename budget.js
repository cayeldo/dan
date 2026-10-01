/* All targets are entered by the user. This script only explains and totals them. */
(() => {
    'use strict';
    let active = null;
    let pinned = false;
    const hide = () => {
        if (active) {
            active.querySelector('.budget-popover').hidden = true;
            const button = active.querySelector('[data-budget-info]');
            button.setAttribute('aria-expanded', 'false');
            button.removeAttribute('aria-describedby');
        }
        active = null;
        pinned = false;
    };
    const show = help => {
        if (active !== help) { hide(); active = help; }
        const button = help.querySelector('[data-budget-info]');
        const popover = help.querySelector('.budget-popover');
        popover.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        button.setAttribute('aria-describedby', popover.id);
        const rect = button.getBoundingClientRect();
        popover.style.left = `${Math.max(12, Math.min(rect.left, innerWidth - popover.offsetWidth - 12))}px`;
        const below = rect.bottom + 8;
        popover.style.top = `${Math.max(12, below + popover.offsetHeight < innerHeight - 12 ? below : rect.top - popover.offsetHeight - 8)}px`;
    };
    document.querySelectorAll('.budget-help').forEach(help => {
        const button = help.querySelector('[data-budget-info]');
        help.addEventListener('pointerenter', () => show(help));
        help.addEventListener('pointerleave', () => { if (active === help && !pinned && !help.contains(document.activeElement)) hide(); });
        button.addEventListener('focus', () => show(help));
        button.addEventListener('click', () => {
            if (active === help && pinned) hide();
            else { show(help); pinned = true; }
        });
        help.addEventListener('focusout', event => { if (!help.contains(event.relatedTarget)) hide(); });
    });
    document.addEventListener('click', event => { if (active && !active.contains(event.target)) hide(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    window.addEventListener('resize', hide);
    window.addEventListener('scroll', event => { if (active && !active.contains(event.target)) hide(); }, true);

    const form = document.querySelector('[data-budget-form]');
    if (!form) return;
    const output = form.querySelector('[data-budget-total]');
    const remaining = form.querySelector('[data-budget-remaining]');
    const fields = [...form.querySelectorAll('[data-budget-target]')];
    const update = () => {
        let cents = 0;
        let missing = 0;
        fields.forEach(field => {
            const raw = field.value.trim();
            const valid = /^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/.test(raw);
            const value = Number(raw.replaceAll(',', ''));
            if (!valid || value > 9999999.99) missing++;
            else cents += Math.round(value * 100);
        });
        output.textContent = new Intl.NumberFormat('en-US', {style:'currency', currency:'USD'}).format(cents / 100);
        remaining.textContent = missing ? `${missing} target${missing === 1 ? '' : 's'} left to enter.` : 'All targets entered.';
    };
    form.addEventListener('input', update);
    update();
})();
