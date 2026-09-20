/**
 * اجرای گزارش‌گرِ خطای مرورگرِ `assets/js/app.js` در node، برای تستِ PHP.
 *
 * عمداً خودِ فایلِ واقعی را بارگذاری می‌کند و نه یک کپی — وگرنه تست چیزی
 * را می‌آزماید که کاربر اجرا نمی‌کند. همان الگوی `tests/kb_js_dump.js`.
 *
 * دو چیز را سنجیدنی می‌کند و **هر دو لازم‌اند**:
 *   • `predicate` — خودِ `window.isOpaqueClientError()`.
 *   • `events`    — مسیرِ واقعی: شنونده‌ی `error` گرفته و آتش زده می‌شود
 *                   و دیده می‌شود چه چیزی به `fetch` رفت. بدونِ این،
 *                   جهشِ «فراخوانیِ صافی را از `report()` بردار» زنده
 *                   می‌ماند، چون خودِ صافی هنوز درست جواب می‌دهد.
 *
 * ورودی (stdin، JSON):
 *   { "predicate": [[message, file, line, stack], …],
 *     "events":    [{ "message":…, "filename":…, "lineno":…, "stack":… }, …] }
 * خروجی (stdout، JSON):
 *   { "predicate": [true|false, …], "sent": ["<پیامِ فرستاده‌شده>", …] }
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

/** شنونده‌های پنجره، تا بتوانیم مسیرِ واقعی را آتش بزنیم. */
const listeners = {};
/** هر چیزی که گزارش‌گر به سرور فرستاد. */
const posted = [];

class FakeFormData {
    constructor() { this.map = {}; }
    set(k, v) { this.map[k] = String(v); }
    get(k) { return this.map[k]; }
}

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
    location: { pathname: '/index.php', href: 'http://localhost/', search: '' },
    getComputedStyle: () => ({ getPropertyValue: () => '' }),
    matchMedia: () => ({ matches: false, addEventListener: noop, addListener: noop }),
    FormData: FakeFormData,
    // ⚠ عمداً `window.fetch` را تعریف نمی‌کنیم: بلوکِ بسته‌بندیِ
    //   `X-Request-Id` آن را می‌پیچد و اینجا موضوعیت ندارد. گزارش‌گر
    //   خودش `fetch` سراسری را صدا می‌زند.
    fetch: (url, opts) => {
        posted.push({ url: String(url), body: opts && opts.body });
        return { catch: () => {} };
    },
    setTimeout, clearTimeout, setInterval, clearInterval, console,
};
sandbox.window.document = sandbox.document;
sandbox.window.localStorage = sandbox.localStorage;
sandbox.window.navigator = sandbox.navigator;
sandbox.window.location = sandbox.location;
sandbox.window.matchMedia = sandbox.matchMedia;
sandbox.window.addEventListener = (type, fn) => {
    (listeners[type] = listeners[type] || []).push(fn);
};

vm.createContext(sandbox);
try {
    vm.runInContext(fs.readFileSync(path.join(assets, 'app.js'), 'utf8'), sandbox,
        { filename: 'app.js' });
} catch (e) {
    console.error('ERR_LOAD:app.js: ' + e.message);
    process.exit(2);
}

if (typeof sandbox.window.isOpaqueClientError !== 'function') {
    console.error('ERR_MISSING:isOpaqueClientError');
    process.exit(3);
}
if (!listeners.error || listeners.error.length === 0) {
    console.error('ERR_MISSING:error-listener');
    process.exit(5);
}

let raw = '';
process.stdin.on('data', (c) => { raw += c; });
process.stdin.on('end', () => {
    let input;
    try { input = JSON.parse(raw); } catch (e) {
        console.error('ERR_INPUT:' + e.message);
        process.exit(4);
    }

    const predicate = (input.predicate || []).map((a) => {
        try { return sandbox.window.isOpaqueClientError(a[0], a[1], a[2], a[3]) === true; }
        catch (e) { return 'ERR:' + e.message; }
    });

    for (const ev of (input.events || [])) {
        const e = {
            message: ev.message,
            filename: ev.filename || '',
            lineno: ev.lineno || 0,
            error: ev.stack ? { stack: ev.stack } : null,
        };
        for (const fn of listeners.error) { fn(e); }
    }

    process.stdout.write(JSON.stringify({
        predicate,
        sent: posted.map((p) => (p.body && p.body.get ? p.body.get('message') : '')),
    }));
});
