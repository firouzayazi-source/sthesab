<?php
/**
 * تست قرارداد اندپوینت‌های api/ — قواعدی که کل امنیت اپ رویشان بنا شده.
 *
 * این‌ها تا امروز فقط در CLAUDE.md نوشته شده بودند و هیچ چیز جز چشم آدم
 * تضمینشان نمی‌کرد. یک اندپوینت تازه که یادش برود بررسی ورود بگذارد،
 * بی‌سروصدا داده‌ی همه‌ی کاربران را باز می‌کند.
 *
 * این تست ساختار کد را می‌آزماید نه رفتار را، پس بدون دیتابیس و سرور
 * هم اجرا می‌شود. تست رفتاری در test_api_auth.php است.
 */

// ---------- نگهبان: فقط خط فرمان ----------
// این فایل داخل ریشه‌ی وب است و بدون این نگهبان، هر کسی می‌توانست با
// باز کردن آدرسش در مرورگر تست را روی دیتابیس واقعی اجرا کند — تست‌ها
// کاربر و رکورد می‌سازند و پاک می‌کنند.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}


require_once __DIR__ . '/lib/assert.php';

$apiDir = __DIR__ . '/../api';
$files  = glob($apiDir . '/*.php');
sort($files);

// اندپوینت‌های فقط-خواندنی که عمداً CSRF ندارند.
// اگر روزی یکی از این‌ها نویسنده شد، باید از این فهرست برداشته شود.
$csrfExempt = [
    'category_transactions.php',
    'day_detail.php',
    'savings_history.php',
    'transaction_attachments.php',
    'view_attachment.php',
];

// ---------------------------------------------------------------
T::group('همه‌ی اندپوینت‌ها پیدا شدند');
T::ok(count($files) >= 40, 'تعداد اندپوینت‌های api/', 'پیدا شد: ' . count($files));

// ---------------------------------------------------------------
T::group('قاعده ۱ — هر اندپوینت باید ورود کاربر را بررسی کند');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    $hasAuthInclude = str_contains($src, "auth.php");
    $hasAuthCheck   = str_contains($src, 'Auth::isLoggedIn')
                   || str_contains($src, 'Auth::requireLogin')
                   || str_contains($src, 'Auth::requireAdmin');
    if (!$hasAuthInclude || !$hasAuthCheck) {
        $bad[] = $name . ($hasAuthInclude ? ' — auth.php لود شده ولی بررسی نمی‌کند' : ' — auth.php اصلاً لود نشده');
    }
}
T::bulk(count($files), $bad, 'هر اندپوینت auth.php را لود و ورود را بررسی می‌کند');

// ---------------------------------------------------------------
T::group('قاعده ۲ — هر اندپوینت نویسنده باید CSRF را بررسی کند');

$bad = [];
$writers = 0;
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    if (in_array($name, $csrfExempt, true)) { continue; }
    $writers++;
    if (!str_contains($src, 'Csrf::verifyOrFail')) {
        $bad[] = "$name — نه در فهرست معافیت است، نه Csrf::verifyOrFail دارد";
    }
}
T::bulk($writers, $bad, 'هر اندپوینت خارج از فهرست معافیت، CSRF را بررسی می‌کند');

// فهرست معافیت نباید بپوسد: فایلی که دیگر وجود ندارد باید حذف شود
$bad = [];
foreach ($csrfExempt as $name) {
    if (!file_exists($apiDir . '/' . $name)) {
        $bad[] = "$name در فهرست معافیت هست ولی فایلش وجود ندارد";
    }
}
T::bulk(count($csrfExempt), $bad, 'فهرست معافیت CSRF به‌روز است');

// معاف‌ها واقعاً باید فقط-خواندنی باشند
$bad = [];
foreach ($csrfExempt as $name) {
    $p = $apiDir . '/' . $name;
    if (!file_exists($p)) { continue; }
    $src = file_get_contents($p);
    if (preg_match('/->\s*(prepare|query)\s*\(\s*["\'][^"\']*\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER)\b/i', $src, $m)) {
        $bad[] = "$name معاف از CSRF است ولی کوئری نویسنده دارد ({$m[2]})";
    }
}
T::bulk(count($csrfExempt), $bad, 'اندپوینت‌های معاف واقعاً فقط-خواندنی‌اند');

// ---------------------------------------------------------------
T::group('قاعده ۳ — user_id هرگز از ورودی کاربر نمی‌آید');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    // گرفتن user_id از POST/GET یعنی هر کاربری می‌تواند خود را جای دیگری بزند
    if (preg_match('/(postParam|getParam)\s*\(\s*[\'"]user_id[\'"]/', $src)) {
        $bad[] = "$name — user_id را از ورودی کاربر می‌خواند";
    }
    if (preg_match('/\$_(POST|GET|REQUEST)\s*\[\s*[\'"]user_id[\'"]\s*\]/', $src)) {
        $bad[] = "$name — user_id را مستقیم از \$_POST/\$_GET می‌خواند";
    }
}
T::bulk(count($files), $bad, 'هیچ اندپوینتی user_id را از ورودی نمی‌گیرد');

// ---------------------------------------------------------------
T::group('قاعده ۴ — کوئری‌ها پارامتری‌اند');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    // درج مستقیم متغیر داخل رشته‌ی SQL
    if (preg_match_all('/->\s*(prepare|query)\s*\(\s*"[^"]*\$[A-Za-z_]/', $src, $m)) {
        foreach ($m[0] as $hit) {
            $bad[] = "$name — متغیر داخل رشته‌ی SQL: " . trim(substr($hit, 0, 60));
        }
    }
}
T::bulk(count($files), $bad, 'هیچ متغیری مستقیم داخل رشته‌ی SQL درج نشده');

// ---------------------------------------------------------------
T::group('قاعده ۵ — هر خواندنِ دسته‌بندی از categoryScopeSql رد می‌شود');

