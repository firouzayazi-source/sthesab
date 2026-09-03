<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

// بررسی و پردازش تراکنش‌های دوره‌ای سررسیدشده (بدون نیاز به Cron)
$recurringNeedsAttention = [];
try {
    $recurringNeedsAttention = processRecurringTransactions($userId);
} catch (PDOException $e) {
    $recurringNeedsAttention = []; // جدول هنوز ساخته نشده
}

$today      = today();
$weekStart  = startOfWeek();
$monthStart = startOfJalaliMonth();
$yearStart  = startOfJalaliYear();

// جمعِ روزانه‌ی کل تاریخچه — یک بار خوانده می‌شود و همه‌ی بازه‌های این
// صفحه از رویش ساخته می‌شوند. قبلاً برای امروز/هفته/ماه/سال/بازه‌ی دلخواه
// پنج کوئری جدا می‌رفت که همگی روی همین جدول همین جمع را می‌گرفتند.
$dailyStmt = $pdo->prepare('
    SELECT transaction_date,
        SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END) AS daily_income,
        SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) AS daily_expense,
        SUM(type = "income")  AS income_count,
        SUM(type = "expense") AS expense_count
    FROM transactions
    WHERE user_id = :user_id
    GROUP BY transaction_date
    ORDER BY transaction_date ASC
');
$dailyStmt->execute(['user_id' => $userId]);
$dailyRows = $dailyStmt->fetchAll();
$dailyRowsMap = [];
foreach ($dailyRows as $row) {
    $dailyRowsMap[$row['transaction_date']] = $row;
}

function getPeriodStats(array $dailyRows, string $fromDate, string $toDate): array
{
    $income = 0; $expense = 0;
    foreach ($dailyRows as $row) {
        $d = $row['transaction_date'];
        if ($d < $fromDate || $d > $toDate) { continue; }
        $income  += (int)$row['daily_income'];
        $expense += (int)$row['daily_expense'];
    }

    return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
}

$todayStats = getPeriodStats($dailyRows, $today, $today);
$weekStats  = getPeriodStats($dailyRows, $weekStart, $today);
$monthStats = getPeriodStats($dailyRows, $monthStart, $today);
$yearStats  = getPeriodStats($dailyRows, $yearStart, $today);

// ---------- بخش گزارش با بازه دلخواه ----------
$customRangeSubmitted = isset($_GET['from_date']) || isset($_GET['to_date']);

$fromDate = getParam('from_date', $monthStart);
$toDate   = getParam('to_date', $today);

if (!isValidDate($fromDate)) $fromDate = $monthStart;
if (!isValidDate($toDate)) $toDate = $today;
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$rangeStats = getPeriodStats($dailyRows, $fromDate, $toDate);

$incomeCount = 0; $expenseCount = 0;
foreach ($dailyRows as $row) {
    if ($row['transaction_date'] < $fromDate || $row['transaction_date'] > $toDate) { continue; }
    $incomeCount  += (int)$row['income_count'];
    $expenseCount += (int)$row['expense_count'];
}

$rangeDailyRows = array_values(array_filter(
    $dailyRows,
    fn($r) => $r['transaction_date'] >= $fromDate && $r['transaction_date'] <= $toDate
));

// تب «هفته»: ۷ روز اخیر
$weekBuckets = [];
for ($i = 6; $i >= 0; $i--) {
    $weekBuckets[date('Y-m-d', strtotime("-$i days"))] = ['income' => 0, 'expense' => 0];
}
// تب «ماه»: ۳۰ روز اخیر
$month30Buckets = [];
for ($i = 29; $i >= 0; $i--) {
    $month30Buckets[date('Y-m-d', strtotime("-$i days"))] = ['income' => 0, 'expense' => 0];
}
foreach ([&$weekBuckets, &$month30Buckets] as &$bucketSet) {
    foreach ($bucketSet as $d => &$b) {
        if (isset($dailyRowsMap[$d])) {
            $b['income']  = (int)$dailyRowsMap[$d]['daily_income'];
            $b['expense'] = (int)$dailyRowsMap[$d]['daily_expense'];
        }
    }
    unset($b);
}
unset($bucketSet);

