/**
 * آپلودِ تصویرِ پروفایل در کرومیومِ واقعی، **زیرِ CSPِ تولید** — برای
 * `test_avatar_upload.php`.
 *
 * ⛔ فایل با `DOM.setFileInputFiles` به همان `#avatarInput` داده می‌شود،
 *    پس همان مسیری اجرا می‌شود که روی گوشی: خواندن → قاب → «ذخیره» →
 *    آپلود → بارگذاریِ دوباره. بی‌وابستگیِ npm (قاعده‌ی `tap_probe.js`).
 *
 * اجرا: node avatar_probe.js baseUrl sessionCookie file1,file2,...
 * خروجی: یک خط JSON.
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
const cookie = process.argv[3];
const files = String(process.argv[4] || '').split(',').filter(Boolean);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9900 + (process.pid % 90);
    const proc = spawn(bin, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', `--remote-debugging-port=${port}`, 'about:blank'], { stdio: 'ignore' });
    const done = (p) => { process.stdout.write(JSON.stringify(p) + '\n'); try { proc.kill('SIGKILL'); } catch (e) {} process.exit(0); };

    let ver = null;
    for (let i = 0; i < 60; i++) {
        await sleep(150);
        try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) {}
    }
    if (!ver) { done({ ok: false, why: 'chromium_no_start' }); }
    const ws = new WebSocket(ver.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let id = 0;
    const pending = new Map();
    const errors = [];
    const csp = [];
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
        else if (m.method === 'Runtime.exceptionThrown') {
            errors.push(String((m.params.exceptionDetails.exception || {}).description || m.params.exceptionDetails.text).slice(0, 200));
        } else if (m.method === 'Log.entryAdded' && /Content Security Policy/i.test(m.params.entry.text || '')) {
            csp.push(String(m.params.entry.text).slice(0, 200));
        }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });
    const t = await send('Target.createTarget', { url: 'about:blank' });
    const a = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
    const sid = a.result.sessionId;
    await send('Page.enable', {}, sid);
    await send('Runtime.enable', {}, sid);
    await send('Log.enable', {}, sid);
    await send('DOM.enable', {}, sid);
    const u = new URL(baseUrl);
    await send('Network.setCookie', { name: 'DAFTAR_SESSION', value: cookie, domain: u.hostname, path: '/' }, sid);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true }, sid);
    const ev = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true }, sid)).result.result.value;
    const load = async (path) => {
        await send('Page.navigate', { url: baseUrl + path }, sid);
        for (let i = 0; i < 60; i++) {
            await sleep(100);
            if (await ev("document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading')")) break;
        }
        await sleep(150);
    };

    const results = [];
    for (const f of files) {
        await ev("localStorage.setItem('daftar_intro_seen','1')");
        await load('profile.php');
        const before = await ev("(document.querySelector('img#avatarPreview')||{}).getAttribute ? document.querySelector('img#avatarPreview').getAttribute('src') : ''");
        const doc = await send('DOM.getDocument', { depth: 1 }, sid);
        const q = await send('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: '#avatarInput' }, sid);
        await send('DOM.setFileInputFiles', { files: [f], nodeId: q.result.nodeId }, sid);
        let shown = false;
        for (let i = 0; i < 40; i++) {
            await sleep(100);
            if (await ev("document.getElementById('avatarCropModal').classList.contains('show')")) { shown = true; break; }
        }
        const msg = await ev("(function(){var m=document.getElementById('avatarMessage');return m && !m.hidden ? m.textContent : ''})()");
        let after = '';
        if (shown) {
            await ev("document.getElementById('cropSave').click()");
            for (let i = 0; i < 60; i++) {
                await sleep(150);
                after = await ev("(function(){var i=document.querySelector('img#avatarPreview');return i ? i.getAttribute('src') : ''})()") || '';
                if (after && after !== before) break;
            }
        }
        results.push({ file: f.split('/').pop(), shown, msg, changed: !!after && after !== before });
    }
    done({ ok: true, results, errors, csp });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
