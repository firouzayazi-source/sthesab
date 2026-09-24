/**
 * پالت‌های رنگ در کرومیومِ واقعی — برای `test_palette.php`.
 *
 * ⛔ چرا مرورگر: اینکه یک پالت «برنده می‌شود» حاصلِ وزنِ چند قاعده است
 *    (بخشِ ۳۴ یک کارتِ ماهِ بنفش برای شب دارد که از `.balance-ribbon`
 *    تنها سنگین‌تر است). فقط سبکِ **محاسبه‌شده** جواب را می‌گوید.
 * ⛔ بی‌وابستگیِ npm؛ کرومیوم با CDP (همان قاعده‌ی `tap_probe.js`).
 *
 * اجرا: node palette_probe.js baseUrl sessionCookie palettes
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
const palettes = String(process.argv[4] || '').split(',').filter(Boolean);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9400 + (process.pid % 500);
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
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
        else if (m.method === 'Runtime.exceptionThrown') {
            errors.push(String((m.params.exceptionDetails.exception || {}).description || m.params.exceptionDetails.text).slice(0, 200));
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
    const u = new URL(baseUrl);
    await send('Network.setCookie', { name: 'DAFTAR_SESSION', value: cookie, domain: u.hostname, path: '/' }, sid);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }, sid);
    const ev = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true }, sid)).result.result.value;
    // ⛔ صبر تا `app.js` واقعاً اجرا شود (کلاسِ js-loading را برمی‌دارد)؛
    //    `syncThemeColorMeta` همان‌جاست.
    const load = async (path) => {
        await send('Page.navigate', { url: baseUrl + path }, sid);
        for (let i = 0; i < 60; i++) {
            await sleep(100);
            if (await ev("document.readyState === 'complete' && !document.documentElement.classList.contains('js-loading')")) break;
        }
        await sleep(150);
    };

    await load('index.php');
    const pages = {};
    for (const p of palettes) {
        pages[p] = {};
        for (const mode of ['light', 'dark']) {
            await ev(`localStorage.setItem('daftar_theme','${mode}');localStorage.setItem('daftar_intro_seen','1');`
                + (p === palettes[0] ? "localStorage.removeItem('daftar_palette')" : `localStorage.setItem('daftar_palette','${p}')`));
            await load('index.php');
            pages[p][mode] = JSON.parse(await ev(`JSON.stringify({
                brand: getComputedStyle(document.documentElement).getPropertyValue('--brand').trim(),
                ribbon: (function(){ var r=document.querySelector('.balance-ribbon'); return r ? getComputedStyle(r).backgroundImage : ''; })(),
                meta: document.querySelector('meta[name="theme-color"]').content
            })`));
        }
    }

    const picker = {};
    await ev("localStorage.removeItem('daftar_palette');localStorage.setItem('daftar_theme','light')");
    await load('profile.php');
    picker.initialChecked = await ev("(document.querySelector('#palettePicker input:checked')||{}).value || null");
    picker.initialAttr = await ev("document.documentElement.getAttribute('data-palette')");
    await ev("document.querySelector('#palettePicker input[value=ocean]').click()");
    await sleep(150);
    picker.afterAttr = await ev("document.documentElement.getAttribute('data-palette')");
    picker.afterLS = await ev("localStorage.getItem('daftar_palette')");
    // ⛔ رنگِ نوارِ وضعیت باید **همان لحظه** عوض شود، نه فقط بعد از بارگذاریِ بعدی.
    picker.afterMeta = await ev("document.querySelector('meta[name=\"theme-color\"]').content");
    await load('index.php');
    picker.persistAttr = await ev("document.documentElement.getAttribute('data-palette')");
    await ev("localStorage.setItem('daftar_palette','bogus')");
    await load('index.php');
    picker.bogusAttr = await ev("document.documentElement.getAttribute('data-palette')");
    await load('profile.php');
    picker.bogusLS = await ev("localStorage.getItem('daftar_palette')");
    await ev("document.querySelector('#palettePicker input[value=sunset]').click()");
    await sleep(100);
    await ev("document.querySelector('#palettePicker input[value=emerald]').click()");
    await sleep(100);
    picker.greenAttr = await ev("document.documentElement.getAttribute('data-palette')");
    await ev(`document.querySelector('#palettePicker input[value=${palettes[0]}]').click()`);
    await sleep(100);
    picker.emeraldAttr = await ev("document.documentElement.getAttribute('data-palette')");
    picker.hscroll = await ev('document.documentElement.scrollWidth - innerWidth');

    done({ ok: true, pages, picker, errors });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
