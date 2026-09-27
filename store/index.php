<?php
/**
 * داشبوردِ محیطِ فروشگاهی.
 *
 * مرحله‌ی ۱ فقط چیزی را نشان می‌دهد که **واقعاً وجود دارد**: سربرگِ
 * فروشگاه و موجودیِ صندوق و حساب‌ها. کارت‌های فروش و خرید و سود با
 * خودِ آن بخش‌ها می‌آیند — کارتِ «۰ تومان»ِ ساختگی عددی است که صاحبِ
 * مغازه واقعی می‌خواندش.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();

$userId   = (int)Auth::userId();
$settings = Biz::settings($userId);
$wallets  = array_values(array_filter(walletBalances($userId), fn($w) => (int)$w['is_active'] === 1));
$total    = totalBalance($userId, $wallets);

$pageTitle = 'داشبورد';
require __DIR__ . '/../includes/biz_head.php';
?>
<section class="st-hello">
    <h1 class="st-h1"><?= h($settings['shop_name'] !== '' ? $settings['shop_name'] : 'فروشگاه من') ?></h1>
    <p class="st-date"><?= h(jalaliLongDate(today())) ?></p>
</section>

<?php if ($settings['shop_name'] === ''): ?>
<section class="st-card st-card-invite">
    <h2 class="st-h2">سربرگِ فروشگاه را کامل کنید</h2>
    <p class="st-muted">نام، تلفن و آدرسِ فروشگاه بالای فاکتورهای چاپی می‌نشیند.</p>
    <a class="st-btn" href="<?= h(Biz::url('settings.php')) ?>">تنظیمات فروشگاه</a>
</section>
<?php endif; ?>

<section class="st-card">
    <div class="st-kpi">
        <span class="st-kpi-label">موجودیِ صندوق و حساب‌ها</span>
        <span class="st-kpi-value st-num"><?= formatMoney($total) ?> <small>تومان</small></span>
    </div>
    <?php if ($wallets): ?>
    <ul class="st-list">
        <?php foreach ($wallets as $w): ?>
        <li class="st-list-row">
            <span><?= h($w['name']) ?></span>
            <span class="st-num<?= (int)$w['balance'] < 0 ? ' is-neg' : '' ?>"><?= formatMoney((int)$w['balance']) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<section class="st-card st-card-soon">
    <h2 class="st-h2">در راه</h2>
    <p class="st-muted">کالا و موجودی، مشتری و تأمین‌کننده، فاکتورِ خرید و فروش با چاپ، دریافت و پرداخت، و گزارشِ فروش و سود.</p>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
