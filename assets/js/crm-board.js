// Admissions pipeline — drag-and-drop status change (desktop) +
// "Move to" picker on every card (mobile + accessible alternative).
//
// On a touch device the picker is the only way to move cards: Sortable
// is skipped entirely so the tap-to-open-card vs. start-drag race never
// happens. On desktop both interactions work — drag for speed, picker
// for keyboard / single-tap accessibility.
(function () {
    'use strict';

    var board = document.querySelector('.crm-board');
    if (!board) return;

    var csrf     = board.dataset.csrf || '';
    var isCoarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

    // Guard against double-posting the same move (e.g. SortableJS firing
    // onEnd twice due to a browser quirk, or a fast drag + select combo).
    var inflight = {};

    function moveCard(card, newStatus, oldStatus, onSnapBack) {
        if (!newStatus || newStatus === oldStatus) return Promise.resolve(false);
        if (newStatus === 'enrolled') {
            if (onSnapBack) onSnapBack();
            alert('To enroll children, open the card and use the "Enroll children" form — it needs a grade and class teacher per child.');
            return Promise.resolve(false);
        }
        var inquiryId = parseInt(card.dataset.inquiryId, 10);
        if (!inquiryId) return Promise.resolve(false);

        // Prevent duplicate in-flight requests for the same card.
        if (inflight[inquiryId]) {
            if (onSnapBack) onSnapBack();
            return Promise.resolve(false);
        }
        inflight[inquiryId] = true;

        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('op', 'move');
        fd.append('id', String(inquiryId));
        fd.append('status', newStatus);
        return fetch('/crm/index.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (r) { return r.json(); }).then(function (data) {
            delete inflight[inquiryId];
            if (!data || !data.ok) {
                console.error('move failed:', data && data.error);
                if (onSnapBack) onSnapBack();
                window.location.reload();
                return false;
            }
            dedup(inquiryId);
            updateColumnCounts();
            return true;
        }).catch(function (err) {
            delete inflight[inquiryId];
            console.error('network error:', err);
            if (onSnapBack) onSnapBack();
            window.location.reload();
            return false;
        });
    }

    function updateColumnCounts() {
        board.querySelectorAll('.crm-col').forEach(function (col) {
            var pill = col.querySelector('.crm-col-head .pill');
            var n    = col.querySelectorAll('.crm-card-li').length;
            if (pill) pill.textContent = String(n);
        });
    }

    // Remove duplicate cards with the same inquiry-id. SortableJS's HTML5
    // drag backend can leave a ghost clone behind in some browser/OS combos.
    function dedup(inquiryId) {
        var cards = board.querySelectorAll('.crm-card-li[data-inquiry-id="' + inquiryId + '"]');
        if (cards.length <= 1) return;
        // Keep the last one (the one SortableJS dropped into the target column);
        // remove all earlier occurrences.
        for (var i = 0; i < cards.length - 1; i++) {
            cards[i].parentNode.removeChild(cards[i]);
        }
    }

    function syncSelect(card, newStatus) {
        var sel = card.querySelector('.crm-card-status-select');
        if (!sel) return;
        sel.querySelectorAll('option').forEach(function (o) {
            if (o.value === '') return;
            o.hidden = (o.value === newStatus);
        });
        sel.value = '';
        sel.dataset.current = newStatus;
    }

    // ----- Move-to picker (works on every device) -----
    board.addEventListener('change', function (ev) {
        var sel = ev.target;
        if (!sel.matches('.crm-card-status-select')) return;
        var newStatus = sel.value;
        if (!newStatus) return;

        var li         = sel.closest('.crm-card-li');
        var fromList   = sel.closest('.crm-col-list');
        var targetList = board.querySelector('.crm-col-list[data-status="' + newStatus + '"]');
        var oldStatus  = fromList ? fromList.dataset.status : '';
        if (!li || !targetList) { sel.value = ''; return; }

        sel.disabled = true;
        moveCard(li, newStatus, oldStatus, function () {
            sel.disabled = false;
            sel.value = '';
        }).then(function (ok) {
            if (ok) {
                targetList.appendChild(li);
                syncSelect(li, newStatus);
                sel.disabled = false;
            }
        });
    });

    // ----- Drag-and-drop (desktop only) -----
    if (isCoarse) return; // mobile: picker only
    if (typeof Sortable === 'undefined') {
        console.warn('Sortable.js not loaded — drag-and-drop disabled.');
        return;
    }

    var lists = board.querySelectorAll('.crm-col-list');
    lists.forEach(function (list) {
        new Sortable(list, {
            group: 'crm-cards',
            animation: 160,
            ghostClass:  'crm-card-ghost',
            chosenClass: 'crm-card-chosen',
            dragClass:   'crm-card-drag',
            // Force SortableJS's own pointer-based drag instead of the HTML5
            // Drag API. The native API can leave ghost clones in the DOM on
            // some browser/OS combos, causing the "duplicate card" bug.
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 8,
            // Auto-scroll while dragging. forceFallback disables the browser's
            // native drag auto-scroll, so we turn on SortableJS's own JS-based
            // AutoScroll and force it to run even under the fallback drag.
            // This scrolls the horizontal board AND bubbles to the window so
            // long columns scroll vertically too.
            scroll: true,
            forceAutoScrollFallback: true,
            bubbleScroll: true,
            scrollSensitivity: 80,
            scrollSpeed: 12,
            // IMPORTANT: onEnd must NOT be async. SortableJS doesn't understand
            // Promises and an async handler can confuse its internal state,
            // causing the dragged element to be duplicated. Fire the fetch and
            // handle the result in .then() instead.
            onEnd: function (evt) {
                var card      = evt.item;
                var newStatus = evt.to.dataset.status;
                var oldStatus = evt.from.dataset.status;
                if (newStatus === oldStatus) return; // intra-column reorder

                // SortableJS has already moved the card in the DOM. The fetch
                // confirms the server side; on failure the snap-back puts it
                // back. On success we sync the select + dedup.
                moveCard(card, newStatus, oldStatus, function () {
                    var anchor = evt.from.children[evt.oldIndex] || null;
                    evt.from.insertBefore(card, anchor);
                }).then(function (ok) {
                    if (ok) {
                        syncSelect(card, newStatus);
                    }
                });
            },
        });
    });
})();
