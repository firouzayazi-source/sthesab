<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/store_share.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

seedUserDefaults($userId);

$typesStmt = $pdo->prepare('SELECT id, name, unit FROM asset_types WHERE user_id = :user_id ORDER BY name');
$typesStmt->execute(['user_id' => $userId]);
$assetTypes = $typesStmt->fetchAll();

// موجودی تجمیع‌شده به تفکیک نوع
//
// ⛔ ارزش با `COALESCE(at.current_price, a.unit_price)` حساب می‌شود، نه
//    فقط `a.unit_price`. `unit_price` بهای واحد **هنگام ثبت** است؛ در
//    اقتصادِ تورمی، ارزشِ سکه‌ای که پارسال خریده شده هیچ ربطی به آن
//    عدد ندارد. اگر کاربر نرخِ روز را وارد نکرده باشد، همان بهای خرید
//    می‌ماند — یعنی رفتارِ قبلی، نه صفر.
$assetSummary = assetSummaryRows($userId);
$totalPortfolioValue = array_sum(array_column($assetSummary, 'total_value'));
// بهای تمام‌شده جدا نگه داشته می‌شود تا بشود «سود/زیانِ محقق‌نشده» را
// نشان داد — بدون آن، کاربر فقط یک عددِ بزرگ‌تر می‌بیند و نمی‌داند
// چقدرش رشدِ قیمت است و چقدرش پولی که گذاشته.
$totalPortfolioCost = array_sum(array_column($assetSummary, 'total_cost'));

