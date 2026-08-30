/*
 * سرویس‌ورکر دفتر مالی.
 *
 * چرا در ریشه‌ی اپ است و نه داخل assets/: دامنه‌ی کنترلِ یک سرویس‌ورکر
 * نمی‌تواند بالاتر از پوشه‌ی خودش برود. اگر داخل assets/ می‌بود فقط
 * همان پوشه را کنترل می‌کرد و ناوبری صفحه‌ها اصلاً از او رد نمی‌شد.
 *
 * ⚠ قاعده‌ی مرکزی: **هیچ HTML ای کش نمی‌شود.**
 *
 * همه‌ی صفحه‌های این اپ عمداً `Cache-Control: no-store, private` می‌گیرند
 * (در Auth::initSession). دلیلش یک باگ واقعی بود: سافاری روی آیفون نسخه‌ی
 * کش‌شده را نشان می‌داد، کاربر چیزی را حذف می‌کرد، صفحه تازه می‌شد و باز
 * همان عدد قدیمی می‌آمد. اگر سرویس‌ورکر HTML را کش کند، دقیقاً همان باگ
 * برمی‌گردد — این بار بدتر، چون کش سرویس‌ورکر با تازه‌سازی صفحه هم پاک
 * نمی‌شود. پس اینجا فقط دارایی‌های ثابت کش می‌شوند: CSS، JS، فونت، آیکون.
 *
 * دستاورد: باز شدن آنی از آیکون صفحه‌ی اصلی، و یک صفحه‌ی «اینترنت نیست»
 * درست به‌جای صفحه‌ی خطای مرورگر.
 *
 * نسخه را با هر تغییرِ فهرست پیش‌کش بالا ببرید؛ نسخه‌های قدیمی هنگام
 * activate پاک می‌شوند.
 */

const VERSION    = 'daftar-v1';
const ASSET_CACHE = VERSION + '-assets';

// مسیرها نسبت به خودِ این فایل حل می‌شوند، پس نصب در زیرپوشه هم کار
// می‌کند و نیازی نیست APP_BASE_PATH را اینجا تکرار کنیم.
const rel = (p) => new URL(p, self.location).toString();

const PRECACHE = [
    rel('offline.html'),
    rel('assets/css/style.css'),
    rel('assets/js/app.js'),
    rel('assets/js/jalali-datepicker.js'),
    rel('assets/js/chart.umd.js'),
    rel('assets/fonts/Vazirmatn.woff2'),
    rel('assets/icons/icon-192.png'),
    rel('assets/icons/icon-512.png'),
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(ASSET_CACHE);
        // تک‌تک، نه addAll: اگر یکی از فایل‌ها ۴۰۴ بدهد، addAll کلِ نصب را
        // رد می‌کند و سرویس‌ورکر هرگز فعال نمی‌شود — یعنی یک فایل جامانده
        // کل قابلیت را بی‌سروصدا خاموش می‌کند.
        await Promise.all(PRECACHE.map(async (url) => {
            try { await cache.add(new Request(url, { cache: 'reload' })); }
            catch (e) { /* این یکی نبود؛ بقیه کار خودشان را می‌کنند */ }
        }));
        self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names.filter((n) => n.startsWith('daftar-') && !n.startsWith(VERSION))
                 .map((n) => caches.delete(n))
        );
        await self.clients.claim();
    })());
});

/** آیا این درخواست یک دارایی ثابتِ خودِ ماست؟ */
function isStaticAsset(url) {
    if (url.origin !== self.location.origin) { return false; }
    const base = new URL('./', self.location).pathname;      // ریشه‌ی نصب
    if (!url.pathname.startsWith(base + 'assets/')) { return false; }
    // manifest.php و serve.php خودشان PHP اند و هدر کش خودشان را دارند؛
    // نباید در لایه‌ی سرویس‌ورکر دوباره کش شوند.
    return !url.pathname.endsWith('.php');
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') { return; }

    const url = new URL(req.url);

    // ---------- ناوبری: همیشه از شبکه ----------
    // هرگز از کش پاسخ داده نمی‌شود؛ کش فقط برای وقتی است که شبکه اصلاً
    // نیست، و آن هم صفحه‌ی «اینترنت نیست» است نه نسخه‌ی کهنه‌ی داده.
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                return await fetch(req);
            } catch (e) {
                const cache = await caches.open(ASSET_CACHE);
                const offline = await cache.match(rel('offline.html'));
                return offline || new Response(
                    'اینترنت در دسترس نیست.',
                    { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
                );
            }
        })());
        return;
    }

    // ---------- دارایی‌های ثابت: کش، و در پس‌زمینه تازه‌سازی ----------
    if (!isStaticAsset(url)) { return; }     // بقیه (api/ و ...) دست‌نخورده به شبکه

    event.respondWith((async () => {
        const cache  = await caches.open(ASSET_CACHE);
        const cached = await cache.match(req);

        const fresh = fetch(req).then((res) => {
            if (res && res.ok && res.type === 'basic') { cache.put(req, res.clone()); }
            return res;
        }).catch(() => null);

        // اگر کش داشتیم فوراً همان، و نسخه‌ی تازه برای دفعه‌ی بعد.
        return cached || (await fresh) || new Response('', { status: 504 });
    })());
});
