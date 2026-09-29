/**
 * تصویرِ شخصیِ فروشگاه در کرومیومِ واقعی، زیرِ CSPِ تولید (`csp_router.php`).
 *
 * می‌سنجد: انتخابِ یک عکسِ بزرگ در تنظیمات → پیش‌نمایش (data:) → فایلِ
 * فرم به JPEGِ کوچک تبدیل شده → ذخیره → تصویر بالای صفحه **واقعاً بار
 * شده** (naturalWidth > 0، یعنی CSP نبسته) و جای حرفِ اول نشسته.
 *
 * ⛔ بی‌وابستگیِ npm. اجرا: node store_avatar_probe.js <baseUrl> <cookieName> <cookieValue> <imagePath>
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const [base, cookieName, cookieValue, imgPath] = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9700 + Math.floor(process.pid % 500);
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
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true }, S);
    await go('store/settings.php');
    await sleep(300);
    out.before = await ev(`!!document.querySelector('.st-top .st-avatar-img')`);
    await send('DOM.enable', {}, S);
    const doc = (await send('DOM.getDocument', { depth: 1 }, S)).result.root;
    const q = (await send('DOM.querySelector', { nodeId: doc.nodeId, selector: '[data-avatar-input]' }, S)).result;
    out.inputFound = !!(q && q.nodeId);
    if (out.inputFound) {
        await send('DOM.setFileInputFiles', { nodeId: q.nodeId, files: [imgPath] }, S);
        await sleep(1500);
        out.picked = await ev(`(()=>{const i=document.querySelector('[data-avatar-input]');const f=i.files[0];const p=document.querySelector('[data-avatar-preview] img');return {type:f?f.type:'',size:f?f.size:0,preview:!!p,previewData:p?p.src.indexOf('data:')===0:false,previewLoaded:p?p.naturalWidth>0:false}})()`);
        const p = waitLoad();
        await ev(`document.querySelector('[data-avatar-form]').requestSubmit()`);
        await p; await sleep(300);
        out.after = await ev(`(()=>{const im=document.querySelector('.st-top .st-avatar-img');return im?{loaded:im.complete&&im.naturalWidth>0,w:im.naturalWidth,src:im.getAttribute('src')}:null})()`);
        out.flash = await ev(`(document.querySelector('.st-flash')||{}).textContent||''`);
    }
    done(out);
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: 'probe_error: ' + e.message }) + '\n'); process.exit(0); });