// رکوردهای جزئی
$listStmt = $pdo->prepare('
    SELECT a.*, at.name AS type_name, at.unit
    FROM assets a
    JOIN asset_types at ON at.id = a.asset_type_id
    WHERE a.user_id = :user_id
    ORDER BY a.entry_date DESC, a.created_at DESC
');
$listStmt->execute(['user_id' => $userId]);
$assetRecords = $listStmt->fetchAll();

// نمای کلی دارایی‌ها: هم دارایی‌های ثبت‌شده‌ی خود کاربر، هم کالایی که
// در بخش معاملات خریده و هنوز نفروخته — چون همه‌ی این‌ها دارایی‌اند.
//
// ⚠️ اینجا فقط «چقدر داریم» دیده می‌شود. خرید و فروش فقط در بخش
// معاملات انجام می‌شود و کم و زیاد کردن مقدار دارایی فقط همین‌جا.
$portfolio = [];

foreach ($assetSummary as $s) {
    $portfolio[] = [
        'name'  => $s['name'],
        'qty'   => (float)$s['total_qty'],
        'unit'  => $s['unit'],
        'value' => (int)$s['total_value'],
        'kind'  => 'asset',
    ];
}

// کالای بازِ بخش معاملات **یک قلمِ جمع‌شده** است، نه یکی به‌ازای هر کالا.
//
// چرا: این صفحه نمای کلیِ ترکیب دارایی است — «چند درصدم طلاست، چند
// درصدم پولِ نقد». اسمِ تک‌تکِ معامله‌ها (گوشی، سکه، …) اینجا فهرست را
// شلوغ می‌کرد و درصدها را خرد می‌کرد، در حالی که جای دیدنِ آن‌ها صفحه‌ی
// معاملات است. با یک ردیف و یک توگل، می‌شود پرسید «دارایی‌ام بدون
// بخش معاملات چقدر است؟» — که همان سؤالِ واقعی است.
//
// دارایی‌های ثبت‌شده عمداً جدا جدا می‌مانند (به تفکیک نوع)، چون آن‌ها
// خودشان ترکیبِ دارایی‌اند نه یک بخش.
$tradeInventoryValue = 0;
$tradeOpenCount      = 0;
if (tradesTablesExist($pdo) && tradesEnabled($pdo, $userId)) {
    foreach (tradesWithProgress($userId) as $t) {
        if ($t['is_closed']) { continue; }
        $tradeInventoryValue += $t['open_cost'];
        $tradeOpenCount++;
    }
}
if ($tradeOpenCount > 0) {
    $portfolio[] = [
        'name'  => 'مجموع دارایی‌های بخش معاملات',
        'qty'   => (float)$tradeOpenCount,
        'unit'  => 'قلم',
        'value' => (int)$tradeInventoryValue,
        'kind'  => 'trade',
    ];
}

// ---------- چک‌های در جریان ----------
//
// **فقط چکِ پاس‌نشده.** چکی که پاس شده پولش از قبل در موجودی حساب نشسته
// (walletBalances آن را با settle_wallet_id جمع می‌کند)، پس آوردنش اینجا
// یعنی دوبار شمردنِ همان پول.
//
// دریافتی مثبت و صادره منفی است، و خالصشان یک ردیف می‌شود: اگر بیشتر
// چکِ صادره داشته باشید، این قلم از دارایی کم می‌کند — که درست است،
// چون آن پول در آینده از دستتان می‌رود.
$chequeNet   = 0;
$chequeCount = 0;
if (tableExists('cheques')) {
    try {
        $cq = $pdo->prepare(
            "SELECT direction, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
             FROM cheques
             WHERE user_id = :u AND " . chequeActiveSql() . "
             GROUP BY direction"
        );
        $cq->execute(['u' => $userId]);
        foreach ($cq->fetchAll() as $r) {
            $chequeCount += (int)$r['n'];
            $chequeNet   += ($r['direction'] === 'received' ? 1 : -1) * (int)$r['total'];
        }
    } catch (PDOException $e) { $chequeCount = 0; $chequeNet = 0; }
}
if ($chequeCount > 0) {
    $portfolio[] = [
        'name'  => 'خالص چک‌های در جریان',
        'qty'   => (float)$chequeCount,
        'unit'  => 'چک',
        'value' => $chequeNet,
        'kind'  => 'cheques',
    ];
}

// ---------- خالص طلب و بدهی ----------
//
// باقیمانده‌ی طلب منهای باقیمانده‌ی بدهی. «باقیمانده» یعنی آنچه هنوز
// جابه‌جا نشده؛ پرداخت‌های انجام‌شده از قبل در موجودی حساب‌ها هستند
// (debt_payments.wallet_id)، پس اینجا دوباره شمرده نمی‌شوند.
//
// یعنی اگر ۵ میلیون طلب و ۳ میلیون بدهی داشته باشید، ۲ میلیون به
// دارایی اضافه می‌شود؛ اگر برعکس بود، همان‌قدر کم می‌شود.
$debtNet   = 0;
$debtCount = 0;
if (tableExists('debts')) {
    try {
        $dq = $pdo->prepare(
            'SELECT direction, amount, paid_amount FROM debts
             WHERE user_id = :u AND is_settled = 0'
        );
        $dq->execute(['u' => $userId]);
        foreach ($dq->fetchAll() as $d) {
            $remaining = debtRemaining($d);
            if ($remaining === 0) { continue; }
            $debtCount++;
            $debtNet += ($d['direction'] === 'receivable' ? 1 : -1) * $remaining;
        }
    } catch (PDOException $e) { $debtCount = 0; $debtNet = 0; }
}
if ($debtCount > 0) {
    $portfolio[] = [
        'name'  => 'خالص طلب و بدهی',
        'qty'   => (float)$debtCount,
        'unit'  => 'مورد',
        'value' => $debtNet,
        'kind'  => 'debts',
    ];
}

// پولِ توی حساب‌ها هم دارایی است. به‌صورت یک قلم می‌آید تا در همین
// نمودار دیده شود، ولی مثل بقیه‌ی قلم‌ها قابل خاموش کردن است — گاهی
// می‌خواهید بدانید دارایی غیرنقدی‌تان چقدر است.
$walletsTotal = 0;
$walletsCount = 0;
try {
    foreach (walletBalances($userId) as $w) {
        if ((int)$w['is_active'] !== 1) { continue; }
        $walletsTotal += (int)$w['balance'];
        $walletsCount++;
    }
} catch (PDOException $e) {
    $walletsCount = 0;
}
if ($walletsCount > 0) {
    $portfolio[] = [
        'name'  => 'مجموع حساب‌ها',
        'qty'   => (float)$walletsCount,
        'unit'  => 'حساب',
        'value' => $walletsTotal,
        'kind'  => 'wallets',
    ];
}

// ---------- سهمِ سهامدارِ فروشگاه ----------
//
// ⛔ عددش **مانده‌ی دفترِ آن سیستم** است، نه جمعِ بهای گوشی‌های
//    نفروخته — دلیلش بالای `StoreShare::valueFor()` نوشته شده: لحظه‌ی
//    فروش، گوشی از انبار بیرون می‌رود ولی پولش همچنان به او بدهکار
//    است، پس با جمعِ گوشی‌ها خالص داراییِ او سرِ هر فروش سقوط می‌کرد.
//
// ⛔ و هیچ محاسبه‌ای اینجا نیست: درصدِ سهم و سودِ هر قلم در حسابداریِ
//    فروشگاه حساب و سند می‌شوند. اینجا فقط نمایش است.
$storeShare = null;
$storeSyncStatus = ['fetched_at' => null, 'last_error' => null];
if (StoreShare::available()) {
    // آینه اگر کهنه بود تازه می‌شود — و همان‌جا سهمِ سود در دفترِ
    // کاربر ثبت می‌شود. جای این فراخوانی عمداً همین یک صفحه است.
    StoreShare::refreshIfStale();
    $storeSyncStatus = StoreShare::status();
    $storeShare      = StoreShare::forUser($userId);
}
if ($storeShare !== null) {
    $storeDevices = isset($storeShare['devices']) && is_array($storeShare['devices'])
        ? $storeShare['devices'] : [];
    $portfolio[] = [
        'name'  => 'دارایی من در فروشگاه',
        'qty'   => (float)count($storeDevices),
        'unit'  => 'دستگاه',
        'value' => (int)StoreShare::valueFor($userId),
        'kind'  => 'store_share',
    ];
}

// ---------- خالص داراییِ کلِ فروشگاه (فقط مدیر) ----------
//
// ⛔ فقط برای مدیر رندر می‌شود و **خالص** است، نه ناخالص: داراییِ
//    فروشگاه منهای بدهی‌اش به سهامداران. با عددِ ناخالص، گوشیِ
//    سهامدارها هم جزو ثروتِ مالکِ فروشگاه شمرده می‌شد — همان پول دو
//    بار، یک بار در دفترِ سهامدار و یک بار اینجا.
$storeNet  = null;
$storeOwed = null;
$owedMine  = false;
if (Auth::isAdmin() && StoreShare::available()) {
    $storeNet  = StoreShare::storeNetWorth();
    // ⛔ سهمِ خودِ همین مدیر کم می‌شود، وگرنه با «دارایی من در فروشگاه»
    //    هم‌پوشانی دارد و مانده‌اش دو بار در جمع می‌نشیند.
    $storeOwed = StoreShare::storeOwed($userId);
    $owedMine  = StoreShare::ownShareCounted($userId);
}
if ($storeNet !== null) {
    $portfolio[] = [
        'name'  => 'دارایی خودِ فروشگاه',
        'qty'   => 1.0,
        'unit'  => 'فروشگاه',
        'value' => (int)$storeNet,
        'kind'  => 'store_total',
    ];
}
// ---------- طلبِ سهامدارانِ فروشگاه (فقط مدیر) ----------
//
// ⛔ قلمِ جدا، چون مالکِ نصب خواست سه چیز را از هم تفکیک کند: داراییِ
//    خودِ فروشگاه، داراییِ بچه‌ها، و باهم. «باهم» همان کلیدِ روشن/خاموشِ
//    همیشگی است — هر دو روشن، جمع می‌شوند — پس هیچ کنترلِ تازه‌ای لازم
//    نشد. و چون `store_total` خالص است، جمعشان چیزی را دو بار نمی‌شمارد.
//
// ⚠ `null` یعنی اندپوینتِ فروشگاه هنوز این کلید را نمی‌دهد (نصبِ
//   عقب‌مانده)؛ آن‌وقت قلم **اصلاً رندر نمی‌شود**، نه اینکه صفر نشان
//   بدهد — صفرِ ساختگی از نبودنِ قلم گمراه‌کننده‌تر است.
if ($storeOwed !== null) {
    $portfolio[] = [
        'name'  => $owedMine ? 'طلب سایر سهامداران فروشگاه' : 'طلب سهامداران فروشگاه',
        'qty'   => 1.0,
        'unit'  => 'فروشگاه',
        'value' => (int)$storeOwed,
        'kind'  => 'store_owed',
    ];
}

// بزرگ‌ترین‌ها اول — وقتی اقلام زیاد شوند، مهم‌ها بالا می‌مانند
usort($portfolio, fn($a, $b) => $b['value'] <=> $a['value']);

// همان پالت گزارش دسته‌بندی، تا دو صفحه یک زبان بصری داشته باشند.
// قاچ اول سبز است (دارایی) و بقیه از چرخه‌ی هیوهای متمایز می‌آیند.
$paletteLight = chartPalette('green', 'light');
$paletteDark  = chartPalette('green', 'dark');
$pn = count($paletteLight);
foreach ($portfolio as $i => $row) {
    $portfolio[$i]['color_l'] = $paletteLight[$i % $pn];
    $portfolio[$i]['color_d'] = $paletteDark[$i % $pn];
    // کلید پایدار برای به‌خاطر سپردن انتخابِ روشن/خاموش در همین مرورگر
    $portfolio[$i]['key'] = $row['kind'] . ':' . $row['name'];
}

// جمع اولیه = همه‌ی قلم‌ها روشن. جاوااسکریپت با خاموش کردن هر قلم،
// همین عدد و درصدها و نمودار را دوباره می‌سازد.
$portfolioTotal = 0;
foreach ($portfolio as $row) { $portfolioTotal += $row['value']; }
$chartable = array_values(array_filter($portfolio, fn($r) => $r['value'] > 0));

// ---------- روندِ خالص دارایی ----------
// عکسِ امروز با همان اجزایی که بالا حساب شده‌اند ثبت می‌شود — بدون
// cron، به همان روشِ processRecurringTransactions. اجزا جدا ذخیره
// می‌شوند نه جمع، چون هر قلم کلیدِ روشن/خاموش دارد.
$snapParts = ['wallets' => 0, 'assets' => 0, 'trades_open' => 0,
              'cheques_net' => 0, 'debts_net' => 0,
              'store_share' => 0, 'store_total' => 0, 'store_owed' => 0];
// ⚠ نامِ کلیدها دقیقاً همانی است که بالا در $portfolio گذاشته شده:
//   asset / trade / cheques / debts / wallets. اگر اینجا مفرد نوشته
//   شود (wallet, cheque, debt) همه به شاخه‌ی else می‌افتند و جزوِ
//   «دارایی» شمرده می‌شوند — جمعِ کل درست می‌ماند ولی اجزا غلط، و
//   همه‌ی دلیلِ جدا ذخیره کردنشان از بین می‌رود. بی‌صدا هم خراب
//   می‌شود، چون عددِ روی صفحه فرقی نمی‌کند.
$snapMap = ['wallets' => 'wallets', 'trade' => 'trades_open',
            'cheques' => 'cheques_net', 'debts' => 'debts_net',
            'store_share' => 'store_share', 'store_total' => 'store_total',
            'store_owed' => 'store_owed'];
foreach ($portfolio as $row) {
    $bucket = $snapMap[$row['kind']] ?? 'assets';
    $snapParts[$bucket] += $row['value'];
}
recordNetWorthSnapshot($userId, $snapParts);

$nwHistory = netWorthHistory($userId, 12);
// «نسبت به قبل» فقط وقتی معنا دارد که دست‌کم دو نقطه باشد؛ با یک نقطه
// عددِ «۰٪» ساختگی است.
$nwPrev  = count($nwHistory) >= 2 ? $nwHistory[count($nwHistory) - 2]['total'] : null;
$nwDelta = $nwPrev !== null ? $portfolioTotal - $nwPrev : null;

// سود/زیانِ محقق‌نشده‌ی دارایی‌های ثبت‌شده — فقط وقتی نرخِ روز وارد
// شده باشد، وگرنه ارزش و بها یکی‌اند و خطِ «۰» بی‌معناست.
$unrealized = $totalPortfolioValue - $totalPortfolioCost;

$pageTitle = 'دارایی‌ها';
include __DIR__ . '/includes/header.php';
?>

<!-- ---------- نمای کلی دارایی‌ها ----------
     هر قلم یک کلید روشن/خاموش دارد. خاموش کردنش چیزی را پاک نمی‌کند؛
     فقط از جمع و نمودار بیرونش می‌گذارد — تا بشود پرسید «دارایی‌ام
     بدون طلا چقدر است؟» یا «بدون پولِ توی حساب‌ها چقدر؟». انتخاب در
     همین مرورگر به خاطر می‌ماند. -->
<div class="card">
    <div class="asset-total-row">
        <?php /* ⚠ «خالص» نه «کل»: بدهی از این عدد کم می‌شود، پس
                 «ارزش کل دارایی‌ها» اسمِ غلطی بود. */ ?>
        <span class="asset-total-label">خالص دارایی</span>
        <b class="asset-total-value" id="assetGrandTotal"><?= formatMoney($portfolioTotal) ?> <small><?= h(APP_CURRENCY) ?></small></b>
    </div>

    <?php if ($nwDelta !== null && $nwDelta !== 0): ?>
        <p class="nw-delta <?= $nwDelta > 0 ? 'delta-up' : 'delta-down' ?>">
            <?= $nwDelta > 0 ? '▲' : '▼' ?> <?= formatMoney(abs($nwDelta)) ?>
            نسبت به <?= h(toJalali($nwHistory[count($nwHistory) - 2]['date'])) ?>
        </p>
    <?php endif; ?>

    <?php if (count($nwHistory) >= 2): ?>
        <?php /* اسپارک‌لاین بی‌محور و بی‌عدد: اینجا شکلِ روند مهم است نه
                 مقدارِ دقیق — آن را همان عددِ بالای صفحه می‌گوید. */ ?>
        <div class="nw-spark"><canvas id="nwSpark" height="46"></canvas></div>
    <?php endif; ?>

    <?php if ($unrealized !== 0 && $totalPortfolioCost > 0): ?>
        <p class="hint asset-total-note">
            از دارایی‌های ثبت‌شده،
            <strong class="<?= $unrealized > 0 ? 'delta-up' : 'delta-down' ?>"><?= formatMoney(abs($unrealized)) ?></strong>
            <?= $unrealized > 0 ? 'سود' : 'زیان' ?> محقق‌نشده دارید
            (بهای خرید <?= formatMoney($totalPortfolioCost) ?>).
        </p>
    <?php endif; ?>

    <p class="hint asset-total-note" id="assetExcludedNote" hidden></p>

    <?php if (empty($portfolio)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php if (!empty($chartable)): ?>
        <div class="chart-container" style="max-width:250px; height:250px; margin:16px auto 18px;">
            <canvas id="assetChart"></canvas>
        </div>
        <?php endif; ?>

        <?php if (count($portfolio) > 6): ?>
        <div class="asset-search-wrap">
            <input type="search" id="assetFilter" class="asset-search"
                   placeholder="جستجو میان <?= toPersianDigits(count($portfolio)) ?> دارایی…"
                   autocapitalize="none" autocorrect="off">
        </div>
        <?php endif; ?>

        <div class="category-breakdown-list" id="assetBreakdown">
            <?php foreach ($portfolio as $row): ?>
                <?php
                // درصد فقط وقتی معنا دارد که هم جمع کل مثبت باشد هم خودِ قلم.
                // با یک خالصِ منفیِ بزرگ (بدهی بیشتر از دارایی) جمع کل منفی
                // می‌شود و «۰٪» گمراه‌کننده بود.
                $pct = ($portfolioTotal > 0 && $row['value'] > 0)
                    ? round($row['value'] / $portfolioTotal * 100, 1) : 0;
                $showPct = $portfolioTotal > 0 && $row['value'] > 0;
                ?>
                <div class="cat-breakdown-item asset-item"
                     data-name="<?= h($row['name']) ?>"
                     data-key="<?= h($row['key']) ?>"
                     data-value="<?= (int)$row['value'] ?>">
                    <div class="cat-breakdown-summary">
                        <span class="cat-dot" style="--dot-l:<?= h($row['color_l']) ?>; --dot-d:<?= h($row['color_d']) ?>;"></span>
                        <?php
                        // برچسب فقط روی دارایی‌های ثبت‌شده معنا دارد. دو ردیفِ
                        // جمع‌شده («مجموع دارایی‌های بخش معاملات» و «مجموع
                        // حساب‌ها») خودشان اسمشان را می‌گویند، و برچسبِ تکراری
                        // فقط عرض می‌گرفت و نام را با «…» می‌برید.
                        ?>
                        <span class="cat-breakdown-name"><?= h($row['name']) ?></span>
                        <span class="cat-breakdown-pct"><?= $showPct ? toPersianDigits($pct) . '٪' : '—' ?></span>
                        <?php
                        // مقدار منفی با علامت «−» می‌آید، وگرنه یک بدهیِ خالص
                        // شبیه دارایی دیده می‌شد. علامت ریاضیِ منها (U+2212)
                        // است نه خط تیره، تا در راست‌به‌چپ درست بنشیند.
                        ?>
                        <span class="cat-breakdown-amount<?= $row['value'] < 0 ? ' asset-amount-neg' : '' ?>">
                            <?= $row['value'] !== 0 ? ($row['value'] < 0 ? '−' : '') . formatMoney(abs($row['value'])) : '۰' ?>
                        </span>
                        <label class="switch switch-sm asset-toggle" title="اعمال در جمع و نمودار">
                            <input type="checkbox" class="js-asset-include" checked>
                            <span class="switch-track"><span class="switch-knob"></span></span>
                        </label>
                    </div>
                    <div class="asset-item-qty">
                        <?= formatQuantity($row['qty']) ?> <?= h($row['unit']) ?>
                        <?php // «قیمت ثبت نشده» فقط برای دارایی معنا دارد؛ خالصِ صفرِ
                              // چک یا طلب/بدهی یعنی سر به سر، نه قیمتِ نداشته. ?>
                        <?php if ($row['value'] === 0 && $row['kind'] === 'asset'): ?>
                            <span class="asset-noprice">قیمت واحد ثبت نشده</span>
                        <?php elseif ($row['value'] === 0): ?>
                            <span class="asset-noprice">سر به سر</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['value'] > 0): ?>
                    <div class="cat-breakdown-bar-track">
                        <div class="cat-breakdown-bar" style="width:<?= $pct ?>%; --dot-l:<?= h($row['color_l']) ?>; --dot-d:<?= h($row['color_d']) ?>;"></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="asset-empty-filter" id="assetNoMatch" hidden>چیزی با این نام پیدا نشد.</p>

        <?php if ($tradeOpenCount > 0): ?>
        <p class="hint" style="margin-top:12px;">
            «مجموع دارایی‌های بخش معاملات» جمعِ <?= toPersianDigits((string)$tradeOpenCount) ?> کالای فروخته‌نشده است.
            برای دیدن و فروششان به <a href="<?= APP_BASE_PATH ?>/trades.php">بخش معاملات</a> بروید.
        </p>
        <?php endif; ?>

        <?php if ($chequeCount > 0 || $debtCount > 0): ?>
        <p class="hint" style="margin-top:<?= $tradeOpenCount > 0 ? '6' : '12' ?>px;">
            <?php if ($chequeCount > 0): ?>
                «خالص چک‌های در جریان» یعنی دریافتی منهای صادره، فقط برای چک‌های
                <b>پاس‌نشده</b> — چکِ پاس‌شده پولش از قبل در موجودی حساب‌ها هست.
                (<a href="<?= APP_BASE_PATH ?>/cheques.php">بخش چک‌ها</a>)
            <?php endif; ?>
            <?php if ($debtCount > 0): ?>
                «خالص طلب و بدهی» یعنی باقیمانده‌ی طلب منهای باقیمانده‌ی بدهی؛
                اگر بدهی بیشتر باشد از جمع کل کم می‌شود.
                (<a href="<?= APP_BASE_PATH ?>/debts.php">بخش طلب و بدهی</a>)
            <?php endif; ?>
        </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($storeShare !== null): ?>
<?php
/*
 * ⛔ این کارت **هیچ دکمه‌ی اقدامی ندارد و نباید داشته باشد.** خواسته‌ی
 *    صریحِ مالکِ نصب «فقط رویت» بود، و مرزش هم فنی است: منطقِ خرید و
 *    فروش و درصدِ سهم در حسابداریِ فروشگاه است. یک دکمه‌ی «فروش» اینجا
 *    یعنی نسخه‌ی دومِ آن منطق — همان مرزی که بین `my-assets.php` و
 *    `trades.php` هم عمداً کشیده شده.
 */
$shDevices = isset($storeShare['devices']) && is_array($storeShare['devices'])
    ? $storeShare['devices'] : [];
$shSold    = array_values(array_filter($shDevices, fn($d) => ($d['status'] ?? '') === 'SOLD'));
$shOpen    = array_values(array_filter($shDevices, fn($d) => ($d['status'] ?? '') !== 'SOLD'));
?>
<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">دارایی من در فروشگاه</h2>
        <span class="asset-tag">فقط نمایش</span>
    </div>

    <div class="asset-total-row">
        <span class="asset-total-label">مانده</span>
        <b class="asset-total-value<?= StoreShare::valueFor($userId) < 0 ? ' asset-amount-neg' : '' ?>">
            <span class="ltr-num"><?= formatMoney((int)StoreShare::valueFor($userId)) ?></span>
        </b>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">اصل سرمایه</span>
        <b class="asset-total-value"><span class="ltr-num"><?= formatMoney((int)round((float)($storeShare['capital'] ?? 0))) ?></span></b>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">سهم سود</span>
        <b class="asset-total-value"><span class="ltr-num"><?= formatMoney((int)round((float)($storeShare['earned'] ?? 0))) ?></span></b>
    </div>
    <div class="asset-total-row">
        <span class="asset-total-label">پرداخت‌شده</span>
        <b class="asset-total-value"><span class="ltr-num"><?= formatMoney((int)round((float)($storeShare['paid'] ?? 0))) ?></span></b>
    </div>

    <p class="hint asset-total-note">
        <?= toPersianDigits((string)count($shOpen)) ?> دستگاه فروش‌نرفته و
        <?= toPersianDigits((string)count($shSold)) ?> دستگاه فروخته‌شده.
        سهمِ سود خودکار در دفتر شما ثبت می‌شود.
        <?php if ($storeSyncStatus['fetched_at']): ?>
            آخرین به‌روزرسانی: <?= h(jalaliWithWeekday(substr((string)$storeSyncStatus['fetched_at'], 0, 10))) ?>.
        <?php endif; ?>
        <?php if ($storeSyncStatus['last_error']): ?>
            <br><span style="color:var(--warn-ink);">آخرین تلاش ناموفق بود؛ عددهای بالا از آخرین نسخه‌ی موفق است.</span>
        <?php endif; ?>
    </p>

    <?php if ($shDevices !== []): ?>
        <?php foreach ($shDevices as $d): ?>
            <div class="tx-row">
                <div class="tx-row-summary">
                    <div class="tx-row-texts">
                        <span class="tx-row-title"><?= h((string)($d['product'] ?? '—')) ?></span>
                        <span class="tx-row-cat">
                            <?= ($d['status'] ?? '') === 'SOLD' ? 'فروخته شد' : 'در انبار' ?>
                            <?php if (!empty($d['imei'])): ?>
                                · <span class="ltr-num"><?= h((string)$d['imei']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="tx-row-amount">
                        <span class="ltr-num"><?= formatMoney((int)round((float)(
                            ($d['status'] ?? '') === 'SOLD' ? ($d['sale_price'] ?? 0) : ($d['cost'] ?? 0)
                        ))) ?></span>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">ثبت‌های دارایی</h2>
        <button type="button" class="btn btn-primary btn-sm" data-modal-open="addAssetModal" <?= empty($assetTypes) ? 'disabled' : '' ?>>+ ثبت دارایی</button>
    </div>

    <?php if (empty($assetTypes)): ?>
        <p class="empty-row">ابتدا از صفحه‌ی «فهرست‌های من» یک نوع دارایی اضافه کنید.<br><a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">رفتن به فهرست‌های من ←</a></p>
    <?php elseif (empty($assetRecords)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($assetRecords as $a): ?>
            <div class="tx-row">
                <div class="tx-row-summary">
                    <span class="tx-row-title"><?= h($a['type_name']) ?></span>
                    <span class="tx-row-amount"><?= formatQuantity($a['quantity']) ?> <small style="font-weight:400; color:var(--color-gray-500);"><?= h($a['unit']) ?></small></span>
                    <span class="tx-row-chevron">▾</span>
                </div>
                <div class="tx-row-details">
                    <div class="tx-row-details-line"><span>تاریخ</span><span><?= toJalali($a['entry_date']) ?></span></div>
                    <?php if (!empty($a['unit_price'])): ?>
                        <div class="tx-row-details-line"><span>قیمت واحد</span><span><?= formatMoney($a['unit_price']) ?> تومان</span></div>
                        <div class="tx-row-details-line"><span>ارزش کل</span><span><?= formatMoney((float)$a['quantity'] * (int)$a['unit_price']) ?> تومان</span></div>
                    <?php endif; ?>
                    <?php if (!empty($a['note'])): ?>
                        <div class="tx-row-details-line"><span>توضیح</span><span><?= h($a['note']) ?></span></div>
                    <?php endif; ?>
                    <div class="tx-row-actions">
                        <button type="button" class="btn btn-secondary btn-sm js-edit-asset"
                            data-id="<?= (int)$a['id'] ?>"
                            data-quantity="<?= h($a['quantity']) ?>"
                            data-unit-price="<?= (int)($a['unit_price'] ?? 0) ?>"
                            data-note="<?= h($a['note'] ?? '') ?>"
                            data-entry-date="<?= h($a['entry_date']) ?>">ویرایش</button>
                        <button class="delete-btn js-delete-asset" data-id="<?= (int)$a['id'] ?>">حذف</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- مدیریت انواع دارایی به صفحه‌ی «فهرست‌های من» منتقل شد. -->
<p class="ref-link-row">
    <a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">افزودن یا حذف انواع دارایی ←</a>
</p>

<!-- مودال افزودن دارایی -->
<div class="modal-overlay" id="addAssetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ثبت دارایی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="addAssetForm" autocomplete="off">
            <?= Csrf::field() ?>

            <div class="form-group">
                <label for="add_asset_type">نوع دارایی</label>
                <select id="add_asset_type" name="asset_type_id" required>
                    <?php foreach ($assetTypes as $at): ?>
                        <option value="<?= (int)$at['id'] ?>" data-unit="<?= h($at['unit']) ?>"><?= h($at['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="add_asset_qty">مقدار <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="add_asset_qty" name="quantity" required placeholder="مثلاً ۲٫۵">
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                        <input type="hidden" class="jdp-hidden" id="add_asset_date" name="entry_date" value="<?= h($todayStr) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="add_asset_price">قیمت واحد به تومان (اختیاری)</label>
                <input type="text" inputmode="numeric" id="add_asset_price" name="unit_price" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="add_asset_note">توضیح (اختیاری)</label>
                <textarea id="add_asset_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="addAssetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="addAssetSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش دارایی -->
<div class="modal-overlay" id="editAssetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش دارایی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="editAssetForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="asset_id" id="edit_asset_id">

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_asset_qty">مقدار <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="edit_asset_qty" name="quantity" required>
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="edit_asset_date_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="edit_asset_date" name="entry_date">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_asset_price">قیمت واحد به تومان (اختیاری)</label>
                <input type="text" inputmode="numeric" id="edit_asset_price" name="unit_price" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="edit_asset_note">توضیح (اختیاری)</label>
                <textarea id="edit_asset_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editAssetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editAssetSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php /* ⚠ شرط شاملِ اسپارک‌لاین هم هست: کاربری که هنوز دارایی ثبت
         نکرده ولی تاریخچه دارد، بدون این Chart.js را نمی‌گرفت و
         نمودارِ روند خالی می‌ماند. */ ?>
<?php if (!empty($chartable) || count($nwHistory) >= 2): ?>
<!-- همان منبعی که گزارش دسته‌بندی از آن استفاده می‌کند -->
<?php foreach (assetUrls(['js/chart.umd.js']) as $__u): ?>
<script defer src="<?= h($__u) ?>"></script>
<?php endforeach; ?>
<?php if (count($nwHistory) >= 2): ?>
<script id="netWorthData" type="application/json"><?= json_encode(array_map(fn($r) => [
    'd' => toJalali($r['date']),
    'v' => (int)$r['total'],
], $nwHistory), JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($chartable)): ?>
<script id="assetPortfolioData" type="application/json"><?= json_encode(array_map(fn($r) => [
    'key'   => $r['key'],
    'name'  => $r['name'],
    'value' => (int)$r['value'],
    'cl'    => $r['color_l'],
    'cd'    => $r['color_d'],
], $chartable), JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
