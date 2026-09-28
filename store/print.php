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

switch ($doc) {
case 'invoice':
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
        <thead><tr><th>#</th><th>شرح</th><th class="pr-c-num">مقدار</th><th class="pr-c-num">بها</th><th class="pr-c-num pr-wide">تخفیف</th><th class="pr-c-num">جمع</th></tr></thead>
        <tbody>
        <?php foreach ($inv['lines'] as $n => $l): ?>
            <tr>
                <td><span class="pr-num"><?= toPersianDigits((string)($n + 1)) ?></span></td>
                <td><?= h((string)$l['description']) ?></td>
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
    $period = getParam('p');
    $period = isset(BizReports::PERIODS[$period]) ? $period : 'month';
    require_once __DIR__ . '/../includes/biz_docview.php';
    [$rf, $rt] = BizReports::range($period, BizDocView::gDate(getParam('from')), BizDocView::gDate(getParam('to')));
    $sa = BizReports::sales($userId, $rf, $rt);
    $ca = BizReports::cash($userId, $rf, $rt);
    BizPrint::head($userId, BizPrint::DOCS['sales'], Biz::url('reports.php?p=' . $period), [
        'از' => toJalali($rf), 'تا' => toJalali($rt),
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
    <?php $tp = BizReports::topProducts($userId, $rf, $rt, 20); if ($tp): ?>
    <table class="pr-table">
        <thead><tr><th>کالا</th><th class="pr-c-num">مقدار</th><th class="pr-c-num">فروش</th><th class="pr-c-num pr-wide">سود</th></tr></thead>
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
