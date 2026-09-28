/**
 * جست‌وجوی کالا در فاکتور — در کرومیومِ واقعی، با همان `store.js`:
 *   ۱. پاپ‌آپِ `<datalist>` با جاوااسکریپت برداشته می‌شود (دو فهرست روی هم نه).
 *   ۲. متنِ فارسیِ **ناقص** کالای ذخیره‌شده با «ي/ك»ِ عربی را پیدا می‌کند
 *      («شارژر سریع» → «شارژر سريع كابل…»)، و «ایفون» بی‌«آ» → «آیفون».
 *   ۳. ↓ + Enter انتخاب می‌کند: شناسه، قیمت، و فوکوس روی «تعداد».
 *   ۴. انتخابِ گوشیِ در انبار با لمس: IMEI پر، «شرحِ کالا» (توضیح) پنهان.
 *   ۵. متنی که به هیچ کالایی نمی‌خورد می‌گوید «نیست»، نه سکوت.
 *   ۶. انتخابِ مشتری «مانده‌ی قبلی» را نشان می‌دهد.
 *   ۷. فروشِ سریع: متنِ ناقص + Enter با یک پیشنهاد → ردیف پر می‌شود.
 *
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `store_probe.js`).
 * اجرا: node search_probe.js <baseUrl> <username> <password> <imei> <partyId>
 */
'use strict';


const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const [base, user, pass, imei, partyId] = process.argv.slice(2);
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
        out[w] = await ev(`(async()=>{
            const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
            const rows=[...document.querySelectorAll('[data-row]')];
            const f=(row,n)=>row.querySelector('[data-'+n+']');
            const type=(el,v)=>{el.focus();el.value=v;el.dispatchEvent(new Event('input',{bubbles:true}));};
            const key=(el,k)=>el.dispatchEvent(new KeyboardEvent('keydown',{key:k,bubbles:true,cancelable:true}));
            const ac=()=>document.querySelector('.st-ac');
            const o={};
            o.listAttr = [...document.querySelectorAll('[data-item]')].some(i=>i.hasAttribute('list'));
            o.head = [...document.querySelectorAll('.st-lines thead th')].map(t=>t.textContent.trim());
            // ۲ و ۳ — متنِ ناقص با «ی»ِ فارسی، کالا با «ي»ِ عربی؛ ↓ + Enter
            const i0=f(rows[0],'item');
            type(i0,'شارژر سریع'); await sleep(40);
            o.ac0 = ac() && !ac().hidden ? [...ac().querySelectorAll('.st-ac-opt b')].map(b=>b.textContent) : [];
            const r=ac()?ac().getBoundingClientRect():null;
            o.acInView = !!r && r.left>=0 && r.right<=document.documentElement.clientWidth+1 && r.top>=0;
            // دو پیشنهاد («شارژر سريع…» و «شارژر ماشین») — فقط ↓ + Enter انتخاب می‌کند
            type(i0,'شارژر'); await sleep(40);
            o.ac0b = [...ac().querySelectorAll('.st-ac-opt b')].map(b=>b.textContent);
            key(i0,'ArrowDown'); key(i0,'Enter'); await sleep(40);
            o.item0=i0.value; o.pid0=f(rows[0],'pid').value; o.price0=f(rows[0],'price').value;
            o.focusQty = document.activeElement===f(rows[0],'qty');
            o.acClosed = !ac() || ac().hidden;
            o.note0 = !f(rows[0],'note').hidden && f(rows[0],'imei-box').hidden;
            // ۴ — «ایفون» بی‌«آ»؛ لمسِ گوشیِ در انبار
            const i1=f(rows[1],'item');
            type(i1,'ایفون'); await sleep(40);
            o.ac1 = [...ac().querySelectorAll('.st-ac-opt')].map(x=>x.textContent);
            const unit=[...ac().querySelectorAll('.st-ac-opt')].find(x=>x.textContent.includes(${JSON.stringify(imei)}));
            if (unit) { unit.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,cancelable:true})); }
            await sleep(40);
            o.imei1=f(rows[1],'imei1').value; o.qty1=f(rows[1],'qty').value;
            o.phoneDesc = !f(rows[1],'imei-box').hidden && f(rows[1],'note').hidden;
            // ۵ — هیچ کالایی
            type(f(rows[2],'item'),'قاشق چوبی ناموجود'); await sleep(40);
            o.none = ac() && !ac().hidden && !!ac().querySelector('.st-ac-none');
            key(f(rows[2],'item'),'Escape'); await sleep(20);
            o.escClosed = ac().hidden;
            // ۶ — مانده‌ی قبلی
            const bb=document.querySelector('[data-balbox]');
            o.balHiddenBefore = bb.hidden;
            const p=document.querySelector('[data-party]'); p.value=${JSON.stringify(partyId)}; p.dispatchEvent(new Event('change',{bubbles:true}));
            await sleep(30);
            o.balShown = !bb.hidden; o.balPrev = bb.querySelector('[data-bal-prev]').textContent;
            o.balAfter = bb.querySelector('[data-bal-after]').textContent;
            o.balLink = bb.querySelector('[data-bal-link]').getAttribute('href');
            o.sw=document.documentElement.scrollWidth; o.W=document.documentElement.clientWidth;
            return o;
        })()`);
        await go('store/quick-sale.php');
        out[w].quick = await ev(`(async()=>{
            const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
            const s=document.querySelector('[data-scan]');
            s.focus(); s.value='کیف چر'; s.dispatchEvent(new Event('input',{bubbles:true})); await sleep(30);
            s.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true})); await sleep(30);
            return document.querySelector('[data-row] [data-item]').value;
        })()`);
    }
    done({ ok: true, res: out });
})();