// چرا: `categories.user_id` سه‌حالتی است — NULL یعنی پیش‌فرضِ برنامه و
// عدد یعنی دسته‌ی شخصیِ همان کاربر. اگر کوئری‌ای این شرط را نگذارد،
// دسته‌های شخصیِ بقیه را هم برمی‌دارد. یک بار در `data.php` همین شد:
// فهرست کامل هم برای تطبیق نام استفاده می‌شد و هم با
// `window.IMPORT_CATEGORIES` به مرورگر می‌رفت، پس نامِ دسته‌های خصوصیِ
// همه‌ی کاربران در سورس صفحه دیده می‌شد.
//
// دو استثنا، هر دو عمدی:
//   admin/categories.php  — با `user_id IS NULL` فقط پیش‌فرض‌ها را می‌بیند
//   api/manage_reference.php — با `user_id = :u` فقط مالِ خودِ کاربر
$catFiles = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../api/*.php') ?: [],
    glob(__DIR__ . '/../includes/*.php') ?: []
);
$bad = [];
$checked = 0;
foreach ($catFiles as $f) {
    // کامنت‌ها کنار گذاشته می‌شوند، وگرنه همین توضیحی که بالای یک کوئریِ
    // ناایمن نوشته شده کافی است تا تست را گول بزند.
    $code = '';
    foreach (token_get_all((string)file_get_contents($f)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $code .= is_array($t) ? $t[1] : $t;
    }

    // هر «FROM categories» جداگانه سنجیده می‌شود، نه کلِ فایل — یک کوئریِ
    // امن در بالای فایل نباید کوئریِ ناایمنِ پایینش را بپوشاند.
    if (!preg_match_all('/FROM\s+categories\b/i', $code, $m, PREG_OFFSET_CAPTURE)) { continue; }
    foreach ($m[0] as [$hit, $at]) {
        $checked++;
        // پنجره تا پایان همان دستور PHP (اولین `;`) بریده می‌شود، نه تعداد
        // ثابتی کاراکتر. نسخه‌ی اول ۶۰۰ کاراکتر می‌گرفت و در `data.php` به
        // کوئریِ بعدی سرریز می‌کرد — آنجا `FROM wallets WHERE user_id = :u`
        // بود و تست، شرطِ *کیف پول* را به حساب دسته‌بندی می‌گذاشت و سبز
        // نشان می‌داد.
        $end    = strpos($code, ';', $at);
        $window = substr($code, $at, $end === false ? 600 : $end - $at);
        // شرط باید در WHERE/AND باشد. `ORDER BY (user_id IS NULL)` یک شرطِ
        // صافی نیست — فقط مرتب‌سازی است — ولی نسخه‌ی دومِ همین تست آن را
        // به‌جای شرط می‌گرفت و کوئریِ ناایمن را سبز نشان می‌داد.
        $ownership = '/\b(WHERE|AND)\s+\(?\s*[a-z_]*\.?user_id\s+(IS\s+NULL|=\s*:)/i';
        $scoped = strpos($window, 'categoryScopeSql') !== false
            || preg_match($ownership, $window);
        if (!$scoped) {
            $bad[] = basename($f) . ' — «FROM categories» بدون شرط مالکیت (بایت ' . $at . ')';
        }
    }
}
T::ok($checked >= 8, 'کوئری‌های دسته‌بندی پیدا شدند', 'بررسی‌شده: ' . $checked);
T::bulk($checked, $bad, 'هر خواندنِ دسته‌بندی شرط مالکیت دارد');

// ---------------------------------------------------------------
T::group('قاعده ۶ — سرویس‌ورکر هیچ HTML ای کش نمی‌کند');

// چرا: همه‌ی صفحه‌ها عمداً `Cache-Control: no-store, private` می‌گیرند،
// چون سافاری روی آیفون نسخه‌ی کش‌شده را نشان می‌داد و کاربر بعد از حذف
// یک تراکنش باز همان عدد قدیمی را می‌دید. اگر سرویس‌ورکر HTML را کش
// کند همان باگ برمی‌گردد — این بار بدتر، چون کشِ سرویس‌ورکر با
// تازه‌سازیِ صفحه هم پاک نمی‌شود.
$swFile = __DIR__ . '/../sw.js';
if (!file_exists($swFile)) {
    T::skip('تست سرویس‌ورکر', 'sw.js وجود ندارد');
} else {
    $sw = (string)file_get_contents($swFile);

    T::ok(file_exists(__DIR__ . '/../offline.html'), 'صفحه‌ی آفلاین وجود دارد');

    // فهرست پیش‌کش نباید هیچ فایل PHP ای داشته باشد
    $pre = [];
    if (preg_match('/const PRECACHE\s*=\s*\[(.*?)\];/s', $sw, $mm)) {
        preg_match_all("/rel\('([^']+)'\)/", $mm[1], $pp);
        $pre = $pp[1];
    }
    T::ok(count($pre) > 0, 'فهرست پیش‌کش خوانده شد', 'تعداد: ' . count($pre));
    $php = array_values(array_filter($pre, fn($u) => str_contains($u, '.php')));
    T::bulk(count($pre), array_map(fn($u) => "پیش‌کشِ PHP: $u", $php), 'هیچ فایل PHP ای پیش‌کش نمی‌شود');

    // بعد از هر deploy آدرس app.js عوض می‌شود، پس کش خطا می‌خورد و فایل
    // باید از شبکه بیاید. اگر آن یک درخواست روی اینترنت موبایل گیر کند،
    // صفحه کامل بالا می‌آید ولی هیچ دکمه‌ای کار نمی‌کند — بی‌هیچ نشانه‌ای.
    // پس نبودِ فایل در کش باید تورِ نجات داشته باشد: نسخه‌ی قبلیِ همان
    // فایل با ignoreSearch، و یک مهلت تا شبکه‌ی گیرکرده بی‌نهایت طول نکشد.
    T::ok(str_contains($sw, 'ignoreSearch: true'),
          'اگر نسخه‌ی تازه نرسید، نسخه‌ی قبلیِ همان فایل برگردانده می‌شود');
    T::ok((bool)preg_match('/NET_TIMEOUT|Promise\.race/', $sw),
          'انتظار برای شبکه مهلت دارد');
    T::ok(str_contains($sw, 'dropOtherVersions'),
          'نسخه‌های قدیمیِ هر فایل از کش پاک می‌شوند');

    // درخواست ناوبری باید شاخه‌ی جدا داشته باشد و از شبکه بیاید
    T::ok(str_contains($sw, "req.mode === 'navigate'"), 'ناوبری شاخه‌ی جداگانه دارد');
    T::ok(str_contains($sw, 'isStaticAsset'), 'کش فقط به دارایی‌های ثابت محدود شده');

    // دارایی کش‌شده نباید در پس‌زمینه دوباره گرفته شود.
    //
    // نسخه‌ی اول «stale-while-revalidate» بود و نتیجه‌اش بدتر از نداشتنِ
    // سرویس‌ورکر شد: در هر ناوبری ۶ فایل (حدود ۲۲۰ کیلوبایت) دوباره از
    // شبکه گرفته می‌شد، با اینکه همه کش بودند. اندازه‌گیری شد: ۶ درخواست
    // در هر جابه‌جایی، که با این اصلاح صفر شد.
    // نامِ متغیر مهم نیست، شکلِ رفتار مهم است: نتیجه‌ی cache.match(req)
    // اگر بود، همان‌جا برگردد — بدون هیچ fetch ای در میان.
    T::ok(
        (bool)preg_match('/const\s+(\w+)\s*=\s*await\s+cache\.match\(req\);\s*\n\s*if\s*\(\1\)\s*\{\s*return\s+\1;/', $sw),
        'دارایی کش‌شده بدون درخواست تازه برگردانده می‌شود'
    );
}

// ---------------------------------------------------------------
T::group('فایل‌های خط‌فرمانی از وب اجرا نشوند');

// چرا: tests/ و deploy/ داخل ریشه‌ی وب‌اند. سایت nginx این دو پوشه را
// می‌بندد، ولی آن یک لایه است و می‌تواند از دست برود (certbot فایل سایت
// را بازنویسی می‌کند، یا کسی روی هاست دیگری نصب می‌کند). تست‌ها کاربر و
// رکورد روی دیتابیس *واقعی* می‌سازند، پس نگهبان باید در خود فایل باشد.
$cliFiles = array_merge(
    glob(__DIR__ . '/test_*.php') ?: [],
    glob(__DIR__ . '/../deploy/*.php') ?: []
);
$bad = [];
foreach ($cliFiles as $f) {
    $src = (string)file_get_contents($f);
    if (strpos($src, "PHP_SAPI !== 'cli'") === false) {
        $bad[] = basename($f) . ' نگهبان «فقط خط فرمان» ندارد';
    }
}
T::bulk(count($cliFiles), $bad, 'هر فایل خط‌فرمانی نگهبان PHP_SAPI دارد');

// ---------------------------------------------------------------
T::group('مود فایل‌ها با گیت هم‌خوان است');

// چرا: deploy/vps-setup.sh هنگام نصب روی سرور، بیت اجرای همه‌ی .sh ها
// را روشن می‌کند. اگر گیت فایلی را ۶۴۴ ثبت کرده باشد، همان chmod آن را
// «تغییر محلی» می‌کند و git pull بعدی روی سرور شکست می‌خورد. یک بار
// دقیقاً همین اتفاق افتاد.
$root = realpath(__DIR__ . '/..');
$bad  = [];
$shFiles = [];
exec('cd ' . escapeshellarg($root) . ' && git ls-files -s -- "*.sh" 2>/dev/null', $lines, $rc);
if ($rc !== 0 || !$lines) {
    T::skip('مود اسکریپت‌های .sh', 'اطلاعات گیت در دسترس نیست');
} else {
    foreach ($lines as $line) {
        if (!preg_match('/^(\d{6})\s+\S+\s+\d+\s+(.+)$/', $line, $m)) { continue; }
        $shFiles[] = $m[2];
        if ($m[1] !== '100755') {
            $bad[] = "{$m[2]} در گیت با مود {$m[1]} ثبت شده — باید 100755 باشد";
        }
    }
    T::bulk(count($shFiles), $bad, 'همه‌ی اسکریپت‌های .sh در گیت اجرایی‌اند');
}

// ---------------------------------------------------------------
T::group('درج متغیر کنار گیومه‌ی فارسی');

// چرا: PHP بایت‌های ≥ 0x80 را جزء نام متغیر می‌داند. پس در رشته‌ی
// "کاربر «$username» فعال شد" نامِ متغیر «$username»» خوانده می‌شود —
// یعنی متغیری که وجود ندارد. نتیجه: هشدار «Undefined variable» و
// پیامی که وسطش خالی است. دقیقاً همین در user-admin.php افتاد و چون
// php -l آن را نمی‌گیرد، تا اجرای واقعی پیدا نشد.
//
// چاره: {$var} — آکولاد مرز نام را روشن می‌کند.
$phpFiles = [];
foreach (['api', 'includes', 'admin', 'deploy', 'config', 'tests', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $phpFiles[realpath($p)] = true; }
}
// با توکنایزر خود PHP بررسی می‌شود، نه با regex روی متن خام: این‌طور
// توضیحات و کامنت‌ها (که خودشان نمونه‌ی خراب دارند) اشتباهاً گیر نمی‌افتند.
// اگر PHP نام متغیر را با بایت غیر-ASCII خوانده باشد، همان باگ است.
$bad = [];
foreach (array_keys($phpFiles) as $p) {
    foreach (token_get_all(file_get_contents($p)) as $tok) {
        if (!is_array($tok) || $tok[0] !== T_VARIABLE) { continue; }
        if (preg_match('/[\x80-\xFF]/', $tok[1])) {
            $bad[] = basename($p) . ':' . $tok[2] . ' — نام متغیر ' . $tok[1] . ' — باید {$...} شود';
        }
    }
}
T::bulk(count($phpFiles), $bad, 'هیچ متغیری بی‌آکولاد به کاراکتر فارسی نچسبیده');

// ---------------------------------------------------------------
T::group('قاعده ۷ — نشستِ منقضی نباید بن‌بست بسازد');

// چرا: `Csrf::verifyOrFail` قبلاً روی توکنِ نامعتبر فقط `die()` با یک
// رشته‌ی خام می‌زد. کاربر روی گوشی یک صفحه‌ی سفید با یک خط متنِ بی‌قالب
// و بدون هیچ دکمه‌ای می‌دید. شایع‌ترین علتش هم حمله نبود: فرمی که
// ساعت‌ها باز مانده و نشست در این فاصله منقضی شده.
$csrfSrc = (string)file_get_contents(__DIR__ . '/../includes/csrf.php');

T::ok(
    !preg_match('/\bdie\s*\(\s*[\'"]/', $csrfSrc),
    'خطای CSRF با die خام پاسخ نمی‌دهد'
);
T::ok(str_contains($csrfSrc, '<!DOCTYPE html>'), 'صفحه‌ی خطای CSRF قالب کامل دارد');
T::ok(str_contains($csrfSrc, 'history.back()'), 'صفحه‌ی خطای CSRF راه برگشت دارد');

// صفحه‌ی ورود باید توکنِ کهنه را ببخشد و فرم را دوباره نشان دهد،
// نه اینکه کاربر را در صفحه‌ی خطا حبس کند — همان‌جا که بیشتر از هر جای
// دیگری فرم ساعت‌ها باز می‌ماند.
$loginSrc = (string)file_get_contents(__DIR__ . '/../login.php');
T::ok(
    str_contains($loginSrc, 'Csrf::validate') && !str_contains($loginSrc, 'Csrf::verifyOrFail'),
    'صفحه‌ی ورود با توکن کهنه فرم را دوباره نشان می‌دهد'
);

// مهلت را اپ تعیین می‌کند، نه پیش‌فرضِ ۲۴ دقیقه‌ایِ PHP. بدون این،
// گزینه‌ی «تا یک هفته وارد بمانم» در پروفایل عملاً دروغ بود.
$authSrc = (string)file_get_contents(__DIR__ . '/../includes/auth.php');
T::ok(
    preg_match('/session\.gc_maxlifetime/', $authSrc) === 1,
    'مهلت جمع‌آوری نشست با سیاست خود اپ تنظیم می‌شود'
);

// ---------------------------------------------------------------
T::group('قاعده ۸ — deploy.sh پوشه‌های نوشتنی را به کاربر اپ برمی‌گرداند');

// چرا: deploy.sh عمداً `chown -R root:root .` می‌زند تا کد را از دست
// PHP دور نگه دارد. ولی دو پوشه باید نوشتنی بمانند و بعد از آن دوباره
// به کاربر اپ برگردند: `uploads` و `var/sessions`.
//
// یک بار `var` جا افتاده بود و باگی ساخت که هیچ ردی در لاگ نداشت:
// مسیر نشستِ این pool داخل خود پوشه‌ی اپ است، پس PHP دیگر نمی‌توانست
// فایل نشست بنویسد. هر درخواست یک نشستِ خالیِ تازه می‌گرفت، توکن CSRF
// هرگز نمی‌ماند، و صفحه‌ی ورود در حلقه‌ی «نشست شما منقضی شده بود» گیر
// می‌کرد — هیچ‌کس نمی‌توانست وارد شود.
// ⛔ منطقِ استقرار از `deploy.sh` به `hesabland` منتقل شد، پس این قاعده
//    هم باید همان‌جا را بسنجد. `deploy.sh` حالا فقط یک پوسته است و
//    **نباید** نسخه‌ی دومی از این منطق داشته باشد: با دو نسخه، اولین
//    اصلاحی که فقط به یکی برسد همان باگِ بی‌صدای بالا را برمی‌گرداند.
$deploySrc = (string)@file_get_contents(__DIR__ . '/../hesabland');
T::ok($deploySrc !== '', 'فایلِ hesabland پیدا شد');

// ⚠ کامنت‌های تمام‌خطی باید **پیش از** بررسی حذف شوند — همان دامی که
//    قاعده ۳۵ و ۱۹ و ۳۸ هم در آن افتادند. سرآیندِ همین فایل عبارتِ
//    `-H "Host:"` و واژه‌ی `--noproxy` را در توضیحاتش دارد، پس بررسی روی
//    سورسِ خام هم روی فایلِ **سالم** قرمز می‌شد هم جهشِ «--noproxy را
//    بردار» را زنده نگه می‌داشت. فقط خطِ کاملِ کامنت حذف می‌شود، نه هر
//    `#`، وگرنه `${url#http://}` هم قربانی می‌شد.
$deployCode = implode("\n", array_filter(
    explode("\n", $deploySrc),
    static fn($ln) => !preg_match('/^\s*#/', $ln)
));
if ($deploySrc !== '') {
    $chownAt = strpos($deploySrc, 'chown -R root:root');
    T::ok($chownAt !== false, 'hesabland مالکیت کد را به root می‌دهد');

    // هر دو باید *بعد از* آن خط دوباره به کاربر اپ برگردند
    foreach (['uploads', 'var'] as $dir) {
        $restoreAt = strpos($deploySrc, 'chown -R "$APP_USER":"$APP_USER" ' . $dir);
        T::ok(
            $restoreAt !== false && $chownAt !== false && $restoreAt > $chownAt,
            "«{$dir}» بعد از chown سراسری به کاربر اپ برمی‌گردد"
        );
    }

    T::ok(
        str_contains($deploySrc, 'test -w var/sessions'),
        'نوشتنی بودن var/sessions واقعاً آزموده می‌شود، نه فرض'
    );
    T::ok(
        str_contains($deploySrc, 'test -r config/config.php'),
        'خواندنی ماندنِ config.php هم واقعاً آزموده می‌شود'
    );

    // ⛔ سنجشِ سلامت باید به 127.0.0.1 بخورد و پراکسیِ محیط را دور بزند،
    //    وگرنه curl مقدارِ --resolve را نادیده می‌گیرد و پاسخ از جای
    //    دیگری می‌آید — سنجشی که درباره‌ی چیزِ دیگری حرف می‌زند.
    T::ok(
        str_contains($deployCode, '--resolve') && !str_contains($deployCode, '-H "Host:'),
        'سنجشِ سلامت با --resolve است نه -H "Host:"'
    );
    T::ok(
        str_contains($deployCode, "--noproxy"),
        'سنجشِ سلامت پراکسیِ محیط را دور می‌زند'
    );
    T::ok(
        str_contains($deployCode, '*"</html>"*'),
        'سنجه‌ی «رندر تمام شد» وجودِ </html> است، نه طولِ بدنه'
    );

    // ⛔ باگِ واقعی: `cmd_deploy` تابع را داخلِ `if` صدا می‌زند و bash
    //    آنجا کلِ بدنه را از `set -e` معاف می‌کند. بدونِ این `|| return`،
    //    لو رفتنِ سورسِ خامِ api/v1 گزارش می‌شد ولی استقرار **سبز** اعلام
    //    می‌شد. با سرورِ HTTPS واقعی بازتولید شد.
    T::ok(
        (bool)preg_match('/api_ping_check\s+"\$base"\s+"\$resolve"\s+"\$quiet"\s*\|\|\s*return\s+1/', $deployCode),
        '⛔ شکستِ api_ping_check واقعاً به health_check منتقل می‌شود'
    );

    // ⛔ کدِ ورودی پیش از `git reset --hard` سنجیده می‌شود، نه بعدش:
    //    با فرود آمدن، opcache همان لحظه می‌بیندش و سایت پیش از هر
    //    سنجشی خوابیده است.
    $preflightAt = strpos($deployCode, 'preflight_syntax "origin/${BRANCH}"');
    $landAt      = strpos($deployCode, 'git reset --hard "origin/${BRANCH}"');
    T::ok(
        $preflightAt !== false && $landAt !== false && $preflightAt < $landAt,
        '⛔ نحوِ کدِ ورودی **پیش از** نشستنش روی دیسک سنجیده می‌شود'
    );

    // ⛔ برگشتِ خودکار وقتی migration اعمال شده ممنوع است: کدِ قدیمی روی
    //    ساختارِ تازه یک خرابیِ دیگر است، نه رفعِ خرابی.
    T::ok(
        str_contains($deployCode, 'if (( migrated )); then'),
        '⛔ بعد از اعمالِ migration برگشتِ خودکار انجام نمی‌شود'
    );

    // ⛔ بالا بردنِ VERSION در sw.js از ابزارِ استقرار ممنوع است: کلِ کشِ
    //    سرویس‌ورکر را پاک می‌کند و همان «تورِ نجاتِ نسخه‌ی قبلیِ فایل»
    //    را می‌برد که برای صفحه‌ی بی‌جان نوشته شد.
    T::ok(
        !preg_match('/sed[^\n]*sw\.js|VERSION\s*=\s*.daftar-v/', $deployCode),
        '⛔ ابزارِ استقرار به VERSION در sw.js دست نمی‌زند'
    );
}

// ⛔ و پوسته باید پوسته بماند.
$wrapSrc = (string)@file_get_contents(__DIR__ . '/../deploy.sh');
T::ok(
    str_contains($wrapSrc, 'hesabland'),
    'deploy.sh به hesabland واگذار می‌کند (بوکمارک و مستندات نمی‌شکنند)'
);
T::ok(
    !str_contains($wrapSrc, 'chown -R root:root'),
    '⛔ deploy.sh نسخه‌ی دومی از منطقِ استقرار ندارد'
);
// ⚠ استقرار خودِ deploy.sh را با git reset بازنویسی می‌کند و bash فایل را
//   تکه‌تکه می‌خواند؛ با بدنه‌ی تخت، ادامه‌ی اجرا از همان آفست در فایلِ
//   **تازه** خوانده می‌شود. پس بدنه باید داخلِ یک تابع باشد و فراخوانی‌اش
//   آخرین خط، تا bash پیش از اجرا کلِ فایل را خوانده باشد.
T::ok(
    (bool)preg_match('/^main\s+"\$@"\s*$/m', $wrapSrc),
    'deploy.sh بدنه‌اش را در تابع نگه می‌دارد و آخرِ فایل صدایش می‌زند'
);

// ---------------------------------------------------------------
T::group('قاعده ۹ — api/v1 هویت را از توکن می‌گیرد، نه از نشست');

// اپی که روی گوشی کاربر نصب شده را نمی‌شود مجبور به به‌روزرسانی کرد.
// اگر یک هندلر در v1 بررسی هویت را جا بیندازد، داده‌ی یک کاربر به دست
// دیگری می‌رسد و تا وقتی همه اپشان را عوض نکنند قابل جبران نیست.

$v1Files = glob($root . '/api/v1/routes/*.php');
T::ok(count($v1Files) > 0, 'فایل‌های مسیر v1 پیدا شدند', 'تعداد: ' . count($v1Files));

// فقط این دو عمداً بی‌نیاز از توکن‌اند: ping برای سنجش دسترسی، و login
// که خودش توکن می‌سازد.
$openHandlers = ['v1Ping', 'v1AuthLogin'];

$missing = [];
$handlers = 0;
foreach ($v1Files as $f) {
    $src = (string)file_get_contents($f);
    // بدنه‌ی هر تابع v1... را جدا می‌کنیم تا بررسی هر کدام مستقل باشد
    preg_match_all('/function\s+(v1\w+)\s*\([^)]*\)\s*:\s*void\s*\{/', $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => $hit) {
        $name = $hit[0];
        $handlers++;
        if (in_array($name, $openHandlers, true)) { continue; }

        $start = $m[0][$i][1];
        $end   = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($src);
        $body  = substr($src, $start, $end - $start);

        if (!str_contains($body, 'Api::requireUser()')) {
            $missing[] = basename($f) . " › {$name} توکن را نمی‌سنجد";
        }
    }
}
T::bulk($handlers, $missing, 'هر هندلر v1 هویت را می‌سنجد');

// شناسه‌ی کاربر باید از توکن بیاید. Auth::userId() نشست را می‌خواند و در
// درخواستِ API همیشه null است — استفاده‌اش یعنی باگِ خاموش.
// ⚠ کامنت‌ها با توکنایزر حذف می‌شوند. بدون این، همین قاعده روی
// توضیحی که *چرا نباید* از Auth::userId() استفاده کرد شکست می‌خورد —
// یک بار همین‌جا واقعاً اتفاق افتاد.
$stripComments = static function (string $file): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};

$sessionUse = [];
foreach (array_merge($v1Files, [$root . '/api/v1/index.php']) as $f) {
    $src = $stripComments($f);
    if (str_contains($src, 'Auth::userId()')) {
        $sessionUse[] = basename($f) . ' از Auth::userId() استفاده می‌کند';
    }
    // cachedCategories() هم کاربر را از نشست می‌گیرد
    if (str_contains($src, 'cachedCategories(')) {
        $sessionUse[] = basename($f) . ' از cachedCategories() استفاده می‌کند (وابسته به نشست)';
    }
}
T::bulk(count($v1Files) + 1, $sessionUse, 'v1 به نشست وابسته نیست');

// پاکت پاسخ باید یک شکل بماند؛ مشتری روی همین حساب می‌کند.
$apiSrc = (string)file_get_contents($root . '/includes/api.php');
T::ok(str_contains($apiSrc, "'ok' => true") && str_contains($apiSrc, "'ok' => false"),
      'پاکت پاسخ کلید ok دارد');
T::ok(str_contains($apiSrc, "header('X-API-Version"), 'نسخه در سرآیند پاسخ می‌آید');

// ---------------------------------------------------------------
T::group('قاعده ۱۰ — قاعده‌ی nginx برای api/v1 هرگز ^~ ندارد');

// ⚠ این از یک خرابیِ واقعی روی سرور درآمد.
//
// `^~` در nginx یعنی «اگر این prefix برنده شد، دیگر location های regex را
// نگاه نکن». `location ~ \.php$` هم یک regex است — پس با ^~ هرگز اجرا
// نمی‌شد و nginx فایل PHP را به‌جای اجرا، خام تحویل می‌داد:
// /api/v1/ping متنِ کاملِ index.php را برمی‌گرداند و API اصلاً کار نمی‌کرد.
//
// `nginx -t` سبز بود و reload هم موفق — یعنی هیچ ابزاری جلویش را نگرفت.
// این قاعده جلویش را می‌گیرد.
$nginxSources = [
    $root . '/deploy/nginx-api.sh',
    $root . '/deploy/vps-setup.sh',
];
$offenders = [];
foreach ($nginxSources as $f) {
    if (!file_exists($f)) { continue; }
    foreach (explode("\n", (string)file_get_contents($f)) as $n => $line) {
        // خطِ کامنت به حساب نمی‌آید — توضیحِ «هرگز ^~ نگذارید» خودش
        // نباید قاعده را بشکند (همان تله‌ای که در قاعده ۹ خوردیم).
        $code = trim($line);
        if ($code === '' || str_starts_with($code, '#')) { continue; }
        // فقط خطی که **خودش یک directive است** شمرده می‌شود، نه هر
        // خطی که نامش را برده. یک `red "... location ^~ /api/v1/"` که
        // دارد همین ایراد را *گزارش* می‌کند، خودش ایراد نیست — بار سوم
        // بود که این تله را می‌خوردیم. گیومه‌ی ابتدای خط مجاز است چون
        // بلوک در پایتون به شکل '    location ...' نوشته می‌شود.
        if (preg_match('/^\s*[\x27"]?\s*location\s+\^~\s*\/api\/v1/', $line)) {
            $offenders[] = basename($f) . ' خط ' . ($n + 1) . ': ^~ روی /api/v1/';
        }
    }
}
T::bulk(count($nginxSources), $offenders, 'هیچ‌کدام از اسکریپت‌های nginx روی api/v1 از ^~ استفاده نمی‌کنند');

// و قاعده باید واقعاً وجود داشته باشد، وگرنه تست بالا الکی سبز می‌ماند
$apiSh = (string)@file_get_contents($root . '/deploy/nginx-api.sh');
T::ok((bool)preg_match('/location\s+\/api\/v1\/\s*\{/', $apiSh),
      'قاعده‌ی prefix ساده‌ی /api/v1/ در اسکریپت هست');
T::ok(str_contains($apiSh, '/api/v1/ping'),
      'اسکریپت بعد از اعمال، خودِ اندپوینت را می‌سنجد');

// اگر سروری قاعده‌ی معیوب داشته باشد، اسکریپت باید ترمیمش کند — نه
// اینکه «از قبل هست» ببیند و بی‌سروصدا رد شود. نسخه‌ی اول همین کار را
// می‌کرد و سرورِ خرابْ خراب می‌ماند.
T::ok(str_contains($apiSh, 'MODE="repair"'),
      'اسکریپت قاعده‌ی معیوبِ ^~ را ترمیم می‌کند، نه اینکه ردش کند');

// سنجش باید با --resolve باشد: با -H "Host: ..." روی https://127.0.0.1
// هیچ SNI ای نمی‌رود و nginx ممکن است سایتِ دیگری را جواب بدهد. یک بار
// همین باعث شد اسکریپت پیکربندیِ درست را برگرداند.
T::ok(str_contains($apiSh, '--resolve'),
      'سنجشِ اسکریپت با --resolve انجام می‌شود نه با Host دستی');

// reload غیرهمزمان است: کارگرهای قدیمی تا ~۱۵۰ms با پیکربندی قبلی جواب
// می‌دهند. سنجشِ فوری، پیکربندیِ درست را «خراب» می‌دید و برمی‌گرداند.
T::ok((bool)preg_match('/for\s+_?\w*\s+in\s+\$\(seq/', $apiSh),
      'سنجش بعد از reload تکرار می‌شود، نه یک بار');

// تورِ نجاتِ اپ: ?p= باید همیشه کار کند، حتی بدون قاعده‌ی nginx
$routerSrc = (string)@file_get_contents($root . '/api/v1/index.php');
T::ok(str_contains($routerSrc, "\$_GET['p']"),
      'روتر شکل ?p= را هم می‌فهمد (کار می‌کند حتی بدون قاعده‌ی nginx)');

// ---------------------------------------------------------------
T::group('قاعده ۱۱ — حاشیه‌ی امنِ آیفون بی‌اثر نماند');

// ⚠ یک باگِ خاموشِ واقعی: style.css در پنج جا از env(safe-area-inset-*)
// استفاده می‌کند، ولی iOS این مقادیر را **فقط** وقتی می‌دهد که متای
// viewport شامل `viewport-fit=cover` باشد. بدون آن همه‌ی این محاسبه‌ها
// صفر می‌شوند و هیچ خطایی هم دیده نمی‌شود — فقط نوار پایین زیر خطِ
// خانه می‌رود و عنوان صفحه زیر ناچ.
$css = (string)file_get_contents($root . '/assets/css/style.css');
$usesSafeArea = str_contains($css, 'env(safe-area-inset');

$pages = ['includes/header.php', 'login.php', 'setup.php',
          'forgot-password.php', 'reset-password.php'];
$missing = [];
foreach ($pages as $rel) {
    $f = $root . '/' . $rel;
    if (!file_exists($f)) { continue; }
    $src = (string)file_get_contents($f);
    if (!preg_match('/<meta\s+name="viewport"[^>]*>/i', $src, $m)) {
        $missing[] = "$rel متای viewport ندارد";
        continue;
    }
    if (!str_contains($m[0], 'viewport-fit=cover')) {
        $missing[] = "$rel متای viewport بدون viewport-fit=cover";
    }
}

if ($usesSafeArea) {
    T::bulk(count($pages), $missing,
            'هر صفحه‌ای که سرآیند خودش را دارد viewport-fit=cover دارد');
} else {
    T::pass('CSS از safe-area استفاده نمی‌کند — این قاعده موضوعیت ندارد');
}

// نوار بالا باید حاشیه‌ی امنِ بالا را حساب کند، وگرنه زیر Dynamic Island
// می‌رود. (status-bar-style=black-translucent عمدی است و وب‌ویو را تا
// بالای صفحه می‌کشد.)
T::ok(str_contains($css, 'env(safe-area-inset-top)'),
      'نوار بالای صفحه حاشیه‌ی امنِ بالا را حساب می‌کند');

// ---------------------------------------------------------------
T::group('قاعده ۱۲ — پیوندِ اپ اندروید با سایت نشکند');

// اپ اندروید یک پوسته‌ی TWA است و «تأیید»ش به سه چیزِ به‌هم‌گره‌خورده
// بند است: نامِ بسته، اثر انگشتِ کلید، و در دسترس بودنِ assetlinks.json
// روی دامنه. اگر یکی عوض شود و بقیه نه، اپ **نصب می‌شود ولی با نوار
// آدرس بالا** — یعنی خرابیِ کاملاً بی‌صدا: ساخت سبز است، نصب موفق است،
// و فقط کاربر می‌فهمد.
//
// اثر انگشت را خودِ workflow بعد از ساخت با APK می‌سنجد (اینجا APK ای
// نداریم). چیزی که اینجا سنجیده می‌شود هم‌خوانیِ خودِ فایل‌هاست.

$alPath = __DIR__ . '/../.well-known/assetlinks.json';
$gradlePath = __DIR__ . '/../mobile/app/build.gradle.kts';

if (!file_exists($gradlePath)) {
    T::pass('اپ اندروید در مخزن نیست — این قاعده موضوعیت ندارد');
} else {
    T::ok(file_exists($alPath), '.well-known/assetlinks.json وجود دارد');

    $al = json_decode((string)file_get_contents($alPath), true);
    T::ok(is_array($al) && isset($al[0]['target']), 'assetlinks.json ساختار درستی دارد');

    $gradle = (string)file_get_contents($gradlePath);
    preg_match('/applicationId\s*=\s*"([^"]+)"/', $gradle, $mApp);
    preg_match('/"hostName"\]\s*=\s*"([^"]+)"/', $gradle, $mHost);
    preg_match('/"launchUrl"\]\s*=\s*"([^"]+)"/', $gradle, $mUrl);

    T::ok(($mApp[1] ?? '') !== '' && ($mApp[1] ?? '') === ($al[0]['target']['package_name'] ?? ''),
          'نام بسته در gradle و assetlinks یکی است');

    // ⛔ و نامِ بسته در **پنج** جای دیگر هم تکرار شده. عوض کردنِ برند یک
    //    بار انجام می‌شود و هر جای جامانده یک خرابیِ **بی‌صدا**ی جدا
    //    می‌سازد:
    //      • پوشه‌ی سورس و خطِ `package` → گریدل ممکن است بسازد ولی
    //        `R` و کلاس‌ها سرِ جای اشتباه می‌نشینند.
    //      • `action` در manifest و لینکِ `intent://` در `profile.php` →
    //        دکمه‌ی «تنظیم در اپ اندروید» زده می‌شود و **هیچ اتفاقی
    //        نمی‌افتد**؛ نه خطایی، نه صفحه‌ای.
    //      • `package=` داخلِ همان لینک → اندروید اپ را پیدا نمی‌کند.
    //    هیچ‌کدام هنگام ساخت دیده نمی‌شوند، پس اینجا سنجیده می‌شوند.
    $appId  = $mApp[1] ?? '';
    $mobile = __DIR__ . '/../mobile/app/src/main/';
    $pkgBad = [];

    if ($appId !== '') {
        // ۱) `namespace` باید با `applicationId` یکی باشد.
        preg_match('/namespace\s*=\s*"([^"]+)"/', $gradle, $mNs);
        if (($mNs[1] ?? '') !== $appId) {
            $pkgBad[] = 'build.gradle.kts — namespace با applicationId یکی نیست';
        }

        // ۲) پوشه‌ی سورس و خطِ `package` هر فایلِ جاوا.
        $pkgDir = $mobile . 'java/' . str_replace('.', '/', $appId);
        if (!is_dir($pkgDir)) {
            $pkgBad[] = 'پوشه‌ی سورسِ جاوا با نامِ بسته نمی‌خواند: java/'
                      . str_replace('.', '/', $appId);
        }
        foreach (glob($mobile . 'java/*/*/*/*.java') ?: [] as $j) {
            if (!preg_match('/^\s*package\s+([A-Za-z0-9_.]+)\s*;/m',
                            (string)file_get_contents($j), $mPkg)
                || $mPkg[1] !== $appId) {
                $pkgBad[] = basename($j) . ' — خطِ package با نامِ بسته نمی‌خواند';
            }
        }

        // ۳) `action` صفحه‌ی تنظیمِ پیامک و لینکِ `intent://` در سایت.
        $mf   = (string)@file_get_contents($mobile . 'AndroidManifest.xml');
        $prof = (string)@file_get_contents(__DIR__ . '/../profile.php');
        $act  = $appId . '.SMS_SETUP';
        if ($mf !== '' && !str_contains($mf, $act)) {
            $pkgBad[] = "AndroidManifest — action «{$act}» نیست";
        }
        if ($prof !== '' && str_contains($prof, 'intent://')) {
            if (!str_contains($prof, 'action=' . $act)) {
                $pkgBad[] = 'profile.php — action لینکِ intent با نامِ بسته نمی‌خواند';
            }
            if (!str_contains($prof, 'package=' . $appId)) {
                $pkgBad[] = 'profile.php — package لینکِ intent با نامِ بسته نمی‌خواند';
            }
        }
    }
    T::bulk(6, $pkgBad, 'نامِ بسته در همه‌ی نقطه‌های وابسته یکی است');

    // ⛔ مسیرِ گرفتنِ مجوز — و هر چهار بندش یک خرابیِ **بی‌صدا** را
    //    می‌بندد. هیچ‌کدام هنگام ساخت یا نصب دیده نمی‌شوند؛ اپ بالا
    //    می‌آید و فقط «کار نمی‌کند».
    $permBad = [];
    $mf   = (string)@file_get_contents($mobile . 'AndroidManifest.xml');
    $setup = (string)@file_get_contents($mobile . 'java/'
                    . str_replace('.', '/', $appId) . '/SmsSetupActivity.java');

    // ۱) `POST_NOTIFICATIONS` هم باید اعلام شود. بدونش روی اندروید ۱۳
    //    به بالا پیامک خوانده می‌شود، `notify()` بی‌خطا اجرا می‌شود، و
    //    **هیچ اعلانی دیده نمی‌شود**.
    foreach (['android.permission.RECEIVE_SMS', 'android.permission.POST_NOTIFICATIONS'] as $p) {
        if (!str_contains($mf, $p)) { $permBad[] = "AndroidManifest — «{$p}» اعلام نشده"; }
    }

    // ۲) و `READ_SMS` نباید باشد: این اپ صندوقِ پیامک را نمی‌خواند،
    //    فقط پیامکِ **رسیده** را می‌گیرد. مجوزی که لازم نیست، هم
    //    کاربر را می‌ترساند هم اپ را از هر فروشگاهی بیرون می‌اندازد.
    if (str_contains($mf, 'android.permission.READ_SMS')) {
        $permBad[] = 'AndroidManifest — READ_SMS لازم نیست و نباید خواسته شود';
    }

    // ۳) `MAIN`/`LAUNCHER` باید روی `LauncherActivity` باشد و روی
    //    `SmsSetupActivity` **نباشد**. یک بار جابه‌جا شد (برای پرسیدنِ
    //    مجوز در اولین اجرا) و پس داده شد: هزینه‌اش را **هر** بار باز
    //    کردنِ اپ می‌داد — یک پنجره‌ی اضافه پیش از کروم، و کاربر «چند
    //    بار رفرش شدن و صفحه‌ی سفید» می‌دید. مجوز یک بار پرسیده می‌شود،
    //    اپ هزار بار باز می‌شود.
    if (preg_match('~<activity\b[^>]*LauncherActivity.*?</activity>~s', $mf, $mLau)) {
        if (!str_contains($mLau[0], 'android.intent.category.LAUNCHER')) {
            $permBad[] = 'AndroidManifest — LauncherActivity دیگر LAUNCHER نیست؛'
                       . ' اپ یک پنجره‌ی اضافه پیش از کروم باز می‌کند';
        }
    } else {
        $permBad[] = 'AndroidManifest — بلوکِ LauncherActivity پیدا نشد';
    }
    if (preg_match('~<activity\b[^>]*\.SmsSetupActivity.*?</activity>~s', $mf, $mAct)) {
        if (str_contains($mAct[0], 'android.intent.category.LAUNCHER')) {
            $permBad[] = 'AndroidManifest — SmsSetupActivity نباید LAUNCHER باشد؛'
                       . ' درِ ورودی کردنش هر بار یک پنجره‌ی اضافه می‌سازد';
        }
        // ۴) و `noHistory` روی همان اکتیویتی یعنی هنگام بالا آمدنِ
        //    دیالوگِ مجوز `finish` می‌شود و `onRequestPermissionsResult`
        //    هرگز نمی‌رسد: کاربر «اجازه» را می‌زند و هیچ اتفاقی نمی‌افتد.
        if (str_contains($mAct[0], 'noHistory')) {
            $permBad[] = 'AndroidManifest — noHistory روی SmsSetupActivity'
                       . ' پاسخِ دیالوگِ مجوز را از بین می‌برد';
        }
    } else {
        $permBad[] = 'AndroidManifest — بلوکِ SmsSetupActivity پیدا نشد';
    }

    // ۵) و خودِ کد باید واقعاً هر دو را بخواهد. «در manifest نوشته شده»
    //    با «از کاربر خواسته می‌شود» یکی نیست — همان درسِ nginx.
    if ($setup === '') {
        $permBad[] = 'SmsSetupActivity.java پیدا نشد';
    } else {
        // ⚠ کامنت‌ها **پیش از** جست‌وجو حذف می‌شوند، وگرنه این بررسی
        //   پوچ است: توضیحاتِ همین فایل نامِ هر دو مجوز را دارند، پس با
        //   جست‌وجوی خام، برداشتنِ کاملِ درخواستِ مجوز هم سبز می‌ماند.
        //   با آزمونِ جهش دیده شد — نسخه‌ی اول دقیقاً همین‌طور بود.
        $setupCode = preg_replace('~/\*.*?\*/~s', '', $setup);
        // `//` فقط وقتی کامنت است که بخشی از `://` نباشد.
        $setupCode = preg_replace('~(?<!:)//[^\n]*~', '', (string)$setupCode);

        foreach (['RECEIVE_SMS', 'POST_NOTIFICATIONS'] as $p) {
            if (!str_contains((string)$setupCode, $p)) {
                $permBad[] = "SmsSetupActivity — «{$p}» در زمانِ اجرا خواسته نمی‌شود";
            }
        }
        if (!str_contains((string)$setupCode, 'requestPermissions')) {
            $permBad[] = 'SmsSetupActivity — هیچ درخواستِ مجوزی در کار نیست';
        }

        // ⛔ و چون همین اکتیویتی **درِ ورودیِ اپ** است، دو چیز روی آن
        //    اجباری‌اند — هر دو با یک خرابیِ واقعی خریداری شده‌اند:
        //    اپ نصب شد و با زدنِ آیکون بسته می‌شد، بی‌هیچ توضیحی.
        //
        //    ۱. `AppCompatActivity` باشد نه `android.app.Activity`ِ خام:
        //       تمش `Theme.AppCompat` است و همه‌ی ویجت‌ها در کد ساخته
        //       می‌شوند، پس آن جفت باید همخوان بماند.
        //    ۲. `onCreate` یک گاردِ `Throwable` داشته باشد: بدونش،
        //       کاربری که «تنظیم در اپ اندروید» را می‌زند فقط یک اپِ
        //       بسته‌شده می‌بیند و هیچ راهی برای فهمیدنِ علتش ندارد.
        if (!str_contains((string)$setupCode, 'extends AppCompatActivity')) {
            $permBad[] = 'SmsSetupActivity — با تمِ AppCompat باید AppCompatActivity باشد';
        }
        // ⚠ گارد باید داخلِ **خودِ `onCreate`** باشد، نه هر جای فایل.
        //   نسخه‌ی اول فقط دنبالِ رشته‌ی `catch (Throwable` در کلِ فایل
        //   می‌گشت و یک `catch (Throwable ignored)` در جای دیگری سبزش
        //   می‌کرد — با آزمونِ جهش دیده شد.
        $oc = '';
        if (preg_match('~protected void onCreate\b.*?\n    \}~s', (string)$setupCode, $mOc)) {
            $oc = $mOc[0];
        }
        if ($oc === '') {
            $permBad[] = 'SmsSetupActivity — بدنه‌ی onCreate پیدا نشد';
        } else {
            // ⚠ و دقیقاً روی «`build()` داخلِ try است» می‌نشیند، نه «جایی
            //   در onCreate یک Throwable هست»: نسخه‌ی دوم هم پوچ بود،
            //   چون `catch (Throwable ignored)`ِ خودِ همان بلوک سبزش
            //   می‌کرد. دو جهش لازم شد تا این معلوم شود.
            //   راهِ فرار هم باید **داخلِ همان catch** باشد، نه هر جای
            //   onCreate: بدونِ `finish()` اکتیویتیِ نیمه‌ساخته روی صفحه
            //   می‌ماند و کاربر یک صفحه‌ی خالی می‌بیند.
            $hasTry = preg_match('~try\s*\{\s*build\(\);\s*\}\s*catch\s*\(\s*Throwable~',
                                 $oc, $mTry, PREG_OFFSET_CAPTURE);
            $escapes = $hasTry
                    && str_contains(substr($oc, (int)$mTry[0][1]), 'finish()');
            if (!$hasTry || !$escapes) {
                $permBad[] = 'SmsSetupActivity — onCreate گاردِ Throwable با راهِ فرار ندارد؛'
                           . ' یک خطا یعنی صفحه‌ی خالی، بی‌هیچ توضیحی';
            }
        }
    }
    T::bulk(10, $permBad, 'مجوزهای پیامک و اعلان اعلام و در زمانِ اجرا خواسته می‌شوند');

    // آدرسِ باز شونده باید روی همان دامنه‌ای باشد که intent-filter
    // تأییدش می‌کند؛ وگرنه اپ صفحه‌ای را باز می‌کند که برایش تأیید ندارد.
    T::ok(($mHost[1] ?? '') !== '' && str_contains($mUrl[1] ?? '', $mHost[1] ?? "\0"),
          'launchUrl روی همان دامنه‌ی hostName است');

    // اثر انگشت باید شکل درستِ SHA-256 داشته باشد (۳۲ بایتِ هگزِ
    // دونقطه‌دار). یک رشته‌ی کوتاه یا جاافتاده اینجا گرفته می‌شود، نه
    // بعد از نصب روی گوشی.
    $fp = $al[0]['target']['sha256_cert_fingerprints'][0] ?? '';
    T::ok((bool)preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $fp),
          'اثر انگشت شکل درستِ SHA-256 دارد');

    // ⚠ nginx فایل‌های نقطه‌دار را می‌بندد ولی .well-known را عمداً
    // استثنا کرده. اگر آن استثنا روزی برداشته شود، تأیید TWA بی‌سروصدا
    // می‌شکند و هیچ‌کس ربطش را پیدا نمی‌کند.
    $vps = (string)@file_get_contents(__DIR__ . '/../deploy/vps-setup.sh');
    T::ok(str_contains($vps, 'well-known'),
          'قاعده‌ی nginx مسیر .well-known را باز گذاشته است');

    // و mobile/ نباید هیچ کد برنامه‌ای داشته باشد: هر منطقی که آنجا
    // برود، نسخه‌ی دومِ همین سایت را شروع می‌کند.
    // ⚠ با glob نوشته نشود: `**` در glob پی‌اچ‌پی بازگشتی نیست و
    //     `mobile/**/*.kt` هیچ‌وقت فایلِ چند پوشه پایین‌تر را پیدا
    //     نمی‌کند — یعنی این بررسی همیشه سبز می‌ماند. یک بار همین شد و
    //     آزمون جهش گرفتش.
    // ⛔ فهرستِ **بسته**ی فایل‌های بومیِ مجاز.
    //
    //    قاعده‌ی اصلی هنوز پابرجاست: اپ یک پوسته است و منطقِ برنامه
    //    نباید آنجا برود، وگرنه نسخه‌ی دومِ همین سایت شروع می‌شود و هر
    //    قابلیت باید دو بار نوشته شود — دقیقاً همان چیزی که اپ فلاتر را
    //    کنار گذاشت.
    //
    // ⚠ این دو استثنا **ناچاری‌اند، نه انتخاب**: نه PWA و نه TWA به SMS
    //   دسترسی ندارند و هیچ API وبی هم برایش نیست، پس گرفتنِ خودکارِ
    //   پیامکِ بانک فقط از لایه‌ی بومی ممکن است. و هر دو عمداً **پارس
    //   نمی‌کنند**: متن را دست‌نخورده به `parseBankSms()` می‌سپارند.
    //
    // ⛔ فهرست بسته است تا فایلِ سومی بی‌سروصدا اضافه نشود. اگر روزی
    //    لازم شد، باید همین‌جا و آگاهانه باز شود.
    $allowedNative = ['BankSmsReceiver.java', 'SmsSetupActivity.java'];

    $code = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../mobile',
            FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!preg_match('/\.(java|kt|dart)$/i', $f->getFilename())) { continue; }
        if (!in_array($f->getFilename(), $allowedNative, true)) {
            $code[] = $f->getFilename() . ' — فایلِ بومیِ تازه‌ی ثبت‌نشده';
            continue;
        }
        // ⛔ و حتی همان دو تا نباید منطقِ دامنه داشته باشند: نه پارسِ
        //    مبلغ، نه تبدیلِ ریال به تومان، نه تقویمِ شمسی. هر کدامشان
        //    یعنی پیاده‌سازیِ دوم — و خرابیِ دو پیاده‌سازیِ ناهم‌خوان
        //    **بی‌صداست**: فرم پر می‌شود، کاربر ثبت می‌کند، و ماه بعد
        //    گزارش غلط است.
        $src = file_get_contents($f->getPathname());
        foreach (['ریال', 'تومان', 'jalali', 'Jalali', '/ 10', '/10'] as $logic) {
            if (str_contains($src, $logic)) {
                $code[] = $f->getFilename() . " — نشانه‌ی منطقِ دامنه («{$logic}»)";
                break;
            }
        }
    }
    T::bulk(count($allowedNative), $code,
        'پوشه‌ی mobile/ جز پلِ پیامک کد برنامه ندارد (پوسته می‌ماند)');
}

