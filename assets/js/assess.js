/* assess.js — visual feedback for rating selection on the assess form.
 * The form works without JS (radios persist on submit); JS only paints
 * the .is-on class so the selected button matches the chosen radio.
 */
(function () {
    'use strict';
    const form = document.getElementById('assessForm');
    if (!form) return;

    function paintRow(input) {
        const name = input.name;                   // rating[std:123]
        document.querySelectorAll(`label.rating-pick input[name="${CSS.escape(name)}"]`)
            .forEach(i => i.closest('.rating-pick').classList.toggle('is-on', i.checked));
    }

    form.querySelectorAll('input[type="radio"]').forEach(i => {
        i.addEventListener('change', () => paintRow(i));
    });
})();
