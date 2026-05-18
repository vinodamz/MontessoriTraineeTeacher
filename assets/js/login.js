/* login.js — numpad PIN entry overlay.
 * Wired up on profile-card click. Each card carries data-uid + data-name.
 */
(function () {
    'use strict';

    const overlay = document.getElementById('pinOverlay');
    const close   = document.getElementById('pinClose');
    const hello   = document.getElementById('pinHello');
    const dots    = document.getElementById('pinDots').querySelectorAll('span');
    const errorEl = document.getElementById('pinError');
    const numpad  = document.getElementById('numpad');
    const submit  = document.getElementById('pinSubmit');

    let userId = null;
    let userName = '';
    let pin = '';
    let busy = false;

    function openModal(uid, name) {
        userId = uid;
        userName = name;
        pin = '';
        busy = false;
        hello.textContent = 'Hi, ' + (name.split(/\s+/)[0] || name) + ' —';
        errorEl.innerHTML = '&nbsp;';
        render();
        overlay.hidden = false;
        setTimeout(() => numpad.querySelector('.key').focus(), 0);
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        overlay.hidden = true;
        pin = '';
        userId = null;
        document.body.style.overflow = '';
    }

    function render() {
        dots.forEach((d, i) => d.classList.toggle('on', i < pin.length));
        submit.disabled = pin.length < 4 || busy;
    }

    document.querySelectorAll('.profile-card').forEach(btn => {
        btn.addEventListener('click', () => {
            openModal(parseInt(btn.dataset.uid, 10), btn.dataset.name || '');
        });
    });

    close.addEventListener('click', closeModal);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', e => {
        if (overlay.hidden) return;
        if (e.key === 'Escape') return closeModal();
        if (/^[0-9]$/.test(e.key)) { press(e.key); return; }
        if (e.key === 'Backspace')  { press('back'); return; }
        if (e.key === 'Enter')      { trySubmit(); }
    });

    numpad.addEventListener('click', e => {
        const k = e.target.closest('.key');
        if (!k) return;
        press(k.dataset.k);
    });

    submit.addEventListener('click', trySubmit);

    function press(k) {
        if (busy) return;
        if (k === 'clear')      pin = '';
        else if (k === 'back')  pin = pin.slice(0, -1);
        else if (/^\d$/.test(k) && pin.length < 6) pin += k;
        render();
    }

    function trySubmit() {
        if (busy || pin.length < 4 || userId == null) return;
        busy = true;
        submit.disabled = true;
        errorEl.textContent = '';
        const body = new URLSearchParams();
        body.set('user_id', userId);
        body.set('pin', pin);
        body.set('_csrf', window.LG_CSRF || '');
        fetch('/login.php', { method: 'POST', body }).then(async r => {
            const j = await r.json().catch(() => ({}));
            if (j.ok && j.redirect) {
                window.location.href = j.redirect;
                return;
            }
            errorEl.textContent = j.error || 'Wrong PIN';
            pin = '';
            busy = false;
            render();
        }).catch(() => {
            errorEl.textContent = 'Network error — try again.';
            pin = '';
            busy = false;
            render();
        });
    }
})();
