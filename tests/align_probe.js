/**
 * اندازه‌گیریِ «عدد کجای جعبه‌اش نشسته» — در کرومیومِ واقعی.
 *
 * ⛔ چرا مرورگر و نه خواندنِ CSS: تراز یک عدد حاصلِ **جمعِ** چند قاعده
 *    است — `direction` ارثی، `flex-direction`، `justify-content`،
 *    `text-align`، و اینکه عنصر inline است یا block. هیچ‌کدام
 *    به‌تنهایی جواب را نمی‌گویند. همان درسِ «داشتنِ مقدار در فایل کافی
 *    نیست — قاعده‌ای که برنده می‌شود باید داشته باشدش».
 *
 * ⛔ هیچ وابستگیِ npm ندارد؛ کرومیوم را مستقیم با CDP می‌راند
 *    (node ≥ ۲۲ خودش `WebSocket` و `fetch` دارد) — همان قاعده‌ی
 *    `tap_probe.js`.
 *
 * اجرا: node align_probe.js baseUrl cookieName cookieValue pages classes width
 *   pages   — با ویرگول جدا
 *   classes — فهرستِ کلاس‌های عددی، با ویرگول جدا (بدونِ نقطه)
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
const pages = String(process.argv[5] || 'index.php').split(',').filter(Boolean);
const classes = String(process.argv[6] || '').split(',').filter(Boolean);
const width = parseInt(process.argv[7] || '390', 10);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };

const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }
if (!classes.length) { fail('no_classes'); }

(async () => {
    const port = 9100 + (process.pid % 700);
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
    await send('Emulation.setDeviceMetricsOverride',
        { width, height: 900, deviceScaleFactor: 1, mobile: width < 900 }, sid);

    const u = new URL(baseUrl);
    await send('Network.setCookie', {
        name: cookieName, value: cookieValue, domain: u.hostname, path: '/',
    }, sid);

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

    /**
     * ⛔ «راست‌چین» یعنی لبه‌ی راستِ عنصر روی لبه‌ی راستِ جعبه‌ی محتوای
     *    پدرش بنشیند **و** سمتِ چپش فضای خالیِ معنادار بماند. هر دو شرط
     *    لازم است: عنصرِ تمام‌عرض هر دو لبه‌اش صفر است و راست‌چین نیست.
     *
     * ⚠ عنصری که در هیچ‌کدام از دو لبه نیست (ستونِ وسطِ یک ردیفِ
     *   چندستونه) offender نیست — جایش را چیدمانِ ستون تعیین می‌کند نه
     *   جهتِ متن. وگرنه تست روی صفحه‌ی **سالم** هشدارِ الکی می‌داد.
     */
    const SCAN = `
        var CLS = ${JSON.stringify(classes)};
        var seen = [], bad = [];
        CLS.forEach(function (cls) {
            Array.prototype.forEach.call(document.querySelectorAll('.' + cls), function (el) {
                var p = el.parentElement;
                if (!p) { return; }
                var r = el.getBoundingClientRect();
                if (r.width <= 0 || r.height <= 0) { return; }
                var pr = p.getBoundingClientRect();
                var pcs = getComputedStyle(p);
                var padL = parseFloat(pcs.paddingLeft) || 0;
                var padR = parseFloat(pcs.paddingRight) || 0;
                var gapL = r.left - (pr.left + padL);
                var gapR = (pr.right - padR) - r.right;
                seen.push(cls);
                if (gapR < 2 && gapL > 8) {
                    bad.push({
                        cls: cls,
                        txt: (el.textContent || '').trim().slice(0, 30),
                        parent: String(p.className || p.tagName).slice(0, 40),
                        gapL: Math.round(gapL), gapR: Math.round(gapR),
                    });
                }
            });
        });
        return { seen: seen.length, bad: bad };
    `;

    const out = {};
    for (const page of pages) {
        await send('Page.navigate', { url: baseUrl + page }, sid);
        let ready = false;
        for (let i = 0; i < 60; i++) {
            await sleep(150);
            ready = await evalJs('return document.readyState === "complete";');
            if (ready) { break; }
        }
        if (!ready) { done({ ok: false, why: 'page_timeout', detail: page }); }
        await sleep(200);
        const inApp = await evalJs('return !!document.querySelector(".topbar");');
        if (!inApp) { done({ ok: false, why: 'not_logged_in', detail: page }); }
        out[page] = await evalJs(SCAN);
    }

    done({ ok: true, width, pages: out });
})().catch((e) => fail('crash:' + String(e && e.message)));
