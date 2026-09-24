<?php
/**
 * تستِ کارتِ سالانه‌ی چهارفصل (`seasonalYearReport()`) — تابعِ خالص،
 * بدونِ دیتابیس.
 *
 * ⛔ خطرِ اصلیِ این کارت دروغ گفتنِ بی‌صداست، نه خطا:
 *   • مرزِ ماهِ شمسی یک روز جابه‌جا شود → تراکنشِ ۱ فروردین در اسفند
 *     می‌نشیند و هر دو عدد غلط‌اند، ولی جمعِ سال درست می‌ماند.
 *   • جمعِ ۱۲ ماه با جمعِ سال نخواند → کاربر به هیچ‌کدام اعتماد نمی‌کند.
 *   • ردیفِ بیرون از سال (سالِ قبل/بعد) داخلِ آن شمرده شود.
 *   • اسفندِ کبیسه (۳۰ روز) بریده شود.
 * پس هر مرز با **تاریخِ واقعیِ تقویم** سنجیده می‌شود، نه با فرضِ ۳۰/۳۱.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
if (!defined('APP_BASE_PATH')) { define('APP_BASE_PATH', ''); }
require_once __DIR__ . '/../includes/functions.php';

$row = static fn(string $d, int $in, int $out) =>
    ['transaction_date' => $d, 'daily_income' => $in, 'daily_expense' => $out];

// ۱۴۰۳ کبیسه است (اسفندش ۳۰ روز)؛ ۱ فروردین ۱۴۰۴ = 2025-03-21.
$rows = [
    $row('2024-03-19', 999, 999),      // ۲۹ اسفند ۱۴۰۲ — بیرون از ۱۴۰۳
    $row('2024-03-20', 100, 10),       // ۱ فروردین ۱۴۰۳
    $row('2024-06-20', 200, 20),       // ۳۱ خرداد ۱۴۰۳ (آخرین روزِ بهار)
    $row('2024-06-21', 300, 30),       // ۱ تیر ۱۴۰۳ (اولین روزِ تابستان)
    $row('2024-12-21', 400, 40),       // ۱ دی ۱۴۰۳
    $row('2025-03-20', 500, 50),       // ۳۰ اسفند ۱۴۰۳ (روزِ کبیسه)
    $row('2025-03-21', 777, 777),      // ۱ فروردین ۱۴۰۴ — بیرون
];

T::group('مرزهای ماه و فصل');

$r = seasonalYearReport($rows, 1403, '2024-07-10');   // ۲۰ تیر ۱۴۰۳
T::same('2024-03-20', $r['from'], 'سال از ۱ فروردین شروع می‌شود');
T::same('2025-03-20', $r['to'], '⛔ اسفندِ کبیسه تا ۳۰ام است');
T::same(['spring', 'summer', 'autumn', 'winter'], array_column($r['seasons'], 'key'),
    'چهار فصل به ترتیب');
T::same(3, count($r['seasons'][0]['months']), 'هر فصل سه ماه');

$sp = $r['seasons'][0]; $su = $r['seasons'][1]; $wi = $r['seasons'][3];
T::same(300, $sp['income'], '۱ فروردین و ۳۱ خرداد در بهار');
T::same(300, $su['income'], '⛔ ۱ تیر در تابستان است نه بهار');
T::same(900, $wi['income'], '۱ دی و ۳۰ اسفند در زمستان');
T::same(500, $wi['months'][2]['income'], 'روزِ کبیسه در خودِ اسفند');

T::group('⛔ جمع‌ها با هم می‌خوانند');

$sumIn = 0; $sumOut = 0; $monthIn = 0;
foreach ($r['seasons'] as $s) {
    $sumIn += $s['income']; $sumOut += $s['expense'];
    foreach ($s['months'] as $m) { $monthIn += $m['income']; }
    T::same($s['income'] - $s['expense'], $s['net'], "خالصِ {$s['name']} = درآمد − هزینه");
}
T::same(1500, $r['income'], 'ردیف‌های بیرون از سال شمرده نمی‌شوند');
T::same($r['income'], $sumIn, 'جمعِ فصل‌ها = جمعِ سال (درآمد)');
T::same($r['expense'], $sumOut, 'جمعِ فصل‌ها = جمعِ سال (هزینه)');
T::same($r['income'], $monthIn, 'جمعِ ۱۲ ماه = جمعِ سال');
T::same($r['income'] - $r['expense'], $r['net'], 'خالصِ سال');

T::group('جاری و آینده');

T::ok($su['current'] && !$sp['current'], 'تابستان فصلِ جاری است');
T::ok($su['months'][0]['current'], 'تیر ماهِ جاری است');
T::ok($r['seasons'][2]['future'] && !$su['future'], 'پاییز «آینده» است، تابستان نه');
T::ok(!$sp['months'][0]['future'], 'فروردین آینده نیست');

T::group('لینکِ هر بازه');

$u = txRangeUrl($su['months'][0]['from'], $su['months'][0]['to']);
T::ok(str_contains($u, 'period=custom') && str_contains($u, 'from_date=2024-06-21')
    && str_contains($u, 'to_date=2024-07-21'),
    'لینکِ تیر دقیقاً بازه‌ی همان ماه است', $u);

/* ⛔ راهِ برگشت به همان سال — «وارد ماه خاص میشیم دکمه برگشت نداره». */
$ub = txRangeUrl('2024-06-21', '2024-07-21', 1403);
T::ok(str_contains($ub, 'back=y1403'), 'لینکِ کارتِ سالانه سالِ برگشت را حمل می‌کند', $ub);
T::ok(!str_contains($u, 'back='), 'بدونِ سال، هیچ `back`ی نمی‌آید');
T::same(1403, txBackYear('y1403'), 'سالِ معتبر خوانده می‌شود');
foreach (['', '1403', 'y99', 'y1403x', 'https://evil', "y1403\n", ['y1403']] as $bad) {
    T::same(null, txBackYear($bad), '⛔ مقدارِ نامعتبرِ back رد می‌شود: ' . json_encode($bad));
}

$empty = seasonalYearReport([], 1404, '2025-04-01');
T::same(0, $empty['income'], 'سالِ بی‌تراکنش صفر است، نه خطا');

exit(T::report());
