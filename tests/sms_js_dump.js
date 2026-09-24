/**
 * اجرای پارسرِ پیامکِ بانک در node، برای تستِ PHP.
 *
 * عمداً خودِ فایل واقعی assets/js/app.js را بارگذاری می‌کند و نه یک
 * کپی — وگرنه تست چیزی را می‌آزماید که کاربر اجرا نمی‌کند. همان
 * الگوی tests/jalali_js_dump.js.
 *
 * jalali-datepicker.js هم لازم است، چون parseBankSms تاریخِ شمسی را
 * با همان تبدیل می‌کند (پیاده‌سازیِ سومِ تقویم نداریم).
 *
 * ورودی: JSON آرایه‌ای از متن‌ها روی stdin.
 * خروجی: JSON آرایه‌ای از نتیجه‌ها، به همان ترتیب.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const assets = path.join(__dirname, '..', 'assets', 'js');
const noop = () => {};
const fakeEl = {
    addEventListener: noop, appendChild: noop, setAttribute: noop,
    getAttribute: () => null, removeAttribute: noop, closest: () => null,
    classList: { add: noop, remove: noop, toggle: () => false, contains: () => false },
    style: {}, dataset: {}, options: [],
    querySelector: () => null, querySelectorAll: () => [],
};
const sandbox = {
    window: {},
    document: {
        addEventListener: noop,
        createElement: () => Object.assign({}, fakeEl),
        getElementById: () => null,
        querySelector: () => null,
        querySelectorAll: () => [],
        documentElement: Object.assign({}, fakeEl),
        body: Object.assign({}, fakeEl),
        readyState: 'loading',
    },
    localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    navigator: { userAgent: 'node', serviceWorker: undefined },
    location: { pathname: '/', href: 'http://localhost/', search: '' },
    getComputedStyle: () => ({ getPropertyValue: () => '' }),
    matchMedia: () => ({ matches: false, addEventListener: noop, addListener: noop }),
    setTimeout, clearTimeout, setInterval, clearInterval, console,
};
sandbox.window.document = sandbox.document;
sandbox.window.localStorage = sandbox.localStorage;
// مقدارِ موقتِ یک کلید در localStorageِ sandbox، فقط برای یک فراخوانی.
const withStored = (val, fn) => {
    const g = sandbox.localStorage.getItem;
    sandbox.localStorage.getItem = () => val;
    try { return fn(); } finally { sandbox.localStorage.getItem = g; }
};
sandbox.window.navigator = sandbox.navigator;
sandbox.window.location = sandbox.location;
sandbox.window.matchMedia = sandbox.matchMedia;
sandbox.window.addEventListener = noop;

vm.createContext(sandbox);
for (const file of ['jalali-datepicker.js', 'app.js']) {
    try {
        vm.runInContext(fs.readFileSync(path.join(assets, file), 'utf8'), sandbox,
            { filename: file });
    } catch (e) {
        console.error('ERR_LOAD:' + file + ': ' + e.message);
        process.exit(2);
    }
}

for (const fn of ['parseBankSms', 'smsAutoOk', 'smsFingerprint', 'smsAutoEnabled']) {
    if (typeof sandbox.window[fn] !== 'function') {
        console.error('ERR_EXPORT: window.' + fn + ' صادر نشد');
        process.exit(2);
    }
}

let input = '';
process.stdin.on('data', (c) => { input += c; });
process.stdin.on('end', () => {
    let cases;
    try { cases = JSON.parse(input); } catch (e) {
        console.error('ERR_INPUT:' + e.message);
        process.exit(2);
    }
    const out = cases.map((t) => {
        try {
            // ⚠ ورودیِ نشانه‌دار: `smsAutoOk` را با آشغال صدا می‌زند.
            //   تستِ PHP از این‌طرف نمی‌تواند مستقیم صدایش بزند، و
            //   بدونِ آن شرطِ `!r || !r.ok` **افزونه** به نظر می‌رسید
            //   (بقیه‌ی شرط‌ها هم آن حالت را می‌گرفتند) — تا وقتی که
            //   ورودیِ null باشد و آن‌وقت خطای کشنده بدهد.
            if (t === '__PROBE_JUNK__') {
                const call = (x) => {
                    try { return sandbox.window.smsAutoOk(x, true); }
                    catch (e) { return { ok: null, why: 'THREW: ' + e.message }; }
                };
                return {
                    ok: false,
                    junkNull: call(null),
                    junkUndef: call(undefined),
                    junkEmpty: call({}),
                    // ⛔ localStorage در این sandbox همیشه خالی است، یعنی
                    //    دقیقاً حالتِ «کاربر هیچ‌وقت روشنش نکرده».
                    autoDefault: sandbox.window.smsAutoEnabled(),
                    // ⚠ دو حالتِ دیگرِ کلید: خاموشِ صریح (`'0'`) و روشنِ
                    //   قدیمی (`'1'`، کسی که پیش از این دستی روشنش کرده بود).
                    autoOff:    withStored('0', () => sandbox.window.smsAutoEnabled()),
                    autoLegacy: withStored('1', () => sandbox.window.smsAutoEnabled()),
                    // ⛔ اعلانِ گوشی (Web Push): همان جدولِ تصمیم، خالص.
                    //    [اجازه، خاموشِ صریح، قبلاً پرسیده، اپِ نصب‌شده]
                    pushAuto: [
                        ['granted', null, null, false],
                        ['granted', '1',  null, true],
                        ['default', null, null, true],
                        ['default', null, null, false],
                        ['default', null, '1',  true],
                        ['denied',  null, null, true],
                    ].map(a => sandbox.window.pushAutoAction(...a)),
                };
            }
            const r = sandbox.window.parseBankSms(t);
            // ⚠ هر دو حالتِ «مقصد قطعی / مبهم» با هم برمی‌گردند تا تستِ
            //   PHP بتواند هر دو را بسنجد بی‌آنکه شکلِ ورودی عوض شود.
            r.autoWithWallet = sandbox.window.smsAutoOk(r, true);
            r.autoNoWallet   = sandbox.window.smsAutoOk(r, false);
            r.fingerprint    = sandbox.window.smsFingerprint(t);
            return r;
        }
        catch (e) { return { ok: false, reason: 'THREW: ' + e.message }; }
    });
    process.stdout.write(JSON.stringify(out));
});
