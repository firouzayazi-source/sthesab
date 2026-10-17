<?php
/**
 * ⛔ پیگیریِ تعمیر برای مشتری (`store/track.php`، `BizShare::repairUrl()`/`verifyRepair()`).
 *
 *   ۱. امضا: پیشوندِ جدا از فاکتور؛ فروشگاهِ دیگر، قبضِ دیگر، امضای فاکتورِ هم‌شماره و نمکِ عوض‌شده همه ۴۰۴.
 *   ۲. صفحه: مرحله و دستگاه؛ ⛔ نه نام و تلفنِ مشتری، نه IMEIِ کامل، نه یادداشتِ داخلی؛ بی‌لینک به داخل.
 *   ۳. دکمه‌ی اشتراک روی قبض.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('پیگیریِ تعمیر');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('پیگیری', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_repairs.php';
require_once __DIR__ . '/../includes/biz_comms.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('پیگیری', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizComms::ready() || !BizRepairs::ready()) { T::blocked('پیگیری', 'migration تعمیرات/ارتباط اجرا نشده'); exit(T::report()); }
const TK_PREFIX = '__store_track_';
const TK_PASS   = 'Track#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . TK_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, TK_PREFIX . $n, TK_PREFIX . $n . '@example.com', TK_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$u = $mk('a'); $o = $mk('o');
Biz::saveSettings($u, ['shop_name' => 'تعمیرگاهِ نمونه', 'phone' => '02100000000', 'address' => '', 'invoice_footer' => '']);
$IM = '353918110009871';
$r1 = BizRepairs::create($u, ['device' => 'Galaxy A54', 'imei' => $IM, 'problem' => 'شکستگیِ صفحه', 'customer_name' => 'رضا محرمانه',
                              'customer_phone' => '09121112233', 'note' => 'یادداشتِ داخلیِ مغازه', 'estimate' => '1500000']);
$r2 = BizRepairs::create($u, ['device' => 'iPhone 13', 'problem' => 'باتری', 'customer_name' => 'سارا', 'customer_phone' => '09120000000']);
$ro = BizRepairs::create($o, ['device' => 'X', 'problem' => 'y', 'customer_name' => 'z']);
T::ok($r1['ok'] && $r2['ok'] && $ro['ok'], 'قبض‌ها');
BizRepairs::setStatus($u, $r1['id'], 'waiting');

T::group('۱ — امضا');
$url = BizShare::repairUrl($u, $r1['id']);
// ⛔ فروشگاهِ دوم با **همان نمک** (پیش از اولین خواندنِ تنظیماتش)
$pdo->prepare('INSERT INTO biz_settings (user_id, comms) VALUES (:o, :c) ON DUPLICATE KEY UPDATE comms = VALUES(comms)')
    ->execute(['o' => $o, 'c' => json_encode(['share_salt' => BizComms::settings($u)['share_salt']])]);
T::ok(str_contains($url, 'store/track.php?') && str_contains($url, 'r=' . $r1['id']), 'آدرسِ عمومی');
$tok = BizShare::repairToken($u, $r1['id']);
T::ok(BizShare::verifyRepair($u, $r1['id'], $tok), 'امضای درست');
T::ok(!BizShare::verifyRepair($u, $r2['id'], $tok), '⛔ امضای قبضِ ۱ برای قبضِ ۲ نه');
T::ok(!BizShare::verifyRepair($u, $r1['id'], BizShare::token($u, $r1['id'])), '⛔ امضای فاکتورِ هم‌شناسه قبض را باز نمی‌کند');
T::ok(!BizShare::verifyRepair($o, $r1['id'], $tok), '⛔ فروشگاهِ دیگر نه');
T::same(BizComms::settings($u)['share_salt'], BizComms::settings($o)['share_salt'], '(دو فروشگاه با یک نمک)');
T::ok(!BizShare::verifyRepair($o, $r1['id'], $tok), '⛔ هم‌نمک ولی فروشگاهِ دیگر: نه (امضا به شناسه‌ی فروشگاه بسته است)');
T::ok(!BizShare::verifyRepair($u, $r1['id'], ''), 'امضای خالی نه');

T::group('۲ — صفحه');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$anon = stHttpClient($port);
$q = fn(int $s, int $r, string $t) => 'store/track.php?' . http_build_query(['s' => $s, 'r' => $r, 't' => $t]);
[$c, $h] = $anon(substr($url, strpos($url, 'store/track.php')));
T::ok($c === 200 && str_contains($h, 'تعمیرگاهِ نمونه') && str_contains($h, 'منتظرِ قطعه') && str_contains($h, 'Galaxy A54'), 'بی‌ورود باز شد: فروشگاه، مرحله، دستگاه', (string)$c);
T::same(2, substr_count($h, 'class="is-done"'), 'منتظرِ قطعه = دو مرحله‌ی اول گذشته');
T::ok(str_contains($h, '۹۸۷۱') && !str_contains($h, $IM) && !str_contains($h, toPersianDigits($IM)), '⛔ فقط چهار رقمِ آخرِ IMEI');
T::ok(!str_contains($h, 'رضا محرمانه') && !str_contains($h, '09121112233') && !str_contains($h, '۰۹۱۲۱۱۱۲۲۳۳') && !str_contains($h, 'یادداشتِ داخلیِ مغازه'), '⛔ نه نام، نه تلفنِ مشتری، نه یادداشتِ داخلی');
T::ok(str_contains($h, formatMoney(1500000)), 'برآورد');
T::ok(!str_contains($h, '/store/index.php') && !str_contains($h, 'login.php'), 'بی‌لینک به داخلِ فروشگاه');
BizRepairs::setStatus($u, $r1['id'], 'ready');
[, $h] = $anon(substr($url, strpos($url, 'store/track.php')));
T::ok(str_contains($h, 'آماده‌ی تحویل') && str_contains($h, 'برای تحویل بیایید'), 'مرحله‌ی تازه بی‌لینکِ تازه');
[$c] = $anon($q($u, $r2['id'], $tok));
T::same(404, $c, '⛔ امضای قبضِ دیگر: ۴۰۴');
[$c] = $anon($q($u, $r1['id'], BizShare::token($u, $r1['id'])));
T::same(404, $c, '⛔ امضای فاکتور: ۴۰۴');
[$c] = $anon($q($o, $ro['id'], BizShare::repairToken($u, $ro['id'])));
T::same(404, $c, '⛔ قبضِ فروشگاهِ دیگر با امضای این فروشگاه: ۴۰۴');
[$c] = $anon($q($u, $r1['id'], 'x' . substr($tok, 1)));
T::same(404, $c, 'امضای دست‌کاری‌شده: ۴۰۴');
BizComms::saveSettings($u, BizComms::settings($u), true);
[$c] = $anon(substr($url, strpos($url, 'store/track.php')));
T::same(404, $c, '⛔ «باطل کردنِ همه‌ی لینک‌ها» پیگیری را هم باطل می‌کند');

T::group('۳ — دکمه روی قبض');
$c2 = stHttpClient($port);
stHttpLogin($c2, TK_PREFIX . 'a', TK_PASS);
[, $h] = $c2('store/repair.php?id=' . $r2['id']);
T::ok(str_contains($h, 'data-share-url="') && str_contains($h, h(BizShare::repairUrl($u, $r2['id']))), 'دکمه‌ی اشتراکِ همان قبض');

exit(T::report());
