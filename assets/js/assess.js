/* assess.js — rating selection, bulk fill, and carried-row handling.
 *
 * The form works without JS: radios persist on submit, and carried-forward
 * ratings are pre-checked server-side, so a no-JS save still records exactly
 * what is on screen. JS only adds convenience — painting the selected pill,
 * per-area bulk fill, and dropping the "carried" flag once a row has been
 * looked at.
 */
(function () {
    'use strict';
    const form = document.getElementById('assessForm');
    if (!form) return;

    /** Sync the .is-on paint for every button sharing this radio's name. */
    function paintRow(input) {
        document.querySelectorAll(`label.rating-pick input[name="${CSS.escape(input.name)}"]`)
            .forEach(i => i.closest('.rating-pick').classList.toggle('is-on', i.checked));
    }

    /** A row the teacher has acted on is no longer merely "carried". */
    function confirmRow(el) {
        const tr = el.closest('tr');
        if (!tr || !tr.classList.contains('ind-carried')) return;
        tr.classList.remove('ind-carried');
        tr.querySelectorAll('.carry-tag').forEach(t => t.remove());
    }

    // Delegated so it also covers rows/controls rendered after load.
    form.addEventListener('change', (ev) => {
        const i = ev.target;
        if (i.matches('input[type="radio"]')) {
            paintRow(i);
            confirmRow(i);
        }
    });

    // ---- Per-area bulk fill ------------------------------------------------
    form.addEventListener('click', (ev) => {
        const setBtn   = ev.target.closest('.bulk-set');
        const clearBtn = ev.target.closest('.bulk-clear');
        if (!setBtn && !clearBtn) return;

        const block = ev.target.closest('.cat-block');
        if (!block) return;

        if (setBtn) {
            const code = setBtn.dataset.code;
            block.querySelectorAll('tr').forEach(tr => {
                const radio = tr.querySelector(`input[type="radio"][value="${CSS.escape(code)}"]`);
                if (!radio) return;          // legacy/retired rows have no radios
                radio.checked = true;
                paintRow(radio);
                confirmRow(radio);
            });
        } else {
            // Clearing wipes a whole area, including any carried starting
            // point, so it asks first.
            const area = (block.querySelector('h2')?.textContent || 'this area').trim();
            if (!window.confirm(`Clear every rating in ${area}?`)) return;
            block.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.checked = false;
                radio.closest('.rating-pick')?.classList.remove('is-on');
                confirmRow(radio);
            });
        }
    });
})();
