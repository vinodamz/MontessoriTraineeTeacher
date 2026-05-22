// Recruitment pipeline — drag-and-drop status change.
// Each column's <ul.crm-col-list> becomes a Sortable. Drops POST to
// /recruitment/api.php op=move; failures reload to re-sync.
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
            group: 'recruit-cards',
            animation: 160,
            ghostClass:  'crm-card-ghost',
            chosenClass: 'crm-card-chosen',
            dragClass:   'crm-card-drag',
            forceFallback: false,
            fallbackOnBody: true,
            fallbackTolerance: 4,
            onEnd: async (evt) => {
                const card        = evt.item;
                const candidateId = parseInt(card.dataset.candidateId, 10);
                const newStatus   = evt.to.dataset.status;
                const oldStatus   = evt.from.dataset.status;

                if (!candidateId || !newStatus) return;
                if (newStatus === oldStatus)    return; // intra-column reorder

                // Block direct drops onto Hired — promotion needs a real
                // users row created in a transaction, which only the Hire
                // button on the detail page triggers (api.php op=hire).
                if (newStatus === 'hired') {
                    const anchor = evt.from.children[evt.oldIndex] || null;
                    evt.from.insertBefore(card, anchor);
                    alert('Use the Hire button on the candidate page to onboard — it creates a user row in one transaction.');
                    return;
                }

                try {
                    const fd = new FormData();
                    fd.append('_csrf', csrf);
                    fd.append('op', 'move');
                    fd.append('id', String(candidateId));
                    fd.append('status', newStatus);
                    const res = await fetch('/recruitment/api.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                    });
                    const data = await res.json();
                    if (!data.ok) {
                        console.error('move failed:', data.error);
                        window.location.reload();
                        return;
                    }
                    // Update column counters.
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
