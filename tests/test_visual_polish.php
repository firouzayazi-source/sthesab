<?php
/**
 * ارتقای ظاهری — سه تابعِ خالصی که پشتِ ظاهرِ تازه نشسته‌اند.
 *
 * ⛔ ظاهر را کرومیوم می‌بیند (در `CLAUDE.md` اسکرین‌شات‌ها ثبت شده‌اند)،
 *    ولی **حرف**ی که روی صفحه نوشته می‌شود از این سه تابع می‌آید، و
 *    خرابیِ هر سه بی‌صداست:
 *
 *    ۱. `monthMeter()` — «۸۱٪ از دریافتیِ این ماه خرج شده». با دریافتیِ
 *       صفر، تقسیم بر صفر یا «۰٪»ِ دروغ؛ با ماهِ قبلِ خالی، «۱۰۰٪ بیشتر»
 *       — همان جمله‌ی دروغی که `financialHighlights()` برای نبودنش
 *       نگهبان دارد.
 *    ۲. `txDayLabel()` — «امروز / دیروز». اگر `date()` خام به‌جای
 *       `today()` بخواند، در پنجره‌ی بامدادی تراکنشِ دیروز «امروز»
 *       خوانده می‌شود.
 *    ۳. `window.skeletonHtml()` — جای‌نگهدارِ بارگذاری. اگر متنِ
 *       صفحه‌خوان از آن بیفتد، کاربرِ نابینا فقط سکوت می‌شنود.
 *
 * ⚠ بدونِ دیتابیس اجرا می‌شود؛ بخشِ ۳ به node نیاز دارد (`T::skip`).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/functions.php';

// ---------------------------------------------------------------
T::group('monthMeter() — نوار و خطِ مقایسه‌ی کارتِ ماه');
// ---------------------------------------------------------------
$mk = static function (int $in, int $out, int $pin, int $pout, int $ch): array {
    return ['current_income' => $in, 'current_expense' => $out,
            'prev_income' => $pin, 'prev_expense' => $pout,
            'expense_change' => $ch, 'prev_label' => 'شهریور'];
};

$m = monthMeter($mk(2500000, 2027000, 0, 0, 100));
T::same(81, $m['pct'], 'درصد از خرج/دریافتی گرد می‌شود');
T::same(81, $m['fill'], 'پرشدگیِ نوار همان درصد است');
T::same('warn', $m['tone'], 'بالای ۸۰٪ کهربایی');
T::ok(mb_strpos($m['caption'], '۸۱٪') !== false, 'عنوانِ زیرِ نوار عدد را فارسی می‌گوید', $m['caption']);
T::same(null, $m['compare'], 'ماهِ قبلِ بی‌خرج → هیچ مقایسه‌ای (نه «۱۰۰٪ بیشتر»)');

$m = monthMeter($mk(1000000, 1300000, 0, 0, 0));
T::same(130, $m['pct'], 'بیش از دریافتی: درصد بریده نمی‌شود');
T::same(100, $m['fill'], '… ولی نوار از ۱۰۰ بیرون نمی‌زند');
T::same('over', $m['tone'], 'بیش از دریافتی قرمز');
T::ok(mb_strpos($m['caption'], '۳۰٪ بیشتر') !== false, 'عنوان می‌گوید چقدر بیشتر', $m['caption']);

$m = monthMeter($mk(1000000, 400000, 0, 0, 0));
T::same('', $m['tone'], 'زیرِ ۸۰٪ رنگِ عادی');

$m = monthMeter($mk(0, 500000, 0, 0, 0));
T::same(null, $m['pct'], 'بدونِ دریافتی درصدی ساخته نمی‌شود (تقسیم بر صفر، یا «۰٪»ِ دروغ)');

$m = monthMeter($mk(0, 0, 0, 0, 0));
T::same(null, $m['pct'], 'ماهِ خالی: نه نوار');
T::same(null, $m['compare'], 'ماهِ خالی: نه مقایسه');

$m = monthMeter($mk(5000000, 900000, 0, 1000000, -10));
T::same('down', $m['compare']['dir'] ?? null, '۱۰٪ کمتر → پیکانِ پایین');
T::ok(mb_strpos($m['compare']['text'] ?? '', '۱۰٪ کمتر از همین روزِ شهریور') !== false,
    'متنِ مقایسه هم‌روز است و ماهِ قبل را نام می‌برد', $m['compare']['text'] ?? '');

$m = monthMeter($mk(5000000, 1200000, 0, 1000000, 20));
T::same('up', $m['compare']['dir'] ?? null, '۲۰٪ بیشتر → پیکانِ بالا');

$m = monthMeter($mk(5000000, 1050000, 0, 1000000, 5));
T::same('flat', $m['compare']['dir'] ?? null, 'نوسانِ زیرِ ۱۰٪ خبر نیست: «تقریباً هم‌اندازه»');

$m = monthMeter($mk(5000000, 0, 0, 1000000, -100));
T::same(null, $m['compare'], 'این ماه بی‌خرج → مقایسه‌ای نیست (نه «۱۰۰٪ کمتر»)');

// ---------------------------------------------------------------
T::group('txDayLabel() — امروز / دیروز / تاریخ');
// ---------------------------------------------------------------
$t = today();
$y = date('Y-m-d', strtotime($t . ' -1 day'));
$l = txDayLabel($t);
T::same('امروز', $l['rel'], 'امروز «امروز» است');
$l = txDayLabel($y);
T::same('دیروز', $l['rel'], 'دیروز «دیروز» است');
$l = txDayLabel(date('Y-m-d', strtotime($t . ' -2 day')));
T::same(null, $l['rel'], 'پریروز برچسبِ نسبی نمی‌گیرد');
T::ok(!preg_match('/[0-9]/', $l['date']), 'تاریخ با ارقامِ فارسی', $l['date']);

[$ty] = gregorianToJalali(...array_map('intval', explode('-', $t)));
$thisYear = toPersianDigits((string)$ty);
T::ok(mb_strpos(txDayLabel($t)['date'], $thisYear) === false, 'سالِ جاری تکرار نمی‌شود', txDayLabel($t)['date']);
$old = date('Y-m-d', strtotime($t . ' -400 day'));
T::ok(mb_strpos(txDayLabel($old)['date'], toPersianDigits((string)($ty - 1))) !== false
      || mb_strpos(txDayLabel($old)['date'], toPersianDigits((string)($ty - 2))) !== false,
    'سالِ دیگر نوشته می‌شود', txDayLabel($old)['date']);

// ⛔ «امروز» از `today()` می‌آید، نه `date()` خام — شکلش سنجیده می‌شود.
$src = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$body = '';
if (preg_match('/function txDayLabel\(.*?\n\}/s', $src, $mm)) { $body = $mm[0]; }
T::ok($body !== '' && strpos($body, 'today()') !== false, '`txDayLabel()` «امروز» را از today() می‌خواند');

// ---------------------------------------------------------------
T::group('window.skeletonHtml() — اسکلتِ بارگذاری');
// ---------------------------------------------------------------
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('skeletonHtml', 'node نصب نیست');
    exit(T::report());
}
$dump = __DIR__ . '/skel_js_dump.js';
$spec = [[0 => 1, 1 => 3, 2 => 'x', 3 => 99, 4 => -5]];
$proc = proc_open([$node, $dump], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], json_encode(array_values($spec[0])));
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
proc_close($proc);
$res = json_decode((string)$out, true);
if (!is_array($res)) {
    T::ok(false, 'skel_js_dump.js اجرا شد', trim($err));
    exit(T::report());
}
$rows = array_map(static fn($h) => substr_count((string)$h, 'class="skel-row"'), $res);
T::same([1, 3, 1, 6, 1], $rows, 'تعدادِ ردیف بین ۱ و ۶ بریده می‌شود، ورودیِ نامعتبر یعنی یک ردیف');
T::ok(strpos($res[1], 'در حال بارگذاری') !== false && strpos($res[1], 'sr-only') !== false,
    'متنِ صفحه‌خوان در اسکلت هست');
T::ok(strpos($res[1], 'role="status"') !== false && strpos($res[1], 'aria-busy="true"') !== false,
    'اسکلت خودش را «در حال کار» اعلام می‌کند');

exit(T::report());
