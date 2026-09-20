/**
 * رفتارِ معرفیِ اولیه در کرومیومِ واقعی.
 *
 * چرا با مرورگر و نه با خواندنِ HTML: خودِ لایه در HTML **بسته** رندر
 * می‌شود (`.modal-overlay` پیش‌فرض `display: none` دارد) و باز شدنش،
 * نشانه‌گذاری، و ماندنِ نشانه بعد از تازه‌سازی همه کارِ `app.js` و
 * `localStorage` اند. سورسِ صفحه درباره‌ی هیچ‌کدامشان حرفی نمی‌زند.
 *
 * ⛔ مهم‌ترین چیزی که اینجا سنجیده می‌شود «هر راهِ خروجی نشانه را
 *    می‌زند» است — نه فقط رسیدن به اسلایدِ آخر. کسی که اسلایدِ دوم را
 *    می‌بندد یعنی «نمی‌خواهم»؛ اگر فقط پایانِ اسلایدها نشانه می‌گذاشت،
 *    همان آدم دفعه‌ی بعد دوباره همین را می‌دید و معرفی به مزاحم تبدیل
 *    می‌شد. پس سه راهِ خروج جدا آزموده می‌شوند: دکمه‌ی پایانی، «×» وسطِ
 *    کار، و Escape.
 *
 * ⛔ هیچ وابستگیِ npm ندارد — کرومیوم مستقیم با CDP رانده می‌شود، همان
 *    الگوی `tap_probe.js`.
 *
 * اجرا: node intro_probe.js <baseUrl> <cookieName> <cookieValue>
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
const cookieName = process.argv[3];
const cookieValue = process.argv[4];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };

const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

(async () => {
    const port = 9100 + Math.floor((process.pid % 800));
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

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const s = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
    const sid = s.result.sessionId;

    await send('Runtime.enable', {}, sid);
    await send('Page.enable', {}, sid);
    await send('Network.enable', {}, sid);

    const u = new URL(baseUrl);
    await send('Network.setCookie', {
        name: cookieName, value: cookieValue, domain: u.hostname, path: '/',
    }, sid);

    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', {
            expression: `(function(){${expr}})()`,
            returnByValue: true, awaitPromise: true,
        }, sid);
        if (r.result && r.result.exceptionDetails) {
            done({ ok: false, why: 'js_error', detail: String(r.result.exceptionDetails.text || '')
                + ' ' + String((r.result.exceptionDetails.exception || {}).description || '') });
        }
        return r.result.result.value;
    };

    // ⚠ انتظار تا **اجرای** `app.js`، نه فقط رسیدنِ HTML: کلاسِ
    //   `js-loading` را اولین کارِ همان فایل برمی‌دارد، پس دقیق‌ترین
    //   نشانه‌ی «اسکریپت واقعاً اجرا شد» همان است. با تکیه بر
    //   `readyState` تنها، probe می‌توانست پیش از ساخته شدنِ معرفی
    //   نگاه کند و «باز نشد» بدهد — یعنی یک قرمزِ الکی.
    const load = async () => {
        await send('Page.navigate', { url: baseUrl + 'index.php' }, sid);
        for (let i = 0; i < 60; i++) {
            await sleep(200);
            const ready = await evalJs(
                'return document.readyState === "complete"'
                + ' && !document.documentElement.classList.contains("js-loading");');
            if (ready) { return true; }
        }
        return false;
    };

    if (!(await load())) { done({ ok: false, why: 'page_not_ready' }); }

    const loggedIn = await evalJs('return !!document.querySelector(".js-add-tx");');
    if (!loggedIn) { done({ ok: false, why: 'not_logged_in' }); }

    if (!(await evalJs('return typeof window.introSeen === "function";'))) {
        done({ ok: false, why: 'intro_api_missing' });
    }

    const clearMark = 'try { localStorage.removeItem(window.INTRO_SEEN_KEY); } catch (e) {} return 1;';
    const mark = 'try { return localStorage.getItem(window.INTRO_SEEN_KEY); } catch (e) { return "ERR"; }';
    const shown = 'var b = document.getElementById("introModal");'
                + 'return !!b && b.classList.contains("show");';

    // ---------- فاز الف: باز شدن، اسلایدها، و نقطه‌ها ----------
    await evalJs(clearMark);
    if (!(await load())) { done({ ok: false, why: 'page_not_ready_2' }); }

    const out = { ok: true };
    out.hasBox      = await evalJs('return !!document.getElementById("introModal");');
    if (!out.hasBox) { done({ ok: false, why: 'no_intro_in_html' }); }

    out.openedFresh = await evalJs(shown);
    out.slides      = await evalJs('return document.querySelectorAll("#introModal .intro-slide").length;');
    out.dots        = await evalJs('return document.querySelectorAll("#introModal .intro-dot").length;');
    out.firstOn     = await evalJs(
        'var s = document.querySelectorAll("#introModal .intro-slide");'
        + 'return s[0].classList.contains("is-on") && !s[1].classList.contains("is-on");');
    // ⚠ سبکِ **محاسبه‌شده**، نه استایلِ درون‌خطی: آنچه کاربر می‌بیند
    //   حاصلِ جمعِ قاعده‌هاست، نه یک انتساب.
    out.prevHiddenAtFirst = await evalJs(
        'return getComputedStyle(document.getElementById("introPrev")).display === "none";');
    out.nextLabelFirst = await evalJs(
        'return document.getElementById("introNext").textContent;');

    // پیمایش تا اسلایدِ آخر: نقطه‌ها باید دقیقاً همراهِ اسلاید جلو بروند.
    out.dotsTrack = await evalJs(`
        var box = document.getElementById("introModal");
        var slides = box.querySelectorAll(".intro-slide");
        var dots   = box.querySelectorAll(".intro-dot");
        var next   = document.getElementById("introNext");
        var ok = true;
        for (var i = 1; i < slides.length; i++) {
            next.click();
            for (var j = 0; j < slides.length; j++) {
                if (slides[j].classList.contains("is-on") !== (j === i)) { ok = false; }
                if (dots[j] && dots[j].classList.contains("is-on") !== (j === i)) { ok = false; }
            }
        }
        return ok;
    `);
    out.prevVisibleLater = await evalJs(
        'return getComputedStyle(document.getElementById("introPrev")).display !== "none";');
    out.nextLabelLast = await evalJs(
        'return document.getElementById("introNext").textContent;');
    out.markBeforeExit = await evalJs(mark);

    // پایانِ اسلایدها: دکمه‌ی آخر هم باید ببندد و هم نشانه بزند.
    await evalJs('document.getElementById("introNext").click(); return 1;');
    out.shownAfterFinish = await evalJs(shown);
    out.markAfterFinish  = await evalJs(mark);

    // ---------- فاز ب: نشانه بعد از تازه‌سازی می‌ماند ----------
    if (!(await load())) { done({ ok: false, why: 'page_not_ready_3' }); }
    out.openedAfterSeen = await evalJs(shown);

    // ---------- فاز ج: بستن وسطِ کار هم نشانه می‌زند ----------
    await evalJs(clearMark);
    if (!(await load())) { done({ ok: false, why: 'page_not_ready_4' }); }
    out.openedAgainAfterClear = await evalJs(shown);
    await evalJs('document.getElementById("introNext").click(); return 1;');
    out.midwayIndex = await evalJs(
        'var s = document.querySelectorAll("#introModal .intro-slide");'
        + 'for (var i = 0; i < s.length; i++) { if (s[i].classList.contains("is-on")) return i; }'
        + 'return -1;');
    await evalJs('document.getElementById("introClose").click(); return 1;');
    out.shownAfterX = await evalJs(shown);
    out.markAfterX  = await evalJs(mark);
    if (!(await load())) { done({ ok: false, why: 'page_not_ready_5' }); }
    out.openedAfterX = await evalJs(shown);

    // ---------- فاز د: Escape هم یک راهِ خروج است ----------
    await evalJs(clearMark);
    if (!(await load())) { done({ ok: false, why: 'page_not_ready_6' }); }
    out.openedBeforeEsc = await evalJs(shown);
    await evalJs(
        'document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));'
        + 'return 1;');
    out.shownAfterEsc = await evalJs(shown);
    out.markAfterEsc  = await evalJs(mark);

    // ---------- فاز ه: نورافکن روی عنصرِ **واقعی** می‌نشیند ----------
    //
    // ⛔ این نیمه فقط در مرورگر سنجیدنی است: هدفِ هر اسلاید یک فهرستِ
    //    نامزد است و «کدام‌یک دیده می‌شود» را چیدمان تعیین می‌کند، نه
    //    سورس. روی موبایل دکمه‌ی ثبت `#addTxBtn`ِ نوارِ پایین است و روی
    //    دسکتاپ قلمِ نوارِ کناری — پس هر دو عرض جدا اندازه گرفته می‌شوند.
    //
    // ⛔ و مهم‌ترین چیزی که برمی‌گردد `covers` است: کارت **نباید** با
    //    عنصری که نورافکن رویش است هم‌پوشانی عمودی داشته باشد، وگرنه
    //    دقیقاً همان چیزی را می‌پوشاند که دارد نشانش می‌دهد — خرابی‌ای
    //    که در سورس هیچ نشانه‌ای ندارد.
    // ⚠ حفره و کارت `transition` دارند، پس `getBoundingClientRect()` بلافاصله
    //   بعد از کلیک مقدارِ **شروعِ** انیمیشن را می‌دهد، نه مقصد را. نسخه‌ی اول
    //   همین بود و برای هر پنج اسلاید حفره‌ی ۰×۰ گزارش کرد — یعنی سنجه‌ای که
    //   روی کدِ سالم هم عددِ غلط می‌داد. پس بین کلیک و اندازه‌گیری مکث هست.
    const STEP = `
        var box = document.getElementById("introModal");
        var spot = document.getElementById("introSpot");
        var card = document.getElementById("introCard");
        var beak = document.getElementById("introBeak");
        var slides = box.querySelectorAll(".intro-slide");
        if (!spot || !card || !beak) { return null; }
        var idx = -1;
        for (var i = 0; i < slides.length; i++) {
            if (slides[i].classList.contains("is-on")) { idx = i; }
        }
        var sel = idx < 0 ? "" : (slides[idx].getAttribute("data-target") || "");
        var sr = spot.getBoundingClientRect();
        var cr = card.getBoundingClientRect();
        // ⛔ عنصرِ هدف از علامتی خوانده می‌شود که **خودِ app.js** روی آن
        //    گذاشته، نه با اجرای دوباره‌ی قاعده‌ی «اولین نامزدِ
        //    دیده‌شدنی». با پیاده‌سازیِ دوم، probe نسخه‌ی خودش را
        //    می‌سنجید و با خرابیِ آن قاعده هم سبز می‌ماند — همان دامِ
        //    بخشِ CSV در test_tx_search. (⚠ بک‌تیک اینجا ممنوع است: این
        //    متن داخلِ یک template literal است — دامِ قاعده ۳۶.)
        var he = document.querySelector("[data-intro-hit]");
        var t = he ? he.getBoundingClientRect() : null;
        var hit = he ? (he.id || String(he.className || "")) : "";
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        return {
            idx: idx,
            sel: sel,
            hit: hit,
            full: card.classList.contains("is-full"),
            hasTarget: spot.classList.contains("has-target"),
            spot: { t: Math.round(sr.top), l: Math.round(sr.left),
                    w: Math.round(sr.width), h: Math.round(sr.height) },
            card: { t: Math.round(cr.top), l: Math.round(cr.left),
                    w: Math.round(cr.width), h: Math.round(cr.height) },
            target: t ? { t: Math.round(t.top), l: Math.round(t.left),
                          w: Math.round(t.width), h: Math.round(t.height) } : null,
            // ⛔ هم‌پوشانیِ **دوبعدی**، نه فقط عمودی: نسخه‌ی اول عمودی بود و
            //    روی نوارِ کناریِ دسکتاپ (که از بالا تا پایین کشیده شده)
            //    هشدارِ الکی می‌داد، در حالی که کارت کنارِ آن نشسته بود.
            covers: t ? (cr.left < t.right && cr.right > t.left
                      && cr.top < t.bottom && cr.bottom > t.top) : false,
            // حفره باید کلِ عنصر را در بر بگیرد
            wraps: t ? (sr.top <= t.top + 1 && sr.bottom >= t.bottom - 1
                     && sr.left <= t.left + 1 && sr.right >= t.right - 1) : null,
            inside: Math.round(sr.left) >= -1 && Math.round(sr.top) >= -1
                 && Math.round(sr.right) <= vw + 1 && Math.round(sr.bottom) <= vh + 1,
            // ⚠ سبکِ **محاسبه‌شده**، نه استایلِ درون‌خطی: نوک را CSS پنهان
            //   می‌کند و درون‌خطی خالی می‌ماند.
            beak: window.getComputedStyle(beak).display !== "none",
            // ⚠ با **لایه** سنجیده می‌شود نه با innerWidth: نوارِ اسکرول
            //   ۱۵ پیکسل اختلاف می‌ساخت و بررسی روی چیدمانِ سالم قرمز
            //   می‌شد. «صفحه را در بر می‌گیرد» یعنی کلِ همان لایه.
            //   (⚠ بک‌تیک اینجا ممنوع است: این متن داخلِ یک template
            //    literal است و همان دامِ قاعده ۳۶ را می‌زند.)
            fills: card.classList.contains("is-full")
                ? (function () {
                    var br = box.getBoundingClientRect();
                    return Math.round(cr.width) >= Math.round(br.width) - 1
                        && Math.round(cr.height) >= Math.round(br.height) - 1;
                })()
                : null,
            overflowX: document.documentElement.scrollWidth > vw + 1,
        };
    `;

    const tour = async (width, height) => {
        await send('Emulation.setDeviceMetricsOverride', {
            width, height, deviceScaleFactor: 1, mobile: width < 900,
        }, sid);
        await evalJs(clearMark);
        if (!(await load())) { return null; }
        const n = await evalJs('return document.querySelectorAll("#introModal .intro-slide").length;');
        const steps = [];
        for (let i = 0; i < n; i++) {
            if (i) { await evalJs('document.getElementById("introNext").click(); return 1;'); }
            await sleep(350);
            steps.push(await evalJs(STEP));
        }
        return steps;
    };

    out.tourMobile  = await tour(390, 844);
    out.tourDesktop = await tour(1400, 900);
    await send('Emulation.clearDeviceMetricsOverride', {}, sid);

    // نشانه را پاک نگذار: اجرای بعدیِ تست نباید به ترتیبِ اجراها بند باشد.
    await evalJs(clearMark);

    done(out);
})().catch((e) => {
    process.stdout.write(JSON.stringify({ ok: false, why: 'probe_error', detail: String(e && e.message) }) + '\n');
    process.exit(0);
});
