/**
 * شمردنِ تپ‌های **لازم** برای ثبتِ یک تراکنش، در کرومیومِ واقعی.
 *
 * چرا با مرورگر و نه با خواندنِ HTML: فهرستِ دسته‌بندی و شبکه‌ی چیپ‌ها
 * را `app.js` می‌سازد، پس سورسِ صفحه چیزی درباره‌ی «چند تپ» نمی‌گوید.
 * همان درسِ همیشگیِ این پروژه: اندازه‌گیری، نه بازبینی.
 *
 * ⛔ هیچ وابستگیِ npm ندارد. کرومیوم را مستقیم با CDP می‌راند (node ≥ ۲۲
 *    خودش `WebSocket` و `fetch` دارد). یک `npm install` در این مخزن یعنی
 *    تستی که روی سرور اجرا نمی‌شود.
 *
 * ⚠ حدِ این اندازه‌گیری، صادقانه: `el.click()` می‌زند نه لمسِ مختصاتی.
 *   پس **تعدادِ تپِ لازم** را می‌سنجد، نه اینکه دکمه به اندازه‌ی انگشت
 *   بزرگ است یا زیرِ کیبورد می‌ماند. آن‌ها را باید روی گوشی دید.
 *
 * ⛔ هر گام شرطی است: «اگر شرط از قبل برقرار است، تپی خرج نکن». پس عدد
 *    از خودِ DOM درمی‌آید نه از یک سناریوی سخت‌کد — برگرداندنِ
 *    `required` روی عنوان یا برگشتِ پیش‌فرض به «درآمد»، عدد را بالا
 *    می‌برد و تست همان‌جا قرمز می‌شود.
 *
 * اجرا: node tap_probe.js <baseUrl> <cookieName> <cookieValue>
 * خروجی: یک خط JSON روی stdout.
 */
'use strict';

const { spawn } = require('child_process');

const BINS = [
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    process.env.CHROME_BIN || '',
];

const baseUrl = process.argv[2];
const cookieName = process.argv[3];
const cookieValue = process.argv[4];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };

const fs = require('fs');
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

