<?php
/**
 * فهرستِ توری و صفحه‌بندی‌شده — `includes/paged_list.php`.
 *
 * ⛔ چهار خرابیِ بی‌صدا که این فایل برایشان نوشته شده:
 *
 *    ۱. **باز کردنِ «همه» روی یک فهرست، بقیه را هم باز کند.** سه
 *       فهرست روی یک صفحه‌اند؛ با یک پرچمِ بولینِ مشترک، زدنِ «همه»
 *       روی «کسانی که کمک لازم دارند» فهرستِ ۷۰۰تاییِ کاربران را هم
 *       باز می‌کرد — یعنی همان شلوغی‌ای که این قابلیت برای رفعش نوشته
 *       شد، فقط با یک تپِ بیشتر.
 *
 *    ۲. **لینکِ صفحه‌ی بعد بقیه‌ی پارامترها را دور بریزد.** آن‌وقت
 *       رفتن به صفحه‌ی دومِ یک فهرست، جای کاربر را در فهرستِ دیگر
 *       **بی‌صدا** صفر می‌کند. هیچ خطایی ندارد و فقط آزاردهنده است،
 *       یعنی دقیقاً چیزی که کسی گزارشش نمی‌کند.
 *
 *    ۳. **شماره‌ی بیرون از بازه فهرستِ خالی بدهد.** `?pg_users=99`
 *       دستِ آدم می‌خورد و صفحه‌ی خالیِ بی‌توضیح شبیهِ خرابی دیده
 *       می‌شود.
 *
 *    ۴. **سقفِ بی‌صدا.** نسخه‌ی قبلیِ `admin/insights.php` با
 *       `array_slice($never, 0, 20)` بیست‌تای اول را نشان می‌داد و
 *       هیچ‌جا نمی‌گفت بقیه کجا رفتند — راهنما همین را ممنوع کرده.
 *
 * ⚠ این تست به دیتابیس نیازی ندارد: منطقِ برش خالص است و روی آرایه
 *   کار می‌کند. همین هم یک تصمیمِ ثبت‌شده است — برش در PHP انجام
 *   می‌شود نه با `LIMIT`، وگرنه هر فهرست دو کوئریِ تازه به صفحه‌ای
 *   اضافه می‌کرد که بودجه‌اش در `test_query_budget` ثبت است.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');
require_once $root . '/includes/paged_list.php';

// `pagedSlice()` از `getParam()` می‌خواند و `pagedNav()` از
// `toPersianDigits()`; هر دو در `functions.php` اند ولی آن فایل
// دیتابیس می‌خواهد. این تست عمداً بدونِ دیتابیس اجرا می‌شود، پس دو
// تابع را — فقط اگر نبودند — همین‌جا تعریف می‌کنیم.
if (!function_exists('getParam')) {
    function getParam(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }
}
if (!function_exists('toPersianDigits')) {
    function toPersianDigits($v): string { return (string)$v; }
}

$rows = [];
for ($i = 1; $i <= 25; $i++) { $rows[] = ['n' => $i]; }

$ids = fn(array $s) => array_map(fn($r) => $r['n'], $s['rows']);

// ---------------------------------------------------------------
T::group('برشِ صفحه');

$_GET = [];
$s = pagedSlice($rows, 'users');
T::same(PAGED_LIST_SIZE, count($s['rows']), 'صفحه‌ی اول دقیقاً به اندازه‌ی PAGED_LIST_SIZE است');
T::same(1, $s['page'], 'بدونِ پارامتر، صفحه‌ی اول');
T::same(3, $s['pages'], '۲۵ ردیف با سقفِ ۱۰ می‌شود ۳ صفحه');
T::same(25, $s['total'], 'عددِ کل همیشه گفته می‌شود، نه فقط چیزی که دیده می‌شود');
T::same(false, $s['all'], 'پیش‌فرض صفحه‌بندی است، نه «همه»');
T::same([1,2,3,4,5,6,7,8,9,10], $ids($s), 'ردیف‌های صفحه‌ی اول');

$_GET = ['pg_users' => '3'];
$s = pagedSlice($rows, 'users');
T::same([21,22,23,24,25], $ids($s), 'صفحه‌ی آخر فقط باقی‌مانده را می‌دهد');

// ⛔ شماره‌ی بیرون از بازه به نزدیک‌ترین صفحه بریده می‌شود، نه فهرستِ خالی.
$_GET = ['pg_users' => '99'];
T::same(3, pagedSlice($rows, 'users')['page'], 'شماره‌ی خیلی بزرگ به صفحه‌ی آخر بریده می‌شود');
$_GET = ['pg_users' => '-4'];
T::same(1, pagedSlice($rows, 'users')['page'], 'شماره‌ی منفی به صفحه‌ی اول بریده می‌شود');
$_GET = ['pg_users' => 'abc'];
T::same(1, pagedSlice($rows, 'users')['page'], 'شماره‌ی غیرعددی صفحه را نمی‌شکند');

// کم‌تر از یک صفحه
$_GET = [];
$small = pagedSlice(array_slice($rows, 0, 4), 'users');
T::same(1, $small['pages'], 'با ۴ ردیف یک صفحه است');
T::same(4, count($small['rows']), 'هر ۴ ردیف می‌آیند');

// فهرستِ خالی نباید صفرِ صفحه بدهد (تقسیم بر صفر در نمایش).
$empty = pagedSlice([], 'users');
T::same(1, $empty['pages'], 'فهرستِ خالی هم یک صفحه است، نه صفر');
T::same(0, $empty['total'], 'کلِ فهرستِ خالی صفر است');

// ---------------------------------------------------------------
T::group('⛔ «همه» فقط برای همان فهرست');

// ⛔ مهم‌ترین بررسیِ این فایل: `?all=` نامِ فهرست را می‌گیرد، نه یک
//    بولین. با کلیدِ مشترک، باز کردنِ یکی هر سه را باز می‌کرد.
$_GET = ['all' => 'never'];
$sNever = pagedSlice($rows, 'never');
$sUsers = pagedSlice($rows, 'users');
T::same(true, $sNever['all'], 'فهرستی که نامش در ?all آمده کامل باز می‌شود');
T::same(25, count($sNever['rows']), '«همه» یعنی واقعاً همه، نه یک سقفِ دیگر');
T::same(false, $sUsers['all'], '⛔ فهرستِ دیگر با همان پارامتر باز نمی‌شود');
T::same(PAGED_LIST_SIZE, count($sUsers['rows']), 'فهرستِ دیگر همچنان صفحه‌بندی‌شده است');

// ---------------------------------------------------------------
T::group('⛔ آدرس بقیه‌ی پارامترها را نگه می‌دارد');

$_GET = ['pg_users' => '2', 'pg_never' => '3', 'q' => 'x'];
$_SERVER['SCRIPT_NAME'] = '/admin/insights.php';

$url = pagedUrl(['pg_users' => 4]);
T::ok(strpos($url, 'pg_users=4') !== false, 'پارامترِ خودش به‌روز می‌شود');
T::ok(strpos($url, 'pg_never=3') !== false, '⛔ صفحه‌ی فهرستِ دیگر حفظ می‌شود');
T::ok(strpos($url, 'q=x') !== false, 'پارامترهای بی‌ربط هم حفظ می‌شوند');
T::ok(strpos($url, 'insights.php?') === 0, 'آدرس نسبی به خودِ صفحه است');

// `null` یعنی «این پارامتر را بردار» — «صفحه‌بندی» باید `all` را ببرد.
$url2 = pagedUrl(['all' => null, 'pg_users' => null]);
T::ok(strpos($url2, 'pg_users=') === false, 'مقدارِ null پارامتر را حذف می‌کند');
T::ok(strpos($url2, 'pg_never=3') !== false, 'و بقیه دست‌نخورده می‌مانند');

// ⛔ آرایه در کوئری نباید به `http_build_query` برسد و آدرسِ عجیب بسازد.
$_GET = ['pg_users' => ['1', '2']];
$url3 = pagedUrl(['all' => 'users']);
T::ok(strpos($url3, 'all=users') !== false, 'با ورودیِ آرایه‌ای هم آدرس ساخته می‌شود');
T::ok(strpos($url3, 'pg_users') === false, 'پارامترِ آرایه‌ای دور ریخته می‌شود');

// ---------------------------------------------------------------
T::group('نوارِ صفحه‌بندی');

// با یک صفحه اصلاً رندر نمی‌شود — نوارِ «۱ از ۱» چیزی نمی‌گوید.
$_GET = [];
ob_start(); pagedNav(pagedSlice(array_slice($rows, 0, 5), 'users')); $out = ob_get_clean();
T::same('', trim($out), 'با کمتر از یک صفحه نوار رندر نمی‌شود');

ob_start(); pagedNav(pagedSlice($rows, 'users')); $out = ob_get_clean();
T::ok(strpos($out, 'بعدی') !== false, 'نوار دکمه‌ی بعدی دارد');
T::ok(strpos($out, 'همه در یک فهرست') !== false, '⛔ راهِ «همه در یک لیست» روی نوار هست');
T::ok(strpos($out, 'pg_users=2') !== false, 'لینکِ بعدی به صفحه‌ی دوم می‌رود');
T::ok(strpos($out, '25') !== false || strpos($out, '۲۵') !== false,
      '⛔ عددِ کل روی نوار نوشته می‌شود — سقفِ بی‌صدا ممنوع است');

$_GET = ['all' => 'users'];
ob_start(); pagedNav(pagedSlice($rows, 'users')); $out = ob_get_clean();
T::ok(strpos($out, 'صفحه‌بندی') !== false, 'در حالتِ «همه» راهِ برگشت به صفحه‌بندی هست');
T::ok(strpos($out, 'بعدی') === false, 'در حالتِ «همه» دکمه‌ی بعدی معنا ندارد و نمی‌آید');

$_GET = [];
exit(T::report());
