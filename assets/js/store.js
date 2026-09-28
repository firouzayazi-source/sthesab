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
      ۳. خواندنِ بارکد در «فروش سریع» (اسکنر متن را تایپ و Enter می‌زند).
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
    var byName = {}, bySku = {};
    var dl = document.getElementById('bizProducts');
    if (dl) {
        Array.prototype.forEach.call(dl.options, function (o) {
            var p = { id: o.dataset.id, name: o.value, sku: o.dataset.sku || '', sell: o.dataset.sell, buy: o.dataset.buy,
                      unit: o.dataset.unit, stock: parseFloat(o.dataset.stock || '0'), track: o.dataset.track === '1' };
            byName[p.name] = p;
            if (p.sku) { bySku[latin(p.sku).toLowerCase()] = p; }
        });
    }
    function find(text) {
        var t = String(text || '').trim();
        if (t === '') { return null; }
        return byName[t] || bySku[latin(t).toLowerCase()] || null;
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

        function apply(row, p) {
            var item = field(row, 'item'), pid = field(row, 'pid'), price = field(row, 'price');
            if (p) {
                item.value = p.name;
                pid.value = p.id;
                if (price.value.trim() === '' && p[priceKey] && p[priceKey] !== '0') { price.value = p[priceKey]; }
                row.classList.toggle('is-over', priceKey === 'sell' && p.track && (qty(field(row, 'qty').value) || 1) > p.stock + 0.0005);
                item.title = p.track ? ('موجودی: ' + fmt(p.stock) + ' ' + (p.unit || '')) : '';
            } else {
                pid.value = '';                                 // ⛔ شناسه‌ی کهنه هرگز روی ردیفِ عوض‌شده نمی‌ماند
                row.classList.remove('is-over');
            }
        }

        function renumber(row, i) {
            row.querySelectorAll('input').forEach(function (inp) {
                inp.name = inp.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
            });
            var no = row.querySelector('.st-line-no');
            if (no) { no.textContent = fmt(i + 1); }
        }
        function addRow() {
            var all = rows(), last = all[all.length - 1];
            var copy = last.cloneNode(true);
            copy.querySelectorAll('input').forEach(function (inp) { inp.value = ''; inp.removeAttribute('title'); });
            var note = copy.querySelector('.st-line-note'); if (note) { note.remove(); }
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
                if (p && p.name === e.target.value) { apply(row, p); } else { field(row, 'pid').value = ''; }
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
                field(row, 'qty').focus();
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
        recalc();
    });
})();
