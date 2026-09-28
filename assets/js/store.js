/* ============================================================
   محیطِ فروشگاهی — بهبودِ پیش‌رونده‌ی فرم‌های سند
   ============================================================
   ⛔ هیچ چیزی به این فایل **بند نیست**: هر فرم بی‌آن هم کار می‌کند
      («افزودنِ ردیف» یک دکمه‌ی فرم است و جمع را سرور حساب می‌کند). این فایل
      فقط سه کار را سریع‌تر می‌کند:
      ۱. پر کردنِ کالا و قیمت از فهرستِ `<datalist id="bizProducts">` (کد و
         قیمت در data-* گزینه‌ها — هیچ JSONی در صفحه نیست)،
      ۲. جمعِ زنده‌ی ردیف و فاکتور — **فقط نمایش**؛ سرور عددِ مرورگر را
         نمی‌پذیرد،
      ۳. خواندنِ بارکد و IMEI در «فروش سریع» (اسکنر متن را تایپ و Enter می‌زند)،
      ۴. بازکردنِ درجای پنلِ «کالای تازه» با «+» (بی‌جاوااسکریپت همان دکمه
         فرم را می‌فرستد و سرور پنل را باز برمی‌گرداند).
   ⛔ IMEI: گوشیِ در انبار در همان datalist یک گزینه است (مقدار = IMEI،
      data-imei1/2). اینجا فقط پیدا و پر می‌شود؛ «در انبار هست؟» را سرور
      هنگامِ صدور می‌سنجد (`BizSerial::check`).
   ⚠ کشوی منو و «+ ثبت» هیچ ربطی به این فایل ندارند (چک‌باکس و details).
   ============================================================ */
