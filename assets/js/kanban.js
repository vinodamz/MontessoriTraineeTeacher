// LG Task Manager — Kanban board drag-and-drop.
// Each column's <ul.board-col-list> becomes a Sortable. Moves POST to
// tasks.php with op=move, returning JSON.
(function () {
    if (typeof Sortable === 'undefined') {
        console.warn('Sortable.js not loaded');
        return;
    }

    const board = document.querySelector('.board');
    if (!board) return;

    const csrf = window.LGTM_CSRF || board.dataset.csrf || '';
    const lists = board.querySelectorAll('.board-col-list');

    lists.forEach(list => {
        new Sortable(list, {
            group: 'lgtm-cards',          // shared group → cards can move between columns
            animation: 160,
            ghostClass: 'board-card-ghost',
            chosenClass: 'board-card-chosen',
            dragClass:   'board-card-drag',
            handle: '.board-card',
            forceFallback: false,         // native HTML5 on desktop, touch fallback elsewhere
            fallbackOnBody: true,
            fallbackTolerance: 8,
            // Touch tuning: a short press-and-hold is required before a drag
            // begins so a normal finger-scroll on the board doesn't grab a
            // card. Desktop is unaffected (delayOnTouchOnly).
            delay: 250,
            delayOnTouchOnly: true,
            touchStartThreshold: 8,
            onEnd: async (evt) => {
                const card = evt.item;
                const taskId = parseInt(card.dataset.taskId, 10);
                const destList = evt.to;
                const colId = parseInt(destList.dataset.colId, 10);
                const newIndex = evt.newIndex;

                if (!taskId || !colId) return;

                try {
                    const fd = new FormData();
                    fd.append('_csrf', csrf);
                    fd.append('op', 'move');
                    fd.append('id', String(taskId));
                    fd.append('column_id', String(colId));
                    fd.append('position', String(newIndex));
                    const res = await fetch('tasks.php', {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const data = await res.json();
                    if (!data.ok) {
                        console.error('move failed:', data.error);
                        // Revert by reloading — simpler than tracking the original index
                        window.location.reload();
                        return;
                    }
                    // Update counts in column headers
                    board.querySelectorAll('.board-col').forEach(col => {
                        const c = col.querySelector('.board-col-count');
                        const n = col.querySelectorAll('.board-card').length;
                        if (c) c.textContent = String(n);
                    });
                } catch (err) {
                    console.error('network error:', err);
                    window.location.reload();
                }
            },
        });
    });
})();
