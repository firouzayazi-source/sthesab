<?php
/**
 * ⛔ شناسه‌ی صیادِ چک (`BizCheques::sayadCheck()`/`setSayad()`/`confirmSayad()`/`unconfirmed()`).
 *
 *   ۱. ثبت با چک: دقیقاً ۱۶ رقم (ارقامِ فارسی و فاصله فهمیده می‌شوند)؛ تکراریِ همان سو رد، برگشتی دوباره پذیرفته.
 *   ۲. افزودنِ بعدی فقط به چکِ در جریان؛ «تأیید شد» فقط علامتِ دستی و فقط روی چکِ در جریانِ شناسه‌دار.
 *   ۳. هشدارِ «تأییدنشده» در `BizAlerts`؛ HTTP: فرمِ پرداخت و کارتِ چک.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('شناسه‌ی صیاد');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('صیاد', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_insight.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('صیاد', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizCheques::sayadReady()) { T::blocked('صیاد', 'migration_biz_sayad اجرا نشده'); exit(T::report()); }
const SY_PREFIX = '__store_sayad_';
const SY_PASS   = 'Sayad#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SY_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, SY_PREFIX . $n, SY_PREFIX . $n . '@example.com', SY_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$u = $mk('a'); $o = $mk('o');
$acc = (int)BizCash::list($u)[0]['id'];
$cust = (int)BizParties::save($u, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
BizPay::create($u, ['kind' => 'capital', 'amount' => '1000000', 'account_id' => $acc]);
$due = date('Y-m-d', strtotime('+10 days'));
$chq = fn(string $kind, int $party, string $say, string $amt = '1000') => BizPay::create($u, ['kind' => $kind, 'party_id' => $party, 'account_id' => $acc,
    'amount' => $amt, 'method' => 'cheque', 'cheque_due' => $due, 'cheque_no' => '1', 'cheque_sayad' => $say]);
$S1 = '1234567890123456'; $S2 = '6543210987654321';

T::group('۱ — ثبت با چک');
$a = $chq('receipt', $cust, toPersianDigits('1234 5678 9012 3456'));
T::ok($a['ok'], 'چکِ دریافتی با شناسه (ارقامِ فارسی و فاصله)', $a['message'] ?? '');
T::same([$S1, 0], [(string)$one('SELECT cheque_sayad FROM biz_payments WHERE id = :i', ['i' => $a['id']]), (int)$one('SELECT cheque_sayad_ok FROM biz_payments WHERE id = :i', ['i' => $a['id']])], 'ذخیره، تأییدنشده');
$bad = $chq('receipt', $cust, '123456789012345');
T::ok(!$bad['ok'] && str_contains($bad['message'], '۱۶ رقم'), '۱۵ رقم رد');
T::ok(!$chq('receipt', $cust, '12345678901234567')['ok'], '۱۷ رقم رد');
$n0 = (int)$one('SELECT COUNT(*) FROM biz_payments WHERE user_id = :u', ['u' => $u]);
T::ok(!$chq('receipt', $cust, $S1)['ok'], '⛔ همان شناسه دو بار دریافت نمی‌شود');
T::same($n0, (int)$one('SELECT COUNT(*) FROM biz_payments WHERE user_id = :u', ['u' => $u]), 'ردشده هیچ سندی نساخت');
T::ok($chq('payment', $sup, $S1)['ok'], 'همان شناسه در سوی دیگر (پرداختی) پذیرفته');
$e = $chq('receipt', $cust, '');
T::ok($e['ok'] && $one('SELECT cheque_sayad FROM biz_payments WHERE id = :i', ['i' => $e['id']]) === null, 'بی‌شناسه هم پذیرفته (اختیاری)');
T::ok(BizCheques::bounce($u, $a['id'])['ok'], 'برگشت خورد');
T::ok($chq('receipt', $cust, $S1)['ok'], 'چکِ برگشتی دوباره پذیرفته می‌شود');
// ⛔ برگشتیِ دوره‌ی بسته سندِ اصل را باطل نمی‌کند (سندِ معکوس می‌خورد) — باز هم باید دوباره پذیرفته شود
$S3 = '3333444455556666';
$old = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cust, 'account_id' => $acc, 'amount' => '900', 'method' => 'cheque',
    'cheque_due' => $due, 'cheque_sayad' => $S3, 'pay_date' => date('Y-m-d', strtotime('-20 days'))]);
T::ok($old['ok'], 'چکِ قدیمی', $old['message'] ?? '');
Biz::saveLock($u, date('Y-m-d', strtotime('-10 days')));
$bo = BizCheques::bounce($u, $old['id']);
T::ok($bo['ok'], 'برگشتی در دوره‌ی بسته', $bo['message'] ?? '');
T::same(['ok', 'bounced'], [(string)$one('SELECT status FROM biz_payments WHERE id = :i', ['i' => $old['id']]), (string)$one('SELECT cheque_status FROM biz_payments WHERE id = :i', ['i' => $old['id']])], '(سندِ اصل باطل نشد، فقط برگشتی)');
T::ok($chq('receipt', $cust, $S3)['ok'], '⛔ همان چکِ برگشتیِ دوره‌ی بسته دوباره پذیرفته می‌شود');
Biz::saveLock($u, '', true);
$ou = BizPay::create($o, ['kind' => 'receipt', 'party_id' => (int)BizParties::save($o, ['name' => 'م', 'kind' => 'customer'])['id'],
    'account_id' => (int)BizCash::list($o)[0]['id'], 'amount' => '5', 'method' => 'cheque', 'cheque_due' => $due, 'cheque_sayad' => $S2]);
T::ok($ou['ok'] && $chq('receipt', $cust, $S2)['ok'], 'تکراری فقط در همان فروشگاه سنجیده می‌شود');

T::group('۲ — افزودنِ بعدی و تأیید');
T::ok(!BizCheques::setSayad($u, $e['id'], '12')['ok'], 'افزودن: نامعتبر رد');
T::ok(!BizCheques::setSayad($u, $e['id'], $S2)['ok'], '⛔ افزودن: تکراری رد');
T::ok(BizCheques::setSayad($u, $e['id'], '1111222233334444')['ok'], 'افزودن به چکِ در جریان');
T::ok(!BizCheques::setSayad($o, $e['id'], '9999222233334444')['ok'], '⛔ فروشگاهِ دیگر نه');
T::ok(!BizCheques::setSayad($u, $a['id'], '5555222233334444')['ok'], '⛔ چکِ برگشتی (نه در جریان) نه');
T::ok(!BizCheques::confirmSayad($o, $e['id'])['ok'], '⛔ تأیید از فروشگاهِ دیگر نه');
T::ok(BizCheques::confirmSayad($u, $e['id'])['ok'], 'تأیید');
T::same(1, (int)$one('SELECT cheque_sayad_ok FROM biz_payments WHERE id = :i', ['i' => $e['id']]), 'علامت خورد');
BizCheques::setSayad($u, $e['id'], '1111222233335555');
T::same(0, (int)$one('SELECT cheque_sayad_ok FROM biz_payments WHERE id = :i', ['i' => $e['id']]), '⛔ شناسه‌ی تازه = دوباره تأییدنشده');
$plain = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cust, 'account_id' => $acc, 'amount' => '7']);
T::ok(!BizCheques::confirmSayad($u, $plain['id'])['ok'], 'دریافتِ نقدی تأیید ندارد');
T::ok(!BizCheques::confirmSayad($u, $a['id'])['ok'], 'چکِ برگشتی تأیید ندارد');

T::group('۳ — هشدار و HTTP');
$un = BizCheques::unconfirmed($u);
T::ok(count($un) >= 3 && !in_array($a['id'], array_map('intval', array_column($un, 'id')), true), 'تأییدنشده‌ها: فقط در جریانِ شناسه‌دار');
T::ok(!in_array($plain['id'], array_map('intval', array_column($un, 'id')), true), 'بی‌چک نه');
BizCheques::confirmSayad($u, $e['id']);
T::ok(!in_array($e['id'], array_map('intval', array_column(BizCheques::unconfirmed($u), 'id')), true), 'تأییدشده از فهرست بیرون');
$al = array_values(array_filter(BizAlerts::all($u, true), fn($x) => $x['key'] === 'sayad'))[0]['items'] ?? [];
T::same(count(BizCheques::unconfirmed($u)), count($al), 'هشدار در «هشدارها و پیش‌بینی»');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, SY_PREFIX . 'a', SY_PASS);
    [, $h] = $c('store/payment.php?k=receipt');
    T::ok(str_contains($h, 'name="cheque_sayad"'), 'فرمِ دریافت: خانه‌ی صیاد');
    $c('store/payment.php?k=receipt', ['csrf_token' => stCsrf($h), 'kind' => 'receipt', 'party_id' => (string)$cust, 'account_id' => (string)$acc,
        'amount' => '۲٬۰۰۰', 'method' => 'cheque', 'cheque_due' => BizDocView::jDate($due), 'cheque_no' => '9', 'cheque_sayad' => '7777888899990000',
        '_once' => preg_match('/name="_once" value="([^"]+)"/', $h, $m) ? $m[1] : '']);
    $nid = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND cheque_sayad = '7777888899990000'", ['u' => $u]);
    T::ok($nid > 0, 'ثبت از فرم');
    [, $h] = $c('store/cheques.php');
    T::ok(str_contains($h, '۷۷۷۷ ۸۸۸۸ ۹۹۹۹ ۰۰۰۰') && str_contains($h, 'در صیاد تأیید نشده'), 'کارتِ چک: شناسه‌ی چهاررقمی و برچسب');
    $c('store/cheques.php', ['csrf_token' => stCsrf($h), 'action' => 'sayad_ok', 'cheque_id' => (string)$nid]);
    T::same(1, (int)$one('SELECT cheque_sayad_ok FROM biz_payments WHERE id = :i', ['i' => $nid]), 'تأیید از کارت');
    [, $h] = $c('store/cheques.php');
    $c('store/cheques.php', ['csrf_token' => 'x', 'action' => 'sayad_ok', 'cheque_id' => (string)$nid, 'ok' => '0']);
    T::same(1, (int)$one('SELECT cheque_sayad_ok FROM biz_payments WHERE id = :i', ['i' => $nid]), '⛔ بی‌CSRF هیچ');
}

exit(T::report());
