/**
 * نوارِ «نسخه‌ی تازه‌ی اپ آماده است» در کرومیومِ واقعی.
 *
 * چرا مرورگر: تصمیمِ «داخلِ اپ هستیم؟» روی `sessionStorage`، `referrer` و
 * `?appv=` است، و پاک شدنِ `appv` از نوارِ آدرس (با نگه داشتنِ فرگمنتِ
 * پیامک) کارِ `history.replaceState` — هیچ‌کدام در سورسِ صفحه دیده
 * نمی‌شوند.
 *
 * صفحه یک نمونه‌ی ایستاست (`/__update_probe`) که روترِ تست با همان
 * `<meta name="apk-latest">`ِ `header.php` می‌سازد و خودِ `app.js`ِ واقعی را
 * لود می‌کند.
 *
 * ⛔ هیچ وابستگیِ npm ندارد — همان الگوی `intro_probe.js`.
 *
 * اجرا: node update_probe.js <baseUrl>
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
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };

const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

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

    // ⚠ هر «زبانه» sessionStorageِ خودش را دارد — همان مرزی که داخلِ اپ را
    //   از کرومِ معمولی جدا می‌کند. پس زبانه‌ی دوم یعنی «کرومِ معمولی».
    const openTab = async () => {
        const t = await send('Target.createTarget', { url: 'about:blank' });
        const s = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
        const sid = s.result.sessionId;
        await send('Runtime.enable', {}, sid);
        await send('Page.enable', {}, sid);
        return sid;
    };

    const evalIn = async (sid, expr) => {
        const r = await send('Runtime.evaluate', {
            expression: `(function(){${expr}})()`, returnByValue: true, awaitPromise: true,
        }, sid);
        if (r.result && r.result.exceptionDetails) {
            done({ ok: false, why: 'js_error', detail: String(r.result.exceptionDetails.text || '')
                + ' ' + String((r.result.exceptionDetails.exception || {}).description || '') });
        }
        return r.result.result.value;
    };

    const load = async (sid, query) => {
        await send('Page.navigate', { url: baseUrl + '__update_probe' + query }, sid);
        for (let i = 0; i < 60; i++) {
            await sleep(150);
            const ready = await evalIn(sid, 'return document.readyState === "complete"'
                + ' && !document.documentElement.classList.contains("js-loading");');
            if (ready) { return true; }
        }
        return false;
    };

    const bar = 'var b = document.querySelector(".app-update-bar"); return !!b;';
    const out = { ok: true };

    // ---------- الف: اولین باز شدنِ اپِ قدیمی‌تر ----------
    const app = await openTab();
    if (!(await load(app, '?latest=10&inst=9&appv=9#smsq=%5B%22x%22%5D'))) { done({ ok: false, why: 'not_ready_a' }); }
    await evalIn(app, 'try { localStorage.clear(); } catch (e) {} return 1;');
    if (!(await load(app, '?latest=10&inst=9&appv=9#smsq=%5B%22x%22%5D'))) { done({ ok: false, why: 'not_ready_a2' }); }
    out.shownOld      = await evalIn(app, bar);
    out.search        = await evalIn(app, 'return location.search;');
    out.hash          = await evalIn(app, 'return location.hash;');
    out.title         = await evalIn(app, 'var t = document.querySelector(".app-update-bar strong"); return t ? t.textContent : "";');
    out.href          = await evalIn(app, 'var a = document.querySelector(".app-update-bar .apk-bar-cta"); return a ? a.getAttribute("href") : "";');
    out.hasDownload   = await evalIn(app, 'var a = document.querySelector(".app-update-bar .apk-bar-cta"); return !!a && a.hasAttribute("download");');
    out.first         = await evalIn(app, 'var c = document.querySelector(".page-content"); return c && c.firstElementChild ? c.firstElementChild.className : "";');
    out.position      = await evalIn(app, 'var b = document.querySelector(".app-update-bar"); return b ? getComputedStyle(b).position : "";');

    // ---------- ب: «بعداً» ----------
    await evalIn(app, 'document.querySelector(".app-update-bar .apk-bar-x").click(); return 1;');
    out.goneAfterX    = !(await evalIn(app, bar));
    out.snoozeX       = await evalIn(app, 'return localStorage.getItem("daftar_update_snooze") || "";');
    await load(app, '?latest=10&inst=9');
    out.hiddenSnoozed = !(await evalIn(app, bar));

    // ---------- ج: نسخه‌ی تازه‌تر از آنچه به تعویق افتاده ----------
    await load(app, '?latest=11&inst=9');
    out.shownNewer    = await evalIn(app, bar);

    // ---------- د: اپِ به‌روز ----------
    await evalIn(app, 'localStorage.removeItem("daftar_update_snooze"); return 1;');
    await load(app, '?latest=10&inst=10');
    out.hiddenCurrent = !(await evalIn(app, bar));

    // ---------- هـ: اپِ پیش از این قابلیت (بی‌نسخه) ----------
    await load(app, '?latest=10&inst=');
    out.shownUnknown  = await evalIn(app, bar);

    // ---------- و: تپ روی «به‌روزرسانی» ----------
    await evalIn(app, 'var a = document.querySelector(".app-update-bar .apk-bar-cta");'
        + ' a.addEventListener("click", function (e) { e.preventDefault(); }); a.click(); return 1;');
    out.tapNote       = await evalIn(app, 'var s = document.querySelector(".app-update-bar .apk-bar-body span"); return s ? s.textContent : "";');
    out.snoozeTap     = await evalIn(app, 'return localStorage.getItem("daftar_update_snooze") || "";');
    out.now           = await evalIn(app, 'return Date.now();');

    // ---------- ز: همان سایت در کرومِ معمولی ----------
    await evalIn(app, 'localStorage.removeItem("daftar_update_snooze"); return 1;');
    const chrome = await openTab();
    await load(chrome, '?latest=10&inst=9');
    out.hiddenBrowser = !(await evalIn(chrome, bar));

    done(out);
})().catch((e) => fail('exception: ' + (e && e.message)));
