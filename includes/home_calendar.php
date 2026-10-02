<?php
/**
 * تقویم — ماهِ شمسی با نقطه‌ی دریافت/پرداخت/سررسیدِ هر روز.
 *
 * **خواسته‌ی مالکِ نصب:** «تقویم رو از داخل یادآوری من بیار بیرون یه جای
 * خوشگل توی داشبورد چه در گوشی و چه در دسکتاپ براش بساز از جای قبلی
 * پاکش کن.» پیش از این زبانه‌ی دومِ `due.php` بود.
 *
 * ⛔ **جا** (دورِ دوم، باز خواسته‌ی مالکِ نصب): «در گوشی بهتره که در بخش
 *    گزارش‌ها کار بشه همون بالا چون صفحه خانه در گوشی خیلی شلوغ میشه…
 *    در دسکتاپ هم تقویم بهتر بیاد زیر تراکنش‌ها و حساب‌ها برگردن سر جای
 *    خودشون». پس:
 *    - گوشی: بالای `dashboard.php` (زبانه‌ی «گزارش») — خانه‌ی گوشی تقویم ندارد.
 *    - دسکتاپ: خانه، ستونِ اصلی، زیرِ «آخرین تراکنش‌ها» — همان جای خالیِ
 *      زیرِ ستونِ اصلی را پر می‌کند و ستونِ کناری دوباره با «مانده‌ی
 *      حساب‌ها» شروع می‌شود.
 *    `homeCalendarUrl()` با `deskView()` همان جایی را می‌دهد که کاربر
 *    تقویم را آنجا می‌بیند.
 *
 * ⛔ **یک رندرکننده:** هم پوسته‌ی خانه و هم پاسخِ `api/home_calendar.php`
 *    از همین `homeCalendarHtml()` می‌آیند. با دو رندرکننده (PHP برای
 *    بارِ اول، JS برای ماهِ بعد) اولین تغییرِ یکی، تقویمِ ماهِ بعد را با
 *    ماهِ جاری متفاوت می‌کرد.
 *
 * ⛔ **خانه هیچ کوئریِ تازه‌ای نمی‌زند.** پوسته (شماره‌ی روزها، نامِ ماه)
 *    بی‌دیتابیس است و نقطه‌ها و جمعِ ماه بعد از بارگذاری از
 *    `api/home_calendar.php` می‌آیند. `financialEvents()` چند کوئری است
 *    و خانه پربازدیدترین صفحه است — بودجه‌اش (`test_query_budget`) عوض
 *    نشد. نقطه‌ها جای ثابتِ خودشان را دارند، پس رسیدنشان چیزی را جابه‌جا
 *    نمی‌کند.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

const HCAL_MONTHS = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                     'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
const HCAL_WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
const HCAL_WEEKDAYS_LONG = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

/** ماهِ امروز به شمسی. */
function homeCalendarToday(): array
{
    [$gy, $gm, $gd] = array_map('intval', explode('-', today()));
    return gregorianToJalali($gy, $gm, $gd);
}

/**
 * ورودیِ `jy`/`jm` → ماهِ معتبر. هر چیزِ بیرون از بازه (یا خالی) = ماهِ
 * امروز؛ ماهِ ۰ و ۱۳ به سالِ قبل/بعد می‌روند تا «ماهِ قبل» از فروردین
 * درست کار کند.
 *
 * @return array{0:int,1:int}
 */
function homeCalendarMonth(int $jy, int $jm): array
{
    [$ty, $tm] = homeCalendarToday();
    if ($jy === 0 && $jm === 0) { return [$ty, $tm]; }
    if ($jm < 1)  { $jm = 12; $jy--; }
    if ($jm > 12) { $jm = 1;  $jy++; }
    if ($jy < 1300 || $jy > 1500) { return [$ty, $tm]; }
    return [$jy, $jm];
}

/**
 * ⛔ تنها سازنده‌ی آدرسِ تقویم. هم `calendar.php` (استابِ قدیمی) و هم
 *    `due.php?t=calendar` (لینک‌های فرستاده‌شده) به همین می‌روند، و لینکِ
 *    بی‌جاوااسکریپتِ ماهِ قبل/بعد هم. ماهِ جاری پارامتر نمی‌گیرد.
 * ⛔ صفحه از `deskView()`: دسکتاپ `index.php`، گوشی `dashboard.php`. با
 *    صفحه‌ی ثابت، لینکِ گوشی به لنگری می‌رفت که آن صفحه ندارد.
 */
