<?php
/**
 * ⛔ تکه‌های مشترکِ «داشبورد» — `dashboard.php` و نمای دسکتاپِ خانه
 *    (`index.php`، فقط وقتی `deskView()` درست است) هر دو از همین‌جا رندر
 *    می‌کنند.
 *
 * **خواسته‌ی مالکِ نصب:** «روی دسکتاپ خانه و داشبورد با هم ادغام بشن…
 * داشبورد بیاد ادامه‌ی خانه.» با کپی کردنِ HTML در دو صفحه، اولین تغییرِ
 * کارتِ سالانه فقط به یکی می‌رسید و کاربر بسته به اینکه از کجا نگاه کند
 * دو عددِ متفاوت می‌دید. پس داده و رندر هر دو فقط اینجا هستند.
 *
 * فقط تابع؛ هیچ متغیرِ سراسری‌ای نمی‌سازد (قاعده ۵۰).
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

/**
 * جمعِ روزانه‌ی کلِ تاریخچه — **یک** کوئری؛ همه‌ی بازه‌های داشبورد
 * (سال و فصل و ماه، روندِ هفته/ماه/سال، بازه‌ی دلخواه) از رویش ساخته
 * می‌شوند. پوششیِ `idx_user_date_created` است.
 */
function dashDailyRows(int $userId): array
{
    $st = Database::getConnection()->prepare('
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
    $st->execute(['user_id' => $userId]);
    return $st->fetchAll();
}

/**
 * سالِ کارتِ چهارفصل — `?y=` فقط بینِ «اولین سالِ دارای تراکنش» و امسال
 * پذیرفته می‌شود؛ هر مقدارِ دیگری امسال است، نه صفحه‌ی خالی.
 * @return array{report:array, year:int, prev:?int, next:?int}
 */
function dashYearState(array $dailyRows, string $today, $yParam): array
{
    [$ty, , ] = gregorianToJalali((int)date('Y', strtotime($today)), (int)date('m', strtotime($today)), (int)date('d', strtotime($today)));
    $first = $ty;
    if ($dailyRows) {
        [$fy, $fm, $fd] = array_map('intval', explode('-', $dailyRows[0]['transaction_date']));
        [$first, , ] = gregorianToJalali($fy, $fm, $fd);
    }
    $min  = min($first, $ty);
    $year = (int)toLatinDigits((string)$yParam);
    if ($year < $min || $year > $ty) { $year = $ty; }
    return [
        'report' => seasonalYearReport($dailyRows, $year, $today),
        'year'   => $year,
        'prev'   => $year > $min ? $year - 1 : null,
        'next'   => $year < $ty ? $year + 1 : null,
    ];
}

/**
 * سری‌های نمودارِ روند: ۷ روز، ۳۰ روز، و ۱۲ ماهِ شمسیِ اخیر.
 * @return array{week:array, month:array, year:array}
 */
function dashTrendSeries(array $dailyRows): array
{
    $map = [];
    foreach ($dailyRows as $r) { $map[$r['transaction_date']] = $r; }

    $days = function (int $n) use ($map): array {
        $out = ['labels' => [], 'income' => [], 'expense' => []];
        for ($i = $n - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out['labels'][]  = toJalali($d);
            $out['income'][]  = isset($map[$d]) ? (int)$map[$d]['daily_income'] : 0;
            $out['expense'][] = isset($map[$d]) ? (int)$map[$d]['daily_expense'] : 0;
        }
        return $out;
    };

    $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    [$jy, $jm, ] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
    $buckets = [];
    for ($i = 0; $i < 12; $i++) {
        $buckets[sprintf('%04d-%02d', $jy, $jm)] = ['jm' => $jm, 'income' => 0, 'expense' => 0];
        if (--$jm < 1) { $jm = 12; $jy--; }
    }
    $buckets = array_reverse($buckets, true);
    foreach ($dailyRows as $r) {
        [$gy, $gm, $gd] = array_map('intval', explode('-', $r['transaction_date']));
        [$ry, $rm, ] = gregorianToJalali($gy, $gm, $gd);
        $k = sprintf('%04d-%02d', $ry, $rm);
        if (isset($buckets[$k])) {
            $buckets[$k]['income']  += (int)$r['daily_income'];
            $buckets[$k]['expense'] += (int)$r['daily_expense'];
        }
    }
    $year = ['labels' => [], 'income' => [], 'expense' => []];
    foreach ($buckets as $b) {
        $year['labels'][]  = $names[$b['jm']];
        $year['income'][]  = $b['income'];
        $year['expense'][] = $b['expense'];
    }
    return ['week' => $days(7), 'month' => $days(30), 'year' => $year];
}

/**
 * کارتِ سالانه‌ی چهارفصل. `$self` صفحه‌ای است که ناوبریِ سال به آن
 * برمی‌گردد (داشبورد یا خانه)، تا کاربر از صفحه‌ای که در آن است بیرون
 * پرت نشود.
 */
function renderYearReportCard(array $ys, string $self): void
{
    $yr  = $ys['report'];
    $nav = fn(int $y): string => APP_BASE_PATH . '/' . $self . '?y=' . $y . '#year';
    ?>
<div class="card year-report" id="year">
    <div class="year-report-head">
        <?php if ($ys['prev'] !== null): ?>
            <a class="year-nav" href="<?= h($nav($ys['prev'])) ?>" aria-label="سال قبل">‹ <?= toPersianDigits($ys['prev']) ?></a>
        <?php else: ?><span class="year-nav is-off"></span><?php endif; ?>
        <a class="year-title" href="<?= h(txRangeUrl($yr['from'], $yr['to'], $yr['year'])) ?>">
            سال <?= toPersianDigits($ys['year']) ?>
        </a>
        <?php if ($ys['next'] !== null): ?>
            <a class="year-nav" href="<?= h($nav($ys['next'])) ?>" aria-label="سال بعد"><?= toPersianDigits($ys['next']) ?> ›</a>
        <?php else: ?><span class="year-nav is-off"></span><?php endif; ?>
    </div>
    <div class="year-totals">
        <div><span class="stats-label">درآمد</span><span class="stats-value stats-income ltr-num"><?= formatMoney($yr['income']) ?></span></div>
        <div><span class="stats-label">هزینه</span><span class="stats-value stats-expense ltr-num"><?= formatMoney($yr['expense']) ?></span></div>
        <div><span class="stats-label">خالص</span><span class="stats-value ltr-num <?= $yr['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($yr['net']) ?></span></div>
    </div>
    <div class="season-grid">
        <?php foreach ($yr['seasons'] as $s): ?>
        <div class="season-card season-<?= h($s['key']) ?><?= $s['current'] ? ' is-current' : '' ?><?= $s['future'] ? ' is-future' : '' ?>">
            <a class="season-head" href="<?= h(txRangeUrl($s['from'], $s['to'], $yr['year'])) ?>">
                <span class="season-name"><?= h($s['name']) ?></span>
                <span class="season-net ltr-num <?= $s['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($s['net']) ?></span>
            </a>
            <div class="season-sub">
                <span>درآمد <b class="ltr-num stats-income"><?= formatMoney($s['income']) ?></b></span>
                <span>هزینه <b class="ltr-num stats-expense"><?= formatMoney($s['expense']) ?></b></span>
            </div>
            <?php foreach ($s['months'] as $m): ?>
            <a class="season-month<?= $m['current'] ? ' is-current' : '' ?><?= $m['future'] ? ' is-future' : '' ?>" href="<?= h(txRangeUrl($m['from'], $m['to'], $yr['year'])) ?>">
                <span class="season-month-name"><?= h($m['name']) ?></span>
                <span class="season-month-val ltr-num <?= $m['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($m['net']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="hint year-hint">مبالغ به تومان · روی سال، فصل یا ماه بزنید تا تراکنش‌های همان بازه را ببینید.</p>
</div>
    <?php
}

/** راهِ ورود به گزارشِ دسته‌بندی — هزینه و درآمدِ همین ماه. */
function renderBreakdownCta(int $income, int $expense): void
{
    ?>
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
                <span class="breakdown-cta-amount"><?= formatMoney($expense) ?></span>
            </span>
        </a>
        <a href="<?= APP_BASE_PATH ?>/category-report.php?type=income&preset=this_month" class="breakdown-cta-btn breakdown-cta-in">
            <span class="breakdown-cta-icon">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21V3M12 3L6 9M12 3l6 6"/></svg>
            </span>
            <span class="breakdown-cta-text">
                <span class="breakdown-cta-title">درآمدها</span>
                <span class="breakdown-cta-amount"><?= formatMoney($income) ?></span>
            </span>
        </a>
    </div>
</div>
    <?php
}

/** کارتِ «روند مالی» با اسکریپتِ نمودارش. بی‌داده، یک جمله — نه نمودارِ خالی. */
function renderTrendCard(array $dailyRows): void
{
    if (!$dailyRows) { ?>
<div class="card">
    <p style="text-align:center; color:var(--color-gray-500); padding:20px 0;">هنوز تراکنشی ثبت نکرده‌اید — نمودار روند بعد از اولین ثبت نمایش داده می‌شود.</p>
</div>
        <?php
        return;
    }
    $series = dashTrendSeries($dailyRows);
    ?>
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
<?php foreach (assetUrls(['js/chart.umd.js']) as $u): ?>
<script defer src="<?= h($u) ?>"></script>
<?php endforeach; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var trendDatasets = <?= json_encode($series, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var el = document.getElementById('trendChart');
    if (!el || typeof Chart === 'undefined') { return; }
    var trendCtx = el.getContext('2d');
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
                { label: 'درآمد', data: trendDatasets.week.income, borderColor: '#15803d', backgroundColor: incomeGradient,
                  fill: true, tension: 0.4, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2.5 },
                { label: 'هزینه', data: trendDatasets.week.expense, borderColor: '#b91c1c', backgroundColor: expenseGradient,
                  fill: true, tension: 0.4, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2.5 }
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
            var d = trendDatasets[btn.getAttribute('data-range')];
            trendChart.data.labels = d.labels;
            trendChart.data.datasets[0].data = d.income;
            trendChart.data.datasets[1].data = d.expense;
            trendChart.update();
        });
    });
});
</script>
    <?php
}

