<?php
/**
 * ⛔ گارانتیِ پخش‌کننده (`BizRma`، `store/rma.php`، هشدارِ «دیرکرده» در `BizAlerts`).
 *
 *   ۱. ثبت: شرکت و قبضِ تعمیر فقط از همین فروشگاه؛ از قبض پیش‌پر می‌شود؛ شماره‌ی پیاپی.
 *   ۲. برگشت فقط از «نزدِ شرکت»؛ تعویض بی‌IMEIِ تازه نه؛ تاریخِ برگشت پیش از ارسال نه.
 *   ۳. دیرکرده بعد از `OVERDUE_DAYS`؛ جست‌وجو با IMEIِ قدیم و تازه.
 *   ۴. هیچ پول و حرکتِ انباری؛ HTTP و دسترسی.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('گارانتیِ پخش');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('گارانتیِ پخش', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_insight.php';
require_once __DIR__ . '/../includes/biz_rma.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('گارانتیِ پخش', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizRma::ready()) { T::blocked('گارانتیِ پخش', 'migration_biz_rma اجرا نشده'); exit(T::report()); }
const RM_PREFIX = '__store_rma_';
const RM_PASS   = 'Rma#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . RM_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, RM_PREFIX . $n, RM_PREFIX . $n . '@example.com', RM_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$ago = fn(int $d): string => date('Y-m-d', strtotime(today() . " -{$d} days"));
$I1 = '353918110001010'; $I2 = '353918110001028'; $I3 = '353918110001036';

$u = $mk('a'); $o = $mk('o');
$dist = (int)BizParties::save($u, ['name' => 'پخشِ سام', 'kind' => 'supplier'])['id'];
$oDist = (int)BizParties::save($o, ['name' => 'پخشِ دیگری', 'kind' => 'supplier'])['id'];
$rep = BizRepairs::create($u, ['device' => 'A54', 'imei' => $I1, 'problem' => 'صفحه خاموش', 'customer_name' => 'رضا', 'customer_phone' => '09120000000']);
T::ok($rep['ok'], 'قبضِ تعمیر', $rep['message'] ?? '');
$oRep = BizRepairs::create($o, ['device' => 'X', 'problem' => 'y', 'customer_name' => 'z']);
$moves0 = (int)$one('SELECT COUNT(*) FROM biz_stock_moves WHERE user_id = :u', ['u' => $u]);
$pays0 = (int)$one('SELECT COUNT(*) FROM biz_payments WHERE user_id = :u', ['u' => $u]);

T::group('۱ — ثبت');
$a = BizRma::create($u, ['party_id' => $dist, 'repair_id' => $rep['id'], 'tracking' => 'TR-۱۲۳', 'sent_on' => $ago(25)], $u);
T::ok($a['ok'], 'از قبضِ تعمیر', $a['message']);
$ra = BizRma::get($u, $a['id']);
T::same(['A54', $I1, 'صفحه خاموش', 'customer', 1], [$ra['device'], $ra['imei'], $ra['problem'], $ra['source'], (int)$ra['number']], 'پیش‌پر از قبض، شماره‌ی ۱');
T::same('TR-123', $ra['tracking'], 'رهگیری با ارقامِ لاتین');
$b = BizRma::create($u, ['party_id' => $dist, 'source' => 'stock', 'device' => 'S24', 'imei' => $I2, 'problem' => 'دوربین', 'sent_on' => $ago(5)], $u);
T::same(2, (int)BizRma::get($u, $b['id'])['number'], 'شماره‌ی پیاپی');
T::ok(!BizRma::create($u, ['party_id' => $oDist, 'device' => 'x', 'problem' => 'y'], $u)['ok'], '⛔ شرکتِ فروشگاهِ دیگر رد');
T::ok(!BizRma::create($u, ['party_id' => $dist, 'repair_id' => $oRep['id'], 'device' => 'x', 'problem' => 'y'], $u)['ok'], '⛔ قبضِ فروشگاهِ دیگر رد (حتی با دستگاه و ایرادِ پر)');
T::ok(!BizRma::create($u, ['party_id' => $dist, 'device' => 'x', 'problem' => 'y', 'imei' => '12'], $u)['ok'], 'IMEIِ نامعتبر رد');
T::ok(!BizRma::create($u, ['party_id' => $dist, 'device' => 'x', 'problem' => 'y', 'sent_on' => $ago(-3)], $u)['ok'], 'تاریخِ آینده رد');
T::ok(!BizRma::create($u, ['party_id' => $dist, 'device' => '', 'problem' => 'y'], $u)['ok'], 'بی‌دستگاه رد');
T::same(1, (int)BizRma::create($o, ['party_id' => $oDist, 'device' => 'x', 'problem' => 'y'], $o)['ok'] ? (int)$one('SELECT number FROM biz_rma WHERE user_id = :o', ['o' => $o]) : 0, 'شماره‌ی هر فروشگاه جدا');

T::group('۲ — دیرکرده و جست‌وجو');
T::same(['open' => 2, 'late' => 1], BizRma::counts($u), '⛔ ۲۵ روز دیرکرده، ۵ روز نه');
T::ok(BizRma::overdue($ra) && !BizRma::overdue(BizRma::get($u, $b['id'])), 'overdue()');
$edge = BizRma::create($u, ['party_id' => $dist, 'device' => 'مرز', 'problem' => 'y', 'sent_on' => $ago(BizRma::OVERDUE_DAYS)], $u);
T::same(1, BizRma::counts($u)['late'], 'مرز: درست ' . BizRma::OVERDUE_DAYS . ' روز هنوز دیر نیست');
T::ok(!BizRma::overdue(BizRma::get($u, $edge['id'])), 'و overdue() هم همان را می‌گوید');
T::same([$a['id']], array_map('intval', array_column(BizRma::list($u, 'late')['rows'], 'id')), 'صافیِ دیرکرده');
$al = array_values(array_filter(BizAlerts::all($u, true), fn($x) => $x['key'] === 'rma'))[0]['items'] ?? [];
T::ok(count($al) === 1 && str_contains($al[0]['text'], 'A54'), 'هشدار در «هشدارها و پیش‌بینی»');
T::same([$b['id']], array_map('intval', array_column(BizRma::list($u, '', toPersianDigits($I2))['rows'], 'id')), 'جست‌وجو با IMEI (ارقامِ فارسی)');

T::group('۳ — برگشت');
T::ok(!BizRma::close($u, $b['id'], 'replaced', [])['ok'], '⛔ تعویض بی‌IMEIِ تازه رد');
T::ok(!BizRma::close($u, $b['id'], 'sent', [])['ok'], '«نزدِ شرکت» نتیجه نیست');
T::ok(!BizRma::close($u, $b['id'], 'repaired', ['returned_on' => $ago(10)])['ok'], '⛔ برگشت پیش از ارسال رد');
$r = BizRma::close($u, $b['id'], 'replaced', ['new_imei' => $I3]);
T::ok($r['ok'], 'تعویض', $r['message']);
$rb = BizRma::get($u, $b['id']);
T::same(['replaced', $I3, today()], [$rb['status'], $rb['new_imei'], $rb['returned_on']], 'وضعیت، IMEIِ تازه، تاریخ');
T::ok(!BizRma::close($u, $b['id'], 'repaired', [])['ok'], '⛔ بسته‌شده دوباره بسته نمی‌شود');
T::same([$b['id']], array_map('intval', array_column(BizRma::list($u, '', $I3)['rows'], 'id')), 'جست‌وجو با IMEIِ تازه');
T::ok(!BizRma::close($o, $a['id'], 'repaired', [])['ok'], '⛔ فروشگاهِ دیگر نمی‌بندد');
BizRma::close($u, $a['id'], 'cancelled', []);
T::same(null, BizRma::get($u, $a['id'])['returned_on'], 'لغو تاریخِ برگشت ندارد');
T::same(['open' => 1, 'late' => 0], BizRma::counts($u), 'بسته‌شده‌ها از باز بیرون');
T::same([$moves0, $pays0], [(int)$one('SELECT COUNT(*) FROM biz_stock_moves WHERE user_id = :u', ['u' => $u]), (int)$one('SELECT COUNT(*) FROM biz_payments WHERE user_id = :u', ['u' => $u])], '⛔ نه حرکتِ انبار، نه پول');

T::group('۴ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, RM_PREFIX . 'a', RM_PASS);
    [$code, $h] = $c('store/rma.php?repair=' . $rep['id']);
    T::ok($code === 200 && str_contains($h, 'value="' . $I1 . '"') && str_contains($h, 'name="repair_id" value="' . $rep['id'] . '"'), 'فرم از قبضِ تعمیر پیش‌پر');
    [, $h] = $c('store/repair.php?id=' . $rep['id']);
    T::ok(str_contains($h, 'rma.php?repair=' . $rep['id']), 'دکمه روی قبضِ تعمیر');
    [, $h] = $c('store/rma.php');
    $c('store/rma.php', ['csrf_token' => stCsrf($h), 'party_id' => (string)$dist, 'device' => 'Note 13', 'imei' => '', 'problem' => 'باتری', 'sent_on' => '۱۴۰۰/۱۳/۴۰']);
    T::same(0, (int)$one("SELECT COUNT(*) FROM biz_rma WHERE user_id = :u AND device = 'Note 13'", ['u' => $u]), '⛔ تاریخِ نامعتبر = خطا، نه امروز');
    [, $h] = $c('store/rma.php');
    $c('store/rma.php', ['csrf_token' => stCsrf($h), 'party_id' => (string)$dist, 'device' => 'Note 13', 'imei' => '', 'problem' => 'باتری', 'sent_on' => toPersianDigits(BizDocView::jDate($ago(2)))]);
    T::same($ago(2), (string)$one("SELECT sent_on FROM biz_rma WHERE user_id = :u AND device = 'Note 13'", ['u' => $u]), 'ثبت از فرم با تاریخِ شمسی');
    $nid = (int)$one("SELECT id FROM biz_rma WHERE user_id = :u AND device = 'Note 13'", ['u' => $u]);
    [, $h] = $c('store/rma.php');
    $c('store/rma.php', ['csrf_token' => stCsrf($h), 'action' => 'close', 'id' => (string)$nid, 'status' => 'repaired']);
    T::same('repaired', (string)$one('SELECT status FROM biz_rma WHERE id = :i', ['i' => $nid]), 'بستن از فرم');
    $c('store/rma.php', ['csrf_token' => 'x', 'action' => 'close', 'id' => (string)$edge['id'], 'status' => 'repaired']);
    T::same('sent', (string)$one('SELECT status FROM biz_rma WHERE id = :i', ['i' => $edge['id']]), '⛔ بی‌CSRF هیچ');
    $acct = $mk('acct');
    $pdo->prepare("INSERT INTO store_staff (user_id, staff_id, role) VALUES (:o, :s, 'accountant')")->execute(['o' => $u, 's' => $acct]);
    $x = stHttpClient($port);
    stHttpLogin($x, RM_PREFIX . 'acct', RM_PASS);
    [$code, $h] = $x('store/rma.php');
    T::ok($code !== 200 || !str_contains($h, 'پخشِ سام'), '⛔ حسابدار (بی‌«تعمیرات») نمی‌بیند', (string)$code);
}

exit(T::report());
