(() => {
    'use strict';

    const pageName = url => url.pathname.split('/').pop();
    const popupPages = new Set([
        'record_view.php', 'record_form.php', 'record_update.php',
        'record_update_edit.php', 'plenary_number_form.php', 'committee_roster.php',
        'record_attachments_view.php', 'record_attachments_manage.php', 'log_history.php',
        'record_recipients.php',
    ]);
    const adminPages = new Set(['users.php', 'officials.php', 'committees.php', 'terms.php']);
    // Only render pages here, never logout, downloads, or action endpoints.
    const navigationPages = new Set([
        ...popupPages, ...adminPages, 'dashboard.php', 'records.php', 'messengerial.php',
        'reports.php', 'audit_logs.php', 'backup.php', 'division_chief_staff.php',
        'division_chief_assignments.php', 'secretariat_assignments.php',
        'committee_referral_print.php', 'transmittal_print.php', 'for_plenary_print.php',
        'communication_qr_print.php',
    ]);
    const snapshot = form => JSON.stringify(Array.from(form.elements)
        .filter(field => !['hidden', 'submit', 'button', 'reset', 'image'].includes(field.type))
        .map(field => [field.name, field.type, field.value, field.checked,
            field.selectedOptions ? Array.from(field.selectedOptions, option => option.value) : null,
            field.files ? Array.from(field.files, file => [file.name, file.size, file.lastModified]) : null]));
    const initialForms = new WeakMap();
    for (const form of document.forms) initialForms.set(form, snapshot(form));
    const hasUnsavedInput = () => Array.from(document.forms).some(form =>
        initialForms.has(form) && initialForms.get(form) !== snapshot(form));

    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey
            || event.metaKey || event.shiftKey || event.altKey || link.hasAttribute('download')
            || (link.target && link.target !== '_self') || link.matches('.modal-close')) return;

        const destination = new URL(link.href, window.location.href);
        const current = new URL(window.location.href);
        const page = pageName(destination);
        if (destination.origin !== current.origin || !navigationPages.has(page)
            || (destination.pathname === current.pathname && destination.search === current.search)) return;
        const isWindow = popupPages.has(page) || (adminPages.has(page) && destination.searchParams.has('edit'));
        if (!isWindow && !hasUnsavedInput()) return;
        event.preventDefault();
        if (popupPages.has(page)) destination.searchParams.set('popup', '1');

        const dialog = document.createElement('dialog');
        dialog.className = 'system-window';
        const title = link.textContent.trim() || 'Related window';
        dialog.setAttribute('aria-label', title);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn secondary system-window-close';
        close.textContent = 'Close window';
        const frame = document.createElement('iframe');
        frame.title = title;
        frame.src = destination.href;
        let updated = false;
        let submitted = false;
        close.addEventListener('click', () => dialog.close());
        frame.addEventListener('load', () => {
            let child;
            try { child = frame.contentDocument; } catch { return; }
            if (!child) return;
            const loaded = new URL(child.URL);
            // A completed edit can return to a list or record. Show the original
            // document again instead of loading another copy inside the window.
            if (submitted && (loaded.pathname !== destination.pathname
                || (destination.searchParams.has('edit') && !loaded.searchParams.has('edit')))) {
                dialog.close();
                return;
            }
            child.addEventListener('submit', submitEvent => {
                if (!submitEvent.defaultPrevented && submitEvent.target.method.toLowerCase() === 'post') {
                    submitted = true;
                    updated = true;
                }
            });
            child.addEventListener('system-window-updated', () => { updated = true; });
            child.addEventListener('click', childEvent => {
                const childLink = childEvent.target.closest('a[href]');
                if (!childLink || childEvent.defaultPrevented || childEvent.button !== 0
                    || childEvent.ctrlKey || childEvent.metaKey || childEvent.shiftKey || childEvent.altKey) return;
                if (childLink.matches('.modal-close, .record-cancel-action')
                    || /^(Cancel|Close|Back to Record|Back to Committees)$/i.test(childLink.textContent.trim())) {
                    childEvent.preventDefault();
                    childEvent.stopImmediatePropagation();
                    dialog.close();
                }
            }, true);
            child.addEventListener('keydown', childEvent => {
                if (childEvent.key === 'Escape' && !child.querySelector('dialog[open]')) {
                    childEvent.preventDefault();
                    dialog.close();
                }
            });
        });
        dialog.addEventListener('close', () => {
            dialog.remove();
            link.focus();
            if (updated) {
                document.dispatchEvent(new Event('system-window-updated'));
                // Never reload a parent containing a draft or selected files.
                if (!hasUnsavedInput()) window.location.reload();
            }
        });
        dialog.append(close, frame);
        document.body.append(dialog);
        dialog.showModal();
    });
})();
