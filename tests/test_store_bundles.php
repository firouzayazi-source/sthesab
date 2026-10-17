<?php
/**
 * ⛔ بسته‌ی فروش (`BizBundles`، `store/bundles.php`، باز شدن در `BizInvoices::saveDraft()`ِ فروش).
 *
 *   ۱. ذخیره: دست‌کم دو قلم؛ گوشیِ IMEIدار و کالای فروشگاهِ دیگر نه؛ هم‌نام/هم‌کدِ کالا نه.
 *   ۲. `split()`: جمعِ ردیف‌ها دقیقاً قیمتِ یک‌جا (ردیفِ چندتایی دو تکه می‌شود).
 *   ۳. فاکتورِ فروش: ردیفِ بسته → اجزای واقعی؛ موجودیِ هر کدام کم می‌شود؛ قیمتِ تایپ‌شده برنده؛ تعدادِ بسته.
 *   ۴. خرید باز نمی‌کند؛ بسته‌ی غیرفعال نه؛ ردیفِ دارای کالا دست نمی‌خورد. HTTP: datalistِ فروش و صفحه.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('بسته‌ی فروش');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('بسته', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('بسته', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizBundles::ready()) { T::blocked('بسته', 'migration_biz_bundles اجرا نشده'); exit(T::report()); }
const BD_PREFIX = '__store_bundle_';
const BD_PASS   = 'Bundle#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . BD_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, BD_PREFIX . $n, BD_PREFIX . $n . '@example.com', BD_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$u = $mk('a'); $o = $mk('o');
$acc = (int)BizCash::list($u)[0]['id'];
BizPay::create($u, ['kind' => 'capital', 'amount' => '90000000', 'account_id' => $acc]);
$case = (int)BizProducts::save($u, ['name' => 'قاب', 'sku' => 'C1', 'unit' => 'عدد', 'sell_price' => '300000'])['id'];
$gl   = (int)BizProducts::save($u, ['name' => 'گلس', 'unit' => 'عدد', 'sell_price' => '100000'])['id'];
$ch   = (int)BizProducts::save($u, ['name' => 'شارژر', 'unit' => 'عدد', 'sell_price' => '600000'])['id'];
$ph   = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'A55', 'unit' => 'دستگاه', 'sell_price' => '20000000'])['id'];
$oP   = (int)BizProducts::save($o, ['name' => 'دیگری', 'unit' => 'عدد', 'sell_price' => '1'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [
    ['product_id' => $case, 'item' => 'قاب', 'qty' => '20', 'price' => '100000'], ['product_id' => $gl, 'item' => 'گلس', 'qty' => '20', 'price' => '30000'],
    ['product_id' => $ch, 'item' => 'شارژر', 'qty' => '20', 'price' => '200000']]]);
BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc]);
$stock = fn(int $p) => (float)BizProducts::get($u, $p)['stock_qty'];

T::group('۱ — ذخیره');
T::ok(!BizBundles::save($u, ['name' => 'تک', 'items' => [$case => '1']])['ok'], 'یک قلم رد');
T::ok(!BizBundles::save($u, ['name' => 'با گوشی', 'items' => [$case => '1', $ph => '1']])['ok'], '⛔ گوشیِ IMEIدار رد');
T::ok(!BizBundles::save($u, ['name' => 'بیگانه', 'items' => [$case => '1', $oP => '1']])['ok'], '⛔ کالای فروشگاهِ دیگر رد');
T::ok(!BizBundles::save($u, ['name' => 'قاب', 'items' => [$case => '1', $gl => '1']])['ok'], '⛔ هم‌نامِ کالا رد');
T::ok(!BizBundles::save($u, ['name' => 'پک', 'sku' => 'C1', 'items' => [$case => '1', $gl => '1']])['ok'], '⛔ هم‌کدِ کالا رد');
T::ok(!BizBundles::save($u, ['name' => 'نیم', 'items' => [$case => '0.5', $gl => '1']])['ok'], 'تعدادِ کسری برای «عدد» رد');
$b = BizBundles::save($u, ['name' => 'پکِ لوازم', 'sku' => 'PK1', 'price' => '۹۰۰٬۰۰۰', 'items' => [$case => '1', $gl => '2', $ch => '1']]);
T::ok($b['ok'], 'پکِ لوازم', $b['message']);
$b0 = BizBundles::save($u, ['name' => 'پکِ ساده', 'items' => [$case => '1', $gl => '1']]);
T::same(400000, BizBundles::price(array_values(array_filter(BizBundles::all($u), fn($x) => $x['name'] === 'پکِ ساده'))[0]), 'بی‌قیمت = جمعِ قیمتِ فروشِ اجزا');

T::group('۲ — پخشِ قیمت');
$pk = array_values(array_filter(BizBundles::all($u), fn($x) => $x['name'] === 'پکِ لوازم'))[0];
foreach ([900000, 900001, 1000003, 7] as $tot) {
    $sp = BizBundles::split($pk, $tot);
    T::same($tot, (int)array_sum(array_map(fn($x) => (int)round($x['price'] * $x['qty']), $sp)), '⛔ جمعِ ردیف‌ها دقیقاً ' . $tot);
    T::ok(array_reduce($sp, fn($c, $x) => $c && $x['price'] >= 0 && floor($x['qty']) == $x['qty'], true), 'قیمت‌ها نامنفی و تعدادها صحیح');
}
$sp2 = BizBundles::split(['items' => [['product_id' => $gl, 'name' => 'گلس', 'qty' => 3, 'sell_price' => 1], ['product_id' => $case, 'name' => 'قاب', 'qty' => 1, 'sell_price' => 1]]], 101);
T::same(101, (int)array_sum(array_map(fn($x) => (int)round($x['price'] * $x['qty']), $sp2)), 'باقی روی ردیفِ چندتایی: دو تکه');
T::same([2.0, 1.0], [$sp2[0]['qty'], $sp2[1]['qty']], 'ردیفِ سه‌تایی به دو + یک');

T::group('۳ — فاکتورِ فروش');
$cust = (int)BizParties::save($u, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$s0 = [$stock($case), $stock($gl), $stock($ch)];
$sd = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cust, 'lines' => [['item' => 'پکِ لوازم', 'qty' => '2', 'price' => '']]]);
T::ok($sd['ok'], 'پیش‌نویس با نامِ بسته', $sd['message'] ?? '');
$inv = BizInvoices::get($u, $sd['id']);
T::same(1800000, (int)$inv['total'], 'دو بسته = ۲ × ۹۰۰ هزار');
$q = []; foreach ($inv['lines'] as $l) { $q[(int)$l['product_id']] = ($q[(int)$l['product_id']] ?? 0) + (float)$l['qty']; }
T::same([$case => 2.0, $gl => 4.0, $ch => 2.0], $q, 'اجزای واقعی با تعدادِ دو بسته');
T::ok(str_contains((string)$inv['lines'][0]['note'], 'پکِ لوازم'), 'شرحِ ردیف نامِ بسته را دارد');
T::ok(BizInvoices::issue($u, $sd['id'], ['full' => true, 'account_id' => $acc])['ok'], 'صدور');
T::same([$s0[0] - 2, $s0[1] - 4, $s0[2] - 2], [$stock($case), $stock($gl), $stock($ch)], '⛔ موجودیِ هر قلم جدا کم شد');
$sk = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cust, 'lines' => [['item' => 'pk1', 'qty' => '1', 'price' => '۸۵۰٬۰۰۰'], ['product_id' => (string)$case, 'item' => 'قاب', 'qty' => '1', 'price' => '300000']]]);
$ik = BizInvoices::get($u, $sk['id']);
T::same(1150000, (int)$ik['total'], 'با کد (بی‌حساسیت به حروف)؛ قیمتِ تایپ‌شده برنده؛ ردیفِ کالا دست‌نخورده');
T::same(4, count($ik['lines']), 'سه جزء + ردیفِ قاب');
$st = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cust, 'lines' => [['product_id' => (string)$case, 'item' => 'پکِ لوازم', 'qty' => '1', 'price' => '']]]);
T::same(3, count(BizInvoices::get($u, $st['id'])['lines']), '⛔ شناسه‌ی کهنه‌ی کالا زیرِ نامِ بسته: باز هم بسته باز می‌شود (نه شرحِ آزادِ بی‌موجودی)');
$pp = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [['item' => 'پکِ لوازم', 'qty' => '1', 'price' => '100']]]);
T::ok(!$pp['ok'] || count(BizInvoices::get($u, $pp['id'])['lines']) === 1, '⛔ فاکتورِ خرید بسته را باز نمی‌کند');
BizBundles::save($u, ['name' => 'پکِ ساده', 'items' => [$case => '1', $gl => '1'], 'inactive' => '1'], (int)$b0['id']);
$si = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cust, 'lines' => [['item' => 'پکِ ساده', 'qty' => '1', 'price' => '5']]]);
T::same(1, count(BizInvoices::get($u, $si['id'])['lines']), 'بسته‌ی غیرفعال باز نمی‌شود (شرحِ آزاد)');
$so = BizInvoices::saveDraft($o, 'sale', ['lines' => [['item' => 'پکِ لوازم', 'qty' => '1', 'price' => '5']]]);
T::same(1, count(BizInvoices::get($o, $so['id'])['lines']), '⛔ بسته‌ی فروشگاهِ دیگر نه');

T::group('۴ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, BD_PREFIX . 'a', BD_PASS);
    [, $h] = $c('store/quick-sale.php');
    T::ok(str_contains($h, 'value="پکِ لوازم"') && str_contains($h, 'data-bundle="1"'), 'datalistِ فروش بسته را پیشنهاد می‌کند');
    [, $h] = $c('store/invoice-edit.php?k=purchase');
    T::ok(!str_contains($h, 'data-bundle="1"'), 'datalistِ خرید نه');
    [$code, $h] = $c('store/bundles.php');
    T::ok($code === 200 && str_contains($h, 'پکِ لوازم') && !str_contains($h, '>A55 ·'), 'صفحه؛ گوشی در فهرستِ اجزا نیست');
    $c('store/bundles.php', ['csrf_token' => stCsrf($h), 'name' => 'پکِ فرم', 'price' => '', 'pid' => [(string)$gl, (string)$ch], 'qty' => ['1', '1']]);
    T::ok((bool)array_filter(BizBundles::all($u), fn($x) => $x['name'] === 'پکِ فرم'), 'ساخت از فرم');
}

exit(T::report());
