<?php
/**
 * ⛔ بهای تمام‌شده و سودِ فروشگاه — «همه‌ی محاسبات درست باشد».
 *
 * خطرِ اصلی **عددِ غلطِ بی‌صدا** است، نه خطا: سودی که بیشتر از واقعیت است و
 * انباری که با جمعِ خرید نمی‌خواند. پس هر گروه یک **برابریِ حسابداری** را
 * می‌سنجد، نه فقط «اجرا شد»:
 *
 *   ۱. فروشِ تاریخ‌گذشته و خریدِ دیرثبت‌شده — بها = میانگینِ **تاریخِ فروش**.
 *   ۲. اصلاحِ بهای اول دوره بعد از فروش — به فروش‌های قبلی هم می‌رسد.
 *   ۳. برگشت از خرید — انبار همان بهایی را از دست می‌دهد که پس گرفته شد.
 *   ۴. برگشت از فروش — به بهای فروشِ اصلی، حتی وقتی آن بها بعداً اصلاح شود.
 *   ۵. گوشیِ IMEIدار — بهای خریدِ **همان** گوشی (شناساییِ ویژه).
 *   ۶. برابریِ کل: ارزشِ ورودی = بهای فروش + برگشت از خرید + کسری + ارزشِ مانده.
 *   ۷. برگشتِ چندمرحله‌ای — هیچ ریالی روی فاکتور جا نمی‌ماند.
 *   ۸. سود و زیان — کسریِ انبار و خریدِ بی‌انبار؛ داشبورد همان عدد.
 *   ۹. «برگشت به پیش‌نویس» برای اصلاح، با دریافتِ همراه و جدا.
 *  ۱۰. `rebuild()` و `user-admin.php --store-recost` روی داده‌ی قدیمی.
 *  ۱۱. جداسازیِ دو فروشگاه.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_reports.php';
require_once __DIR__ . '/../includes/biz_dash.php';

$root = dirname(__DIR__);
const CPREFIX = '__bcost_';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('بهای تمام‌شده', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_invoices') || !tableHasColumn('biz_invoice_lines', 'imei1')) {
    T::blocked('بهای تمام‌شده', 'جدول‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . CPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . CPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, CPREFIX . $name, CPREFIX . $name . '@example.com', 'Cost12345');
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => CPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');

$acc = function (int $u): int {
    $l = BizCash::list($u);
    if (!$l) { BizCash::save($u, ['name' => 'صندوق', 'kind' => 'cash']); $l = BizCash::list($u); }
    return (int)$l[0]['id'];
};
$A_ACC = $acc($a);
$party = fn(int $u, string $n): int => (int)BizParties::save($u, ['name' => $n, 'kind' => 'both'])['id'];
$CUST = $party($a, 'مشتریِ بها');
$prod = function (int $u, string $name, string $type = 'goods'): int {
    $r = BizProducts::save($u, ['name' => $name, 'unit' => $type === 'phone' ? 'دستگاه' : 'عدد', 'type' => $type]);
    if (!$r['ok']) { throw new RuntimeException($r['message']); }
    return (int)$r['id'];
};
$line = fn(int $u, int $pid, $q, $price, string $i1 = '') =>
    ['product_id' => $pid, 'item' => BizProducts::get($u, $pid)['name'], 'qty' => (string)$q, 'price' => (string)$price, 'imei1' => $i1];
/** فاکتور بساز و صادر کن؛ شکست = استثنا تا تست با پیامِ روشن بایستد. */
$doc = function (int $u, string $kind, string $date, array $lines, array $head = [], array $pay = []) {
    $r = BizInvoices::saveDraft($u, $kind, $head + ['inv_date' => $date, 'lines' => $lines]);
    if (!$r['ok']) { throw new RuntimeException('پیش‌نویس: ' . $r['message']); }
    $i = BizInvoices::issue($u, (int)$r['id'], $pay);
    if (!$i['ok']) { throw new RuntimeException('صدور: ' . $i['message']); }
    return (int)$r['id'];
};
$costs = fn(int $u, int $inv): array => array_map(fn($l) => $l['unit_cost'] === null ? null : (int)$l['unit_cost'], BizInvoices::get($u, $inv)['lines']);
$p = fn(int $u, int $pid): array => BizProducts::get($u, $pid);
$ret = function (int $u, int $inv, array $qtyByLineNo, string $date, array $pay = []) {
    $orig = BizInvoices::get($u, $inv);
    $q = [];
    foreach ($qtyByLineNo as $n => $v) { $q[(int)$orig['lines'][$n]['id']] = (string)$v; }
    $r = BizInvoices::createReturn($u, $inv, $q, $pay, $date);
    if (!$r['ok']) { throw new RuntimeException('برگشت: ' . $r['message']); }
    return (int)$r['id'];
};
/**
 * ⛔ برابریِ کلِ یک کالا از خودِ حرکت‌ها و ردیف‌ها (نه از `recalc()`):
 *    ارزشِ ورودی − ارزشِ خروجی = ارزشِ مانده (موجودی × میانگین).
 *    هر حرکت ارزشِ ثبت‌شده‌ی خودش را دارد (`unit_cost`)، پس این سنجه
 *    مستقل از الگوریتمِ پیمایش است. خطای مجاز = گردِ هر حرکت (۱ تومان).
 */