// ---------------------------------------------------------------
// قاعده ۱۳ — حاشیه‌ی امن: قاعده‌ای که **برنده می‌شود** باید آن را داشته باشد
//
// قاعده ۱۱ وجودِ `viewport-fit=cover` را می‌سنجد، یعنی اینکه iOS اصلاً
// این مقادیر را بدهد. ولی یک بار چیز دیگری شکست و آن قاعده ندیدش:
// مقدارها **داده می‌شدند** و CSS هم `env(...)` را داشت، ولی یک قاعده‌ی
// بعدی در همان فایل رویش می‌نوشت.
//
//   .topbar        قاعده‌ی پایه `calc(13px + env(safe-area-inset-top))` داشت،
//                  ولی داخل @media (max-width:900px) نوشته شده بود
//                  `padding: 12px 14px` — شورتهند، بعدتر در فایل، پس
//                  حاشیه‌ی امنِ بالا **کاملاً پاک می‌شد** و عنوان صفحه در
//                  اپِ نصب‌شده زیر Dynamic Island می‌رفت.
//   .page-content  فاصله‌ی کف ۹۶px ثابت بود. نوارِ شناور از کف پنجره
//                  14+62+env(bottom) بالا می‌ایستد: در مرورگر ۷۶px (پس
//                  کافی بود) ولی روی آیفون ۱۱۰px — یعنی ۱۴px از آخرین
//                  ردیف زیر نوار گیر می‌کرد.
//
// هر دو **فقط روی گوشیِ نصب‌شده** دیده می‌شدند و در مرورگر بی‌عیب بودند،
// چون آنجا env(...) صفر است. پس آزمونِ چشمی روی دسکتاپ هرگز نمی‌گرفتشان.
//
// آنچه سنجیده می‌شود ساده و مقاوم است: **آخرین** قاعده‌ای که این فاصله را
// تعیین می‌کند (یعنی همانی که واقعاً برنده می‌شود) باید `env(...)` داشته
// باشد. این‌طور اضافه شدنِ یک شورتهندِ بی‌خبر در آینده هم گرفته می‌شود.
T::group('قاعده ۱۳ — حاشیه‌ی امن با یک قاعده‌ی بعدی پاک نشود');

$css = file_get_contents(__DIR__ . '/../assets/css/style.css');
$css = preg_replace('!/\*.*?\*/!s', '', $css);          // کامنت‌ها حذف

/**
 * آخرین بلوکی که برای $selector این ویژگی را تعیین می‌کند برمی‌گرداند.
 * شورتهندِ `padding` هم شمرده می‌شود، چون padding-top/bottom را صفر می‌کند.
 */
$lastDecl = static function (string $css, string $selector, string $side): ?string {
    $winner = null;
    // هر بلوک: فهرست سلکتور + بدنه
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $blk) {
            $sels = $blk[1];
            $body = $blk[2];
            // سلکتور باید دقیقاً همین کلاس باشد، نه چیزی مثل .page-content-x
            if (!preg_match('/(^|[\s,>+~])' . preg_quote($selector, '/') . '(?![\w-])/', $sels)) {
                continue;
            }
            if (preg_match_all('/(?:^|;)\s*(padding(?:-' . $side . ')?)\s*:([^;]*)/i', $body, $d, PREG_SET_ORDER)) {
                $winner = end($d)[2];
            }
        }
    }
    return $winner;
};

$cases = [
    ['.topbar',       'top',    'safe-area-inset-top',
     'نوار بالا فاصله‌ی ناچ را نگه می‌دارد'],
    ['.page-content', 'bottom', 'safe-area-inset-bottom',
     'کف صفحه فاصله‌ی خطِ خانه را نگه می‌دارد'],
    // ⛔ مودال‌ها از این قاعده جا مانده بودند. شیت‌ها از اول
    //    `env(safe-area-inset-bottom)` داشتند، ولی `.modal-overlay` فقط
    //    `padding: 16px` بود — پس روی گوشیِ نصب‌شده مودالِ بلند سرآیندش
    //    زیرِ ناچ می‌رفت و دکمه‌ی بستن از دسترس خارج می‌شد. با اندازه‌گیری
    //    روی حاشیه‌های واقعیِ آیفون ۱۵ پرو دیده شد: لبه‌ی بالای مودال
    //    ۵۱px بود در حالی که ناچ تا ۵۹px می‌آید.
    ['.modal-overlay', 'top',    'safe-area-inset-top',
     'مودال از ناچ فاصله می‌گیرد'],
    ['.modal-overlay', 'bottom', 'safe-area-inset-bottom',
     'مودال از خطِ خانه فاصله می‌گیرد'],
];
foreach ($cases as [$sel, $side, $env, $what]) {
    $decl = $lastDecl($css, $sel, $side);
    T::ok(
        $decl !== null && str_contains($decl, $env),
        "قاعده ۱۳ — {$what}",
        $decl === null
            ? "هیچ قاعده‌ای padding-{$side} را برای {$sel} تعیین نمی‌کند"
            : "آخرین قاعده‌ی برنده «{$sel}» این است و env({$env}) ندارد:" .
              " padding…: " . trim($decl)
    );
}

// نوار پایین باید از خطِ خانه فاصله بگیرد، وگرنه روی همان می‌نشیند.
T::ok(
    (bool)preg_match('/\.bottom-nav\s*\{[^}]*safe-area-inset-bottom/s', $css),
    'قاعده ۱۳ — نوار پایین از خطِ خانه فاصله می‌گیرد'
);

// ---------------------------------------------------------------
// ⛔ قاعده ۱۴ — رمز که عوض شد، هر دسترسیِ قبلی باید باطل شود.
//
// عوض کردنِ رمز به‌تنهایی مهاجم را بیرون نمی‌کند: توکنِ api/v1 نه به
// رمز وابسته است نه به نشست، و ۹۰ روز زنده می‌ماند. کاربر رمز را عوض
// می‌کند، خیالش راحت می‌شود، و مهاجم همچنان داخل است — بی‌هیچ نشانه‌ای.
//
// چهار مسیر رمز می‌نویسند (پروفایل، بازیابی، پنل مدیر، خط فرمان) و
// هیچ‌کدام «یادش» نمی‌ماند مگر اینکه اینجا سنجیده شود.
T::group('قاعده ۱۴ — هر جا رمز نوشته می‌شود، دسترسی‌ها باطل شوند');

$pwWriters = [];
foreach (['api', 'includes', 'admin', 'deploy', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = php_strip_whitespace($p);
        // فقط نوشتن، نه خواندن: `SET ... password_hash =`
        if (preg_match('/UPDATE\s+users\s+SET[^\'"]*password_hash\s*=/i', $src)) {
            $pwWriters[realpath($p)] = $src;
        }
    }
}
T::ok(count($pwWriters) >= 4, 'مسیرهای نوشتنِ رمز پیدا شدند',
    count($pwWriters) . ' فایل: ' . implode('، ', array_map('basename', array_keys($pwWriters))));

$noRevoke = [];
foreach ($pwWriters as $path => $src) {
    if (!str_contains($src, 'revokeAllAccessFor(')) {
        $noRevoke[] = basename($path);
    }
}
T::bulk(count($pwWriters), $noRevoke,
    'هر مسیرِ نوشتنِ رمز، revokeAllAccessFor() را صدا می‌زند');

// و خودِ آن تابع باید **هر دو** چیز را باطل کند — نه فقط یکی. باطل
// کردنِ دستگاه‌ها بدونِ توکن‌ها همان باگی است که این قاعده برایش نوشته
// شده، و از بیرون هیچ فرقی دیده نمی‌شود.
//
// ⚠ بدنه با شمردنِ آکولاد بریده می‌شود، نه با regex: کامنت‌ها با
//   php_strip_whitespace از قبل رفته‌اند، ولی `.*?\n\}` روی فایلی که
//   قالب‌بندی‌اش فشرده شده به هیچ چیز نمی‌خورد — یک بار همین‌طور شد و
//   تست به‌جای سنجیدنِ بدنه، «تابع پیدا نشد» داد.
$fn = php_strip_whitespace(__DIR__ . '/../includes/functions.php');
$at = strpos($fn, 'function revokeAllAccessFor');
$body = '';
if ($at !== false && ($open = strpos($fn, '{', $at)) !== false) {
    $depth = 0;
    for ($i = $open, $n = strlen($fn); $i < $n; $i++) {
        if ($fn[$i] === '{') { $depth++; }
        elseif ($fn[$i] === '}') { $depth--; if ($depth === 0) { $body = substr($fn, $open, $i - $open); break; } }
    }
}
T::ok($body !== '', 'تابع revokeAllAccessFor پیدا شد');
T::ok(str_contains($body, 'revokeAllDevices'),
    'revokeAllAccessFor دستگاه‌های مورد اعتماد را باطل می‌کند');
T::ok(str_contains($body, 'ApiAuth::revokeAllFor'),
    'revokeAllAccessFor توکن‌های api/v1 را هم باطل می‌کند');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۵ — تگِ پایانِ PHP داخل کامنت، PHP را می‌بندد.
//
// یک کامنتِ `//` که تگِ پایانِ PHP را در متنش دارد بی‌ضرر به نظر
// می‌رسد، ولی همان‌جا PHP بسته
// می‌شود و بقیه‌ی فایل HTML حساب می‌شود. اینجا شانس آوردیم و خطای نحوی
// داد؛ در فایلی که آکولادهایش بالانس باشد **هیچ خطایی نمی‌دهد** و فقط
// نصفِ فایل بی‌صدا اجرا نمی‌شود — یا بدتر، سورس به مرورگر می‌رود.
T::group('قاعده ۱۵ — تگِ پایانِ PHP نباید داخل کامنت باشد');