/** «مقایسه با ماه قبل» — جمع‌شده، با جمله‌ی خلاصه‌ی همیشه‌پیدا. */
function renderMonthComparisonCard(?array $comparison, ?array $insights): void
{
    if ($comparison === null) { return; }
    $cmpDelta = $comparison['expense_change'];
    ?>
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">مقایسه با ماه قبل</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <?php /* ⛔ این خط عمداً **بیرون** از بدنه‌ی جمع‌شونده است: کارت پیش‌فرض
             بسته است و بدونِ آن تنها سیگنالِ «این ماه برای من عادی است؟»
             تا باز نکردن دیده نمی‌شد — و صریح می‌گوید نسبت به چه بازه‌ای. */ ?>
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
        <?php foreach ([['درآمد', 'income', 'stats-income', true], ['هزینه', 'expense', 'stats-expense', false]] as [$lbl, $k, $cls, $upGood]):
            $chg = $comparison[$k . '_change']; ?>
        <div class="compare-row">
            <div class="compare-label"><?= $lbl ?></div>
            <div class="compare-bars">
                <div class="compare-line">
                    <span class="compare-name"><?= h($comparison['prev_label']) ?></span>
                    <span class="compare-val"><?= formatMoney($comparison['prev_' . $k]) ?></span>
                </div>
                <div class="compare-line">
                    <span class="compare-name"><?= h($comparison['current_label']) ?></span>
                    <span class="compare-val <?= $cls ?>"><?= formatMoney($comparison['current_' . $k]) ?></span>
                </div>
            </div>
            <div class="compare-delta <?= ($upGood ? $chg >= 0 : $chg <= 0) ? 'delta-up' : 'delta-down' ?>">
                <?= $chg >= 0 ? '▲' : '▼' ?> <?= toPersianDigits(abs($chg)) ?>٪
            </div>
        </div>
        <?php endforeach; ?>

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
    <?php
}
