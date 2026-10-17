/**
 * انبارگردانی — خانه‌ی اسکن: Enter (اسکنرِ دستی یا دوربینِ `store-scan.js`) کد را به فهرست می‌برد،
 * نه فرم را می‌فرستد. ⛔ بی‌fetch: ثبت فقط با دکمه‌ی فرم (قاعده‌ی store.js). بی‌جاوااسکریپت هم
 * فهرست دستی پر می‌شود.
 */
(function () {
    'use strict';
    var form = document.querySelector('[data-count-form]');
    if (!form) { return; }
    var inp = form.querySelector('[data-count-scan]'), list = form.querySelector('[data-count-list]'), n = form.querySelector('[data-count-n]');
    function fa(s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function refresh() { n.textContent = fa(list.value.split(/\n/).filter(function (l) { return l.trim() !== ''; }).length); }
    inp.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var v = inp.value.trim();
        if (v !== '') {
            list.value = (list.value.replace(/\s+$/, '') + '\n' + v).replace(/^\n/, '') + '\n';
            if (navigator.vibrate) { try { navigator.vibrate(40); } catch (x) {} }
        }
        inp.value = '';
        refresh();
        inp.focus();
    });
    list.addEventListener('input', refresh);
    refresh();
})();
