<?php
/**
 * داشبوردِ محیطِ فروشگاهی.
 *
 * فقط چیزی را نشان می‌دهد که **واقعاً وجود دارد**: صندوق‌های فروشگاه،
 * ارزشِ انبار، طلب و بدهیِ طرف‌حساب‌ها، و کالاهای کم‌موجودی. کارت‌های
 * فروش و سود با خودِ فاکتور می‌آیند — کارتِ «۰ تومان»ِ ساختگی عددی است که
 * صاحبِ مغازه واقعی می‌خواندش.
 *
 * ⛔ هیچ عددی از حساب لندِ شخصی اینجا نیست: صندوق از `biz_accounts`
 *    می‌آید، نه `walletBalances()`. حسابِ «شخصی + فروشگاه» وگرنه پولِ
 *    خانه‌اش را روی داشبوردِ مغازه می‌دید.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId   = (int)Auth::userId();
$settings = Biz::settings($userId);
$cash     = BizCash::list($userId);
$cashSum  = BizCash::total($cash);
$stock    = BizProducts::summary($userId);
$low      = $stock['low'] + $stock['out'] > 0 ? BizProducts::lowStock($userId) : [];
$parties  = BizParties::summary($userId);

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

<div class="st-kpis">
    <a class="st-kpi-card" href="<?= h(Biz::url('settings.php')) ?>#cash">
        <span class="st-kpi-label">موجودیِ صندوق‌ها</span>
        <span class="st-kpi-value st-num<?= $cashSum < 0 ? ' is-neg' : '' ?>"><?= formatMoney($cashSum) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)count(array_filter($cash, fn($a) => (int)$a['is_active'] === 1))) ?> صندوق و حساب</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('products.php')) ?>">
        <span class="st-kpi-label">ارزشِ انبار</span>
        <span class="st-kpi-value st-num"><?= formatMoney($stock['value']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$stock['count']) ?> کالا · به میانگینِ بهای خرید</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('parties.php?f=debtor')) ?>">
        <span class="st-kpi-label">طلب از مشتری‌ها</span>
        <span class="st-kpi-value st-num is-pos"><?= formatMoney($parties['receivable']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$parties['debtors']) ?> بدهکار</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('parties.php?f=creditor')) ?>">
        <span class="st-kpi-label">بدهی به تأمین‌کننده‌ها</span>
        <span class="st-kpi-value st-num<?= $parties['payable'] > 0 ? ' is-neg' : '' ?>"><?= formatMoney($parties['payable']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$parties['creditors']) ?> طلبکار</span>
    </a>
</div>
<p class="st-muted st-unit-note">همه‌ی مبلغ‌ها به تومان.</p>

<div class="st-cols">
    <section class="st-card">
        <div class="st-card-head">
            <h2 class="st-h2">موجودیِ رو به اتمام</h2>
            <?php if ($low): ?><a href="<?= h(Biz::url('products.php?f=low')) ?>">همه</a><?php endif; ?>
        </div>
        <?php if (!$low): ?>
            <p class="st-empty"><?= $stock['count'] > 0 ? 'همه‌ی کالاها موجودیِ کافی دارند.' : 'هنوز کالایی ثبت نکرده‌اید.' ?></p>
        <?php else: ?>
        <ul class="st-list">
            <?php foreach ($low as $p): ?>
            <li class="st-list-row">
                <a href="<?= h(Biz::url('product.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?></a>
                <span class="st-badge <?= (float)$p['stock_qty'] <= 0 ? 'is-out' : 'is-low' ?>">
                    <?= (float)$p['stock_qty'] <= 0 ? 'ناموجود' : h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="st-card">
        <h2 class="st-h2">کارِ سریع</h2>
        <div class="st-quick">
            <a class="st-quick-btn" href="<?= h(Biz::url('product.php')) ?>">+ کالای تازه</a>
            <a class="st-quick-btn" href="<?= h(Biz::url('party.php?kind=customer')) ?>">+ مشتریِ تازه</a>
            <a class="st-quick-btn" href="<?= h(Biz::url('party.php?kind=supplier')) ?>">+ تأمین‌کننده‌ی تازه</a>
            <a class="st-quick-btn" href="<?= h(Biz::url('settings.php')) ?>#cash">صندوق‌ها</a>
            <a class="st-quick-btn" href="<?= h(Biz::url('products-io.php')) ?>">ورود از اکسل</a>
            <a class="st-quick-btn" href="<?= h(Biz::url('reports.php')) ?>">چاپ و گزارش</a>
        </div>
        <p class="st-muted st-soon">در راه: فاکتورِ خرید و فروش با چاپ، دریافت و پرداخت، و گزارشِ فروش و سود.</p>
    </section>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
