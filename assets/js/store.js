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
   ⛔ جست‌وجوی کالا یک فهرستِ کشویی خودِ ماست، نه پاپ‌آپِ `<datalist>`:
      **گزارشِ مالکِ نصب** «سرچِ کالا اصلاً کار نمی‌کنه». پاپ‌آپِ مرورگر روی
      گوشی قابلِ اتکا نیست، فقط رشته را عیناً می‌گردد (پس «آيفون»ِ اکسل با
      «آیفون»ِ کیبورد، نیم‌فاصله و ارقامِ فارسی هیچ‌وقت نمی‌خوانند) و انتخاب
      نکردن یعنی متنِ نیمه‌کاره «شرحِ آزاد» می‌شد. `fold()` همان
      `BizCommon::fold()`ِ سرور است. بی‌جاوااسکریپت `list="bizProducts"` سرِ
      جایش می‌ماند؛ با آن، این فایل برش می‌دارد تا دو فهرست روی هم نیفتند.
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
    // ⛔ همان `BizCommon::fold()`: حروفِ عربی، ارقام، اعراب/کشیده، نیم‌فاصله، بزرگی
    function fold(s) {
        return latin(s).replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[آأإٱ]/g, 'ا').replace(/ؤ/g, 'و').replace(/[ةۀ]/g, 'ه')
            .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
            .replace(/[\s\u200c\u200e\u200f]+/g, ' ').trim().toLowerCase();
    }
    var byName = {}, bySku = {}, byImei = {}, byFold = {}, index = [];
    var dl = document.getElementById('bizProducts');
    if (dl) {
        Array.prototype.forEach.call(dl.options, function (o) {
            var unit = !!o.dataset.imei1;
            var p = { id: o.dataset.id, name: unit ? o.dataset.name : o.value, sku: o.dataset.sku || '', sell: o.dataset.sell, buy: o.dataset.buy,
                      unit: o.dataset.unit, stock: parseFloat(o.dataset.stock || '0'), track: o.dataset.track === '1',
                      serial: o.dataset.serial === '1', imei1: unit ? o.dataset.imei1 : '', imei2: unit ? (o.dataset.imei2 || '') : '' };
            p.fname = fold(p.name);
            p.hay = p.fname + ' ' + fold(p.sku) + (unit ? ' ' + p.imei1 + ' ' + p.imei2 : '');
            index.push(p);
            if (unit) {
                byImei[p.imei1] = p;
                if (p.imei2) { byImei[p.imei2] = p; }
                return;
            }
            byName[p.name] = p;
            (byFold[p.fname] = byFold[p.fname] || []).push(p);
            if (p.sku) { bySku[latin(p.sku).toLowerCase()] = p; }
        });
    }
    // تطبیقِ دقیق (اسکنر، Enter، تایپِ کاملِ نام) — «از نظرِ آدم یکی» فقط وقتی یکتاست
    function find(text) {
        var t = String(text || '').trim();
        if (t === '') { return null; }
        var f = byFold[fold(t)];
        return byName[t] || bySku[latin(t).toLowerCase()] || byImei[imeiNorm(t)] || (f && f.length === 1 ? f[0] : null);
    }
    /**
     * پیشنهادها: هر تکه‌ی متن باید جایی در نام/کد/IMEI باشد؛ اولویت با نامی
     * که با متن شروع می‌شود. سقف ۸ — فهرستِ بلندتر روی گوشی خوانده نمی‌شود.
     */
    var AC_MAX = 8;
    function suggest(text) {
        var q = fold(text);
        if (q === '') { return []; }
        var toks = q.split(' '), digits = imeiNorm(text), out = [];
        for (var i = 0; i < index.length; i++) {
            var p = index[i], ok = true;
            for (var k = 0; k < toks.length; k++) { if (p.hay.indexOf(toks[k]) < 0) { ok = false; break; } }
            if (!ok && /^\d{4,}$/.test(digits) && p.imei1 && (p.imei1 + ' ' + p.imei2).indexOf(digits) >= 0) { ok = true; }
            if (!ok) { continue; }
            var score = p.fname.indexOf(q) === 0 ? 0 : (fold(p.sku) === q ? 0 : (p.fname.indexOf(q) >= 0 ? 1 : 2));
            out.push({ p: p, s: score + (p.imei1 ? 0.5 : 0) });
        }
        out.sort(function (a, b) { return a.s - b.s || (a.p.name < b.p.name ? -1 : a.p.name > b.p.name ? 1 : 0); });
        return out.slice(0, AC_MAX).map(function (x) { return x.p; });
    }

    // ---------- فهرستِ کشوییِ پیشنهاد (یکی برای همه‌ی خانه‌ها) ----------
    var ac = null, acFor = null, acItems = [], acActive = -1, acPick = null;
    function acEl() {
        if (ac) { return ac; }
        ac = document.createElement('div');
        ac.className = 'st-ac';
        ac.id = 'stAc';
        ac.setAttribute('role', 'listbox');
        ac.hidden = true;
        document.body.appendChild(ac);
        // ⛔ pointerdown نه click: وگرنه blurِ خانه پیش از انتخاب فهرست را می‌بست
        ac.addEventListener('pointerdown', function (e) {
            var o = e.target.closest('[data-ac-i]');
            if (!o) { return; }
            e.preventDefault();
            acChoose(parseInt(o.dataset.acI, 10));
        });
        return ac;
    }
    function acPlace() {
        if (!ac || ac.hidden || !acFor) { return; }
        var r = acFor.getBoundingClientRect(), vw = document.documentElement.clientWidth;
        var w = Math.min(Math.max(r.width, 280), vw - 16);
        var left = Math.min(Math.max(8, r.right - w), vw - w - 8);    // راست‌چین با خودِ خانه
        var below = window.innerHeight - r.bottom;
        ac.style.width = w + 'px';
        ac.style.left = left + 'px';
        if (below < 200 && r.top > below) { ac.style.top = ''; ac.style.bottom = (window.innerHeight - r.top + 4) + 'px'; }
        else { ac.style.bottom = ''; ac.style.top = (r.bottom + 4) + 'px'; }
    }
    function acClose() {
        if (ac) { ac.hidden = true; ac.innerHTML = ''; }
        if (acFor) { acFor.setAttribute('aria-expanded', 'false'); acFor.removeAttribute('aria-activedescendant'); }
        acFor = null; acItems = []; acActive = -1; acPick = null;
    }
    function acMark() {
        if (!ac) { return; }
        Array.prototype.forEach.call(ac.children, function (c, i) { c.classList.toggle('is-active', i === acActive); c.setAttribute('aria-selected', i === acActive ? 'true' : 'false'); });
        if (acActive >= 0 && ac.children[acActive]) {
            ac.children[acActive].scrollIntoView({ block: 'nearest' });
            acFor.setAttribute('aria-activedescendant', ac.children[acActive].id);
        }
    }
    function acOpen(input, onPick) {
        var list = suggest(input.value);
        if (!list.length) {
            if (acFor === input) { acClose(); }
            if (fold(input.value).length >= 2) { acEmpty(input); }
            return;
        }
        var el = acEl();
        acFor = input; acItems = list; acActive = -1; acPick = onPick;
        el.innerHTML = '';
        list.forEach(function (p, i) {
            var o = document.createElement('div');
            o.className = 'st-ac-opt'; o.id = 'stAcO' + i; o.setAttribute('role', 'option'); o.dataset.acI = String(i);
            var n = document.createElement('b'); n.textContent = p.name;              // ⛔ نامِ کاربر → textContent
            var m = document.createElement('span');
            var bits = [];
            if (p.imei1) { bits.push('IMEI ' + p.imei1); } else if (p.sku) { bits.push(p.sku); }
            if (p[priceKeyOf(input)] && p[priceKeyOf(input)] !== '0') { bits.push(fmt(parseInt(p[priceKeyOf(input)], 10))); }
            if (p.imei1) { bits.push('گوشیِ در انبار'); } else if (p.track) { bits.push('موجودی ' + fmt(p.stock)); } else { bits.push('خدمت'); }
            m.textContent = bits.join(' · ');
            if (!p.imei1 && p.track && p.stock <= 0) { o.classList.add('is-out'); }
            o.appendChild(n); o.appendChild(m);
            el.appendChild(o);
        });
        el.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', 'stAc');
        acPlace();
    }
    // چیزی پیدا نشد — گفته می‌شود، نه سکوت (سکوت یعنی «جست‌وجو کار نمی‌کند»)
    function acEmpty(input) {
        var el = acEl();
        acFor = input; acItems = []; acActive = -1; acPick = null;
        el.innerHTML = '';
        var o = document.createElement('div');
        o.className = 'st-ac-none';
        o.textContent = input.closest('[data-row]') && input.closest('form').querySelector('[data-np]')
            ? 'کالایی با این نام نیست — با «+» تعریفش کنید.' : 'کالایی با این نام یا کد نیست.';
        el.appendChild(o);
        el.hidden = false;
        input.setAttribute('aria-expanded', 'false');
        acPlace();
    }
    function acChoose(i) {
        var p = acItems[i], input = acFor, cb = acPick;
        acClose();
        if (p && input && cb) { cb(input, p); }
    }
    function priceKeyOf(input) {
        var f = input.closest('form');
        return f && f.dataset.price === 'buy' ? 'buy' : 'sell';
    }
    /** کلیدهای فهرست؛ true یعنی رویداد مصرف شد. */
    function acKey(e) {
        if (!ac || ac.hidden || acFor !== e.target) { return false; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!acItems.length) { return true; }
            e.preventDefault();
            acActive = (acActive + (e.key === 'ArrowDown' ? 1 : -1) + acItems.length) % acItems.length;
            acMark();
            return true;
        }
        if (e.key === 'Escape') { e.preventDefault(); acClose(); return true; }
        if (e.key === 'Enter' && acActive >= 0) { e.preventDefault(); acChoose(acActive); return true; }
        return false;
    }
    window.addEventListener('scroll', acPlace, { passive: true, capture: true });
    window.addEventListener('resize', acPlace);
    document.addEventListener('focusout', function (e) { if (acFor && e.target === acFor) { setTimeout(function () { if (document.activeElement !== acFor) { acClose(); } }, 120); } });
    // با جاوااسکریپت پاپ‌آپِ مرورگر برداشته می‌شود تا دو فهرست روی هم نیفتند
    if (dl) {
        document.querySelectorAll('input[list="' + dl.id + '"]').forEach(function (inp) {
            inp.removeAttribute('list');
            inp.setAttribute('role', 'combobox');
            inp.setAttribute('aria-autocomplete', 'list');
            inp.setAttribute('aria-expanded', 'false');
        });
    }
    window.stFold = fold;               // فقط برای تست (`search_probe.js`)
    window.stSuggest = suggest;

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
            balance(total);
        }

        // ---------- مانده‌ی قبلی / پس از این فاکتور — فقط پیش‌نمایش ----------
        // ⛔ عددِ «قبلی» را سرور در data-balance گذاشته (`BALANCE_SQL`)؛ اینجا
        //    فقط جمع می‌شود و هیچ عددی به سرور برنمی‌گردد.
        var balBox = form.querySelector('[data-balbox]'), party = form.querySelector('[data-party]');
        function sideOf(v) { return v > 0 ? 'بدهکار' : (v < 0 ? 'بستانکار' : 'تسویه'); }
        function balance(total) {
            if (!balBox || !party) { return; }
            var opt = party.options[party.selectedIndex];
            var has = opt && opt.value !== '0' && opt.dataset.balance !== undefined;
            balBox.hidden = !has;
            if (!has) { return; }
            var prev = parseInt(opt.dataset.balance, 10) || 0, sign = parseInt(balBox.dataset.sign, 10) || 1;
            var mode = form.querySelector('[data-paymode]:checked'), amt = form.querySelector('[data-payamount]');
            var now = !mode || mode.value === 'none' ? 0 : (mode.value === 'full' ? total : Math.min(money(amt ? amt.value : ''), total));
            var after = prev + sign * (total - now);
            balBox.querySelector('[data-bal-prev]').textContent = fmt(Math.abs(prev));
            balBox.querySelector('[data-bal-side]').textContent = sideOf(prev);
            balBox.querySelector('[data-bal-after]').textContent = fmt(Math.abs(after));
            balBox.querySelector('[data-after-side]').textContent = sideOf(after);
            var link = balBox.querySelector('[data-bal-link]');
            if (link) { link.href = link.href.replace(/party\.php.*$/, 'party.php?id=' + encodeURIComponent(opt.value)); }
        }
        form.addEventListener('change', function (e) { if (e.target.matches('[data-party],[data-paymode]')) { recalc(); } });

        // «شرحِ کالا»: گوشی → IMEI، بقیه → توضیح (توضیحِ نوشته‌شده هرگز پنهان نمی‌شود)
        function imeiBox(row, show) {
            var box = row.querySelector('[data-imei-box]'), note = field(row, 'note');
            if (!box) { return; }
            var filled = field(row, 'imei1').value.trim() !== '' || field(row, 'imei2').value.trim() !== '';
            box.hidden = !(show || filled);
            if (note) { note.hidden = !box.hidden && note.value.trim() === ''; }
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
            var nt = field(copy, 'note'); if (nt) { nt.hidden = false; }
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
        function pickRow(input, p) {
            var row = input.closest('[data-row]');
            apply(row, p);
            recalc();
            var box = row.querySelector('[data-imei-box]'), im = field(row, 'imei1');
            (box && !box.hidden && im && im.value.trim() === '' ? im : field(row, 'qty')).focus();
        }
        body.addEventListener('input', function (e) {
            var row = e.target.closest('[data-row]');
            if (row && e.target.matches('[data-item]')) {
                var p = find(e.target.value);
                // نامِ کامل یا IMEIِ کاملِ یک گوشیِ در انبار (اسکن)
                if (p && (p.name === e.target.value || p.imei1)) { apply(row, p); } else { field(row, 'pid').value = ''; }
                acOpen(e.target, pickRow);
            }
            recalc();
        });
        form.addEventListener('input', function (e) { if (e.target.matches('[data-discount],[data-extra],[data-payamount]')) { recalc(); } });

        // Enter داخلِ ردیف فرم را نمی‌فرستد؛ به خانه‌ی بعدی می‌رود
        body.addEventListener('keydown', function (e) {
            if (e.target.matches('[data-item]') && acKey(e)) { return; }
            if (e.key !== 'Enter' || !e.target.matches('input')) { return; }
            e.preventDefault();
            var row = e.target.closest('[data-row]');
            if (e.target.matches('[data-item]')) {
                // Enter روی متنِ نیمه‌کاره: اگر فقط یک پیشنهاد هست همان
                var hit = find(e.target.value), sug = hit ? [] : suggest(e.target.value);
                acClose();
                apply(row, hit || (sug.length === 1 ? sug[0] : null));
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
            scan.addEventListener('input', function () { scan.classList.remove('is-miss'); acOpen(scan, function (inp, p) { addScanned(p); scan.focus(); }); });
            scan.addEventListener('keydown', function (e) {
                if (acKey(e)) { return; }
                if (e.key !== 'Enter') { return; }
                e.preventDefault();
                acClose();
                var p = find(scan.value), sug = p ? [] : suggest(scan.value);
                if (!p && sug.length === 1) { p = sug[0]; }
                if (!p) { scan.classList.add('is-miss'); scan.select(); return; }
                addScanned(p);
            });
            function addScanned(p) {
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
            }
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

/* رنگِ فروشگاه: پیش‌نمایشِ فوری و ذخیره با همان فرم (بهبودِ تدریجی — بی‌اسکریپت
   هم دکمه‌ی «ذخیره‌ی رنگ» کار می‌کند). نام فقط از میانِ رادیوهای خودِ صفحه
   است، پس هیچ مقدارِ دلخواهی روی ویژگی نمی‌نشیند. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var radios = document.querySelectorAll('.st-pal input[name="palette"]');
        if (!radios.length) { return; }
        var first = radios[0].value;
        Array.prototype.forEach.call(radios, function (r) {
            r.addEventListener('change', function () {
                if (r.value === first) { document.documentElement.removeAttribute('data-st-palette'); }
                else { document.documentElement.setAttribute('data-st-palette', r.value); }
                if (r.form && r.form.requestSubmit) { r.form.requestSubmit(); }
            });
        });
    });
})();

/* مشخصاتِ چک در «دریافت/پرداخت»: فقط وقتی روش «چک» است دیده می‌شود.
   ⛔ بی‌اسکریپت همیشه دیده می‌شود (نه `hidden` در HTML)، پس نرسیدنِ این
   فایل فرمِ چک را بی‌صدا نمی‌بندد. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.querySelector('[data-cheque-method]');
        var box = document.querySelector('[data-cheque-fields]');
        if (!sel || !box) { return; }
        var sync = function () { box.style.display = sel.value === 'cheque' ? '' : 'none'; };
        sel.addEventListener('change', sync);
        sync();
    });
})();

/* «انتخابِ همه» — چک‌باکسِ `js-check-all` همه‌ی `[data-group]`ِ هم‌نامش را
   تیک می‌زند یا برمی‌دارد (پاک‌سازیِ کالاها). بی‌اسکریپت هر ردیف جدا تیک
   می‌خورد و فرم همان کار را می‌کند. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.js-check-all'), function (all) {
            var boxes = document.querySelectorAll('input[type="checkbox"][data-group="' + all.getAttribute('data-target') + '"]');
            var sync = function () {
                var on = 0;
                Array.prototype.forEach.call(boxes, function (b) { if (b.checked) { on++; } });
                all.checked = on === boxes.length;
                all.indeterminate = on > 0 && on < boxes.length;
            };
            all.addEventListener('change', function () {
                Array.prototype.forEach.call(boxes, function (b) { b.checked = all.checked; });
                sync();
            });
            Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', sync); });
            sync();
        });
    });
})();

/* طراحیِ فاکتور: پیش‌نمایشِ زنده. ⛔ فقط آدرسِ قاب عوض می‌شود
   (`print.php?…&preview=1`، فقط خواندنی)؛ ذخیره همچنان با دکمه‌ی فرم است و
   بی‌اسکریپت پیش‌نمایش طراحیِ ذخیره‌شده را نشان می‌دهد. توکنِ CSRF و
   `action` در آدرس نمی‌روند. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form  = document.querySelector('[data-design-form]');
        var frame = document.querySelector('[data-design-preview]');
        if (!form || !frame) { return; }
        var picker = form.querySelector('[data-accent-picker]');
        var custom = form.querySelector('[data-accent-custom]');
        if (picker && custom) {
            picker.addEventListener('input', function () { custom.value = picker.value; custom.checked = true; });
            picker.addEventListener('click', function () { custom.checked = true; });
        }
        var t = null;
        var refresh = function () {
            var q = new URLSearchParams();
            new FormData(form).forEach(function (v, k) {
                if (k !== 'csrf_token' && k !== 'action' && typeof v === 'string') { q.append(k, v); }
            });
            q.set('preview', '1');
            var base = frame.getAttribute('data-base');
            frame.src = base + (base.indexOf('?') >= 0 ? '&' : '?') + q.toString();
        };
        var later = function () { clearTimeout(t); t = setTimeout(refresh, 350); };
        form.addEventListener('input', later);
        form.addEventListener('change', later);
    });
})();
