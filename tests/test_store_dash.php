<?php
/**
 * ⛔ عددهای داشبوردِ فروشگاه (`BizDash`, `BizChart`).
 *
 * داشبورد جایی است که صاحبِ مغازه «در پنج ثانیه» تصمیم می‌گیرد، پس
 * خطرهایش بی‌صدا و گران‌اند:
 *   ۱. **درصدِ دروغ** — «۱۰۰٪ بیشتر» روی دوره‌ی قبلِ صفر.
 *   ۲. **چکِ وصول‌نشده به‌عنوانِ نقد** — جریانِ نقد پولی نشان می‌دهد که نیست.
 *   ۳. **انتقالِ بینِ دو صندوق به‌عنوانِ جریان** — پول از جیبی به جیبی رفته.
 *   ۴. **فروشِ داشبورد ≠ فروشِ گزارش** — دو عدد برای یک حقیقت.
 *   ۵. **مرزِ ماهِ شمسی** در نمودارِ ماهانه.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_dash.php';

// =================================================================
T::group('۱ — تغییر نسبت به دوره‌ی قبل (بی‌دیتابیس)');
T::same(['pct' => null, 'dir' => 'none'], BizDash::change(500, 0), '⛔ دوره‌ی قبلِ صفر درصد ندارد (نه «۱۰۰٪ بیشتر»)');
T::same(['pct' => null, 'dir' => 'flat'], BizDash::change(0, 0), 'صفر در برابرِ صفر بی‌تغییر است');
T::same(['pct' => 50.0, 'dir' => 'up'], BizDash::change(150, 100), '۱۵۰ در برابرِ ۱۰۰ ← ۵۰٪ بیشتر');
T::same(['pct' => -25.0, 'dir' => 'down'], BizDash::change(75, 100), '۷۵ در برابرِ ۱۰۰ ← ۲۵٪ کمتر');
T::same('flat', BizDash::change(1003, 1000)['dir'], 'زیرِ نیم درصد «بی‌تغییر» است');
T::same('up', BizDash::change(-50, -100)['dir'], 'زیانِ کمتر (−۵۰ در برابرِ −۱۰۰) بهتر شدن است');

// =================================================================
T::group('۲ — وضعیتِ طلب و بدهی (بی‌دیتابیس)');
$t0 = '2026-06-01';
$ago = fn(int $d): string => date('Y-m-d', strtotime($t0 . ' -' . $d . ' days'));
T::same(30, BizDash::CREDIT_DAYS, 'مهلتِ فرضیِ نسیه ۳۰ روز');
T::same('ok', BizDash::ageState(null, $t0)[0], 'بی‌فاکتورِ باز ← سررسید نشده');
T::same('ok', BizDash::ageState($ago(23), $t0)[0], '۲۳ روز ← سررسید نشده');
T::same('near', BizDash::ageState($ago(24), $t0)[0], '۲۴ روز ← نزدیک سررسید');
T::same('near', BizDash::ageState($ago(30), $t0)[0], '۳۰ روز ← هنوز نزدیک سررسید (مرز)');
T::same('due', BizDash::ageState($ago(31), $t0)[0], '۳۱ روز ← سررسید شده');
T::same('due', BizDash::ageState($ago(60), $t0)[0], '۶۰ روز ← سررسید شده (مرز)');
T::same(['late', 'معوق'], BizDash::ageState($ago(61), $t0), '۶۱ روز ← معوق');

// =================================================================
T::group('۳ — سری و دانه‌بندیِ شمسی (بی‌دیتابیس)');
$series = ['2024-03-18' => ['a' => 5], '2024-03-19' => ['a' => 7], '2024-03-20' => ['a' => 11], '2024-03-25' => ['a' => 1]];
T::same(12, BizDash::sum($series, 'a', '2024-03-18', '2024-03-19'), 'جمع در بازه (دو سر شامل)');
T::same(['2024-03-18' => 5, '2024-03-19' => 7, '2024-03-20' => 11, '2024-03-21' => 0],
        BizDash::fill($series, '2024-03-18', '2024-03-21', fn($v) => $v['a'] ?? 0), 'روزِ بی‌ردیف صفر پر می‌شود');
// ۲۰ مارس ۲۰۲۴ = ۱ فروردین ۱۴۰۳
$m = BizDash::bucket($series, '2024-03-18', '2024-03-25', 'm');
T::same(['1402-12', '1403-01'], array_keys($m), '⛔ مرزِ ماه از تقویمِ شمسی (۲۰ مارس = ۱ فروردین)');
T::same([12, 12], [$m['1402-12']['v']['a'], $m['1403-01']['v']['a']], 'جمعِ هر ماه');
T::same('فروردین', $m['1403-01']['label'], 'برچسبِ ماه');
$w = BizDash::bucket($series, '2024-03-18', '2024-03-25', 'w');
T::same(['2024-03-16', '2024-03-23'], array_keys($w), 'هفته از شنبه (کلید = شنبه‌ی همان هفته)');
T::same(24, array_sum(array_map(fn($b) => $b['v']['a'] ?? 0, $w)), 'جمعِ هفته‌ها = جمعِ کل');

// =================================================================
T::group('۴ — نمودار (بی‌دیتابیس)');
T::same('', BizChart::spark([5]), 'یک نقطه نمودار نمی‌دهد');
T::ok(str_contains(BizChart::spark([1, 3, 2], 'up'), 'is-up'), 'جهتِ روند روی مینی‌نمودار');
$svg = BizChart::flow([['label' => 'x', 'tip' => '<script>"', 'in' => 5, 'out' => 2], ['label' => 'y', 'tip' => 'ok', 'in' => 1, 'out' => 9]]);
T::ok(!str_contains($svg, '<script>') && str_contains($svg, '&lt;script&gt;'), '⛔ متنِ راهنما فرار داده می‌شود');
T::same('۱٫۵م', BizChart::short(1500000), 'برچسبِ کوتاهِ محور');
T::same('−۳۵۰ه', BizChart::short(-350000), 'منفی با علامت');
$d = BizChart::donut([3, 0, 1]);
T::same(2, substr_count($d, 'class="st-c'), 'قطعه‌ی صفر رسم نمی‌شود');
T::ok(str_contains($d, 'st-c3'), 'رنگِ هر قطعه به جای خودش (نه به ترتیبِ رسم)');

// =================================================================
T::group('۵ — جریانِ نقد و فروش با دیتابیس');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('داشبوردِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_payments') || !BizCheques::ready()) {
    T::blocked('داشبوردِ فروشگاه', 'جدول‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}
const DPREFIX = '__bdash_';
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . DPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
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
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . DPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, DPREFIX . $name, DPREFIX . $name . '@example.com', 'Dash12345');
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => DPREFIX . $name]);
    return (int)$st->fetchColumn();
};
try {
    $a = $make('a');
    $b = $make('b');
    Biz::setType($a, 'business');
    Biz::setType($b, 'business');
    $today = today();
    $cash = (int)BizCash::list($a)[0]['id'];
    $bank = (int)BizCash::save($a, ['name' => 'بانک', 'kind' => 'bank', 'opening_balance' => '0'])['id'];
    $cus  = (int)BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
    $sd   = (int)BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => [['item' => 'خدمت', 'qty' => '1', 'price' => '5000']]])['id'];
    T::ok(BizInvoices::issue($a, $sd, ['amount' => '0'])['ok'], 'فاکتورِ فروشِ نسیه‌ی ۵۰۰۰');

    T::ok(BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '1000', 'account_id' => $cash])['ok'], 'دریافتِ نقدِ ۱۰۰۰');
    $r = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '3000', 'account_id' => $cash, 'method' => 'cheque',
                             'cheque_due' => $today, 'cheque_no' => '777']);
    T::ok($r['ok'], 'چکِ دریافتیِ ۳۰۰۰');
    $chq = (int)$r['id'];
    T::ok(BizPay::create($a, ['kind' => 'transfer', 'amount' => '400', 'account_id' => $cash, 'to_account_id' => $bank])['ok'], 'انتقالِ ۴۰۰ صندوق ← بانک');
    T::ok(BizPay::create($a, ['kind' => 'expense', 'amount' => '200', 'account_id' => $cash, 'title' => 'اجاره'])['ok'], 'هزینه‌ی ۲۰۰');

    $cf = BizDash::cashDaily($a, $today, $today)[$today] ?? ['in' => 0, 'out' => 0, 'exp' => 0];
    T::same(1000, $cf['in'], '⛔ چکِ وصول‌نشده ورودِ نقد نیست؛ انتقالِ بینِ صندوق‌ها هم نه');
    T::same(200, $cf['out'], 'خروجِ نقد فقط هزینه (انتقال جریان نیست)');
    T::same(200, $cf['exp'], 'هزینه‌ی متفرقه');

    T::ok(BizCheques::clear($a, $chq, $bank, $today)['ok'], 'چک به بانک وصول شد');
    $cf = BizDash::cashDaily($a, $today, $today)[$today] ?? ['in' => 0];
    T::same(4000, $cf['in'], '⛔ وصولِ چک در روزِ وصول ورودِ نقد است');

    $td = BizDash::todayByAccount($a, $today);
    T::same(400, (int)($td[$bank] ?? 0) - 3000, 'تغییرِ امروزِ بانک = انتقال + وصول');

    $sales = BizDash::salesDaily($a, $today, $today)[$today]['rev'] ?? 0;
    $rep   = BizReports::sales($a, $today, $today);
    T::same((int)$rep['net'], $sales, '⛔ فروشِ داشبورد = فروشِ گزارش');

    T::same([], BizDash::cashDaily($b, $today, $today), '⛔ فروشگاهِ دیگر هیچ جریانی از این فروشگاه نمی‌بیند');
    T::same([], BizDash::salesDaily($b, $today, $today), '⛔ و هیچ فروشی');

    // ---------- ۶ — سرعت: مانده‌ها یک بار، با همان عدد ----------
    T::group('۶ — دفترِ طرف‌حساب‌ها یک بار، و پرفروش‌ها با گروه‌بندیِ تازه');
    // دو بدهکار، دو بستانکار، یک صفر؛ و یک فاکتورِ خریدِ نسیه برای «قدیمی‌ترین»
    $p2 = (int)BizParties::save($a, ['name' => 'مشتریِ دوم', 'kind' => 'customer', 'opening_amount' => '9000'])['id'];
    $s1 = (int)BizParties::save($a, ['name' => 'تأمین‌کننده', 'kind' => 'supplier', 'opening_amount' => '7000', 'opening_side' => 'we'])['id'];
    $s2 = (int)BizParties::save($a, ['name' => 'تأمین‌کننده‌ی دوم', 'kind' => 'supplier'])['id'];
    BizParties::save($a, ['name' => 'صفر', 'kind' => 'customer']);
    $pd = (int)BizInvoices::saveDraft($a, 'purchase', ['party_id' => $s2, 'inv_date' => date('Y-m-d', strtotime($today . ' -40 days')),
        'lines' => [['item' => 'قطعه', 'qty' => '2', 'price' => '1500']]])['id'];
    T::ok(BizInvoices::issue($a, $pd, ['amount' => '0'])['ok'], 'فاکتورِ خریدِ نسیه‌ی ۳۰۰۰، چهل روز پیش');

    $book = BizDash::partyBook($a, 5);
    T::same(BizParties::summary($a), $book['summary'], '⛔ خلاصه‌ی داشبورد = خلاصه‌ی صفحه‌ی طرف‌حساب‌ها (یک تعریف)');
    $all  = BizParties::all($a)['rows'];
    $want = ['d' => [], 'c' => []];
    foreach ($all as $r) {
        $v = (int)$r['balance'];
        if ($v > 0) { $want['d'][] = [(int)$r['id'], $v]; } elseif ($v < 0) { $want['c'][] = [(int)$r['id'], $v]; }
    }
    usort($want['d'], fn($x, $y) => [$y[1], $x[0]] <=> [$x[1], $y[0]]);
    usort($want['c'], fn($x, $y) => [$x[1], $x[0]] <=> [$y[1], $y[0]]);
    T::same($want['d'], array_map(fn($r) => [(int)$r['id'], (int)$r['balance']], $book['debtors']), 'بدهکارها: بزرگ‌ترین اول، همان مانده‌ی `BALANCE_SQL`');
    T::same($want['c'], array_map(fn($r) => [(int)$r['id'], (int)$r['balance']], $book['creditors']), 'بستانکارها: بزرگ‌ترین بدهیِ ما اول');
    $oldS2 = null;
    foreach ($book['creditors'] as $r) { if ((int)$r['id'] === $s2) { $oldS2 = $r['oldest']; } }
    T::same(date('Y-m-d', strtotime($today . ' -40 days')), $oldS2, '«قدیمی‌ترین فاکتورِ تسویه‌نشده»ِ بستانکار از خرید می‌آید');
    $oldCus = null;
    foreach ($book['debtors'] as $r) { if ((int)$r['id'] === $cus) { $oldCus = $r['oldest']; } }
    T::same($today, $oldCus, '… و ِ بدهکار از فروش');
    T::same(1, count(BizDash::partyBook($a, 1)['debtors']), 'سقفِ هر سو رعایت می‌شود');
    T::same(['count' => 0, 'receivable' => 0, 'payable' => 0, 'debtors' => 0, 'creditors' => 0], BizDash::partyBook($b)['summary'],
        '⛔ فروشگاهِ دیگر هیچ طرف‌حسابی از این فروشگاه نمی‌بیند');
    $nb = BizParties::all($a, '', 50, false)['rows'][0] ?? [];
    T::ok(array_key_exists('balance', $nb) && $nb['balance'] === null,
        'منوی بی‌مانده: `balance` برابرِ null است، نه صفرِ ساختگی');

    // پرفروش‌ها: کالا با نامِ کالا، شرحِ آزاد جدا؛ جمع = فروشِ خالص
    $prod = BizProducts::save($a, ['name' => 'گوشیِ آزمایشی', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '900', 'opening_qty' => '5']);
    T::ok($prod['ok'] ?? false, 'کالای آزمایشی ساخته شد', (string)($prod['message'] ?? ''));
    $sl = (int)BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => [
        ['item' => 'گوشیِ آزمایشی', 'qty' => '2', 'price' => '900'], ['item' => 'شرحِ آزادِ دیگر', 'qty' => '1', 'price' => '50']]])['id'];
    T::ok(BizInvoices::issue($a, $sl, ['amount' => '0'])['ok'], 'فروشِ دو گوشی و یک شرحِ آزاد');
    $top = BizReports::topProducts($a, $today, $today, 10);
    $names = array_column($top, 'name');
    $row = [];
    foreach ($top as $r) { if ((int)$r['product_id'] === (int)($prod['id'] ?? -1)) { $row = $r; } }
    T::same('گوشیِ آزمایشی', $row['name'] ?? null, 'ردیفِ کالا نامِ خودِ کالا را دارد (نه شرحِ ردیف)');
    T::same(2, (int)($row['qty'] ?? 0), 'تعدادِ کالا درست جمع شد');
    T::same(1800, (int)($row['rev'] ?? 0), 'فروشِ کالا');
    T::same(5000, (int)($top[0]['rev'] ?? 0), 'مرتب: بیشترین فروش اول');
    T::ok(in_array('خدمت', $names, true) && in_array('شرحِ آزادِ دیگر', $names, true), 'دو شرحِ آزادِ متفاوت دو ردیف‌اند');
    T::same((int)BizReports::sales($a, $today, $today)['net'], array_sum(array_map(fn($r) => (int)$r['rev'], $top)),
        '⛔ جمعِ پرفروش‌ها = فروشِ خالصِ گزارش');
    T::same([], BizReports::topProducts($b, $today, $today, 10), '⛔ فروشگاهِ دیگر هیچ پرفروشی از این فروشگاه نمی‌بیند');
} finally {
    $wipe();
}

exit(T::report());
