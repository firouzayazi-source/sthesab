<?php
/**
 * ⛔ فرمانِ سریعِ فروشگاه (Ctrl+K) — `window.stCmd` در `assets/js/store.js`.
 *
 * همان تکه‌ی واقعیِ فایل (بینِ `@cmd-start` و `@cmd-end`) در node اجرا
 * می‌شود، نه یک کپی — الگوی `test_sms_parse.php`.
 *
 * خطرها، هر دو بی‌صدا:
 *   ۱. **مبلغِ غلط** — «۵ میلیون» پانصد هزار شود، یا شماره‌ی فاکتور مبلغ.
 *   ۲. **حدس به‌جای پرسیدن** — «ثبت تراکنش ۵ میلیون» یک‌راست هزینه شود.
 *      نیتِ مبهم باید چند گزینه بدهد.
 * ⛔ و هیچ فرمانی خودش نمی‌نویسد: خروجی فقط آدرسِ صفحه‌های موجود است.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

T::group('فرمانِ سریعِ فروشگاه');

exec('command -v node 2>/dev/null', $o, $rc);
if ($rc !== 0) {
    T::skip('فرمانِ سریع', 'node نصب نیست');
    exit(T::report());
}

$js = (string)file_get_contents(__DIR__ . '/../assets/js/store.js');
$a = strpos($js, '/* @cmd-start');
$b = strpos($js, '/* @cmd-end */');
T::ok($a === 0 && $b > $a, 'تکه‌ی فرمان در ابتدای store.js و بسته است');
if ($a === false || $b === false) { exit(T::report()); }
$block = substr($js, $a, $b - $a);

// ⛔ تکه‌ی خالص: نه DOM، نه شبکه، نه نوشتن
foreach (['document.', 'fetch(', 'XMLHttpRequest', 'localStorage', "method: 'POST'", 'submit('] as $bad) {
    T::ok(!str_contains($block, $bad), "تکه‌ی فرمان «{$bad}» ندارد");
}

$inputs = [
    'amt1' => '۵ میلیون', 'amt2' => '۲٫۵ میلیون', 'amt3' => '۳۰۰ هزار', 'amt4' => '1,200,000', 'amt5' => 'فاکتور ۴۵',
    'amt6' => '۴۵ تومان', 'amt7' => '5م',
    'tx'   => 'ثبت تراکنش ۵ میلیون',
    'exp'  => 'هزینه اجاره ۱۵ میلیون',
    'inv'  => 'فاکتور جدید',
    'pinv' => 'فاکتور خرید',
    'cus'  => 'مشتری جدید',
    'srch' => 'جستجوی علی رضایی',
    'debt' => 'نمایش بدهی‌ها',
    'st'   => 'نمایش فروش امروز',
    'chq'  => 'ثبت چک ۲ میلیون',
    'arab' => 'مشتري جديد',
    'none' => 'سلام',
    'inc'  => 'درآمد ۲ میلیون',
    'srchA'=> 'جست‌و‌جوی آرش احمدی',
];
// ⛔ `fold` (جست‌وجوی کالا) و `norm` (فرمان) یکی‌اند و همان `BizCommon::fold()`ِ سرورند.
$foldIn = ['آيفون ١٢ ٱؤ ـكتاب‌ها', 'ایفون', 'آیفون', 'IPHONE  13'];
$runner = $block . "\nvar I = " . json_encode($inputs, JSON_UNESCAPED_UNICODE) . ", O = {};\n"
        . "Object.keys(I).forEach(function (k) { O[k] = k.indexOf('amt') === 0 ? stCmd.amount(I[k]) : stCmd.parse(I[k]); });\n"
        . "O.fold = " . json_encode($foldIn, JSON_UNESCAPED_UNICODE) . ".map(function (x) { return [stCmd.fold(x), stCmd.norm(x)]; });\n"
        . "process.stdout.write(JSON.stringify(O));\n";
$tmp = tempnam(sys_get_temp_dir(), 'stcmd') . '.js';
file_put_contents($tmp, $runner);
$out = shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
$r = json_decode((string)$out, true);
if (!is_array($r)) {
    T::ok(false, 'node خروجیِ JSON داد', (string)$out);
    exit(T::report());
}
$urls = fn(string $k): array => array_column($r[$k], 'u');

