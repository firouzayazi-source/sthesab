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
    'dashboard_stats.php',
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

    // درخواست ناوبری باید شاخه‌ی جدا داشته باشد و از شبکه بیاید
    T::ok(str_contains($sw, "req.mode === 'navigate'"), 'ناوبری شاخه‌ی جداگانه دارد');
    T::ok(str_contains($sw, 'isStaticAsset'), 'کش فقط به دارایی‌های ثابت محدود شده');
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
