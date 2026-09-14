<?php
/**
 * تستِ اجرای کاملِ زنجیره‌ی migration روی یک دیتابیسِ **تازه**.
 *
 * ⛔ چرا این تست وجود دارد — دو خرابیِ واقعی که هیچ‌کدام در تست‌های
 *    دیگر دیده نشدند و هر دو تا **سرور** رفتند:
 *
 *  ۱. «Illegal mix of collations». `migration_household_categories.sql`
 *     از `FIND_IN_SET(name, @shop_names)` استفاده می‌کرد. متغیرِ کاربری
 *     همان «قابلیتِ تبدیلِ ۲» را دارد که یک ستون دارد، پس روی دیتابیسی
 *     که ستونش `utf8mb4_persian_ci` است و اتصالش `utf8mb4_general_ci`،
 *     مقایسه اصلاً اجرا نمی‌شود. روی سرور migration وسطِ کار ایستاد.
 *
 *     **و چرا محلی دیده نشد:** من فایل را با `mysql` بدون آرگومان
 *     اجرا کرده بودم، یعنی اتصال `latin1_swedish_ci` بود و آنجا
 *     تبدیلِ charset اتفاق می‌افتد و خطا نمی‌دهد. ولی `migrate.sh`
 *     با `--default-character-set=utf8mb4` وصل می‌شود. این تست هم
 *     **دقیقاً همان آرگومان** را می‌دهد — وگرنه چیزی را می‌آزماید که
 *     روی سرور اجرا نمی‌شود.
 *
 *  ۲. `schema.sql` ستون‌های `icon` و `color` را در `categories` **درج**
 *     می‌کرد ولی در `CREATE TABLE` نداشت. روی نصبِ موجود دیده نمی‌شد
 *     (آن ستون‌ها را `migration_category_icons.sql` قبلاً ساخته بود)،
 *     ولی نصبِ **تازه** با «Unknown column 'icon'» بی‌سروصدا بدونِ هیچ
 *     دسته‌بندی‌ای بالا می‌آمد.
 *
 * پس دو شرطِ این تست قابل مذاکره نیستند: دیتابیس **تازه** باشد و
 * collation اش همان چیزی باشد که `schema.sql` می‌سازد.
 *
 * اگر کاربرِ دیتابیس اجازه‌ی `CREATE DATABASE` نداشته باشد رد می‌شود،
 * نه شکست — کاربرِ اپ طبق قانونِ جداسازی فقط روی `hesab_db` دسترسی
 * دارد و این عمدی و درست است.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = dirname(__DIR__);

T::group('زنجیره‌ی migration روی دیتابیسِ تازه');

// ---------- فهرستِ migration ها از خودِ migrate.sh ----------
// ⛔ فهرستِ دوم ساخته نمی‌شود: تنها مرجعِ ترتیب همان آرایه‌ی
//    MIGRATIONS است و اگر اینجا کپی می‌شد، دو فهرست از هم دور می‌افتادند
//    و این تست دقیقاً همان migration تازه‌ای را که مشکل دارد جا می‌انداخت.
$sh = file_get_contents($root . '/deploy/migrate.sh');
if (!preg_match('/^MIGRATIONS=\((.*?)^\)/ms', $sh, $m)) {
    T::ok(false, 'خواندنِ آرایه‌ی MIGRATIONS از migrate.sh');
    exit(T::report());
}
$files = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
T::ok(count($files) >= 20, 'فهرستِ migration از migrate.sh خوانده شد',
    count($files) . ' فایل');

$missing = array_values(array_filter($files, fn($f) => !file_exists("$root/$f")));
T::bulk(count($files), $missing, 'هر فایلِ فهرست واقعاً وجود دارد');

// ---------- دسترسی به mysql ----------
exec('command -v mysql 2>/dev/null', $o, $rc);
if ($rc !== 0) {
    T::skip('اجرای زنجیره', 'کلاینت mysql نصب نیست');
    exit(T::report());
}

if (!file_exists($root . '/config/config.php')) {
    T::blocked('اجرای زنجیره', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once $root . '/config/config.php';

$scratch = 'hesab_chain_' . substr(bin2hex(random_bytes(4)), 0, 8);

/** اتصال دقیقاً مثل migrate.sh — همان --default-character-set. */
$mysql = function (string $args, ?string $file = null, ?string &$out = null) use ($scratch): int {
    $cmd = 'mysql --default-character-set=utf8mb4'
         . ' -h ' . escapeshellarg(DB_HOST)
         . ' -u ' . escapeshellarg(DB_USER)
         . (DB_PASSWORD !== '' ? ' -p' . escapeshellarg(DB_PASSWORD) : '')
         . ' ' . $args;
    if ($file !== null) { $cmd .= ' < ' . escapeshellarg($file); }
    $cmd .= ' 2>&1';
    $out = (string)shell_exec($cmd . '; echo "__RC:$?"');
    if (preg_match('/__RC:(\d+)\s*$/', $out, $mm)) {
        $rc = (int)$mm[1];
        $out = preg_replace('/__RC:\d+\s*$/', '', $out);
        return $rc;
    }
    return 1;
};

