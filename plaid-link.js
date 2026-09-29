'use strict';
(() => {
    const root = document.getElementById('plaid-sandbox');
    if (!root) return;
    const connect = document.getElementById('connect-card');
    const refresh = document.getElementById('refresh-plaid');
    const status = document.getElementById('plaid-status');
    let handler = null;
    let busy = false;
    const message = text => { status.textContent = text; };
    const setBusy = value => {
        busy = value;
        if (connect) connect.disabled = value;
        if (refresh) refresh.disabled = value;
    };
    async function api(action, values = {}) {
        const response = await fetch('/?page=plaid&api=' + action, {
            method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams({csrf: root.dataset.csrf, ...values})
        });
        let data;
        try { data = await response.json(); } catch { throw new Error('Your session may have expired. Reload the page and sign in again.'); }
        if (!response.ok || data.error) throw new Error(data.error || 'Request failed. Please try again.');
        return data;
    }
    async function loadTransactions() {
        setBusy(true);
        try {
            for (let attempt = 0; attempt < 7; attempt++) {
                message(attempt ? 'Connected. Plaid is preparing the sample transactions…' : 'Retrieving Sandbox credit-card transactions…');
                const result = await api('transactions');
                if (result.ready) { location.assign('/?page=plaid'); return; }
                if (attempt < 6) await new Promise(resolve => setTimeout(resolve, 5000));
            }
            message('Your Sandbox card is connected. Plaid is still preparing transactions. Use Refresh transactions shortly.');
        } catch (error) { message(error.message); }
        finally { setBusy(false); }
    }
    if (connect) connect.addEventListener('click', async () => {
        if (busy) return;
        setBusy(true);
        message('Opening Plaid Sandbox…');
        try {
            if (!window.Plaid) throw new Error('Plaid Link did not load. Reload the page or check your browser’s content blocker.');
            const result = await api('link-token');
            if (handler) handler.destroy();
            handler = Plaid.create({
                token: result.link_token,
                onSuccess: async publicToken => {
                    message('Saving your Sandbox connection…');
                    try {
                        await api('exchange', {public_token: publicToken});
                        // The server now owns the access token. Never store tokens in browser storage.
                        connect.hidden = true;
                        refresh.hidden = false;
                        await loadTransactions();
                    } catch (error) { message(error.message); setBusy(false); }
                },
                onExit: error => {
                    message(error ? 'Plaid Link could not finish. Please try again.' : 'Connection cancelled. No new card was saved.');
                    setBusy(false);
                }
            });
            handler.open();
        } catch (error) { message(error.message); setBusy(false); }
    });
    refresh.addEventListener('click', () => { if (!busy) loadTransactions(); });
})();
