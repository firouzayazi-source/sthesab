<?php
/**
 * ویرایشگرِ فاکتورِ فروش و خرید — ساختِ تازه (`?k=sale|purchase`) یا ادامه‌ی
 * پیش‌نویس (`?id=`). سندِ صادرشده اینجا باز نمی‌شود: به صفحه‌ی خودش می‌رود.
 *
 * سه دکمه: «ذخیره‌ی پیش‌نویس» (هیچ اثری ندارد)، «صدور» و «صدور و چاپ»
 * (موجودی، مانده و دریافت/پرداختِ همزمان در یک تراکنش — `BizInvoices::issue`).
 *
 * ⛔ فرمِ سادهٔ HTML است و بی‌جاوااسکریپت کار می‌کند: «افزودنِ ردیف» یک
 *    دکمه‌ی فرم است که فقط دوباره رندر می‌کند، و جمع‌ها را همیشه سرور حساب
 *    می‌کند؛ `store.js` فقط جمعِ زنده و پر کردنِ قیمت را اضافه می‌کند.
 *
 * فروشگاهِ موبایل و لوازم جانبی:
 * - ⛔ سررسید ندارد (خواسته‌ی مالکِ نصب) — فیلدش رفت و `BizInvoices::state()`
 *   هم دیگر «سررسید گذشته» نمی‌سازد.
 * - «+» کنارِ خانه‌ی کالا پنلِ «کالای تازه» را باز می‌کند (نوع: کالا/گوشی/
 *   خدمت، قیمت، و برای گوشی IMEI ۱ و ۲). ثبتش از همان `BizProducts::save()`
 *   است و ردیف را پر می‌کند؛ پیش‌نویس ذخیره نمی‌شود و چیزی از فاکتورِ
 *   نیمه‌کاره گم نمی‌شود، چون پنل داخلِ همین فرم است.
 * - IMEI در خانه‌ی کالا (تایپ، اسکن یا انتخاب از فهرست) گوشی را پیدا می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = (int)Auth::userId();
$id     = (int)getParam('id', '0');
$inv    = $id > 0 ? BizInvoices::get($userId, $id) : null;
if ($id > 0 && !$inv) {
    redirectWithMessage(Biz::url('sales.php'), 'error', 'سند پیدا نشد.');
}
if ($inv && $inv['status'] !== 'draft') {
    header('Location: ' . Biz::url('invoice.php?id=' . $id));
    exit;
}
$kind = $inv ? (string)$inv['kind'] : (getParam('k') === 'purchase' ? 'purchase' : 'sale');
if (!isset(BizInvoices::RETURN_OF[$kind])) { $kind = 'sale'; }
$side = BizDocView::sideOf($kind);
Biz::$navActive = BizDocView::SIDES[$side]['page'];
$self = Biz::url('invoice-edit.php' . ($id > 0 ? '?id=' . $id : '?k=' . $kind));

$accounts = BizDocView::accounts($userId);
$form = [
    'party_id' => $inv ? (int)$inv['party_id'] : (int)getParam('party', '0'),
    'inv_date' => BizDocView::jDate($inv ? (string)$inv['inv_date'] : date('Y-m-d')),
    'discount' => $inv && (int)$inv['discount'] > 0 ? (string)(int)$inv['discount'] : '',
    'extra'    => $inv && (int)$inv['extra'] > 0 ? (string)(int)$inv['extra'] : '',
    'note'     => (string)($inv['note'] ?? ''),
    'lines'    => $inv ? $inv['lines'] : [],
    'pay_mode' => 'none', 'pay_amount' => '', 'account_id' => (int)($accounts[0]['id'] ?? 0), 'method' => 'cash',
];
$blank = $inv ? 2 : 4;
$error = '';
$notice = '';
// پنلِ «کالای تازه» — `row` اندیسِ ردیف در فهرستِ پُرشده، یا -1 = ردیفِ تازه
$np = ['open' => false, 'row' => -1, 'type' => 'goods', 'name' => '', 'sku' => '', 'buy' => '', 'sell' => '', 'imei1' => '', 'imei2' => '', 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    if ($inv && $action === 'delete') {
        $res = BizInvoices::deleteDraft($userId, $id);
        redirectWithMessage(Biz::url(BizDocView::SIDES[$side]['page']), $res['ok'] ? 'success' : 'error', $res['message']);
    }
    $in = [
        'party_id' => (int)postParam('party_id'),
        'inv_date' => BizDocView::gDate(postParam('inv_date')),
        'discount' => postParam('discount'), 'extra' => postParam('extra'), 'note' => postParam('note'),
        'lines'    => is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [],
    ];
    $rawLines = array_values(array_filter($in['lines'], 'is_array'));
    $filled   = fn($l) => trim((string)($l['item'] ?? '')) !== '' || trim((string)($l['price'] ?? '')) !== ''
                          || trim((string)($l['imei1'] ?? '')) !== '';
    // اندیسِ ردیفِ جدولِ فرستاده‌شده → اندیس در فهرستِ پُرشده (ردیفِ خالی = ردیفِ تازه)
    $rowAt = function (int $i) use ($rawLines, $filled): int {
        if (!isset($rawLines[$i]) || !$filled($rawLines[$i])) { return -1; }
        $n = 0;
        for ($k = 0; $k < $i; $k++) { if ($filled($rawLines[$k])) { $n++; } }
        return $n;
    };
    $form = array_merge($form, [
        'party_id' => $in['party_id'], 'inv_date' => postParam('inv_date'),
        'discount' => $in['discount'], 'extra' => $in['extra'], 'note' => $in['note'], 'lines' => array_values($in['lines']),
        'pay_mode' => in_array(postParam('pay_mode'), ['none', 'full', 'part'], true) ? postParam('pay_mode') : 'none',
        'pay_amount' => postParam('pay_amount'), 'account_id' => (int)postParam('account_id'),
        'method' => isset(BizPay::METHODS[postParam('method')]) ? postParam('method') : 'cash',
    ]);
    // ردیف‌های خالیِ فرم دوباره نشان داده نمی‌شوند؛ «افزودنِ ردیف» پنج تای تازه می‌گذارد
    $form['lines'] = array_values(array_filter($rawLines, $filled));

    if (isset($_POST['np_open'])) {
        // «+» بی‌جاوااسکریپت: همان صفحه با پنلِ باز. متنِ ردیف نامِ پیشنهادی
        // است؛ اگر خودش IMEI بود، گوشی با همان IMEI.
        $ri    = (int)postParam('np_open');
        $typed = trim((string)($rawLines[$ri]['item'] ?? ''));
        $asImei = BizSerial::valid(BizSerial::norm($typed));
        $np = array_merge($np, ['open' => true, 'row' => $rowAt($ri), 'type' => $asImei ? 'phone' : 'goods',
                                'name' => $asImei ? '' : $typed, 'imei1' => $asImei ? BizSerial::norm($typed) : '']);
    } elseif ($action === 'np_save') {
        $raw = is_array($_POST['np'] ?? null) ? $_POST['np'] : [];
        $np  = array_merge($np, ['open' => true, 'row' => $rowAt((int)($raw['row'] ?? -1)),
            'type' => isset(BizProducts::TYPES[$raw['type'] ?? '']) ? (string)$raw['type'] : 'goods',
            'name' => (string)($raw['name'] ?? ''), 'sku' => (string)($raw['sku'] ?? ''),
            'buy' => (string)($raw['buy'] ?? ''), 'sell' => (string)($raw['sell'] ?? ''),
            'imei1' => BizSerial::norm((string)($raw['imei1'] ?? '')), 'imei2' => BizSerial::norm((string)($raw['imei2'] ?? ''))]);
        if ($np['type'] !== 'phone') { $np['imei1'] = $np['imei2'] = ''; }
        // IMEI پیش از ساختِ کالا سنجیده می‌شود، وگرنه کالا ساخته می‌شد و ردیف نه
        foreach (['imei1', 'imei2'] as $c) {
            if ($np[$c] !== '' && !BizSerial::valid($np[$c])) { $np['error'] = 'IMEI «' . $np[$c] . '» معتبر نیست (۱۴ تا ۱۷ رقم).'; }
        }
        if ($np['error'] === '') {
            $res = BizProducts::save($userId, [
                'type' => $np['type'], 'name' => $np['name'], 'sku' => $np['sku'],
                'unit' => $np['type'] === 'phone' ? 'دستگاه' : 'عدد',
                'buy_price' => $np['buy'], 'sell_price' => $np['sell'],
            ]);
            if (!$res['ok']) {
                $np['error'] = $res['message'];
            } else {
                $prod = BizProducts::get($userId, (int)$res['id']);
                $line = ['item' => (string)$prod['name'], 'product_id' => (int)$prod['id'], 'qty' => '1',
                         'price' => (string)(int)($kind === 'sale' ? $prod['sell_price'] : $prod['buy_price']),
                         'imei1' => $np['imei1'], 'imei2' => $np['imei2'], 'serial' => $np['type'] === 'phone'];
                if ($np['row'] >= 0 && isset($form['lines'][$np['row']])) {
                    $form['lines'][$np['row']] = $line;
                    $at = $np['row'];
                } else {
                    $form['lines'][] = $line;
                    $at = count($form['lines']) - 1;
                }
                $notice = '«' . $prod['name'] . '» به فهرستِ کالا اضافه شد و در ردیفِ ' . toPersianDigits((string)($at + 1)) . ' نشست.';
                $np = ['open' => false, 'row' => -1, 'type' => 'goods', 'name' => '', 'sku' => '', 'buy' => '', 'sell' => '', 'imei1' => '', 'imei2' => '', 'error' => ''];
            }
        }
    } elseif ($action === 'np_cancel') {
        // فقط بستنِ پنل — فرم همان‌طور که بود دوباره نشان داده می‌شود
    } elseif ($action === 'addrows') {
        $blank = 6;
    } else {
        $res = BizInvoices::saveDraft($userId, $kind, $in, $id);
        if (!$res['ok']) {
            $error = $res['message'];
        } else {
            $id = (int)$res['id'];
            if ($action === 'save') {
                redirectWithMessage(Biz::url('invoice-edit.php?id=' . $id), 'success', $res['message']);
            }
            $r = BizInvoices::issue($userId, $id, [
                'account_id' => $form['account_id'], 'method' => $form['method'],
                'full'       => $form['pay_mode'] === 'full',
                'amount'     => $form['pay_mode'] === 'part' ? $form['pay_amount'] : '0',
            ]);
            if ($r['ok']) {
                if ($action === 'issue_print') {
                    // برگه‌ی چاپ پیام نشان نمی‌دهد؛ پیامِ «صادر شد» نباید روی صفحه‌ی بعدی بماند
                    header('Location: ' . Biz::url('print.php?doc=invoice&id=' . $id));
                    exit;
                }
                redirectWithMessage(Biz::url('invoice.php?id=' . $id), 'success', $r['message']);
            }
            // ⚠ پیش‌نویس ذخیره شده؛ فقط صدور انجام نشد — کاربر روی همان پیش‌نویس می‌ماند
            redirectWithMessage(Biz::url('invoice-edit.php?id=' . $id), 'error', $r['message'] . ' (پیش‌نویس ذخیره شد.)');
        }
    }
}

// جمع‌ها برای نمایش — همیشه از سرور (همان توابعِ ذخیره)
$parsed = BizInvoices::parseLines($userId, array_map(fn($l) => [
    'item' => $l['item'] ?? $l['description'] ?? '', 'product_id' => $l['product_id'] ?? '',
    'qty' => isset($l['qty']) ? (string)$l['qty'] : '', 'price' => $l['price'] ?? (isset($l['unit_price']) ? (string)$l['unit_price'] : ''),
    'disc' => $l['disc'] ?? (isset($l['line_discount']) ? (string)$l['line_discount'] : ''),
    'imei1' => (string)($l['imei1'] ?? ''), 'imei2' => (string)($l['imei2'] ?? ''), 'note' => (string)($l['note'] ?? ''),
], $form['lines']));
$form['lines'] = BizDocView::mergeMeta($form['lines'], $parsed['meta']);
$tot = BizInvoices::totals($parsed['lines'], sanitizeAmount($form['discount']), sanitizeAmount($form['extra']));
// جمعِ هر ردیف کنارِ خودش (فرمِ ردشده جمع ندارد)
foreach ($form['lines'] as $k => $l) {
    if (!isset($l['line_total']) && isset($parsed['lines'][$k]) && count($parsed['lines']) === count($form['lines'])) {
        $form['lines'][$k]['line_total'] = $parsed['lines'][$k]['line_total'];
    }
}
$parties  = BizDocView::parties($userId);
$partyLbl = BizDocView::SIDES[$side]['party'];
$isSale   = $kind === 'sale';

// ⚠ هشدارِ موجودیِ پیش‌نویس — فقط نمایش؛ سدِ واقعی هنگامِ صدور در BizStock است
$stockWarn = [];
if ($isSale && $inv) {
    foreach ($inv['lines'] as $l) {
        if ($l['product_id'] !== null && (int)$l['track_stock'] === 1 && (float)$l['qty'] > (float)$l['stock_qty'] + 0.0005) {
            $stockWarn[] = '«' . $l['description'] . '»: موجودی ' . formatQty($l['stock_qty']) . ' ' . $l['unit'];
        }
    }
}

$pageTitle = $inv ? BizInvoices::title($inv) : BizInvoices::KINDS[$kind] . 'ِ تازه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url(BizDocView::SIDES[$side]['page'])) ?>">‹ <?= h(BizDocView::SIDES[$side]['title']) ?></a>
        <h1 class="st-h1"><?= h($inv ? BizInvoices::title($inv) : BizInvoices::KINDS[$kind] . 'ِ تازه') ?></h1>
    </div>
    <?php if ($inv): ?><span class="st-pill is-draft">پیش‌نویس — هنوز اثری ندارد</span><?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="st-flash st-flash-ok" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($stockWarn): ?>
<div class="st-flash st-flash-warn" role="status">بیش از موجودی — صدور انجام نمی‌شود تا موجودی برسد: <?= h(implode('، ', $stockWarn)) ?></div>
<?php endif; ?>

<form method="post" class="st-docform" action="<?= h($self) ?>" data-invoice data-price="<?= $isSale ? 'sell' : 'buy' ?>">
    <?= Csrf::field() ?>
    <section class="st-card st-doc-head">
        <div class="st-row2">
            <label class="st-field">
                <span><?= h($partyLbl) ?></span>
                <select name="party_id" data-party>
                    <option value="0"><?= $isSale ? 'مشتریِ گذری (نقدی)' : 'فروشنده‌ی گذری (نقدی)' ?></option>
                    <?php foreach ($parties as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-balance="<?= (int)$p['balance'] ?>"<?= (int)$p['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="st-field-link" href="<?= h(Biz::url('party.php?kind=' . ($isSale ? 'customer' : 'supplier'))) ?>">+ <?= h($partyLbl) ?>ِ تازه</a>
            </label>
            <label class="st-field">
                <span>تاریخ</span>
                <input type="text" name="inv_date" value="<?= h((string)$form['inv_date']) ?>" dir="ltr" placeholder="۱۴۰۵/۰۷/۰۵" inputmode="numeric">
            </label>
        </div>
    </section>

    <section class="st-card st-lines-card">
        <div class="st-table-wrap st-lines-wrap">
            <table class="st-table st-lines">
                <?= BizDocView::lineHead() ?>
                <tbody data-lines><?= BizDocView::lineRows($form['lines'], $blank, true) ?></tbody>
            </table>
        </div>
        <div class="st-lines-tools">
            <button type="submit" name="action" value="addrows" class="st-link-btn" formnovalidate data-add-rows>+ ردیفِ بیشتر</button>
            <span class="st-muted-i">چند حرف از نام، کد یا IMEI را بنویسید و از فهرست انتخاب کنید؛ کالای تازه با «+». گوشی: هر ردیف یک دستگاه با IMEIِ خودش. چیزی که در فهرستِ کالا نیست «شرحِ آزاد» می‌شود و به موجودی دست نمی‌زند.</span>
        </div>
    </section>

    <section class="st-card st-np" id="np" data-np<?= $np['open'] ? '' : ' hidden' ?> aria-labelledby="npTitle">
        <div class="st-np-head">
            <h2 class="st-h3" id="npTitle">تعریفِ کالای تازه</h2>
            <button type="submit" name="action" value="np_cancel" class="st-link-btn" formnovalidate data-np-close>بستن</button>
        </div>
        <?php if ($np['error'] !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($np['error']) ?></div><?php endif; ?>
        <input type="hidden" name="np[row]" value="<?= (int)$np['row'] ?>" data-np-row>
        <div class="st-seg st-seg-sm st-np-types" role="radiogroup" aria-label="نوعِ کالا">
            <?php foreach (BizProducts::TYPES as $tk => $tl): ?>
            <label class="st-seg-opt"><input type="radio" name="np[type]" value="<?= h($tk) ?>"<?= $np['type'] === $tk ? ' checked' : '' ?> data-np-type><span><?= h($tl) ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>نام <small class="st-muted">(مثلاً «آیفون ۱۵ — ۱۲۸ گیگ»)</small></span><input type="text" name="np[name]" value="<?= h($np['name']) ?>" maxlength="<?= BizProducts::LIMITS['name'] ?>" data-np-name></label>
            <label class="st-field"><span>کد یا بارکد <small class="st-muted">(اختیاری)</small></span><input type="text" name="np[sku]" value="<?= h($np['sku']) ?>" dir="ltr" maxlength="<?= BizProducts::LIMITS['sku'] ?>"></label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>قیمتِ خرید</span><input type="text" name="np[buy]" value="<?= h($np['buy']) ?>" inputmode="numeric" dir="ltr"></label>
            <label class="st-field"><span>قیمتِ فروش</span><input type="text" name="np[sell]" value="<?= h($np['sell']) ?>" inputmode="numeric" dir="ltr"></label>
        </div>
        <div class="st-row2" data-np-imei>
            <label class="st-field"><span>IMEI ۱ <small class="st-muted">(فقط گوشی)</small></span><input type="text" name="np[imei1]" value="<?= h($np['imei1']) ?>" inputmode="numeric" dir="ltr" autocomplete="off"></label>
            <label class="st-field"><span>IMEI ۲ <small class="st-muted">(گوشیِ دوسیم‌کارت)</small></span><input type="text" name="np[imei2]" value="<?= h($np['imei2']) ?>" inputmode="numeric" dir="ltr" autocomplete="off"></label>
        </div>
        <p class="st-muted">کالا در فهرستِ کالاها ثبت می‌شود و همین‌جا در ردیفِ فاکتور می‌نشیند (قیمتِ <?= $isSale ? 'فروش' : 'خرید' ?>، تعدادِ ۱). موجودی فقط با صدورِ فاکتور عوض می‌شود.</p>
        <button type="submit" name="action" value="np_save" class="st-btn" formnovalidate>ثبتِ کالا و افزودن به فاکتور</button>
    </section>

    <div class="st-doc-foot">
        <section class="st-card st-sumbox">
            <div class="st-sum-line"><span>جمعِ ردیف‌ها</span><b class="st-num" data-subtotal><?= formatMoney($tot['subtotal']) ?></b></div>
            <label class="st-sum-line"><span>تخفیفِ فاکتور</span><input type="text" name="discount" value="<?= h((string)$form['discount']) ?>" inputmode="numeric" dir="ltr" data-discount></label>
            <label class="st-sum-line"><span>حمل و هزینه‌ی دیگر</span><input type="text" name="extra" value="<?= h((string)$form['extra']) ?>" inputmode="numeric" dir="ltr" data-extra></label>
            <div class="st-sum-line st-sum-total"><span>مبلغِ فاکتور</span><b class="st-num" data-total><?= formatMoney($tot['total']) ?></b></div>
            <?php
            // ⛔ مانده‌ی قبلی = مانده‌ی امروزِ طرف‌حساب (پیش‌نویس هنوز اثری ندارد) —
            //    همان `BALANCE_SQL` که `parties()` خوانده، پس کوئریِ تازه‌ای نیست.
            //    «پس از این فاکتور» فقط پیش‌نمایش است؛ عددِ واقعی را صدور می‌سازد.
            $prevBal = 0;
            foreach ($parties as $p) { if ((int)$p['id'] === (int)$form['party_id']) { $prevBal = (int)$p['balance']; } }
            $payNow  = $form['pay_mode'] === 'full' ? $tot['total'] : ($form['pay_mode'] === 'part' ? min(sanitizeAmount($form['pay_amount']), $tot['total']) : 0);
            $afterBal = $prevBal + ($isSale ? 1 : -1) * ($tot['total'] - $payNow);
            ?>
            <div class="st-balbox" data-balbox data-sign="<?= $isSale ? 1 : -1 ?>"<?= (int)$form['party_id'] > 0 ? '' : ' hidden' ?>>
                <div class="st-sum-line is-prev"><span>مانده‌ی قبلی <small data-bal-side><?= h(BizParties::sideLabel($prevBal)) ?></small></span><b class="st-num" data-bal-prev><?= formatMoney(abs($prevBal)) ?></b></div>
                <div class="st-sum-line is-after"><span>مانده‌ی کل پس از این فاکتور <small data-after-side><?= h(BizParties::sideLabel($afterBal)) ?></small></span><b class="st-num" data-bal-after><?= formatMoney(abs($afterBal)) ?></b></div>
                <a class="st-field-link" data-bal-link href="<?= h(Biz::url('party.php' . ((int)$form['party_id'] > 0 ? '?id=' . (int)$form['party_id'] : ''))) ?>">صورت‌حساب و اصلاحِ مانده‌ی اول دوره ›</a>
            </div>
            <p class="st-muted st-unit-note">مبلغ‌ها به تومان.</p>
        </section>
        <section class="st-card st-paybox">
            <h2 class="st-h3"><?= $isSale ? 'دریافت همزمان' : 'پرداخت همزمان' ?></h2>
            <div class="st-seg st-seg-sm">
                <?php foreach (['none' => $isSale ? 'نسیه' : 'نسیه', 'full' => 'کامل', 'part' => 'بخشی'] as $pm => $pl): ?>
                <label class="st-seg-opt"><input type="radio" name="pay_mode" value="<?= $pm ?>"<?= $form['pay_mode'] === $pm ? ' checked' : '' ?> data-paymode><span><?= h($pl) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="st-row2">
                <label class="st-field"><span>مبلغ (برای «بخشی»)</span><input type="text" name="pay_amount" value="<?= h((string)$form['pay_amount']) ?>" inputmode="numeric" dir="ltr" data-payamount></label>
                <label class="st-field"><span>روش</span>
                    <select name="method"><?php foreach (BizPay::METHODS as $mk => $ml): ?><option value="<?= h($mk) ?>"<?= $mk === $form['method'] ? ' selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?></select>
                </label>
            </div>
            <label class="st-field"><span>صندوق</span>
                <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$form['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select>
            </label>
            <p class="st-muted">بی‌<?= h($partyLbl) ?> (گذری) فقط با تسویه‌ی کامل صادر می‌شود.</p>
        </section>
    </div>

    <section class="st-card">
        <label class="st-field"><span>توضیح <small class="st-muted">(اختیاری — روی فاکتور چاپ می‌شود)</small></span>
            <textarea name="note" rows="2" maxlength="<?= BizInvoices::NOTE_MAX ?>"><?= h((string)$form['note']) ?></textarea>
        </label>
    </section>

    <div class="st-actionbar">
        <button type="submit" name="action" value="issue_print" class="st-btn">صدور و چاپ</button>
        <button type="submit" name="action" value="issue" class="st-btn st-btn-ghost">صدور</button>
        <button type="submit" name="action" value="save" class="st-btn st-btn-ghost" formnovalidate>ذخیره‌ی پیش‌نویس</button>
    </div>
</form>

<?php if ($inv): ?>
<form method="post" action="<?= h($self) ?>" class="st-danger-row st-after" onsubmit="return confirm('این پیش‌نویس حذف شود؟');">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="st-link-btn st-link-danger">حذفِ پیش‌نویس</button>
</form>
<?php endif; ?>
<?= BizDocView::productDatalist($userId, 'bizProducts', 2000, $isSale) ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
