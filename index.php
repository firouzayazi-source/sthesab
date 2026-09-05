<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$today = today();

// بررسی و پردازش تراکنش‌های دوره‌ای سررسیدشده (بدون نیاز به Cron)
$recurringNeedsAttention = [];
try {
    $recurringNeedsAttention = processRecurringTransactions($userId);
} catch (PDOException $e) {
    $recurringNeedsAttention = []; // جدول هنوز ساخته نشده
}

$categories = cachedCategories();
$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');

$recentStmt = $pdo->prepare('
    SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.category_id,
           c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
    ORDER BY t.created_at DESC
    LIMIT 8
');
$recentStmt->execute(['user_id' => $userId]);
$recentTransactions = $recentStmt->fetchAll();

// آمار ماه جاری برای نوار مانده بالای صفحه
$monthStmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN type = "income" THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expense
    FROM transactions
    WHERE user_id = :user_id AND transaction_date BETWEEN :from_date AND :to_date
');
$monthStmt->execute(['user_id' => $userId, 'from_date' => startOfJalaliMonth(), 'to_date' => $today]);
$monthRow = $monthStmt->fetch();
$monthIncome  = (int)$monthRow['income'];
$monthExpense = (int)$monthRow['expense'];
$monthNet     = $monthIncome - $monthExpense;

// ⛔ جمله‌ها **بعد از** جمع‌های بالا ساخته می‌شوند و خودشان هیچ استثنایی
//    پرتاب نمی‌کنند (`financialHighlights` هر بخش را جدا `try` می‌کند).
//    این یک کارِ جانبی است؛ اگر یک جدول نیامده باشد نباید صفحه‌ی خانه —
//    یعنی اولین چیزی که کاربر می‌بیند — بشکند.
$highlights = financialHighlights($userId);

$pageTitle = 'خانه';
include __DIR__ . '/includes/header.php';
?>

<div class="balance-ribbon">
    <div class="balance-label">مانده این ماه</div>
    <div class="balance-value"><span class="bv-num"><?= $monthNet < 0 ? '−' : '' ?><?= formatMoney(abs($monthNet)) ?></span><span class="bv-unit">تومان</span></div>
    <div class="balance-split">
        <div>
            <div class="bs-label">دریافتی</div>
            <div class="bs-value bs-in"><?= formatMoney($monthIncome) ?></div>
        </div>
        <div>
            <div class="bs-label">پرداختی</div>
            <div class="bs-value bs-out"><?= formatMoney($monthExpense) ?></div>
        </div>
    </div>
</div>

<?php /* ⛔ جای این کارت عمداً **بلافاصله زیرِ عددِ ماه** است.
         کاربر عدد را می‌بیند و بلافاصله می‌پرسد «خب یعنی چه؟» — جواب
         باید همان‌جا باشد، نه دو صفحه آن‌طرف‌تر. اگر جمله‌ای نبود، کارت
         اصلاً رندر نمی‌شود: کارتِ خالیِ «بینشی نیست» بدتر از نبودنش
         است. */ ?>
<?php if ($highlights): ?>
<div class="card insight-card">
    <?php foreach ($highlights as $h): ?>
        <a class="insight-row insight-<?= h($h['tone']) ?>"
           href="<?= APP_BASE_PATH ?>/<?= h($h['link'] ?? 'dashboard.php') ?>">
            <span class="insight-dot"></span>
            <span class="insight-text"><?= h($h['text']) ?></span>
            <svg class="insight-go" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">آخرین تراکنش‌های من</h2>
        <a href="transactions.php" class="link-more">مشاهده همه ←</a>
    </div>

    <div class="table-wrapper">
        <?php if (empty($recentTransactions)): ?>
            <p class="empty-row">هنوز تراکنشی ثبت نکرده‌اید.<br>با دکمه + پایین صفحه اولین تراکنش را ثبت کنید.</p>
        <?php else: ?>
            <?php renderTransactionsGrouped($recentTransactions); ?>
        <?php endif; ?>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/edit_tx_modal.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
