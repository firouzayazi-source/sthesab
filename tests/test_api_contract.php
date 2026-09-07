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
$deploySrc = (string)@file_get_contents(__DIR__ . '/../deploy.sh');
if ($deploySrc === '') {
    T::skip('تست deploy.sh', 'deploy.sh وجود ندارد');
} else {
    $chownAt = strpos($deploySrc, 'chown -R root:root');
    T::ok($chownAt !== false, 'deploy.sh مالکیت کد را به root می‌دهد');

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
}

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
foreach (glob(__DIR__ . '/../mobile/app/src/main/java/ir/stland/daftar/*.java') as $j) {
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

$brand = 'دفتر مالی';
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

        // ⚠ الگوی مجازِ برگشتی: `defined('APP_NAME') ? APP_NAME : 'دفتر مالی'`
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
