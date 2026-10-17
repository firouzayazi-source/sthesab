<?php
/**
 * ⛔ کالای خواب و ریسکِ افتِ قیمت (`BizAging`، `store/aging.php`، هشدارِ داشبورد).
 *
 *   ۱. گوشی: سنِ هر دستگاه از ورودِ همان دستگاه؛ فروخته‌شده نمی‌ماند؛ دو IMEIِ یک گوشی یک دستگاه.
 *   ۲. بقیه: FIFO — مانده از تازه‌ترین ورودی‌ها؛ بی‌ورودی = سنِ نامعلوم (نه صفر).
 *   ۳. ریسک = بهای مانده − قیمتِ خریدِ امروز (فقط مثبت، فقط با قیمتِ ثبت‌شده)؛ زیرِ قیمتِ فروش.
 *   ۴. HTTP: صفحه و هشدارِ داشبورد فقط با «بها و سود».
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('کالای خواب');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('کالای خواب', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_aging.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('کالای خواب', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
const AG_PREFIX = '__store_aging_';
const AG_PASS   = 'Aging#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . AG_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, AG_PREFIX . $n, AG_PREFIX . $n . '@example.com', AG_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$imei = function (string $b14): string {
    $s = 0; for ($i = 0; $i < 14; $i++) { $d = (int)$b14[13 - $i]; if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } } $s += $d; }
    return $b14 . (string)((10 - $s % 10) % 10);
};
$ago = fn(int $d): string => date('Y-m-d', strtotime(today() . " -{$d} days"));

$u = $mk('a');
$acc = (int)BizCash::list($u)[0]['id'];
BizPay::create($u, ['kind' => 'capital', 'amount' => '900000000', 'account_id' => $acc, 'pay_date' => $ago(200)]);
$phone = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'Galaxy S24', 'unit' => 'دستگاه', 'sell_price' => '50000000'])['id'];
$case  = (int)BizProducts::save($u, ['name' => 'قاب', 'unit' => 'عدد', 'sell_price' => '1000'])['id'];
$empty = (int)BizProducts::save($u, ['name' => 'شارژر', 'unit' => 'عدد', 'sell_price' => '500'])['id'];
$sup = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$cust = (int)BizParties::save($u, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$doc = function (string $kind, int $party, string $date, array $lines) use ($u, $acc) {
    $d = BizInvoices::saveDraft($u, $kind, ['party_id' => $party, 'inv_date' => $date, 'lines' => $lines]);
    T::ok($d['ok'], "پیش‌نویسِ {$kind}", $d['message'] ?? '');
    $r = BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc]);
    T::ok($r['ok'], "صدورِ {$kind} {$date}", $r['message'] ?? '');
    return (int)$d['id'];
};
$A = $imei('35391811000101'); $A2 = $imei('35391811000102'); $B = $imei('35391811000103'); $C = $imei('35391811000104');
$doc('purchase', $sup, $ago(100), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '40000000', 'imei1' => $A, 'imei2' => $A2],
                                   ['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '41000000', 'imei1' => $C, 'imei2' => '']]);
$doc('purchase', $sup, $ago(10), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '38000000', 'imei1' => $B, 'imei2' => '']]);
$doc('sale', $cust, $ago(5), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '50000000', 'imei1' => $C, 'imei2' => '']]);
// گوشیِ دوسیمه که با IMEI ۲ فروخته شد — همان دستگاه است و نباید بماند
$D1 = $imei('35391811000105'); $D2 = $imei('35391811000106');
$doc('purchase', $sup, $ago(50), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '39000000', 'imei1' => $D1, 'imei2' => $D2]]);
$doc('sale', $cust, $ago(3), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '50000000', 'imei1' => $D2, 'imei2' => '']]);
// دوسیمه: فروش با IMEI ۲، برگشت، و فروشِ دوباره با IMEI ۱ — هنوز همان یک دستگاه است
$E1 = $imei('35391811000107'); $E2 = $imei('35391811000108');
$doc('purchase', $sup, $ago(20), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '39000000', 'imei1' => $E1, 'imei2' => $E2]]);
$sE = $doc('sale', $cust, $ago(15), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '50000000', 'imei1' => $E2, 'imei2' => '']]);
$rt = BizInvoices::createReturn($u, $sE, [(int)BizInvoices::get($u, $sE)['lines'][0]['id'] => '1'], ['full' => true, 'account_id' => $acc], $ago(12));
T::ok($rt['ok'], 'برگشت از فروش', $rt['message'] ?? '');
$doc('sale', $cust, $ago(8), [['product_id' => $phone, 'item' => 'Galaxy S24', 'qty' => '1', 'price' => '50000000', 'imei1' => $E1, 'imei2' => '']]);
// مرزِ دسته: درست ۳۰ روز
$edge = (int)BizProducts::save($u, ['name' => 'گلس', 'unit' => 'عدد', 'sell_price' => '100'])['id'];
$doc('purchase', $sup, $ago(30), [['product_id' => $edge, 'item' => 'گلس', 'qty' => '2', 'price' => '50']]);
$doc('purchase', $sup, $ago(95), [['product_id' => $case, 'item' => 'قاب', 'qty' => '5', 'price' => '600']]);
$doc('purchase', $sup, $ago(5), [['product_id' => $case, 'item' => 'قاب', 'qty' => '5', 'price' => '700']]);
$doc('sale', $cust, $ago(2), [['product_id' => $case, 'item' => 'قاب', 'qty' => '6', 'price' => '1000']]);
// موجودیِ بی‌سندِ ورود (داده‌ی ناقص): سن نامعلوم
$pdo->prepare('UPDATE biz_products SET stock_qty = 3, avg_cost = 200 WHERE id = :i')->execute(['i' => $empty]);
// قیمتِ خریدِ امروز
$pdo->prepare('UPDATE biz_products SET buy_price = 37000000 WHERE id = :i')->execute(['i' => $phone]);
$pdo->prepare('UPDATE biz_products SET buy_price = 650, sell_price = 680 WHERE id = :i')->execute(['i' => $case]);

T::group('۱ — گوشی');
$rows = BizAging::rows($u);
$by = []; foreach ($rows as $r) { $by[$r['id']] = $r; }
$ph = $by[$phone] ?? null;
T::ok($ph !== null, 'گوشی در فهرست');
$imeis = array_column($ph['lots'] ?? [], 'imei');
sort($imeis);
T::same([$A, $B], $imeis, '⛔ فقط دستگاه‌های موجود (فروخته‌شده نه؛ دوسیمه‌ی فروخته‌شده با IMEI ۲، یا برگشته و دوباره فروخته‌شده با IMEI ۱، هم نه)');
T::same([2.0, 0.0, 0.0, 0.0], $by[$edge]['buckets'] ?? null, 'مرز: ۳۰ روز هنوز در دسته‌ی اول');
$ageOf = []; foreach ($ph['lots'] as $l) { $ageOf[$l['imei']] = $l['age']; }
T::same([100, 10], [$ageOf[$A] ?? null, $ageOf[$B] ?? null], 'سنِ هر دستگاه از ورودِ خودش');
T::same(100, $ph['oldest'], 'قدیمی‌ترین');
T::same(78000000, $ph['value'], 'ارزش = بهای همان دستگاه‌ها (۴۰ + ۳۸ میلیون)');
T::same(3000000 + 1000000, $ph['risk'], '⛔ ریسک = (۴۰−۳۷) + (۳۸−۳۷) میلیون');
T::same([1.0, 0.0, 0.0, 1.0], $ph['buckets'], 'دسته‌ها: یکی تازه، یکی بیش از ۹۰');

T::group('۲ — FIFO و نامعلوم');
$cs = $by[$case];
T::same(4.0, $cs['qty'], 'قاب: ۴ مانده');
T::same(5, $cs['oldest'], '⛔ FIFO: مانده از تازه‌ترین ورود (۵ روز)، نه ۹۵ روز');
T::same(2800, $cs['value'], 'ارزش = ۴ × ۷۰۰');
T::same(200, $cs['risk'], 'ریسک = ۴ × (۷۰۰ − ۶۵۰)');
T::ok($cs['below_sell'], 'قیمتِ فروشِ ۶۸۰ زیرِ بهای ۷۰۰');
T::ok(!$ph['below_sell'], 'گوشی زیرِ قیمتِ فروش نیست');
$em = $by[$empty];
T::same(null, $em['oldest'], '⛔ بی‌ورودی: سنِ نامعلوم، نه صفر');
T::same(3.0, $em['buckets'][3], 'نامعلوم در دسته‌ی آخر (خواب حساب می‌شود)');
T::same(0, $em['risk'], 'بی‌قیمتِ امروز: ریسک نه (حدس نه)');

T::group('۳ — جمع');
$s = BizAging::summary($rows);
T::same(38000000 + 2800 + 100, $s['value'][0], 'دسته‌ی ۰ تا ۳۰');
T::same(40000000 + 600, $s['value'][3], 'بیش از ۹۰ (گوشیِ کهنه + نامعلوم)');
T::same(40000600, $s['idle'], 'سرمایه‌ی خوابیده');
T::same(4000200, $s['risk'], 'ریسکِ کل');
T::same([2, 1], [$s['risky'], $s['below_sell']], 'شمار');
T::same(array_sum(array_column($rows, 'value')), $s['total'], 'کل');
$o = $mk('o');
T::same([], BizAging::rows($o), 'فروشگاهِ دیگر هیچ');

T::group('۴ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, AG_PREFIX . 'a', AG_PASS);
    [$code, $h] = $c('store/aging.php');
    T::ok($code === 200 && str_contains($h, 'Galaxy S24') && str_contains($h, formatMoney(4000200)), 'صفحه: کالا و ریسکِ کل', (string)$code);
    T::ok(str_contains($h, $A) && !str_contains($h, $B), 'IMEIِ خوابیده نام برده می‌شود، تازه نه');
    [, $h] = $c('store/aging.php?f=risk');
    T::ok(str_contains($h, 'قاب') && !str_contains($h, 'شارژر'), 'صافیِ ریسک');
    [, $h] = $c('store/index.php');
    T::ok(str_contains($h, 'سرمایه‌ی خوابیده: ' . formatMoney(40000600)), 'هشدارِ داشبورد');
    $sel = $mk('sel');
    $pdo->prepare("INSERT INTO store_staff (user_id, staff_id, role) VALUES (:o, :s, 'seller')")->execute(['o' => $u, 's' => $sel]);
    $x = stHttpClient($port);
    stHttpLogin($x, AG_PREFIX . 'sel', AG_PASS);
    [$code, $h] = $x('store/aging.php');
    T::ok($code !== 200 || !str_contains($h, formatMoney(4000200)), '⛔ فروشنده (بی‌«بها و سود») مبلغ نمی‌بیند', (string)$code);
}

// ⛔ هیچ نقشِ امروزی داشبورد را بی‌«بها و سود» نمی‌بیند، پس سدِ هشدار فقط با خواندنِ کد سنجیده می‌شود —
//    نقشِ تازه‌ای با «پول» ولی بی‌«بها» نباید سرمایه‌ی خوابیده را ببیند.
$idx = (string)file_get_contents(__DIR__ . '/../store/index.php');
T::ok((bool)preg_match("/if \\(Biz::can\\('cost'\\)\\) \\{\\s*require_once[^\\n]*biz_aging\\.php/", $idx), '⛔ هشدارِ داشبورد پشتِ `Biz::can(\'cost\')`');

exit(T::report());
