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

if (typeof sandbox.window.parseBankSms !== 'function') {
    console.error('ERR_EXPORT: window.parseBankSms صادر نشد');
    process.exit(2);
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
        try { return sandbox.window.parseBankSms(t); }
        catch (e) { return { ok: false, reason: 'THREW: ' + e.message }; }
    });
    process.stdout.write(JSON.stringify(out));
});
