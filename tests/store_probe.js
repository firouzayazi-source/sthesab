/**
 * صفحه‌های فروشگاه در کرومیومِ واقعی: روی گوشی (۳۹۰) و دسکتاپ (۱۴۰۰)
 * هیچ صفحه‌ای اسکرولِ افقی نمی‌سازد و هیچ عنصری از کارت یا صفحه بیرون
 * نمی‌زند (نامِ بلندِ بی‌فاصله در fixture هست — درسِ `paged_list`).
 *
 * چرا با مرورگر: فهرستِ کالا جدول است و نیم‌فاصله و عددِ بلند فقط با
 * چیدمانِ واقعی دیده می‌شوند؛ HTML به‌تنهایی چیزی نمی‌گوید.
 *
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `tap_probe.js`).
 *
 * اجرا: node store_probe.js <baseUrl> <username> <password> <pagesJson> [shotDir]
 * خروجی: یک خط JSON روی stdout.
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const [base, user, pass, pagesJson, shotDir] = process.argv.slice(2);
const pages = JSON.parse(pagesJson || '[]');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9800 + Math.floor(process.pid % 150);
    const proc = spawn(bin, [
        '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--mute-audio', `--remote-debugging-port=${port}`, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'ignore'] });
    const done = (payload) => {
        process.stdout.write(JSON.stringify(payload) + '\n');
        try { proc.kill('SIGKILL'); } catch (e) {}
        process.exit(0);
    };
    setTimeout(() => done({ ok: false, why: 'timeout' }), 120000);

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
    const listeners = [];
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
        else { listeners.forEach((l) => l(m)); }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id;
        pending.set(i, res);
        ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const S = (await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true })).result.sessionId;
    await send('Page.enable', {}, S);
    await send('Runtime.enable', {}, S);

    const waitLoad = () => new Promise((r) => {
        const l = (m) => { if (m.method === 'Page.loadEventFired' && m.sessionId === S) { listeners.splice(listeners.indexOf(l), 1); r(); } };
        listeners.push(l);
        setTimeout(() => { const i = listeners.indexOf(l); if (i >= 0) { listeners.splice(i, 1); r(); } }, 10000);
    });
    const ev = async (x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true }, S)).result.result.value;
    const go = async (u) => { const p = waitLoad(); await send('Page.navigate', { url: base + u }, S); await p; await sleep(200); };
    const size = (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 }, S);

    await size(1400, 900);
    await go('store/login.php');
    const p = waitLoad();
    await ev(`(()=>{document.querySelector('[name=username]').value=${JSON.stringify(user)};document.querySelector('[name=password]').value=${JSON.stringify(pass)};document.querySelector('form').submit();return 1})()`);
    await p;
    await sleep(300);

    // عنصرهای بیرون‌زده: هر برگ یا کنترلی که لبه‌اش از ظرفِ اسکرول‌ناپذیرش
    // بیرون برود. ظرفِ جدول (`.st-table-wrap`) عمداً اسکرول‌شونده است.
    const probe = `(()=>{
        const W = document.documentElement.clientWidth;
        const out = [];
        const scrollable = (el) => { for (let e = el.parentElement; e; e = e.parentElement) {
            const s = getComputedStyle(e); if (/(auto|scroll)/.test(s.overflowX)) return true; } return false; };
        for (const el of document.querySelectorAll('.st-main *, .st-top *, .st-tabbar *')) {
            const r = el.getBoundingClientRect();
            if (!r.width || getComputedStyle(el).position === 'fixed') continue;
            if ((r.right > W + 1 || r.left < -1) && !scrollable(el)) {
                out.push((el.className || el.tagName) + ' ' + Math.round(r.left) + '..' + Math.round(r.right));
            }
        }
        // ⛔ ظرفِ جدول اسکرول‌شونده است، ولی فقط تورِ نجات است: جدولی که
        //    واقعاً اسکرول می‌خواهد یعنی ستونِ قیمت روی گوشی پنهان است.
        for (const tw of document.querySelectorAll('.st-table-wrap')) {
            if (tw.scrollWidth > tw.clientWidth + 1) { out.push('st-table-wrap scroll ' + tw.scrollWidth + '>' + tw.clientWidth); }
        }
        // ⛔ «همه چیز راست‌چین، جز عدد» — متنِ فارسی که جهتش rtl نیست یا
        //    چپ‌چین شده خطاست. عدد (.st-num، ستونِ عدد، ورودیِ ltr) عمداً
        //    بیرون است.
        const rtlBad = [];
        const FA = /[\u0600-\u06FF]/;
        for (const el of document.querySelectorAll('.st-main *, .st-side *, .st-top *, .st-tabbar *')) {
            if (el.closest('.st-num, .st-td-num, .st-th-num, [dir="ltr"], svg, option')) continue;
            const own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join('').trim();
            if (!own || !FA.test(own)) continue;
            const cs = getComputedStyle(el);
            if (cs.direction !== 'rtl' || cs.textAlign === 'left') {
                rtlBad.push((el.className || el.tagName) + ' dir=' + cs.direction + ' align=' + cs.textAlign);
            }
        }
        const sd = document.querySelector('.st-side');
        const sr = sd ? sd.getBoundingClientRect() : null;
        const side = sr ? { left: Math.round(sr.left), right: Math.round(sr.right), shown: sr.right > 0 && sr.left < W } : null;
        return { sw: document.documentElement.scrollWidth, W, out: out.slice(0, 5), rtl: rtlBad.slice(0, 5), side,
                 done: document.documentElement.outerHTML.includes('</html>') || !!document.querySelector('.st-tabbar') };
    })()`;

    const res = [];
    for (const w of [390, 1400]) {
        await size(w, w < 700 ? 844 : 900);
        for (const pg of pages) {
            await go(pg);
            const m = await ev(probe);
            // کشوی موبایل: با زدنِ «منو» نوار از لبه‌ی راست بیرون می‌آید
            let drawer = null;
            if (w < 700) {
                await ev(`document.getElementById('stNavToggle').checked = true`);
                await sleep(350);
                drawer = await ev(`(()=>{const r=document.querySelector('.st-side').getBoundingClientRect();return {left:Math.round(r.left),right:Math.round(r.right)}})()`);
                await ev(`document.getElementById('stNavToggle').checked = false`);
                await sleep(300);
            }
            res.push({ w, pg, sw: m.sw, W: m.W, out: m.out, rtl: m.rtl, side: m.side, drawer });
            if (shotDir) {
                const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, S);
                fs.writeFileSync(`${shotDir}/${w}-${pg.replace(/[^a-z0-9]+/gi, '_')}.png`, Buffer.from(shot.result.data, 'base64'));
            }
        }
    }
    done({ ok: true, res });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
