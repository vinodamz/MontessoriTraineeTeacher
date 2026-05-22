// Admissions pipeline — drag-and-drop status change (desktop) +
// "Move to" picker on every card (mobile + accessible alternative).
//
// On a touch device the picker is the only way to move cards: Sortable
// is skipped entirely so the tap-to-open-card vs. start-drag race never
// happens. On desktop both interactions work — drag for speed, picker
// for keyboard / single-tap accessibility.
(function () {
    'use strict';

    const board = document.querySelector('.crm-board');
    if (!board) return;

    const csrf       = board.dataset.csrf || '';
    const isCoarse   = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

    function moveCard(card, newStatus, oldStatus, onSnapBack) {
        if (!newStatus || newStatus === oldStatus) return Promise.resolve(false);
        if (newStatus === 'enrolled') {
            if (onSnapBack) onSnapBack();
            alert('To enroll children, open the card and use the "Enroll children" form — it needs a grade and class teacher per child.');
            return Promise.resolve(false);
        }
        const inquiryId = parseInt(card.dataset.inquiryId, 10);
        if (!inquiryId) return Promise.resolve(false);
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('op', 'move');
        fd.append('id', String(inquiryId));
        fd.append('status', newStatus);
        return fetch('/crm/index.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(r => r.json()).then(data => {
            if (!data || !data.ok) {
                console.error('move failed:', data && data.error);
                if (onSnapBack) onSnapBack();
                window.location.reload();
                return false;
            }
            updateColumnCounts();
            return true;
        }).catch(err => {
            console.error('network error:', err);
            if (onSnapBack) onSnapBack();
            window.location.reload();
            return false;
        });
    }

    function updateColumnCounts() {
        board.querySelectorAll('.crm-col').forEach(col => {
            const pill = col.querySelector('.crm-col-head .pill');
            const n    = col.querySelectorAll('.crm-card-li').length;
            if (pill) pill.textContent = String(n);
        });
    }

    // ----- Move-to picker (works on every device) -----
    board.addEventListener('change', async (ev) => {
        const sel = ev.target;
        if (!sel.matches('.crm-card-status-select')) return;
        const newStatus = sel.value;
        if (!newStatus) return;

        const li        = sel.closest('.crm-card-li');
        const fromList  = sel.closest('.crm-col-list');
        const targetList = board.querySelector('.crm-col-list[data-status="' + newStatus + '"]');
        const oldStatus = fromList ? fromList.dataset.status : '';
        if (!li || !targetList) { sel.value = ''; return; }

        sel.disabled = true;
        const ok = await moveCard(li, newStatus, oldStatus, () => { sel.disabled = false; sel.value = ''; });
        if (ok) {
            targetList.appendChild(li);
            // The select now lives in the new column — hide the option for
            // the current column and reveal the option for the previous one
            // so the dropdown reflects "Move to anywhere except here".
            sel.querySelectorAll('option').forEach(o => {
                if (o.value === '')          return; // keep the placeholder
                o.hidden = (o.value === newStatus);
            });
            sel.value = '';
            sel.dataset.current = newStatus;
            sel.disabled = false;
        }
    });

    // ----- Drag-and-drop (desktop only) -----
    if (isCoarse) return; // mobile: picker only
    if (typeof Sortable === 'undefined') {
        console.warn('Sortable.js not loaded — drag-and-drop disabled.');
        return;
    }

    const lists = board.querySelectorAll('.crm-col-list');
    lists.forEach(list => {
        new Sortable(list, {
            group: 'crm-cards',
            animation: 160,
            ghostClass:  'crm-card-ghost',
            chosenClass: 'crm-card-chosen',
            dragClass:   'crm-card-drag',
            forceFallback: false,
            fallbackOnBody: true,
            fallbackTolerance: 8,
            onEnd: async (evt) => {
                const card      = evt.item;
                const newStatus = evt.to.dataset.status;
                const oldStatus = evt.from.dataset.status;
                if (newStatus === oldStatus) return; // intra-column reorder
                const ok = await moveCard(card, newStatus, oldStatus, () => {
                    const anchor = evt.from.children[evt.oldIndex] || null;
                    evt.from.insertBefore(card, anchor);
                });
                if (ok) {
                    // Re-sync the card's stage picker to reflect the new column.
                    const sel = card.querySelector('.crm-card-status-select');
                    if (sel) {
                        sel.querySelectorAll('option').forEach(o => {
                            if (o.value === '') return;
                            o.hidden = (o.value === newStatus);
                        });
                        sel.value = '';
                        sel.dataset.current = newStatus;
                    }
                }
            },
        });
    });
})();
