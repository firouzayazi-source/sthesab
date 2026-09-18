/**
 * اجرای `window.introSeen()` / `window.introMarkSeen()` در node.
 *
 * عمداً خودِ `assets/js/app.js` را بارگذاری می‌کند و نه یک کپی — همان
 * الگوی `kb_js_dump.js` و `sms_js_dump.js`. تستی که کپیِ خودش را
 * بسنجد، با خرابیِ فایلِ واقعی هم سبز می‌ماند.
 *
 * ورودی: JSON روی stdin — آرایه‌ای از سناریوها:
 *        {"store": null}            → اصلاً `localStorage` نیست (پنجره‌ی ناشناس)
 *        {"store": {}}              → هست ولی خالی (کاربرِ تازه)
 *        {"store": {"<key>": "1"}}  → نشانه از قبل خورده
 * خروجی: JSON — به‌ازای هر سناریو `{seen, afterMark, wrote}`.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const noop = () => {};
const fakeEl = {
    addEventListener: noop, appendChild: noop, setAttribute: noop,
    getAttribute: () => null, removeAttribute: noop, closest: () => null,
    classList: { add: noop, remove: noop, toggle: () => false, contains: () => false },
    style: {}, dataset: {}, options: [],
    querySelector: () => null, querySelectorAll: () => [],
};

function makeSandbox(store) {
    // ⛔ `store === null` یعنی دسترسی به `localStorage` **استثنا پرتاب
    //    می‌کند** — همان چیزی که در پنجره‌ی ناشناس واقعاً می‌افتد. با
    //    یک شیِ ساکت، شاخه‌ی `catch` هرگز آزموده نمی‌شد.
    const ls = store === null
        ? { getItem() { throw new Error('denied'); },
            setItem() { throw new Error('denied'); } }
        : { getItem: (k) => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v); } };

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
        localStorage: ls,
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
    return sandbox;
}

const src = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'app.js'), 'utf8');

let raw = '';
process.stdin.on('data', (c) => { raw += c; });
process.stdin.on('end', () => {
    let cases;
    try { cases = JSON.parse(raw); } catch (e) {
        console.error('ERR_INPUT:' + e.message);
        process.exit(4);
    }

    const out = cases.map((c) => {
        const store = c.store === null ? null : Object.assign({}, c.store);
        const sandbox = makeSandbox(store);
        vm.createContext(sandbox);
        try {
            vm.runInContext(src, sandbox, { filename: 'app.js' });
        } catch (e) {
            return { error: 'LOAD:' + e.message };
        }
        if (typeof sandbox.window.introSeen !== 'function'
            || typeof sandbox.window.introMarkSeen !== 'function') {
            return { error: 'MISSING' };
        }
        const key  = sandbox.window.INTRO_SEEN_KEY;
        const seen = sandbox.window.introSeen() === true;
        sandbox.window.introMarkSeen();
        return {
            key: key,
            seen: seen,
            afterMark: sandbox.window.introSeen() === true,
            wrote: store !== null && store[key] === '1',
        };
    });

    process.stdout.write(JSON.stringify(out));
});
