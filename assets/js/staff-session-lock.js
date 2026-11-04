(() => {
    'use strict';
    const style = document.createElement('style');
    style.textContent = 'html.staff-lock-checking body{visibility:hidden!important}html.staff-lock-checking::before{content:"Checking your session...";position:fixed;inset:0;z-index:2147483647;display:grid;place-items:center;background:#f0fdfa;color:#115e59;font:600 16px system-ui,sans-serif;visibility:visible}';
    document.head.appendChild(style);
    // PHP checks access before rendering a new page. Hiding that validated
    // page again caused a blank flash on every dashboard tab navigation.
    const returnUrl = location.pathname.split('/').pop() + location.search;
    const lockUrl = 'StaffLock.php?return=' + encodeURIComponent(returnUrl);
    let csrf = '', deadline = 0, pendingActivity = false, lastSent = 0, busy = false, navigating = false;
    let checkVersion = 0, checkTimer = null;
    const resetCheck = () => {
        checkVersion += 1;
        clearTimeout(checkTimer);
        checkTimer = null;
        busy = false;
        navigating = false;
    };
    const hide = () => document.documentElement.classList.add('staff-lock-checking');
    const openLock = () => { hide(); if (!navigating) { navigating = true; location.replace(lockUrl); } };
    async function sync(activity = false) {
        if (busy || navigating) return;
        busy = true;
        const version = ++checkVersion;
        // A suspended or stalled request must not leave a restored page hidden.
        checkTimer = setTimeout(() => {
            if (version !== checkVersion) return;
            openLock();
        }, 10000);
        try {
            const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
            if (activity && csrf) {
                options.method = 'POST';
                options.body = new URLSearchParams({ action: 'activity', csrf });
                pendingActivity = false; lastSent = Date.now();
            }
            const response = await fetch('StaffLock.php?api=1', options);
            const state = await response.json();
            if (version !== checkVersion || navigating) return;
            if (!response.ok || !state.authenticated || state.locked) { openLock(); return; }
            if (state.setup_required) { hide(); navigating = true; location.replace(lockUrl + '&settings=1'); return; }
            csrf = state.csrf;
            deadline = Date.now() + Math.max(0, state.timeout - (state.server_time - state.last_activity)) * 1000;
            document.documentElement.classList.remove('staff-lock-checking');
        } catch (_) {
            // A lock cannot be bypassed by going offline or blocking the status request.
            if (version === checkVersion) openLock();
        } finally {
            if (version === checkVersion) {
                clearTimeout(checkTimer);
                checkTimer = null;
                busy = false;
            }
        }
    }
    function activity(event) {
        if (!event.isTrusted || document.hidden || navigating) return;
        if (deadline && Date.now() >= deadline) { hide(); sync(); return; }
        pendingActivity = true;
        if (csrf && !busy && Date.now() - lastSent >= 10000) sync(true);
    }
    ['pointerdown','pointermove','keydown','wheel','touchstart'].forEach(type =>
        document.addEventListener(type, activity, { passive: true, capture: true }));
    setInterval(() => {
        if (deadline && Date.now() >= deadline) { hide(); sync(); return; }
        if (!document.hidden) sync(pendingActivity);
    }, 1000 * 15);
    setInterval(() => { if (deadline && Date.now() >= deadline) { hide(); sync(); } }, 1000);
    window.addEventListener('pagehide', resetCheck);
    window.addEventListener('pageshow', event => {
        // A back/forward-cache restore can display an old, unlocked snapshot.
        if (event.persisted) { resetCheck(); hide(); sync(); }
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            // Suspended requests may never settle after an app switch. Start a
            // fresh check and ignore their eventual responses.
            resetCheck();
            if (!deadline || Date.now() >= deadline) hide();
            sync();
        }
    });
    // Also catches protected fetch requests returning a server-side lock response.
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
        const response = await originalFetch(...args);
        if (response.status === 423) openLock();
        return response;
    };
    sync();
})();
