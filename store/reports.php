<?php
/**
 * گزارش و چاپ — بالای صفحه گزارشِ فروش و سود (`BizReports`) برای یک بازه،
 * پایینش درِ ورودِ همه‌ی برگه‌های چاپی (`BizPrint::DOCS`).
 *
 * ⛔ بازه با پیوندِ GET عوض می‌شود (`?p=`)، نه جاوااسکریپت؛ `custom` از/تا
 *    را شمسی می‌گیرد. همه‌ی عددها از اسنادِ **صادرشده** و خالصِ برگشت‌اند.
 *
 * هر کارت یک فرمِ GET به `print.php` است، نه جاوااسکریپت: با دکمه‌ی
 * بازگشتِ مرورگر و کپیِ آدرس هم کار می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_print.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_reports.php';

$userId   = (int)Auth::userId();
$period   = getParam('p');
$period   = isset(BizReports::PERIODS[$period]) ? $period : 'month';
[$rFrom, $rTo] = BizReports::range($period, BizDocView::gDate(getParam('from')), BizDocView::gDate(getParam('to')));
$rSales   = BizReports::sales($userId, $rFrom, $rTo);
$rCash    = BizReports::cash($userId, $rFrom, $rTo);
$rTop     = BizReports::topProducts($userId, $rFrom, $rTo);
$rCats    = BizReports::byCategory($userId, $rFrom, $rTo);
$rExp     = BizReports::expenses($userId, $rFrom, $rTo);
$rAging   = BizReports::aging($userId);
$rPrint   = BizPrint::url('sales', ['p' => $period, 'from' => $period === 'custom' ? BizDocView::jDate($rFrom) : '', 'to' => $period === 'custom' ? BizDocView::jDate($rTo) : '']);
$cats     = BizProducts::categories($userId);
$parties  = BizParties::all($userId, '', 500);
$products = BizProducts::all($userId, '', '', 500);
$prefs    = Biz::printPrefs($userId);
$printUrl = Biz::url('print.php');

$pageTitle = 'گزارش و چاپ';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">گزارش فروش و سود</h1>
        <p class="st-muted"><span class="st-num"><?= h(toJalali($rFrom)) ?></span> تا <span class="st-num"><?= h(toJalali($rTo)) ?></span></p>
    </div>
    <div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h($rPrint) ?>">چاپِ این گزارش</a></div>
</div>
<nav class="st-tabs" aria-label="بازه">
    <?php foreach (BizReports::PERIODS as $pk => $pl): if ($pk === 'custom') { continue; } ?>
        <a class="st-tab-chip<?= $pk === $period ? ' is-active' : '' ?>" href="<?= h(Biz::url('reports.php?p=' . $pk)) ?>"><?= h($pl) ?></a>
    <?php endforeach; ?>
</nav>
<form class="st-filters" method="get" action="<?= h(Biz::url('reports.php')) ?>">
    <input type="hidden" name="p" value="custom">
    <input type="text" name="from" value="<?= $period === 'custom' ? h(BizDocView::jDate($rFrom)) : '' ?>" placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" dir="ltr" class="st-date-in" aria-label="از تاریخ">
    <input type="text" name="to" value="<?= $period === 'custom' ? h(BizDocView::jDate($rTo)) : '' ?>" placeholder="تا تاریخ" dir="ltr" class="st-date-in" aria-label="تا تاریخ">
    <button type="submit" class="st-btn st-btn-ghost">بازه‌ی دلخواه</button>
</form>

<div class="st-kpis">
    <div class="st-kpi-card">
        <span class="st-kpi-label">فروشِ خالص</span>
        <span class="st-kpi-value st-num"><?= formatMoney($rSales['net']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$rSales['docs']) ?> فاکتور · برگشتی <?= formatMoney($rSales['returns']) ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">سودِ ناخالص</span>
        <span class="st-kpi-value st-num<?= $rSales['gross'] < 0 ? ' is-neg' : ' is-pos' ?>"><?= ($rSales['gross'] < 0 ? '−' : '') . formatMoney(abs($rSales['gross'])) ?></span>
        <span class="st-kpi-sub"><?= $rSales['margin'] !== null ? 'حاشیه‌ی ' . toPersianDigits((string)$rSales['margin']) . '٪ · ' : '' ?>بهای تمام‌شده <?= formatMoney($rSales['cogs']) ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">هزینه‌های فروشگاه</span>
        <span class="st-kpi-value st-num"><?= formatMoney($rCash['expense']) ?></span>
        <span class="st-kpi-sub">سودِ پس از هزینه <?= ($rSales['gross'] + $rCash['income'] - $rCash['expense'] < 0 ? '−' : '') . formatMoney(abs($rSales['gross'] + $rCash['income'] - $rCash['expense'])) ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">میانگینِ هر فاکتور</span>
        <span class="st-kpi-value st-num"><?= formatMoney($rSales['avg']) ?></span>
        <span class="st-kpi-sub">دریافت <?= formatMoney($rCash['receipt']) ?> · پرداخت <?= formatMoney($rCash['payment']) ?></span>
    </div>
</div>
<p class="st-muted st-unit-note">مبلغ‌ها به تومان. سود = فروشِ خالص − بهای تمام‌شده‌ی کالای فروخته (به میانگینِ خریدِ لحظه‌ی فروش)؛ «پس از هزینه» هزینه‌ها و درآمدهای متفرقه‌ی همین بازه را هم حساب می‌کند.</p>

<div class="st-cols">
    <section class="st-card">
        <h2 class="st-h2">پرفروش‌ترین کالاها</h2>
        <?php if (!$rTop): ?><p class="st-empty">در این بازه فروشی صادر نشده است.</p><?php else: ?>
        <div class="st-table-wrap st-flat"><table class="st-table">
            <thead><tr><th>کالا</th><th class="st-th-num">مقدار</th><th class="st-th-num">فروش</th><th class="st-th-num">سود</th></tr></thead>
            <tbody><?php foreach ($rTop as $t): ?><tr>
                <td><?php if ($t['product_id'] !== null): ?><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . (int)$t['product_id'])) ?>"><?= h((string)$t['name']) ?></a><?php else: ?><?= h((string)$t['name']) ?><?php endif; ?></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($t['qty'])) ?></span></td>
                <td class="st-td-num"><?= BizDocView::money((int)$t['rev']) ?></td>
                <td class="st-td-num"><?= BizDocView::money((int)$t['profit']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <h2 class="st-h2">به تفکیکِ دسته</h2>
        <?php if (!$rCats): ?><p class="st-empty">در این بازه فروشی صادر نشده است.</p><?php else: $catMax = max(1, max(array_map(fn($c) => (int)$c['rev'], $rCats))); ?>
        <ul class="st-list">
            <?php foreach ($rCats as $c): ?>
            <li class="st-catrow">
                <div class="st-list-row st-plain"><span><?= $c['cat'] !== null ? h((string)$c['cat']) : 'بی‌دسته' ?></span><span class="st-move-end"><?= BizDocView::money((int)$c['rev']) ?> <small class="st-muted-i">سود <?= h(formatMoney((int)$c['profit'])) ?></small></span></div>
                <div class="st-meter"><span style="width: <?= max(1, (int)round(max(0, (int)$c['rev']) / $catMax * 100)) ?>%"></span></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <h2 class="st-h2">سنِ طلب از مشتری‌ها</h2>
        <?php if ($rAging['total'] === 0): ?><p class="st-empty">هیچ فاکتورِ فروشِ تسویه‌نشده‌ای نیست.</p><?php else: ?>
        <ul class="st-list">
            <?php foreach ($rAging['buckets'] as $bk => $bv): ?>
            <li class="st-list-row"><span><?= h(BizReports::AGING_LABELS[$bk]) ?> <span class="st-muted-i"><?= toPersianDigits((string)$bv['n']) ?> فاکتور</span></span><?= BizDocView::money($bv['s']) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="st-muted">از سررسید (یا اگر ندارد، از تاریخِ فاکتور). <a href="<?= h(Biz::url('sales.php?f=open')) ?>">فهرستِ فاکتورهای تسویه‌نشده</a></p>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <h2 class="st-h2">بزرگ‌ترین هزینه‌ها</h2>
        <?php if (!$rExp): ?><p class="st-empty">در این بازه هزینه‌ای ثبت نشده است. <a href="<?= h(Biz::url('payment.php?k=expense')) ?>">ثبتِ هزینه</a></p><?php else: ?>
        <ul class="st-list"><?php foreach ($rExp as $e): ?><li class="st-list-row"><span><?= h((string)$e['title']) ?> <span class="st-muted-i"><?= toPersianDigits((string)$e['n']) ?> بار</span></span><?= BizDocView::money((int)$e['s']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
</div>

<h2 class="st-h2 st-section-title">برگه‌های چاپی</h2>
<p class="st-muted">کاغذ: <?= h(Biz::PRINT_OPTIONS['paper'][$prefs['paper']]) ?> —
    <a href="<?= h(Biz::url('print-settings.php')) ?>">تنظیماتِ چاپ</a></p>

<div class="st-cols">
    <?php foreach (['stock', 'prices'] as $d): ?>
    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="<?= h($d) ?>">
        <h2 class="st-h2"><?= h(BizPrint::DOCS[$d]) ?></h2>
        <p class="st-muted"><?= $d === 'stock' ? 'موجودی، میانگینِ بهای خرید و ارزشِ هر کالا، به تفکیکِ دسته، با جمعِ کل.' : 'نام، کد، واحد و قیمتِ فروشِ همه‌ی کالاها و خدمت‌ها.' ?></p>
        <div class="st-row2">
            <label class="st-field"><span>صافی</span>
                <select name="f">
                    <?php foreach (BizProducts::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="st-field"><span>دسته</span>
                <select name="c">
                    <option value="">همه‌ی دسته‌ها</option>
                    <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
    </form>
    <?php endforeach; ?>

    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="parties">
        <h2 class="st-h2"><?= h(BizPrint::DOCS['parties']) ?></h2>
        <p class="st-muted">فهرستِ مشتری‌ها و تأمین‌کننده‌ها با مانده‌ی بدهکار و بستانکارِ هر کدام.</p>
        <label class="st-field"><span>صافی</span>
            <select name="f">
                <?php foreach (BizParties::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
    </form>

    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="party">
        <h2 class="st-h2"><?= h(BizPrint::DOCS['party']) ?></h2>
        <p class="st-muted">گردش و مانده‌ی یک طرف‌حساب، با جای امضا — برای دادن به خودِ مشتری یا تأمین‌کننده.</p>
        <?php if ($parties['rows']): ?>
        <label class="st-field"><span>طرف‌حساب</span>
            <select name="id" required>
                <?php foreach ($parties['rows'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php if ($parties['capped']): ?><p class="st-muted-i">فقط ۵۰۰ طرف‌حسابِ اول آمده‌اند؛ بقیه از صفحه‌ی خودِ طرف‌حساب چاپ می‌شوند.</p><?php endif; ?>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
        <?php else: ?>
        <p class="st-empty">هنوز طرف‌حسابی ثبت نشده است.</p>
        <?php endif; ?>
    </form>

    <form class="st-card st-form" method="get" action="<?= h($printUrl) ?>">
        <input type="hidden" name="doc" value="kardex">
        <h2 class="st-h2"><?= h(BizPrint::DOCS['kardex']) ?></h2>
        <p class="st-muted">همه‌ی ورود و خروج‌های یک کالا با مانده‌ی بعد از هر حرکت.</p>
        <?php if ($products['rows']): ?>
        <label class="st-field"><span>کالا</span>
            <select name="id" required>
                <?php foreach ($products['rows'] as $r): if ((int)$r['track_stock'] !== 1) { continue; } ?>
                <option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($products['capped']): ?><p class="st-muted-i">فقط ۵۰۰ کالای اول آمده‌اند؛ بقیه از صفحه‌ی خودِ کالا چاپ می‌شوند.</p><?php endif; ?>
        <button type="submit" class="st-btn">نمایش برای چاپ</button>
        <?php else: ?>
        <p class="st-empty">هنوز کالایی ثبت نشده است.</p>
        <?php endif; ?>
    </form>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
