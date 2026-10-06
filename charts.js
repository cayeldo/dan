/* Progressive enhancement: all chart links and disclosure controls work without JS. */
(() => {
    'use strict';
    const tooltip = document.createElement('div');
    tooltip.className = 'chart-tooltip';
    tooltip.id = 'chart-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.append(tooltip);
    let active = null;
    let priorDescription = null;
    let pinned = false;
    let hideTimer = null;
    const hide = () => {
        clearTimeout(hideTimer);
        pinned = false;
        tooltip.hidden = true;
        if (active) {
            if (priorDescription === null) active.removeAttribute('aria-describedby');
            else active.setAttribute('aria-describedby', priorDescription);
        }
        active = null;
    };
    const position = (x, y) => {
        const width = tooltip.offsetWidth;
        const height = tooltip.offsetHeight;
        tooltip.style.left = `${Math.max(8, Math.min(x + 14, window.innerWidth - width - 8))}px`;
        tooltip.style.top = `${Math.max(8, y + height + 22 > window.innerHeight ? y - height - 12 : y + 16)}px`;
    };
    const show = (target, x, y) => {
        clearTimeout(hideTimer);
        if (active !== target) {
            hide();
            active = target;
            priorDescription = target.getAttribute('aria-describedby');
            target.setAttribute('aria-describedby', [priorDescription, tooltip.id].filter(Boolean).join(' '));
            tooltip.textContent = target.dataset.tooltip;
        }
        tooltip.hidden = false;
        position(x, y);
    };
    document.addEventListener('pointerover', event => {
        if (tooltip.contains(event.target)) { clearTimeout(hideTimer); return; }
        const target = event.target.closest('[data-tooltip]');
        if (target) show(target, event.clientX, event.clientY);
    });
    document.addEventListener('pointermove', event => {
        if (active && event.target.closest('[data-tooltip]') === active) position(event.clientX, event.clientY);
    });
    document.addEventListener('pointerout', event => {
        if (active && !pinned && !active.contains(event.relatedTarget) && !tooltip.contains(event.relatedTarget)) {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(hide, 180);
        }
    });
    document.addEventListener('focusin', event => {
        const target = event.target.closest('[data-tooltip]');
        if (target) {
            const rect = target.getBoundingClientRect();
            show(target, rect.left + rect.width / 2, rect.bottom);
        }
    });
    document.addEventListener('focusout', hide);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    document.addEventListener('click', event => {
        const target = event.target.closest('[data-tooltip-toggle]');
        if (target) {
            if (active === target && pinned) { hide(); return; }
            const rect = target.getBoundingClientRect();
            show(target, rect.left + rect.width / 2, rect.bottom);
            pinned = true;
        } else if (!tooltip.contains(event.target)) { hide(); }
    });
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
