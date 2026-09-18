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
// ⛔ موجودیِ حساب‌ها **یک بار** خوانده می‌شود و پاس داده می‌شود.
//    سنگین‌ترین کوئریِ اپ است (شش منبعِ پول با LEFT JOIN و تجمیع) و
//    پیش از این در همین یک بارگذاری دو بار اجرا می‌شد: یک بار از
//    `financialHighlights()` → `safeToSpend()` → `totalBalance()` و یک
//    بار از `pinnedWallets()`.
//
// ⚠ چرا پاس دادن و نه کش: کشِ درخواستی یک بار آزموده شد و تست‌ها
//   قرمز شدند — هر مسیری که پول می‌نویسد و بعد موجودی می‌خواند عددِ
//   کهنه می‌گرفت، بی‌هیچ خطایی. اینجا فراخواننده تازگی را خودش
//   تضمین می‌کند، پس چیزی نمی‌تواند کهنه بماند.
$walletRows = walletBalances($userId);

$highlights = financialHighlights($userId, $walletRows);

// ⛔ «دقیقه‌ی اول». تصمیمش تنها در `openingBalanceHint()` است.
$openingHint = openingBalanceHint($userId);

// حساب‌هایی که کاربر خودش برای خانه پین کرده. تصمیمش تنها در
// `pinnedWallets()` است و اگر چیزی پین نشده باشد، نوار اصلاً رندر
// نمی‌شود — نه یک نوارِ خالی.
$pinned = pinnedWallets($userId, $walletRows);

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

         ⛔ کارت **همان `.bank-card`ِ صفحه‌ی حساب‌هاست**، نه یک طرحِ دوم.
            با دو طرح، اولین باری که یکی عوض شود کاربر بسته به اینکه از
            کجا نگاه می‌کند دو چیزِ متفاوت می‌بیند.

         ⚠ شماره‌ی کارت **روی خودِ کارت** دیده می‌شود — این خواسته‌ی صریحِ
            مالکِ نصب است و جای قاعده‌ی قبلی («فقط داخلِ نمای کارت») را
            گرفت. هزینه‌اش واقعی است: صفحه‌ی خانه همان صفحه‌ای است که
            کاربر جلوی دیگران هم بازش می‌کند. شماره‌ی **حساب** و **شبا**
            عمداً روی کارت نمی‌آیند و فقط داخلِ نما باز می‌شوند، چون
            آن‌ها در یک نگاه از دور خوانده نمی‌شوند ولی در یک عکس
            می‌مانند. */ ?>
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
            <?php
                $__bp   = bankPreset($pw['bank_code'] ?? null);
                $__bank = $pw['bank_name'] ?: ($__bp['name'] ?? '');
                $__bal  = ((int)$pw['balance'] < 0 ? '−' : '') . formatMoney(abs((int)$pw['balance']));
                $__kind = walletKindLabel($pw['kind'], $pw['kind_label'] ?? null);
                $__card = formatCardNumber($pw['card_number'] ?? '');
            ?>
            <?php /* ⚠ همان `data-*`هایی که ردیفِ `wallets.php` دارد، با همان
                     نام‌ها: `app.js` یک شنونده‌ی مشترک روی `.js-show-card`
                     دارد و اگر یکی از این کلیدها نامش فرق کند، نما
                     **بی‌صدا** خالی باز می‌شود. */ ?>
            <div class="bank-card home-card js-show-card" role="button" tabindex="0"
                 style="--bc1: <?= h($pw['color']) ?>; --bc2: <?= h(shadeColor($pw['color'])) ?>;"
                 data-id="<?= (int)$pw['id'] ?>"
                 data-name="<?= h($pw['name']) ?>"
                 data-bank="<?= h($__bank) ?>"
                 data-card="<?= h($__card) ?>"
                 data-account="<?= h(toPersianDigits($pw['account_number'] ?? '')) ?>"
                 data-iban="<?= h(formatIban($pw['iban'] ?? '')) ?>"
                 data-kind="<?= h($__kind) ?>"
                 data-balance="<?= h($__bal) ?>"
                 data-c1="<?= h($pw['color']) ?>"
                 data-c2="<?= h(shadeColor($pw['color'])) ?>">
                <div class="bank-card-shine"></div>
                <?php /* ⚠ نگاشتِ فیلدها **دقیقاً** همانِ مودال است: بالا
                         نامِ بانک (و اگر نبود نامِ حساب)، پایین نامِ خودِ
                         حساب. برعکسش کارتِ خانه و کارتِ داخلِ نما را دو
                         چیزِ متفاوت نشان می‌داد، و روی حسابی که نامش با
                         بانکش یکی است هر دو خط یک کلمه می‌شدند. */ ?>
                <div class="bank-card-top">
                    <span class="bank-card-bank"><?= h($__bank ?: $pw['name']) ?></span>
                    <span class="bank-card-kind"><?= h($__kind) ?></span>
                </div>
                <div class="bank-card-chip" aria-hidden="true"></div>
                <div class="bank-card-number"><?= h($__card) ?></div>
                <div class="bank-card-bottom">
                    <div>
                        <span class="bank-card-label">صاحب حساب</span>
                        <span class="bank-card-owner"><?= h($pw['name']) ?></span>
                    </div>
                    <div class="bank-card-balance-wrap">
                        <span class="bank-card-label">موجودی</span>
                        <span class="bank-card-balance"><?= h($__bal) ?></span>
                    </div>
                </div>
            </div>
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

<?php /* ⛔ معرفیِ اولیه فقط برای دفترِ **خالی** رندر می‌شود، و این شرط
         هیچ کوئریِ تازه‌ای نمی‌خواهد: `$recentTransactions` همین بالا
         خوانده شده. برای کاربرِ قدیمی اصلاً وارد HTML نمی‌شود — نه یک
         لایه‌ی پنهانِ بی‌دلیل در هر بارگذاری، همان استدلالِ
         `$pinned` پایین‌تر. دلیلِ تصمیم‌ها در خودِ فایل نوشته شده. */ ?>
<?php if (empty($recentTransactions)): ?>
<?php include __DIR__ . '/includes/intro_sheet.php'; ?>
<?php endif; ?>

<?php /* نمای کارت و تعدیل موجودی — همان دو مودالِ `wallets.php`، از یک
         فایلِ مشترک. فقط وقتی رندر می‌شوند که کارتی روی خانه باشد؛
         بدونِ آن دو لایه‌ی پنهان بی‌دلیل در HTML هر بارگذاری می‌ماندند. */ ?>
<?php if ($pinned): ?>
<?php include __DIR__ . '/includes/wallet_card_modals.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
