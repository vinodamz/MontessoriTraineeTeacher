// Admissions pipeline — drag-and-drop status change.
// Each column's <ul.crm-col-list> becomes a Sortable. Drops POST back to
// crm/index.php with op=move; failures reload to re-sync.
(function () {
    if (typeof Sortable === 'undefined') {
        console.warn('Sortable.js not loaded — drag-and-drop disabled.');
        return;
    }

    const board = document.querySelector('.crm-board');
    if (!board) return;

    const csrf  = board.dataset.csrf || '';
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
            fallbackTolerance: 4,
            onEnd: async (evt) => {
                const card      = evt.item;
                const inquiryId = parseInt(card.dataset.inquiryId, 10);
                const newStatus = evt.to.dataset.status;
                const oldStatus = evt.from.dataset.status;

                if (!inquiryId || !newStatus)   return;
                if (newStatus === oldStatus)    return; // intra-column reorder, no-op

                // Block drops onto Enrolled — that needs grade + teacher per
                // child, which only the detail-page form can collect. Snap the
                // card back and tell the user where to go.
                if (newStatus === 'enrolled') {
                    const anchor = evt.from.children[evt.oldIndex] || null;
                    evt.from.insertBefore(card, anchor);
                    alert('To enroll children, open the card and use the "Enroll children" form — it needs a grade and class teacher per child.');
                    return;
                }

                try {
                    const fd = new FormData();
                    fd.append('_csrf', csrf);
                    fd.append('op', 'move');
                    fd.append('id', String(inquiryId));
                    fd.append('status', newStatus);
                    const res = await fetch('/crm/index.php', {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const data = await res.json();
                    if (!data.ok) {
                        console.error('move failed:', data.error);
                        window.location.reload();
                        return;
                    }
                    // Update column counters
                    board.querySelectorAll('.crm-col').forEach(col => {
                        const pill = col.querySelector('.crm-col-head .pill');
                        const n    = col.querySelectorAll('.crm-card-li').length;
                        if (pill) pill.textContent = String(n);
                    });
                } catch (err) {
                    console.error('network error:', err);
                    window.location.reload();
                }
            },
        });
    });
})();