function homeCalendarUrl(?int $jy = null, ?int $jm = null): string
{
    $q = '';
    if ($jy !== null && $jm !== null) {
        [$ty, $tm] = homeCalendarToday();
        [$jy, $jm] = homeCalendarMonth($jy, $jm);
        if ($jy !== $ty || $jm !== $tm) { $q = '?jy=' . $jy . '&jm=' . $jm; }
    }
    return APP_BASE_PATH . '/' . (deskView() ? 'index.php' : 'dashboard.php') . $q . '#cal';
}

/** اولین و آخرین روزِ ماهِ شمسی به میلادی. */
function homeCalendarRange(int $jy, int $jm): array
{
    $s = jalaliToGregorian($jy, $jm, 1);
    $e = jalaliToGregorian($jy, $jm, jalaliMonthLength($jy, $jm));
    return [sprintf('%04d-%02d-%02d', $s[0], $s[1], $s[2]), sprintf('%04d-%02d-%02d', $e[0], $e[1], $e[2])];
}

/**
 * داده‌ی یک ماه: پرچم‌های هر روز و جمع‌ها.
 *
 * ⛔ رویدادها فقط از `financialEvents()` — همان تابعی که «سررسیدها» و
 *    «پول قابل خرج» از آن می‌خوانند. تقویمی که سررسیدِ خودش را حساب کند،
 *    دیر یا زود روزی را نشان می‌داد که فهرستِ سررسیدها نمی‌شناخت.
 * ⚠ جمعِ ماه فقط درآمد/هزینه‌ی **ثبت‌شده** است: انتقال و تعدیل ردیفِ
 *   `transactions` نیستند (`docs/decisions/money.md`).
 *
 * @return array{days:array<string,array>, in:int, out:int, dues:int, late:int}
 */
function homeCalendarData(int $userId, int $jy, int $jm): array
{
    [$from, $to] = homeCalendarRange($jy, $jm);
    $out = ['days' => [], 'in' => 0, 'out' => 0, 'dues' => 0, 'late' => 0];

    foreach (financialEvents($userId, $from, $to) as $e) {
        $d = $e['date'];
        $out['days'][$d][$e['direction'] === 'in' ? 'in' : 'out'] = true;
        $out['days'][$d]['due'] = true;
        $out['dues']++;
        if (!empty($e['is_overdue'])) { $out['days'][$d]['late'] = true; $out['late']++; }
    }

    try {
        $st = Database::getConnection()->prepare("
            SELECT transaction_date AS d,
                   SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END) AS i,
                   SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS o
              FROM transactions
             WHERE user_id = :u AND transaction_date BETWEEN :f AND :t
             GROUP BY transaction_date
        ");
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        foreach ($st->fetchAll() as $r) {
            if ((int)$r['i'] > 0) { $out['days'][$r['d']]['in'] = true; $out['days'][$r['d']]['i'] = (int)$r['i']; }
            if ((int)$r['o'] > 0) { $out['days'][$r['d']]['out'] = true; $out['days'][$r['d']]['o'] = (int)$r['o']; }
            $out['in']  += (int)$r['i'];
            $out['out'] += (int)$r['o'];
        }
    } catch (PDOException $e) { /* جدول هنوز نیامده — تقویم بی‌نقطه، نه صفحه‌ی شکسته */ }

    return $out;
}

/**
 * مبلغِ کوتاه برای خانه‌ی روز: «۸۵۰ هزار»، «۱٫۵ میلیون»، «۲ میلیارد».
 * ⚠ فقط نمایشِ فشرده است؛ عددِ دقیق در `title` همان خانه می‌ماند و جمعِ
 *   ماه با `formatMoney()` کامل نوشته می‌شود.
 */
function hcalShort(int $v): string
{
    $v = abs($v);
    foreach ([[1000000000, 'میلیارد'], [1000000, 'میلیون'], [1000, 'هزار']] as [$u, $w]) {
        if ($v >= $u) {
            $x = round($v / $u, $v >= 10 * $u ? 0 : 1);
            $t = rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');
            return toPersianDigits(str_replace('.', '٫', $t)) . ' ' . $w;
        }
    }
    return toPersianDigits((string)$v);
}

/**
 * درونِ کارتِ تقویم (`.hcal-inner`). `$data === null` یعنی پوسته — همان
 * شبکه، بی‌نقطه و بی‌جمع، با `data-pending` تا `app.js` داده را بیاورد.
 *
 * ⚠ هفته‌ی امروز `is-wk` می‌گیرد: وقتی کاربر کارت را جمع کرده باشد
 *   (`.hcal.is-week`) فقط همان ردیف دیده می‌شود.
 *   روزهای ماهِ قبل/بعد که ردیفِ اول و آخر را پر می‌کنند کم‌رنگ‌اند و
 *   تپ نمی‌خورند — بی‌آن‌ها نوارِ هفته در اولِ ماه نیمه‌خالی بود.
 */
