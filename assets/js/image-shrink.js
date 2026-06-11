/**
 * image-shrink.js — compress images in the browser before upload.
 *
 * Phone cameras produce 4–15 MB photos; shared-host PHP commonly allows
 * 2 MB per file and 8 MB per POST. Without this, a parent attaching a
 * couple of photos watches the form "hang" on mobile data and then the
 * server drops the oversized request entirely.
 *
 * Usage: add data-shrink to any <input type="file">. On change, every
 * image file larger than THRESHOLD is redrawn onto a canvas capped at
 * MAX_DIM px and re-encoded as JPEG. The input's FileList is replaced
 * via DataTransfer. Non-images (PDFs) and small files pass through.
 * Any failure (HEIC the browser can't decode, ancient browser, etc.)
 * falls back silently to the original file — the server-side size
 * checks remain the backstop.
 */
(() => {
    'use strict';

    const THRESHOLD = 400 * 1024;   // leave already-small files alone
    const MAX_DIM   = 1600;         // px, longest edge — plenty for ID scans
    const QUALITY   = 0.82;

    const fmt = b => b > 1024 * 1024
        ? (b / (1024 * 1024)).toFixed(1) + ' MB'
        : Math.round(b / 1024) + ' KB';

    async function shrinkFile(file) {
        if (!file.type.startsWith('image/') || file.size <= THRESHOLD) return file;

        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((res, rej) => {
                const i = new Image();
                i.onload  = () => res(i);
                i.onerror = rej;
                i.src = url;
            });

            const scale = Math.min(1, MAX_DIM / Math.max(img.naturalWidth, img.naturalHeight));
            const w = Math.max(1, Math.round(img.naturalWidth  * scale));
            const h = Math.max(1, Math.round(img.naturalHeight * scale));

            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            canvas.getContext('2d').drawImage(img, 0, 0, w, h);

            const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', QUALITY));
            if (!blob || blob.size >= file.size) return file;   // no win — keep original

            const name = file.name.replace(/\.\w+$/, '') + '.jpg';
            return new File([blob], name, { type: 'image/jpeg' });
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    function hintEl(input) {
        let el = input.parentElement.querySelector('.shrink-hint');
        if (!el) {
            el = document.createElement('span');
            el.className = 'shrink-hint';
            el.style.cssText = 'display:block;font-size:.78rem;color:#66bb6a;margin-top:.2rem;';
            input.insertAdjacentElement('afterend', el);
        }
        return el;
    }

    document.querySelectorAll('input[type="file"][data-shrink]').forEach(input => {
        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            if (!file) return;
            try {
                const out = await shrinkFile(file);
                if (out !== file) {
                    const dt = new DataTransfer();
                    dt.items.add(out);
                    input.files = dt.files;
                    hintEl(input).textContent =
                        'Photo optimised: ' + fmt(file.size) + ' → ' + fmt(out.size);
                } else if (file.type.startsWith('image/') && file.size > 2 * 1024 * 1024) {
                    // Couldn't compress (e.g. HEIC the browser won't decode) and
                    // it's over the usual per-file server cap — warn up-front.
                    hintEl(input).textContent =
                        'This photo is large (' + fmt(file.size) + ') and may fail to upload. Try a different photo.';
                    hintEl(input).style.color = '#b03030';
                }
            } catch (e) { /* keep the original file; server limits backstop */ }
        });
    });
})();
