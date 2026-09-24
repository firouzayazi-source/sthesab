/**
 * اجرای `window.skeletonHtml()` در node، برای `tests/test_visual_polish.php`.
 *
 * عمداً خودِ فایلِ واقعی `assets/js/app.js` را بارگذاری می‌کند و نه یک
 * کپی — وگرنه تست چیزی را می‌آزماید که کاربر اجرا نمی‌کند. همان الگوی
 * `tests/sms_js_dump.js` و `tests/jalali_js_dump.js`.
 *
 * ورودی: JSON آرایه‌ای از تعدادِ ردیف (هر مقداری، حتی نامعتبر) روی stdin.
 * خروجی: JSON آرایه‌ای از رشته‌ی HTMLِ برگشتی، به همان ترتیب.
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
try {
    vm.runInContext(fs.readFileSync(path.join(assets, 'app.js'), 'utf8'), sandbox,
        { filename: 'app.js' });
} catch (e) {
    console.error('ERR_LOAD:app.js: ' + e.message);
    process.exit(2);
}

if (typeof sandbox.window.skeletonHtml !== 'function') {
    console.error('ERR_MISSING:skeletonHtml');
    process.exit(3);
}

let raw = '';
process.stdin.on('data', (c) => { raw += c; });
process.stdin.on('end', () => {
    let items;
    try { items = JSON.parse(raw); } catch (e) {
        console.error('ERR_INPUT:' + e.message);
        process.exit(4);
    }
    const out = items.map((n) => {
        try { return String(sandbox.window.skeletonHtml(n)); }
        catch (e) { return 'ERR:' + e.message; }
    });
    process.stdout.write(JSON.stringify(out));
});
