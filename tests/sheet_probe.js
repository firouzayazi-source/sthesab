/**
 * شیتِ ثبتِ تراکنش روی گوشی — در کرومیومِ واقعی با لمسِ واقعی (CDP).
 *
 * **گزارشِ مالکِ نصب (اپ اندروید، مهر ۱۴۰۵):** «میخوام ثبت هزینه یا درآمد
 * بکنم این منو که میاد بالا دیگه با دست من بالا پایین نمیشه … انگار صفحه
 * بسته میشه و خیلی سخت از اونجا میتونم بیام بیرون.»
 *
 * دو علت، هر دو فقط روی گوشی:
 *   ۱. کیبورد (مبلغ با باز شدن فوکوس می‌گیرد) فقط viewport **دیداری** را
 *      کوتاه می‌کند؛ لایه‌ی `fixed` قدِ کامل می‌ماند و پایینِ شیت زیرِ کیبورد
 *      بود. اینجا کیبورد با یک `visualViewport`ِ ساختگی شبیه‌سازی می‌شود —
 *      همان چیزی که کرومِ اندروید (`resizes-visual`) به صفحه می‌دهد.
 *   ۲. «برگشت»ِ اندروید صفحه را می‌برد، نه شیت را.
 *
 * ⛔ بی‌وابستگیِ npm — همان الگوی `intro_probe.js`.
 * اجرا: node sheet_probe.js <baseUrl> <cookieName> <cookieValue>
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    process.env.CHROME_BIN || '',
];
const [baseUrl, cookieName, cookieValue] = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

const W = 412, H = 839, KB = 330;

(async () => {
    const port = 9300 + Math.floor((process.pid % 600));
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
    await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 2.6, mobile: true }, sid);
    await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, sid);
    // ⚠ کیبوردِ اندروید: viewport دیداری کوتاه، viewport چیدمان دست‌نخورده.
    await send('Page.addScriptToEvaluateOnNewDocument', { source: `
        (function () {
            var t = new EventTarget(), h = null;
            Object.defineProperty(t, 'height', { get: function () { return h === null ? window.innerHeight : h; } });
            Object.defineProperty(t, 'width', { get: function () { return window.innerWidth; } });
            Object.defineProperty(t, 'offsetTop', { get: function () { return 0; } });
            window.__kb = function (px) { h = px === null ? null : window.innerHeight - px; t.dispatchEvent(new Event('resize')); };
            Object.defineProperty(window, 'visualViewport', { get: function () { return t; } });
        })();` }, sid);

    const u = new URL(baseUrl);
    await send('Network.setCookie', { name: cookieName, value: cookieValue, domain: u.hostname, path: '/' }, sid);

    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', {
            expression: `(function(){${expr}})()`, returnByValue: true, awaitPromise: true,
        }, sid);
        if (r.result && r.result.exceptionDetails) {
            done({ ok: false, why: 'js_error', detail: String((r.result.exceptionDetails.exception || {}).description || r.result.exceptionDetails.text) });
        }
        return r.result.result.value;
    };
    const ready = async () => {
        for (let i = 0; i < 60; i++) {
            await sleep(200);
            const ok = await evalJs('return document.readyState === "complete"'
                + ' && !document.documentElement.classList.contains("js-loading");').catch(() => false);
            if (ok) { return true; }
        }
        return false;
    };
    const go = async (path) => {
        await send('Page.navigate', { url: baseUrl + path }, sid);
        return ready();
    };
    const swipe = async (x, y0, dy) => {
        await send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: y0 }] }, sid);
        for (let i = 1; i <= 12; i++) {
            await send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: y0 + dy * i / 12 }] }, sid);
            await sleep(16);
        }
        await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }, sid);
        await sleep(350);
    };
    const isOpen = 'return document.getElementById("addTxSheet").classList.contains("show");';
    const openSheet = 'document.querySelector(".bottom-nav .js-add-tx").click(); return 1;';
    const path = 'return location.pathname.replace(/^.*\\//, "");';
    const ours = 'return !!(history.state && history.state[window.OVERLAY_STATE || "hlOverlay"]);';

    if (!(await go('transactions.php')) || !(await go('index.php'))) { done({ ok: false, why: 'page_not_ready' }); }
    if (!(await evalJs('return !!document.querySelector(".bottom-nav .js-add-tx");'))) { done({ ok: false, why: 'not_logged_in' }); }

    const out = { ok: true };

    // ---------- ۱. کیبورد ----------
    const h0 = await evalJs('return history.length;');
    await evalJs(openSheet); await sleep(500);
    out.opened = await evalJs(isOpen);
    out.pushed = (await evalJs('return history.length;')) === h0 + 1 && await evalJs(ours);
    await evalJs(`window.__kb(${KB}); return 1;`); await sleep(200);
    out.kbOverlayH = await evalJs('return Math.round(document.getElementById("addTxSheet").getBoundingClientRect().height);');
    out.kbSheetFits = await evalJs(`var s = document.querySelector("#addTxSheet .sheet").getBoundingClientRect();
        return s.top >= 0 && s.bottom <= ${H - KB} + 1;`);
    for (let i = 0; i < 4; i++) { await swipe(W / 2, 400, -300); }
    out.kbSubmitReached = await evalJs(`var r = document.getElementById("submitBtn").getBoundingClientRect();
        return r.top >= 0 && r.bottom <= ${H - KB} + 1;`);
    await evalJs('window.__kb(null); return 1;'); await sleep(150);
    out.kbRestored = await evalJs('var o = document.getElementById("addTxSheet").style; return o.height === "" && o.top === "";');

    // ---------- ۲. «برگشت» شیت را می‌بندد، صفحه می‌ماند ----------
    await evalJs('history.back(); return 1;'); await sleep(500);
    out.backClosed = !(await evalJs(isOpen)) && (await evalJs(path)) === 'index.php';
    await evalJs('history.back(); return 1;'); await sleep(300); await ready();
    out.secondBackLeaves = (await evalJs(path)) === 'transactions.php';

    // ---------- ۳. × خانه‌ی تاریخچه را برمی‌دارد ----------
    if (!(await go('index.php'))) { done({ ok: false, why: 'page_not_ready_2' }); }
    const h1 = await evalJs('return history.length;');
    await evalJs(openSheet); await sleep(400);
    await evalJs('document.querySelector("#addTxSheet [data-sheet-close]").click(); return 1;'); await sleep(500);
    out.closeConsumes = !(await evalJs(isOpen)) && !(await evalJs(ours));
    await evalJs('history.back(); return 1;'); await sleep(300); await ready();
    out.closeThenBackLeaves = (await evalJs(path)) === 'transactions.php';
    out.h1 = h1;

    // ---------- ۴. کشیدنِ سرِ شیت به پایین می‌بندد؛ کشیدنِ بدنه نه ----------
    if (!(await go('index.php'))) { done({ ok: false, why: 'page_not_ready_3' }); }
    await evalJs(openSheet); await sleep(500);
    await swipe(W / 2, 600, 200); await sleep(150);
    out.bodySwipeKeeps = await evalJs(isOpen);
    const hb = await evalJs('var r = document.querySelector("#addTxSheet .sheet-head").getBoundingClientRect(); return [r.left + 60, r.top + r.height / 2];');
    await swipe(hb[0], hb[1], 140);
    out.headSwipeCloses = !(await evalJs(isOpen));
    await sleep(300);

    // ---------- ۵. ثبت با شیتِ باز → تازه‌سازی → یک «برگشت» صفحه را می‌برد ----------
    await evalJs(openSheet); await sleep(400);
    await evalJs('window.__before = 1; var a = document.getElementById("amount"); a.value = "12000";'
        + ' a.dispatchEvent(new Event("input", { bubbles: true }));'
        + ' document.getElementById("submitBtn").click(); return 1;');
    let reloaded = false;
    for (let i = 0; i < 40 && !reloaded; i++) {
        await sleep(250);
        reloaded = await evalJs('return typeof window.__before === "undefined" && document.readyState === "complete";').catch(() => false);
    }
    await ready();
    out.saveReloaded = reloaded;
    out.afterSaveNotOurs = !(await evalJs(ours));
    await evalJs('history.back(); return 1;'); await sleep(300); await ready();
    out.afterSaveBackLeaves = (await evalJs(path)) === 'transactions.php';

    // ---------- ۶. ⛔ لایه به لایه: دکمه‌های کارتِ حساب ----------
    // گزارشِ مالکِ نصب: «تعدیل حساب نمی‌شه کرد». `closeModal(کارت)` و بلافاصله
    // `openModal(بعدی)`: ناظرِ لایه‌ی بسته `history.back()` می‌زد و `popstate`ِ
    // دیررس لایه‌ی **تازه** را می‌بست. هر دکمه یک بار، با مکث تا رسیدنِ آن popstate.
    const isShown = (id) => `var e = document.getElementById(${JSON.stringify(id)}); return !!(e && e.classList.contains("show"));`;
    const swap = {};
    for (const [btn, modal] of [['bcAdjustBtn', 'adjustWalletModal'], ['bcMergeBtn', 'mergeWalletModal'], ['bcEditBtn', 'walletModal']]) {
        if (!(await go('wallets.php'))) { done({ ok: false, why: 'wallets_not_ready' }); }
        await evalJs('var c = Array.prototype.find.call(document.querySelectorAll(".js-show-card"), function (e) { return e.offsetParent !== null; }); c.click(); return 1;');
        await sleep(400);
        await evalJs(`document.getElementById(${JSON.stringify(btn)}).click(); return 1;`);
        await sleep(600);
        const open = await evalJs(isShown(modal)), cardGone = !(await evalJs(isShown('bankCardModal')));
        // ⚠ نه با `history.length`: کروم آن را روی ۵۰ می‌بندد و این probe تا اینجا
        //   بیش از آن رفته است. «یک خانه» یعنی: الان خانه‌ی لایه هست، و بعد از یک
        //   «برگشت» دیگر نیست (دو خانه‌ی لایه یعنی برگشتِ دوم هم روی همین صفحه).
        const marked = await evalJs(ours);
        await evalJs('history.back(); return 1;'); await sleep(500);
        const backClosed = !(await evalJs(isShown(modal))) && (await evalJs(path)) === 'wallets.php';
        const oneEntry = marked && !(await evalJs(ours));
        swap[btn] = { open, cardGone, oneEntry, backClosed };
    }
    out.swap = swap;

    // ---------- ۷. تعدیلِ واقعی از همان راه: مبلغ به سرور می‌رسد ----------
    if (!(await go('wallets.php'))) { done({ ok: false, why: 'wallets_not_ready_2' }); }
    await evalJs('window.__before = 1; var c = Array.prototype.find.call(document.querySelectorAll(".js-show-card"), function (e) { return e.offsetParent !== null; }); c.click(); return 1;');
    await sleep(400);
    await evalJs('document.getElementById("bcAdjustBtn").click(); return 1;'); await sleep(600);
    await evalJs('var a = document.getElementById("adjust_amount"); a.value = "7000"; a.dispatchEvent(new Event("input", { bubbles: true }));'
        + ' document.getElementById("adjustWalletSubmitBtn").click(); return 1;');
    let adjReloaded = false;
    for (let i = 0; i < 40 && !adjReloaded; i++) {
        await sleep(250);
        adjReloaded = await evalJs('return typeof window.__before === "undefined" && document.readyState === "complete";').catch(() => false);
    }
    out.adjustReloaded = adjReloaded;

    done(out);
})().catch((e) => fail('probe_error: ' + e.message));
