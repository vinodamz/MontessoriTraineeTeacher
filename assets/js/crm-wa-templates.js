/*
 * WhatsApp template picker for the admissions module.
 *
 * Intercepts clicks on .phone-btn-wa when at least one template is
 * available, shows a small modal with the active templates substituted
 * for this inquiry, opens wa.me?text=… with the picked message.
 *
 * Template data is rendered by the server into <script id="wa-templates">
 * as JSON. Per-inquiry substitution variables (parent name, child name)
 * sit on the .phone-actions wrapper as data attributes.
 */
(function () {
    'use strict';

    var rawTpl = document.getElementById('wa-templates');
    if (!rawTpl) return;
    var templates;
    try { templates = JSON.parse(rawTpl.textContent || '[]'); }
    catch (e) { templates = []; }
    if (!Array.isArray(templates) || templates.length === 0) return; // no templates → keep default direct nav

    var schoolName = (document.querySelector('meta[name="school-name"]') || {}).content || 'our school';

    function substitute(body, vars) {
        return body
            .replace(/\{parent_name\}/g, vars.parent_name || 'there')
            .replace(/\{child_name\}/g,  vars.child_name  || 'your child')
            .replace(/\{school_name\}/g, vars.school_name || schoolName)
            .replace(/\{stage\}/g,       vars.stage       || '')
            .replace(/\{date\}/g,        new Date().toLocaleDateString());
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function logUsage(familyId, templateId, templateName, phone) {
        if (!('sendBeacon' in navigator)) return;
        var fd = new FormData();
        fd.append('action', 'whatsapp_initiated');
        if (familyId) fd.append('family_id', familyId);
        var meta = {};
        if (phone)        meta.phone         = phone;
        if (templateName) meta.template      = templateName;
        if (templateId)   meta.template_id   = templateId;
        if (Object.keys(meta).length) fd.append('meta', JSON.stringify(meta));
        var token = csrfToken();
        if (token) fd.append('_csrf', token);
        try { navigator.sendBeacon('/crm/log_action.php', fd); } catch (e) {}
    }

    function openWa(phone, text) {
        var base = 'https://wa.me/' + encodeURIComponent(phone);
        if (text) base += '?text=' + encodeURIComponent(text);
        // Use _blank so the kanban page stays put (mobile UX).
        var w = window.open(base, '_blank');
        if (!w) window.location.href = base; // popup blocked → same tab
    }

    // ---- Modal ----------
    var modal;
    function ensureModal() {
        if (modal) return modal;
        modal = document.createElement('div');
        modal.className = 'wa-modal-backdrop';
        modal.setAttribute('hidden', '');
        modal.innerHTML = ''
            + '<div class="wa-modal" role="dialog" aria-modal="true" aria-labelledby="wa-modal-title">'
            +   '<header class="wa-modal-head">'
            +     '<h3 id="wa-modal-title">Send WhatsApp</h3>'
            +     '<button class="wa-modal-close" aria-label="Close">×</button>'
            +   '</header>'
            +   '<p class="muted small wa-modal-sub"></p>'
            +   '<ul class="wa-template-list" role="list"></ul>'
            +   '<button class="btn wa-blank">Open blank chat</button>'
            + '</div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        modal.querySelector('.wa-modal-close').addEventListener('click', closeModal);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal();
        });
        return modal;
    }

    function closeModal() {
        if (modal) modal.setAttribute('hidden', '');
    }

    function openModal(opts) {
        var m = ensureModal();
        m.querySelector('.wa-modal-sub').textContent = 'To ' + (opts.parent_name || 'this contact') + ' · +' + opts.phone;

        var list = m.querySelector('.wa-template-list');
        list.innerHTML = '';
        templates.forEach(function (t) {
            var body = substitute(t.body, opts);
            var li = document.createElement('li');
            li.innerHTML =
                '<button class="wa-template-row" type="button">'
              +   '<span class="wa-template-name"></span>'
              +   '<span class="wa-template-preview"></span>'
              + '</button>';
            li.querySelector('.wa-template-name').textContent = t.name;
            li.querySelector('.wa-template-preview').textContent = body;
            li.querySelector('button').addEventListener('click', function () {
                logUsage(opts.family_id, t.id, t.name, opts.phone);
                closeModal();
                openWa(opts.phone, body);
            });
            list.appendChild(li);
        });

        var blank = m.querySelector('.wa-blank');
        blank.onclick = function () {
            logUsage(opts.family_id, null, null, opts.phone);
            closeModal();
            openWa(opts.phone, '');
        };

        m.removeAttribute('hidden');
    }

    // ---- Click interception ----------
    // Run in capture phase so we beat the audit-log script that also
    // hooks the click. We preventDefault so the browser doesn't follow
    // the wa.me href directly — the modal handles navigation instead.
    document.addEventListener('click', function (ev) {
        var t = ev.target;
        while (t && t !== document && !t.matches('.phone-btn-wa')) {
            t = t.parentElement;
        }
        if (!t || !t.matches('.phone-btn-wa')) return;

        var wrapper = t.closest('.phone-actions');
        var familyId = wrapper ? wrapper.getAttribute('data-inquiry-id') : '';
        var phone    = t.getAttribute('data-wa-phone') || '';
        if (!phone) {
            // Fallback — extract from the wa.me href.
            var href = t.getAttribute('href') || '';
            phone = href.replace(/^.*wa\.me\//, '').split(/[?#]/)[0];
        }
        if (!phone) return; // shouldn't happen, but bail safely

        ev.preventDefault();
        // stopImmediatePropagation also stops the audit-log script's own
        // capture-phase listener from firing a duplicate
        // 'whatsapp_initiated' beacon. The picker calls logUsage() itself
        // after the user picks a template (or chooses blank chat).
        ev.stopImmediatePropagation();

        openModal({
            family_id:   familyId,
            phone:       phone,
            parent_name: (wrapper && wrapper.getAttribute('data-wa-parent')) || '',
            child_name:  (wrapper && wrapper.getAttribute('data-wa-child'))  || '',
            stage:       (wrapper && wrapper.getAttribute('data-wa-stage'))  || '',
        });
    }, true);
})();
