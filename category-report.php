<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

$type = getParam('type', 'expense');
if (!in_array($type, ['income', 'expense'], true)) {
    $type = 'expense';
}

$preset = getParam('preset', 'this_month');

$thisMonthStart = startOfJalaliMonth();

[$curJy, $curJm, ] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));

// ماه قبل
$prevJy = $curJy; $prevJm = $curJm - 1;
if ($prevJm < 1) { $prevJm = 12; $prevJy--; }
[$py, $pm, $pd] = jalaliToGregorian($prevJy, $prevJm, 1);
$prevMonthStart = sprintf('%04d-%02d-%02d', $py, $pm, $pd);
$prevMonthEnd = date('Y-m-d', strtotime($thisMonthStart . ' -1 day'));

// ۳ ماه اخیر (شامل ماه جاری)
$q3Jy = $curJy; $q3Jm = $curJm - 2;
if ($q3Jm < 1) { $q3Jm += 12; $q3Jy--; }
[$qy, $qm, $qd] = jalaliToGregorian($q3Jy, $q3Jm, 1);
$quarterStart = sprintf('%04d-%02d-%02d', $qy, $qm, $qd);

$presets = [
    'this_month' => ['label' => 'این ماه',      'from' => $thisMonthStart, 'to' => $todayStr],
    'last_month' => ['label' => 'ماه قبل',       'from' => $prevMonthStart, 'to' => $prevMonthEnd],
    'quarter'    => ['label' => '۳ ماه اخیر',    'from' => $quarterStart,   'to' => $todayStr],
    'year'       => ['label' => 'امسال',         'from' => startOfJalaliYear(), 'to' => $todayStr],
];

if (isset($_GET['from_date']) || isset($_GET['to_date'])) {
    $preset = 'custom';
}

if ($preset !== 'custom' && isset($presets[$preset])) {
    $fromDate = $presets[$preset]['from'];
    $toDate   = $presets[$preset]['to'];
} else {
    $fromDate = getParam('from_date', $thisMonthStart);
    $toDate   = getParam('to_date', $todayStr);
    if (!isValidDate($fromDate)) $fromDate = $thisMonthStart;
    if (!isValidDate($toDate)) $toDate = $todayStr;
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    $preset = 'custom';
}