(async () => {
    const port = 9000 + Math.floor((process.pid % 900));
    const proc = spawn(bin, [
        '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--disable-extensions', '--no-first-run', '--mute-audio',
        `--remote-debugging-port=${port}`, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'ignore'] });

    const done = (payload) => {
        process.stdout.write(JSON.stringify(payload) + '\n');
        try { proc.kill('SIGKILL'); } catch (e) {}
        process.exit(0);
    };

    let ver = null;
    for (let i = 0; i < 60; i++) {
        await sleep(150);
        try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) {}
    }
    if (!ver) { done({ ok: false, why: 'chromium_no_start' }); }

    const ws = new WebSocket(ver.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.addEventListener('open', r); ws.addEventListener('error', j); });

    let id = 0;
    const pending = new Map();
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id;
        pending.set(i, res);
        ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const s = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
    const sid = s.result.sessionId;

    await send('Runtime.enable', {}, sid);
    await send('Page.enable', {}, sid);
    await send('Network.enable', {}, sid);

    const u = new URL(baseUrl);
    await send('Network.setCookie', {
        name: cookieName, value: cookieValue, domain: u.hostname, path: '/',
    }, sid);

    // ارزیابیِ یک عبارت و برگرداندنِ مقدارش. خطای داخلِ صفحه همین‌جا
    // بیرون می‌آید، نه به شکلِ یک عددِ غلط.
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', {
            expression: `(function(){${expr}})()`,
            returnByValue: true, awaitPromise: true,
        }, sid);
        if (r.result && r.result.exceptionDetails) {
            done({ ok: false, why: 'js_error', detail: String(r.result.exceptionDetails.text || '')
                + ' ' + String((r.result.exceptionDetails.exception || {}).description || '') });
        }
        return r.result.result.value;
    };

    await send('Page.navigate', { url: baseUrl + 'index.php' }, sid);
    for (let i = 0; i < 60; i++) {
        await sleep(200);
        const ready = await evalJs('return document.readyState === "complete" && !!document.getElementById("addTxSheet");');
        if (ready) { break; }
    }

    const loggedIn = await evalJs('return !!document.querySelector(".js-add-tx");');
    if (!loggedIn) { done({ ok: false, why: 'not_logged_in' }); }

    /**
     * سناریوی «یک هزینه ثبت کن» — هر گام فقط اگر لازم باشد تپ می‌کند.
     * `want` نوعِ هدف است و `catName` نامِ دسته‌ی هدف.
     */
    const run = (want, catName, amount) => `
        var taps = 0, notes = [];
        function tap(el, why) { if (!el) { notes.push("missing:" + why); return false; }
            el.click(); taps++; notes.push(why); return true; }

        var sheet = document.getElementById('addTxSheet');
        if (!sheet.classList.contains('show')) { tap(document.querySelector('.js-add-tx'), 'open'); }

        var amt = document.getElementById('amount');
        if (document.activeElement !== amt) { tap(amt, 'focus-amount'); }
        amt.value = ${JSON.stringify(amount)};
        amt.dispatchEvent(new Event('input', { bubbles: true }));

        var hidden = document.getElementById('transactionType');
        if (hidden.value !== ${JSON.stringify(want)}) {
            // ⚠ انتخابگر باید به **همین** تاگل بسته باشد: مودالِ ویرایش
            //   هم «type-btn» دارد و در index.php **زودتر** رندر می‌شود،
            //   پس querySelector بی‌قید دکمه‌ی آن یکی را می‌زد — نوع عوض
            //   نمی‌شد و probe بی‌صدا عددِ غلط می‌داد.
            //   ⚠ و بک‌تیک اینجا ننویسید: این متن داخلِ یک template
            //   literal است و بک‌تیک رشته را می‌بندد — همان دامِ قاعده ۳۶،
            //   این بار در جاوااسکریپت.
            tap(document.querySelector('#typeToggle .type-btn[data-type="' + ${JSON.stringify(want)} + '"]'),
                'switch-type');
        }

        var sel = document.getElementById('category_id');
        var grid = document.getElementById('catGrid');
        var chips = grid ? Array.prototype.slice.call(grid.querySelectorAll('.cat-chip')) : [];
        var chip = chips.filter(function (c) {
            return c.querySelector('.cat-chip-name').textContent === ${JSON.stringify(catName)};
        })[0];
        if (chip) {
            tap(chip, 'chip');
        } else {
            // مسیرِ بدون شبکه: باز کردنِ منو و انتخابِ گزینه — دو تپ.
            taps += 2; notes.push('select-open', 'select-pick');
            var hit = false;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].textContent === ${JSON.stringify(catName)}) {
                    sel.selectedIndex = i; hit = true; break;
                }
            }
            // ⛔ اگر گزینه پیدا نشد، دو تپِ بالا برای کاری خرج شده‌اند که
            //    انجام نشد — یعنی عددِ probe دروغ می‌شود. صریح ثبتش کن.
            if (!hit) { notes.push('select-miss'); }
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        var title = document.getElementById('title');
        if (title.required && !title.value) { tap(title, 'focus-title'); title.value = 'x'; }

        var form = document.getElementById('quickAddForm');
        var valid = form.checkValidity();

        // نشانه‌ی «هنوز همان صفحه‌ایم» — مسیرِ موفق صفحه را تازه می‌کند و
        // این از بین می‌رود. تنها راهِ مطمئنِ تشخیصِ ثبت از سمتِ مرورگر.
        window.__tapMark = 1;
        tap(document.getElementById('submitBtn'), 'submit');

        return {
            taps: taps, notes: notes, valid: valid,
            chipCount: chips.length,
            chipUsed: !!chip,
            categoryValue: sel.value,
            typeValue: hidden.value,
            titleRequired: !!title.required,
            requiredIds: Array.prototype.slice.call(form.querySelectorAll('[required]'))
                .map(function (e) { return e.id || e.name; }),
        };
    `;

    // ---- وضعیتِ اولیه: پیش از هر تپ ----
    const initial = await evalJs(`
        var hidden = document.getElementById('transactionType');
        var active = document.querySelector('#typeToggle .type-btn.active');
        return {
            defaultType: hidden ? hidden.value : null,
            activeType: active ? active.getAttribute('data-type') : null,
            sheetOpen: document.getElementById('addTxSheet').classList.contains('show'),
        };
    `);

    // ---- سناریوی هزینه (۷۳٪ ردیف‌ها) ----
    const catName = await evalJs(`
        var d = window.CATEGORY_DATA || {};
        return (d.expense && d.expense[0]) ? d.expense[0].name : null;
    `);
    if (!catName) { done({ ok: false, why: 'no_expense_category' }); }

    // فوکوسِ خودکار پس از باز شدنِ شیت — **پیش از** سناریو سنجیده
    // می‌شود، چون اگر برود، همان یک تپِ اضافه در عددِ پایین هم دیده
    // می‌شود ولی علتش نه.
    await evalJs(`document.querySelector('.js-add-tx').click(); return true;`);
    await sleep(300);
    const focused = await evalJs('return document.activeElement ? (document.activeElement.id || "") : "";');
    await evalJs(`document.getElementById('addTxSheet').classList.remove('show'); return true;`);

    const expense = await evalJs(run('expense', catName, '123000'));

    // ---- «شبیه‌سازی شد» با «ثبت شد» یکی نیست ----
    //
    // ⚠ مسیرِ موفق صفحه را **تازه می‌کند** (جمع‌ها و موجودی سمتِ سرور
    //   رندر شده‌اند)، پس `#formMessage` هرگز دیده نمی‌شود: یا پنهان
    //   است، یا سندِ تازه‌ای آمده. تنها نشانه‌ی قابل اتکا رفتنِ
    //   `window.__tapMark` است. مدرکِ نهایی هم ردیفِ دیتابیس است که
    //   سمتِ PHP سنجیده می‌شود.
    const waitSaved = async () => {
        for (let i = 0; i < 60; i++) {
            await sleep(200);
            const r = await evalJs(`
                if (typeof window.__tapMark === 'undefined') { return { ok: true, text: 'صفحه تازه شد' }; }
                var m = document.getElementById('formMessage');
                if (m && !m.hidden && !m.classList.contains('success')) {
                    return { ok: false, text: (m.textContent || '').slice(0, 120) };
                }
                return null;
            `);
            if (r) { return r; }
        }
        return { ok: false, text: 'نه صفحه تازه شد نه پیامی آمد' };
    };
    const saved = await waitSaved();

    // ---- سناریوی درآمد: باید دقیقاً یک تپ بیشتر باشد ----
    await send('Page.navigate', { url: baseUrl + 'index.php' }, sid);
    for (let i = 0; i < 60; i++) {
        await sleep(200);
        const ready = await evalJs('return document.readyState === "complete" && !!document.getElementById("addTxSheet");');
        if (ready) { break; }
    }
    const incName = await evalJs(`
        var d = window.CATEGORY_DATA || {};
        return (d.income && d.income[0]) ? d.income[0].name : null;
    `);
    const income = incName ? await evalJs(run('income', incName, '55000')) : null;
    const savedIncome = income ? await waitSaved() : null;

    done({ ok: true, initial, expense, income, focused, saved, savedIncome, catName, incName });
})().catch((e) => fail('probe_crash:' + (e && e.message ? e.message : String(e))));