$identity = function (int $u, int $pid) use ($pdo, $p): array {
    $st = $pdo->prepare('SELECT qty, unit_cost FROM biz_stock_moves WHERE user_id = :u AND product_id = :p');
    $st->execute(['u' => $u, 'p' => $pid]);
    $flow = 0.0; $units = 0.0;
    foreach ($st->fetchAll() as $m) { $flow += (float)$m['qty'] * (float)$m['unit_cost']; $units += abs((float)$m['qty']); }
    $row = $p($u, $pid);
    $value = (float)$row['stock_qty'] * (float)$row['avg_cost'];
    // گردِ مجاز: نیم تومان به‌ازای هر واحدِ جابه‌جاشده (بهای ردیف عددِ صحیح است)
    return ['flow' => round($flow), 'value' => round($value), 'ok' => abs($flow - $value) <= $units * 0.5 + 1];
};

try {

/* ---------------------------------------------------------------- */
T::group('۱ — فروشِ تاریخ‌گذشته: بها = میانگینِ تاریخِ فروش، نه امروز');
$P1 = $prod($a, 'کابلِ تاریخ');
$doc($a, 'purchase', '2026-01-01', [$line($a, $P1, 10, 100)], ['party_id' => $CUST]);
$doc($a, 'purchase', '2026-01-05', [$line($a, $P1, 10, 200)], ['party_id' => $CUST]);
$S1 = $doc($a, 'sale', '2026-01-03', [$line($a, $P1, 5, 500)], ['party_id' => $CUST]);
T::same([100], $costs($a, $S1), '⛔ فروشِ ۳ دی پیش از خریدِ ۵ دی: بها ۱۰۰ (نه میانگینِ امروز ۱۵۰)');
T::same(166.67, (float)$p($a, $P1)['avg_cost'], 'میانگینِ امروز: (۵×۱۰۰ + ۱۰×۲۰۰) ÷ ۱۵');
$mv = $pdo->prepare("SELECT unit_cost FROM biz_stock_moves WHERE user_id = :u AND ref_id = :r AND kind = 'sale'");
$mv->execute(['u' => $a, 'r' => $S1]);
T::same(100, (int)$mv->fetchColumn(), 'حرکتِ انبارِ همان فروش هم همان بها را دارد (کاردکس با فاکتور یکی است)');

// خریدی که دیرتر ثبت شد ولی تاریخش پیش از فروش است، بهای آن فروش را عوض می‌کند
$doc($a, 'purchase', '2026-01-02', [$line($a, $P1, 10, 400)], ['party_id' => $CUST]);
T::same([250], $costs($a, $S1), '⛔ خریدِ دیرثبت‌شده با تاریخِ ۲ دی: بهای فروشِ ۳ دی (۱۰×۱۰۰ + ۱۰×۴۰۰) ÷ ۲۰ = ۲۵۰ شد');
$id1 = $identity($a, $P1);
T::ok($id1['ok'], '⛔ برابری: ارزشِ ورودی − بهای فروش = ارزشِ مانده', "flow={$id1['flow']} value={$id1['value']}");
$rep = BizReports::sales($a, '2026-01-01', '2026-01-31');
T::same(5 * 250, $rep['cogs'], 'گزارشِ سود همان بهای اصلاح‌شده را می‌خواند');
T::same(BizInvoices::profit(BizInvoices::get($a, $S1)), $rep['gross'], 'سودِ صفحه‌ی فاکتور = سودِ گزارش');

/* ---------------------------------------------------------------- */
T::group('۲ — اصلاحِ بهای اول دوره بعد از فروش');
$P2 = $prod($a, 'قابِ بی‌بها');
BizStock::setOpening($a, $P2, 10, 0);
$S2 = $doc($a, 'sale', '2026-02-01', [$line($a, $P2, 4, 500)], ['party_id' => $CUST]);
T::same([0], $costs($a, $S2), 'اول دوره بی‌بها: فروش با بهای صفر (سود = کلِ فروش)');
$r = BizStock::setOpening($a, $P2, 10, 120);
T::ok($r['ok'], 'بهای اول دوره بعد از فروش اصلاح‌شدنی است');
T::same([120], $costs($a, $S2), '⛔ اصلاحِ بهای اول دوره به فروشِ قبلی هم رسید (۰ → ۱۲۰)');
T::same(4 * 120, BizReports::sales($a, '2026-02-01', '2026-02-01')['cogs'], 'و سودِ آن روز هم درست شد');
T::same(120.0, (float)$p($a, $P2)['avg_cost'], 'میانگینِ مانده‌ی ۶ تا هم ۱۲۰');
$r = BizStock::setOpening($a, $P2, 3, 120);
T::ok(!$r['ok'] && (float)$p($a, $P2)['stock_qty'] === 6.0, 'کم کردنِ اول دوره زیرِ فروخته‌شده هنوز رد می‌شود (سدِ منفی سرِ جایش است)');

/* ---------------------------------------------------------------- */
T::group('۳ — برگشت از خرید: انبار همان بها را از دست می‌دهد');
$P3 = $prod($a, 'هندزفریِ برگشتی');
$doc($a, 'purchase', '2026-03-01', [$line($a, $P3, 10, 100)], ['party_id' => $CUST]);
$B3 = $doc($a, 'purchase', '2026-03-02', [$line($a, $P3, 10, 200)], ['party_id' => $CUST]);
$R3 = $ret($a, $B3, [0 => 5], '2026-03-03');
$row = $p($a, $P3);
T::same(15.0, (float)$row['stock_qty'], 'موجودی ۲۰ − ۵');
T::same(133.33, (float)$row['avg_cost'], '⛔ میانگینِ مانده (۳۰۰۰ − ۵×۲۰۰) ÷ ۱۵ = ۱۳۳٫۳۳ — نه ۱۵۰ (ارزشِ ۲۵۰ تومان شبح)');
T::same(1000, (int)BizInvoices::get($a, $R3)['total'], 'مبلغِ برگشت همان ۵ × ۲۰۰');
$id3 = $identity($a, $P3);
T::ok($id3['ok'], '⛔ برابری بعد از برگشت از خرید', "flow={$id3['flow']} value={$id3['value']}");
$S3 = $doc($a, 'sale', '2026-03-04', [$line($a, $P3, 15, 300)], ['party_id' => $CUST]);
T::same([133], $costs($a, $S3), 'فروشِ بعدی با همان میانگینِ درست');
T::same(0.0, (float)$p($a, $P3)['stock_qty'], 'انبار خالی');
$id3 = $identity($a, $P3);
T::ok($id3['ok'] && abs($id3['flow']) <= 8, '⛔ انبارِ خالی بی‌ارزشِ شبح (فقط گردِ تومانی)', "flow={$id3['flow']}");

/* ---------------------------------------------------------------- */
T::group('۴ — برگشت از فروش به بهای فروشِ اصلی، حتی بعد از اصلاح');
$P4 = $prod($a, 'شارژرِ برگشتی');
$doc($a, 'purchase', '2026-04-01', [$line($a, $P4, 10, 100)], ['party_id' => $CUST]);
$S4 = $doc($a, 'sale', '2026-04-05', [$line($a, $P4, 4, 300)], ['party_id' => $CUST]);
$R4 = $ret($a, $S4, [0 => 2], '2026-04-06');
T::same([100], $costs($a, $R4), 'برگشت با بهای فروشِ اصلی');
$doc($a, 'purchase', '2026-04-02', [$line($a, $P4, 10, 300)], ['party_id' => $CUST]);    // دیرثبت، پیش از فروش
T::same([200], $costs($a, $S4), 'خریدِ دیرثبت بهای فروش را ۲۰۰ کرد');
T::same([200], $costs($a, $R4), '⛔ و برگشتِ همان فروش هم با آن ۲۰۰ شد (سودِ برگشت دقیقاً سودِ فروش را خنثی می‌کند)');
$rp = BizReports::sales($a, '2026-04-01', '2026-04-30');
T::same(2 * 300 - 2 * 200, $rp['gross'], 'سودِ خالصِ برگشت: ۲ تای مانده × (۳۰۰ − ۲۰۰)');
$id4 = $identity($a, $P4);
T::ok($id4['ok'], '⛔ برابری با برگشت از فروش', "flow={$id4['flow']} value={$id4['value']}");

/* ---------------------------------------------------------------- */
T::group('۵ — گوشیِ IMEIدار: بهای خریدِ همان گوشی');
$PH = $prod($a, 'آیفونِ کارکرده', 'phone');
$I1 = '356938035643801'; $I2 = '356938035643802'; $I3 = '356938035643803';
$doc($a, 'purchase', '2026-05-01', [$line($a, $PH, 1, 10000, $I1), $line($a, $PH, 1, 16000, $I2)], ['party_id' => $CUST]);
T::same(13000.0, (float)$p($a, $PH)['avg_cost'], 'میانگینِ دو گوشی ۱۳٬۰۰۰');
$S5 = $doc($a, 'sale', '2026-05-02', [$line($a, $PH, 1, 18000, $I2)], ['party_id' => $CUST]);
T::same([16000], $costs($a, $S5), '⛔ گوشیِ ۱۶ میلیونی با بهای خودش فروخته شد (نه میانگینِ ۱۳)');
T::same(2000, BizInvoices::profit(BizInvoices::get($a, $S5)), 'سودِ واقعیِ همان گوشی: ۲٬۰۰۰ (نه ۵٬۰۰۰)');
T::same(10000.0, (float)$p($a, $PH)['avg_cost'], 'گوشیِ مانده با بهای خودش (۱۰٬۰۰۰)');
$R5 = $ret($a, $S5, [0 => 1], '2026-05-03');
T::same([16000], $costs($a, $R5), 'برگشتِ همان گوشی با بهای خودش');
T::same(13000.0, (float)$p($a, $PH)['avg_cost'], 'و دوباره در انبار با همان بها');
$S5b = $doc($a, 'sale', '2026-05-04', [$line($a, $PH, 1, 12000, $I1)], ['party_id' => $CUST]);
T::same([10000], $costs($a, $S5b), 'گوشیِ ارزان با بهای خودش');
// گوشیِ بی‌سابقه (موجودیِ اول دوره، بی‌IMEI) از «بقیه‌ی انبار» بها می‌گیرد
$PH2 = $prod($a, 'سامسونگِ کارکرده', 'phone');
BizStock::setOpening($a, $PH2, 2, 5000);
$doc($a, 'purchase', '2026-05-05', [$line($a, $PH2, 1, 20000, $I3)], ['party_id' => $CUST]);
$S5c = $doc($a, 'sale', '2026-05-06', [$line($a, $PH2, 1, 9000, '356938035643899')], ['party_id' => $CUST]);
T::same([5000], $costs($a, $S5c), '⛔ گوشیِ بی‌سابقه (از اول دوره) با بهای اول دوره، نه میانگینِ ۱۰٬۰۰۰ که گوشیِ گران را هم دارد');
$S5d = $doc($a, 'sale', '2026-05-07', [$line($a, $PH2, 1, 25000, $I3)], ['party_id' => $CUST]);
T::same([20000], $costs($a, $S5d), 'گوشیِ IMEIدار همچنان با بهای خودش');
T::same(5000.0, (float)$p($a, $PH2)['avg_cost'], 'آخرین گوشیِ اول دوره با بهای خودش ماند');
// کسریِ انبارگردانی روی همان مدل: گوشیِ گمشده بی‌IMEI است، پس از «بقیه‌ی انبار»
$PH3 = $prod($a, 'شیائومیِ شمارشی', 'phone');
BizStock::setOpening($a, $PH3, 2, 5000);
$doc($a, 'purchase', '2026-05-05', [$line($a, $PH3, 1, 20000, '356938035643804')], ['party_id' => $CUST]);
BizStock::adjustTo($a, $PH3, 2, 'یک گوشی کم است');
$mv = $pdo->prepare("SELECT unit_cost FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND kind = 'adjust'");
$mv->execute(['u' => $a, 'p' => $PH3]);
T::same(5000, (int)$mv->fetchColumn(), '⛔ کسریِ یک گوشیِ بی‌IMEI به بهای اول دوره (۵٬۰۰۰)، نه میانگینِ ۱۰٬۰۰۰');
$S5e = $doc($a, 'sale', today(), [$line($a, $PH3, 1, 9000, '356938035643898')], ['party_id' => $CUST]);
T::same([5000], $costs($a, $S5e), 'گوشیِ بی‌سابقه‌ی باقیمانده هنوز ۵٬۰۰۰ (نه صفر)');
foreach ([$PH, $PH2, $PH3] as $pid) {
    $idp = $identity($a, $pid);
    T::ok($idp['ok'], '⛔ برابری برای گوشی‌ها', "flow={$idp['flow']} value={$idp['value']}");
}

/* ---------------------------------------------------------------- */
T::group('۶ — انبارگردانی: کسری به میانگین، و در سود و زیان');
$P6 = $prod($a, 'گلسِ شمارشی');
$doc($a, 'purchase', '2026-06-01', [$line($a, $P6, 10, 100)], ['party_id' => $CUST]);
$before = BizReports::sales($a, today(), today());
$r = BizStock::adjustTo($a, $P6, 7, 'شمارش');
T::ok($r['ok'], 'انبارگردانی ثبت شد');
$mv = $pdo->prepare("SELECT unit_cost FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND kind = 'adjust'");
$mv->execute(['u' => $a, 'p' => $P6]);
T::same(100, (int)$mv->fetchColumn(), '⛔ حرکتِ کسری ارزشِ خودش را دارد (۱۰۰ = میانگین)');
$after = BizReports::sales($a, today(), today());
T::same(300, $after['shrink'] - $before['shrink'], '⛔ کسریِ ۳ تا × ۱۰۰ = ۳۰۰ در سود و زیان');
T::same(BizReports::profit($after['gross'], $after['other'], 0, 0), $after['gross'] - $after['other'], 'سودِ خالص کسری را کم می‌کند');
BizStock::adjustTo($a, $P6, 8, 'پیدا شد');
T::same(200, BizReports::sales($a, today(), today())['shrink'] - $before['shrink'], 'اضافه‌ی ۱ تا کسری را کم می‌کند');
$id6 = $identity($a, $P6);
T::ok($id6['ok'], '⛔ برابری با انبارگردانی', "flow={$id6['flow']} value={$id6['value']}");

/* ---------------------------------------------------------------- */
T::group('۷ — خریدِ بی‌انبار (خدمت و شرحِ آزاد) هزینه است');
$SV = $prod($a, 'تعمیرِ بیرونی', 'service');
$B7 = $doc($a, 'purchase', '2026-07-01', [
    ['item' => 'کرایه‌ی پیک', 'qty' => '1', 'price' => '700'],
    $line($a, $SV, 1, 300),
    $line($a, $P6, 1, 100),
], ['party_id' => $CUST]);
$r7 = BizReports::sales($a, '2026-07-01', '2026-07-01');
T::same(1000, $r7['nonstock'], '⛔ ۷۰۰ + ۳۰۰ خریدِ بی‌انبار در سود و زیان؛ کالای انباری نه');
$ret($a, $B7, [0 => 1], '2026-07-02');
T::same(300, BizReports::sales($a, '2026-07-01', '2026-07-02')['nonstock'], 'برگشتِ همان خرید از آن کم می‌شود');
// ⚠ کلِ زمان: انبارگردانیِ گروهِ ۶ تاریخِ امروز را دارد، نه ۲۰۲۶
$daily = BizDash::salesDaily($a, BizReports::ALL_FROM, BizReports::ALL_TO);
$all7  = BizReports::sales($a, BizReports::ALL_FROM, BizReports::ALL_TO);
T::ok($all7['shrink'] !== 0 && $all7['nonstock'] !== 0, 'بازه هر دو جزءِ `other` را دارد');
T::same($all7['other'], array_sum(array_column($daily, 'other')), '⛔ سریِ روزانه‌ی داشبورد همان `other`ِ گزارش را دارد');
T::same($all7['cogs'], array_sum(array_column($daily, 'cost')), 'و همان بهای تمام‌شده');

/* ---------------------------------------------------------------- */
T::group('۸ — برگشتِ چندمرحله‌ای: هیچ تومانی جا نمی‌ماند');
$P8 = $prod($a, 'پاوربانکِ تخفیفی');
$doc($a, 'purchase', '2026-08-01', [$line($a, $P8, 3, 10)], ['party_id' => $CUST]);
$S8 = $doc($a, 'sale', '2026-08-02', [$line($a, $P8, 3, 100)], ['party_id' => $CUST, 'discount' => '200']);
T::same(100, (int)BizInvoices::get($a, $S8)['total'], 'فاکتورِ ۳ تایی با جمعِ ۱۰۰');
$tot = 0;
foreach ([1, 1, 1] as $k) { $tot += (int)BizInvoices::get($a, $ret($a, $S8, [0 => $k], '2026-08-03'))['total']; }
T::same(100, $tot, '⛔ سه برگشتِ تک‌تایی دقیقاً ۱۰۰ (نه ۹۹)');
$inv8 = BizInvoices::get($a, $S8);
T::same('paid', BizInvoices::state($inv8), 'فاکتورِ کاملاً برگشته «تسویه» است، نه یک تومانِ باز');
$S8b = $doc($a, 'sale', '2026-08-04', [$line($a, $P8, 3, 100)], ['party_id' => $CUST, 'discount' => '200']);
$t2 = (int)BizInvoices::get($a, $ret($a, $S8b, [0 => 2], '2026-08-05'))['total'] + (int)BizInvoices::get($a, $ret($a, $S8b, [0 => 1], '2026-08-05'))['total'];
T::same(100, $t2, 'دو برگشت (۲ + ۱) هم دقیقاً ۱۰۰');

/* ---------------------------------------------------------------- */
T::group('۹ — «برگشت به پیش‌نویس» برای اصلاح، با دریافت');
$cashOf = function (int $u, int $accId): int {
    foreach (BizCash::list($u) as $x) { if ((int)$x['id'] === $accId) { return (int)$x['balance']; } }
    return 0;
};
$P9 = $prod($a, 'اسپیکرِ اصلاحی');
BizStock::setOpening($a, $P9, 10, 50);
$CUST9 = $party($a, 'مشتریِ اصلاح');
$cash0 = $cashOf($a, $A_ACC);
$S9 = $doc($a, 'sale', today(), [$line($a, $P9, 2, 100)], ['party_id' => $CUST9], ['account_id' => $A_ACC, 'full' => 1]);
T::same($cash0 + 200, $cashOf($a, $A_ACC), 'دریافتِ همراهِ فاکتور در صندوق');
$r = BizInvoices::unissue($a, $S9);
T::ok($r['ok'], '⛔ فاکتورِ نقدی برای اصلاح به پیش‌نویس برمی‌گردد', $r['message']);
T::ok(str_contains($r['message'], 'باطل'), 'پیام می‌گوید دریافتِ همراه باطل شد');
T::same($cash0, $cashOf($a, $A_ACC), 'پولِ آن دریافت از صندوق بیرون رفت (دو بار شمرده نمی‌شود)');
T::same(10.0, (float)$p($a, $P9)['stock_qty'], 'موجودی برگشت');
T::same(0, (int)BizParties::get($a, $CUST9)['balance'], 'مانده‌ی مشتری صفر');
$e = BizInvoices::saveDraft($a, 'sale', ['party_id' => $CUST9, 'inv_date' => today(), 'lines' => [$line($a, $P9, 2, 90)]], $S9);
T::ok($e['ok'], 'پیش‌نویس اصلاح شد (فی ۹۰)');
$i = BizInvoices::issue($a, $S9, ['account_id' => $A_ACC, 'full' => 1]);
$inv9 = BizInvoices::get($a, $S9);
T::ok($i['ok'] && (int)$inv9['total'] === 180 && BizInvoices::state($inv9) === 'paid', 'صدورِ دوباره با همان شماره و دریافتِ تازه', $i['message']);
T::same($cash0 + 180, $cashOf($a, $A_ACC), 'صندوق فقط دریافتِ تازه را دارد');

// دریافتِ جدا: روی حساب می‌ماند و با صدورِ دوباره برمی‌گردد
$S9b = $doc($a, 'sale', today(), [$line($a, $P9, 1, 100)], ['party_id' => $CUST9]);
$rc = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST9, 'account_id' => $A_ACC, 'amount' => '100', 'invoice_id' => $S9b]);
T::same('paid', BizInvoices::state(BizInvoices::get($a, $S9b)), 'دریافتِ جدا فاکتور را تسویه کرد');
$r = BizInvoices::unissue($a, $S9b);
T::ok($r['ok'], '⛔ با دریافتِ جدا هم برای اصلاح برمی‌گردد', $r['message']);
$rcp = BizPay::get($a, (int)$rc['id']);
T::ok($rcp['status'] === 'ok' && (int)$rcp['allocated'] === 0, '⛔ دریافتِ جدا باطل نشد — پیش‌پرداخت روی حسابِ مشتری');
T::same(-100, (int)BizParties::get($a, $CUST9)['balance'], 'مشتری ۱۰۰ بستانکار است (پول از دست نرفت)');
BizInvoices::issue($a, $S9b);
T::same('paid', BizInvoices::state(BizInvoices::get($a, $S9b)), 'صدورِ دوباره: همان دریافت دوباره به همین فاکتور خورد');
T::same(0, (int)BizParties::get($a, $CUST9)['balance'], 'مانده دوباره صفر');

