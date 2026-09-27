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

$userId = (int)Auth::userId();
$doc    = getParam('doc');
$doc    = isset(BizPrint::DOCS[$doc]) ? $doc : 'stock';
$id     = (int)getParam('id', '0');
$filter = getParam('f');
$cat    = mb_substr(trim(getParam('c')), 0, 80);
$money  = static fn($v): string => '<span class="pr-num">' . formatMoney((int)$v) . '</span>';
$qty    = static fn($v): string => '<span class="pr-num">' . h(formatQty($v)) . '</span>';

switch ($doc) {
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
