<?php
/**
 * زبانه‌ی «تقویم» — همان ماهِ شمسی، با نقطه‌ی رویداد روی هر روز.
 *
 * ⚠ قراردادِ ورودی صریح است — همان الگوی `header.php` با `$pageTitle`.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

$userId = $userId ?? (int)Auth::userId();
$today  = $today  ?? today();

[$todayJy, $todayJm, $todayJd] = gregorianToJalali(
    (int)date('Y', strtotime($today)),
    (int)date('m', strtotime($today)),
    (int)date('d', strtotime($today))
);

// ماه نمایش‌داده‌شده
$jy = (int)getParam('jy', (string)$todayJy);
$jm = (int)getParam('jm', (string)$todayJm);
if ($jm < 1) { $jm = 12; $jy--; }
if ($jm > 12) { $jm = 1; $jy++; }
if ($jy < 1300 || $jy > 1500) { $jy = $todayJy; $jm = $todayJm; }

$daysInMonth = jalaliMonthLength($jy, $jm);

// اولین و آخرین روز ماه به میلادی
$startG = jalaliToGregorian($jy, $jm, 1);
$endG   = jalaliToGregorian($jy, $jm, $daysInMonth);
$monthStart = sprintf('%04d-%02d-%02d', $startG[0], $startG[1], $startG[2]);
$monthEnd   = sprintf('%04d-%02d-%02d', $endG[0], $endG[1], $endG[2]);

// رویدادهای مالی این ماه
$events = financialEvents($userId, $monthStart, $monthEnd);
$eventsByDate = [];
foreach ($events as $e) {
    $eventsByDate[$e['date']][] = $e;
}

// تراکنش‌های واقعی این ماه (جمع روزانه)
$txByDate = [];
try {
    $txStmt = Database::getConnection()->prepare('
        SELECT transaction_date,
               SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END) AS income,
               SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) AS expense
        FROM transactions
        WHERE user_id = :u AND transaction_date BETWEEN :f AND :t
        GROUP BY transaction_date
    ');
    $txStmt->execute(['u' => $userId, 'f' => $monthStart, 't' => $monthEnd]);
    foreach ($txStmt->fetchAll() as $r) {
        $txByDate[$r['transaction_date']] = $r;
    }
} catch (PDOException $e) { /* ignore */ }

// روز اول ماه چه روزی از هفته است؟ (شنبه = ۰)
$firstWeekday = ((int)date('w', strtotime($monthStart)) + 1) % 7;

$monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
               'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

$prevJm = $jm - 1; $prevJy = $jy;
if ($prevJm < 1) { $prevJm = 12; $prevJy--; }
$nextJm = $jm + 1; $nextJy = $jy;
if ($nextJm > 12) { $nextJm = 1; $nextJy++; }

// جمع ماه
$monthIn = 0; $monthOut = 0;
foreach ($txByDate as $r) {
    $monthIn  += (int)$r['income'];
    $monthOut += (int)$r['expense'];
}
?>

<div class="card">
    <div class="cal-header">
        <?php /* ⚠ `t=calendar` در هر سه لینک لازم است، وگرنه جابه‌جا کردنِ
                 ماه کاربر را به زبانه‌ی پیش‌فرض برمی‌گرداند. */ ?>
        <a href="?t=calendar&jy=<?= $prevJy ?>&jm=<?= $prevJm ?>" class="cal-nav" aria-label="ماه قبل">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </a>
        <div class="cal-title">
            <?= h($monthNames[$jm]) ?> <?= toPersianDigits($jy) ?>
            <?php if ($jy !== $todayJy || $jm !== $todayJm): ?>
                <a href="?t=calendar" class="cal-today-link">برو به امروز</a>
            <?php endif; ?>
        </div>
        <a href="?t=calendar&jy=<?= $nextJy ?>&jm=<?= $nextJm ?>" class="cal-nav" aria-label="ماه بعد">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
        </a>
    </div>

    <div class="cal-weekdays">
        <span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span>
    </div>

    <div class="cal-grid">
        <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
            <div class="cal-cell cal-empty"></div>
        <?php endfor; ?>

        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
            <?php
            $g = jalaliToGregorian($jy, $jm, $d);
            $gDate = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
            $isToday = ($gDate === $today);
            $dayEvents = $eventsByDate[$gDate] ?? [];
            $dayTx = $txByDate[$gDate] ?? null;

            $hasIn  = $dayTx && (int)$dayTx['income'] > 0;
            $hasOut = $dayTx && (int)$dayTx['expense'] > 0;
            foreach ($dayEvents as $de) {
                if ($de['direction'] === 'in') { $hasIn = true; } else { $hasOut = true; }
            }
            $hasOverdue = false;
            foreach ($dayEvents as $de) {
                if ($de['is_overdue']) { $hasOverdue = true; break; }
            }
            $clickable = !empty($dayEvents) || $dayTx;
            ?>
            <div class="cal-cell <?= $isToday ? 'cal-today' : '' ?> <?= $clickable ? 'cal-has js-cal-day' : '' ?>"
                 <?= $clickable ? 'data-date="' . h($gDate) . '"' : '' ?>>
                <span class="cal-num"><?= toPersianDigits($d) ?></span>
                <span class="cal-dots">
                    <?php if ($hasIn): ?><i class="cal-dot cal-dot-in"></i><?php endif; ?>
                    <?php if ($hasOut): ?><i class="cal-dot cal-dot-out"></i><?php endif; ?>
                    <?php if ($hasOverdue): ?><i class="cal-dot cal-dot-late"></i><?php endif; ?>
                </span>
            </div>
        <?php endfor; ?>
    </div>

    <div class="cal-legend">
        <span><i class="cal-dot cal-dot-in"></i> دریافت</span>
        <span><i class="cal-dot cal-dot-out"></i> پرداخت</span>
        <span><i class="cal-dot cal-dot-late"></i> سررسید گذشته</span>
    </div>
</div>

<div class="summary-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="summary-box">
        <div class="label">دریافتی این ماه</div>
        <div class="value stats-income"><?= formatMoney($monthIn) ?></div>
    </div>
    <div class="summary-box">
        <div class="label">پرداختی این ماه</div>
        <div class="value stats-expense"><?= formatMoney($monthOut) ?></div>
    </div>
</div>

<div class="card" id="calDayCard" hidden>
    <h2 class="card-title" id="calDayTitle">جزئیات روز</h2>
    <div id="calDayBody"></div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">