(function () {
    'use strict';

    var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
    function latin(s) {
        return String(s == null ? '' : s).replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); });
    }
    function money(s) { var n = parseInt(latin(s).replace(/[^0-9]/g, ''), 10); return isNaN(n) ? 0 : n; }
    function qty(s) {
        var t = latin(s).trim().replace(/[٫\/,،]/g, '.').replace(/[^0-9.]/g, '');
        if (t === '') { return null; }
        var n = parseFloat(t); return isNaN(n) ? 0 : n;
    }
    function fmt(n) {
        var neg = n < 0; n = Math.round(Math.abs(n));
        var s = String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
        return (neg ? '−' : '') + s.replace(/[0-9]/g, function (d) { return FA[d]; });
    }

    // ---------- فهرستِ کالا از datalist ----------
    function imeiNorm(s) { return latin(s).replace(/[\s\u200c\-\/.]+/g, ''); }
    var byName = {}, bySku = {}, byImei = {};
    var dl = document.getElementById('bizProducts');
    if (dl) {
        Array.prototype.forEach.call(dl.options, function (o) {
            var unit = !!o.dataset.imei1;
            var p = { id: o.dataset.id, name: unit ? o.dataset.name : o.value, sku: o.dataset.sku || '', sell: o.dataset.sell, buy: o.dataset.buy,
                      unit: o.dataset.unit, stock: parseFloat(o.dataset.stock || '0'), track: o.dataset.track === '1',
                      serial: o.dataset.serial === '1', imei1: unit ? o.dataset.imei1 : '', imei2: unit ? (o.dataset.imei2 || '') : '' };
            if (unit) {
                byImei[p.imei1] = p;
                if (p.imei2) { byImei[p.imei2] = p; }
                return;
            }
            byName[p.name] = p;
            if (p.sku) { bySku[latin(p.sku).toLowerCase()] = p; }
        });
    }
    function find(text) {
        var t = String(text || '').trim();
        if (t === '') { return null; }
        return byName[t] || bySku[latin(t).toLowerCase()] || byImei[imeiNorm(t)] || null;
    }

    document.querySelectorAll('form[data-invoice]').forEach(function (form) {
        var body = form.querySelector('[data-lines]');
        if (!body) { return; }
        var priceKey = form.dataset.price === 'buy' ? 'buy' : 'sell';

        function rows() { return Array.prototype.slice.call(body.querySelectorAll('[data-row]')); }
        function field(row, name) { return row.querySelector('[data-' + name + ']'); }

        function recalc() {
            var sub = 0;
            rows().forEach(function (r) {
                var q = qty(field(r, 'qty').value), p = money(field(r, 'price').value), d = money(field(r, 'disc') ? field(r, 'disc').value : '');
                var hasItem = field(r, 'item').value.trim() !== '' || field(r, 'price').value.trim() !== '';
                var lt = hasItem ? Math.round((q === null ? 1 : q) * p) - d : 0;
                var out = field(r, 'lt');
                if (out) { out.textContent = hasItem ? fmt(lt) : ''; }
                sub += lt;
            });
            var disc = form.querySelector('[data-discount]'), extra = form.querySelector('[data-extra]');
            var total = sub - (disc ? money(disc.value) : 0) + (extra ? money(extra.value) : 0);
            var s = form.querySelector('[data-subtotal]'), t = form.querySelector('[data-total]');
            if (s) { s.textContent = fmt(sub); }
            if (t) { t.textContent = fmt(total); }
        }

        function imeiBox(row, show) {
            var box = row.querySelector('[data-imei-box]');
            if (!box) { return; }
            var filled = field(row, 'imei1').value.trim() !== '' || field(row, 'imei2').value.trim() !== '';
            box.hidden = !(show || filled);
        }
        function apply(row, p) {
            var item = field(row, 'item'), pid = field(row, 'pid'), price = field(row, 'price');
            if (p) {
                item.value = p.name;
                pid.value = p.id;
                if (p.imei1) {                                  // یک گوشیِ مشخص: IMEIها و مقدارِ ۱
                    field(row, 'imei1').value = p.imei1;
                    field(row, 'imei2').value = p.imei2 || '';
                    field(row, 'qty').value = '1';
                }
                imeiBox(row, p.serial);
                if (p.serial && !p.imei1 && field(row, 'imei1').value.trim() === '') { field(row, 'imei1').focus(); }
                if (price.value.trim() === '' && p[priceKey] && p[priceKey] !== '0') { price.value = p[priceKey]; }
                row.classList.toggle('is-over', priceKey === 'sell' && p.track && (qty(field(row, 'qty').value) || 1) > p.stock + 0.0005);
                item.title = p.track ? ('موجودی: ' + fmt(p.stock) + ' ' + (p.unit || '')) : '';
            } else {
                pid.value = '';                                 // ⛔ شناسه‌ی کهنه هرگز روی ردیفِ عوض‌شده نمی‌ماند
                row.classList.remove('is-over');
                imeiBox(row, false);
            }
        }

        function renumber(row, i) {
            row.querySelectorAll('input').forEach(function (inp) {
                inp.name = inp.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
            });
            var plus = row.querySelector('[data-np-open]');
            if (plus) { plus.value = String(i); }
            var no = row.querySelector('.st-line-no');
            if (no) { no.textContent = fmt(i + 1); }
        }
        function addRow() {
            var all = rows(), last = all[all.length - 1];
            var copy = last.cloneNode(true);
            copy.querySelectorAll('input').forEach(function (inp) { inp.value = ''; inp.removeAttribute('title'); });
            var note = copy.querySelector('.st-line-note'); if (note) { note.remove(); }
            var box = copy.querySelector('[data-imei-box]'); if (box) { box.hidden = true; }
            var lt = field(copy, 'lt'); if (lt) { lt.textContent = ''; }
            copy.classList.remove('is-over');
            body.appendChild(copy);
            renumber(copy, all.length);
            return copy;
        }
        function firstEmpty() {
            var r = rows().filter(function (x) { return field(x, 'item').value.trim() === ''; })[0];
            return r || addRow();
        }

        body.addEventListener('change', function (e) {
            var row = e.target.closest('[data-row]');
            if (row && e.target.matches('[data-item]')) { apply(row, find(e.target.value)); }
            recalc();
        });
        body.addEventListener('input', function (e) {
            var row = e.target.closest('[data-row]');
            if (row && e.target.matches('[data-item]')) {
                var p = find(e.target.value);
                // نامِ کامل یا IMEIِ کاملِ یک گوشیِ در انبار (انتخاب از فهرست یا اسکن)
                if (p && (p.name === e.target.value || p.imei1)) { apply(row, p); } else { field(row, 'pid').value = ''; }
            }
            recalc();
        });
        form.addEventListener('input', function (e) { if (e.target.matches('[data-discount],[data-extra]')) { recalc(); } });

        // Enter داخلِ ردیف فرم را نمی‌فرستد؛ به خانه‌ی بعدی می‌رود
        body.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches('input')) { return; }
            e.preventDefault();
            var row = e.target.closest('[data-row]');
            if (e.target.matches('[data-item]')) {
                apply(row, find(e.target.value));
                var box = row.querySelector('[data-imei-box]'), im = field(row, 'imei1');
                if (box && !box.hidden && im && im.value.trim() === '') { im.focus(); } else { field(row, 'qty').focus(); }
            } else {
                var next = row.nextElementSibling || addRow();
                field(next, 'item').focus();
            }
            recalc();
        });

        var add = form.querySelector('[data-add-rows]');
        if (add) {
            add.addEventListener('click', function (e) { e.preventDefault(); field(addRow(), 'item').focus(); });
        }

        // ---------- فروشِ سریع: بارکد ----------
        var scan = form.querySelector('[data-scan]');
        if (scan) {
            scan.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') { return; }
                e.preventDefault();
                var p = find(scan.value);
                if (!p) { scan.classList.add('is-miss'); scan.select(); return; }
                scan.classList.remove('is-miss');
                if (p.imei1) {
                    // گوشی: هر دستگاه ردیفِ خودش؛ اسکنِ دوباره‌ی همان IMEI چیزی اضافه نمی‌کند
                    var dup = rows().filter(function (x) { var f = field(x, 'imei1'); return f && f.value === p.imei1; })[0];
                    if (!dup) { apply(firstEmpty(), p); }
                    scan.value = '';
                    recalc();
                    return;
                }
                var same = rows().filter(function (x) { return field(x, 'pid').value === p.id; })[0];
                if (same) {
                    var q = qty(field(same, 'qty').value);
                    field(same, 'qty').value = String(Math.round(((q === null ? 1 : q) + 1) * 1000) / 1000);
                    apply(same, p);
                } else {
                    apply(firstEmpty(), p);
                }
                scan.value = '';
                recalc();
            });
        }
        // ---------- «+»: پنلِ کالای تازه، درجا ----------
        var np = form.querySelector('[data-np]');
        if (np) {
            var npRow = np.querySelector('[data-np-row]'), npName = np.querySelector('[data-np-name]');
            var npImei = np.querySelector('[data-np-imei]');
            function npType() { var c = np.querySelector('[data-np-type]:checked'); return c ? c.value : 'goods'; }
            function npSync() { if (npImei) { npImei.hidden = npType() !== 'phone'; } }
            np.addEventListener('change', function (e) { if (e.target.matches('[data-np-type]')) { npSync(); } });
            body.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-np-open]');
                if (!btn) { return; }
                e.preventDefault();
                var row = btn.closest('[data-row]');
                npRow.value = String(rows().indexOf(row));
                var typed = field(row, 'item').value.trim(), digits = imeiNorm(typed);
                if (/^\d{14,17}$/.test(digits)) {
                    var ph = np.querySelector('[data-np-type][value="phone"]'); if (ph) { ph.checked = true; }
                    var i1 = np.querySelector('[name="np[imei1]"]'); if (i1 && i1.value === '') { i1.value = digits; }
                } else if (typed !== '' && npName.value === '') {
                    npName.value = typed;
                }
                npSync();
                np.hidden = false;
                np.scrollIntoView({ block: 'nearest' });
                npName.focus();
            });
            var close = np.querySelector('[data-np-close]');
            if (close) { close.addEventListener('click', function (e) { e.preventDefault(); np.hidden = true; }); }
            npSync();
        }
        recalc();
    });
})();