// ---------- تفکیک بر اساس دسته‌بندی ----------
$stmt = $pdo->prepare('
    SELECT c.id, c.name, COALESCE(SUM(t.amount), 0) AS total, COUNT(t.id) AS cnt
    FROM categories c
    LEFT JOIN transactions t
        ON t.category_id = c.id AND t.user_id = :user_id AND t.type = :type
        AND t.transaction_date BETWEEN :from_date AND :to_date
    WHERE c.type = :type2 AND c.is_active = 1
    GROUP BY c.id, c.name
    HAVING total > 0
    ORDER BY total DESC
');
$stmt->execute([
    'user_id' => $userId, 'type' => $type, 'type2' => $type,
    'from_date' => $fromDate, 'to_date' => $toDate,
]);
$categoryBreakdown = $stmt->fetchAll();

// تراکنش‌های بدون دسته‌بندی
$uncatStmt = $pdo->prepare('
    SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
    FROM transactions
    WHERE user_id = :user_id AND type = :type AND category_id IS NULL
      AND transaction_date BETWEEN :from_date AND :to_date
');
$uncatStmt->execute(['user_id' => $userId, 'type' => $type, 'from_date' => $fromDate, 'to_date' => $toDate]);
$uncatRow = $uncatStmt->fetch();
if ((int)$uncatRow['total'] > 0) {
    $categoryBreakdown[] = ['id' => 0, 'name' => 'بدون دسته‌بندی', 'total' => $uncatRow['total'], 'cnt' => $uncatRow['cnt']];
    usort($categoryBreakdown, fn($a, $b) => $b['total'] <=> $a['total']);
}

$grandTotal = array_sum(array_column($categoryBreakdown, 'total'));

// رنگ اینجا معنا دارد: هزینه گرم (قرمز/نارنجی/زرد)، درآمد سبز.
$palette = chartPalette($type === 'income' ? 'green' : 'warm');

$chartLabels = []; $chartValues = []; $chartColors = [];
foreach ($categoryBreakdown as $i => $row) {
    $chartLabels[] = $row['name'];
    $chartValues[] = (int)$row['total'];
    $chartColors[] = $palette[$i % count($palette)];
}

$pageTitle = 'گزارش دسته‌بندی';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="type-toggle" id="reportTypeToggle" style="margin-bottom:14px;">
        <a href="?type=expense&preset=<?= h($preset) ?><?= $preset === 'custom' ? '&from_date=' . urlencode($fromDate) . '&to_date=' . urlencode($toDate) : '' ?>"
           class="type-btn type-btn-expense <?= $type === 'expense' ? 'active' : '' ?>" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">هزینه‌ها</a>
        <a href="?type=income&preset=<?= h($preset) ?><?= $preset === 'custom' ? '&from_date=' . urlencode($fromDate) . '&to_date=' . urlencode($toDate) : '' ?>"
           class="type-btn type-btn-income <?= $type === 'income' ? 'active' : '' ?>" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">درآمدها</a>
    </div>

    <div class="filter-bar">
        <?php foreach ($presets as $key => $p): ?>
            <a href="?type=<?= h($type) ?>&preset=<?= h($key) ?>" class="filter-chip <?= $preset === $key ? 'active' : '' ?>" style="text-decoration:none;"><?= h($p['label']) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="report-form" style="margin-top:12px;">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="hidden" name="preset" value="custom">
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
        <button type="submit" class="btn btn-secondary btn-sm">بازه دلخواه</button>
    </form>
</div>

<div class="card">
    <div class="stats-row" style="padding-bottom:14px; border-bottom:1px solid var(--color-gray-100); margin-bottom:14px;">
        <span class="stats-label">مجموع <?= $type === 'expense' ? 'هزینه‌ها' : 'درآمدها' ?> در این بازه</span>
        <span class="stats-value <?= $type === 'expense' ? 'stats-expense' : 'stats-income' ?>"><?= formatMoney($grandTotal) ?> <small>تومان</small></span>
    </div>

    <?php if (empty($categoryBreakdown)): ?>
        <p style="text-align:center; color:var(--color-gray-500); padding:24px 0;">برای این بازه <?= $type === 'expense' ? 'هزینه‌ای' : 'درآمدی' ?> ثبت نشده است.</p>
    <?php else: ?>
        <div class="chart-container" style="max-width:280px; height:280px; margin:0 auto 20px;">
            <canvas id="categoryChart"></canvas>
        </div>

        <div class="category-breakdown-list">
            <?php foreach ($categoryBreakdown as $i => $row): ?>
                <?php $pct = $grandTotal > 0 ? round(((int)$row['total'] / $grandTotal) * 100, 1) : 0; ?>
                <div class="cat-breakdown-item">
                    <div class="cat-breakdown-summary <?= $row['id'] > 0 ? 'js-cat-toggle' : '' ?>" data-cat-id="<?= (int)$row['id'] ?>">
                        <span class="cat-dot" style="background:<?= h($chartColors[$i]) ?>;"></span>
                        <span class="cat-breakdown-name"><?= h($row['name']) ?></span>
                        <span class="cat-breakdown-pct"><?= toPersianDigits($pct) ?>٪</span>
                        <span class="cat-breakdown-amount"><?= formatMoney($row['total']) ?></span>
                    </div>
                    <div class="cat-breakdown-bar-track">
                        <div class="cat-breakdown-bar" style="width:<?= $pct ?>%; background:<?= h($chartColors[$i]) ?>;"></div>
                    </div>
                    <?php if ((int)$row['id'] > 0): ?>
                        <div class="cat-breakdown-detail" id="catDetail<?= (int)$row['id'] ?>" hidden></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">
<input type="hidden" id="reportFromDate" value="<?= h($fromDate) ?>">
<input type="hidden" id="reportToDate" value="<?= h($toDate) ?>">
<input type="hidden" id="reportType" value="<?= h($type) ?>">

<?php if (!empty($categoryBreakdown)): ?>
<script defer src="<?= APP_BASE_PATH ?>/assets/serve.php?f=js/chart.umd.js&v=<?= assetVersion(['js/chart.umd.js']) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new Chart(document.getElementById('categoryChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode($chartValues) ?>,
                backgroundColor: <?= json_encode($chartColors) ?>,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: { legend: { display: false } }
        }
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
