/**
 * خروجی گرفتن از پیاده‌سازی JS تاریخ شمسی، برای مقایسه با PHP.
 *
 * عمداً خودِ فایل واقعی assets/js/jalali-datepicker.js را بارگذاری می‌کند
 * و نه یک کپی — وگرنه تست چیزی را می‌آزماید که کاربر اجرا نمی‌کند.
 *
 * آن فایل برای مرورگر نوشته شده، پس کمترین شبیه‌سازی لازم را می‌سازیم.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const src = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'jalali-datepicker.js'), 'utf8');

const noop = () => {};
const fakeEl = {
    addEventListener: noop, appendChild: noop, setAttribute: noop,
    classList: { add: noop, remove: noop, toggle: noop, contains: () => false },
    style: {}, dataset: {}, querySelector: () => null, querySelectorAll: () => [],
};
const sandbox = {
    window: {},
    document: {
        addEventListener: noop,
        createElement: () => Object.assign({}, fakeEl),
        querySelector: () => null,
        querySelectorAll: () => [],
        body: Object.assign({}, fakeEl),
        readyState: 'complete',
    },
    console,
};
sandbox.window.document = sandbox.document;

vm.createContext(sandbox);
try {
    vm.runInContext(src, sandbox, { filename: 'jalali-datepicker.js' });
} catch (e) {
    console.error('ERR_LOAD:' + e.message);
    process.exit(2);
}

const api = sandbox.window.JalaliDatePicker;
if (!api || !api.jalaliToGregorian || !api.gregorianToJalali) {
    console.error('ERR_EXPORT: window.JalaliDatePicker صادر نشد');
    process.exit(2);
}

// همان بازه‌ای که تست PHP استفاده می‌کند
const [fromY, toY] = [parseInt(process.argv[2] || '1300', 10),
                      parseInt(process.argv[3] || '1500', 10)];
const out = [];
for (let jy = fromY; jy <= toY; jy++) {
    for (let jm = 1; jm <= 12; jm++) {
        const len = jm <= 6 ? 31 : 31; // طول واقعی را PHP تعیین می‌کند؛
        // اینجا تا ۳۱ می‌رویم و PHP فقط سطرهای مشترک را مقایسه می‌کند.
        for (let jd = 1; jd <= len; jd++) {
            const g = api.jalaliToGregorian(jy, jm, jd);
            const b = api.gregorianToJalali(g[0], g[1], g[2]);
            out.push(`${jy}/${jm}/${jd}\t${g[0]}-${g[1]}-${g[2]}\t${b[0]}/${b[1]}/${b[2]}`);
        }
    }
}
process.stdout.write(out.join('\n') + '\n');
