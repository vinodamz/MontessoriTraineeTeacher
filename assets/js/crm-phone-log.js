/*
 * Fires an audit event when a user clicks the Call or WhatsApp pill on
 * an inquiry. Uses navigator.sendBeacon so the request goes out reliably
 * even as the page navigates to the tel: / wa.me URI.
 *
 * Hooks every link with data-audit-action; looks up the inquiry id from
 * the nearest [data-inquiry-id] ancestor (kanban cards, leads list,
 * detail page all set this on the .phone-actions wrapper).
 */
(function () {
    'use strict';
    if (!('sendBeacon' in navigator)) return; // Old browser — skip silently.

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var input = document.querySelector('input[name="_csrf"]');
        return input ? input.value : '';
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        // Walk up — the click might be on a child of the <a>.
        while (t && t !== document && !t.matches('[data-audit-action]')) {
            t = t.parentElement;
        }
        if (!t || !t.matches('[data-audit-action]')) return;

        var action  = t.getAttribute('data-audit-action');
        var wrapper = t.closest('[data-inquiry-id]');
        var famId   = wrapper ? wrapper.getAttribute('data-inquiry-id') : '';

        var fd = new FormData();
        fd.append('action',   action);
        if (famId) fd.append('family_id', famId);
        var token = csrfToken();
        if (token) fd.append('_csrf', token);

        try { navigator.sendBeacon('/crm/log_action.php', fd); } catch (e) { /* swallow */ }
    }, true); // capture-phase so we run before the browser navigation.
})();
