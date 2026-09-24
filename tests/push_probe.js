/**
 * سرویس‌ورکرِ اعلان در کرومیومِ واقعی — برای `test_push.php`.
 *
 * ⛔ پیام با `ServiceWorker.deliverPushMessage` (CDP) به همان `sw.js`ِ
 *    ثبت‌شده داده می‌شود — یعنی دقیقاً همان رویدادِ `push` که سرویسِ پوشِ
 *    گوشی می‌فرستد — و بعد از خودِ `registration.getNotifications()`
 *    پرسیده می‌شود چه چیزی روی «صفحه‌ی گوشی» نشست.
 *
 * اجرا: node push_probe.js baseUrl sessionCookie
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const baseUrl = process.argv[2];
const cookie = process.argv[3];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9700 + (process.pid % 90);
    const udd = fs.mkdtempSync('/tmp/pushprobe-');
    const proc = spawn(bin, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', `--user-data-dir=${udd}`, `--remote-debugging-port=${port}`, 'about:blank'], { stdio: 'ignore' });
    const done = (p) => { process.stdout.write(JSON.stringify(p) + '\n'); try { proc.kill('SIGKILL'); } catch (e) {} try { fs.rmSync(udd, { recursive: true, force: true }); } catch (e) {} process.exit(0); };
    let ver = null;
    for (let i = 0; i < 60; i++) { await sleep(150); try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) {} }
    if (!ver) { done({ ok: false, why: 'chromium_no_start' }); }
    const ws = new WebSocket(ver.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let id = 0;
    const pending = new Map();
    const regs = [];
    const errors = [];
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
        else if (m.method === 'ServiceWorker.workerRegistrationUpdated') { regs.push(...m.params.registrations); }
        else if (m.method === 'Runtime.exceptionThrown') { errors.push(String(m.params.exceptionDetails.text).slice(0, 160)); }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });
    const origin = new URL(baseUrl).origin;
    await send('Browser.grantPermissions', { origin, permissions: ['notifications'] });
    const t = await send('Target.createTarget', { url: 'about:blank' });
    const a = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
    const sid = a.result.sessionId;
    await send('Page.enable', {}, sid);
    await send('Runtime.enable', {}, sid);
    await send('Network.setCookie', { name: 'DAFTAR_SESSION', value: cookie, domain: new URL(baseUrl).hostname, path: '/' }, sid);
    const ev = async (e, awaitP) => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: !!awaitP }, sid)).result.result.value;
    const load = async (path) => {
        await send('Page.navigate', { url: baseUrl + path }, sid);
        for (let i = 0; i < 60; i++) { await sleep(100); if (await ev("document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading')")) break; }
        await sleep(200);
    };
    await ev("localStorage.setItem('daftar_intro_seen','1')");
    await load('profile.php');
    const ready = await ev("navigator.serviceWorker.ready.then(function(r){return !!r.active})", true);
    await sleep(600);
    const card = await ev("JSON.stringify({card: !!document.getElementById('pushCard'), state: (document.getElementById('pushState')||{}).textContent || '', on: !(document.getElementById('pushOn')||{hidden:true}).hidden})");
    await send('ServiceWorker.enable', {}, sid);
    for (let i = 0; i < 30 && !regs.length; i++) { await sleep(100); }
    const reg = regs.find((r) => r.scopeURL.startsWith(origin));
    if (!reg) { done({ ok: false, why: 'no_registration', ready, card: JSON.parse(card) }); }
    await send('ServiceWorker.deliverPushMessage', { origin, registrationId: reg.registrationId,
        data: JSON.stringify({ title: 'چک امروز سررسید است', body: 'بانک ملت', url: 'due.php', tag: 't1', badge: 3 }) }, sid);
    let notes = [];
    for (let i = 0; i < 30; i++) {
        await sleep(150);
        notes = JSON.parse(await ev("navigator.serviceWorker.ready.then(function(r){return r.getNotifications()}).then(function(n){return JSON.stringify(n.map(function(x){return {title:x.title, body:x.body, dir:x.dir, badge:x.badge, icon:x.icon, url:(x.data||{}).url}}))})", true) || '[]');
        if (notes.length) break;
    }
    // پیامِ خراب هم باید یک اعلانِ عادی بدهد، نه سکوت (userVisibleOnly).
    await send('ServiceWorker.deliverPushMessage', { origin, registrationId: reg.registrationId, data: 'not json' }, sid);
    let notes2 = [];
    for (let i = 0; i < 30; i++) {
        await sleep(150);
        notes2 = JSON.parse(await ev("navigator.serviceWorker.ready.then(function(r){return r.getNotifications()}).then(function(n){return JSON.stringify(n.map(function(x){return x.title}))})", true) || '[]');
        if (notes2.length >= 2) break;
    }
    const badgeFn = await ev("typeof navigator.setAppBadge");
    done({ ok: true, ready, card: JSON.parse(card), notes, notes2, badgeFn, errors });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
