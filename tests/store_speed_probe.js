/**
 * سرعتِ تعویض صفحه در **فروشگاه** (`/store/`)، در کرومیومِ واقعی.
 *
 * همان سازوکارِ `speed_probe.js` (Speculation Rules)، با دامنه‌ی فروشگاه:
 * مکثِ نشانگر روی یک لینکِ منو → ناوبری از پیش‌گرفته می‌آید؛ «خروج»
 * پیش‌گرفته نمی‌شود (با **شاهد**)؛ و فونت از preload می‌آید نه دیر.
 *
 * ⚠ با DevToolsِ وصل، کرومیوم prerender را خاموش می‌کند و قاعده‌ی
 *   prefetch جایش را می‌گیرد — پس اینجا `navigational-prefetch` دیده می‌شود.
 *
 * ⛔ هیچ وابستگیِ npm ندارد. اجرا: node store_speed_probe.js <baseUrl> <cookieName> <cookieValue>
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
    const port = 9600 + Math.floor(process.pid % 500);
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

    const pre = {};
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

    const out = { ok: true };
    await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false }, S);
    await go('store/index.php');
    await sleep(400);
    out.rules = await ev(`(()=>{try{JSON.parse(document.getElementById('stSpecRules').textContent);return HTMLScriptElement.supports('speculationrules')}catch(e){return false}})()`);
    // فونت: از preload آمده (initiatorType = link) و فقط یک بار دانلود شده
    out.font = await ev(`(()=>{const f=performance.getEntriesByType('resource').filter(e=>e.name.indexOf('Vazirmatn.woff2')!==-1);return {n:f.length,init:f.map(e=>e.initiatorType)}})()`);

    // ۱) لینکِ منو: مکثِ نشانگر → ناوبری همان پیش‌گرفته را مصرف می‌کند
    out.hoverFound = await hover('.st-side a[href$="/store/products.php"]');
    await assign('store/products.php');
    out.normal = await dtype();

    // ۲) «خروج» پیش‌گرفته نمی‌شود؛ شاهد: همان پنجره لینکِ عادی را **می‌گیرد**
    await go('store/index.php'); await sleep(300);
    prefetched.length = 0;
    out.exFound = await hover('.st-side a[href$="/store/logout.php"]');
    await hover('.st-side a[href$="/store/parties.php"]');
    await sleep(500);
    out.prefetchedUrls = prefetched.slice();
    // هنوز واردیم؟ (اگر «خروج» پیش‌گرفته می‌شد، نشست رفته بود)
    await go('store/accounts.php');
    out.stillIn = await ev(`location.pathname.indexOf('login.php') === -1`);

    // ۳) موبایل: منوی پایین هم پیش‌گرفته می‌شود
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true }, S);
    await go('store/index.php'); await sleep(300);
    out.tabFound = await hover('.st-tabbar a[href$="/store/accounts.php"]');
    await assign('store/accounts.php');
    out.tab = await dtype();

    done(out);
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: 'probe_error: ' + e.message }) + '\n'); process.exit(0); });