$badTag = [];
$scanned = 0;
foreach (['api', 'includes', 'admin', 'deploy', 'tests', 'config', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $scanned++;
        foreach (token_get_all(file_get_contents($p)) as $tk) {
            if (is_array($tk) && $tk[0] === T_COMMENT && str_contains($tk[1], '?' . '>')) {
                $badTag[] = basename($p) . ' خط ' . $tk[2];
            }
        }
    }
}
T::bulk($scanned, $badTag, 'هیچ کامنتی تگِ پایانِ PHP ندارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۰ — منوی کناری باید اسکرول شود.
//
// `.sidebar` ارتفاعش به `100vh` قفل است، ولی فهرستش با آمدنِ هر بخشِ
// تازه بلندتر می‌شود. وقتی از ارتفاعِ پنجره رد شد، گزینه‌های آخر
// **ناپدید** می‌شوند: نه اسکرولی، نه بریدگی‌ای، نه خطایی — فقط نیستند.
// روی حسابِ مدیر محتوای منو به ۱۲۰۱ پیکسل رسیده بود، یعنی روی **هر**
// پنجره‌ی دسکتاپی «کاربران» و «ورود و پیامک» دست‌نیافتنی بودند.
//
// ⛔ و `overflow-y: auto` به‌تنهایی این را حل **نمی‌کند**: فرزندِ فلکس
//    پیش‌فرض `min-height: auto` دارد و حاضر نیست کوتاه‌تر از محتوایش
//    شود، پس `flex: 1` هرگز فشرده نمی‌شود و `overflow` هرگز فعال
//    نمی‌شود. هر دو با هم لازم‌اند — به همین دلیل هر دو سنجیده می‌شوند.
//
// ⚠ این خرابی روی موبایل اصلاً دیده نمی‌شود (آنجا `.sidebar` با
//   `display:none` کنار گذاشته می‌شود)، پس «روی گوشی درست است» اینجا
//   مدرک نیست — همان درسی که سرِ حاشیه‌ی امن هم گرفتیم، این بار برعکس.
T::group('قاعده ۲۰ — منوی کناری باید اسکرول شود');

// ⚠ کامنت‌ها **اول** حذف می‌شوند. بدونِ آن، الگوی «هر چیزی تا `{`» کلِ
//   بلوکِ توضیحِ بالای قاعده را هم جزوِ انتخابگر می‌گیرد و `trim($sel)`
//   دیگر برابرِ `.sidebar-nav` نیست — یعنی تست روی فایلِ **سالم** هم قرمز
//   می‌شد. اولین اجرا دقیقاً همین بود.
$navCss   = preg_replace('~/\*.*?\*/~s', '',
                @file_get_contents(__DIR__ . '/../assets/css/style.css') ?: '');
$navBad   = [];
$navHasOv = $navHasMin = false;

preg_match_all('~([^{}]+)\{([^{}]*)\}~', $navCss, $navRules, PREG_SET_ORDER);
foreach ($navRules as $r) {
    foreach (explode(',', $r[1]) as $sel) {
        // فقط قاعده‌ای که **خودِ** `.sidebar-nav` را هدف بگیرد، نه فرزندش:
        // `.sidebar-nav a { … }` هیچ ربطی به اسکرولِ خودِ فهرست ندارد.
        if (trim($sel) !== '.sidebar-nav') { continue; }
        if (preg_match('~overflow-y\s*:\s*(auto|scroll)~', $r[2])) { $navHasOv = true; }
        if (preg_match('~min-height\s*:\s*0~', $r[2]))             { $navHasMin = true; }
    }
}
if (!$navHasOv)  { $navBad[] = '.sidebar-nav — `overflow-y: auto` ندارد؛ گزینه‌های آخر دست‌نیافتنی می‌شوند'; }
if (!$navHasMin) { $navBad[] = '.sidebar-nav — `min-height: 0` ندارد؛ فرزندِ فلکس فشرده نمی‌شود و overflow بی‌اثر است'; }

T::bulk(2, $navBad, 'منوی کناری هم overflow دارد هم min-height صفر');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۳ — اسلایدرِ خانه: اسلاید دقیقاً به اندازه‌ی نوارِ ماه.
//
// خواسته‌ی صریح این است که کارتِ حساب روی صفحه‌ی خانه **دقیقاً ابعادِ
// «مانده این ماه»** را داشته باشد و چیزی هم پایین‌تر نرود. سه چیز آن را
// نگه می‌دارند و هر سه بی‌صدا شکستنی‌اند:
//
//  ۱. `flex: 0 0 100%` روی اسلایدها — با هر عرضِ دیگری (`auto`, `63%`,
//     یک `max-width`) اسلاید دیگر هم‌اندازه‌ی نوار نیست و همان چیزی
//     می‌شود که قرار بود نباشد.
//  ۲. `margin-bottom: 0` روی نوار **داخلِ** اسلایدر — حاشیه‌ی یک آیتمِ
//     فلکس از ارتفاعِ جعبه‌اش کم می‌شود، پس با حاشیه‌ی ۱۲ پیکسلیِ خودش
//     نوار دوازده پیکسل **کوتاه‌تر** از کارت‌های کنارش می‌شد. هیچ خطایی
//     هم نمی‌داد.
//  ۳. `scroll-padding-inline` وقتی اسلایدر هم padding افقی دارد هم
//     `scroll-snap-type` — وگرنه اسنپ لبه‌ی اسلاید را به لبه‌ی جعبه‌ی
//     padding می‌چسباند و همان padding را خنثی می‌کند. یک بار روی نسخه‌ی
//     نواریِ همین قابلیت واقعاً همین شد و فقط با `scrollLeft` دیده شد.
//
// ⚠ هر سه فقط در مرورگر دیده می‌شوند و هیچ‌کدام خطا نمی‌دهند، پس
//   `php -l` و تستِ رندر هم نمی‌گیرندشان.
T::group('قاعده ۲۳ — اسلایدرِ خانه هم‌اندازه‌ی نوارِ ماه می‌ماند');

$carBad  = [];
$carFlex = $carRibbon = $carSnapPad = false;
$carHasPad = $carHasSnap = false;

preg_match_all('~([^{}]+)\{([^{}]*)\}~', $navCss, $carRules, PREG_SET_ORDER);
foreach ($carRules as $r) {
    foreach (explode(',', $r[1]) as $sel) {
        $sel = trim($sel);
        if ($sel === '.home-slides > *') {
            if (preg_match('~flex\s*:\s*0\s+0\s+100%~', $r[2])) { $carFlex = true; }
        } elseif ($sel === '.home-slides .balance-ribbon') {
            if (preg_match('~margin-bottom\s*:\s*0~', $r[2])) { $carRibbon = true; }
        } elseif ($sel === '.home-slides') {
            // ⚠ شورتهندِ `padding` هم شمرده می‌شود، نه فقط `padding-inline`:
            //   `padding: 0 14px 26px` همان ۱۴ پیکسل افقی را می‌دهد.
            if (preg_match('~padding(-inline|-left|-right)?\s*:\s*[^;]*\d~', $r[2])
                && !preg_match('~padding(-bottom|-top)\s*:~', $r[2])) { $carHasPad = true; }
            if (preg_match('~scroll-snap-type\s*:~', $r[2]))          { $carHasSnap = true; }
            if (preg_match('~scroll-padding(-inline|-left|-right)?\s*:~', $r[2])) { $carSnapPad = true; }
        }
    }
}
if (!$carFlex)   { $carBad[] = '`.home-slides > *` — `flex: 0 0 100%` ندارد؛ اسلاید دیگر هم‌اندازه‌ی نوارِ ماه نیست'; }
if (!$carRibbon) { $carBad[] = '`.home-slides .balance-ribbon` — `margin-bottom: 0` ندارد؛ نوار از کارت‌های کنارش کوتاه‌تر می‌شود'; }
if ($carHasPad && $carHasSnap && !$carSnapPad) {
    $carBad[] = '`.home-slides` — با padding افقی و scroll-snap، `scroll-padding-inline` هم لازم است';
}
T::bulk(3, $carBad, 'اسلایدهای خانه دقیقاً هم‌اندازه‌ی نوارِ ماه می‌مانند');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۴ — هر `.js-show-card` همه‌ی `data-*`ی که `app.js` می‌خواند را دارد.
//
// نمای کارت از **دو** صفحه باز می‌شود: ردیفِ `wallets.php` و کارتِ
// پین‌شده‌ی `index.php`. یک شنونده‌ی مشترک در `app.js` مقدارها را از
// `data-*` همان عنصر می‌خواند، پس اگر یکی از دو صفحه کلیدی را جا
// بیندازد یا نامش را عوض کند، **هیچ خطایی رخ نمی‌دهد**: مودال باز
// می‌شود و همان یک خط خالی است — شماره‌ی کارت نیست، یا دکمه‌ی «تراکنش‌های
// این حساب» به `?wallet=` خالی می‌رود.
//
// ⛔ فهرستِ کلیدها از **خودِ `app.js`** کشف می‌شود، نه از یک آرایه‌ی
//    دستی اینجا — همان قاعده‌ی `userDataTables()` و آرایه‌ی `MIGRATIONS`.
//    با فهرستِ دستی، کلیدِ یازدهمی که فردا به هندلر اضافه شود بی‌صدا
//    بیرونِ پوشش می‌ماند و تست به‌مرور بی‌ارزش می‌شد.
T::group('قاعده ۲۴ — کارت‌های نمای بانکی همه‌ی data-* لازم را دارند');

$appJs   = @file_get_contents(__DIR__ . '/../assets/js/app.js') ?: '';
$cardBad = [];
$need    = [];

$hs = strpos($appJs, "querySelectorAll('.js-show-card')");
$he = $hs === false ? false : strpos($appJs, "openModal('bankCardModal')", $hs);
if ($hs === false || $he === false) {
    $cardBad[] = 'هندلرِ `.js-show-card` در app.js پیدا نشد — قاعده کور شده است';
} else {
    preg_match_all("~getAttribute\('data-([a-z0-9-]+)'\)~", substr($appJs, $hs, $he - $hs), $mm);
    $need = array_values(array_unique($mm[1]));
}

$cardsSeen = 0;
if ($need) {
    foreach (['.', 'includes', 'admin'] as $dir) {
        foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
            // ⛔ بلوک‌های PHP **اول** خالی می‌شوند و این نیمه‌ی لازمِ کار
            //    است، نه یک احتیاط: مقدارِ هر `data-*` با یک اکوی PHP
            //    نوشته می‌شود و تگِ پایانش یک `>`ِ واقعی دارد. بدونِ این خط،
            //    پنجره‌ی «تا اولین `>`» سرِ اولین مقدار بریده می‌شد و تست
            //    روی فایلِ **سالم** هم می‌گفت هیچ `data-*`ی نیست — دقیقاً
            //    همان چیزی که در اولین اجرا شد (۴۰ هشدارِ الکی).
            //    خالی کردنشان کامنت‌های PHP را هم می‌برد، پس یادداشتی که
            //    نامِ کلاس را در متنش دارد شمرده نمی‌شود.
            $src = preg_replace_callback(
                '~<\?(?:php|=)?.*?\?>~s',
                static fn(array $m): string => str_repeat(' ', strlen($m[0])),
                file_get_contents($p)
            );
            // ⚠ پنجره تا اولین `>` بریده می‌شود، یعنی همان تگِ باز — نه یک
            //   تعداد ثابت کاراکتر، وگرنه به عنصرِ بعدی سرریز می‌کرد و
            //   `data-*`ِ او را به حساب این می‌گذاشت (همان درسِ قاعده ۶).
            $off = 0;
            while (($i = strpos($src, 'js-show-card', $off)) !== false) {
                $off  = $i + 1;
                $open = strrpos(substr($src, 0, $i), '<');
                $shut = strpos($src, '>', $i);
                if ($open === false || $shut === false) { continue; }
                $tag = substr($src, $open, $shut - $open);
                $cardsSeen++;
                foreach ($need as $k) {
                    if (!str_contains($tag, 'data-' . $k . '=')) {
                        $cardBad[] = basename($p) . " — `.js-show-card` بدونِ `data-{$k}`";
                    }
                }
            }
        }
    }
    if ($cardsSeen === 0) {
        $cardBad[] = 'هیچ `.js-show-card` ای در صفحه‌ها پیدا نشد — قاعده کور شده است';
    }
}