function homeCalendarHtml(int $jy, int $jm, ?array $data): string
{
    $today = today();
    [$ty, $tm] = homeCalendarToday();
    $isNow = ($jy === $ty && $jm === $tm);
    $len   = jalaliMonthLength($jy, $jm);
    [$from] = homeCalendarRange($jy, $jm);
    $lead  = ((int)date('w', strtotime($from)) + 1) % 7;   // شنبه = ۰
    $cells = $lead + $len;
    $rows  = (int)ceil($cells / 7);

    // ردیفِ هفته‌ی امروز (یا ردیفِ اول برای ماهِ دیگر)
    $focus = 0;
    if ($isNow) {
        $todayJd = homeCalendarToday()[2];
        $focus = intdiv($lead + $todayJd - 1, 7);
    }

    $pJm = $jm - 1; $pJy = $jy; if ($pJm < 1)  { $pJm = 12; $pJy--; }
    $nJm = $jm + 1; $nJy = $jy; if ($nJm > 12) { $nJm = 1;  $nJy++; }
    $pLen = jalaliMonthLength($pJy, $pJm);

    $days = $data['days'] ?? [];
    ob_start();
    ?>
<div class="hcal-inner"<?= $data === null ? ' data-pending="1"' : '' ?> data-jy="<?= $jy ?>" data-jm="<?= $jm ?>" data-now="<?= $isNow ? '1' : '0' ?>">
    <div class="hcal-head">
        <div class="hcal-title">
            <span class="hcal-kicker">تقویم</span>
            <strong><?= h(HCAL_MONTHS[$jm]) ?> <?= toPersianDigits((string)$jy) ?></strong>
        </div>
        <div class="hcal-tools">
            <?php if (!$isNow): ?>
                <a class="hcal-now js-hcal-go" href="<?= h(homeCalendarUrl()) ?>" data-jy="<?= $ty ?>" data-jm="<?= $tm ?>">امروز</a>
            <?php endif; ?>
            <a class="hcal-nav js-hcal-go" href="<?= h(homeCalendarUrl($pJy, $pJm)) ?>" data-jy="<?= $pJy ?>" data-jm="<?= $pJm ?>" aria-label="ماهِ قبل">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
            </a>
            <a class="hcal-nav js-hcal-go" href="<?= h(homeCalendarUrl($nJy, $nJm)) ?>" data-jy="<?= $nJy ?>" data-jm="<?= $nJm ?>" aria-label="ماهِ بعد">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
        </div>
    </div>

    <div class="hcal-week" aria-hidden="true">
        <?php foreach (HCAL_WEEKDAYS as $i => $w): ?><span<?= $i === 6 ? ' class="is-fri"' : '' ?>><i class="hcal-wd-s"><?= h($w) ?></i><i class="hcal-wd-l"><?= h(HCAL_WEEKDAYS_LONG[$i]) ?></i></span><?php endforeach; ?>
    </div>

    <div class="hcal-grid">
        <?php for ($i = 0; $i < $rows * 7; $i++):
            $row = intdiv($i, 7);
            $wk  = $row === $focus ? ' is-wk' : '';
            $fri = $i % 7 === 6 ? ' is-fri' : '';
            $d   = $i - $lead + 1;
            if ($d < 1 || $d > $len):
                $n = $d < 1 ? $pLen + $d : $d - $len; ?>
            <span class="hcal-d is-adj<?= h($wk . $fri) ?>" aria-hidden="true"><span class="hcal-n"><?= toPersianDigits((string)$n) ?></span></span>
        <?php else:
                $g  = jalaliToGregorian($jy, $jm, $d);
                $gd = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
                $f  = $days[$gd] ?? [];
                $cls = 'hcal-d js-hcal-day' . $wk . $fri
                     . ($gd === $today ? ' is-today' : '')
                     . ($gd < $today ? ' is-past' : '')
                     . ($f ? ' has-any' : ''); ?>
            <button type="button" class="<?= h($cls) ?>" data-date="<?= h($gd) ?>" data-label="<?= h(jalaliLongDate($gd)) ?>">
                <span class="hcal-n"><?= toPersianDigits((string)$d) ?></span>
                <span class="hcal-dots"><?php
                    if (!empty($f['in']))   { echo '<i class="hcal-dot is-in"></i>'; }
                    if (!empty($f['out']))  { echo '<i class="hcal-dot is-out"></i>'; }
                    if (!empty($f['late'])) { echo '<i class="hcal-dot is-late"></i>'; }
                    elseif (!empty($f['due']) && $gd >= $today) { echo '<i class="hcal-dot is-due"></i>'; }
                ?></span>
                <?php /* ⚠ مبلغِ روز فقط وقتی کارت پهن است دیده می‌شود (container
                         query در `style.css`) — روی گوشی خانه جا ندارد.
                         ⚠ بی‌علامتِ +/−: کنارِ «میلیون» در متنِ راست‌به‌چپ
                         جابه‌جا می‌نشست («۱٫۲− میلیون»)؛ رنگ جهت را می‌گوید. */ ?>
                <?php if (!empty($f['i']) || !empty($f['o'])): ?>
                <span class="hcal-amt"><?php
                    if (!empty($f['i'])) { echo '<b class="is-in" title="' . h(formatMoney($f['i'])) . '">' . h(hcalShort($f['i'])) . '</b>'; }
                    if (!empty($f['o'])) { echo '<b class="is-out" title="' . h(formatMoney($f['o'])) . '">' . h(hcalShort($f['o'])) . '</b>'; }
                ?></span>
                <?php endif; ?>
            </button>
        <?php endif; endfor; ?>
    </div>

    <div class="hcal-foot">
        <?php if ($data === null): ?>
            <span class="hcal-chip is-wait">&nbsp;</span>
        <?php else: ?>
            <span class="hcal-chip is-in" title="دریافتیِ ثبت‌شده‌ی این ماه"><i class="hcal-dot is-in"></i>دریافتی <b class="ltr-num"><?= formatMoney((int)$data['in']) ?></b></span>
            <span class="hcal-chip is-out" title="پرداختیِ ثبت‌شده‌ی این ماه"><i class="hcal-dot is-out"></i>پرداختی <b class="ltr-num"><?= formatMoney((int)$data['out']) ?></b></span>
            <?php if ($data['late'] > 0): ?>
                <a class="hcal-chip is-late" href="<?= APP_BASE_PATH ?>/due.php?t=list&amp;f=overdue"><i class="hcal-dot is-late"></i><?= toPersianDigits((string)$data['late']) ?> سررسیدِ گذشته</a>
            <?php elseif ($data['dues'] > 0): ?>
                <a class="hcal-chip is-due" href="<?= APP_BASE_PATH ?>/due.php?t=list"><i class="hcal-dot is-due"></i><?= toPersianDigits((string)$data['dues']) ?> سررسید</a>
            <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="hcal-toggle js-hcal-toggle" aria-expanded="false">
            <span class="hcal-toggle-more">ماهِ کامل</span><span class="hcal-toggle-less">فقط این هفته</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>
    </div>
</div>
    <?php
    return (string)ob_get_clean();
}

