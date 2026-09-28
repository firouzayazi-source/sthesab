<?php
/**
 * نمایشِ اسنادِ فروشگاه — تکه‌های مشترکِ صفحه‌های `store/`.
 *
 * فقط تابعِ ایستا؛ هیچ متغیرِ سراسری‌ای نمی‌سازد (قاعده ۵۰). منطقِ پول
 * اینجا نیست — فقط رندرِ چیزی که `BizInvoices`/`BizPay` برگردانده‌اند.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

require_once __DIR__ . '/biz_docs.php';

final class BizDocView
{
    /** دو «سمتِ» فهرستِ اسناد: فروش و خرید، هر کدام با برگشتِ خودش. */
    public const SIDES = [
        'sale'     => ['page' => 'sales.php',     'title' => 'فاکتورهای فروش', 'doc' => 'sale',     'ret' => 'sale_return',     'party' => 'مشتری'],
        'purchase' => ['page' => 'purchases.php', 'title' => 'فاکتورهای خرید', 'doc' => 'purchase', 'ret' => 'purchase_return', 'party' => 'فروشنده'],
    ];

    /** سمتِ یک نوعِ سند. */
    public static function sideOf(string $kind): string
    {
        return in_array($kind, ['purchase', 'purchase_return'], true) ? 'purchase' : 'sale';
    }

    /** نشانِ وضعیت — رنگ از `.st-pill.is-*` (خاکستری/آبی/کهربایی/سبز/قرمز). */
    public static function pill(array $inv): string
    {
        $s = BizInvoices::state($inv);
        return '<span class="st-pill is-' . h($s) . '">' . h(BizInvoices::STATUS_LABELS[$s] ?? $s) . '</span>';
    }

    public static function money(int $v): string
    {
        return '<span class="st-num' . ($v < 0 ? ' is-neg' : '') . '">' . ($v < 0 ? '−' : '') . formatMoney(abs($v)) . '</span>';
    }

    /** تاریخِ شمسی برای فیلدِ متنی (ارقامِ لاتین تا تایپِ دوباره آسان باشد). */
    public static function jDate(?string $g): string
    {
        if ($g === null || $g === '' || !isValidDate($g)) { return ''; }
        [$y, $m, $d] = array_map('intval', explode('-', $g));
        [$jy, $jm, $jd] = gregorianToJalali($y, $m, $d);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /** ورودیِ تاریخِ شمسی («۱۴۰۵/۷/۵» یا «1405-07-05») → میلادی؛ خالی یا نامعتبر → ''. */
    public static function gDate(string $j): string
    {
        $j = trim(toLatinDigits($j));
        if ($j === '') { return ''; }
        if (isValidDate($j) && (int)substr($j, 0, 4) > 1700) { return $j; }       // از پیش میلادی
        $g = jalaliStringToGregorian(str_replace('-', '/', $j));
        return is_string($g) && isValidDate($g) ? $g : '';
    }

    /** طرف‌حساب‌های فعال برای `<select>` — سقف دارد و می‌گوید. */
    public static function parties(int $userId, int $cap = 2000): array
    {
        return BizParties::all($userId, '', $cap)['rows'];
    }

    /** صندوق‌های فعال. */
    public static function accounts(int $userId): array
    {
        return array_values(array_filter(BizCash::list($userId), fn($a) => (int)$a['is_active'] === 1));
    }

    /**
     * `<datalist>`ِ کالاها — مقدار = نام، و کد و قیمت‌ها در data-* برای `store.js`.
     *
     * با `$units` (فاکتورِ فروش و فروشِ سریع) گوشی‌های **همین حالا در انبار**
     * هم هر کدام یک گزینه می‌شوند: مقدار = IMEI و برچسب = نامِ گوشی. پس
     * جست‌وجوی مرورگر هم با تکه‌ای از IMEI پیدایش می‌کند هم با نامِ گوشی
     * (کروم برچسب را هم می‌گردد)، و انتخابش ردیف را با کالا و هر دو IMEI پر
     * می‌کند. ⛔ فهرستِ گوشی‌ها از `BizSerial::inStock()` است — تنها تعریفِ
     * «در انبار».
     */
    public static function productDatalist(int $userId, string $id = 'bizProducts', int $cap = 2000, bool $units = false): string
    {
        $rows = BizProducts::all($userId, '', '', $cap)['rows'];
        $out = '<datalist id="' . h($id) . '">';
        $byId = [];
        $attrs = function (array $p): string {
            return ' data-id="' . (int)$p['id'] . '" data-sku="' . h((string)$p['sku']) . '" data-sell="' . (int)$p['sell_price']
                 . '" data-buy="' . (int)$p['buy_price'] . '" data-unit="' . h((string)$p['unit']) . '" data-stock="'
                 . h((string)(float)$p['stock_qty']) . '" data-track="' . (int)$p['track_stock'] . '" data-serial="'
                 . (BizProducts::typeOf($p) === 'phone' ? 1 : 0) . '"';
        };
        foreach ($rows as $p) {
            $byId[(int)$p['id']] = $p;
            $label = trim(((string)$p['sku'] !== '' ? $p['sku'] . ' · ' : '') . formatMoney((int)$p['sell_price'])
                   . ((int)$p['track_stock'] === 1 ? ' · موجودی ' . formatQty($p['stock_qty']) : ''));
            $out .= '<option value="' . h((string)$p['name']) . '" label="' . h($label) . '"' . $attrs($p) . '></option>';
        }
        if ($units) {
            foreach (BizSerial::inStock($userId, 0, $cap)['rows'] as $u) {
                $p = $byId[$u['product_id']] ?? null;
                if ($p === null) { continue; }                       // کالای غیرفعال: از فهرستِ جست‌وجو بیرون
                $out .= '<option value="' . h($u['imei1']) . '" label="' . h((string)$p['name'] . ' · گوشیِ در انبار'
                      . ($u['imei2'] !== null ? ' · ' . $u['imei2'] : '')) . '"' . $attrs($p) . ' data-name="' . h((string)$p['name'])
                      . '" data-imei1="' . h($u['imei1']) . '" data-imei2="' . h((string)$u['imei2']) . '"></option>';
            }
        }
        return $out . '</datalist>';
    }

    /**
     * ردیف‌های ویرایشگرِ فاکتور — فرمِ ساده‌ی HTML؛ `store.js` فقط جمعِ زنده و
     * «افزودنِ ردیف» را بهتر می‌کند. ⛔ هر ردیف `product_id`ِ پنهان دارد ولی
     * سرور بدونِ آن هم با کد یا نامِ دقیق تطبیق می‌دهد.
     *
     * هر ردیف دو خانه‌ی IMEI دارد که فقط برای گوشی دیده می‌شوند (`serial` یا
     * IMEIِ پر؛ `store.js` با انتخابِ گوشی بازشان می‌کند). با `$plus` کنارِ
     * خانه‌ی کالا دکمه‌ی «+» می‌آید: یک دکمه‌ی **فرم** که همان صفحه را با
     * پنلِ «کالای تازه» برمی‌گرداند — بی‌جاوااسکریپت هم کار می‌کند و هیچ
     * چیزی از فاکتورِ نیمه‌کاره گم نمی‌شود (کلِ فرم با آن فرستاده می‌شود).
     * @param array<int,array> $lines ردیف‌های موجود (از سند یا فرمِ ردشده)
     */
    public static function lineRows(array $lines, int $blank = 3, bool $plus = false): string
    {
        $rows = array_values($lines);
        for ($i = 0; $i < $blank; $i++) { $rows[] = []; }
        $out = '';
        foreach ($rows as $i => $l) {
            $item  = (string)($l['item'] ?? $l['description'] ?? '');
            $pid   = (int)($l['product_id'] ?? 0);
            $qty   = isset($l['qty']) && $l['qty'] !== '' ? (is_numeric($l['qty']) ? formatQty($l['qty']) : (string)$l['qty']) : '';
            $price = $l['price'] ?? (isset($l['unit_price']) ? (string)(int)$l['unit_price'] : '');
            $disc  = $l['disc'] ?? (isset($l['line_discount']) && (int)$l['line_discount'] > 0 ? (string)(int)$l['line_discount'] : '');
            $lt    = isset($l['line_total']) ? formatMoney((int)$l['line_total']) : '';
            $free  = $item !== '' && $pid === 0 && !empty($l['description']);
            $im1   = (string)($l['imei1'] ?? '');
            $im2   = (string)($l['imei2'] ?? '');
            $showImei = !empty($l['serial']) || !empty($l['has_serial']) || $im1 !== '' || $im2 !== '';
            $out .= '<tr class="st-line" data-row>'
                  . '<td class="st-line-no st-num">' . toPersianDigits((string)($i + 1)) . '</td>'
                  . '<td class="st-line-item"><div class="st-item-wrap">'
                  . '<input type="text" name="lines[' . $i . '][item]" value="' . h($item) . '" list="bizProducts" autocomplete="off" placeholder="نام، کد، بارکد یا IMEI" data-item>'
                  . ($plus ? '<button type="submit" name="np_open" value="' . $i . '" class="st-plus" formnovalidate data-np-open title="تعریفِ کالای تازه" aria-label="تعریفِ کالای تازه">+</button>' : '')
                  . '</div>'
                  . '<input type="hidden" name="lines[' . $i . '][product_id]" value="' . ($pid ?: '') . '" data-pid>'
                  . '<div class="st-line-imei" data-imei-box' . ($showImei ? '' : ' hidden') . '>'
                  . '<input type="text" name="lines[' . $i . '][imei1]" value="' . h($im1) . '" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="IMEI ۱" aria-label="IMEI ۱" data-imei1>'
                  . '<input type="text" name="lines[' . $i . '][imei2]" value="' . h($im2) . '" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="IMEI ۲ (اختیاری)" aria-label="IMEI ۲" data-imei2>'
                  . '</div>'
                  . ($free ? '<span class="st-line-note">شرحِ آزاد — بی‌اثر بر موجودی</span>' : '') . '</td>'
                  . '<td><input type="text" name="lines[' . $i . '][qty]" value="' . h($qty) . '" inputmode="decimal" dir="ltr" placeholder="۱" data-qty></td>'
                  . '<td><input type="text" name="lines[' . $i . '][price]" value="' . h((string)$price) . '" inputmode="numeric" dir="ltr" data-price></td>'
                  . '<td class="st-hide-sm"><input type="text" name="lines[' . $i . '][disc]" value="' . h((string)$disc) . '" inputmode="numeric" dir="ltr" data-disc></td>'
                  . '<td class="st-td-num"><span class="st-num" data-lt>' . $lt . '</span></td>'
                  . '</tr>';
        }
        return $out;
    }

    /** IMEIِ یک ردیفِ سند زیرِ شرح — سند، برگشت و چاپ همه از همین. */
    public static function imeiLine(array $l, string $class = 'st-imei'): string
    {
        $n = array_values(array_filter([(string)($l['imei1'] ?? ''), (string)($l['imei2'] ?? '')], fn($v) => $v !== ''));
        return $n ? '<span class="' . h($class) . '" dir="ltr">IMEI ' . h(implode(' / ', $n)) . '</span>' : '';
    }

    /**
     * ردیف‌های فرمِ ردشده را با آنچه سرور فهمید هم‌تراز می‌کند: کالای
     * پیدا‌شده (مثلاً از IMEIِ تایپ‌شده در خانه‌ی کالا)، «گوشی است؟» و IMEIها.
     * ⛔ فقط نمایش؛ ذخیره همیشه دوباره از `parseLines` می‌گذرد.
     * @param array<int,array> $formLines
     * @param array<int,array> $meta `parseLines()['meta']`
     */
    public static function mergeMeta(array $formLines, array $meta): array
    {
        foreach ($formLines as $k => $l) {
            $m = $meta[$k] ?? null;
            if ($m === null) { continue; }
            $formLines[$k]['serial'] = $m['serial'];
            if ($m['product_id'] !== null) {
                $formLines[$k]['product_id'] = $m['product_id'];
                if (BizSerial::valid(BizSerial::norm((string)($l['item'] ?? '')))) { $formLines[$k]['item'] = $m['name']; }
            }
            if ($m['imei1'] !== '') { $formLines[$k]['imei1'] = $m['imei1']; $formLines[$k]['imei2'] = $m['imei2']; }
        }
        return $formLines;
    }

    /**
     * فهرستِ اسنادِ یک سمت (فروش/خرید) — تنها رندرِ «فاکتورهای فروش» و
     * «فاکتورهای خرید»؛ دو صفحه‌ی `sales.php`/`purchases.php` فقط سمت را
     * می‌گویند، پس دو نسخه‌ی فهرست وجود ندارد.
     */
    public static function docList(int $userId, string $side): void
    {
        $cfg    = self::SIDES[$side];
        $tab    = getParam('t') === 'returns' ? 'returns' : 'docs';
        $kind   = $tab === 'returns' ? $cfg['ret'] : $cfg['doc'];
        $filter = getParam('f');
        $filter = isset(BizInvoices::FILTERS[$filter]) ? $filter : '';
        $q      = mb_substr(trim(getParam('q')), 0, 80);
        $from   = self::gDate(getParam('from'));
        $to     = self::gDate(getParam('to'));
        $page   = max(1, (int)getParam('page', '1'));
        $list   = BizInvoices::list($userId, [$kind], $q, $filter, $page, $from, $to);
        $keep   = ['t' => $tab === 'returns' ? 'returns' : '', 'f' => $filter, 'q' => $q,
                   'from' => $from !== '' ? self::jDate($from) : '', 'to' => $to !== '' ? self::jDate($to) : ''];
        $url    = fn(array $over): string => Biz::url($cfg['page']) . '?' . http_build_query(array_filter(array_merge($keep, $over), fn($v) => $v !== '' && $v !== null));
        ?>
<div class="st-page-head">
    <h1 class="st-h1"><?= h($cfg['title']) ?></h1>
    <div class="st-head-actions">
        <?php if ($side === 'sale'): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('quick-sale.php')) ?>">فروشِ سریع</a><?php endif; ?>
        <a class="st-btn" href="<?= h(Biz::url('invoice-edit.php?k=' . $cfg['doc'])) ?>">+ <?= h(BizInvoices::KINDS[$cfg['doc']]) ?></a>
    </div>
</div>

<nav class="st-segtabs" aria-label="نوعِ سند">
    <a class="<?= $tab === 'docs' ? 'is-active' : '' ?>" href="<?= h($url(['t' => '', 'f' => '', 'page' => ''])) ?>">فاکتورها</a>
    <a class="<?= $tab === 'returns' ? 'is-active' : '' ?>" href="<?= h($url(['t' => 'returns', 'f' => '', 'page' => ''])) ?>">برگشتی‌ها</a>
</nav>

<form class="st-filters" method="get" action="<?= h(Biz::url($cfg['page'])) ?>" role="search">
    <?php if ($tab === 'returns'): ?><input type="hidden" name="t" value="returns"><?php endif; ?>
    <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= h($filter) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="شماره، نامِ <?= h($cfg['party']) ?> یا IMEI…" aria-label="جست‌وجو">
    <input type="text" name="from" value="<?= h($keep['from']) ?>" placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" dir="ltr" class="st-date-in" aria-label="از تاریخ">
    <input type="text" name="to" value="<?= h($keep['to']) ?>" placeholder="تا تاریخ" dir="ltr" class="st-date-in" aria-label="تا تاریخ">
    <button type="submit" class="st-btn st-btn-ghost">بگرد</button>
</form>

<nav class="st-tabs" aria-label="وضعیت">
    <?php foreach (BizInvoices::FILTERS as $fk => $fl): ?>
        <a href="<?= h($url(['f' => $fk, 'page' => ''])) ?>" class="st-tab-chip<?= $fk === $filter ? ' is-active' : '' ?>"><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card">
        <?php if ($q !== '' || $filter !== '' || $from !== '' || $to !== ''): ?>
            <p class="st-empty">سندی با این مشخصات پیدا نشد.</p>
        <?php elseif ($tab === 'returns'): ?>
            <p class="st-empty">هنوز برگشتی ثبت نشده. برگشت از صفحه‌ی خودِ فاکتور زده می‌شود («برگشت»).</p>
        <?php else: ?>
            <p class="st-empty">هنوز <?= h(BizInvoices::KINDS[$cfg['doc']]) ?>ی ثبت نکرده‌اید.</p>
            <a class="st-btn" href="<?= h(Biz::url('invoice-edit.php?k=' . $cfg['doc'])) ?>">+ <?= h(BizInvoices::KINDS[$cfg['doc']]) ?></a>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="st-sumrow">
    <span><?= toPersianDigits((string)$list['total']) ?> سند</span>
    <span>جمعِ صادرشده <?= self::money($list['sum']) ?></span>
    <span>مانده‌ی تسویه‌نشده <?= self::money($list['due']) ?></span>
</div>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr>
            <th>شماره</th><th>تاریخ</th><th><?= h($cfg['party']) ?></th><th>وضعیت</th>
            <th class="st-th-num">جمع</th><th class="st-th-num st-hide-sm">مانده</th>
        </tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr class="<?= $r['status'] === 'void' ? 'is-inactive' : '' ?>">
                <td><a class="st-row-link" href="<?= h(Biz::url(($r['status'] === 'draft' ? 'invoice-edit.php' : 'invoice.php') . '?id=' . (int)$r['id'])) ?>"><?= $r['number'] !== null ? '<span class="st-num">' . toPersianDigits((string)$r['number']) . '</span>' : 'پیش‌نویس' ?></a></td>
                <td><span class="st-num"><?= h(toJalali((string)$r['inv_date'])) ?></span></td>
                <td><?= $r['party_name'] !== null ? h((string)$r['party_name']) : '<span class="st-muted-i">گذری</span>' ?></td>
                <td><?= self::pill($r) ?></td>
                <td class="st-td-num"><?= self::money((int)$r['total']) ?></td>
                <td class="st-td-num st-hide-sm"><?= $r['status'] === 'issued' && BizInvoices::remaining($r) > 0 ? self::money(BizInvoices::remaining($r)) : '<span class="st-muted-i">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= BizView::pager($cfg['page'], $keep, $list['page'], $list['pages'], $list['total']) ?>
<?php endif; ?>
        <?php
    }
}
