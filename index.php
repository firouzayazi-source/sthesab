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

// ⛔ «دقیقه‌ی اول». تصمیمش تنها در `openingBalanceHint()` است.
$openingHint = openingBalanceHint($userId);

// حساب‌هایی که کاربر خودش برای خانه پین کرده. تصمیمش تنها در
// `pinnedWallets()` است و اگر چیزی پین نشده باشد، نوار اصلاً رندر
// نمی‌شود — نه یک نوارِ خالی.
$pinned = pinnedWallets($userId);

$pageTitle = 'خانه';
include __DIR__ . '/includes/header.php';
?>

<?php /* ⛔ جای این کارت **بالای همه چیز** است، نه پایین‌تر. حرفش این است
         که عددهای زیرش هنوز درست نیستند؛ نشاندنش زیرِ همان عددها یعنی
         کاربر اول باور می‌کند و بعد می‌خواند. فقط تا وقتی دیده می‌شود
         که کاربر جواب نداده باشد و هیچ حسابی موجودی اولیه نداشته باشد. */ ?>
<?php if ($openingHint): ?>
<div class="card start-card">
    <div class="start-head">
        <span class="start-step">قدم اول</span>
        <h2 class="start-title">همین حالا چقدر پول دارید؟</h2>
    </div>
    <p class="start-why">
        تا این عدد را وارد نکنید، حساب‌ها از صفر شروع می‌کنند: با اولین هزینه
        «مجموع حساب‌ها» منفی می‌شود و «پول قابل خرج» هم همین‌طور.
    </p>

    <form id="startBalanceForm" class="start-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="wallet_id" value="<?= (int)$openingHint['wallet_id'] ?>">
        <input type="hidden" name="mode" value="set">
        <label class="start-label" for="start_balance">
            موجودی «<?= h($openingHint['wallet_name']) ?>» به تومان
        </label>
        <div class="start-row">
            <input type="text" inputmode="numeric" id="start_balance" name="amount"
                   class="amount-input" placeholder="۰" required>
            <button type="submit" class="btn btn-primary" id="startBalanceSubmit">ثبت</button>
        </div>
        <div id="startBalanceMessage" class="form-message" hidden></div>
    </form>

    <div class="start-foot">
        <?php if ($openingHint['more']): ?>
            <a href="<?= APP_BASE_PATH ?>/wallets.php">حساب‌های دیگر ←</a>
        <?php endif; ?>
        <button type="button" class="start-skip" id="startBalanceSkip">نیازی نیست</button>
    </div>
</div>
<?php endif; ?>

<?php /* اسلایدرِ خانه — یک نوار، چند اسلاید.

         ⛔ **اسلاید اول همیشه «مانده این ماه» است** و دقیقاً همان‌جا و
            همان اندازه‌ای می‌ماند که همیشه بوده. حساب‌های پین‌شده
            اسلایدهای بعدی‌اند، نه یک نوارِ تازه زیرِ آن: با نوارِ جدا،
            هر چیزی که پایین‌تر است ۱۲۰ پیکسل پایین می‌رفت و صفحه‌ی
            خانه‌ی کسی که یک حساب پین می‌کند عوض می‌شد.

         ⛔ کارت‌ها **هم‌قدِ نوارِ ماه** می‌شوند، بدونِ هیچ عددِ ثابتی:
            `align-items` پیش‌فرضِ فلکس `stretch` است و ارتفاعِ ردیف را
            بلندترین اسلاید (همان نوار) تعیین می‌کند.

         ⛔ شماره‌ی کارت اینجا **نمی‌آید** — نه کامل، نه چهار رقمِ آخر.
            آن ستون‌ها حساس‌اند و طبق قاعده‌ی خودشان فقط داخلِ نمای کارت
            باز می‌شوند. صفحه‌ی خانه چیزی است که کاربر جلوی دیگران هم
            بازش می‌کند. */ ?>
<div class="home-carousel">
    <div class="home-slides" id="homeSlides">
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

        <?php foreach ($pinned as $pw): ?>
            <?php $__neg = (int)$pw['balance'] < 0; ?>
            <a class="wallet-tile"
               href="<?= APP_BASE_PATH ?>/transactions.php?wallet=<?= (int)$pw['id'] ?>"
               style="--wt1: <?= h($pw['color']) ?>; --wt2: <?= h(shadeColor($pw['color'])) ?>;">
                <span class="wallet-tile-name"><?= h($pw['name']) ?></span>
                <span class="wallet-tile-sub">
                    <?= h(walletKindLabel($pw['kind'], $pw['kind_label'] ?? null)) ?>
                    <?php if (!empty($pw['bank_name'])): ?> · <?= h($pw['bank_name']) ?><?php endif; ?>
                </span>
                <?php /* ⚠ `.ltr-num` روی **خودِ عدد** است، نه روی ظرفش: ظرف
                         واحدِ فارسیِ «تومان» را هم دارد و چپ‌چین کردنش
                         چیدمانِ آن را خراب می‌کند — همان قاعده‌ای که برای
                         `.money-card` و `.balance-ribbon` نوشته شده. */ ?>
                <span class="wallet-tile-bal<?= $__neg ? ' is-neg' : '' ?>">
                    <span class="ltr-num"><?= $__neg ? '−' : '' ?><?= formatMoney(abs((int)$pw['balance'])) ?></span>
                    <span class="wallet-tile-unit">تومان</span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php /* ⛔ با اسلایدِ تمام‌عرض، «سرک کشیدنِ» کارتِ بعدی از بین می‌رود —
             و همان تنها چیزی بود که می‌گفت نوار کشیدنی است. نقطه‌ها
             جایگزینِ آن نشانه‌اند، پس اختیاری نیستند. با یک اسلاید
             اصلاً رندر نمی‌شوند: نشانگرِ تک‌نقطه‌ای چیزی نمی‌گوید. */ ?>
    <?php if ($pinned): ?>
    <div class="home-dots">
        <?php for ($i = 0; $i <= count($pinned); $i++): ?>
            <button type="button" class="home-dot<?= $i === 0 ? ' is-on' : '' ?>"
                    data-slide="<?= $i ?>"
                    aria-label="<?= $i === 0 ? 'مانده این ماه' : h($pinned[$i - 1]['name']) ?>"></button>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
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
            <?php /* ⛔ متنِ قبلی می‌گفت «با دکمه + **پایین صفحه**» — و روی
                     دسکتاپ `.bottom-nav` با `display:none` کنار می‌رود، پس
                     تنها راهنماییِ کاربرِ خالی‌دفتر به دکمه‌ای اشاره می‌کرد
                     که وجود نداشت. راهِ درست این است که خودِ اینجا **در**
                     باشد: `.js-add-tx` تنها بندِ باز کردنِ شیتِ ثبت است و
                     روی هر عرضی کار می‌کند. */ ?>
            <p class="empty-row">هنوز تراکنشی ثبت نکرده‌اید.</p>
            <p class="empty-cta"><button type="button" class="btn btn-primary js-add-tx">ثبت اولین تراکنش</button></p>
        <?php else: ?>
            <?php renderTransactionsGrouped($recentTransactions); ?>
        <?php endif; ?>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/edit_tx_modal.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
