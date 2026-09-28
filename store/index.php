<?php
/**
 * داشبوردِ فروشگاه — پیشخوانِ کارِ روزانه.
 *
 * از بالا به پایین، به همان ترتیبی که صاحبِ مغازه نگاه می‌کند:
 * ۱. **نوارِ اقدام** — فروشِ سریع، فاکتور، دریافت، هزینه. اولین چیزِ صفحه کار
 *    است، نه عدد.
 * ۲. **چهار کاشی** — فروشِ امروز (کنارش همان روزِ هفته‌ی قبل)، ورودِ پولِ
 *    امروز، طلب از مشتری‌ها، و موجودیِ صندوق‌ها.
 * ۳. **نیازمندِ اقدام** — فاکتورِ سررسیدگذشته، کالای کم‌موجودی، پیش‌نویسِ
 *    صادرنشده. وقتی چیزی نیست، کلِ بخش رندر نمی‌شود.
 * ۴. **فروشِ ۳۰ روز** (میله‌ای، بی‌جاوااسکریپت) و **آخرین اسناد**.
 *
 * ⛔ هیچ عددی از حساب لندِ شخصی نیست: صندوق از `biz_accounts`، فروش از
 *    `biz_invoices`. حسابِ «شخصی + فروشگاه» وگرنه پولِ خانه‌اش را روی
 *    داشبوردِ مغازه می‌دید.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_reports.php';

$userId   = (int)Auth::userId();
$settings = Biz::settings($userId);
$today    = today();
$cash     = BizCash::list($userId);
$cashSum  = BizCash::total($cash);
$stock    = BizProducts::summary($userId);
$low      = $stock['low'] + $stock['out'] > 0 ? BizProducts::lowStock($userId, 5) : [];
$parties  = BizParties::summary($userId);
$attn     = BizInvoices::attention($userId);
$recent   = BizInvoices::recent($userId, 8);

$saleToday = BizReports::sales($userId, $today, $today);
$lastWeek  = date('Y-m-d', strtotime($today . ' -7 days'));
$saleLW    = BizReports::sales($userId, $lastWeek, $lastWeek);
$cashToday = BizReports::cash($userId, $today, $today);
$inToday   = $cashToday['receipt'] + $cashToday['income'];
$from30    = date('Y-m-d', strtotime($today . ' -29 days'));
$days      = BizReports::daily($userId, $from30, $today);
$sum30     = array_sum($days);
$max30     = max(1, max($days ?: [0]));
$hasDocs   = (bool)$recent;

$pageTitle = 'داشبورد';
require __DIR__ . '/../includes/biz_head.php';
?>
<section class="st-hello">
    <h1 class="st-h1"><?= h($settings['shop_name'] !== '' ? $settings['shop_name'] : 'فروشگاه من') ?></h1>
    <p class="st-date"><?= h(jalaliLongDate($today)) ?></p>
</section>

<nav class="st-actions" aria-label="کارِ سریع">
    <a class="st-action is-primary" href="<?= h(Biz::url('quick-sale.php')) ?>"><svg class="st-action-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>فروشِ سریع</a>
    <a class="st-action" href="<?= h(Biz::url('invoice-edit.php?k=sale')) ?>">فاکتور فروش</a>
    <a class="st-action" href="<?= h(Biz::url('invoice-edit.php?k=purchase')) ?>">فاکتور خرید</a>
    <a class="st-action" href="<?= h(Biz::url('payment.php?k=receipt')) ?>">دریافت</a>
    <a class="st-action" href="<?= h(Biz::url('payment.php?k=expense')) ?>">هزینه</a>
</nav>

<?php if ($settings['shop_name'] === ''): ?>
<section class="st-card st-card-invite">
    <h2 class="st-h2">سربرگِ فروشگاه را کامل کنید</h2>
    <p class="st-muted">نام، تلفن و آدرسِ فروشگاه بالای فاکتورهای چاپی می‌نشیند.</p>
    <a class="st-btn" href="<?= h(Biz::url('settings.php')) ?>">تنظیمات فروشگاه</a>
</section>
<?php endif; ?>

<div class="st-kpis">
    <a class="st-kpi-card st-kpi-hero" href="<?= h(Biz::url('reports.php?p=today')) ?>">
        <span class="st-kpi-label">فروشِ امروز</span>
        <span class="st-kpi-value st-num"><?= formatMoney($saleToday['net']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$saleToday['docs']) ?> فاکتور · هفته‌ی پیش همین روز <?= formatMoney($saleLW['net']) ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('payments.php?f=receipt')) ?>">
        <span class="st-kpi-label">ورودِ پولِ امروز</span>
        <span class="st-kpi-value st-num is-pos"><?= formatMoney($inToday) ?></span>
        <span class="st-kpi-sub">خروج <?= formatMoney($cashToday['payment'] + $cashToday['expense']) ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('parties.php?f=debtor')) ?>">
        <span class="st-kpi-label">طلب از مشتری‌ها</span>
        <span class="st-kpi-value st-num"><?= formatMoney($parties['receivable']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$parties['debtors']) ?> بدهکار · بدهی به تأمین‌کننده <?= formatMoney($parties['payable']) ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('accounts.php')) ?>">
        <span class="st-kpi-label">موجودیِ صندوق‌ها</span>
        <span class="st-kpi-value st-num<?= $cashSum < 0 ? ' is-neg' : '' ?>"><?= ($cashSum < 0 ? '−' : '') . formatMoney(abs($cashSum)) ?></span>
        <span class="st-kpi-sub">ارزشِ انبار <?= formatMoney($stock['value']) ?></span>
    </a>
</div>
<p class="st-muted st-unit-note">همه‌ی مبلغ‌ها به تومان.</p>

<?php $cheqDue = BizCheques::due($userId); $attnRows = ($low ? 1 : 0) + ($attn['drafts'] > 0 ? 1 : 0) + ($cheqDue ? 1 : 0); ?>
<?php if ($attnRows > 0): ?>
<section class="st-card st-attn">
    <h2 class="st-h2">نیازمندِ اقدام</h2>
    <ul class="st-list">
        <?php if ($attn['drafts'] > 0): ?>
        <li class="st-list-row"><a href="<?= h(Biz::url('sales.php?f=draft')) ?>"><span class="st-pill is-draft">پیش‌نویس</span> <?= toPersianDigits((string)$attn['drafts']) ?> سندِ صادرنشده</a></li>
        <?php endif; ?>
        <?php foreach ($cheqDue as $c): $cd = (int)$c['days']; ?>
        <li class="st-list-row">
            <a href="<?= h(Biz::url('cheques.php' . ($cd < 0 ? '?f=overdue' : ''))) ?>"><span class="st-badge <?= $cd < 0 ? 'is-out' : 'is-low' ?>"><?= $cd < 0 ? 'چکِ گذشته' : ($cd === 0 ? 'چکِ امروز' : 'چک · ' . toPersianDigits((string)$cd) . ' روز') ?></span>
                <?= $c['kind'] === 'receipt' ? 'دریافتی از' : 'پرداختی به' ?> <?= h((string)($c['party_name'] ?? '')) ?></a>
            <?= BizDocView::money((int)$c['amount']) ?>
        </li>
        <?php endforeach; ?>
        <?php foreach ($low as $p): ?>
        <li class="st-list-row">
            <a href="<?= h(Biz::url('product.php?id=' . (int)$p['id'])) ?>"><span class="st-badge <?= (float)$p['stock_qty'] <= 0 ? 'is-out' : 'is-low' ?>"><?= (float)$p['stock_qty'] <= 0 ? 'ناموجود' : 'کم' ?></span> <?= h($p['name']) ?></a>
            <span class="st-num"><?= h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="st-cols st-cols-wide">
    <section class="st-card">
        <div class="st-card-head">
            <h2 class="st-h2">فروشِ ۳۰ روزِ گذشته</h2>
            <a href="<?= h(Biz::url('reports.php')) ?>">گزارشِ کامل</a>
        </div>
        <?php if ($sum30 === 0): ?>
            <p class="st-empty">هنوز در این ۳۰ روز فروشی صادر نشده است.</p>
        <?php else: ?>
        <p class="st-muted">جمع <b class="st-num"><?= formatMoney($sum30) ?></b> تومان</p>
        <div class="st-bars" role="img" aria-label="فروشِ روزانه‌ی ۳۰ روزِ گذشته">
            <?php foreach ($days as $d => $v): ?>
                <span class="st-bar<?= $d === $today ? ' is-today' : '' ?>" style="height: <?= max(2, (int)round(max(0, $v) / $max30 * 100)) ?>%" title="<?= h(toJalali($d) . ' — ' . formatMoney($v)) ?>"></span>
            <?php endforeach; ?>
        </div>
        <div class="st-bars-axis" dir="ltr"><span class="st-num"><?= h(toJalali($from30)) ?></span><span>امروز</span></div>
        <?php endif; ?>
    </section>

    <section class="st-card">
        <div class="st-card-head">
            <h2 class="st-h2">آخرین اسناد</h2>
            <a href="<?= h(Biz::url('sales.php')) ?>">همه</a>
        </div>
        <?php if (!$hasDocs): ?>
            <p class="st-empty">هنوز سندی ثبت نشده. با «فروشِ سریع» یا «فاکتور خرید» شروع کنید؛ کالا و طرف‌حساب را هم می‌شود همان‌جا تازه ساخت.</p>
        <?php else: ?>
        <ul class="st-list">
            <?php foreach ($recent as $r): ?>
            <li class="st-list-row">
                <a href="<?= h(Biz::url(($r['status'] === 'draft' ? 'invoice-edit.php' : 'invoice.php') . '?id=' . (int)$r['id'])) ?>">
                    <?= h(BizInvoices::title($r)) ?>
                    <span class="st-muted-i"><?= h(toJalali((string)$r['inv_date'])) ?> · <?= $r['party_name'] !== null ? h((string)$r['party_name']) : 'گذری' ?></span>
                </a>
                <span class="st-move-end"><?= BizDocView::pill($r) ?><?= BizDocView::money((int)$r['total']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
