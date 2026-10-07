/**
 * رفتارهای کوچکِ `app.js` که فقط در مرورگر دیده می‌شوند — در کرومیومِ واقعی،
 * روی همان صفحه‌ها، با همان نشست. هر گام یک باگِ واقعیِ گذشته است:
 *
 *   dash/search  — «ویرایش» و «حذف»ِ ردیفِ تراکنش در گزارش (جزئیاتِ روزِ
 *                  تقویم) و جست‌وجو. توکنِ CSRF فقط از متا خوانده می‌شد و
 *                  این دو صفحه متا نداشتند (۴۰۳)؛ مودالِ ویرایش هم نبود.
 *   support      — پاسخِ آماده‌ی پشتیبانی پشتِ `return`ِ بلوکِ معاملات بود.
 *   recurring / transfer / budget — «جدید» بعد از یک ویرایش تاریخ (و حساب‌ها)ی
 *                  همان ردیف را نگه می‌داشت.
 *   csv          — متنِ خامِ خانه‌ی CSV در پیامِ خطا به `innerHTML` می‌رفت (XSS)؛
 *                  و تاریخِ شمسی با همان الگوریتمِ تقویم به میلادی می‌رود.
 *   plan         — خلاصه‌ی اقساطِ یادآور با ارقامِ فارسی.
 *   digits       — فرمت‌کننده‌ی مبلغ ارقامِ عربی (٠-٩) را پاک نمی‌کند.
 *
 * ⛔ هیچ وابستگیِ npm ندارد — همان الگوی `tap_probe.js`.
 * اجرا: node js_regress_probe.js <baseUrl> <cookieName> <cookieValue> <configJson>
 * خروجی: یک خط JSON روی stdout.
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    process.env.CHROME_BIN || '',
];

const [baseUrl, cookieName, cookieValue, cfgJson] = process.argv.slice(2);
const cfg = JSON.parse(cfgJson || '{}');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };

const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

(async () => {
    const port = 9100 + Math.floor((process.pid % 700));
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
    setTimeout(() => done({ ok: false, why: 'timeout' }), 150000);

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
        // ⚠ alert/confirm هرگز صفحه را نگه نمی‌دارد؛ متنش ثبت می‌شود.
        if (m.method === 'Page.javascriptDialogOpening') {
            dialogs.push(m.params.message);
            send('Page.handleJavaScriptDialog', { accept: true }, m.sessionId);
        }
    });
    const dialogs = [];
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id;
        pending.set(i, res);
        ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const sid = (await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true })).result.sessionId;
    await send('Runtime.enable', {}, sid);
    await send('Page.enable', {}, sid);
    await send('Network.enable', {}, sid);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }, sid);
    const u = new URL(baseUrl);
    await send('Network.setCookie', { name: cookieName, value: cookieValue, domain: u.hostname, path: '/' }, sid);

    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', {
            expression: `(function(){${expr}})()`, returnByValue: true, awaitPromise: true,
        }, sid);
        if (r.result && r.result.exceptionDetails) {
            done({ ok: false, why: 'js_error', detail: String((r.result.exceptionDetails.exception || {}).description || r.result.exceptionDetails.text || '') });
        }
        return r.result.result.value;
    };
    const waitFor = async (cond, ms) => {
        for (let i = 0; i < ms / 150; i++) {
            await sleep(150);
            let v = null;
            try { v = await evalJs(cond); } catch (e) { v = null; }
            if (v) { return v; }
        }
        return null;
    };
    const READY = `return document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading') && window.__probeMark !== 1;`;
    const go = async (path) => {
        await send('Page.navigate', { url: baseUrl + path }, sid);
        await sleep(200);
        await waitFor(READY, 15000);
        await sleep(250);
    };
    // نشانه پیش از کاری که باید صفحه را تازه کند؛ تازه‌سازی پاکش می‌کند.
    const mark = () => evalJs('window.__probeMark = 1; return 1;');
    const reloaded = async () => !!(await waitFor(`return window.__probeMark !== 1 && document.readyState === 'complete';`, 8000));

    const out = { ok: true };
    const q = (s) => JSON.stringify(s);

    // ویرایشِ یک ردیف از مودالِ مشترک: مبلغ را عوض و ذخیره کن.
    const editRow = async (scope, txId, amount) => {
        const r = await evalJs(`
            var b = document.querySelector(${q(scope)} + ' .js-edit-tx[data-id="${txId}"]');
            if (!b) { return { btn: false }; }
            b.click();
            var m = document.getElementById('editTxModal');
            return { btn: true, modal: !!m, shown: !!(m && m.classList.contains('show')),
                     id: (document.getElementById('edit_transaction_id') || {}).value || '' };`);
        if (!r.shown) { return r; }
        await mark();
        await evalJs(`document.getElementById('edit_amount').value = ${q(String(amount))};
                      document.getElementById('editTxForm').requestSubmit(); return 1;`);
        r.reloaded = await reloaded();
        return r;
    };
    const deleteRow = async (scope, txId) => {
        await mark();
        const found = await evalJs(`var b = document.querySelector(${q(scope)} + ' .js-delete-tx[data-id="${txId}"]');
                                    if (b) { b.click(); } return !!b;`);
        return { btn: found, reloaded: found ? await reloaded() : false };
    };

    // ── ۱. گزارش (dashboard.php) — جزئیاتِ روزِ تقویم روی گوشی ──
    const openDay = async () => {
        await waitFor(`return !!document.querySelector('#cal .js-hcal-day[data-date="${cfg.today}"]')
                       && !document.querySelector('#cal .hcal-inner.is-loading');`, 8000);
        await sleep(300);
        await evalJs(`document.querySelector('#cal .js-hcal-day[data-date="${cfg.today}"]').click(); return 1;`);
        return !!(await waitFor(`return !!document.querySelector('#cal .hcal-day-body .js-delete-tx');`, 8000));
    };
    await go('dashboard.php');
    out.dash = { day: await openDay() };
    out.dash.edit = await editRow('#cal .hcal-day-body', cfg.dashEdit, '۷۷٬۷۰۰');
    await go('dashboard.php');
    await openDay();
    out.dash.del = await deleteRow('#cal .hcal-day-body', cfg.dashDel);

    // ── ۲. جست‌وجو ──
    await go('search.php?q=' + encodeURIComponent(cfg.searchQ));
    out.search = { edit: await editRow('.page-content', cfg.searchEdit, '۸۸٬۸۰۰') };
    await go('search.php?q=' + encodeURIComponent(cfg.searchQ));
    out.search.del = await deleteRow('.page-content', cfg.searchDel);

    // ── ۳. پاسخِ آماده‌ی پشتیبانی ──
    if (cfg.ticket) {
        await go('admin/support.php?f=all&t=' + cfg.ticket);
        out.support = await evalJs(`
            var sel = document.getElementById('supCanned'), box = document.getElementById('supAdminReply');
            if (!sel || !box) { return { sel: !!sel, box: !!box }; }
            box.value = 'سلام،';
            sel.value = ${q(String(cfg.canned))};
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            return { sel: true, box: true, text: box.value, reset: sel.selectedIndex };`);
    }

    // ── ۴. «جدید» بعد از ویرایش ──
    await go('recurring.php');
    out.recurring = await evalJs(`
        var e = document.querySelector('.js-edit-recurring[data-id="${cfg.recurring}"]');
        var a = document.querySelector('.js-add-recurring');
        if (!e || !a) { return { found: false }; }
        e.click();
        var editEnd = document.getElementById('recurring_end').value;
        if (window.closeModal) { window.closeModal('recurringModal'); }
        a.click();
        return { found: true, editEnd: editEnd,
                 start: document.getElementById('recurring_start').value,
                 end: document.getElementById('recurring_end').value,
                 endShown: document.getElementById('recurring_end_display').value,
                 startShown: document.getElementById('recurring_start_display').value,
                 startShownDefault: document.getElementById('recurring_start_display').defaultValue };`);

    await go('wallets.php');
    out.transfer = await evalJs(`
        var e = document.querySelector('.js-edit-transfer[data-id="${cfg.transfer}"]');
        var a = document.querySelector('.js-add-transfer');
        if (!e || !a) { return { found: false }; }
        var f = document.getElementById('transfer_from'), t = document.getElementById('transfer_to');
        var def = { from: f.value, to: t.value };
        e.click();
        var edited = { from: f.value, to: t.value, date: document.getElementById('transfer_date').value };
        if (window.closeModal) { window.closeModal('transferModal'); }
        a.click();
        return { found: true, def: def, edited: edited, from: f.value, to: t.value,
                 date: document.getElementById('transfer_date').value,
                 shown: document.getElementById('transfer_date_display').value,
                 shownDefault: document.getElementById('transfer_date_display').defaultValue };`);

    await go('budget.php');
    out.budget = await evalJs(`
        var e = document.querySelector('.js-edit-budget[data-id="${cfg.budget}"]');
        var a = document.querySelector('.js-add-budget');
        if (!e || !a) { return { found: false }; }
        e.click();
        var editStart = document.getElementById('budget_start').value;
        if (window.closeModal) { window.closeModal('budgetModal'); }
        a.click();
        return { found: true, editStart: editStart,
                 start: document.getElementById('budget_start').value,
                 end: document.getElementById('budget_end').value,
                 startShown: document.getElementById('budget_start_display').value };`);

    // ── ۵. ورود از فایل: XSS در پیامِ خطا + تاریخِ شمسی ──
    await go('data.php');
    await mark();
    const up = await evalJs(`
        var inp = document.getElementById('csvFile');
        if (!inp) { return false; }
        var dt = new DataTransfer();
        dt.items.add(new File([${q(cfg.csv)}], 'probe.csv', { type: 'text/csv' }));
        inp.files = dt.files;
        inp.form.submit();
        return true;`);
    out.csv = { uploaded: up };
    if (up) {
        await reloaded();
        await waitFor(READY, 10000);
        out.csv.preview = await evalJs(`
            window.__xss = 0;
            var b = document.getElementById('previewBtn');
            if (!b) { return { btn: false }; }
            b.click();
            return { btn: true };`);
        await sleep(600);
        Object.assign(out.csv, await evalJs(`
            var s = document.getElementById('importSummary'), t = document.getElementById('previewTable');
            return { xss: window.__xss === 1, imgs: s ? s.querySelectorAll('img').length : -1,
                     summary: s ? s.innerHTML : '', table: t ? t.textContent : '' };`));
    }

    // ── ۶. خلاصه‌ی اقساطِ یادآور با ارقامِ فارسی ──
    await go('due.php?t=reminders');
    out.plan = await evalJs(`
        var chip = document.querySelector('#rm_repeat_chips .stay-chip[data-key="m1"]');
        var cnt = document.getElementById('rm_count'), amt = document.getElementById('rm_amount');
        var note = document.getElementById('rm_plan_note');
        if (!chip || !cnt || !amt || !note) { return { found: false }; }
        chip.click();
        cnt.value = '12'; cnt.dispatchEvent(new Event('input', { bubbles: true }));
        amt.value = '۱۲۰۰۰۰۰'; amt.dispatchEvent(new Event('input', { bubbles: true }));
        return { found: true, hidden: note.hidden, text: note.textContent };`);

    // ── ۷. فرمت‌کننده‌ی مبلغ با ارقامِ عربی ──
    out.digits = await evalJs(`
        var a = document.getElementById('amount');
        if (!a) { return null; }
        a.value = '١٢٣٤'; a.dispatchEvent(new Event('input', { bubbles: true }));
        var arabic = a.value;
        a.value = '۵۶۷۸'; a.dispatchEvent(new Event('input', { bubbles: true }));
        return { arabic: arabic, persian: a.value };`);

    out.dialogs = dialogs;
    done(out);
})().catch((e) => fail('probe_threw: ' + e.message));