/**
 * کلِ کارت. ⛔ `id="cal"` مقصدِ `homeCalendarUrl()` است، پس هر صفحه فقط
 * یک بار صدایش می‌زند.
 *
 * ⚠ روی خانه‌ی دسکتاپ (`$desk`) همیشه ماهِ کامل است و دکمه‌ی «فقط این
 *   هفته» ندارد. در «گزارش» پیش‌فرض ماهِ کامل است و کاربر می‌تواند به نوارِ
 *   هفته جمعش کند؛ اسکریپتِ کوچکِ داخلِ کارت آن انتخاب را **پیش از اولین
 *   نقاشی** از `localStorage` برمی‌گرداند — در `app.js` (که `defer` است)
 *   کارت اول ماه و بعد هفته می‌شد، ۲۰۰ پیکسل پرش زیرِ انگشت.
 */
function renderHomeCalendar(int $jy, int $jm, bool $desk = false): void
{
    ?>
<section class="card hcal<?= $desk ? ' is-desk' : '' ?>" id="cal" aria-label="تقویم">
    <?= homeCalendarHtml($jy, $jm, null) ?>
    <div class="hcal-day" hidden>
        <div class="hcal-day-head">
            <strong class="hcal-day-title"></strong>
            <button type="button" class="hcal-day-x js-hcal-close" aria-label="بستن">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="hcal-day-body"></div>
    </div>
</section>
<?php if (!$desk): ?>
<script>(function () { try {
    var c = document.getElementById('cal'), i = c && c.querySelector('.hcal-inner');
    if (c && localStorage.getItem('daftar_hcal_month') === '0' && i && i.getAttribute('data-now') === '1') { c.classList.add('is-week'); }
} catch (e) {} })();</script>
<?php endif; ?>
    <?php
}
