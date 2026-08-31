// materials-daily.js — autosave + photo/video on today's materials sheet.
(function () {
    var form = document.getElementById('bulkForm');
    if (!form || !window.fetch) return;
    var CSRF = form.querySelector('input[name="_csrf"]').value;
    var UPLOAD_LIMIT = (window.MM_DAILY && window.MM_DAILY.uploadLimit) || 40 * 1024 * 1024;
    var DATE = 'today';

    function rowEls(item) {
        return {
            cond: item.querySelector('.mm-cond'),
            note: item.querySelector('.mm-note'),
            status: item.querySelector('.mm-status'),
            upmsg: item.querySelector('.mm-upmsg')
        };
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function draftKey(tr) { return 'mmdaily:' + DATE + ':' + tr.dataset.id; }
    function serializeRow(tr) {
        var el = rowEls(tr);
        return JSON.stringify({ c: el.cond ? el.cond.value : '', t: el.note ? el.note.value : '' });
    }
    var pending = {};
    function writeDraft(tr) {
        try { localStorage.setItem(draftKey(tr), serializeRow(tr)); } catch (e) {}
        pending[tr.dataset.id] = true;
        refreshPending();
    }
    function clearDraft(tr) {
        try { localStorage.removeItem(draftKey(tr)); } catch (e) {}
        delete pending[tr.dataset.id];
        refreshPending();
    }
    function refreshPending() {
        var n = Object.keys(pending).length;
        var out = document.getElementById('pendingCount');
        if (out) out.textContent = n === 0
            ? 'Changes save as you mark them.'
            : n + ' change' + (n === 1 ? '' : 's') + ' not confirmed yet — kept on this phone.';
    }
    function updateProgress(d) {
        if (typeof d.checked !== 'number') return;
        var p = document.getElementById('mmProgress');
        if (p) p.textContent = d.checked + '/' + d.total + ' checked today';
        var c = document.getElementById('mmChecked');
        if (c) c.textContent = String(d.checked);
        var pend = document.getElementById('mmPending');
        if (pend) pend.textContent = String(d.pending);
    }

    function buildRowFormData(tr) {
        var el = rowEls(tr);
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('op', 'ajax_mark');
        fd.append('material_id', tr.dataset.id);
        fd.append('condition_code', el.cond.value);
        fd.append('notes', el.note ? el.note.value : '');
        return fd;
    }

    function saveRow(tr) {
        var el = rowEls(tr);
        if (!el.cond || el.cond.value === '') {
            el.status.innerHTML = '<span style="color:#b3261e">pick a condition — the note is kept until you do</span>';
            return Promise.resolve(false);
        }
        var state = serializeRow(tr);
        if (tr.dataset.lastSaved === state) {
            clearDraft(tr);
            return Promise.resolve(true);
        }
        el.status.textContent = 'Saving…';
        return fetch('/materials/daily.php', { method: 'POST', body: buildRowFormData(tr), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'save failed');
                tr.dataset.saved = el.cond.value;
                tr.dataset.lastSaved = state;
                clearDraft(tr);
                flushWaiting(tr);
                var toneBg = { ok: '#dff1d3;color:#2d6526', warn: '#fcebc6;color:#6c4612', bad: '#fbdcd8;color:#8b1c14' }[d.tone] || '#eee';
                el.status.innerHTML =
                    '<span class="pill small" style="background:' + toneBg + '">' + escapeHtml(d.label) + '</span> ' +
                    '✓ ' + escapeHtml(d.by) + ' · ' + escapeHtml(d.at);
                updateProgress(d);
                return true;
            })
            .catch(function (err) {
                el.status.innerHTML = '<button type="button" class="link-btn danger mm-retry">⚠ not saved — retry</button>';
                console.error('daily autosave failed:', err);
                return false;
            });
    }

    form.querySelectorAll('.mm-item').forEach(function (item) {
        var sel = item.querySelector('.mm-cond');
        if (sel && sel.value !== item.dataset.saved) sel.value = item.dataset.saved || '';
        item.dataset.lastSaved = serializeRow(item);
    });
    form.querySelectorAll('.mm-item').forEach(function (item) {
        var raw = null;
        try { raw = localStorage.getItem(draftKey(item)); } catch (e) {}
        if (!raw || raw === item.dataset.lastSaved) {
            if (raw) { try { localStorage.removeItem(draftKey(item)); } catch (e) {} }
            return;
        }
        var d; try { d = JSON.parse(raw); } catch (e) { return; }
        var el = rowEls(item);
        if (d.c) el.cond.value = d.c;
        if (el.note && typeof d.t === 'string') el.note.value = d.t;
        pending[item.dataset.id] = true;
        el.status.innerHTML = '<span style="color:#6c4612">restoring unsaved change…</span>';
        saveRow(item);
    });
    refreshPending();

    var noteTimers = {};
    form.addEventListener('input', function (ev) {
        var tr = ev.target.closest('.mm-item');
        if (!tr) return;
        writeDraft(tr);
        if (ev.target.classList.contains('mm-note')) {
            clearTimeout(noteTimers[tr.dataset.id]);
            noteTimers[tr.dataset.id] = setTimeout(function () { saveRow(tr); }, 1200);
        }
    });
    form.addEventListener('change', function (ev) {
        var tr = ev.target.closest('.mm-item');
        if (!tr) return;
        if (ev.target.classList.contains('mm-file') || ev.target.classList.contains('mm-cam')) {
            uploadFiles(tr, ev.target);
            return;
        }
        writeDraft(tr);
        saveRow(tr);
    });
    form.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button');
        if (!btn) return;
        var tr = btn.closest('.mm-item');
        if (btn.classList.contains('mm-retry') && tr) saveRow(tr);
        if (btn.classList.contains('mm-snap') && tr) {
            var input = tr.querySelector(btn.dataset.kind === 'video' ? '.mm-cam-video' : '.mm-cam-photo');
            if (input) input.click();
        }
        if (btn.classList.contains('mm-gallery') && tr) {
            var g = tr.querySelector('.mm-file');
            if (g) g.click();
        }
    });

    function flushPending() {
        Object.keys(pending).forEach(function (id) {
            var tr = document.getElementById('m' + id);
            if (!tr) return;
            var el = rowEls(tr);
            if (!el.cond || el.cond.value === '') return;
            if (tr.dataset.lastSaved === serializeRow(tr)) return;
            if (navigator.sendBeacon) navigator.sendBeacon('/materials/daily.php', buildRowFormData(tr));
        });
    }
    window.addEventListener('pagehide', flushPending);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flushPending();
    });

    var upQueue = [], upActive = null;
    var obDB = null;
    function obOpen() {
        return new Promise(function (res) {
            if (obDB) return res(obDB);
            if (!window.indexedDB) return res(null);
            try {
                var rq = indexedDB.open('mm_daily_outbox', 1);
                rq.onupgradeneeded = function () { rq.result.createObjectStore('files', { keyPath: 'id' }); };
                rq.onsuccess = function () { obDB = rq.result; res(obDB); };
                rq.onerror = function () { res(null); };
            } catch (e) { res(null); }
        });
    }
    function obPut(rec) {
        return obOpen().then(function (db) {
            return new Promise(function (res) {
                if (!db) return res(false);
                try {
                    var tx = db.transaction('files', 'readwrite');
                    tx.objectStore('files').put(rec);
                    tx.oncomplete = function () { res(true); };
                    tx.onerror = function () { res(false); };
                } catch (e) { res(false); }
            });
        });
    }
    function obDel(id) {
        return obOpen().then(function (db) {
            return new Promise(function (res) {
                if (!db) return res(false);
                try {
                    var tx = db.transaction('files', 'readwrite');
                    tx.objectStore('files').delete(id);
                    tx.oncomplete = function () { res(true); };
                    tx.onerror = function () { res(false); };
                } catch (e) { res(false); }
            });
        });
    }
    function obAll() {
        return obOpen().then(function (db) {
            return new Promise(function (res) {
                if (!db) return res([]);
                try {
                    var rq = db.transaction('files', 'readonly').objectStore('files').getAll();
                    rq.onsuccess = function () { res(rq.result || []); };
                    rq.onerror = function () { res([]); };
                } catch (e) { res([]); }
            });
        });
    }

    function showPendingPreview(tr, item) {
        removePendingPreview(tr, item.obId);
        var f = item.file;
        var holder = document.createElement('span');
        holder.className = 'mm-pending';
        holder.dataset.obId = item.obId;
        holder.title = 'On this phone, not uploaded yet';
        if ((f.type || '').indexOf('image') === 0) {
            var img = document.createElement('img');
            try { img.src = URL.createObjectURL(f); } catch (e) {}
            holder.appendChild(img);
        } else {
            holder.textContent = (f.type || '').indexOf('video') === 0 ? '🎥' : '🎙';
        }
        tr.querySelector('.mm-top').appendChild(holder);
    }
    function removePendingPreview(tr, obId) {
        tr.querySelectorAll('.mm-pending').forEach(function (el) {
            if (!obId || el.dataset.obId === String(obId)) el.remove();
        });
    }
    function fmtMB(b) { return (b / 1048576).toFixed(1) + ' MB'; }
    function kindLabel(f) {
        var t = (f.type || '');
        return t.indexOf('video') === 0 ? 'video' : (t.indexOf('audio') === 0 ? 'voice memo' : 'photo');
    }
    function refreshUploadBar() {
        var n = (upActive ? 1 : 0) + upQueue.length;
        var out = document.getElementById('uploadCount');
        if (out) out.textContent = n > 0 ? '⬆ uploading ' + n + ' file' + (n === 1 ? '' : 's') + ' — keep this page open' : '';
    }

    var waitingForCondition = {};
    function parkForCondition(item) {
        var id = item.tr.dataset.id;
        (waitingForCondition[id] = waitingForCondition[id] || []).push(item);
        rowEls(item.tr).upmsg.innerHTML = '📷 kept on this phone — <strong>pick a condition</strong> and it uploads';
    }
    function flushWaiting(tr) {
        var list = waitingForCondition[tr.dataset.id];
        if (!list || !list.length) return;
        delete waitingForCondition[tr.dataset.id];
        list.forEach(function (item) { upQueue.push(item); });
        pumpUploads(); refreshUploadBar();
    }

    function queueUploads(tr, files) {
        var el = rowEls(tr);
        var accepted = 0;
        files.forEach(function (f) {
            if (f.size > UPLOAD_LIMIT) {
                el.upmsg.innerHTML = '<span style="color:#b3261e">⚠ ' + escapeHtml(f.name || 'file') + ' is ' + fmtMB(f.size)
                    + ' — over the ' + fmtMB(UPLOAD_LIMIT) + ' limit.</span>';
                return;
            }
            var item = {
                tr: tr, file: f, tries: 0,
                obId: 'ob_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8)
            };
            obPut({ id: item.obId, material_id: tr.dataset.id, blob: f, name: f.name || '', type: f.type || '', ts: Date.now() });
            showPendingPreview(tr, item);
            upQueue.push(item);
            accepted++;
        });
        if (accepted > 0) {
            el.upmsg.textContent = '⬆ queued ' + accepted + ' file' + (accepted === 1 ? '' : 's') + '…';
            pumpUploads();
        }
        refreshUploadBar();
    }

    function pumpUploads() {
        if (upActive || !upQueue.length) return;
        var item = upActive = upQueue.shift();
        refreshUploadBar();
        saveRow(item.tr).then(function (ok) {
            if (!ok) {
                upActive = null;
                parkForCondition(item);
                pumpUploads(); refreshUploadBar();
                return;
            }
            sendUpload(item);
        });
    }

    function sendUpload(item) {
        var tr = item.tr, el = rowEls(tr);
        var label = kindLabel(item.file);
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('op', 'ajax_media');
        fd.append('material_id', tr.dataset.id);
        fd.append('media', item.file, item.file.name || (label.replace(' ', '-') + '.bin'));
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/materials/daily.php');
        xhr.timeout = 300000;
        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            var pct = Math.round(e.loaded * 100 / e.total);
            el.upmsg.innerHTML = '⬆ Uploading ' + label + ' — <strong>' + pct + '%</strong>'
                + '<span class="mm-upbar"><i style="width:' + pct + '%"></i></span>';
        };
        xhr.onload = function () {
            upActive = null;
            var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status === 200 && d && d.ok) {
                if (item.obId) { obDel(item.obId); removePendingPreview(tr, item.obId); }
                el.upmsg.textContent = '✓ ' + label + ' attached';
                var pill = tr.querySelector('.mm-media-pill');
                var n = tr.querySelector('.mm-media-n');
                if (n) n.textContent = String((parseInt(n.textContent, 10) || 0) + 1);
                if (pill) pill.hidden = false;
                pumpUploads(); refreshUploadBar();
            } else if (xhr.status === 413 || xhr.status === 400) {
                if (item.obId) obDel(item.obId);
                uploadFailed(item, (d && d.error) ? d.error : ('rejected: ' + xhr.status), true);
            } else {
                uploadFailed(item, (d && d.error) ? d.error : ('server error ' + xhr.status));
            }
        };
        xhr.onerror = function () { uploadRetryOrFail(item, 'connection lost'); };
        xhr.ontimeout = function () { uploadRetryOrFail(item, 'timed out'); };
        xhr.send(fd);
    }
    function uploadRetryOrFail(item, why) {
        upActive = null;
        if (item.tries < 2) {
            item.tries++;
            rowEls(item.tr).upmsg.textContent = '⚠ ' + why + ' — retrying (' + item.tries + '/2)…';
            upQueue.unshift(item);
            setTimeout(pumpUploads, 2000 * item.tries);
            refreshUploadBar();
            return;
        }
        uploadFailed(item, why);
    }
    function uploadFailed(item, err, permanent) {
        upActive = null;
        var el = rowEls(item.tr);
        item.tries = 0;
        el.upmsg.innerHTML = '<span style="color:#b3261e">⚠ ' + escapeHtml(kindLabel(item.file)) + ' NOT uploaded (' + escapeHtml(err) + ')</span> ';
        if (!permanent) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn small';
            btn.textContent = 'retry upload';
            btn.addEventListener('click', function () {
                upQueue.unshift(item);
                el.upmsg.textContent = '⬆ retrying…';
                pumpUploads(); refreshUploadBar();
            });
            el.upmsg.appendChild(btn);
        }
        pumpUploads(); refreshUploadBar();
    }

    window.addEventListener('beforeunload', function (e) {
        if (upActive || upQueue.length) {
            e.preventDefault();
            e.returnValue = 'Photos are still uploading.';
            return e.returnValue;
        }
    });

    obAll().then(function (list) {
        var restored = 0;
        list.forEach(function (rec) {
            var tr = document.getElementById('m' + rec.material_id);
            if (!tr) { obDel(rec.id); return; }
            var f = rec.blob;
            if (f && !f.name) {
                try { f = new File([rec.blob], rec.name || 'photo.jpg', { type: rec.type || 'image/jpeg' }); } catch (e) {}
            }
            var item = { tr: tr, file: f, tries: 0, obId: rec.id };
            showPendingPreview(tr, item);
            var hasCond = tr.querySelector('.mm-cond') && tr.querySelector('.mm-cond').value !== '';
            if (hasCond) {
                rowEls(tr).upmsg.textContent = '⏳ found a photo that never uploaded — sending now…';
                upQueue.push(item);
            } else {
                parkForCondition(item);
            }
            restored++;
        });
        if (restored > 0) { pumpUploads(); refreshUploadBar(); }
    });

    function uploadFiles(tr, input) {
        var files = Array.prototype.slice.call(input.files || []);
        input.value = '';
        if (files.length) queueUploads(tr, files);
    }
})();