[$currentJy, $currentJm, ] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));

// تب «سال»: ۱۲ ماه اخیر شمسی (تجمیع ماهانه)
$monthlyBuckets = [];
$jy = $currentJy; $jm = $currentJm;
for ($i = 0; $i < 12; $i++) {
    $key = sprintf('%04d-%02d', $jy, $jm);
    $monthlyBuckets[$key] = ['jy' => $jy, 'jm' => $jm, 'income' => 0, 'expense' => 0];
    $jm--;
    if ($jm < 1) { $jm = 12; $jy--; }
}
$monthlyBuckets = array_reverse($monthlyBuckets, true);

foreach ($dailyRows as $row) {
    [$gy, $gm, $gd] = array_map('intval', explode('-', $row['transaction_date']));
    [$ry, $rm, ] = gregorianToJalali($gy, $gm, $gd);
    $mKey = sprintf('%04d-%02d', $ry, $rm);
    if (isset($monthlyBuckets[$mKey])) {
        $monthlyBuckets[$mKey]['income']  += (int)$row['daily_income'];
        $monthlyBuckets[$mKey]['expense'] += (int)$row['daily_expense'];
    }
}

$monthNamesShort = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function bucketsToSeries(array $buckets, bool $isDaily, array $monthNamesShort): array
{
    $labels = []; $income = []; $expense = [];
    foreach ($buckets as $key => $b) {
        $labels[]  = $isDaily ? toJalali($key) : $monthNamesShort[$b['jm']];
        $income[]  = $b['income'];
        $expense[] = $b['expense'];
    }
    return [$labels, $income, $expense];
}

[$weekLabels, $weekIncome, $weekExpense] = bucketsToSeries($weekBuckets, true, $monthNamesShort);
[$month30Labels, $month30Income, $month30Expense] = bucketsToSeries($month30Buckets, true, $monthNamesShort);
[$monthlyLabels, $monthlyIncome, $monthlyExpense] = bucketsToSeries($monthlyBuckets, false, $monthNamesShort);

$hasAnyData = !empty($dailyRows);

