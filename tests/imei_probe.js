/**
 * فاکتورِ فروشگاهِ موبایل در کرومیومِ واقعی — رفتارِ `store.js`، نه چیدمان:
 *   ۱. IMEIِ یک گوشیِ در انبار در خانه‌ی کالا → نامِ گوشی، هر دو IMEI، مقدارِ ۱
 *      و قیمتِ فروش خودبه‌خود پر می‌شوند (جست‌وجوی هوشمند).
 *   ۲. نامِ مدلِ گوشی → خانه‌های IMEI باز می‌شوند؛ لوازم جانبی → نه.
 *   ۳. «+» داخلِ خودِ خانه‌ی کالا و در انتهای آن است، پنلِ «کالای تازه» را
 *      **درجا** باز می‌کند (فرم فرستاده نمی‌شود) و متنِ ردیف را پیشنهاد می‌کند؛
 *      IMEIِ تایپ‌شده نوع را «گوشی» می‌کند.
 *   ۴. فیلدِ سررسید نیست.
 *
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `store_probe.js`).
 * اجرا: node imei_probe.js <baseUrl> <username> <password> <imei> <phoneName> <accName>
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const [base, user, pass, imei, phoneName, accName] = process.argv.slice(2);
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
    const lp = waitLoad();
    await ev(`(()=>{document.querySelector('[name=username]').value=${JSON.stringify(user)};document.querySelector('[name=password]').value=${JSON.stringify(pass)};document.querySelector('form').submit();return 1})()`);
    await lp;
    await sleep(300);

    const out = {};
    for (const w of [1400, 390]) {
        await size(w, w < 700 ? 844 : 900);
        await go('store/invoice-edit.php?k=sale');
        const r = await ev(`(async()=>{
            const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
            const rows=[...document.querySelectorAll('[data-row]')];
            const f=(row,n)=>row.querySelector('[data-'+n+']');
            const type=(el,v,evn)=>{el.value=v;el.dispatchEvent(new Event(evn||'input',{bubbles:true}));};
            const o={};
            o.due = !!document.querySelector('[name="due_date"]');
            // ۱ — IMEI (با فاصله و ارقامِ فارسی، همان‌طور که از روی جعبه تایپ می‌شود)
            const fa = ${JSON.stringify(imei)}.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
            type(f(rows[0],'item'), fa.slice(0,5)+' '+fa.slice(5), 'change');
            await sleep(50);
            o.item0 = f(rows[0],'item').value; o.imei0 = f(rows[0],'imei1').value; o.qty0 = f(rows[0],'qty').value;
            o.price0 = f(rows[0],'price').value; o.pid0 = f(rows[0],'pid').value;
            o.box0 = !f(rows[0],'imei-box').hidden;
            // ۲ — مدلِ گوشی و لوازم جانبی
            type(f(rows[1],'item'), ${JSON.stringify(phoneName)}, 'change'); await sleep(30);
            o.box1 = !f(rows[1],'imei-box').hidden;
            type(f(rows[2],'item'), ${JSON.stringify(accName)}, 'change'); await sleep(30);
            o.box2 = !f(rows[2],'imei-box').hidden;
            // ۳ — «+»
            const plus = rows[3].querySelector('[data-np-open]'), inp = f(rows[3],'item');
            const pr = plus.getBoundingClientRect(), ir = inp.getBoundingClientRect();
            o.plusInside = pr.left >= ir.left - 1 && pr.right <= ir.right + 1 && pr.top >= ir.top - 1 && pr.bottom <= ir.bottom + 1;
            o.plusAtEnd = (pr.left - ir.left) < 8;                  // انتهای خانه در راست‌به‌چپ = لبه‌ی چپ
            type(inp, 'قاب سیلیکونی آزمون');
            const np = document.querySelector('[data-np]');
            o.npHiddenBefore = np.hidden;
            // ⛔ «+» یک دکمه‌ی فرم است؛ با جاوااسکریپت نباید فرم را بفرستد
            let submitted = false;
            const form = document.querySelector('form[data-invoice]');
            form.addEventListener('submit', (e) => { submitted = true; e.preventDefault(); });
            plus.click(); await sleep(80);
            o.npOpen = !np.hidden; o.stayed = !submitted;
            o.npName = np.querySelector('[data-np-name]').value;
            o.npRow = np.querySelector('[data-np-row]').value;
            o.npImeiHidden = np.querySelector('[data-np-imei]').hidden;
            np.querySelector('[data-np-type][value="phone"]').click(); await sleep(30);
            o.npImeiShownPhone = !np.querySelector('[data-np-imei]').hidden;
            np.querySelector('[data-np-close]').click(); await sleep(30);
            o.npClosed = np.hidden;
            // IMEIِ تازه در ردیف + «+» → نوعِ گوشی و IMEIِ پیشنهادی
            document.querySelector('[data-add-rows]').click(); await sleep(30);
            const r5 = [...document.querySelectorAll('[data-row]')][4];
            o.addedPlusValue = r5.querySelector('[data-np-open]').value;
            type(f(r5,'item'), '359999000011112'); r5.querySelector('[data-np-open]').click(); await sleep(50);
            o.npTypeFromImei = (np.querySelector('[data-np-type]:checked')||{}).value;
            o.npImei1 = np.querySelector('[name="np[imei1]"]').value;
            o.sw = document.documentElement.scrollWidth; o.W = document.documentElement.clientWidth;
            return o;
        })()`);
        out[w] = r;
    }
    done({ ok: true, res: out });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
