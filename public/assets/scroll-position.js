(() => {
    'use strict';

    // Give each embedded window its own position, separate from the main page.
    const surface = window === window.top ? 'main' : 'frame:' + (window.frameElement?.title || window.location.pathname);
    const key = 'sp-records-scroll:' + surface;
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(key) || 'null');
        sessionStorage.removeItem(key);
    } catch { /* Navigation still works if browser storage is unavailable. */ }
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

    const capture = () => ({
        time: Date.now(), x: window.scrollX, y: window.scrollY,
        sidebar: document.querySelector('.sidebar')?.scrollTop || 0,
        tables: Array.from(document.querySelectorAll('.table-wrap'), table => table.scrollLeft),
    });
    const save = () => {
        try { sessionStorage.setItem(key, JSON.stringify(capture())); } catch { /* Optional storage. */ }
    };
    const restore = () => {
        if (!saved || Date.now() - saved.time > 60000) return;
        // An explicit section link must reach its target, including edit forms
        // opened from a table lower on the same page.
        if (!window.location.hash) window.scrollTo({left: saved.x, top: saved.y, behavior: 'instant'});
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) sidebar.scrollTop = saved.sidebar;
        document.querySelectorAll('.table-wrap').forEach((table, index) => {
            table.scrollLeft = saved.tables?.[index] || 0;
        });
    };
    document.addEventListener('DOMContentLoaded', restore, {once: true});
    window.addEventListener('load', () => {
        restore();
        requestAnimationFrame(restore);
    }, {once: true});
    window.addEventListener('pagehide', save);
    window.addEventListener('beforeunload', save);
    document.addEventListener('submit', save, true);
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const destination = new URL(link.href, window.location.href);
        if (destination.origin === window.location.origin && !destination.hash) save();
    }, true);
})();