// ---------- یادآوری طلب/بدهی نزدیک به سررسید یا سررسیدگذشته ----------
$reminderStmt = $pdo->prepare('
    SELECT id, direction, counterparty_name, amount, due_date
    FROM debts
    WHERE user_id = :user_id AND is_settled = 0 AND due_date <= :soon
    ORDER BY due_date ASC
    LIMIT 5
');
$reminderStmt->execute(['user_id' => $userId, 'soon' => date('Y-m-d', strtotime('+7 days'))]);
$debtReminders = $reminderStmt->fetchAll();

$chequeReminders = [];
try {
    $chRemStmt = $pdo->prepare('
        SELECT id, direction, counterparty_name, amount, due_date
        FROM cheques
        WHERE user_id = :user_id AND ' . chequeActiveSql() . ' AND due_date IS NOT NULL AND due_date <= :soon
        ORDER BY due_date ASC
        LIMIT 5
    ');
    $chRemStmt->execute(['user_id' => $userId, 'soon' => date('Y-m-d', strtotime('+7 days'))]);
    $chequeReminders = $chRemStmt->fetchAll();
} catch (PDOException $e) {
    $chequeReminders = [];
}

// موجودی حساب‌ها عمداً اینجا نیست: جایش صفحه‌ی «حساب‌ها و انتقال» است.
// در گزارش دو بار یک عدد نشان دادن، فقط شلوغی بود.

// گزارش مقایسه‌ای و بینش هزینه
$comparison = null;
$insights = null;
try {
    $comparison = monthComparison($userId);
    $insights = spendingInsights($userId, startOfJalaliMonth(), $today);
} catch (PDOException $e) {
    $comparison = null;
    $insights = null;
}

$pageTitle = 'داشبورد';
include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($debtReminders)): ?>
<div class="card" style="border-color: var(--color-expense);">
    <h2 class="card-title" style="color: var(--color-expense);">⏰ یادآوری طلب و بدهی</h2>
    <?php foreach ($debtReminders as $r): ?>
        <?php $overdue = $r['due_date'] < today(); ?>
        <div class="stats-row" style="border-bottom: 1px solid var(--color-gray-100); padding: 8px 0;">
            <span class="stats-label">
                <?= $r['direction'] === 'receivable' ? 'طلب از' : 'بدهی به' ?> <?= h($r['counterparty_name']) ?>
                — <?= $overdue ? '<span style="color:var(--color-expense); font-weight:700;">سررسید گذشته</span>' : 'سررسید ' . toJalali($r['due_date']) ?>
            </span>
            <span class="stats-value" style="direction:ltr;"><?= formatMoney($r['amount']) ?> <small>تومان</small></span>
        </div>
    <?php endforeach; ?>
    <a href="debts.php" class="link-more" style="display:inline-block; margin-top:10px;">مدیریت طلب و بدهی ←</a>
</div>
<?php endif; ?>

<?php if (!empty($chequeReminders)): ?>
<div class="card" style="border-color: var(--color-expense);">
    <h2 class="card-title" style="color: var(--color-expense);">⏰ یادآوری چک‌ها</h2>
    <?php foreach ($chequeReminders as $r): ?>
        <?php $overdue = $r['due_date'] < today(); ?>
        <div class="stats-row" style="border-bottom: 1px solid var(--color-gray-100); padding: 8px 0;">
            <span class="stats-label">
                چک <?= $r['direction'] === 'received' ? 'دریافتی از' : 'صادره به' ?> <?= h($r['counterparty_name']) ?>
                — <?= $overdue ? '<span style="color:var(--color-expense); font-weight:700;">سررسید گذشته</span>' : 'سررسید ' . toJalali($r['due_date']) ?>
            </span>
            <span class="stats-value" style="direction:ltr;"><?= formatMoney($r['amount']) ?> <small>تومان</small></span>
        </div>
    <?php endforeach; ?>
    <a href="cheques.php" class="link-more" style="display:inline-block; margin-top:10px;">مدیریت چک‌ها ←</a>
</div>
<?php endif; ?>

<?php if (!empty($recurringNeedsAttention)): ?>
<div class="card" style="border: 1px solid #d97706;">
    <h2 class="card-title" style="color:#d97706;">🔁 تراکنش‌های دوره‌ای در انتظار</h2>
    <p class="hint" style="margin-bottom:10px;"><?= toPersianDigits(count($recurringNeedsAttention)) ?> مورد سررسید شده — بررسی کنید.</p>
    <a href="recurring.php" class="link-more">مشاهده و تأیید ←</a>
</div>
<?php endif; ?>



<div class="stats-grid">
    <div class="stats-card">
        <div class="stats-card-title">امروز</div>
        <div class="stats-row"><span class="stats-label">درآمد</span><span class="stats-value stats-income"><?= formatMoney($todayStats['income']) ?> <small>تومان</small></span></div>
        <div class="stats-row"><span class="stats-label">هزینه</span><span class="stats-value stats-expense"><?= formatMoney($todayStats['expense']) ?> <small>تومان</small></span></div>
        <div class="stats-row stats-net-row"><span class="stats-label">سود خالص</span><span class="stats-value <?= $todayStats['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($todayStats['net']) ?> <small>تومان</small></span></div>
    </div>
    <div class="stats-card">
        <div class="stats-card-title">این هفته</div>
        <div class="stats-row"><span class="stats-label">درآمد</span><span class="stats-value stats-income"><?= formatMoney($weekStats['income']) ?> <small>تومان</small></span></div>
        <div class="stats-row"><span class="stats-label">هزینه</span><span class="stats-value stats-expense"><?= formatMoney($weekStats['expense']) ?> <small>تومان</small></span></div>
        <div class="stats-row stats-net-row"><span class="stats-label">سود خالص</span><span class="stats-value <?= $weekStats['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($weekStats['net']) ?> <small>تومان</small></span></div>
    </div>
    <div class="stats-card">
        <div class="stats-card-title">این ماه</div>
        <div class="stats-row"><span class="stats-label">درآمد</span><span class="stats-value stats-income"><?= formatMoney($monthStats['income']) ?> <small>تومان</small></span></div>
        <div class="stats-row"><span class="stats-label">هزینه</span><span class="stats-value stats-expense"><?= formatMoney($monthStats['expense']) ?> <small>تومان</small></span></div>
        <div class="stats-row stats-net-row"><span class="stats-label">سود خالص</span><span class="stats-value <?= $monthStats['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($monthStats['net']) ?> <small>تومان</small></span></div>
    </div>
    <div class="stats-card">
        <div class="stats-card-title">امسال</div>
        <div class="stats-row"><span class="stats-label">درآمد</span><span class="stats-value stats-income"><?= formatMoney($yearStats['income']) ?> <small>تومان</small></span></div>
        <div class="stats-row"><span class="stats-label">هزینه</span><span class="stats-value stats-expense"><?= formatMoney($yearStats['expense']) ?> <small>تومان</small></span></div>
        <div class="stats-row stats-net-row"><span class="stats-label">سود خالص</span><span class="stats-value <?= $yearStats['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($yearStats['net']) ?> <small>تومان</small></span></div>
    </div>
</div>

<!-- ---------- راه ورود به گزارش دسته‌بندی ----------
     تا حالا این گزارش فقط از شیت «بیشتر» پیدا می‌شد، در حالی که جایش
     دقیقاً همین‌جاست: کاربر عددِ کلِ هزینه‌ی ماه را می‌بیند و سؤال بعدی‌اش
     «این پول کجا رفت؟» است. دو دکمه چون هزینه و درآمد دو گزارش جدا هستند
     و رنگشان هم همان رنگی است که در خودِ گزارش می‌بیند. -->
<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">گزارش دسته‌بندی</h2>
        <span class="breakdown-cta-period">این ماه</span>
    </div>
    <p class="hint" style="margin:-4px 0 12px;">ببینید پولتان در هر دسته چقدر بوده — با نمودار و سهم درصدی.</p>
    <div class="breakdown-cta">
        <a href="<?= APP_BASE_PATH ?>/category-report.php?type=expense&preset=this_month" class="breakdown-cta-btn breakdown-cta-out">
            <span class="breakdown-cta-icon">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M12 21l-6-6M12 21l6-6"/></svg>
            </span>
            <span class="breakdown-cta-text">
                <span class="breakdown-cta-title">هزینه‌ها</span>
                <span class="breakdown-cta-amount"><?= formatMoney($monthStats['expense']) ?></span>
            </span>
        </a>
        <a href="<?= APP_BASE_PATH ?>/category-report.php?type=income&preset=this_month" class="breakdown-cta-btn breakdown-cta-in">
            <span class="breakdown-cta-icon">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21V3M12 3L6 9M12 3l6 6"/></svg>
            </span>
            <span class="breakdown-cta-text">
                <span class="breakdown-cta-title">درآمدها</span>
                <span class="breakdown-cta-amount"><?= formatMoney($monthStats['income']) ?></span>
            </span>
        </a>
    </div>
</div>

<?php if ($hasAnyData): ?>
<div class="card">
    <h2 class="card-title">روند مالی</h2>
    <div class="chart-range-tabs">
        <button type="button" class="chart-range-tab active" data-range="week">هفته</button>
        <button type="button" class="chart-range-tab" data-range="month">ماه</button>
        <button type="button" class="chart-range-tab" data-range="year">سال</button>
    </div>
    <div class="chart-container" style="max-width:100%; height:280px;">
        <canvas id="trendChart"></canvas>
    </div>
</div>
<?php else: ?>
<div class="card">
    <p style="text-align:center; color:var(--color-gray-500); padding:20px 0;">هنوز تراکنشی ثبت نکرده‌اید — نمودار روند بعد از اولین ثبت نمایش داده می‌شود.</p>
</div>
<?php endif; ?>

<?php if ($comparison !== null): ?>
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">مقایسه با ماه قبل</h2>
        <span class="collapse-chevron">▾</span>
    </div>

    <?php
    // ⛔ این خط عمداً **بیرون** از بدنه‌ی جمع‌شونده است.
    //    کارت پیش‌فرض بسته است، پس تنها سیگنالِ «این ماه برای من عادی
    //    است؟» تا وقتی کاربر بازش نکند دیده نمی‌شد. حالا یک جمله همیشه
    //    پیداست و — مهم‌تر — صریح می‌گوید مقایسه با چه بازه‌ای است،
    //    وگرنه «۷٪ بیشتر» معلوم نیست نسبت به چه.
    $cmpDelta = $comparison['expense_change'];
    ?>
    <p class="compare-summary">
        روز <strong><?= toPersianDigits($comparison['elapsed_days']) ?></strong>
        از <?= toPersianDigits($comparison['total_days']) ?> —
        تا همین روزِ <?= h($comparison['prev_label']) ?>
        <strong><?= formatMoney($comparison['prev_expense']) ?></strong> خرج کرده بودید،
        این ماه <strong><?= formatMoney($comparison['current_expense']) ?></strong>
        <?php if ($comparison['prev_expense'] > 0): ?>
            <span class="compare-summary-delta <?= $cmpDelta <= 0 ? 'delta-up' : 'delta-down' ?>">
                (<?= $cmpDelta >= 0 ? '▲' : '▼' ?> <?= toPersianDigits(abs($cmpDelta)) ?>٪)
            </span>
        <?php endif; ?>
    </p>

    <div class="collapsible-body">
        <div class="compare-row">
            <div class="compare-label">درآمد</div>
            <div class="compare-bars">
                <div class="compare-line">
                    <span class="compare-name"><?= h($comparison['prev_label']) ?></span>
                    <span class="compare-val"><?= formatMoney($comparison['prev_income']) ?></span>
                </div>
                <div class="compare-line">
                    <span class="compare-name"><?= h($comparison['current_label']) ?></span>
                    <span class="compare-val stats-income"><?= formatMoney($comparison['current_income']) ?></span>
                </div>
            </div>
            <div class="compare-delta <?= $comparison['income_change'] >= 0 ? 'delta-up' : 'delta-down' ?>">
                <?= $comparison['income_change'] >= 0 ? '▲' : '▼' ?> <?= toPersianDigits(abs($comparison['income_change'])) ?>٪
            </div>
        </div>

        <div class="compare-row">
            <div class="compare-label">هزینه</div>
            <div class="compare-bars">
                <div class="compare-line">
                    <span class="compare-name"><?= h($comparison['prev_label']) ?></span>
                    <span class="compare-val"><?= formatMoney($comparison['prev_expense']) ?></span>
                </div>
                <div class="compare-line">
                    <span class="compare-name"><?= h($comparison['current_label']) ?></span>
                    <span class="compare-val stats-expense"><?= formatMoney($comparison['current_expense']) ?></span>
                </div>
            </div>
            <div class="compare-delta <?= $comparison['expense_change'] <= 0 ? 'delta-up' : 'delta-down' ?>">
                <?= $comparison['expense_change'] >= 0 ? '▲' : '▼' ?> <?= toPersianDigits(abs($comparison['expense_change'])) ?>٪
            </div>
        </div>

        <?php if ($insights !== null && $insights['count'] > 0): ?>
            <div class="insight-block">
                <div class="stats-row">
                    <span class="stats-label">میانگین هزینه روزانه</span>
                    <span class="stats-value"><?= formatMoney($insights['daily_average']) ?> <small>تومان</small></span>
                </div>
                <div class="stats-row">
                    <span class="stats-label">تعداد هزینه‌های این ماه</span>
                    <span class="stats-value"><?= toPersianDigits($insights['count']) ?></span>
                </div>
            </div>

            <?php if (!empty($insights['top_expenses'])): ?>
                <h3 class="day-section-title">بزرگ‌ترین هزینه‌های این ماه</h3>
                <?php foreach ($insights['top_expenses'] as $te): ?>
                    <div class="event-row">
                        <span class="event-body">
                            <span class="event-title"><?= h($te['title']) ?></span>
                            <span class="event-meta"><?= toJalali($te['transaction_date']) ?><?= !empty($te['category_name']) ? ' · ' . h($te['category_name']) : '' ?></span>
                        </span>
                        <span class="event-amount amount-expense"><?= formatMoney($te['amount']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card collapsible-card <?= $customRangeSubmitted ? '' : 'collapsed' ?>">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">گزارش با بازه دلخواه</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
    <form method="GET" class="report-form">
        <div class="form-group">
            <label>از تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($fromDate) ?>">
                <input type="hidden" class="jdp-hidden" name="from_date" value="<?= h($fromDate) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>تا تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($toDate) ?>">
                <input type="hidden" class="jdp-hidden" name="to_date" value="<?= h($toDate) ?>">
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary">نمایش گزارش</button>
            <a href="export_transactions.php?from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-secondary">دانلود اکسل</a>
        </div>
    </form>

    <div class="summary-grid">
        <div class="summary-box"><div class="label">مجموع درآمد</div><div class="value stats-income"><?= formatMoney($rangeStats['income']) ?></div></div>
        <div class="summary-box"><div class="label">مجموع هزینه</div><div class="value stats-expense"><?= formatMoney($rangeStats['expense']) ?></div></div>
        <div class="summary-box"><div class="label">سود خالص</div><div class="value <?= $rangeStats['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($rangeStats['net']) ?></div></div>
        <div class="summary-box"><div class="label">تعداد درآمدها</div><div class="value"><?= toPersianDigits($incomeCount) ?></div></div>
        <div class="summary-box"><div class="label">تعداد هزینه‌ها</div><div class="value"><?= toPersianDigits($expenseCount) ?></div></div>
    </div>

    <?php if (empty($rangeDailyRows)): ?>
        <p style="text-align:center; color:var(--color-gray-500); padding:20px 0;">برای این بازه تراکنشی ثبت نشده است.</p>
    <?php endif; ?>
    </div>
</div>

<?php if ($hasAnyData): ?>
<?php foreach (assetUrls(['js/chart.umd.js']) as $__u): ?>
<script defer src="<?= h($__u) ?>"></script>
<?php endforeach; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var trendDatasets = {
        week:  { labels: <?= json_encode($weekLabels, JSON_UNESCAPED_UNICODE) ?>,   income: <?= json_encode($weekIncome) ?>,   expense: <?= json_encode($weekExpense) ?> },
        month: { labels: <?= json_encode($month30Labels, JSON_UNESCAPED_UNICODE) ?>, income: <?= json_encode($month30Income) ?>, expense: <?= json_encode($month30Expense) ?> },
        year:  { labels: <?= json_encode($monthlyLabels, JSON_UNESCAPED_UNICODE) ?>,  income: <?= json_encode($monthlyIncome) ?>,  expense: <?= json_encode($monthlyExpense) ?> }
    };

    var trendCtx = document.getElementById('trendChart').getContext('2d');
    var incomeGradient = trendCtx.createLinearGradient(0, 0, 0, 260);
    incomeGradient.addColorStop(0, 'rgba(21,128,61,0.30)');
    incomeGradient.addColorStop(1, 'rgba(21,128,61,0)');
    var expenseGradient = trendCtx.createLinearGradient(0, 0, 0, 260);
    expenseGradient.addColorStop(0, 'rgba(185,28,28,0.30)');
    expenseGradient.addColorStop(1, 'rgba(185,28,28,0)');

    var trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendDatasets.week.labels,
            datasets: [
                {
                    label: 'درآمد', data: trendDatasets.week.income,
                    borderColor: '#15803d', backgroundColor: incomeGradient,
                    fill: true, tension: 0.4, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2.5
                },
                {
                    label: 'هزینه', data: trendDatasets.week.expense,
                    borderColor: '#b91c1c', backgroundColor: expenseGradient,
                    fill: true, tension: 0.4, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2.5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { font: { family: 'Vazirmatn' } } } },
            scales: {
                y: { beginAtZero: true, ticks: { font: { family: 'Vazirmatn' } } },
                x: { ticks: { font: { family: 'Vazirmatn' }, autoSkip: true, maxTicksLimit: 8 } }
            }
        }
    });

    document.querySelectorAll('.chart-range-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.chart-range-tab').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var range = btn.getAttribute('data-range');
            var d = trendDatasets[range];
            trendChart.data.labels = d.labels;
            trendChart.data.datasets[0].data = d.income;
            trendChart.data.datasets[1].data = d.expense;
            trendChart.update();
        });
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
