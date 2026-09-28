<?php
/**
 * برگه‌های چاپیِ فروشگاه — «پرینت حساب».
 *
 * `?doc=` یکی از `BizPrint::DOCS` است؛ ناشناخته به «موجودی انبار»
 * برمی‌گردد، نه صفحه‌ی خالی. هندسه‌ی برگه از «تنظیماتِ چاپ» می‌آید
 * (`BizPrint::head()`)؛ این فایل فقط محتوای هر سند را می‌نویسد.
 *
 * ⛔ فقط خواندنی — هیچ POST ای ندارد. طرف‌حساب و کالا با
 *    `BizParties::statement()`/`BizProducts::get()` خوانده می‌شوند که شرطِ
 *    `user_id` دارند؛ شناسه‌ی کاربرِ دیگر ۴۰۴ِ خنثی می‌گیرد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_print.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_reports.php';

$userId = (int)Auth::userId();
$doc    = getParam('doc');
$doc    = isset(BizPrint::DOCS[$doc]) ? $doc : 'stock';
$id     = (int)getParam('id', '0');
$filter = getParam('f');
$cat    = mb_substr(trim(getParam('c')), 0, 80);
$money  = static fn($v): string => '<span class="pr-num">' . formatMoney((int)$v) . '</span>';
$qty    = static fn($v): string => '<span class="pr-num">' . h(formatQty($v)) . '</span>';
// مبلغِ علامت‌دار — منفی با «−» جلوی عدد، نه با پرانتز
$signed = static fn($v): string => ((int)$v < 0 ? '−' : '') . '<span class="pr-num">' . formatMoney(abs((int)$v)) . '</span>';

// ⛔ بازه‌ی گزارش‌های دوره‌ای — همان `BizReports::range()` که صفحه‌ی گزارش دارد
require_once __DIR__ . '/../includes/biz_docview.php';
$period = getParam('p');
$period = isset(BizReports::PERIODS[$period]) ? $period : 'month';
[$rf, $rt] = BizReports::range($period, BizDocView::gDate(getParam('from')), BizDocView::gDate(getParam('to')));
$periodBack = Biz::url('reports.php') . '?' . http_build_query(array_filter([
    'p' => $period, 'from' => $period === 'custom' ? BizDocView::jDate($rf) : '', 'to' => $period === 'custom' ? BizDocView::jDate($rt) : '',
], fn($v) => $v !== ''));
$side = getParam('k') === 'purchase' ? 'purchase' : 'sale';

switch ($doc) {
case 'by_product':
    $bp = BizReports::byProduct($userId, $side, $rf, $rt);
    $isSale = $side === 'sale';
    BizPrint::head($userId, BizPrint::SIDE_TITLES['by_product'][$side], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]);
    $cols = $isSale ? 6 : 4; ?>
    <table class="pr-table">
        <thead><tr><th>کالا</th><th class="pr-wide">کد</th><th class="pr-c-num">تعداد</th><th class="pr-c-num">مبلغ</th>
            <?php if ($isSale): ?><th class="pr-c-num pr-wide">بهای تمام‌شده</th><th class="pr-c-num">سود</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$bp['rows']): ?><tr><td colspan="<?= $cols ?>" class="pr-empty">در این بازه سندِ صادرشده‌ای نیست.</td></tr><?php endif; ?>
        <?php foreach ($bp['rows'] as $r): ?>
            <tr>
                <td><?= h((string)$r['name']) ?></td>
                <td class="pr-wide"><span class="pr-num"><?= h((string)($r['sku'] ?? '')) ?></span></td>
                <td class="pr-c-num"><?= $qty($r['qty']) ?> <?= h((string)$r['unit']) ?></td>
                <td class="pr-c-num"><?= $signed($r['amount']) ?></td>
                <?php if ($isSale): ?><td class="pr-c-num pr-wide"><?= $signed($r['cost']) ?></td><td class="pr-c-num"><?= $signed($r['profit']) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">جمع</td><td class="pr-c-num"><?= $signed($bp['amount']) ?></td>
            <?php if ($isSale): ?><td class="pr-c-num pr-wide"><?= $signed($bp['cost']) ?></td><td class="pr-c-num"><?= $signed($bp['profit']) ?></td><?php endif; ?></tr></tfoot>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان و خالصِ برگشت‌اند؛ فقط اسنادِ صادرشده.<?= $isSale ? ' سود = مبلغ − بهای تمام‌شده‌ی لحظه‌ی فروش.' : '' ?></p>
    <?php if ($bp['capped']): ?><p class="pr-note">فهرست بریده شده است؛ بازه را کوتاه‌تر کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

case 'by_invoice':
    $bi = BizReports::byInvoice($userId, $side, $rf, $rt);
    $isSale = $side === 'sale';
    BizPrint::head($userId, BizPrint::SIDE_TITLES['by_invoice'][$side], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]);
    $cols = $isSale ? 7 : 6; ?>
    <table class="pr-table">
        <thead><tr><th>سند</th><th>تاریخ</th><th><?= $isSale ? 'مشتری' : 'فروشنده' ?></th><th class="pr-c-num">مبلغ</th>
            <th class="pr-c-num pr-wide"><?= $isSale ? 'دریافت‌شده' : 'پرداخت‌شده' ?></th><th class="pr-c-num">مانده</th>
            <?php if ($isSale): ?><th class="pr-c-num">سود</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$bi['rows']): ?><tr><td colspan="<?= $cols ?>" class="pr-empty">در این بازه سندِ صادرشده‌ای نیست.</td></tr><?php endif; ?>
        <?php foreach ($bi['rows'] as $r): $s = (int)$r['sign']; ?>
            <tr>
                <td><?= h(BizInvoices::title($r)) ?></td>
                <td><span class="pr-num"><?= h(toJalali((string)$r['inv_date'])) ?></span></td>
                <td><?= $r['party_name'] !== null ? h((string)$r['party_name']) : 'گذری' ?></td>
                <td class="pr-c-num"><?= $signed($s * (int)$r['total']) ?></td>
                <td class="pr-c-num pr-wide"><?= $signed($s * (int)$r['paid']) ?></td>
                <td class="pr-c-num"><?= $r['due'] > 0 ? $signed($s * $r['due']) : '' ?></td>
                <?php if ($isSale): ?><td class="pr-c-num"><?= $signed($r['profit']) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">جمع (خالصِ برگشت)</td><td class="pr-c-num"><?= $signed($bi['total']) ?></td>
            <td class="pr-c-num pr-wide"><?= $signed($bi['paid']) ?></td><td class="pr-c-num"><?= $signed($bi['due']) ?></td>
            <?php if ($isSale): ?><td class="pr-c-num"><?= $signed($bi['profit']) ?></td><?php endif; ?></tr></tfoot>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان؛ برگشتی‌ها با علامتِ منفی. فقط اسنادِ صادرشده.</p>
    <?php if ($bi['capped']): ?><p class="pr-note">فهرست بریده شده است؛ بازه را کوتاه‌تر کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

case 'by_party':
    $bpa = BizReports::byParty($userId, $rf, $rt);
    BizPrint::head($userId, BizPrint::DOCS['by_party'], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]);
    $sm = $bpa['sums']; ?>
    <table class="pr-table">
        <thead><tr><th>نام</th><th class="pr-c-num">فروش</th><th class="pr-c-num pr-wide">برگشت از فروش</th><th class="pr-c-num">خرید</th>
            <th class="pr-c-num pr-wide">برگشت از خرید</th><th class="pr-c-num">دریافت</th><th class="pr-c-num">پرداخت</th><th class="pr-c-num">مانده‌ی امروز</th></tr></thead>
        <tbody>
        <?php if (!$bpa['rows']): ?><tr><td colspan="8" class="pr-empty">در این بازه گردشی نیست.</td></tr><?php endif; ?>
        <?php foreach ($bpa['rows'] as $r): $b = (int)$r['balance']; ?>
            <tr>
                <td><?= h($r['name']) ?></td>
                <td class="pr-c-num"><?= $r['sale'] ? $money($r['sale']) : '' ?></td>
                <td class="pr-c-num pr-wide"><?= $r['sale_return'] ? $money($r['sale_return']) : '' ?></td>
                <td class="pr-c-num"><?= $r['purchase'] ? $money($r['purchase']) : '' ?></td>
                <td class="pr-c-num pr-wide"><?= $r['purchase_return'] ? $money($r['purchase_return']) : '' ?></td>
                <td class="pr-c-num"><?= $r['receipt'] ? $money($r['receipt']) : '' ?></td>
                <td class="pr-c-num"><?= $r['payment'] ? $money($r['payment']) : '' ?></td>
                <td class="pr-c-num"><?= $money(abs($b)) ?> <?= $b > 0 ? 'بد' : ($b < 0 ? 'بس' : '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>جمع</td><td class="pr-c-num"><?= $money($sm['sale']) ?></td><td class="pr-c-num pr-wide"><?= $money($sm['sale_return']) ?></td>
            <td class="pr-c-num"><?= $money($sm['purchase']) ?></td><td class="pr-c-num pr-wide"><?= $money($sm['purchase_return']) ?></td>
            <td class="pr-c-num"><?= $money($sm['receipt']) ?></td><td class="pr-c-num"><?= $money($sm['payment']) ?></td><td></td></tr></tfoot>
    </table>
    <p class="pr-note">ستون‌ها گردشِ همین بازه‌اند و «مانده» وضعیتِ امروز: «بد» یعنی او به فروشگاه بدهکار است، «بس» یعنی فروشگاه به او. مبلغ‌ها به تومان.</p>
    <?php if ($bpa['capped']): ?><p class="pr-note">فهرست بریده شده است.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

case 'serials':
    $sf = isset(BizSerial::REPORT_FILTERS[$filter]) ? $filter : '';
    $sr = BizSerial::report($userId, $sf, $id);
    $sp = $id > 0 ? BizProducts::get($userId, $id) : null;
    if ($id > 0 && !$sp) { Biz::notFound(); }
    BizPrint::head($userId, BizPrint::DOCS['serials'] . ($sf !== '' ? ' — ' . BizSerial::REPORT_FILTERS[$sf] : ''),
        $sp ? Biz::url('product.php?id=' . $id) : Biz::url('reports.php'), [
        'کالا'     => $sp ? (string)$sp['name'] : '',
        'در انبار' => toPersianDigits((string)$sr['in']) . ' گوشی',
        'بیرون'    => toPersianDigits((string)$sr['out']) . ' گوشی',
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>IMEI</th><th>کالا</th><th>ورود</th><th class="pr-c-num pr-wide">بهای خرید</th><th>وضعیت</th><th class="pr-c-num pr-wide">بهای فروش</th></tr></thead>
        <tbody>
        <?php if (!$sr['rows']): ?><tr><td colspan="6" class="pr-empty">هیچ گوشیِ IMEIداری با این صافی نیست.</td></tr><?php endif; ?>
        <?php foreach ($sr['rows'] as $u): $f = $u['first']; $l = $u['last']; $out = $u['state'] === 'out'; ?>
            <tr>
                <td><span class="pr-num" dir="ltr"><?= h($u['imei1']) ?></span><?php if ($u['imei2'] !== null): ?><br><span class="pr-num pr-sub" dir="ltr"><?= h($u['imei2']) ?></span><?php endif; ?></td>
                <td><?= h($u['product']) ?></td>
                <td><?= h(BizInvoices::KINDS[$f['kind']] ?? '') ?> <span class="pr-num"><?= toPersianDigits((string)$f['number']) ?></span> · <span class="pr-num"><?= h(toJalali($f['date'])) ?></span><br><span class="pr-sub"><?= h($f['party']) ?></span></td>
                <td class="pr-c-num pr-wide"><?= in_array($f['kind'], BizSerial::IN_KINDS, true) ? $money($f['price']) : '' ?></td>
                <td><?php if ($out): ?><?= h(BizInvoices::KINDS[$l['kind']] ?? '') ?> <span class="pr-num"><?= toPersianDigits((string)$l['number']) ?></span> · <span class="pr-num"><?= h(toJalali($l['date'])) ?></span><br><span class="pr-sub"><?= h($l['party']) ?></span><?php else: ?>در انبار<?php endif; ?></td>
                <td class="pr-c-num pr-wide"><?= $out ? $money($l['price']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="pr-note">وضعیتِ هر گوشی از آخرین سندِ صادرشده با همان IMEI می‌آید؛ سندِ باطل اثری ندارد. گوشی‌ای که پیش از ثبتِ IMEI (موجودی اول دوره) وارد شده اینجا نیست.</p>
    <?php if ($sr['capped']): ?><p class="pr-note">فهرست بریده شده است؛ با صافیِ وضعیت یا کالا چاپ کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
    break;

case 'account':
    $as = BizReports::accountStatement($userId, $id, $rf, $rt);
    if (!$as) { Biz::notFound(); }
    $acc = $as['account'];
    BizPrint::head($userId, BizPrint::DOCS['account'] . ' — ' . $acc['name'], Biz::url('payments.php?acc=' . $id), [
        'حساب'  => (string)$acc['name'],
        'نوع'   => BizCash::KINDS[$acc['kind']] ?? '',
        'بازه'  => BizReports::rangeLabel($rf, $rt),
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>تاریخ</th><th>شرح</th><th class="pr-c-num">ورود</th><th class="pr-c-num">خروج</th><th class="pr-c-num">مانده</th></tr></thead>
        <tbody>
            <tr class="pr-group"><td colspan="4">مانده‌ی ابتدای بازه</td><td class="pr-c-num"><?= $signed($as['opening']) ?></td></tr>
        <?php if (!$as['lines']): ?><tr><td colspan="5" class="pr-empty">در این بازه گردشی نیست.</td></tr><?php endif; ?>
        <?php foreach ($as['lines'] as $l): ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali($l['date'])) ?></span></td>
                <td><?= h($l['desc']) ?></td>
                <td class="pr-c-num"><?= $l['in'] ? $money($l['in']) : '' ?></td>
                <td class="pr-c-num"><?= $l['out'] ? $money($l['out']) : '' ?></td>
                <td class="pr-c-num"><?= $signed($l['balance']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">جمع</td><td class="pr-c-num"><?= $money($as['in']) ?></td><td class="pr-c-num"><?= $money($as['out']) ?></td><td class="pr-c-num"><?= $signed($as['closing']) ?></td></tr></tfoot>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان؛ فقط دریافت و پرداخت‌های باطل‌نشده.</p>
    <?php if ($as['capped']): ?><p class="pr-note">فقط ردیف‌های آخر آمده‌اند؛ بازه را کوتاه‌تر کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['صندوق‌دار', 'تأیید']);
    break;

case 'invoice':
    require_once __DIR__ . '/../includes/biz_docview.php';
    $inv = BizInvoices::get($userId, $id);
    if (!$inv || $inv['status'] === 'draft') { Biz::notFound(); }
    $isSaleSide = in_array($inv['kind'], ['sale', 'sale_return'], true);
    $back = getParam('back') === 'quick' ? Biz::url('quick-sale.php') : Biz::url('invoice.php?id=' . $id);
    BizPrint::head($userId, BizInvoices::title($inv) . ($inv['status'] === 'void' ? ' (باطل)' : ''), $back, [
        'تاریخ'                              => toJalali((string)$inv['inv_date']),
        'سررسید'                             => !empty($inv['due_date']) ? toJalali((string)$inv['due_date']) : '',
        ($isSaleSide ? 'خریدار' : 'فروشنده') => $inv['party_id'] !== null ? (string)$inv['party_name'] : 'گذری',
        'تلفن'                               => (string)($inv['party_phone'] ?? ''),
        'نشانی'                              => (string)($inv['party_address'] ?? ''),
        'فاکتورِ اصلی'                       => $inv['ref_invoice_id'] !== null ? toPersianDigits((string)$inv['ref_number']) : '',
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>#</th><th>کالا</th><th class="pr-c-num">تعداد</th><th class="pr-c-num">فی</th><th class="pr-c-num pr-wide">تخفیف</th><th class="pr-c-num">جمع</th></tr></thead>
        <tbody>
        <?php foreach ($inv['lines'] as $n => $l): ?>
            <tr>
                <td><span class="pr-num"><?= toPersianDigits((string)($n + 1)) ?></span></td>
                <td><?= h((string)$l['description']) ?><?= BizDocView::lineSub($l, 'pr-imei') ?></td>
                <td class="pr-c-num"><?= $qty($l['qty']) ?></td>
                <td class="pr-c-num"><?= $money($l['unit_price']) ?></td>
                <td class="pr-c-num pr-wide"><?= (int)$l['line_discount'] > 0 ? $money($l['line_discount']) : '' ?></td>
                <td class="pr-c-num"><?= $money($l['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="pr-totals">
        <?php if ((int)$inv['discount'] > 0 || (int)$inv['extra'] > 0): ?>
        <div><span>جمعِ ردیف‌ها</span><span><?= $money($inv['subtotal']) ?></span></div>
        <?php if ((int)$inv['discount'] > 0): ?><div><span>تخفیف</span><span>−<?= $money($inv['discount']) ?></span></div><?php endif; ?>
        <?php if ((int)$inv['extra'] > 0): ?><div><span>حمل و هزینه‌ی دیگر</span><span><?= $money($inv['extra']) ?></span></div><?php endif; ?>
        <?php endif; ?>
        <div class="pr-grand"><span>مبلغِ کل</span><span><?= $money($inv['total']) ?> تومان</span></div>
        <?php if ($inv['status'] === 'issued'): ?>
        <div><span><?= BizInvoices::SETTLED_BY[$inv['kind']] === 'receipt' ? 'دریافت‌شده' : 'پرداخت‌شده' ?></span><span><?= $money($inv['paid']) ?></span></div>
        <div><span>مانده</span><span><?= $money(BizInvoices::remaining($inv)) ?></span></div>
        <?php endif; ?>
        <?php
        // ⛔ «مانده‌ی قبلی / کل» — کلیدش در تنظیماتِ چاپ (`show_balance`)
        $ba = !empty(Biz::printPrefs($userId)['show_balance']) && $inv['status'] === 'issued' && $inv['party_id'] !== null
            ? BizParties::balanceAround($userId, (int)$inv['party_id'], $id) : null;
        if ($ba !== null) { echo BizDocView::balanceRows($ba, (string)$inv['party_name'], $money, 'pr-bal'); }
        ?>
    </div>
    <?php if ((string)$inv['note'] !== ''): ?><p class="pr-note"><?= nl2br(h((string)$inv['note'])) ?></p><?php endif; ?>
    <?php BizPrint::foot($userId, $isSaleSide ? ['امضای فروشنده', 'امضای خریدار'] : ['امضای تحویل‌گیرنده', 'امضای فروشنده']);
    break;

case 'payment':
    $pay = BizPay::get($userId, $id);
    if (!$pay) { Biz::notFound(); }
    BizPrint::head($userId, (BizPay::KINDS[$pay['kind']] ?? '') . ' شماره‌ی ' . toPersianDigits((string)$pay['number']) . ($pay['status'] === 'void' ? ' (باطل)' : ''),
        Biz::url('payment.php?id=' . $id), [
        'تاریخ'     => toJalali((string)$pay['pay_date']),
        'طرف‌حساب' => (string)($pay['party_name'] ?? ''),
        'شرح'       => (string)($pay['title'] ?? ''),
        'صندوق'     => (string)$pay['account_name'] . ($pay['kind'] === 'transfer' ? ' ← ' . (string)$pay['to_account_name'] : ''),
        'روش'       => $pay['kind'] === 'transfer' ? '' : (BizPay::METHODS[$pay['method']] ?? ''),
    ]); ?>
    <div class="pr-totals"><div class="pr-grand"><span>مبلغ</span><span><?= $money($pay['amount']) ?> تومان</span></div></div>
    <?php if ($pay['allocations']): ?>
    <table class="pr-table">
        <thead><tr><th>بابتِ سند</th><th class="pr-c-num">مبلغ</th></tr></thead>
        <tbody><?php foreach ($pay['allocations'] as $al): ?><tr><td><?= h(BizInvoices::title($al)) ?></td><td class="pr-c-num"><?= $money($al['amount']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <?php if ((string)$pay['note'] !== ''): ?><p class="pr-note"><?= nl2br(h((string)$pay['note'])) ?></p><?php endif; ?>
    <?php BizPrint::foot($userId, ['امضای پرداخت‌کننده', 'امضای دریافت‌کننده']);
    break;

case 'sales':
    $sa = BizReports::sales($userId, $rf, $rt);
    $ca = BizReports::cash($userId, $rf, $rt);
    BizPrint::head($userId, BizPrint::DOCS['sales'], $periodBack, [
        'بازه' => BizReports::rangeLabel($rf, $rt),
    ]); ?>
    <table class="pr-table">
        <tbody>
            <tr><td>فروش</td><td class="pr-c-num"><?= $money($sa['sales']) ?></td></tr>
            <tr><td>برگشت از فروش</td><td class="pr-c-num">−<?= $money($sa['returns']) ?></td></tr>
            <tr class="pr-group"><td>فروشِ خالص</td><td class="pr-c-num"><?= $money($sa['net']) ?></td></tr>
            <tr><td>بهای تمام‌شده‌ی کالای فروخته</td><td class="pr-c-num">−<?= $money($sa['cogs']) ?></td></tr>
            <tr class="pr-group"><td>سودِ ناخالص</td><td class="pr-c-num"><?= $sa['gross'] < 0 ? '−' : '' ?><?= $money(abs($sa['gross'])) ?></td></tr>
            <tr><td>هزینه‌های فروشگاه</td><td class="pr-c-num">−<?= $money($ca['expense']) ?></td></tr>
            <tr><td>درآمدِ متفرقه</td><td class="pr-c-num"><?= $money($ca['income']) ?></td></tr>
            <?php $afterExp = $sa['gross'] - $ca['expense'] + $ca['income']; ?>
            <tr class="pr-group"><td>سود پس از هزینه</td><td class="pr-c-num"><?= $afterExp < 0 ? '−' : '' ?><?= $money(abs($afterExp)) ?></td></tr>
        </tbody>
    </table>
    <?php $ex = BizReports::expenses($userId, $rf, $rt, 100); if ($ex): ?>
    <table class="pr-table">
        <thead><tr><th>هزینه‌ها به تفکیکِ شرح</th><th class="pr-c-num pr-wide">دفعات</th><th class="pr-c-num">مبلغ</th></tr></thead>
        <tbody><?php foreach ($ex as $e): ?><tr><td><?= h((string)$e['title']) ?></td><td class="pr-c-num pr-wide"><span class="pr-num"><?= toPersianDigits((string)$e['n']) ?></span></td><td class="pr-c-num"><?= $money($e['s']) ?></td></tr><?php endforeach; ?></tbody>
        <tfoot><tr><td>جمعِ هزینه‌ها</td><td class="pr-wide"></td><td class="pr-c-num"><?= $money($ca['expense']) ?></td></tr></tfoot>
    </table>
    <?php endif; ?>
    <?php $tp = BizReports::topProducts($userId, $rf, $rt, 20); if ($tp): ?>
    <table class="pr-table">
        <thead><tr><th>کالا</th><th class="pr-c-num">تعداد</th><th class="pr-c-num">فروش</th><th class="pr-c-num pr-wide">سود</th></tr></thead>
        <tbody><?php foreach ($tp as $t): ?><tr><td><?= h((string)$t['name']) ?></td><td class="pr-c-num"><?= $qty($t['qty']) ?></td><td class="pr-c-num"><?= $money($t['rev']) ?></td><td class="pr-c-num pr-wide"><?= $money($t['profit']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <p class="pr-note">مبلغ‌ها به تومان؛ فقط اسنادِ صادرشده.</p>
    <?php BizPrint::foot($userId);
    break;

case 'party':
    $s = BizParties::statement($userId, $id);
    if (!$s) { Biz::notFound(); }
    $party = $s['party'];
    BizPrint::head($userId, BizPrint::DOCS['party'] . ' — ' . $party['name'], Biz::url('party.php?id=' . $id), [
        'نام'   => (string)$party['name'],
        'نوع'   => BizParties::KINDS[$party['kind']] ?? '',
        'تلفن'  => (string)($party['phone'] ?? ''),
        'نشانی' => (string)($party['address'] ?? ''),
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>تاریخ</th><th>شرح</th><th class="pr-c-num">بدهکار</th><th class="pr-c-num">بستانکار</th><th class="pr-c-num">مانده</th></tr></thead>
        <tbody>
        <?php if (!$s['lines']): ?>
            <tr><td colspan="5" class="pr-empty">هیچ گردشی ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($s['lines'] as $l): ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali($l['date'])) ?></span></td>
                <td><?= h($l['desc']) ?></td>
                <td class="pr-c-num"><?= $l['debit'] ? $money($l['debit']) : '' ?></td>
                <td class="pr-c-num"><?= $l['credit'] ? $money($l['credit']) : '' ?></td>
                <td class="pr-c-num"><?= $money(abs($l['balance'])) ?> <?= $l['balance'] > 0 ? 'بد' : ($l['balance'] < 0 ? 'بس' : '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">جمع</td><td class="pr-c-num"><?= $money($s['debit']) ?></td><td class="pr-c-num"><?= $money($s['credit']) ?></td><td></td></tr></tfoot>
    </table>
    <div class="pr-total">
        <span><?= $s['balance'] > 0 ? 'مانده — بدهکار به فروشگاه' : ($s['balance'] < 0 ? 'مانده — طلبکار از فروشگاه' : 'مانده — تسویه') ?></span>
        <span><?= $money(abs($s['balance'])) ?> تومان</span>
    </div>
    <p class="pr-note">«بد» یعنی او به فروشگاه بدهکار است و «بس» یعنی فروشگاه به او بدهکار است. مبلغ‌ها به تومان.</p>
    <?php BizPrint::foot($userId, ['امضای فروشگاه', 'امضای طرف‌حساب']);
    break;

case 'kardex':
    $p = BizProducts::get($userId, $id);
    if (!$p) { Biz::notFound(); }
    $led = (int)$p['track_stock'] === 1 ? BizStock::ledger($userId, $id) : ['rows' => [], 'capped' => false];
    BizPrint::head($userId, BizPrint::DOCS['kardex'] . ' — ' . $p['name'], Biz::url('product.php?id=' . $id), [
        'کالا'            => (string)$p['name'],
        'کد'              => (string)$p['sku'],
        'واحد'            => (string)$p['unit'],
        'موجودیِ فعلی'    => formatQty($p['stock_qty']) . ' ' . $p['unit'],
        'میانگینِ بها'    => formatMoney((int)round((float)$p['avg_cost'])) . ' تومان',
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>تاریخ</th><th>شرح</th><th class="pr-c-num">ورود</th><th class="pr-c-num">خروج</th><th class="pr-c-num">مانده</th><th class="pr-c-num pr-wide">بهای واحد</th></tr></thead>
        <tbody>
        <?php if (!$led['rows']): ?>
            <tr><td colspan="6" class="pr-empty"><?= (int)$p['track_stock'] === 1 ? 'هیچ حرکتی ثبت نشده است.' : 'این قلم خدمت است و موجودی ندارد.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($led['rows'] as $m): $q = (float)$m['qty']; ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali((string)$m['move_date'])) ?></span></td>
                <td><?= h(BizStock::KINDS[$m['kind']] ?? (string)$m['kind']) ?><?= (string)($m['note'] ?? '') !== '' ? ' — ' . h((string)$m['note']) : '' ?></td>
                <td class="pr-c-num"><?= $q > 0 ? $qty($q) : '' ?></td>
                <td class="pr-c-num"><?= $q < 0 ? $qty(-$q) : '' ?></td>
                <td class="pr-c-num"><?= $qty($m['balance']) ?></td>
                <td class="pr-c-num pr-wide"><?= $m['unit_cost'] !== null ? $money($m['unit_cost']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($led['capped']): ?><p class="pr-note">فهرست بریده شده است؛ فقط حرکت‌های اول آمده‌اند.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
    break;

case 'parties':
    $filter = isset(BizParties::FILTERS[$filter]) ? $filter : '';
    $all = BizParties::all($userId, $filter);
    BizPrint::head($userId, BizPrint::DOCS['parties'] . ($filter !== '' ? ' — ' . BizParties::FILTERS[$filter] : ''), Biz::url('parties.php'));
    $rec = 0; $pay = 0; ?>
    <table class="pr-table">
        <thead><tr><th>ردیف</th><th>نام</th><th class="pr-wide">تلفن</th><th class="pr-c-num">بدهکار</th><th class="pr-c-num">بستانکار</th></tr></thead>
        <tbody>
        <?php if (!$all['rows']): ?><tr><td colspan="5" class="pr-empty">طرف‌حسابی نیست.</td></tr><?php endif; ?>
        <?php foreach ($all['rows'] as $i => $r): $b = (int)$r['balance']; $rec += max($b, 0); $pay += max(-$b, 0); ?>
            <tr>
                <td><span class="pr-num"><?= toPersianDigits((string)($i + 1)) ?></span></td>
                <td><?= h((string)$r['name']) ?></td>
                <td class="pr-wide"><span class="pr-num"><?= h((string)($r['phone'] ?? '')) ?></span></td>
                <td class="pr-c-num"><?= $b > 0 ? $money($b) : '' ?></td>
                <td class="pr-c-num"><?= $b < 0 ? $money(-$b) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">جمع</td><td class="pr-wide"></td><td class="pr-c-num"><?= $money($rec) ?></td><td class="pr-c-num"><?= $money($pay) ?></td></tr></tfoot>
    </table>
    <p class="pr-note">«بدهکار» یعنی به فروشگاه بدهکار است. مبلغ‌ها به تومان.</p>
    <?php if ($all['capped']): ?><p class="pr-note">فهرست بریده شده است.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

default: // stock | prices
    $filter = isset(BizProducts::FILTERS[$filter]) ? $filter : '';
    $all = BizProducts::all($userId, $filter, $cat);
    $isPrice = $doc === 'prices';
    $title = BizPrint::DOCS[$doc] . ($filter !== '' ? ' — ' . BizProducts::FILTERS[$filter] : '') . ($cat !== '' ? ' — ' . $cat : '');
    BizPrint::head($userId, $title, Biz::url('products.php'));
    $sum = 0; $group = null; $cols = $isPrice ? 4 : 5; ?>
    <table class="pr-table">
        <thead><tr>
            <th>کالا</th><th class="pr-wide">کد</th>
            <?php if ($isPrice): ?>
                <th>واحد</th><th class="pr-c-num">قیمتِ فروش</th>
            <?php else: ?>
                <th class="pr-c-num">موجودی</th><th class="pr-c-num pr-wide">میانگینِ بها</th><th class="pr-c-num">ارزش</th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$all['rows']): ?><tr><td colspan="<?= $cols ?>" class="pr-empty">کالایی نیست.</td></tr><?php endif; ?>
        <?php foreach ($all['rows'] as $r):
            $track = (int)$r['track_stock'] === 1;
            if (!$isPrice && !$track) { continue; }
            $g = (string)($r['category'] ?? '');
            if ($g !== $group): $group = $g; ?>
            <tr class="pr-group"><td colspan="<?= $cols ?>"><?= $g !== '' ? h($g) : 'بی‌دسته' ?></td></tr>
            <?php endif;
            $val = (int)round(max(0, (float)$r['stock_qty']) * (float)$r['avg_cost']); $sum += $val; ?>
            <tr>
                <td><?= h((string)$r['name']) ?></td>
                <td class="pr-wide"><span class="pr-num"><?= h((string)$r['sku']) ?></span></td>
                <?php if ($isPrice): ?>
                    <td><?= h((string)$r['unit']) ?></td>
                    <td class="pr-c-num"><?= $money($r['sell_price']) ?></td>
                <?php else: ?>
                    <td class="pr-c-num"><?= $qty($r['stock_qty']) ?> <?= h((string)$r['unit']) ?></td>
                    <td class="pr-c-num pr-wide"><?= $money(round((float)$r['avg_cost'])) ?></td>
                    <td class="pr-c-num"><?= $money($val) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (!$isPrice): ?>
        <tfoot><tr><td>جمعِ ارزشِ انبار</td><td class="pr-wide"></td><td></td><td class="pr-wide"></td><td class="pr-c-num"><?= $money($sum) ?></td></tr></tfoot>
        <?php endif; ?>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان<?= $isPrice ? '' : '؛ ارزش = موجودی × میانگینِ بهای خرید' ?>. خدمت‌ها <?= $isPrice ? 'هم آمده‌اند' : 'در این فهرست نیستند' ?>.</p>
    <?php if ($all['capped']): ?><p class="pr-note">فهرست بریده شده است؛ با صافیِ دسته چند برگه چاپ کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
}
