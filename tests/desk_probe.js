/**
 * خانه‌ی دسکتاپ در کرومیومِ واقعی: کوکیِ نمای دسکتاپ، چیدمانِ دو ستونه،
 * و دست‌نخوردنِ نمای گوشی.
 *
 * چرا با مرورگر: کلِ این قابلیت روی دو چیز سوار است که فقط در مرورگر
 * دیده می‌شوند — اسکریپتِ درون‌خطیِ سرآیند که با `matchMedia` کوکی
 * می‌گذارد، و CSSی که بالای ۱۱۰۰ پیکسل ستونِ کناری را نشان می‌دهد و
 * زیرش پنهان. HTML به‌تنهایی نمی‌گوید کدام ستون کجا نشسته.
 *
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `tap_probe.js`).
 *
 * اجرا: node desk_probe.js <baseUrl> <username> <password>
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
const [base, user, pass] = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9300 + Math.floor(process.pid % 500);
    const proc = spawn(bin, [
        '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--mute-audio', `--remote-debugging-port=${port}`, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'ignore'] });
    const done = (payload) => {
        process.stdout.write(JSON.stringify(payload) + '\n');
        try { proc.kill('SIGKILL'); } catch (e) {}
        process.exit(0);
    };
    setTimeout(() => done({ ok: false, why: 'timeout' }), 90000);

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
    const go = async (u) => { const p = waitLoad(); await send('Page.navigate', { url: base + u }, S); await p; await sleep(250); };
    const size = (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 }, S);

    // حالتِ صفحه: کوکی، بودنِ ستونِ کناری در HTML، دیده شدنش، و جای دو ستون.
    const state = () => ev(`(()=>{
        const vis = (el) => !!el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 0;
        const side = document.querySelector('.home-side');
        const main = document.querySelector('.home-main');
        const kpis = document.querySelector('.desk-kpis');
        const pc = document.querySelector('.page-content').getBoundingClientRect();
        const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { l: Math.round(b.left), r: Math.round(b.right), t: Math.round(b.top), w: Math.round(b.width) }; };
        return {
            cookie: /(?:^|; )daftar_desk=1(?:;|$)/.test(document.cookie),
            sideInHtml: !!side, sideVisible: vis(side), kpisVisible: vis(kpis),
            kpiCount: kpis ? kpis.querySelectorAll('.desk-kpi').length : 0,
            kpiRow: kpis ? new Set([...kpis.querySelectorAll('.desk-kpi')].map((k) => Math.round(k.getBoundingClientRect().top))).size : 0,
            side: r(side), main: r(main), pcW: Math.round(pc.width),
            hscroll: document.documentElement.scrollWidth > window.innerWidth,
            ribbon: !!document.querySelector('.balance-ribbon'),
            // ادامه‌ی خانه = داشبورد (کارتِ سالانه + روند + دسته‌بندی)
            moreInHtml: !!document.querySelector('.home-desk-more'),
            moreVisible: vis(document.querySelector('.home-desk-more')),
            yearVisible: vis(document.querySelector('.home-desk-more .year-report')),
            trendCanvas: (() => { const c = document.querySelector('.home-desk-more #trendChart'); return c ? Math.round(c.getBoundingClientRect().height) : 0; })(),
            moreMain: r(document.querySelector('.desk-more-main')), moreSide: r(document.querySelector('.desk-more-side')),
            moreBelow: (() => { const m = document.querySelector('.home-desk-more'), d = document.querySelector('.home-desk'); return !!m && !!d && m.getBoundingClientRect().top >= d.getBoundingClientRect().bottom - 1; })(),
        };
    })()`);

    // ورود از فرمِ واقعی — صفحه‌ی ورود خودش کوکیِ نمای دسکتاپ را می‌گذارد.
    await size(1440, 900);
    await go('login.php');
    const p = waitLoad();
    await ev(`(()=>{document.querySelector('[name=username]').value=${JSON.stringify(user)};document.querySelector('[name=password]').value=${JSON.stringify(pass)};document.querySelector('form').submit();return 1})()`);
    await p;
    await sleep(400);
    await ev(`(()=>{try{localStorage.setItem('daftar_intro_seen','1')}catch(e){}return 1})()`);

    const out = { ok: true };
    // ۱) بعد از ورود، اولین خانه همان نمای دسکتاپ است (کوکی از صفحه‌ی ورود آمد)
    await go('index.php');
    out.wide = await state();
    // ۲) پنجره‌ی نیمه‌باز: همان بارگذاری هنوز HTMLِ دسکتاپ دارد ولی CSS پنهانش
    //    می‌کند؛ کوکی برداشته می‌شود و بارگذاریِ بعدی اصلاً آن را نمی‌سازد.
    await size(1000, 800);
    out.midSameLoad = await state();
    await go('index.php');
    out.midNext = await state();
    await go('index.php');
    out.midAfter = await state();
    // ۳) گوشی
    await size(390, 844);
    await go('index.php');
    out.phone = await state();
    // ۴) برگشت به پنجره‌ی بزرگ: یک بارگذاری برای کوکی، بعد دسکتاپ
    await size(1280, 800);
    await go('index.php');
    await go('index.php');
    out.back = await state();

    done(out);
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: 'probe_error: ' + e.message }) + '\n'); process.exit(0); });
