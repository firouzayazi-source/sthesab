/**
 * صفِ پیامکِ بانک در کرومیومِ واقعی — «هیچ چیزی باز نمی‌شود».
 *
 * چرا مرورگر: تصمیمِ «ثبت کن / برای بررسی نگه دار / کنار بگذار»، ثبت با
 * `fetch`، هم‌ترازیِ مانده، تازه‌سازی و نوارِ «لغو» همه در `app.js` اند و
 * هیچ‌کدام در سورسِ صفحه دیده نمی‌شوند.
 *
 * ⛔ هیچ وابستگیِ npm ندارد — همان الگوی `tap_probe.js`.
 *
 * اجرا: node sms_batch_probe.js <baseUrl> <cookieName> <cookieValue> <smsqJson>
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

const baseUrl = process.argv[2];
const cookieName = process.argv[3];
const cookieValue = process.argv[4];
const smsq = process.argv[5];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };

const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

(async () => {
    const port = 9400 + Math.floor((process.pid % 500));
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
        for (let i = 0; i < ms / 200; i++) {
            await sleep(200);
            let v = null;
            try { v = await evalJs(cond); } catch (e) { v = null; }
            if (v) { return v; }
        }
        return null;
    };
    const state = () => evalJs(`
        var sheet = document.getElementById('addTxSheet');
        var bar = document.querySelector('.undo-bar');
        var slot = document.getElementById('smsPendingSlot');
        var pend = []; try { pend = JSON.parse(localStorage.getItem('daftar_sms_pending') || '[]'); } catch (e) {}
        return {
            path: location.pathname, hash: location.hash,
            sheetOpen: !!(sheet && sheet.classList.contains('show')),
            bar: bar ? bar.textContent : '',
            barUndo: !!(bar && bar.querySelector('.undo-bar-undo')),
            slotShown: !!(slot && !slot.hidden && slot.textContent.trim() !== ''),
            slotText: slot ? slot.textContent : '',
            flash: !!document.getElementById('smsPendingFlash'),
            pending: pend.length,
            ready: document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading'),
        };`);

    const out = { ok: true };

    // ۱ — صفحه‌ی حساب‌ها، همان جایی که صفِ قبلی هیچ کاری را نمی‌گذاشت.
    await send('Page.navigate', { url: baseUrl + 'wallets.php#smsq=' + encodeURIComponent(smsq) }, sid);
    // ثبت + هم‌ترازی + تازه‌سازی؛ بعد از تازه‌سازی نوار دیده می‌شود.
    const got = await waitFor(`var b = document.querySelector('.undo-bar'); return b ? b.textContent : null;`, 15000);
    await sleep(400);
    out.afterBatch = await state();
    out.barSeen = got;

    // ۱ب — نوبتی که **فقط** پیامکِ نامطمئن دارد: هیچ ثبتی، پس هیچ
    //      تازه‌سازی‌ای — شیتی که باز شود همین‌جا می‌ماند و دیده می‌شود.
    //      (بدونِ این گام، جهشِ «شیت را برای پیامکِ نامطمئن باز کن» زنده
    //      ماند: تازه‌سازیِ بعد از ثبت، شیتِ باز را می‌بست.)
    const onlyPending = JSON.stringify(['واریز 70,000\nمانده 1,000,000']);
    await send('Page.navigate', { url: baseUrl + 'budget.php#smsq=' + encodeURIComponent(onlyPending) }, sid);
    await waitFor(`return document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading');`, 10000);
    await sleep(1500);
    out.pendingOnly = await state();

    // ۲ — خانه: کارتِ «برای بررسی».
    await send('Page.navigate', { url: baseUrl + 'index.php' }, sid);
    await waitFor(`return document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading');`, 10000);
    await sleep(400);
    out.home = await state();

    // ۳ — «رد»: از فهرست بیرون و کارت پنهان.
    await evalJs(`var b = document.querySelectorAll('#smsPendingSlot .sms-review-actions button');
                  for (var i = 0; i < b.length; i++) { if (b[i].textContent.trim() === 'رد') { b[i].click(); } } return 1;`);
    await sleep(300);
    out.afterReject = await state();

    // ۴ — همان فرگمنت دوباره (تپِ دوباره روی اعلان): هیچ ثبتِ تازه‌ای.
    await send('Page.navigate', { url: baseUrl + 'transactions.php#smsq=' + encodeURIComponent(smsq) }, sid);
    await waitFor(`return document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading');`, 10000);
    await sleep(2500);
    out.again = await state();

    done(out);
})().catch((e) => fail('probe_threw: ' + e.message));