// گذری با پولِ جدا: بسته
$S9c = $doc($a, 'sale', today(), [$line($a, $P9, 1, 100)], [], ['account_id' => $A_ACC, 'full' => 1]);
$r = BizInvoices::unissue($a, $S9c);
T::ok($r['ok'], 'فاکتورِ گذری با فقط دریافتِ همراه برمی‌گردد');
BizInvoices::issue($a, $S9c, ['account_id' => $A_ACC, 'full' => 1]);
// ⚠ پولِ جدا روی فاکتورِ گذری حالا ساخته نمی‌شود (سقفِ «جمع − دریافت‌ها» در
//   `createTx()`)؛ داده‌ی قدیمی‌ای که پیش از آن سقف ساخته شده هنوز ممکن است
$x = BizPay::create($a, ['kind' => 'receipt', 'account_id' => $A_ACC, 'amount' => '10', 'invoice_id' => $S9c]);
T::ok(!$x['ok'], '⛔ فاکتورِ گذریِ تسویه‌شده دریافتِ اضافه نمی‌پذیرد', $x['message']);
$pdo->prepare("INSERT INTO biz_payments (user_id, kind, number, account_id, invoice_id, amount, pay_date, method)
               VALUES (:u, 'receipt', 9001, :a, :i, 10, CURDATE(), 'cash')")->execute(['u' => $a, 'a' => $A_ACC, 'i' => $S9c]);
$r = BizInvoices::unissue($a, $S9c);
T::ok(!$r['ok'] && str_contains($r['message'], 'گذری'), '⛔ گذری با پولِ جدا برنمی‌گردد (صدورِ دوباره پول را دو بار می‌آورد)', $r['message']);
// برگشت‌دار: بسته
$S9d = $doc($a, 'sale', today(), [$line($a, $P9, 2, 100)], ['party_id' => $CUST9]);
$ret($a, $S9d, [0 => 1], today());
$r = BizInvoices::unissue($a, $S9d);
T::ok(!$r['ok'], 'فاکتورِ برگشت‌دار برنمی‌گردد');

/* ---------------------------------------------------------------- */
T::group('۱۰ — `rebuild()` و `--store-recost` روی داده‌ی قدیمی');
// داده‌ی پیش از این قابلیت: بهای منجمدِ غلط روی ردیف و حرکت، و انبارگردانیِ بی‌بها
$pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = 999 WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $S1, 'u' => $a]);
$pdo->prepare("UPDATE biz_stock_moves SET unit_cost = 999 WHERE ref_id = :i AND user_id = :u AND kind = 'sale'")->execute(['i' => $S1, 'u' => $a]);
$pdo->prepare("UPDATE biz_stock_moves SET unit_cost = NULL WHERE product_id = :p AND user_id = :u AND kind = 'adjust'")->execute(['p' => $P6, 'u' => $a]);
$stockBefore = $pdo->query("SELECT GROUP_CONCAT(id, ':', stock_qty ORDER BY id) FROM biz_products WHERE user_id = {$a}")->fetchColumn();
$totBefore   = $pdo->query("SELECT GROUP_CONCAT(id, ':', total, ':', paid ORDER BY id) FROM biz_invoices WHERE user_id = {$a}")->fetchColumn();
$rb = BizStock::rebuild($a);
T::ok($rb['failed'] === [] && $rb['lines'] >= 1, 'rebuild بی‌خطا، ردیفِ غلط را پیدا کرد', json_encode($rb, JSON_UNESCAPED_UNICODE));
T::same([250], $costs($a, $S1), '⛔ بهای منجمدِ غلط درست شد');
$mv = $pdo->prepare("SELECT unit_cost FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND kind = 'adjust' ORDER BY id LIMIT 1");
$mv->execute(['u' => $a, 'p' => $P6]);
T::same(100, (int)$mv->fetchColumn(), 'انبارگردانیِ بی‌بها ارزش گرفت');
T::same($stockBefore, $pdo->query("SELECT GROUP_CONCAT(id, ':', stock_qty ORDER BY id) FROM biz_products WHERE user_id = {$a}")->fetchColumn(), '⛔ rebuild موجودی را دست نزد');
T::same($totBefore, $pdo->query("SELECT GROUP_CONCAT(id, ':', total, ':', paid ORDER BY id) FROM biz_invoices WHERE user_id = {$a}")->fetchColumn(), '⛔ rebuild مبلغ و تسویه‌ی هیچ فاکتوری را دست نزد');
$rb2 = BizStock::rebuild($a);
T::ok($rb2['lines'] === 0 && $rb2['failed'] === [], 'اجرای دوم: هیچ ردیفی عوض نشد (ایدمپوتنت)', json_encode($rb2, JSON_UNESCAPED_UNICODE));

