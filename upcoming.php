<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();
$todayStr = today();

$range = getParam('range', '30');
$daysAhead = in_array($range, ['7', '30', '90'], true) ? (int)$range : 30;

// شامل سررسیدهای گذشته‌ی تسویه‌نشده هم می‌شود (مهم‌اند، نباید پنهان بمانند)
$fromDate = date('Y-m-d', strtotime('-90 days'));
$toDate   = date('Y-m-d', strtotime("+{$daysAhead} days"));

$allEvents = financialEvents($userId, $fromDate, $toDate);

$overdue  = array_values(array_filter($allEvents, fn($e) => $e['is_overdue']));
$upcoming = array_values(array_filter($allEvents, fn($e) => !$e['is_overdue']));

$sts = safeToSpend($userId, $daysAhead);

$totalIn  = 0;
$totalOut = 0;
foreach ($upcoming as $e) {
    if ($e['direction'] === 'in') { $totalIn += $e['amount']; }
    else { $totalOut += $e['amount']; }
}

$pageTitle = 'آینده مالی';
include __DIR__ . '/includes/header.php';
?>

<?php if ($sts['available'] !== null): ?>
<div class="balance-ribbon">
    <div class="balance-label">پول قابل خرج</div>
    <div class="balance-value">
        <span class="bv-num"><?= $sts['available'] < 0 ? '−' : '' ?><?= formatMoney(abs($sts['available'])) ?></span>
        <span class="bv-unit">تومان</span>
    </div>
    <div class="balance-split">
        <div>
            <div class="bs-label">موجودی کل</div>
            <div class="bs-value"><?= formatMoney($sts['balance']) ?></div>
        </div>
        <div>
            <div class="bs-label">تعهدات <?= toPersianDigits($daysAhead) ?> روز آینده</div>
            <div class="bs-value bs-out"><?= formatMoney($sts['commitments']) ?></div>
        </div>
    </div>
    <p class="hint" style="color:#8d939e; margin-top:11px;">
        این عدد موجودی بانکی شما نیست؛ موجودی منهای پرداخت‌های قطعی پیش‌رو است.
    </p>
</div>
<?php endif; ?>

<div class="card">
    <div class="filter-bar">
        <a href="?range=7"  class="filter-chip <?= $daysAhead === 7  ? 'active' : '' ?>" style="text-decoration:none;">۷ روز</a>
        <a href="?range=30" class="filter-chip <?= $daysAhead === 30 ? 'active' : '' ?>" style="text-decoration:none;">۳۰ روز</a>
        <a href="?range=90" class="filter-chip <?= $daysAhead === 90 ? 'active' : '' ?>" style="text-decoration:none;">۹۰ روز</a>
    </div>

    <div class="summary-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom:0;">
        <div class="summary-box">
            <div class="label">دریافتی پیش‌رو</div>
            <div class="value stats-income"><?= formatMoney($totalIn) ?></div>
        </div>
        <div class="summary-box">
            <div class="label">پرداختی پیش‌رو</div>
            <div class="value stats-expense"><?= formatMoney($totalOut) ?></div>
        </div>
    </div>
</div>

<?php if (!empty($overdue)): ?>
<div class="card" style="border: 1px solid var(--out);">
    <h2 class="card-title" style="color: var(--out);">سررسید گذشته</h2>
    <?php foreach ($overdue as $e): ?>
        <a href="<?= h($e['url']) ?>" class="event-row">
            <span class="event-when event-overdue"><?= humanDaysUntil($e['date']) ?></span>
            <span class="event-body">
                <span class="event-title"><?= h($e['title']) ?></span>
                <span class="event-meta"><?= eventKindLabel($e['kind']) ?> · <?= toJalali($e['date']) ?></span>
            </span>
            <span class="event-amount <?= $e['direction'] === 'in' ? 'amount-income' : 'amount-expense' ?>">
                <?= $e['direction'] === 'in' ? '+' : '−' ?><?= formatMoney($e['amount']) ?>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">رویدادهای پیش‌رو</h2>
    <?php if (empty($upcoming)): ?>
        <p class="empty-row">در <?= toPersianDigits($daysAhead) ?> روز آینده رویداد مالی ثبت‌شده‌ای ندارید.</p>
    <?php else: ?>
        <?php
        $lastDate = null;
        foreach ($upcoming as $e):
            if ($lastDate !== $e['date']):
                $lastDate = $e['date'];
                ?>
                <div class="event-daybreak"><?= humanDaysUntil($e['date']) ?> — <?= toJalali($e['date']) ?></div>
            <?php endif; ?>
            <a href="<?= h($e['url']) ?>" class="event-row">
                <span class="event-dot event-dot-<?= $e['direction'] ?>"></span>
                <span class="event-body">
                    <span class="event-title"><?= h($e['title']) ?></span>
                    <span class="event-meta"><?= eventKindLabel($e['kind']) ?></span>
                </span>
                <span class="event-amount <?= $e['direction'] === 'in' ? 'amount-income' : 'amount-expense' ?>">
                    <?= $e['direction'] === 'in' ? '+' : '−' ?><?= formatMoney($e['amount']) ?>
                </span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
