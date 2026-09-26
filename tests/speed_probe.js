/**
 * سرعتِ تعویض صفحه، در کرومیومِ واقعی: پیش‌گیری (Speculation Rules) و
 * پیش‌بارگذاریِ ناوبری (Navigation Preload).
 *
 * چرا با مرورگر: «قاعده در HTML هست» با «مرورگر واقعاً صفحه را پیش‌گرفت و
 * همان را مصرف کرد» یکی نیست — سرویس‌ورکرِ فعال و `Cache-Control:
 * no-store` هر دو می‌توانستند بی‌صدا جلویش را بگیرند. عدد از
 * `performance…deliveryType` و رویدادهای `Preload` می‌آید، نه از حدس.
 *
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `tap_probe.js`).
 *
 * اجرا: node speed_probe.js <baseUrl> <cookieName> <cookieValue>
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
const [base, cookieName, cookieValue] = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9400 + Math.floor(process.pid % 500);
    const proc = spawn(bin, [
        '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--mute-audio', `--remote-debugging-port=${port}`, 'about:blank',
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

    // ---------- مرحله‌ی الف: prerenderِ زبانه‌ها ----------
    // ⚠ کرومیوم prerender را برای صفحه‌ای که DevTools به آن **وصل** است
    //   خاموش می‌کند (`PrerenderingDisabledByDevTools`). پس صفحه بی‌اتصال
    //   باز می‌شود، صبر می‌کنیم تا زبانه‌ها آماده شوند، و فقط بعد وصل
    //   می‌شویم — همان‌طور که روی گوشی هیچ DevTools ای وصل نیست.
    const pre = {};
    await send('Storage.setCookies', { cookies: [{ name: cookieName, value: cookieValue, domain: new URL(base).hostname, path: '/' }] });
    const evIn = async (sid, x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true }, sid)).result.result.value;
    const openBare = async (url) => (await send('Target.createTarget', { url })).result.targetId;
    const attach = async (tid) => (await send('Target.attachToTarget', { targetId: tid, flatten: true })).result.sessionId;
    const findPage = async (part) => {
        const all = (await send('Target.getTargets')).result.targetInfos;
        const p = all.find((x) => x.type === 'page' && x.url.indexOf(part) !== -1);
        return p ? p.targetId : null;
    };
    const navInfo = `(()=>{const n=performance.getEntriesByType('navigation')[0];return {act:Math.round(n.activationStart||0),main:(typeof window.__appMainAt==='number')?Math.round(window.__appMainAt):-1,origin:performance.timeOrigin}})()`;
    {
        // ۰) گرم کردنِ سرویس‌ورکر، تا مرحله‌ی الف زیرِ همان شرایطِ واقعی باشد
        const w = await openBare(base + 'index.php');
        await sleep(3000);
        await send('Target.closeTarget', { targetId: w });
        // ۱) خانه → گزارش: دومین زبانه با فاصله آماده می‌شود
        const tA = await openBare(base + 'index.php');
        await sleep(8000);
        const sA = await attach(tA);
        await evIn(sA, `location.href=${JSON.stringify(base + 'dashboard.php')}`);
        await sleep(2500);
        const tD = await findPage('dashboard.php');
        pre.tab = tD ? await evIn(await attach(tD), navInfo) : null;
        if (tD) { await send('Target.closeTarget', { targetId: tD }); }
        // ۲) کهنگی: بعد از یک POSTِ موفق، prerenderِ قبلی نباید مصرف شود
        const tB = await openBare(base + 'index.php');
        await sleep(6000);
        const sB = await attach(tB);
        pre.postStatus = await evIn(sB, `fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'}}).then(r=>r.status)`);
        const tPost = Date.now();
        await sleep(500);
        await evIn(sB, `location.href=${JSON.stringify(base + 'transactions.php')}`);
        await sleep(2500);
        const tT = await findPage('transactions.php');
        const infoT = tT ? await evIn(await attach(tT), navInfo) : null;
        pre.afterPost = infoT ? { act: infoT.act, createdAfterPost: infoT.origin > tPost } : null;
        if (tT) { await send('Target.closeTarget', { targetId: tT }); }
    }

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const a = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
    const S = a.result.sessionId;
    for (const d of ['Page', 'Runtime', 'Network', 'Preload']) { await send(d + '.enable', {}, S); }
    const prefetched = [];
    listeners.push((m) => {
        if (m.method === 'Preload.prefetchStatusUpdated') {
            prefetched.push(m.params.prefetchUrl.replace(base, '').split('?')[0]);
        }
    });
    await send('Network.setCookie', { name: cookieName, value: cookieValue, domain: new URL(base).hostname, path: '/' }, S);

    const waitLoad = () => new Promise((r) => {
        const l = (m) => { if (m.method === 'Page.loadEventFired' && m.sessionId === S) { listeners.splice(listeners.indexOf(l), 1); r(); } };
        listeners.push(l);
        setTimeout(() => { const i = listeners.indexOf(l); if (i >= 0) { listeners.splice(i, 1); r(); } }, 10000);
    });
    const ev = async (x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true }, S)).result.result.value;
    const go = async (u) => { const p = waitLoad(); await send('Page.navigate', { url: base + u }, S); await p; };
    const assign = async (u) => { const p = waitLoad(); await ev(`location.href=${JSON.stringify(base + u)}`); await p; await sleep(150); };
    const dtype = () => ev(`performance.getEntriesByType('navigation')[0].deliveryType`);
    // نشانگر ۹۰۰ms روی لینک (moderate = ۲۰۰ms)، بعد بیرون — بی‌کلیک.
    const hover = async (sel) => {
        const r = await ev(`(()=>{const a=document.querySelector(${JSON.stringify(sel)});if(!a)return null;a.scrollIntoView({block:'center'});const b=a.getBoundingClientRect();return [b.x+b.width/2,b.y+b.height/2]})()`);
        if (!r) { return false; }
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: r[0], y: r[1] }, S);
        await sleep(900);
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 }, S);
        return true;
    };

    const out = { ok: true, pre };
    await go('index.php');
    await ev('navigator.serviceWorker.ready.then(()=>1)');
    await sleep(1500);
    await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false }, S);
    await go('index.php');
    await sleep(400);

    out.controlled = await ev('!!navigator.serviceWorker.controller');
    out.preload = await ev('navigator.serviceWorker.ready.then(r=>r.navigationPreload?r.navigationPreload.getState().then(s=>!!s.enabled):null)');
    out.rules = await ev(`(()=>{try{JSON.parse(document.getElementById('specRules').textContent);return HTMLScriptElement.supports('speculationrules')}catch(e){return false}})()`);

    // ۱) لینکِ عادی: مکثِ نشانگر → ناوبری همان پیش‌گرفته را مصرف می‌کند
    out.hoverFound = await hover('.sidebar a[href$="/transactions.php"]');
    await assign('transactions.php');
    out.normal = await dtype();

    // ۲) لینکِ پرسش‌دار (?t=…) هم پوشش دارد
    await go('index.php'); await sleep(300);
    out.queryFound = await hover('a[href*="due.php?"]');
    const qh = await ev(`(document.querySelector('a[href*="due.php?"]')||{}).getAttribute ? document.querySelector('a[href*="due.php?"]').getAttribute('href') : ''`);
    if (qh) { await assign(qh.replace(/^\//, '')); }
    out.query = await dtype();

    // ۳) صفحه‌های نویسنده با GET هرگز پیش‌گرفته نمی‌شوند — و شاهد: همان پنجره
    //    لینکِ عادی را **می‌گیرد**، پس سکوت از کور بودنِ probe نیست.
    await go('index.php'); await sleep(300);
    prefetched.length = 0;
    out.exFound = [
        await hover('a[href$="/logout.php"]'),
        await hover('a[href$="/notifications.php"]'),
        await hover('.sidebar a[href$="/support.php"]'),
    ];
    await hover('.sidebar a[href$="/wallets.php"]');
    await sleep(500);
    out.prefetchedUrls = prefetched.slice();

    // ۴) کهنگی: مکث → نوشتنِ موفق با fetch → ناوبری؛ پیش‌گرفته باید دور ریخته شده باشد
    await go('index.php'); await sleep(300);
    await hover('.sidebar a[href$="/wallets.php"]');
    out.postStatus = await ev(`fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'}}).then(r=>r.status)`);
    await sleep(400);
    await assign('wallets.php');
    out.afterPost = await dtype();

    // ۴ب) شاهد: همان مسیر با GET، پیش‌گرفته **می‌ماند**
    await go('index.php'); await sleep(300);
    await hover('.sidebar a[href$="/wallets.php"]');
    await ev(`fetch(location.href).then(r=>r.status)`);
    await sleep(400);
    await assign('wallets.php');
    out.afterGet = await dtype();

    done(out);
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: 'probe_error: ' + e.message }) + '\n'); process.exit(0); });
