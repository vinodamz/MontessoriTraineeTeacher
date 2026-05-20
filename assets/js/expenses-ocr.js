/* expenses-ocr.js — client-side receipt OCR using Tesseract.js.
 *
 * Loads when the user picks an image on the new-expense form. The library
 * is fetched from a CDN by the parent page; we just check it's there and
 * fail soft otherwise. After OCR'ing, we regex out an amount, a date and
 * a merchant guess, and pre-fill the form fields IF the user hasn't
 * typed anything in them already. Raw text is stashed in #ocr_text for
 * the audit trail.
 */
(function () {
    'use strict';

    var fileInput  = document.getElementById('receipt-file');
    var statusBox  = document.getElementById('ocr-status');
    var detailsBox = document.getElementById('ocr-details');
    var preview    = document.getElementById('ocr-preview');
    if (!fileInput || !statusBox) return;

    var amountField   = document.getElementById('expense_amount');
    var dateField     = document.getElementById('expense_date');
    var merchantField = document.getElementById('expense_merchant');
    var ocrTextInput  = document.getElementById('ocr_text');

    function setStatus(msg, kind) {
        statusBox.hidden = false;
        statusBox.textContent = msg;
        statusBox.className = 'ocr-status ocr-status-' + (kind || 'info');
    }

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) { statusBox.hidden = true; return; }

        // We only OCR images. PDFs are uploaded as-is and reviewed manually.
        if (!file.type || file.type.indexOf('image/') !== 0) {
            setStatus('OCR is only run on image files. Fill the fields manually.', 'info');
            return;
        }

        if (typeof Tesseract === 'undefined') {
            setStatus('OCR library failed to load (offline?). Type the values manually.', 'warn');
            return;
        }

        setStatus('Reading the receipt… this can take 5-20 seconds.', 'info');

        Tesseract.recognize(file, 'eng', {
            logger: function (m) {
                if (m && m.status === 'recognizing text' && typeof m.progress === 'number') {
                    setStatus('Reading the receipt… ' + Math.round(m.progress * 100) + '%', 'info');
                }
            }
        }).then(function (result) {
            var text = (result && result.data && result.data.text) || '';
            if (ocrTextInput) ocrTextInput.value = text;
            if (preview)     preview.textContent = text;
            if (detailsBox)  detailsBox.hidden  = false;

            var parsed = parseReceipt(text);
            var filled = [];

            if (parsed.amount && amountField && !amountField.value) {
                amountField.value = parsed.amount.toFixed(2);
                filled.push('amount ₹' + parsed.amount.toFixed(2));
            }
            if (parsed.dateISO && dateField && !dateField.value) {
                dateField.value = parsed.dateISO;
                filled.push('date ' + parsed.dateISO);
            } else if (parsed.dateISO && dateField && dateField.value === todayISO()) {
                // The form defaults to today's date — override it with the parsed
                // value if we found one, since "today" is just a placeholder.
                dateField.value = parsed.dateISO;
                filled.push('date ' + parsed.dateISO);
            }
            if (parsed.merchant && merchantField && !merchantField.value) {
                merchantField.value = parsed.merchant;
                filled.push('merchant "' + parsed.merchant + '"');
            }

            if (filled.length) {
                setStatus('Pre-filled: ' + filled.join(', ') + '. Check it before submitting.', 'ok');
            } else {
                setStatus('Could not confidently extract any fields — fill them manually. (Raw text available below.)', 'warn');
            }
        }).catch(function (err) {
            setStatus('OCR failed: ' + (err && err.message ? err.message : 'unknown error'), 'warn');
        });
    });

    function todayISO() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    // --------------- parsing helpers ---------------

    function parseReceipt(text) {
        var amount   = pickAmount(text);
        var dateISO  = pickDate(text);
        var merchant = pickMerchant(text);
        return { amount: amount, dateISO: dateISO, merchant: merchant };
    }

    function pickAmount(text) {
        // Prefer "total / grand total / amount due / amount payable" lines —
        // those are usually the final tally on a printed receipt.
        var labelRx = /(grand\s*total|total\s*amount|amount\s*payable|amount\s*due|net\s*amount|net\s*total|total)\b[^0-9\-]*([₹$€£]?\s*[\-]?[\d,]+\.?\d{0,2})/ig;
        var best = null;
        var m;
        while ((m = labelRx.exec(text)) !== null) {
            var v = toNumber(m[2]);
            if (v && v > 0) {
                // Last "total"-line wins (receipts often list a subtotal first).
                best = v;
            }
        }
        if (best !== null) return best;

        // Fallback: pick the largest number on the page that looks like money
        // (has a decimal point or a currency symbol nearby).
        var moneyRx = /([₹$€£]\s*[\d,]+\.\d{2}|[\d,]+\.\d{2})/g;
        var max = 0;
        while ((m = moneyRx.exec(text)) !== null) {
            var n = toNumber(m[1]);
            if (n && n > max) max = n;
        }
        return max > 0 ? max : null;
    }

    function toNumber(s) {
        if (!s) return null;
        s = String(s).replace(/[₹$€£\s]/g, '').replace(/,/g, '');
        var n = parseFloat(s);
        return isFinite(n) ? n : null;
    }

    function pickDate(text) {
        // Try a few common date layouts.  All return YYYY-MM-DD or null.
        var monthNames = {
            jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6,
            jul: 7, aug: 8, sep: 9, sept: 9, oct: 10, nov: 11, dec: 12
        };

        // 1) dd/mm/yyyy  or dd-mm-yyyy  or dd.mm.yyyy
        var m = text.match(/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})\b/);
        if (m) {
            var d = +m[1], mo = +m[2], y = +m[3];
            if (y < 100) y += 2000;
            if (mo > 12 && d <= 12) { var tmp = d; d = mo; mo = tmp; } // swap if mm/dd/yyyy
            if (isPlausible(d, mo, y)) return iso(y, mo, d);
        }

        // 2) yyyy-mm-dd  or yyyy/mm/dd
        m = text.match(/\b(20\d{2})[\/\-](\d{1,2})[\/\-](\d{1,2})\b/);
        if (m) {
            var y2 = +m[1], mo2 = +m[2], d2 = +m[3];
            if (isPlausible(d2, mo2, y2)) return iso(y2, mo2, d2);
        }

        // 3) "12 May 2026" / "12-May-2026" / "May 12, 2026"
        m = text.match(/\b(\d{1,2})[\s\-]+([A-Za-z]{3,9})[\s\-,]+(\d{2,4})\b/);
        if (m) {
            var mo3 = monthNames[m[2].toLowerCase().slice(0, 4)] || monthNames[m[2].toLowerCase().slice(0, 3)];
            var y3  = +m[3]; if (y3 < 100) y3 += 2000;
            if (mo3 && isPlausible(+m[1], mo3, y3)) return iso(y3, mo3, +m[1]);
        }
        m = text.match(/\b([A-Za-z]{3,9})[\s\-]+(\d{1,2})[\s\-,]+(\d{2,4})\b/);
        if (m) {
            var mo4 = monthNames[m[1].toLowerCase().slice(0, 4)] || monthNames[m[1].toLowerCase().slice(0, 3)];
            var y4  = +m[3]; if (y4 < 100) y4 += 2000;
            if (mo4 && isPlausible(+m[2], mo4, y4)) return iso(y4, mo4, +m[2]);
        }

        return null;
    }

    function isPlausible(d, m, y) {
        return d >= 1 && d <= 31 && m >= 1 && m <= 12 && y >= 2000 && y <= 2100;
    }

    function iso(y, m, d) {
        return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    function pickMerchant(text) {
        // First non-empty line that looks like a business name —
        // skip lines that are obviously a date, an address starting with a
        // number, or a phone number.
        var lines = text.split(/\r?\n/);
        for (var i = 0; i < Math.min(lines.length, 6); i++) {
            var line = lines[i].trim();
            if (line.length < 3 || line.length > 80) continue;
            if (/^\d/.test(line))                        continue;  // street numbers, etc.
            if (/(invoice|receipt|bill|gst|tax|date)/i.test(line)) continue;
            if (/\d{4,}/.test(line))                     continue;  // phone numbers
            // Keep letters + a few punctuation marks. Strip trailing dots.
            return line.replace(/[\.,;:]+$/, '').substring(0, 80);
        }
        return null;
    }
}());