T::bulk(max(1, $cardsSeen), $cardBad, 'هر کارتِ بازکننده‌ی نما، کلیدهای data-* را کامل دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۹ — متنِ پیامکِ بانک هرگز روی سیم نمی‌رود.
//
// این تنها تضمینِ حریمِ خصوصیِ کلِ این قابلیت است و تا امروز «رایگان»
// به دست می‌آمد: وب اصلاً به SMS دسترسی نداشت، پس کاربر خودش متن را
// می‌چسباند و چیزی به سرور نمی‌رفت. حالا که اپ اندروید پیامک را
// **خودکار** می‌گیرد، همان تضمین باید عمداً نگه داشته شود.
//
// راهش این است که متن در **فرگمنتِ** آدرس (`#sms=…`) برود: مرورگر
// فرگمنت را هرگز به سرور نمی‌فرستد — نه در خطِ درخواست، نه در
// `Referer`، نه در لاگِ دسترسیِ nginx. با `?sms=…` متنِ خامِ پیامکِ
// بانکِ کاربر روی دیسکِ سرور می‌نشست، و **هیچ چیزی خراب به نظر
// نمی‌رسید**: قابلیت دقیقاً همان‌طور کار می‌کرد.
//
// ⚠ تغییرِ یک `#` به `?` کلِ این تضمین را بی‌صدا می‌برد. به همین دلیل
//   شکلش سنجیده می‌شود، نه فقط رفتارش.
T::group('قاعده ۱۹ — پیامکِ بانک فقط در فرگمنت، نه در آدرسِ سرور');

$smsBad  = [];
$smsSeen = 0;

$appJs = @file_get_contents(__DIR__ . '/../assets/js/app.js') ?: '';
if ($appJs !== '') {
    $smsSeen++;
    // ⚠ «`location.hash` جایی در فایل هست» کافی نیست — این رشته در
    //   بخش‌های دیگرِ همان فایل هم هست، پس با آن شرط، عوض کردنِ خودِ
    //   خطِ خواندنِ پیامک بی‌صدا از تست رد می‌شد. (آزمونِ جهش نشانش
    //   داد.) پس باید در **همان چند خط** کنارِ `'#sms='` باشد.
    $pos = strpos($appJs, "'#sms='");
    if ($pos === false) {
        $smsBad[] = 'app.js — نشانه‌ی `#sms=` پیدا نشد';
    } elseif (!preg_match('~location\.hash~', substr($appJs, max(0, $pos - 400), 500))) {
        $smsBad[] = 'app.js — پیامک از `location.hash` خوانده نمی‌شود';
    }
    if (preg_match('~[?&]sms=~', $appJs)) {
        $smsBad[] = 'app.js — `?sms=` پیدا شد؛ متن به سرور می‌رود';
    }
}

// سمتِ اندروید: آدرس با `#sms=` ساخته شود، و هیچ درخواستِ شبکه‌ای در
// کارِ گیرنده نباشد.
// ⛔ مسیرِ پکیج **سخت‌کد نمی‌شود**. نسخه‌ی اول
//    `java/ir/stland/daftar/*.java` بود، و اولین باری که نامِ بسته عوض
//    شد آن glob هیچ فایلی برنمی‌گرداند — یعنی همه‌ی بررسی‌های زیر بی‌صدا
//    ناپدید می‌شدند و تست **سبز** می‌ماند. سنجشی که روی خرابی سبز
//    می‌شود از نبودِ سنجش بدتر است.
$nativeJava = [];
$javaRoot   = __DIR__ . '/../mobile/app/src/main/java';
if (is_dir($javaRoot)) {
    $itJ = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($javaRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($itJ as $f) {
        if (preg_match('/\.java$/i', $f->getFilename())) { $nativeJava[] = $f->getPathname(); }
    }
}
// و اگر روزی هیچ‌کدام پیدا نشدند، خودِ همین نبودن یک خطاست.
if (!$nativeJava && is_dir($javaRoot)) {
    $smsBad[] = 'هیچ فایلِ جاوایی زیر mobile/ پیدا نشد — پلِ پیامک گم شده';
    $smsSeen++;
}
foreach ($nativeJava as $j) {
    $smsSeen++;
    $src  = file_get_contents($j);
    $name = basename($j);

    if (preg_match('~[?&]sms=~', $src)) {
        $smsBad[] = "$name — `?sms=` پیدا شد؛ متنِ پیامک به سرور می‌رفت";
    }
    // ⛔ هیچ کلاسِ شبکه‌ای: اگر روزی کسی وسوسه شود متن را «برای پارسِ
    //    بهتر» به یک اندپوینت بفرستد، همین‌جا می‌ایستد.
    foreach (['HttpURLConnection', 'OkHttp', 'java.net.Socket', 'URLConnection'] as $net) {
        if (str_contains($src, $net)) {
            $smsBad[] = "$name — «{$net}» در لایه‌ی بومی؛ پیامک نباید فرستاده شود";
        }
    }

    if ($name === 'BankSmsReceiver.java') {
        // ⛔ **متنِ پیامک ذخیره نمی‌شود** — همان قاعده‌ای که آن را در
        //    فرگمنت نگه می‌دارد. ردِ تشخیص فقط زمان و فرستنده و نتیجه
        //    است؛ نوشتنِ خودِ متن در SharedPreferences یک نسخه‌ی
        //    دائمیِ تازه از پیامکِ بانک روی دیسکِ گوشی می‌ساخت، برای
        //    چیزی که اصلاً به متن نیاز ندارد.
        if (preg_match('~put[A-Za-z]*\([^)]*\btext\b~', $src)) {
            $smsBad[] = "$name — متنِ پیامک در prefs ذخیره می‌شود؛ فقط نتیجه باید ثبت شود";
        }

        // ⛔ ردِ تشخیص باید **پیش از** خروجِ زودهنگام نوشته شود. کلِ
        //    ارزشش همان حالتی است که اعلانی ساخته نمی‌شود؛ اگر به داخلِ
        //    شاخه‌ی موفق منتقل شود، دقیقاً در خرابی ساکت می‌ماند و
        //    صفحه‌ی تنظیم می‌گوید «هیچ پیامکی نرسیده» در حالی که رسیده.
        // ⚠ جای **نوشتن** سنجیده می‌شود نه جای تعریفِ ثابت: نسخه‌ی اول
        //   دنبالِ خودِ `PREF_LAST_WHY` می‌گشت و آن اولین بار بالای فایل
        //   به‌عنوان ثابت تعریف شده، پس همیشه از هر چیزی جلوتر بود و
        //   بررسی **پوچ** می‌شد. جهش نشانش داد.
        $wrote  = strpos($src, 'putString(PREF_LAST_WHY');
        $bailed = strpos($src, '!WHY_OK.equals');
        if ($wrote === false || $bailed === false) {
            $smsBad[] = "$name — ردِ «آخرین پیامک» پیدا نشد؛ خرابی دوباره بی‌صدا می‌شود";
        } elseif ($wrote > $bailed) {
            $smsBad[] = "$name — ردِ تشخیص بعد از خروجِ زودهنگام نوشته می‌شود؛"
                      . ' پیامکِ ردشده هیچ ردی نمی‌گذارد';
        }
    }

    // ⛔ اعلانِ آزمایشی باید از **همان** مسیرِ اعلانِ واقعی برود. با یک
    //    سازنده‌ی دومِ اعلان در صفحه‌ی تنظیم، دکمه‌ی آزمایش می‌توانست
    //    اعلان نشان بدهد در حالی که مسیرِ واقعیِ پیامک خراب است — یعنی
    //    همان «سنجشی که روی خرابی سبز می‌شود»، این بار در دستِ کاربر.
    if ($name === 'SmsSetupActivity.java') {
        foreach (['Notification.Builder', 'NotificationCompat.Builder', '.notify('] as $dup) {
            if (str_contains($src, $dup)) {
                $smsBad[] = "$name — «{$dup}» یعنی سازنده‌ی دومِ اعلان؛"
                          . ' آزمایش باید از postNotification برود';
            }
        }
        if (!str_contains($src, 'BankSmsReceiver.postNotification')) {
            $smsBad[] = "$name — آزمایشِ اعلان از مسیرِ واقعی نمی‌رود";
        }
    }
}

// و خودِ گیرنده باید هنوز در manifest ثبت باشد — بدونِ آن اپ ساخته
// می‌شود، نصب می‌شود، و فقط هیچ پیامکی نمی‌گیرد.
$manifest = @file_get_contents(__DIR__ . '/../mobile/app/src/main/AndroidManifest.xml') ?: '';
if ($manifest !== '') {
    $smsSeen++;
    foreach (['BankSmsReceiver' => 'گیرنده‌ی پیامک',
              'android.permission.RECEIVE_SMS' => 'مجوز خواندن پیامک',
              'SmsSetupActivity' => 'صفحه‌ی روشن/خاموش'] as $needle => $what) {
        if (!str_contains($manifest, $needle)) {
            $smsBad[] = "AndroidManifest — {$what} ثبت نشده";
        }
    }
}

T::bulk($smsSeen, $smsBad, 'متنِ پیامک فقط از راهِ فرگمنت جابه‌جا می‌شود');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۸ — عنصری که `display` دارد، باید `[hidden]` خودش را هم داشته باشد.
//
// ویژگیِ `hidden` مرورگر با یک قاعده‌ی **UA** به‌شکلِ `display: none`
// پیاده می‌شود، و هر قاعده‌ی نویسنده — حتی یک `.foo { display: flex }`
// ساده — بر آن غلبه می‌کند. یعنی عنصری که در HTML `hidden` نوشته شده
// **دیده می‌شود**، بی‌هیچ خطایی.
//
// این تا امروز سه بار زده: `.sms-conn` (کارتِ پنهانِ پنلِ پیامک)،
// `.asset-item` / `.chart-container` (قلمِ خاموشِ دارایی)، و
// `.stay-chips` (چیپ‌های «مبلغ هر قسط / جمع کل» که فقط برای تعهدِ
// چندقسطی معنا دارند و همیشه دیده می‌شدند). هر سه بار فقط با چشم و
// روی مرورگر پیدا شد.
//
// ⚠ فقط کلاس‌هایی سنجیده می‌شوند که واقعاً در PHP با `hidden` نوشته
//   شده‌اند — وگرنه هر کلاسِ `display`داری هشدار می‌داد و هشدارِ الکی
//   از نبودِ تست بدتر است.
T::group('قاعده ۱۸ — عنصرِ پنهان‌شونده باید [hidden] خودش را داشته باشد');

$css = file_get_contents(__DIR__ . '/../assets/css/style.css');

// کلاس‌هایی که در HTML با ویژگیِ `hidden` رندر می‌شوند.
// ⚠ `includes/` هم باید اسکن شود: شیتِ ثبت تراکنش آنجاست و در **هر**
//   صفحه رندر می‌شود، پس یک `[hidden]`ِ جامانده آنجا همه‌جا دیده می‌شود.
$hiddenClasses = [];
foreach (['admin', 'includes', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = file_get_contents($p);
        // تگی که هم `class="…"` دارد هم `hidden` تنها (نه `data-hidden`).
        if (!preg_match_all('~<[a-z]+[^>]*>~i', $src, $tags)) { continue; }
        foreach ($tags[0] as $tag) {
            if (!preg_match('~\shidden(?=[\s/>])~i', $tag)) { continue; }
            if (!preg_match('~\sclass="([^"]*)"~i', $tag, $m)) { continue; }
            foreach (preg_split('~\s+~', trim($m[1])) as $cls) {
                if ($cls !== '' && !str_contains($cls, '<')) { $hiddenClasses[$cls] = basename($p); }
            }
        }
    }
}

// ⚠ فقط قاعده‌ای می‌شمارد که **خودِ** آن کلاس را هدف بگیرد، نه یک
//   فرزندش: `.form-group label { display: … }` هیچ ربطی به پنهان شدنِ
//   `.form-group` ندارد و شمردنش یعنی چهار هشدارِ الکی. پس آخرین
//   «compound»ِ هر انتخابگر جدا می‌شود.
$lastCompound = function (string $sel): string {
    // ⚠ جداکننده `~` نیست: خودِ `~` یکی از ترکیب‌کننده‌های CSS است.
    $parts = preg_split('#\s*[>+~]\s*|\s+#', trim($sel));
    return $parts ? (string)end($parts) : '';
};

// قاعده‌های سطحِ بالای CSS (کافی است؛ داخلِ @media هم با همین الگو
// خوانده می‌شود چون فقط دنبالِ جفتِ «انتخابگر { بدنه }» است).
preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);

$hasDisplay = [];   // کلاس → قاعده‌ای که مستقیماً display می‌دهد
$hasHidden  = [];   // کلاس → قاعده‌ای با [hidden]
foreach ($rules as $r) {
    $body    = $r[2];
    $setsDsp = (bool)preg_match('~\bdisplay\s*:~', $body);
    foreach (explode(',', $r[1]) as $sel) {
        $comp = $lastCompound($sel);
        if ($comp === '') { continue; }
        if (!preg_match_all('~\.([A-Za-z0-9_-]+)~', $comp, $cm)) { continue; }
        $isHiddenRule = str_contains($comp, '[hidden]');
        foreach ($cm[1] as $c) {
            if ($isHiddenRule) { $hasHidden[$c] = true; }
            elseif ($setsDsp)  { $hasDisplay[$c] = true; }
        }
    }
}

$hiddenBad = [];
foreach ($hiddenClasses as $cls => $where) {
    if (empty($hasDisplay[$cls]) || !empty($hasHidden[$cls])) { continue; }
    $hiddenBad[] = ".$cls (در {$where}) — display دارد ولی .{$cls}[hidden] ندارد";
}
T::bulk(count($hiddenClasses), $hiddenBad,
    'هر کلاسِ پنهان‌شونده‌ای که display دارد، [hidden] خودش را هم دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۷ — کامنتِ پی‌اچ‌پی نباید داخلِ رشته‌ی SQL باشد.
//
// MySQL کامنتِ `//` نمی‌شناسد (فقط `--`، `#` و `/* */`). یک بار دو خط
// توضیح **داخلِ** کوتیشنِ یک `INSERT` نوشته شدند و نتیجه‌اش این بود که
// ثبتِ هر یادآورِ تازه با خطای نحویِ SQL رد می‌شد — در حالی که:
//   • `php -l` سبز بود (رشته از دیدِ PHP کاملاً درست است)،
//   • ویرایش و «انجام شد» کار می‌کردند (کوئریِ دیگری بودند)،
//   • و هیچ تستی آن مسیر را با پیلودِ واقعی صدا نمی‌زد.
// یعنی دقیقاً همان خانواده‌ی خرابیِ **بی‌صدا**ی این پروژه.
//
// ⚠ فقط داخلِ رشته سنجیده می‌شود، نه در کدِ عادی: `//` در خودِ PHP
//   کامنتِ درستی است و توکنایزر آن دو را از هم جدا می‌کند. و فقط
//   رشته‌هایی که واقعاً SQL به نظر می‌رسند، وگرنه هر رشته‌ی حاویِ یک
//   آدرسِ `https://` هشدارِ الکی می‌داد — و هشدارِ الکی از نبودِ تست
//   بدتر است.
T::group('قاعده ۱۷ — کامنتِ پی‌اچ‌پی داخلِ رشته‌ی SQL نباشد');

$badSql  = [];
$sqlScan = 0;
$sqlWord = '~\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\b~i';
foreach (['api', 'api/v1', 'includes', 'admin', 'deploy', 'tests', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $sqlScan++;
        foreach (token_get_all(file_get_contents($p)) as $tk) {
            if (!is_array($tk)) { continue; }
            if ($tk[0] !== T_CONSTANT_ENCAPSED_STRING && $tk[0] !== T_ENCAPSED_AND_WHITESPACE
                && $tk[0] !== T_INLINE_HTML) { continue; }
            if ($tk[0] === T_INLINE_HTML) { continue; }
            if (!preg_match($sqlWord, $tk[1])) { continue; }
            // خطی که با `//` یا `#` شروع می‌شود — یعنی کامنتی که یادش
            // رفته بیرونِ رشته بماند. (`--` کامنتِ خودِ SQL است و مجاز.)
            foreach (explode("\n", $tk[1]) as $ln) {
                if (preg_match('~^\s*(//|#\s)~', $ln)) {
                    $badSql[] = basename($p) . ' خط ' . $tk[2] . ' → ' . trim(mb_substr($ln, 0, 60));
                    break;
                }
            }
        }
    }
}
T::bulk($sqlScan, $badSql, 'هیچ رشته‌ی SQL ای کامنتِ پی‌اچ‌پی داخلش ندارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۶ — لینک یا ریدایرکتِ داخلی باید به فایلی برسد که هست.
//
// قاعده‌ی nginx این است: `location ~ \.php$` شاملِ
// `include snippets/fastcgi-php.conf` می‌شود که خودش `try_files $uri =404`
// دارد — یعنی آدرسی که فایلش روی دیسک نباشد اصلاً به PHP **نمی‌رسد**.
// نتیجه‌اش صفحه‌ی ۴۰۴ خودِ nginx است: بدون قالب، بدون منو، و بدون هیچ
// ردی در لاگِ PHP. یعنی این خرابی **بی‌صداست** و هیچ‌کدام از تست‌های
// دیگر نمی‌بیندش — نه `php -l` (فایل نحوش درست است)، نه تست‌های رفتاری
// (آن صفحه‌ها اصلاً صدا زده نمی‌شوند).
//
// دو راهِ افتادن در آن، و هر دو یک بار در همین پروژه ممکن بوده:
//   ۱. صفحه‌ای حذف شود ولی لینکش در منو بماند (مثل حذفِ
//      `admin/all-transactions.php`).
//   ۲. `redirectWithMessage()` بعد از ذخیره به صفحه‌ای برود که نیست —
//      آن‌وقت کاربر «ذخیره» می‌زند و ۴۰۴ می‌بیند، و چون خودِ ذخیره
//      انجام شده، هیچ خطایی هم در کار نیست که راهنمایی‌اش کند.
T::group('قاعده ۱۶ — هر لینک و ریدایرکتِ داخلی باید فایل داشته باشد');

$root      = realpath(__DIR__ . '/..');
$linkFiles = [];
foreach (['.', 'admin', 'includes'] as $d) {
    foreach (glob($root . '/' . $d . '/*.php') as $p) { $linkFiles[realpath($p)] = true; }
}
foreach (glob($root . '/assets/js/*.js') as $p) { $linkFiles[realpath($p)] = true; }

$deadLinks  = [];
$linkChecks = 0;
foreach (array_keys($linkFiles) as $p) {
    $src = file_get_contents($p);
    $targets = [];

    // href="…" / action="…" و ریدایرکت‌های سمت سرور و مرورگر
    foreach ([
        '~\b(?:href|action)\s*=\s*"([^"]*)"~',
        "~\\bredirectWithMessage\\(\\s*'([^']*)'~",
        "~\\bheader\\(\\s*'Location:\\s*([^']*)'~",
        "~\\blocation(?:\\.href)?\\s*=\\s*'([^']*)'~",
    ] as $re) {
        if (preg_match_all($re, $src, $m)) {
            foreach ($m[1] as $t) { $targets[] = $t; }
        }
    }

    foreach ($targets as $t) {
        // تنها مسیرِ ثابتِ APP_BASE_PATH شناخته می‌شود؛ بقیه‌ی مقدارهای
        // پویا (متغیر، الحاق رشته، اکوی PHP) عمداً سنجیده نمی‌شوند —
        // هشدارِ الکی از نبودِ تست بدتر است.
        $t = str_replace('<?= APP_BASE_PATH ?>', '', $t);
        $t = preg_replace('~[?#].*$~', '', $t);
        if ($t === '' || $t === '/') { continue; }
        if (str_contains($t, '<?') || str_contains($t, '$') || str_contains($t, '{')) { continue; }
        if (str_contains($t, "'") || str_contains($t, ' . ')) { continue; }  // الحاقِ رشته
        if (preg_match('~^(?:[a-z]+:|//|#)~i', $t)) { continue; }
        // فقط آدرسِ فایل؛ مسیرهای بدون پسوند کارِ nginx و try_files است.
        if (!preg_match('~\.[a-z0-9]{2,5}$~i', $t)) { continue; }

        $linkChecks++;

        // ⛔ فایلِ مشترک (includes/ و assets/js/) روی صفحه‌هایی با عمقِ
        // مختلف اجرا می‌شود و مرورگر مسیرِ نسبی را نسبت به **آدرسِ
        // صفحه** حل می‌کند، نه محلِ خودِ فایل. پس مسیرِ نسبی آنجا
        // «گاهی درست» است و همین بدترین حالت است: از ریشه کار می‌کند و
        // از `/admin/` نه. مطلق بنویسید (`APP_BASE_PATH` یا
        // `window.APP_BASE`). حل کردنش نسبت به ریشه در همین تست، خودِ
        // تست را نسبت به این باگ کور می‌کرد.
        $shared = str_starts_with($p, $root . '/includes/')
               || str_starts_with($p, $root . '/assets/');
        if ($shared && !str_starts_with($t, '/')) {
            $deadLinks[] = str_replace($root . '/', '', $p)
                         . ' → ' . $t . ' (مسیرِ نسبی در فایلِ مشترک)';
            continue;
        }

        $abs = str_starts_with($t, '/')
            ? $root . $t
            : dirname($p) . '/' . $t;

        if (!is_file($abs)) {
            $deadLinks[] = str_replace($root . '/', '', $p) . ' → ' . $t;
        }
    }
}
T::ok($linkChecks >= 30, 'لینک‌های ثابت پیدا شدند', 'سنجیده شد: ' . $linkChecks);
T::bulk($linkChecks, $deadLinks, 'هر لینک و ریدایرکتِ داخلی فایلِ موجود دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۱ — هر لینکِ `due.php?t=…` باید زبانه‌ی واقعی باشد.
//
// پنج مقصدِ جدا («آینده مالی»، «تقویم مالی»، «سررسیدها»، «یادآورها»)
// در یک صفحه با سه زبانه ادغام شدند و آدرس‌های قدیمی به همان زبانه
// هدایت می‌شوند. آن هدایت‌ها **جای بوک‌مارک، میان‌برِ PWA، اعلانِ داخلِ
// اپ، و ایمیل‌هایی که از قبل فرستاده شده‌اند** را می‌گیرند.
//
// ⛔ و خرابی‌اش کاملاً بی‌صداست: `due.php` هر `?t=` ناشناخته را به
//    زبانه‌ی پیش‌فرض می‌برد. پس عوض کردنِ نامِ یک زبانه هیچ خطایی
//    نمی‌دهد — فقط لینکِ ایمیلِ «یادآورها» از فردا کاربر را به فهرستِ
//    سررسید می‌برد و هیچ‌کس نمی‌فهمد چرا.
T::group('قاعده ۲۱ — زبانه‌های سررسید: لینک و فهرست یکی بمانند');

$dueSrc = @file_get_contents(__DIR__ . '/../due.php') ?: '';
$tabKeys = [];
if (preg_match('~const\s+DUE_TABS\s*=\s*\[(.*?)\];~s', $dueSrc, $m)) {
    preg_match_all("~'([a-z_]+)'\s*=>~", $m[1], $km);
    $tabKeys = $km[1];
}
T::ok(count($tabKeys) >= 2, 'فهرستِ DUE_TABS در due.php پیدا شد',
    'زبانه‌ها: ' . implode(', ', $tabKeys));

$tabBad = [];
$tabSeen = 0;
foreach (['*.php', 'includes/*.php', 'api/*.php', 'admin/*.php'] as $g) {
    foreach (glob(__DIR__ . '/../' . $g) as $p) {
        $src = file_get_contents($p);
        if (!preg_match_all("~due\.php\?t=([a-z_]+)~", $src, $mm)) { continue; }
        foreach ($mm[1] as $key) {
            $tabSeen++;
            if (!in_array($key, $tabKeys, true)) {
                $tabBad[] = str_replace(__DIR__ . '/../', '', $p) . " → t={$key} (زبانه‌ای به این نام نیست)";
            }
        }
    }
}
T::ok($tabSeen >= 3, 'لینک‌های زبانه‌دار پیدا شدند', 'سنجیده شد: ' . $tabSeen);
T::bulk($tabSeen, $tabBad, 'هر لینکِ `due.php?t=…` به زبانه‌ی موجود می‌رود');

// ⚠ و خودِ استاب‌ها: صفحه‌ای که ادغام شده باید **بماند** و هدایت کند.
//   حذفشان بوک‌مارک و ایمیل‌های فرستاده‌شده را می‌شکند، بی‌هیچ خطایی.
$stubBad = [];
foreach (['upcoming.php' => 'list', 'calendar.php' => 'calendar',
          'reminders.php' => 'reminders'] as $file => $tab) {
    $p = __DIR__ . '/../' . $file;
    if (!is_file($p)) { $stubBad[] = "$file — فایل حذف شده؛ بوک‌مارک و ایمیل‌های قبلی می‌شکنند"; continue; }
    $s = file_get_contents($p);
    if (!str_contains($s, 'due.php?t=' . $tab)) {
        $stubBad[] = "$file — به `due.php?t={$tab}` هدایت نمی‌کند";
    }
    if (!str_contains($s, 'Auth::requireLogin()')) {
        $stubBad[] = "$file — بدونِ ورود هم هدایت می‌کند (باید به صفحه‌ی ورود برود)";
    }
}
T::bulk(3, $stubBad, 'آدرس‌های قدیمی هنوز به زبانه‌ی درست هدایت می‌شوند');

// ---------------------------------------------------------------
T::group('نحو — هر فایل PHP باید بدون خطا پارس شود');

$bad = [];
// ⛔ قاعده ۲۲ — نامِ برند فقط از `APP_NAME` می‌آید.
//
// `config.php` ثابتِ `APP_NAME` را دارد و بیشترِ جاها از همان می‌خوانند،
// ولی دو نقطه‌ی **دیده‌شدنی** سخت‌کد مانده بودند: موضوعِ ایمیلِ یادآوریِ
// روزانه، و فیلدِ `app` داخلِ خودِ فایلِ بکاپِ کاربر.
//
// ⛔ چرا مهم است: خرابی‌اش برای مالکِ نصب **بی‌صداست**. کسی که برند را
//    عوض می‌کند، اپ را باز می‌کند و همه‌چیز درست است — ولی ایمیل‌هایش با
//    نامِ ما می‌روند و فایل‌های بکاپش نامِ ما را دارند. و آن ایمیل با
//    cron می‌رود، پس خروجی‌اش را هیچ‌کس نمی‌بیند.
//
// ⚠ با توکنایزر است نه grep: همین توضیح، خودش کلمه‌ی برند را دارد و با
//   جست‌وجوی متنی تست روی فایلِ **سالم** هم قرمز می‌شد.
T::group('قاعده ۲۲ — نامِ برند فقط از APP_NAME');

$brand = 'حساب لند';
$brandFiles = [];
foreach (['.', 'includes', 'api', 'admin', 'deploy'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $brandFiles[realpath($p)] = true; }
}
// ⚠ متغیرِ جدا، نه `$bad`: بلوکِ بعدی (بررسیِ نحو) هم `$bad[]` می‌زند
//   بدونِ خالی کردنش، پس با نامِ مشترک، خطاهای این قاعده در گزارشِ آن
//   یکی هم تکرار می‌شدند — یعنی تست **دروغ** می‌گفت کدام بررسی شکسته.
//   در همان اولین اجرا دیده شد.
$badBrand = [];
$scanned = 0;
foreach (array_keys($brandFiles) as $path) {
    // `config/` استثناست: **جای تعریفِ** خودِ ثابت است.
    if (str_contains($path, '/config/')) { continue; }
    $src = (string)@file_get_contents($path);
    if (!str_contains($src, $brand)) { $scanned++; continue; }
    $scanned++;

    $lines = explode("\n", $src);
    foreach (token_get_all($src) as $tok) {
        if (!is_array($tok)) { continue; }
        // فقط رشته‌ی واقعی — کامنت و docblock اینجا اصلاً نمی‌آیند.
        if ($tok[0] !== T_CONSTANT_ENCAPSED_STRING && $tok[0] !== T_ENCAPSED_AND_WHITESPACE
            && $tok[0] !== T_INLINE_HTML) { continue; }
        if (!str_contains($tok[1], $brand)) { continue; }

        // ⚠ الگوی مجازِ برگشتی: `defined('APP_NAME') ? APP_NAME : 'حساب لند'`
        //   روی همان خط. نصبی که هنوز ثابت را ندارد نباید بشکند.
        $line = $lines[$tok[2] - 1] ?? '';
        if (str_contains($line, 'APP_NAME')) { continue; }

        $badBrand[] = basename(dirname($path)) . '/' . basename($path) . ':' . $tok[2]
               . ' — نامِ برند سخت‌کد شده؛ از APP_NAME بخوانید';
    }
}
T::bulk($scanned, $badBrand, 'هیچ متنِ دیده‌شدنی‌ای نامِ برند را سخت‌کد نکرده');

// ---------------------------------------------------------------
T::group('قاعده ۲۵ — قاعده‌ی نام کاربری فقط در usernameRuleError()');

/*
 * سه مسیر نام کاربری می‌نویسند (ثبت‌نام، پنل مدیر، پروفایل) و هر کدام
 * الگوی خودش را داشت. دو تا با هم نمی‌خواندند: یکی نقطه را می‌ساخت و
 * دیگری ردش می‌کرد — پس کاربری با نامِ `ali.k` **هرگز** نمی‌توانست
 * پروفایلش را ذخیره کند، و خطا درباره‌ی فیلدی بود که دست نزده بود.
 *
 * ⚠ با توکنایزر است نه grep: همین توضیح خودش الگو را در متن دارد و با
 *   جست‌وجوی متنی، تست روی فایلِ **سالم** هم قرمز می‌شد — همان درسی که
 *   قاعده ۲۲ داد.
 */
$badUser = [];
$userScanned = 0;
$userFiles = [];
foreach (['.', 'includes', 'api', 'admin'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $userFiles[realpath($p)] = true; }
}
foreach (array_keys($userFiles) as $path) {
    $userScanned++;
    // ⛔ تنها استثناء: خودِ جای تعریفِ قاعده.
    if (basename($path) === 'signup.php') { continue; }

    $src = (string)@file_get_contents($path);
    if (!str_contains($src, 'username')) { continue; }
    $lines = explode("\n", $src);

    foreach (token_get_all($src) as $tok) {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        // الگویی که کلاسِ کاراکترِ نام کاربری را تعریف می‌کند: هم
        // `[a-zA-Z0-9_.]` هم `[A-Za-z0-9_]{3,50}` را می‌گیرد.
        if (!preg_match('/\[[aA]-[zZ][A-Za-z]*-?[A-Za-z]*0-9[_.\-]/', $tok[1])) { continue; }

        // ⚠ فقط وقتی که همان خط واقعاً روی **نام کاربری** اعمالش کند.
        //   بدونِ این شرط، توصیفِ شکلِ کلیدِ پنلِ پیامک در
        //   `includes/sms.php` هم قرمز می‌شد — یعنی هشدارِ الکی روی
        //   فایلِ سالم، که از نبودِ تست بدتر است. در همان اجرای اول شد.
        $line = $lines[$tok[2] - 1] ?? '';
        if (!str_contains($line, 'username')) { continue; }

        $badUser[] = basename(dirname($path)) . '/' . basename($path) . ':' . $tok[2]
            . ' — الگوی نام کاربری اینجا تکرار شده؛ از usernameRuleError() رد شوید';
    }
}
T::bulk($userScanned, $badUser, 'قاعده‌ی نام کاربری فقط یک جا تعریف شده');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۶ — گیتِ اشتراک فقط روی مسیرِ **ساختِ رکوردِ تازه**.
//
// اشتراکِ تمام‌شده نباید دفترِ کاربر را قفل کند: ویرایش، حذف، تسویه و
// پرداختِ قسط باید کار کنند، وگرنه دفتر روی واقعیتِ ماه‌ها پیش یخ
// می‌زند — و دفترِ غلط از دفترِ نداشته بدتر است.
//
// فهرست **بسته** است و دو طرفه می‌سنجد، چون هر دو خرابی بی‌صدایند:
//   • گیت روی اندپوینتی که نباید → کاربر نمی‌تواند چکِ خودش را
//     «پاس شد» کند و فقط یک پیامِ «اشتراک لازم است» می‌بیند.
//   • نبودِ گیت روی اندپوینتِ ساخت → قفل با یک درخواستِ مستقیم دور
//     می‌خورد، دقیقاً مثل روزی که فقط صفحه گیت داشت.
T::group('قاعده ۲۶ — گیتِ اشتراک فقط روی ساختِ رکوردِ تازه');

// اندپوینت‌هایی که **باید** `apiRequirePlan()` داشته باشند.
// ⚠ `save_recurring` هم می‌سازد هم ویرایش می‌کند، پس گیتش شرطی است
//   (`$id === 0`) و همین‌جا هم شرطی سنجیده می‌شود.
const PLAN_GATE_REQUIRED = [
    'add_cheque.php'    => 'cheques',
    'add_debt.php'      => 'debts',
    'save_trade.php'    => 'trades',
    'save_recurring.php'=> 'recurring',
];

$planScanned = 0;
$badPlan     = [];

foreach (glob(__DIR__ . '/../api/*.php') as $path) {
    $planScanned++;
    $name = basename($path);
    $src  = (string)@file_get_contents($path);

    // با توکنایزر، نه با grep: همین توضیح خودش نامِ تابع را دارد و
    // جست‌وجوی متنی تست را روی فایلِ سالم قرمز می‌کرد.
    $calls = false;
    $toks  = token_get_all($src);
    foreach ($toks as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'apiRequirePlan') { $calls = true; break; }
    }

    if (isset(PLAN_GATE_REQUIRED[$name])) {
        if (!$calls) {
            $badPlan[] = "api/{$name} — اندپوینتِ ساخت است ولی apiRequirePlan() ندارد؛ "
                       . 'قفل با یک درخواستِ مستقیم دور می‌خورد';
        } elseif ($name === 'save_recurring.php' && !str_contains($src, '$id === 0')) {
            // ⛔ گیتِ بی‌قید اینجا یعنی ویرایشِ قانونِ موجود هم بسته
            //    می‌شود: کاربرِ منقضی نمی‌تواند مبلغِ قبضِ همیشگی‌اش را
            //    هم درست کند.
            $badPlan[] = "api/{$name} — گیت باید فقط برای رکوردِ تازه باشد (\$id === 0)";
        }
    } elseif ($calls) {
        $badPlan[] = "api/{$name} — رکوردِ تازه نمی‌سازد ولی گیت دارد؛ "
                   . 'با اشتراکِ تمام‌شده کاربر نمی‌تواند داده‌ی خودش را ویرایش/تسویه کند';
    }
}
T::bulk($planScanned, $badPlan, 'گیتِ اشتراک فقط روی اندپوینت‌های ساخت است');

// و نیمه‌ی صفحه: هیچ صفحه‌ای نباید محتوا را با یک صفحه‌ی قفل عوض کند.
$badLock = [];
foreach (glob(__DIR__ . '/../*.php') as $path) {
    $src = (string)@file_get_contents($path);
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'requirePlanOrLock') {
            $badLock[] = basename($path) . ' — صفحه نباید با قفل جایگزین شود؛ '
                       . 'از planReadOnly() + planReadOnlyNotice() رد شوید';
        }
    }
}
T::bulk(count(glob(__DIR__ . '/../*.php')), $badLock,
    '⛔ هیچ صفحه‌ای محتوای کاربر را پشتِ صفحه‌ی قفل پنهان نمی‌کند');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۷ — سرآیندِ آی‌پیِ CDN هرگز در PHP خوانده نمی‌شود.
//
// وقتی سایت پشتِ کلادفلر می‌رود، `REMOTE_ADDR` آی‌پیِ لبه است و آی‌پیِ
// واقعی در `CF-Connecting-IP` می‌آید. وسوسه‌ی طبیعی این است که همان‌جا
// در PHP خوانده شود — و آن **یک آسیب‌پذیری است، نه یک میان‌بر**:
// سرآیند را هر کسی می‌تواند بفرستد، پس سدِ حدس رمز، سقفِ بازیابیِ رمز،
// سدِ ثبت‌نام و سقفِ پیامک همگی با یک خطِ curl دور می‌خورند.
//
// جایگزینی باید در nginx باشد (`deploy/nginx-realip.sh`)، چون آنجا فقط
// وقتی انجام می‌شود که **خودِ اتصال** از یکی از بازه‌های کلادفلر آمده
// باشد. آن‌وقت `REMOTE_ADDR` خودش درست است و هیچ‌جای PHP عوض نمی‌شود.
//
// ⚠ با توکنایزر است نه grep: همین توضیح و متنِ خودِ اسکریپت نامِ آن
//   سرآیندها را دارند و با جست‌وجوی متنی، تست روی فایلِ **سالم** هم
//   قرمز می‌شد — همان دامی که قاعده‌های ۹ و ۲۲ هم برایش نوشته شدند.
T::group('قاعده ۲۷ — آی‌پی فقط از REMOTE_ADDR');

$PROXY_KEYS = [
    'HTTP_CF_CONNECTING_IP',
    'HTTP_X_FORWARDED_FOR',
    'HTTP_X_REAL_IP',
    'HTTP_TRUE_CLIENT_IP',
    'HTTP_X_CLIENT_IP',
];
$badProxy = [];
$proxyScanned = 0;
foreach (['api', 'api/v1', 'includes', 'admin', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $path) {
        $proxyScanned++;
        $src = (string)@file_get_contents($path);
        foreach (token_get_all($src) as $t) {
            // فقط رشته‌های واقعیِ کد، نه کامنت و نه HTML بیرونِ تگ.
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
            $val = trim($t[1], "'\"");
            if (in_array($val, $PROXY_KEYS, true)) {
                $badProxy[] = basename($path) . " — «{$val}» جعل‌شدنی است؛ "
                            . 'جایگزینی باید در nginx باشد (deploy/nginx-realip.sh)';
            }
        }
    }
}
T::bulk($proxyScanned, $badProxy,
    '⛔ هیچ فایلی سرآیندِ آی‌پیِ CDN را باور نمی‌کند');

/*
 * ⛔ و نیمه‌ی دومِ همین قاعده: مسیرِ سنجشِ `/__realip` که همان اسکریپت
 *   می‌سازد باید واقعاً بسته باشد.
 *
 *   نسخه‌ی اول با `allow 127.0.0.1; deny all;` بسته **به نظر می‌رسید** و
 *   نبود: `return` در فازِ rewrite اجرا می‌شود و آن پیش از فازِ access
 *   است، پس آن دو خط هرگز نوبتشان نمی‌رسد. روی سرور واقعی دیده شد —
 *   درخواست از بیرون به‌جای ۴۰۳، **۲۰۰** گرفت — و با nginx 1.24 بازتولید
 *   شد. کامنتِ بالای همان بلوک هم ادعا می‌کرد «بیرون بسته است»، یعنی
 *   نگهبانی که کار نمی‌کند به‌علاوه‌ی یک ادعای نادرست کنارش.
 *
 *   گیتِ درست روی `$realip_remote_addr` است (آدرسِ واقعیِ اتصال، پیش از
 *   جایگزینی): بازدیدکننده‌ی بیرونی ۴۰۴ می‌گیرد ولی سنجشِ خودِ اسکریپت —
 *   که از 127.0.0.1 وصل می‌شود و سرآیند می‌فرستد — دست‌نخورده کار می‌کند.
 */
/*
 * ⚠ و بلوک **رندرشده** سنجیده می‌شود، نه متنِ اسکریپت. اسکریپت آن را از
 *   راهِ `awk -v` داخلِ فایلِ سایت می‌نویسد و awk هر دنباله‌ی escape را
 *   **بی‌صدا** می‌خورد: `\.` می‌شود `.`. یعنی چیزی که روی سرور می‌نشیند
 *   با چیزی که در سورس نوشته شده یکی نبود، و تستی که سورس را می‌سنجید
 *   چیزی را تأیید می‌کرد که اجرا نمی‌شود. روی سرور واقعی با همان هشدارِ
 *   awk دیده شد.
 */
$realipSh = __DIR__ . '/../deploy/nginx-realip.sh';
$realipSrc = (string)@file_get_contents($realipSh);
T::ok($realipSrc !== '', 'deploy/nginx-realip.sh وجود دارد');

$rendered = '';
if (preg_match('/^build_block\(\) \{.*?\n\}/ms', $realipSrc, $m)) {
    $tmp = sys_get_temp_dir() . '/realip_block_' . getmypid() . '.sh';
    file_put_contents($tmp, "BEGIN=''\nEND=''\n" . $m[0] . "\nbuild_block '127.0.0.1'\n");
    $rendered = (string)@shell_exec('bash ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
}
T::ok($rendered !== '', 'بلوکِ nginx رندر شد');
T::ok(str_contains($rendered, 'if ($realip_remote_addr !~')
      && str_contains($rendered, 'return 404;'),
    '⛔ گیتِ /__realip روی آدرسِ واقعیِ اتصال است');
T::ok(!preg_match('/awk\s+-v\s+block=/', $realipSrc),
    '⛔ بلوک با `awk -v` منتقل نمی‌شود — awk مقدارِ -v را تفسیر می‌کند و '
    . 'هر escape را بی‌صدا عوض می‌کند (`\\.`→`.`، `\\n`→خطِ واقعی)');
T::ok(!preg_match('/deny\s+all/', $rendered),
    '⛔ allow/deny کنارِ return برنگشته — آنجا اصلاً اجرا نمی‌شود '
    . 'و مسیر را برای کلِ اینترنت باز می‌گذارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۸ — دو تصمیمِ تازه، هر کدام فقط یک جا.
//
// نیمه‌ی اول: **گیتِ ورودِ پیامکی**. `SmsLogin::loginAllowedFor()` تنها
// جایی است که `planAllows(..., 'sms_login')` خوانده می‌شود، چون آن گیت
// دو استثنای عمدی دارد (کلیدِ مدیر که از پرداخت هم بالاتر است، و
// حسابِ بی‌رمز که پیامک تنها درِ اوست). صفحه‌ای که مستقیم `planAllows`
// را بپرسد آن دو را نمی‌بیند و **بی‌صدا** کاربر را بیرونِ دفترِ خودش
// قفل می‌کند.
//
// نیمه‌ی دوم: **هیچ `password_verify()` بدونِ سنجشِ حسابِ بی‌رمز**.
// از وقتی `password_hash` می‌تواند `NULL` باشد، هر مسیرِ تازه‌ای که
// یادش برود این را بسنجد یک هشدارِ `Deprecated` در هر تلاش می‌سازد (و
// در PHP 9 یک ۵۰۰). همان شکلِ قاعده ۱۴: فایل باید **در همان فایل**
// نشانه‌ی سنجش را داشته باشد، تا مسیرِ پنجمیِ فردا جا نماند.
//
// ⚠ با توکنایزر است نه grep: همین توضیح هر دو نام را دارد و با
//   جست‌وجوی متنی، تست روی فایلِ سالم هم قرمز می‌شد.
T::group('قاعده ۲۸ — گیتِ پیامک و نگهبانِ حسابِ بی‌رمز');

$phpFiles = [];
foreach (['api', 'api/v1', 'includes', 'admin', 'deploy', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $phpFiles[realpath($p)] = true; }
}
$phpFiles = array_keys($phpFiles);

// ⛔ فقط **کامنت‌ها** حذف می‌شوند، نه رشته‌ها.
//
// ⚠ نسخه‌ی اول رشته‌های متنی را هم دور می‌ریخت و روی فایلِ **سالم**
//   قرمز شد: نگهبانِ واقعی در `auth.php` به شکلِ
//   `$user['password_hash'] === null` نوشته شده و با حذفِ رشته‌ها به
//   `$user[] === null` تبدیل می‌شد. کامنت‌ها منشأ هشدارِ الکی‌اند (همین
//   توضیح خودش هر دو نام را دارد)؛ رشته‌ها بخشِ واقعیِ کدند.
$noComments = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
};

// ⚠ `plan.php` استثناست چون **تعریف** است نه فراخوان: فهرستِ Pro-only
//   خودش آنجاست. `sms_login.php` هم تنها فراخوانِ مجاز است.
$gateHome = ['plan.php', 'sms_login.php'];

$badGate = [];
$badNull = [];
foreach ($phpFiles as $p) {
    $code = $noComments((string)@file_get_contents($p));
    $rel  = basename(dirname($p)) . '/' . basename($p);

    if (str_contains($code, 'planAllows(') && str_contains($code, "'sms_login'")
        && !in_array(basename($p), $gateHome, true)) {
        $badGate[] = $rel;
    }

    if (str_contains($code, 'password_verify(')
        && !str_contains($code, 'userHasPassword(')
        && !preg_match("/'password_hash'\]\s*(===|!==)\s*null/", $code)
        && !str_contains($code, '$needsPassword')
        && !str_contains($code, '$hasPassword')) {
        $badNull[] = $rel;
    }
}

T::bulk(count($phpFiles), $badGate,
    "⛔ فقط `SmsLogin::loginAllowedFor()` گیتِ 'sms_login' را می‌خواند");
T::bulk(count($phpFiles), $badNull,
    '⛔ هر فایلی که `password_verify()` دارد، حسابِ بی‌رمز را هم می‌سنجد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۹ — موجودیِ حساب هرگز کش نمی‌شود.
//
// یک بار کشِ درخواستی روی `walletBalances()` گذاشته شد تا فراخوانیِ
// دوباره‌ی صفحه‌ی خانه ارزان شود. **پنج مجموعه‌ی تست همان‌جا قرمز شدند**:
// هر مسیری که پول می‌نویسد و بعد موجودی می‌خواند عددِ **پیش از** تغییر
// را می‌گرفت — نه خطایی، نه استثنایی، فقط یک عددِ غلط در پاسخ.
//
// راهِ درستِ ارزان کردنش این است که فراخواننده نتیجه را **یک بار بگیرد و
// پاس بدهد** (پارامترِ `$rows` در `totalBalance()` / `pinnedWallets()` و
// `$walletRows` در `safeToSpend()` / `financialHighlights()`)، نه اینکه
// حقیقتِ پول در حافظه بماند.
//
// ⚠ وسوسه‌ی برگرداندنش واقعی است، چون «فقط یک کوئری» به نظر می‌رسد. این
//   قاعده همان را می‌بندد تا تصمیم آگاهانه باشد، نه تصادفی.
T::group('قاعده ۲۹ — موجودیِ حساب کش نمی‌شود');

$balSrc  = php_strip_whitespace(__DIR__ . '/../includes/functions.php');
$badCache = [];

// ⚠ بدنه تا **تعریفِ تابعِ بعدی** بریده می‌شود، نه تا اولین `}`ی که
//   در متن پیدا شود: هر `if`ِ داخلِ تابع یکی از آن‌ها دارد، و نسخه‌ی اولِ
//   همین بررسی به همین دلیل تا نصفِ فایل جلو رفت و `static`ِ تابعِ
//   **دیگری** را پیدا کرد — یعنی روی فایلِ سالم قرمز شد.
$fnStart = strpos($balSrc, 'function walletBalances(');
if ($fnStart !== false) {
    $next = strpos($balSrc, 'function ', $fnStart + 10);
    $body = substr($balSrc, $fnStart, $next === false ? null : $next - $fnStart);

    if (preg_match('/\bstatic\s+\$/', $body)) {
        $badCache[] = 'walletBalances() یک `static` دارد — کشِ موجودی برگشته است';
    }
    if (preg_match('/\bwalletBalanceCache/i', $balSrc)) {
        $badCache[] = 'کشِ موجودی (walletBalanceCache…) دوباره ساخته شده است';
    }
} else {
    $badCache[] = 'بدنه‌ی walletBalances() پیدا نشد — قاعده ۲۹ کور شده';
}

// و راهِ ارزانش باید سرِ جایش بماند، وگرنه کسی که کش را برمی‌دارد
// جایگزینی هم ندارد و دوباره وسوسه می‌شود.
foreach ([
    'totalBalance'        => '/function\s+totalBalance\s*\(\s*int\s+\$userId\s*,\s*\?array\s+\$rows/',
    'pinnedWallets'       => '/function\s+pinnedWallets\s*\(\s*int\s+\$userId\s*,\s*\?array\s+\$rows/',
    'safeToSpend'         => '/function\s+safeToSpend\s*\([^)]*\?array\s+\$walletRows/',
    'financialHighlights' => '/function\s+financialHighlights\s*\([^)]*\?array\s+\$walletRows/',
] as $fn => $re) {
    if (!preg_match($re, $balSrc)) {
        $badCache[] = "{$fn}() دیگر ردیف‌های آماده را نمی‌پذیرد؛"
                    . ' بدونِ آن صفحه‌ی خانه سنگین‌ترین کوئری را دو بار می‌زند';
    }
}

T::bulk(5, $badCache, '⛔ موجودی کش نمی‌شود، بلکه یک بار خوانده و پاس داده می‌شود');

// ---------------------------------------------------------------
// ⛔ قاعده ۳۰ — ادعای «چیزی بیرون نمی‌رود» از پیکربندی خوانده می‌شود.
//
// `privacy.php` قرار است **هر ادعایش در کد نشان‌دادنی باشد**. یک بار
// همان صفحه با یک جمله‌ی ثابت می‌گفت «هیچ چیزی به سرویس بیرونی فرستاده
// نمی‌شود» — و بردنِ دامنه پشتِ کلادفلر آن را وارونه کرد: TLS روی لبه‌ی
// آن‌ها باز می‌شود، پس رمز و شماره کارتِ کاربر از دیدشان رد می‌شود.
// **هیچ خطایی هم نمی‌داد**؛ فقط صفحه‌ی حریم خصوصی دروغ می‌گفت، و
// `git pull` هم عوضش نمی‌کرد چون جمله ثابت بود.
//
// حالا `outboundDataFlows()` تنها جای این تصمیم است و از خودِ پیکربندی
// می‌خواند. سه چیز باید بسته بماند، و هر سه خرابیِ بی‌صدا می‌سازند:
//   ۱. صفحه باید واقعاً از همان تابع بخواند، نه از متنِ خودش.
//   ۲. ادعای مطلق فقط داخلِ شاخه‌ی «فهرست خالی است» گفته شود.
//   ۳. مصرف‌کننده باید `includes/sms.php` را لود کند — وگرنه
//      `class_exists('Sms')` نادرست می‌شود و قلمِ پیامک **بی‌صدا** از
//      فهرست می‌افتد، بی‌آنکه چیزی خطا بدهد.
T::group('قاعده ۳۰ — حریم خصوصی از روی پیکربندی');

$badPriv  = [];
$fnSrc    = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
$privPath = __DIR__ . '/../privacy.php';
$privRaw  = (string)@file_get_contents($privPath);

// تنها جای تعریف — نسخه‌ی دوم یعنی صفحه و واقعیت از هم دور می‌افتند.
foreach (['cdnInFront', 'outboundDataFlows'] as $fn) {
    $defs = 0;
    foreach (['api', 'api/v1', 'includes', 'admin', '.'] as $dir) {
        foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
            $defs += preg_match_all('/function\s+' . $fn . '\s*\(/', (string)@file_get_contents($p));
        }
    }
    if ($defs !== 1) {
        $badPriv[] = "{$fn}() باید دقیقاً یک بار تعریف شود (شمرده شد: {$defs})";
    }
}

// و باید هر سه منبع را بپرسد؛ افتادنِ یکی یعنی قلمی که واقعاً بیرون
// می‌رود در صفحه دیده نمی‌شود.
$flowStart = strpos($fnSrc, 'function outboundDataFlows(');
if ($flowStart === false) {
    $badPriv[] = 'outboundDataFlows() پیدا نشد — قاعده ۳۰ کور شده';
} else {
    $nextFn   = strpos($fnSrc, "\nfunction ", $flowStart + 10);
    $flowBody = substr($fnSrc, $flowStart, $nextFn === false ? null : $nextFn - $flowStart);
    foreach ([
        'cdnInFront('  => 'CDN',
        'MAIL_METHOD'  => 'ایمیل',
        'Sms::method(' => 'پیامک',
    ] as $needle => $label) {
        if (!str_contains($flowBody, $needle)) {
            $badPriv[] = "outboundDataFlows() دیگر «{$label}» را نمی‌سنجد؛"
                       . ' آن قلم بی‌صدا از صفحه‌ی حریم خصوصی می‌افتد';
        }
    }
}

// مصرف‌کننده‌ها: باید تابع را صدا بزنند و کلاسِ Sms را هم لود کنند.
$privConsumers = 0;
foreach (['api', 'includes', 'admin', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = (string)@file_get_contents($p);
        if (!str_contains($src, 'outboundDataFlows(')
            || preg_match('/function\s+outboundDataFlows\s*\(/', $src)) {
            continue;
        }
        $privConsumers++;
        if (!preg_match('#includes/sms\.php#', $src)) {
            $badPriv[] = basename($p) . ' — outboundDataFlows() را صدا می‌زند ولی'
                       . ' includes/sms.php را لود نمی‌کند؛ قلمِ پیامک بی‌صدا می‌افتد';
        }
    }
}
if ($privConsumers === 0) {
    $badPriv[] = 'هیچ صفحه‌ای outboundDataFlows() را صدا نمی‌زند —'
               . ' یعنی صفحه‌ی حریم خصوصی دوباره از متنِ ثابتِ خودش می‌گوید';
}

// ⚠ کامنت‌ها پیش از تجزیه خالی می‌شوند (با حفظِ شماره‌ی خط)، وگرنه
//   توضیحی که *بالای* همین شاخه نوشته شود تست را روی فایلِ سالم قرمز
//   می‌کند — همان دامی که قاعده‌های ۲۰ و ۲۴ هم برایش نوشته شدند.
$privLines = [];
if ($privRaw !== '') {
    $clean = '';
    foreach (token_get_all($privRaw) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            $clean .= str_repeat("\n", substr_count($t[1], "\n"));
        } else {
            $clean .= is_array($t) ? $t[1] : $t;
        }
    }
    $privLines = explode("\n", $clean);
}

$CLAIM   = 'هیچ چیزی به سرویس بیرونی';
$ifLine  = -1;
$elseLine = -1;
foreach ($privLines as $i => $line) {
    if ($ifLine < 0 && preg_match('/if\s*\(\s*\$flows\s*===\s*\[\s*\]\s*\)/', $line)) {
        $ifLine = $i;
    } elseif ($ifLine >= 0 && $elseLine < 0 && preg_match('/\belse\s*:/', $line)) {
        $elseLine = $i;
    }
}
if ($ifLine < 0 || $elseLine < 0) {
    $badPriv[] = 'privacy.php شاخه‌ی «فهرست خالی است» را ندارد؛'
               . ' ادعای مطلق آن‌وقت بی‌قید گفته می‌شود';
} else {
    foreach ($privLines as $i => $line) {
        if (!str_contains($line, $CLAIM)) { continue; }
        if ($i <= $ifLine || $i >= $elseLine) {
            $badPriv[] = 'privacy.php خطِ ' . ($i + 1) . ' — ادعای «چیزی بیرون'
                       . ' نمی‌رود» بیرونِ شاخه‌ی خالی بودنِ فهرست است';
        }
    }
    if (!preg_match('/foreach\s*\(\s*\$flows\s+as/', $clean)) {
        $badPriv[] = 'privacy.php فهرستِ خروجی‌ها را رندر نمی‌کند';
    }
}

T::bulk(6, $badPriv, '⛔ صفحه‌ی حریم خصوصی از پیکربندیِ واقعی می‌خواند');

// ---------------------------------------------------------------
// ⛔ قاعده ۳۱ — برچسبِ کنترل نمی‌شکند، و ستونِ «عملیات» له نمی‌شود.
//
// **خرابیِ واقعی، و فقط روی دسکتاپ دیده می‌شد:** در `admin/users.php`
// ستونِ عملیات روی ۱۱۵ پیکسل بسته می‌شد، چهار دکمه زیرِ هم می‌افتادند و
// ارتفاعِ هر ردیف از ۶۱ به **۲۱۱** پیکسل می‌رسید — با برچسب‌هایی مثل
// «غیرفعال‌ساز» که نصفه دیده می‌شدند. علتش دو چیزِ به‌هم‌بافته بود:
//   ۱. فارسی **نیم‌فاصله** دارد و مرورگر آن را جای شکستن می‌شناسد، پس
//      کمترین عرضِ لازمِ یک دکمه به یک تکه‌ی کوچک می‌رسید؛
//   ۲. ردیفِ دکمه‌ها `flex-wrap: wrap` بود، پس کمترین عرضِ لازمِ کلِ
//      ستون فقط **یک** دکمه بود و جدول باقیِ عرض را به ستون‌های دیگر داد.
// روی گوشی بی‌عیب بود (جدول آنجا کارتی می‌شود)، پس **«روی گوشی درست
// است» اینجا مدرک نبود** — همان درسِ منوی کناری، آینه‌وار.
//
// ⚠ هر سه تکه لازم‌اند: `nowrap` روی برچسب، `nowrap` روی ردیفِ دکمه‌ها
//   در دسکتاپ، و `width: 1%` روی خودِ ستون. با افتادنِ هرکدام ستون دوباره
//   له می‌شود و هیچ خطایی هم داده نمی‌شود.
T::group('قاعده ۳۱ — برچسبِ کنترل نمی‌شکند');

$badWrap = [];
$cssRaw  = (string)@file_get_contents(__DIR__ . '/../assets/css/style.css');
// ⚠ کامنت‌های CSS پیش از تجزیه حذف می‌شوند، وگرنه همین توضیح‌ها بخشی از
//   بلوکِ قاعده خوانده می‌شوند — همان دامی که قاعده ۲۰ در آن افتاد.
$css = preg_replace('#/\*.*?\*/#s', '', $cssRaw);

/** بدنه‌ی یک قاعده‌ی CSS را برمی‌گرداند (اولین تطابقِ دقیقِ انتخابگر). */
$ruleBody = function (string $haystack, string $selector) {
    if (!preg_match('/(^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $haystack, $m)) {
        return null;
    }
    return $m[2];
};

foreach (['.btn', '.delete-btn'] as $sel) {
    $body = $ruleBody($css, $sel);
    if ($body === null) {
        $badWrap[] = "قاعده‌ی {$sel} پیدا نشد — قاعده ۳۱ کور شده";
    } elseif (!preg_match('/white-space\s*:\s*nowrap/', $body)) {
        $badWrap[] = "{$sel} دیگر `white-space: nowrap` ندارد؛ برچسبِ فارسی روی"
                   . ' نیم‌فاصله می‌شکند و ستونِ جدول له می‌شود';
    }
}

$cellBody = $ruleBody($css, '.data-table th.actions-cell, .data-table td.actions-cell');
if ($cellBody === null || !preg_match('/width\s*:\s*1%/', $cellBody)) {
    $badWrap[] = 'ستونِ .actions-cell دیگر `width: 1%` ندارد؛ جدول عرضش را'
               . ' به ستون‌های دیگر می‌دهد';
}

// و نیمه‌ی دسکتاپ: ردیفِ دکمه‌ها آنجا نباید بشکند.
// ⚠ بیش از **یک** بلوکِ `min-width: 901px` در فایل هست، پس گرفتنِ اولی
//   کافی نیست: نسخه‌ی اولِ همین بررسی دقیقاً به این دلیل روی فایلِ
//   **سالم** قرمز شد. همه‌ی بلوک‌ها با هم سنجیده می‌شوند.
$desktop = '';
$off = 0;
while (preg_match('/@media\s*\(\s*min-width:\s*901px\s*\)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE, $off)) {
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $len = strlen($css);
    for ($i = $start; $i < $len && $depth > 0; $i++) {
        if ($css[$i] === '{') { $depth++; }
        elseif ($css[$i] === '}') { $depth--; }
    }
    $desktop .= substr($css, $start, $i - $start - 1);
    $off = $i;
}
if ($desktop === '') {
    $badWrap[] = 'بلوکِ @media (min-width: 901px) پیدا نشد — قاعده ۳۱ کور شده';
} elseif (!preg_match('/\.table-actions\s*\{[^}]*flex-wrap\s*:\s*nowrap/', $desktop)) {
    $badWrap[] = '`.table-actions { flex-wrap: nowrap }` در بلوکِ دسکتاپ نیست؛'
               . ' بدونش `width: 1%` بی‌اثر است و دکمه‌ها دوباره زیرِ هم می‌افتند';
}

// و هر صفحه‌ای که ردیفِ عملیات دارد باید ستونش را هم علامت زده باشد —
// در **هر دو** سر (`th` و `td`)، وگرنه فقط نیمی از رفع اعمال می‌شود.
$actionPages = 0;
foreach (['admin', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = (string)@file_get_contents($p);
        if (!str_contains($src, 'table-actions')) { continue; }
        $actionPages++;
        if (!preg_match('/<th[^>]*class="[^"]*actions-cell/', $src)) {
            $badWrap[] = basename($p) . ' — سرستونِ عملیات کلاسِ actions-cell ندارد';
        }
        if (!preg_match('/<td[^>]*class="[^"]*actions-cell/', $src)) {
            $badWrap[] = basename($p) . ' — سلولِ عملیات کلاسِ actions-cell ندارد';
        }
    }
}
if ($actionPages === 0) {
    $badWrap[] = 'هیچ صفحه‌ای `.table-actions` ندارد — یعنی ردیفِ دکمه‌ها دوباره'
               . ' با استایلِ درون‌خطی نوشته شده و از این قاعده بیرون است';
}

T::bulk(5 + $actionPages, $badWrap, '⛔ برچسبِ دکمه‌ها نمی‌شکند و ستونِ عملیات عرضِ خودش را دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۳۲ برداشته شد — انتخابگرِ خودمان پس گرفته شد
//
// آن قاعده چهار ستونِ `optionPicker()` را پین می‌کرد. خودِ آن تابع
// به خواستِ مالکِ نصب حذف شد (فرم‌ها روی گوشی شلوغ شده بودند)، پس
// قاعده دیگر چیزی برای سنجیدن نداشت. **قاعده‌ای که موضوعش رفته
// باید برداشته شود، نه خالی نگه داشته شود** — بررسیِ همیشه‌سبز همان
// «سنجشی که روی خرابی سبز می‌شود» است. تاریخچه‌اش در گیت هست.
//
// دو خطِ CSS که جهتِ خودِ کنترلِ بسته را راست‌به‌چپ می‌کند
// (`select, select option`) سرِ جایش ماند، چون هزینه‌اش صفر است.
$badSelDir = [];
if (!preg_match('/select\s*,\s*select\s+option\s*\{[^}]*direction:\s*rtl/s', $css)) {
    $badSelDir[] = '⛔ `select, select option { direction: rtl }` باید بماند: جهتِ'
                 . ' خودِ کنترلِ بسته را همین می‌بندد، و هزینه‌اش صفر است';
}
if (strpos($appJs, 'opt-pop') !== false) {
    $badSelDir[] = '⛔ `optionPicker()` پس گرفته شده و نباید بی‌سروصدا برگردد:'
                 . ' مالکِ نصب دو بار آن را رد کرده (شیتِ کف، و پاپ‌آپِ چسبیده به فیلد)';
}
T::group('⛔ جهتِ <select> از CSS می‌آید');
T::bulk(2, $badSelDir, '⛔ جهتِ <select> از CSS می‌آید و جعبهٔ خودمان برنمی‌گردد');

// ⛔ قاعده ۳۳ — کیبورد که باز است، نوارِ پایین کنار می‌رود
//
// خرابیِ گزارش‌شده: «گاهی منوی پایین می‌رود وسطِ صفحه». علتش CSS نیست،
// کیبوردِ مجازی است: `position: fixed` نسبت به viewport **چیدمان** جای
// می‌گیرد و کیبورد آن را کوچک نمی‌کند (فقط viewport دیداری کوچک می‌شود
// و صفحه اسکرول می‌شود تا فیلد دیده شود). پس کفِ چیدمان جایی وسطِ
// صفحه‌ی دیداری می‌افتد و نوار همان‌جا شناور می‌ماند. در مرورگرِ دسکتاپ
// اصلاً بازتولید نمی‌شود — همان درسِ حاشیه‌ی امن و منوی کناری.
//
// چهار ستونش پین می‌شود، چون افتادنِ هرکدام **بی‌صداست**:
//   ۱. خودِ قاعده‌ی CSS.
//   ۲. `focusin` — مرورگری که `visualViewport` ندارد باید هم کار کند،
//      و روی گوشی کیبورد چند صد میلی‌ثانیه بالا می‌آید: در همان فاصله
//      نوار باید رفته باشد، وگرنه دقیقاً همان باگ دیده می‌شود.
//   ۳. صافیِ نوعِ فیلد — چک‌باکس و دکمه و `type="color"` فوکوس می‌گیرند
//      بدونِ کیبورد؛ بدونِ صافی، تپ روی هر کلیدِ `.switch` نوار را
//      ناپدید می‌کرد.
//   ۴. تصحیح با `visualViewport` — با کیبوردِ سخت‌افزاری (آیپد، دسکتاپ)
//      هیچ چیزی کوچک نمی‌شود، پس نوار باید برگردد.
T::group('قاعده ۳۳ — نوارِ پایین و کیبوردِ مجازی');

$badKb = [];

if (!preg_match('/html\.kb-open\s+\.bottom-nav\s*\{[^}]*display\s*:\s*none/', $css)) {
    $badKb[] = 'قاعده‌ی `html.kb-open .bottom-nav { display: none }` در style.css نیست —'
             . ' نوار با کیبوردِ باز وسطِ صفحه شناور می‌ماند';
}

// ⚠ `$jsSrc` را قبلاً **قاعده ۳۲** تعریف می‌کرد و این قاعده بی‌سروصدا از
//    آن قرض می‌گرفت. با برداشتنِ قاعده ۳۲، متغیر تهی شد و هر پنج بررسیِ
//    اینجا روی فایلِ **سالم** قرمز شدند. قاعده‌ای که به همسایه‌اش بند
//    باشد، روزی که همسایه برود بی‌صدا چیزِ دیگری را می‌سنجد — پس هر
//    قاعده منبعِ خودش را می‌خواند.
$jsSrc    = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
$kbAnchor = strpos($jsSrc, '(function keyboardAwareNav() {');
if ($kbAnchor === false) {
    $badKb[] = 'تابعِ `keyboardAwareNav()` در app.js نیست — هیچ‌چیز کلاسِ `kb-open` را نمی‌گذارد';
} else {
    $tail  = substr($jsSrc, $kbAnchor);
    $tail  = preg_replace('#//[^\n]*#', '', $tail);
    $tail  = preg_replace('#/\*.*?\*/#s', '', $tail);
    $depth = 0; $end = null;
    for ($i = 0, $n = strlen($tail); $i < $n; $i++) {
        if ($tail[$i] === '{') { $depth++; }
        elseif ($tail[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $kbBody = $end === null ? $tail : substr($tail, 0, $end);

    if (!preg_match('/addEventListener\(\s*[\'"]focusin[\'"]/', $kbBody)) {
        $badKb[] = 'نوار باید با `focusin` پنهان شود، نه فقط با `visualViewport`:'
                 . ' کیبورد چند صد میلی‌ثانیه بالا می‌آید و مرورگرِ بدونِ آن API هم هست';
    }
    if (!preg_match('/visualViewport/', $kbBody)) {
        $badKb[] = '`visualViewport` باید تصمیمِ فوکوس را تصحیح کند، وگرنه با کیبوردِ'
                 . ' سخت‌افزاری نوار بی‌دلیل پنهان می‌ماند';
    }
    // ⛔ صافیِ نوع باید از `window.kbNeedsKeyboard` بیاید، نه یک کپیِ
    //    محلی: *رفتارش* در `tests/test_keyboard_nav.php` با node سنجیده
    //    می‌شود و با کپیِ دوم، آن تست چیزی را می‌آزماید که اجرا نمی‌شود.
    //    ⚠ نسخه‌ی اولِ همین بررسی دنبالِ رشته‌های `checkbox`/`color` داخلِ
    //    همین بدنه بود و جهشِ «همیشه true» از زیرش رد شد — فهرست سرِ
    //    جایش می‌ماند و فقط دیگر خوانده نمی‌شد.
    if (strpos($kbBody, 'window.kbNeedsKeyboard') === false) {
        $badKb[] = 'صافیِ نوعِ فیلد باید از `window.kbNeedsKeyboard()` بیاید — تنها'
                 . ' جای این تصمیم، و تنها چیزی که در node آزمودنی است';
    }
}

// و خودِ تابع باید **بیرون از** `DOMContentLoaded` باشد، وگرنه در node
// تعریف نمی‌شود و تستِ رفتاری بی‌صدا از پوشش می‌افتد — همان قاعده‌ی
// `parseBankSms()` و `smsAutoOk()`.
$domReady = strpos($jsSrc, "document.addEventListener('DOMContentLoaded'");
$kbDef    = strpos($jsSrc, 'window.kbNeedsKeyboard = function');
if ($kbDef === false) {
    $badKb[] = '`window.kbNeedsKeyboard` تعریف نشده است';
} elseif ($domReady !== false && $kbDef > $domReady) {
    $badKb[] = '`window.kbNeedsKeyboard` باید بیرون از `DOMContentLoaded` تعریف شود،'
             . ' وگرنه `tests/test_keyboard_nav.php` نمی‌تواند صدایش بزند';
}

T::bulk(5, $badKb, '⛔ نوارِ پایین با کیبوردِ باز کنار می‌رود');

// =====================================================================
// قاعده ۳۴ — تست‌رانر روی خرابیِ زیرساخت نباید سبز شود
// =====================================================================
/**
 * ⛔ چرا: یک بار MariaDB خوابیده بود و `bash tests/run.sh` نوشت
 *    «✅ هر ۴۲ مجموعه تست موفق بود» در حالی که حدود ۲۵ مجموعه اصلاً به
 *    دیتابیس نرسیده بودند. **دو** چیز با هم این را می‌ساختند:
 *    ۱. `Database::getConnection()` در شکست `die()` می‌کرد و `die()` کدِ
 *       خروجِ **صفر** دارد — پس `try/catch`ِ همه‌ی آن تست‌ها کدِ مرده بود.
 *    ۲. `T::skip()` هیچ شمارنده‌ای را بالا نمی‌برد و `report()` صفر
 *       برمی‌گرداند، پس «اجرا نشد» از «موفق شد» جدا نبود.
 *    هر دو نیمه اینجا پین می‌شوند، وگرنه برگشتِ هرکدام دوباره همان
 *    «سنجشی که روی خرابی سبز می‌شود» را می‌سازد.
 */
T::group('قاعده ۳۴ — «اجرا نشد» با «موفق شد» یکی نیست');

$badRun = [];

$assertSrc = (string)file_get_contents(__DIR__ . '/lib/assert.php');
$runSrc    = (string)file_get_contents(__DIR__ . '/run.sh');
$dbSrc     = (string)file_get_contents(__DIR__ . '/../includes/db.php');

// ۱. `T::blocked()` وجود دارد و ردش را نگه می‌دارد
if (!preg_match('/function\s+blocked\s*\(/', $assertSrc)
    || strpos($assertSrc, 'self::$blocked[]') === false) {
    $badRun[] = 'tests/lib/assert.php — `T::blocked()` باید وجود داشته باشد و مورد را ثبت کند';
}

// ۲. و `report()` برایش کدِ ناصفر برمی‌گرداند
if (strpos($assertSrc, 'return self::EXIT_BLOCKED;') === false
    || !preg_match('/EXIT_BLOCKED\s*=\s*([1-9])/', $assertSrc)) {
    $badRun[] = 'tests/lib/assert.php — `report()` برای موردِ بلوک‌شده باید کدِ ناصفر بدهد';
}

// ۳. `run.sh` آن کد را می‌شناسد و با ناصفر تمام می‌شود
if (strpos($runSrc, 'BLOCKED') === false || !preg_match('/^\s*2\)\s*BLOCKED/m', $runSrc)) {
    $badRun[] = 'tests/run.sh — کدِ خروجِ ۲ باید جدا از شکست شمرده شود';
}
if (!preg_match('/BLOCKED\[@\]\}\s*>\s*0\s*\)\)(?s:.{0,900}?)exit 1/', $runSrc)) {
    $badRun[] = 'tests/run.sh — وجودِ مجموعه‌ی اجرانشده باید کدِ خروجِ ناصفر بدهد';
}

// ۴. ⛔ نیمه‌ی دوم: اتصالِ ناموفق روی CLI باید **پرتاب** شود، نه `die()`.
//    بدونِ این، بند ۱ تا ۳ بی‌اثرند چون پروسه پیش از رسیدن به
//    `T::blocked()` با کدِ صفر می‌میرد.
if (!preg_match('/PHP_SAPI\s*===\s*[\'"]cli[\'"](?s:.{0,200}?)throw\s+\$e/', $dbSrc)) {
    $badRun[] = 'includes/db.php — شکستِ اتصال روی خط فرمان باید استثنا پرتاب کند، نه die()';
}

// ۵. و هیچ تستی نباید علتِ **زیرساختی** را ردِ نرم بشمارد
foreach (glob(__DIR__ . '/test_*.php') as $tf) {
    $src = (string)file_get_contents($tf);
    foreach (explode("\n", $src) as $n => $line) {
        if (strpos($line, 'T::skip(') === false) { continue; }
        if (strpos($line, 'اتصال به دیتابیس برقرار نشد') !== false
            || strpos($line, 'config/config.php وجود ندارد') !== false) {
            $badRun[] = basename($tf) . ':' . ($n + 1) . ' — باید `T::blocked()` باشد نه `T::skip()`';
        }
    }
}

T::bulk(5, $badRun, '⛔ خرابیِ زیرساخت، اجرا را سبز نمی‌کند');

// =====================================================================
// قاعده ۳۵ — سیاستِ CSP در دو جا نوشته شده و باید یکی بماند
// =====================================================================
/**
 * ⛔ `deploy/vps-setup.sh` سیاست را برای نصبِ **تازه** می‌نویسد و
 *    `deploy/nginx-csp.sh` همان را روی نصبِ **موجود** می‌گذارد. اگر یکی
 *    عوض شود و دیگری نه، دو نصب دو رفتارِ متفاوت می‌گیرند — و آن تفاوت
 *    فقط وقتی دیده می‌شود که صفحه‌ای روی یکی از آن دو بشکند، یعنی
 *    دیرترین و بدترین لحظه‌ی ممکن.
 */
T::group('قاعده ۳۵ — سیاستِ CSP یکی است');

$badCsp = [];

/**
 * ⚠ دو فایل سیاست را به دو **شکل** نگه می‌دارند و الگوی استخراج هم باید
 *   دو تا باشد: در `vps-setup.sh` سیاست مستقیم داخلِ `add_header` است،
 *   در `nginx-csp.sh` یک متغیرِ `CSP=` است (چون همان‌جا هم چاپ می‌شود هم
 *   نوشته). نسخه‌ی اولِ این قاعده هر دو را با یک الگو می‌خواند و از
 *   دومی مقدارِ `%s`ِ `printf` را برداشت — یعنی **روی فایلِ سالم قرمز
 *   شد**، همان اشتباهی که سرِ کامنت‌های CSS در قاعده ۲۰ هم شد.
 */
$src = (string)@file_get_contents(__DIR__ . '/../deploy/vps-setup.sh');
$cspSetup = preg_match('/add_header Content-Security-Policy\s+"([^"]+)"/', $src, $m)
    ? $m[1] : null;

$src = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-csp.sh');
$cspApply = preg_match('/^CSP="([^"]+)"/m', $src, $m) ? $m[1] : null;

if ($cspSetup === null) { $badCsp[] = 'deploy/vps-setup.sh — هدر CSP ندارد'; }
if ($cspApply === null) { $badCsp[] = 'deploy/nginx-csp.sh — سیاستِ CSP ندارد'; }

if ($cspSetup !== null && $cspApply !== null && $cspSetup !== $cspApply) {
    $badCsp[] = 'سیاستِ دو فایل یکی نیست — نصبِ تازه و نصبِ موجود دو رفتار می‌گیرند';
}

// و سه دستوری که بدونِ آن‌ها این هدر بیشتر تزئین است تا محافظ.
foreach (['object-src', 'base-uri', 'form-action', 'frame-ancestors'] as $need) {
    if ($cspSetup !== null && strpos($cspSetup, $need) === false) {
        $badCsp[] = "سیاست `{$need}` ندارد";
    }
}

// ⛔ و سنجشِ بعد از اعمال اجباری است — «nginx -t سبز شد» چیزی را ثابت
//    نمی‌کند (همان درسِ `^~` در `nginx-api.sh`).
$applySrc = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-csp.sh');

/**
 * ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند، وگرنه این بررسی پوچ است:
 *   توضیحِ بالای همان بخش خودش کلمه‌ی `--resolve` را دارد، پس با
 *   برداشتنش از خودِ `curl` هم سبز می‌ماند. **جهشِ M3 دقیقاً همین را
 *   نشان داد و اول زنده ماند** — همان درسی که سرِ `PREF_LAST_WHY` در
 *   قاعده ۱۹ و `catch (Throwable` در قاعده ۱۲ هم تکرار شد.
 */
$applyCode = preg_replace('/^\s*#.*$/m', '', $applySrc) ?? $applySrc;
if (strpos($applyCode, '--resolve') === false) {
    $badCsp[] = 'deploy/nginx-csp.sh — سنجش باید با --resolve باشد، نه Host هدر';
}
if (preg_match('/-H\s+"Host:/i', $applyCode)) {
    $badCsp[] = 'deploy/nginx-csp.sh — سنجش با سرآیندِ Host است؛ روی چندسایتی پاسخِ سایتِ دیگر را می‌گیرد';
}
if (!preg_match('/ok\s*-ne\s*1(?s:.{0,500}?)cp -a "\$BACKUP"/', $applySrc)) {
    $badCsp[] = 'deploy/nginx-csp.sh — اگر سنجش رد شد باید پیکربندی را برگرداند';
}

T::bulk(8, $badCsp, '⛔ CSP در هر دو مسیر یکی و سنجیده است');

// =====================================================================
// قاعده ۳۶ — بک‌تیکِ فرارنداده داخلِ رشته‌ی دابل‌کوتِ bash
// =====================================================================
/**
 * ⛔ خرابیِ واقعی، و روی سرور دیده شد نه در بازبینی. `nginx-csp.sh` یک
 *    پیامِ هشدار داشت که برای زیبایی نامِ یک دستورِ CSP را داخلِ بک‌تیک
 *    گذاشته بود:
 *
 *        warn "… سیاست [بک‌تیک]'unsafe-inline'[بک‌تیک] دارد …"
 *
 *    داخلِ `"…"` بک‌تیک **جانشینیِ فرمان** است، نه یک کاراکترِ معمولی.
 *    پس bash سعی کرد `'unsafe-inline'` را **اجرا** کند و وسطِ خروجیِ
 *    `--apply` نوشت `line 170: unsafe-inline: command not found` — و
 *    همان جمله را با یک **حفره** چاپ کرد، یعنی دقیقاً آن جمله‌ای که
 *    «حدِ صادقانه»ی این محافظ را می‌گوید نصفه به مالکِ نصب رسید.
 *
 * ⚠ اینجا شانس آوردیم که آن رشته فرمانی نبود. همان شکل با یک نامِ
 *   فرمانِ واقعی (یا با `$(…)` که هم‌معناست) **اجرا می‌شد** — داخلِ
 *   اسکریپتی که با `sudo` اجرا می‌شود و پیکربندیِ nginx را می‌نویسد.
 *   همان جنسِ خطر که قاعده ۱۵ برای تگِ پایانِ PHP دارد.
 *
 * ⛔ بررسی با **پیمایشِ کاراکتری** است نه grep: بک‌تیک سه جای کاملاً
 *    بی‌خطر هم هست و هیچ‌کدام نباید قرمز شوند (وگرنه هشدارِ الکی روی
 *    فایلِ سالم، که از نبودِ تست بدتر است):
 *      ۱. `\[بک‌تیک]` ی که فرار داده شده (نامِ جدول در SQLِ `migrate.sh`)
 *      ۲. داخلِ رشته‌ی **تک‌کوتی** (`printf` در `nginx-realip.sh`)
 *      ۳. داخلِ کامنت — که در این مخزن **ده‌ها** بار تکرار شده
 *    پس وضعیتِ کوتیشن، فرار، کامنت و heredoc همه دنبال می‌شوند.
 */
T::group('قاعده ۳۶ — بک‌تیک داخلِ رشته‌ی دابل‌کوتِ bash');

/**
 * وضعیت بینِ خط‌ها حمل می‌شود، چون رشته‌ی دابل‌کوت می‌تواند چندخطی باشد.
 * heredoc کامل رد می‌شود: محتوایش داده است، نه کدِ همین اسکریپت.
 */
$shBacktickHits = static function (string $src): array {
    $hits = [];
    $lines = explode("\n", $src);
    $state = 0;                 // 0 = بیرونِ کوتیشن، 1 = تک‌کوتیشن، 2 = دابل‌کوت
    $heredoc = null;
    foreach ($lines as $i => $line) {
        if ($heredoc !== null) {
            if (trim($line) === $heredoc) { $heredoc = null; }
            continue;
        }
        $len = strlen($line);
        for ($p = 0; $p < $len; $p++) {
            $c = $line[$p];
            if ($state === 1) {                       // تک‌کوتیشن: هیچ چیزی معنا ندارد
                if ($c === "'") { $state = 0; }
                continue;
            }
            if ($state === 2) {                       // دابل‌کوت: اینجا بک‌تیک خطرناک است
                if ($c === '\\') { $p++; continue; }  // فرارداده‌شده، بی‌خطر
                if ($c === '"')  { $state = 0; continue; }
                if ($c === '`')  { $hits[] = $i + 1; }
                continue;
            }
            if ($c === '\\') { $p++; continue; }
            if ($c === "'")  { $state = 1; continue; }
            if ($c === '"')  { $state = 2; continue; }
            if ($c === '#' && ($p === 0 || $line[$p - 1] === ' ' || $line[$p - 1] === "\t")) {
                break;                                 // بقیه‌ی خط کامنت است
            }
            if ($c === '<' && isset($line[$p + 1]) && $line[$p + 1] === '<'
                && preg_match('/^<<-?\s*[\'"]?([A-Za-z_][A-Za-z0-9_]*)[\'"]?/', substr($line, $p), $m)) {
                $heredoc = $m[1];
                break;
            }
        }
    }
    return $hits;
};

$badTick = [];
// ⚠ `hesabland` پسوند ندارد (یک فرمان است، نه یک اسکریپتِ کمکی)، پس
//    هیچ‌کدام از الگوهای `*.sh` آن را برنمی‌دارند و بی‌صدا بیرونِ پوشش
//    می‌ماند — همان درسِ globِ سخت‌کدِ قاعده ۱۹. صریح اضافه می‌شود، و
//    نبودنش هم خطاست (پایین‌تر شمرده می‌شود).
$shFiles = array_merge(
    glob(__DIR__ . '/../deploy/*.sh') ?: [],
    glob(__DIR__ . '/../*.sh') ?: [],
    glob(__DIR__ . '/*.sh') ?: [],
    array_filter([__DIR__ . '/../hesabland'], 'is_file')
);
if (count($shFiles) < 5) {
    $badTick[] = 'هیچ اسکریپتِ پوسته‌ای پیدا نشد — الگوی جست‌وجو خراب است';
}
if (!is_file(__DIR__ . '/../hesabland')) {
    $badTick[] = 'hesabland پیدا نشد — اگر نامش عوض شده، همین‌جا هم به‌روز شود'
               . ' وگرنه بی‌صدا بیرونِ پوششِ این قاعده می‌ماند';
}
foreach ($shFiles as $sh) {
    foreach ($shBacktickHits((string)@file_get_contents($sh)) as $ln) {
        $badTick[] = basename($sh) . ':' . $ln
            . ' — بک‌تیکِ فرارنداده داخلِ "…"؛ bash آن را اجرا می‌کند. «…» بنویسید یا \\` کنید';
    }
}

T::bulk(count($shFiles), $badTick, '⛔ هیچ بک‌تیکِ فرارنداده‌ای داخلِ رشته‌ی دابل‌کوت نیست');

// ---------------------------------------------------------------------
// قاعده ۳۷ — تاگلِ نوع: دکمه‌ی فعال با مقدارِ ارسالی یکی باشد
//
// ⛔ چرا **اینجا** و نه در تستِ رفتاری: `setupTypeToggle()` هنگام
//    بارگذاری `activate(initialBtn)` را صدا می‌زند و مقدارِ فیلدِ پنهان
//    را از روی دکمه‌ی فعال **بازنویسی** می‌کند. پس در مرورگر این
//    ناهماهنگی خودبه‌خود ترمیم می‌شود و هیچ تستِ رفتاری‌ای نمی‌بیندش —
//    جهشش در `test_tap_budget` **زنده ماند** و همین نشان داد قاعده‌ی
//    شکل لازم است.
//    ولی بی‌ضرر نیست: اگر `app.js` نرسد (همان حالتی که `js-loading`
//    برایش ساخته شد — یک درخواستِ گیرکرده روی دیتای موبایل)، فرم با
//    مقدارِ خامِ HTML ارسال می‌شود در حالی که کاربر دکمه‌ی دیگری را
//    فعال می‌بیند: تراکنش **برعکس** ثبت می‌شود، بی‌هیچ خطایی.
//
// ⛔ و پیش‌فرضِ خودِ شیتِ ثبت باید «هزینه» بماند: روی همین دیتابیس
//    ۲۹٬۲۲۴ هزینه در برابر ۱۰٬۷۷۹ درآمد شمرده شد.
// ---------------------------------------------------------------------
T::group('قاعده ۳۷ — تاگلِ نوع و مقدارِ ارسالی');

$badToggle = [];
$toggleFiles = glob(__DIR__ . '/../includes/*.php') ?: [];
$toggleSeen = 0;

foreach ($toggleFiles as $f) {
    $src = (string)@file_get_contents($f);
    // ⚠ شرطِ ورود «`data-type="expense"` دارد» است، نه `class="type-toggle"`:
    //   `wallet_card_modals.php` هم آن کلاس را برای چیزِ دیگری دارد و
    //   نسخه‌ی اولِ این قاعده روی فایلِ **سالم** قرمز شد — همان
    //   «هشدارِ الکی از نبودِ تست بدتر است».
    if (strpos($src, 'data-type="expense"') === false) { continue; }
    $toggleSeen++;
    $base = basename($f);

    if (!preg_match('/class="type-btn[^"]*\bactive\b[^"]*"\s+data-type="(income|expense)"/', $src, $m)
        && !preg_match('/data-type="(income|expense)"[^>]*class="type-btn[^"]*\bactive\b/', $src, $m)) {
        $badToggle[] = "{$base} — دکمه‌ی `active` پیدا نشد";
        continue;
    }
    $activeType = $m[1];

    if (!preg_match('/<input[^>]*name="type"[^>]*value="(income|expense)"/', $src, $v)) {
        $badToggle[] = "{$base} — فیلدِ پنهانِ `name=\"type\"` با مقدار پیدا نشد";
        continue;
    }
    if ($v[1] !== $activeType) {
        $badToggle[] = "{$base} — دکمه‌ی فعال «{$activeType}» است ولی مقدارِ ارسالی «{$v[1]}»";
    }
    if ($base === 'add_tx_sheet.php' && $activeType !== 'expense') {
        $badToggle[] = "{$base} — پیش‌فرضِ شیتِ ثبت باید «هزینه» باشد، نه «{$activeType}»";
    }
}

if ($toggleSeen < 2) {
    $badToggle[] = 'کمتر از دو تاگلِ نوع پیدا شد — الگوی جست‌وجو خراب است';
}
T::bulk($toggleSeen, $badToggle, '⛔ دکمه‌ی فعالِ تاگل با مقدارِ ارسالی یکی است');

// ---------------------------------------------------------------------
// قاعده ۳۸ — شبکه‌ی دسته‌بندی و عنوانِ اختیاری
//
// ⛔ چهار تصمیم که خرابیِ هر کدام **بی‌صداست**:
//    ۱. نامِ دسته با `textContent` نوشته شود، نه `innerHTML` — نام را
//       خودِ کاربر می‌نویسد.
//    ۲. سقفِ چیپ‌ها از `CATEGORY_GRID_MAX`ِ سرور بیاید، نه یک عددِ
//       سخت‌کد در جاوااسکریپت (وگرنه دو مرجع، و یکی عقب می‌ماند).
//    ۳. چیپ مقدار را روی خودِ `<select>` بنویسد و `change` بدهد — تنها
//       چیزی که `optionPicker()` و `smsAutoOk()` و اعتبارسنجیِ فرم را
//       سرِ پا نگه می‌دارد.
//    ۴. عنوانِ خالی فقط از `fallbackTxTitle()` پر شود، و آن تابع
//       **بعد از** `txResolveCategory()` صدا زده شود — وگرنه نامِ
//       دسته‌ی کاربرِ دیگری داخلِ عنوانِ این کاربر می‌نشیند.
// ---------------------------------------------------------------------
T::group('قاعده ۳۸ — شبکه‌ی دسته‌بندی و عنوانِ اختیاری');

$badGrid = [];
$appJs = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
// کامنت‌ها پیش از بررسی حذف می‌شوند — همان درسِ قاعده ۳۵: توضیحِ کنارِ
// کد نباید بررسی را سبز نگه دارد.
$appNoC = preg_replace('!/\*.*?\*/!s', '', $appJs);
$appNoC = preg_replace('!^\s*//.*$!m', '', (string)$appNoC);

if (!preg_match('/function\s+renderCategoryGrid\s*\(/', (string)$appNoC)) {
    $badGrid[] = 'app.js — تابع renderCategoryGrid پیدا نشد';
} else {
    $start = strpos((string)$appNoC, 'function renderCategoryGrid');
    $end   = strpos((string)$appNoC, 'function setupTypeToggle');
    $body  = ($end !== false && $end > $start)
        ? substr((string)$appNoC, $start, $end - $start)
        : substr((string)$appNoC, $start, 3000);

    if (strpos($body, '.textContent = cat.name') === false) {
        $badGrid[] = 'app.js — نامِ دسته باید با textContent نوشته شود (نامش را کاربر می‌نویسد)';
    }
    if (preg_match('/innerHTML\s*=\s*cat\.name/', $body)) {
        $badGrid[] = 'app.js — نامِ دسته با innerHTML نوشته شده است';
    }
    if (strpos($body, 'window.CATEGORY_GRID_MAX') === false) {
        $badGrid[] = 'app.js — سقفِ چیپ‌ها باید از window.CATEGORY_GRID_MAX بیاید، نه عددِ سخت‌کد';
    }
    if (strpos($body, 'selectEl.value') === false) {
        $badGrid[] = 'app.js — چیپ باید مقدار را روی خودِ <select> بنویسد';
    }
    if (strpos($body, "new Event('change'") === false) {
        $badGrid[] = 'app.js — چیپ باید رویدادِ change بدهد، وگرنه هیچ مصرف‌کننده‌ای خبردار نمی‌شود';
    }
}

// ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند، وگرنه همین توضیحاتی که
//   کنارِ هر تصمیم نوشته شده‌اند خودشان بررسی را سبز نگه می‌دارند.
//   جهشِ «JSON_HEX_TAG را بردار» اول دقیقاً به همین دلیل **زنده ماند**:
//   نامش در کامنتِ بالای همان خط بود. همان دامِ قاعده ۳۵ و ۱۹.
$sheetSrc = $stripComments(__DIR__ . '/../includes/add_tx_sheet.php');
if (strpos($sheetSrc, 'CATEGORY_GRID_MAX') === false) {
    $badGrid[] = 'add_tx_sheet.php — سقف باید از ثابتِ سرور به مرورگر داده شود';
}
if (strpos($sheetSrc, 'JSON_HEX_TAG') === false) {
    $badGrid[] = 'add_tx_sheet.php — JSON_HEX_TAG لازم است: نامِ دسته داخلِ <script> می‌نشیند';
}
if (preg_match('/id="title"[^>]*\srequired/', $sheetSrc)) {
    $badGrid[] = 'add_tx_sheet.php — عنوان دوباره required شد؛ جایش fallbackTxTitle است';
}

// عنوانِ خالی: تنها مسیرِ پر شدنش، و ترتیبش نسبت به سنجشِ دسته
$txSrc = (string)@file_get_contents(__DIR__ . '/../includes/transactions.php');
foreach (['txCreate', 'txUpdate'] as $fn) {
    $s = strpos($txSrc, "function {$fn}(");
    if ($s === false) { $badGrid[] = "transactions.php — {$fn} پیدا نشد"; continue; }
    // تا تعریفِ تابعِ بعدی بریده می‌شود — همان درسِ قاعده ۲۹.
    $e = strpos($txSrc, "\nfunction ", $s + 10);
    $chunk = $e === false ? substr($txSrc, $s) : substr($txSrc, $s, $e - $s);

    $posCat   = strpos($chunk, 'txResolveCategory(');
    $posTitle = strpos($chunk, 'fallbackTxTitle(');
    if ($posTitle === false) {
        $badGrid[] = "transactions.php — {$fn} عنوانِ خالی را پر نمی‌کند";
    } elseif ($posCat === false || $posTitle < $posCat) {
        $badGrid[] = "transactions.php — {$fn} باید fallbackTxTitle را **بعد از** txResolveCategory صدا بزند";
    }
}
if (preg_match('/\$errors\[\]\s*=\s*.عنوان الزامی/u', $txSrc)) {
    $badGrid[] = 'transactions.php — «عنوان الزامی است» برگشت؛ عنوان دیگر اجباری نیست';
}

// ⛔ دو تصمیمِ تازهٔ همین ردیف، و افتادنِ هر دو **بی‌صداست**:
//   ۱. یک خطِ افقی، نه شبکهٔ چندردیفی (خواستهٔ صریحِ مالکِ نصب).
//   ۲. کدام دسته‌ها بیایند را **سرور** می‌گوید (`categoriesForGrid()`):
//      با `slice()`ِ محلی در مرورگر، پینی که کاربر در «فهرست‌های من»
//      زده نادیده می‌ماند و او فکر می‌کند دکمه کار نمی‌کند.
if (!preg_match('/\\.cat-grid\\s*\\{[^}]*flex-wrap:\\s*nowrap/s', $css)) {
    $badGrid[] = 'style.css — ردیفِ چیپ باید `flex-wrap: nowrap` باشد: با wrap هشت چیپ'
               . ' روی عرضِ گوشی سه ردیف می‌شد و همان شلوغی‌ای است که گزارش شد';
}
if (strpos($appJs, 'CATEGORY_GRID[') === false) {
    $badGrid[] = 'app.js — ردیفِ چیپ باید فهرستش را از `window.CATEGORY_GRID` بخواند،'
               . ' وگرنه انتخابِ کاربر در «فهرست‌های من» بی‌صدا نادیده می‌ماند';
}
if (strpos($sheetSrc, 'CATEGORY_GRID') === false
    || strpos($sheetSrc, 'categoriesForGrid(') === false) {
    $badGrid[] = 'add_tx_sheet.php — فهرستِ چیپ باید از `categoriesForGrid()` بیاید و به'
               . ' مرورگر داده شود؛ آن تنها جای این تصمیم است';
}

T::bulk(12, $badGrid, '⛔ شبکه‌ی دسته‌بندی و عنوانِ اختیاری سرِ جایشان‌اند');

// ---------------------------------------------------------------------
// قاعده ۳۹ — «آخرین استفاده» و آستانه‌ی «فعال»
//
// ⛔ **خرابیِ واقعی که این قاعده برایش نوشته شد:** `userActivity()` فقط
//    جدولِ `transactions` را می‌خواند، پس کاربری که چک و طلب ثبت کرده
//    بود روی صفحه‌ی مدیر «هرگز شروع نکرده» خوانده می‌شد. رفتارش را
//    `tests/test_admin_stats.php` می‌سنجد؛ اینجا **شکل** پین می‌شود تا
//    برنگردد:
//
//    ۱. فهرستِ جدول‌ها فقط یک جا تعریف شود (`ACTIVITY_TABLES`)، وگرنه
//       نسخه‌ی دومی می‌شود که دیر یا زود از این عقب می‌افتد.
//    ۲. فاصله‌ی روز را **دیتابیس** حساب کند (`DATEDIFF`)، نه PHP: با
//       `new DateTimeImmutable('today')` امروزِ PHP و `created_at`ِ
//       دیتابیس در پنجره‌ی بامدادی دو روزِ متفاوت می‌گفتند — همان درسِ
//       بخشِ «منطقه‌ی زمانی» و لینکِ بازیابیِ رمز.
//    ۳. آستانه‌ی «فعال» فقط `ACTIVE_DAYS` باشد؛ پیش از این عددِ ۱۴ در
//       سه جا سخت‌کد بود و عوض کردنِ یکی، دو تای دیگر را بی‌صدا
//       دروغ‌گو می‌کرد.
// ---------------------------------------------------------------------
T::group('قاعده ۳۹ — «آخرین استفاده» و آستانه‌ی «فعال»');

$badStat = [];
$aiPath  = __DIR__ . '/../includes/admin_insights.php';
// کامنت‌ها **پیش از** بررسی حذف می‌شوند: همین توضیحاتِ بالا نامِ
// `transactions` و عددِ ۱۴ را دارند و با سورسِ خام، بررسی روی فایلِ
// سالم هم قرمز (یا بدتر، بی‌جهت سبز) می‌شد — همان دامِ قاعده ۳۵ و ۳۸.
$aiSrc = $stripComments($aiPath);

// ⚠ هر دو شکلِ تعریف پذیرفته می‌شود. نسخه‌ی اول فقط `const` را قبول
//   می‌کرد و آن یک **هشدارِ الکی** بود: `define()` دقیقاً همان کار را
//   می‌کند و فایل خراب نیست. قاعده باید چیزی را ببندد که خراب است، نه
//   چیزی را که فقط شکلش فرق دارد.
if (strpos($aiSrc, 'const ACTIVE_DAYS') === false
    && strpos($aiSrc, "define('ACTIVE_DAYS'") === false) {
    $badStat[] = 'admin_insights.php — ثابتِ ACTIVE_DAYS تعریف نشده';
}
if (strpos($aiSrc, 'const ACTIVITY_TABLES') === false) {
    $badStat[] = 'admin_insights.php — فهرستِ ACTIVITY_TABLES تعریف نشده';
}
if (strpos($aiSrc, 'const NON_ACTIVITY_TABLES') === false) {
    $badStat[] = 'admin_insights.php — فهرستِ NON_ACTIVITY_TABLES تعریف نشده';
}

// بدنه‌ی `userActivity()` — تا **تعریفِ تابعِ بعدی** بریده می‌شود، نه تا
// اولین `}` (همان درسِ قاعده ۲۹).
$sU = strpos($aiSrc, 'function userActivity(');
if ($sU === false) {
    $badStat[] = 'admin_insights.php — userActivity پیدا نشد';
} else {
    $eU  = strpos($aiSrc, "\nfunction ", $sU + 10);
    $bod = $eU === false ? substr($aiSrc, $sU) : substr($aiSrc, $sU, $eU - $sU);

    if (strpos($bod, 'DATEDIFF(') === false) {
        $badStat[] = 'userActivity() — فاصله‌ی روز باید با DATEDIFF در دیتابیس حساب شود';
    }
    if (strpos($bod, 'DateTimeImmutable') !== false || strpos($bod, '->diff(') !== false) {
        $badStat[] = 'userActivity() — حسابِ تاریخ به PHP برگشت؛ روزِ PHP و روزِ دیتابیس یکی نیستند';
    }
    if (strpos($bod, 'ACTIVE_DAYS') === false) {
        $badStat[] = 'userActivity() — آستانه باید از ACTIVE_DAYS بیاید';
    }
    if (preg_match('/<=\s*14\b/', $bod)) {
        $badStat[] = 'userActivity() — عددِ ۱۴ دوباره سخت‌کد شد';
    }
    // ⛔ نامِ هیچ جدولی مستقیم داخلِ این تابع نباشد: فهرست فقط از
    //    `activityAggregateSql()` می‌آید که خودش `ACTIVITY_TABLES` را
    //    می‌خواند.
    foreach (['transactions', 'cheques', 'debts'] as $t) {
        if (strpos($bod, "`{$t}`") !== false || strpos($bod, "FROM {$t}") !== false) {
            $badStat[] = "userActivity() — نامِ جدولِ {$t} مستقیم نوشته شده؛ فهرست فقط ACTIVITY_TABLES است";
        }
    }
}

// فهرست فقط یک جا تعریف شود
foreach (glob(__DIR__ . '/../includes/*.php') as $p) {
    if (realpath($p) === realpath($aiPath)) { continue; }
    if (strpos((string)@file_get_contents($p), 'ACTIVITY_TABLES = [') !== false) {
        $badStat[] = basename($p) . ' — نسخه‌ی دومِ ACTIVITY_TABLES';
    }
}

// صفحه آستانه را از ثابت بخواند، نه عددِ خودش
$pgSrc = $stripComments(__DIR__ . '/../admin/insights.php');
if (strpos($pgSrc, 'ACTIVE_DAYS') === false) {
    $badStat[] = 'admin/insights.php — آستانه باید از ACTIVE_DAYS بیاید';
}
if (strpos($pgSrc, '۱۴ روز') !== false || preg_match('/بیش از\s*14\s*روز/u', $pgSrc)) {
    $badStat[] = 'admin/insights.php — عددِ آستانه سخت‌کد شد';
}

T::bulk(12, $badStat, '⛔ «آخرین استفاده» و آستانه‌ی «فعال» یک مرجع دارند');

// ---------------------------------------------------------------------
// قاعده ۴۰ — «شخصی‌سازیِ» دسته‌بندی، و نگهبانِ حذف
//
// ⛔ **خرابیِ واقعیِ بی‌صدا که این قاعده برایش نوشته شد:** نگهبانِ حذف
//    در `admin/categories.php` فقط `transactions` را می‌شمرد، در حالی
//    که `budgets.category_id` کلیدِ خارجی با `ON DELETE CASCADE` دارد.
//    یعنی حذفِ دسته‌ای که هیچ تراکنشی نداشت ولی رویش بودجه بسته شده
//    بود، **بودجه‌ی کاربران را هم با خودش می‌برد** — نه خطایی، نه
//    هشداری، و مدیر پیامِ «دسته‌بندی حذف شد» می‌گرفت.
//
//    رفتارش را `tests/test_category_privatize.php` می‌سنجد؛ اینجا
//    **شکل** پین می‌شود تا برنگردد:
//
//    ۱. نگهبانِ حذف از `categoryUsage()` رد شود، نه یک شمارشِ محلی روی
//       `transactions` — وگرنه جدولِ ارجاع‌دهنده‌ی فردا هم جا می‌ماند.
//    ۲. فهرستِ جدول‌های ارجاع‌دهنده از **خودِ دیتابیس** کشف شود
//       (`schemaMap()`)، نه یک آرایه‌ی دستی: اینجا فهرست باید **کامل**
//       باشد نه گزینشی — همان قاعده‌ی `userDataTables()`، و برعکسِ
//       `ACTIVITY_TABLES` که عمداً بسته است.
//    ۳. جابه‌جا کردنِ `category_id` از یک دسته به دسته‌ی دیگر فقط در
//       `privatizeDefaultCategory()` باشد. نسخه‌ی دومِ آن یعنی مسیری که
//       شرطِ `user_id` را فراموش می‌کند و ردیفِ یک کاربر را به دسته‌ی
//       کاربرِ دیگری می‌چسباند — همان نشتی‌ای که `categoryScopeSql()`
//       برای نبودنش نوشته شد.
// ---------------------------------------------------------------------
T::group('قاعده ۴۰ — شخصی‌سازیِ دسته‌بندی و نگهبانِ حذف');

$badPriv = [];
// ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند: همین توضیحاتِ بالا و
//   توضیحاتِ خودِ آن فایل‌ها نامِ `transactions` و `categoryUsage` را
//   دارند، پس با سورسِ خام بررسی روی فایلِ **سالم** هم جواب می‌داد —
//   همان دامِ قاعده ۳۵ و ۳۸.
$catAdmin = $stripComments(__DIR__ . '/../admin/categories.php');
$fnSrc    = $stripComments(__DIR__ . '/../includes/functions.php');

// ۱ — نگهبانِ حذف از categoryUsage() رد شود
if (strpos($catAdmin, 'categoryUsage(') === false) {
    $badPriv[] = 'admin/categories.php — نگهبانِ حذف باید از categoryUsage() رد شود';
}
if (preg_match('/FROM\s+transactions\s+WHERE\s+category_id/i', $catAdmin)) {
    $badPriv[] = 'admin/categories.php — شمارشِ محلی روی transactions برگشته؛ جدول‌های دیگر جا می‌مانند';
}

// ۲ — فهرستِ جدول‌های ارجاع‌دهنده کشف می‌شود، نه دستی
if (preg_match('/function\s+categoryRefTables\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n\}/s', $fnSrc, $m)) {
    $body = $m[1];
    if (strpos($body, 'schemaMap()') === false) {
        $badPriv[] = 'categoryRefTables() — باید از schemaMap() کشف کند، نه فهرستِ دستی';
    }
    foreach (['transactions', 'budgets', 'recurring_transactions', 'category_pins'] as $t) {
        if (strpos($body, "'{$t}'") !== false) {
            $badPriv[] = "categoryRefTables() — نامِ {$t} دستی نوشته شده؛ فهرست باید کامل و کشف‌شده باشد";
        }
    }
} else {
    $badPriv[] = 'categoryRefTables() پیدا نشد';
}

// ۳ — جابه‌جاییِ category_id فقط در privatizeDefaultCategory()
$movePat = '/SET\s+category_id\s*=\s*:\w+\s*\n?\s*WHERE\s+category_id/i';
foreach (['api', 'includes', 'admin', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = $stripComments($p);
        if (!preg_match($movePat, $src)) { continue; }
        if (basename($p) === 'functions.php') { continue; }
        $badPriv[] = basename($p) . ' — جابه‌جاییِ category_id فقط در privatizeDefaultCategory() مجاز است';
    }
}
// و همان‌جا هم شرطِ user_id روی UPDATE باشد
if (preg_match('/function\s+privatizeDefaultCategory\s*\(.*?\n\}/s', $fnSrc, $m)) {
    if (!preg_match('/SET\s+category_id\s*=\s*:new\s*\n?\s*WHERE\s+category_id\s*=\s*:old\s+AND\s+user_id\s*=\s*:u/i', $m[0])) {
        $badPriv[] = 'privatizeDefaultCategory() — شرطِ user_id روی UPDATE برداشته شده (نشتی بینِ کاربران)';
    }
    if (strpos($m[0], 'user_id IS NULL') === false) {
        $badPriv[] = 'privatizeDefaultCategory() — نگهبانِ «فقط دسته‌ی پیش‌فرض» برداشته شده';
    }
    // ⚠ نسخه‌ی اول فقط دنبالِ `rollBack()` می‌گشت و **پوچ بود**: بلوکِ
    //   `catch` خودش یکی دارد، پس با برداشتنِ کاملِ سد هم سبز می‌ماند و
    //   جهشش زنده ماند. حالا خودِ شرطِ سد سنجیده می‌شود.
    if (!preg_match('/if\s*\(\s*\$left\s*!==\s*0\s*\|\|\s*\$moved\s*!==\s*\$usage\[.rows.\]\s*\)/', $m[0])) {
        $badPriv[] = 'privatizeDefaultCategory() — سدِ شمارشِ پیش از commit برداشته شده';
    }
} else {
    $badPriv[] = 'privatizeDefaultCategory() پیدا نشد';
}

T::bulk(9, $badPriv, '⛔ شخصی‌سازی و حذفِ دسته‌بندی یک مرجع دارند');

$all = [];
foreach (['api', 'includes', 'admin', 'config', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $all[realpath($p)] = true; }
}
foreach (array_keys($all) as $p) {
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { $bad[] = basename($p) . ' — ' . implode(' ', $out); }
    $out = [];
}
T::bulk(count($all), $bad, 'همه‌ی فایل‌های PHP نحو درست دارند');

exit(T::report());
