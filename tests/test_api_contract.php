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
    $code = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../mobile',
            FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (preg_match('/\.(java|kt|dart)$/i', $f->getFilename())) {
            $code[] = $f->getFilename();
        }
    }
    T::bulk(1, $code, 'پوشه‌ی mobile/ کد برنامه ندارد (پوسته می‌ماند)');
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
T::group('نحو — هر فایل PHP باید بدون خطا پارس شود');

$bad = [];
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