T::same(5000000, $r['amt1'], '«۵ میلیون» ← ۵٬۰۰۰٬۰۰۰');
T::same(2500000, $r['amt2'], '«۲٫۵ میلیون» با ممیزِ فارسی');
T::same(300000, $r['amt3'], '«۳۰۰ هزار»');
T::same(1200000, $r['amt4'], 'جداکننده‌ی هزارگان');
T::same(0, $r['amt5'], '⛔ عددِ کوچکِ بی‌واحد (شماره‌ی فاکتور) مبلغ نیست');
T::same(45, $r['amt6'], 'ولی با «تومان» مبلغ است');
T::same(5000000, $r['amt7'], '«5م»');

T::same(['payment.php?k=receipt&amount=5000000', 'payment.php?k=expense&amount=5000000', 'payment.php?k=payment&amount=5000000'],
        $urls('tx'), '⛔ مبلغِ بی‌نوع سه گزینه می‌دهد، نه حدس');
T::same('payment.php?k=expense&amount=15000000', $urls('exp')[0] ?? '', 'نیتِ صریح: هزینه با مبلغ');
T::same(['invoice-edit.php?k=sale'], $urls('inv'), 'فاکتور جدید ← فاکتورِ فروش');
T::same('invoice-edit.php?k=purchase', $urls('pinv')[0] ?? '', 'فاکتور خرید');
T::same(['party.php'], $urls('cus'), 'مشتری جدید');
T::same(['party.php'], $urls('arab'), 'حروفِ عربی (ي) هم فهمیده می‌شود');
T::same(['search.php?q=' . rawurlencode('علی رضایی')], $urls('srch'), 'جستجو');
T::same(['parties.php?f=debtor', 'parties.php?f=creditor'], $urls('debt'), 'بدهی‌ها: هر دو سو');
T::same(['reports.php?p=today'], $urls('st'), 'فروش امروز ← گزارشِ امروز');
T::same('payment.php?k=receipt&method=cheque&amount=2000000', $urls('chq')[0] ?? '', 'ثبتِ چک با مبلغ');
T::same([], $r['none'], 'متنِ بی‌نیت هیچ فرمانی نمی‌سازد');
T::same('payment.php?k=income&amount=2000000', $urls('inc')[0] ?? '', '«درآمد» بعد از تا شدنِ «آ» هم فهمیده می‌شود');
T::same(['search.php?q=' . rawurlencode('آرش احمدی')], $urls('srchA'),
    '⛔ عبارتِ جستجو همان واژه‌های کاربر است («آرش» نه «ارش») — سرور با LIKE می‌گردد');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
$foldBad = [];
foreach ($foldIn as $i => $x) {
    [$jf, $jn] = $r['fold'][$i] ?? ['', ''];
    if ($jf !== BizCommon::fold($x) || $jn !== $jf) { $foldBad[] = "{$x} → js {$jf} / norm {$jn} / php " . BizCommon::fold($x); }
}
T::same([], $foldBad, '⛔ stCmd.fold = stCmd.norm = BizCommon::fold (آ/ٱ/ؤ، ارقامِ عربی، کشیده، نیم‌فاصله)');
T::same($r['fold'][1][0] ?? 'x', $r['fold'][2][0] ?? 'y', '«ایفون» و «آیفون» یکی‌اند');

// ⛔ همه‌ی آدرس‌ها به صفحه‌ی موجودِ store/ می‌روند
$all = [];
foreach ($r as $k => $v) { if (is_array($v) && $k !== 'fold') { foreach ($v as $it) { $all[] = (string)$it['u']; } } }
$missing = [];
foreach (array_unique($all) as $u) {
    $f = strtok($u, '?');
    if (!is_file(__DIR__ . '/../store/' . $f)) { $missing[] = $u; }
}
T::same([], $missing, 'هر فرمان به یک صفحه‌ی موجودِ فروشگاه می‌رود');

exit(T::report());