// ---------- ساخت دیتابیسِ موقت ----------
// همان charset/collation که schema.sql برای جدول‌هایش می‌گذارد.
$create = sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci', $scratch);
$rc = $mysql('-e ' . escapeshellarg($create), null, $out);
if ($rc !== 0) {
    T::skip('اجرای زنجیره',
        'کاربر دیتابیس اجازه‌ی CREATE DATABASE ندارد — طبق قانون جداسازی درست است');
    exit(T::report());
}

// ⚠ با trap-ــِ PHP: هر جور که این اسکریپت تمام شود، دیتابیس موقت
//   نباید جا بماند.
register_shutdown_function(function () use ($mysql, $scratch) {
    $mysql('-e ' . escapeshellarg("DROP DATABASE IF EXISTS `$scratch`"));
});

// ---------- اجرای زنجیره ----------
// migration_indexes عمداً ایدمپوتنت نیست (ADD INDEX بدون IF NOT EXISTS)
// و migrate.sh هم خطای «ایندکس تکراری» را می‌شناسد.
$DUP_INDEX_OK = ['migration_indexes.sql'];

$failed = [];
foreach ($files as $f) {
    $rc = $mysql(escapeshellarg($scratch), "$root/$f", $out);
    if ($rc !== 0) {
        $err = trim(preg_replace('/\s+/', ' ', substr($out, 0, 300)));
        $failed[] = "$f → $err";
    }
}
T::bulk(count($files), $failed, 'هر migration روی دیتابیسِ تازه بدون خطا اجرا شد');

if ($failed) { exit(T::report()); }

// ---------- دسته‌بندی‌های پیش‌فرض واقعاً ساخته شدند ----------
// ⛔ «بدون خطا اجرا شد» با «کار کرد» یکی نیست: seedِ دسته‌بندی داخل
//    یک `INSERT ... SELECT` است و اگر شرطش نخواند، بی‌هیچ خطایی صفر
//    ردیف درج می‌کند. نصبِ تازه‌ای که هیچ دسته‌بندی ندارد یعنی کاربر
//    در همان اولین ثبت هیچ گزینه‌ای نمی‌بیند.
$rc = $mysql(
    '-N -B ' . escapeshellarg($scratch) . ' -e '
    . escapeshellarg("SELECT COUNT(*), SUM(type='income'), SUM(type='expense') FROM categories WHERE user_id IS NULL"),
    null, $out
);
[$total, $inc, $exp] = array_map('intval', preg_split('/\s+/', trim($out)) + [0, 0, 0]);

T::ok($total >= 15, 'نصبِ تازه با دسته‌بندی‌های پیش‌فرض بالا می‌آید',
    "تعداد: $total");
T::ok($inc >= 4, 'دسته‌های درآمد وجود دارند', "درآمد: $inc");
T::ok($exp >= 8, 'دسته‌های هزینه وجود دارند', "هزینه: $exp");

// «حقوق» باید درآمد باشد — در seedِ قدیمیِ مغازه هزینه بود.
$mysql('-N -B ' . escapeshellarg($scratch) . ' -e '
    . escapeshellarg("SELECT type FROM categories WHERE user_id IS NULL AND name='حقوق'"),
    null, $out);
T::same('income', trim($out), '«حقوق» درآمد است، نه هزینه');

// ⚠ همان ستون‌هایی که در `CREATE TABLE`ِ schema.sql جا افتاده بودند.
//   اگر دوباره جا بیفتند، seed با «Unknown column» می‌میرد و اصلاً به
//   اینجا نمی‌رسیم؛ ولی اگر ستون باشد و مقدارش نوشته نشود، همه‌ی
//   دسته‌ها آیکونِ پیش‌فرضِ ستون را می‌گیرند و هیچ خطایی نمی‌دهد.
//   «سایر هزینه‌ها» عمداً `default` است — آن یکی سطلِ همه‌چیزِ دیگر است.
$mysql('-N -B ' . escapeshellarg($scratch) . ' -e '
    . escapeshellarg("SELECT COUNT(*) FROM categories WHERE user_id IS NULL AND icon NOT IN ('', 'default')"),
    null, $out);
T::ok((int)trim($out) >= $total - 1,
    'دسته‌های پیش‌فرض آیکونِ خودشان را دارند، نه آیکونِ پیش‌فرضِ ستون',
    'با آیکون: ' . trim($out) . ' از ' . $total);

// ---------- اجرای دوباره چیزی را خراب نکند ----------
// همه‌ی فایل‌ها به‌جز migration_indexes ایدمپوتنت‌اند.
$again = [];
foreach ($files as $f) {
    $rc = $mysql(escapeshellarg($scratch), "$root/$f", $out);
    if ($rc !== 0 && !in_array($f, $DUP_INDEX_OK, true)) {
        $again[] = "$f → " . trim(preg_replace('/\s+/', ' ', substr($out, 0, 200)));
    }
}
T::bulk(count($files), $again, 'اجرای دوباره‌ی زنجیره بدون خطاست (به‌جز migration_indexes)');

$mysql('-N -B ' . escapeshellarg($scratch) . ' -e '
    . escapeshellarg('SELECT COUNT(*) FROM categories WHERE user_id IS NULL'), null, $out);
T::same($total, (int)trim($out), 'اجرای دوباره دسته‌بندیِ تکراری نمی‌سازد');

exit(T::report());