// ردیفِ بی‌حرکت (داده‌ی ناجور): فاکتورِ فروشِ دوردیفی که یکی از دو حرکتش نیست.
// جفت کردنِ k-امین حرکت با k-امین ردیف آنجا حدس است، پس آن سند دست نمی‌خورد.
$PHX = $prod($a, 'گوشیِ ناجور', 'phone');
$doc($a, 'purchase', '2026-09-01', [$line($a, $PHX, 1, 100, '356938035643811'), $line($a, $PHX, 1, 900, '356938035643812')], ['party_id' => $CUST]);
$SX = $doc($a, 'sale', '2026-09-02', [$line($a, $PHX, 1, 1000, '356938035643811'), $line($a, $PHX, 1, 1000, '356938035643812')], ['party_id' => $CUST]);
T::same([100, 900], $costs($a, $SX), 'دو گوشی در یک فاکتور، هر کدام با بهای خودش');
$pdo->prepare("DELETE FROM biz_stock_moves WHERE user_id = :u AND ref_id = :i AND kind = 'sale' ORDER BY id DESC LIMIT 1")->execute(['u' => $a, 'i' => $SX]);
$pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = 777 WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $SX, 'u' => $a]);
$rb = BizStock::rebuild($a);
T::ok($rb['failed'] === [], 'داده‌ی ناجور rebuild را نمی‌خواباند', json_encode($rb, JSON_UNESCAPED_UNICODE));
T::same([777, 777], $costs($a, $SX), 'سندی که شمارِ ردیف و حرکتش نمی‌خواند حدس زده نمی‌شود — بهایش دست نخورد');

// خطِ فرمان، روی همان فروشگاه
$pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = 999 WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $S2, 'u' => $a]);
$cmd = 'php ' . escapeshellarg($root . '/deploy/user-admin.php') . ' --store-recost ' . escapeshellarg(CPREFIX . 'a') . ' 2>&1';
exec($cmd, $outL, $rc);
T::ok($rc === 0 && str_contains(implode("\n", $outL), 'بازسازی شد'), '⛔ `user-admin.php --store-recost ali` کار می‌کند', implode(' | ', $outL));
T::same([120], $costs($a, $S2), 'و همان ردیف را درست کرد');

/* ---------------------------------------------------------------- */
T::group('۱۰ب — سرعت: صدورِ یک فاکتور فاکتورهای قبلیِ مشتری را بازنویسی نمی‌کند');
// ⛔ `reallocateTx()` همه‌چیز را در حافظه از نو می‌سازد ولی فقط تغییر را می‌نویسد.
//    نسخه‌ی قبلی `paid`ِ همه‌ی فاکتورهای طرف‌حساب را با هر صدور دوباره می‌نوشت
//    (مشتریِ ثابت با ۱۵۰۰ فاکتور: ۱۳۵ ms برای ثبتِ یک فاکتور). شمارِ UPDATEِ
//    همین نشست سنجیده می‌شود، نه زمان (زمان روی ماشینِ تست بی‌ثبات است).
$P10 = $prod($a, 'کالای پرکار');
BizStock::setOpening($a, $P10, 1000, 10);
$CUST10 = $party($a, 'مشتریِ ثابت');
for ($k = 0; $k < 25; $k++) { $doc($a, 'sale', '2026-09-10', [$line($a, $P10, 1, 20)], ['party_id' => $CUST10]); }
// ده دریافت که ده فاکتورِ اول را تسویه کرده‌اند — تخصیص‌ها و `allocated`شان هم نباید بازنویسی شوند
for ($k = 0; $k < 10; $k++) { BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST10, 'account_id' => $A_ACC, 'amount' => '20', 'pay_date' => '2026-09-10']); }
$upd = fn(): int => (int)$pdo->query("SELECT SUM(VARIABLE_VALUE) FROM information_schema.SESSION_STATUS
                                       WHERE VARIABLE_NAME IN ('COM_UPDATE','COM_INSERT','COM_DELETE')")->fetchColumn();
$d10 = BizInvoices::saveDraft($a, 'sale', ['party_id' => $CUST10, 'inv_date' => '2026-09-11', 'lines' => [$line($a, $P10, 1, 20)]]);
$u0 = $upd();
BizInvoices::issue($a, (int)$d10['id']);
$n10 = $upd() - $u0;
T::ok($n10 <= 10, '⛔ صدورِ فاکتورِ نسیه‌ی بیست‌وششم فقط چند نوشتن (نه یکی برای هر فاکتور، دریافت یا تخصیصِ قبلی)', "{$n10} UPDATE/INSERT/DELETE");
$rc10 = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST10, 'account_id' => $A_ACC, 'amount' => '30']);
$st10 = $pdo->prepare("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND party_id = :p AND status = 'issued' AND paid > 0");
$st10->execute(['u' => $a, 'p' => $CUST10]);
T::ok($rc10['ok'] && (int)$st10->fetchColumn() === 12, 'و تخصیص هنوز درست است: ده فاکتورِ اول + ۳۰ = یازدهمی کامل و نصفِ دوازدهمی (FIFO)');

/* ---------------------------------------------------------------- */
T::group('۱۱ — جداسازی: بهای فروشگاهِ دیگر دست نمی‌خورد');
$PB = $prod($b, 'کالای B');
$doc($b, 'purchase', '2026-01-01', [$line($b, $PB, 2, 50)], ['party_id' => $party($b, 'طرفِ B')]);
$SB = $doc($b, 'sale', '2026-01-02', [$line($b, $PB, 1, 80)], ['party_id' => $party($b, 'مشتریِ B')]);
$pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = 1 WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $SB, 'u' => $b]);
BizStock::rebuild($a);
T::same([1], $costs($b, $SB), '⛔ rebuild ِ A ردیفِ B را دست نزد');
$rbB = BizStock::rebuild($b);
T::ok($rbB['lines'] === 1 && $rbB['failed'] === [], 'rebuild ِ B فقط ردیفِ خودش را درست کرد', json_encode($rbB, JSON_UNESCAPED_UNICODE));
T::same([50], $costs($b, $SB), 'بهای B درست شد');

} catch (Throwable $e) {
    T::ok(false, 'استثنا', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$wipe();
exit(T::report());
