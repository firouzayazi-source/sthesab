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
    'due_date' => BizDocView::jDate($inv['due_date'] ?? null),
    'discount' => $inv && (int)$inv['discount'] > 0 ? (string)(int)$inv['discount'] : '',
    'extra'    => $inv && (int)$inv['extra'] > 0 ? (string)(int)$inv['extra'] : '',
    'note'     => (string)($inv['note'] ?? ''),
    'lines'    => $inv ? $inv['lines'] : [],
    'pay_mode' => 'none', 'pay_amount' => '', 'account_id' => (int)($accounts[0]['id'] ?? 0), 'method' => 'cash',
];
$blank = $inv ? 2 : 4;
$error = '';

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
        'due_date' => BizDocView::gDate(postParam('due_date')),
        'discount' => postParam('discount'), 'extra' => postParam('extra'), 'note' => postParam('note'),
        'lines'    => is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [],
    ];
    $form = array_merge($form, [
        'party_id' => $in['party_id'], 'inv_date' => postParam('inv_date'), 'due_date' => postParam('due_date'),
        'discount' => $in['discount'], 'extra' => $in['extra'], 'note' => $in['note'], 'lines' => array_values($in['lines']),
        'pay_mode' => in_array(postParam('pay_mode'), ['none', 'full', 'part'], true) ? postParam('pay_mode') : 'none',
        'pay_amount' => postParam('pay_amount'), 'account_id' => (int)postParam('account_id'),
        'method' => isset(BizPay::METHODS[postParam('method')]) ? postParam('method') : 'cash',
    ]);
    // ردیف‌های خالیِ فرم دوباره نشان داده نمی‌شوند؛ «افزودنِ ردیف» پنج تای تازه می‌گذارد
    $form['lines'] = array_values(array_filter($form['lines'], fn($l) => is_array($l)
        && (trim((string)($l['item'] ?? '')) !== '' || trim((string)($l['price'] ?? '')) !== '')));

    if ($action === 'addrows') {
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
], $form['lines']));
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
<?php if ($stockWarn): ?>
<div class="st-flash st-flash-warn" role="status">بیش از موجودی — صدور انجام نمی‌شود تا موجودی برسد: <?= h(implode('، ', $stockWarn)) ?></div>
<?php endif; ?>

<form method="post" class="st-docform" action="<?= h($self) ?>" data-invoice data-price="<?= $isSale ? 'sell' : 'buy' ?>">
    <?= Csrf::field() ?>
    <section class="st-card st-doc-head">
        <div class="st-row3">
            <label class="st-field">
                <span><?= h($partyLbl) ?></span>
                <select name="party_id" data-party>
                    <option value="0"><?= $isSale ? 'مشتریِ گذری (نقدی)' : 'فروشنده‌ی گذری (نقدی)' ?></option>
                    <?php foreach ($parties as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="st-field-link" href="<?= h(Biz::url('party.php?kind=' . ($isSale ? 'customer' : 'supplier'))) ?>">+ <?= h($partyLbl) ?>ِ تازه</a>
            </label>
            <label class="st-field">
                <span>تاریخ</span>
                <input type="text" name="inv_date" value="<?= h((string)$form['inv_date']) ?>" dir="ltr" placeholder="۱۴۰۵/۰۷/۰۵" inputmode="numeric">
            </label>
            <label class="st-field">
                <span>سررسید <small class="st-muted">(اختیاری)</small></span>
                <input type="text" name="due_date" value="<?= h((string)$form['due_date']) ?>" dir="ltr" placeholder="—" inputmode="numeric">
            </label>
        </div>
    </section>

    <section class="st-card st-lines-card">
        <div class="st-table-wrap st-lines-wrap">
            <table class="st-table st-lines">
                <thead><tr>
                    <th class="st-line-no">#</th><th>کالا یا شرح</th><th class="st-th-num">مقدار</th>
                    <th class="st-th-num">بهای واحد</th><th class="st-th-num st-hide-sm">تخفیف</th><th class="st-th-num">جمع</th>
                </tr></thead>
                <tbody data-lines><?= BizDocView::lineRows($form['lines'], $blank) ?></tbody>
            </table>
        </div>
        <div class="st-lines-tools">
            <button type="submit" name="action" value="addrows" class="st-link-btn" formnovalidate data-add-rows>+ ردیفِ بیشتر</button>
            <span class="st-muted-i">کالا را با نام، کد یا بارکد بنویسید؛ چیزی که در فهرستِ کالا نیست «شرحِ آزاد» می‌شود و به موجودی دست نمی‌زند.</span>
        </div>
    </section>

    <div class="st-doc-foot">
        <section class="st-card st-sumbox">
            <div class="st-sum-line"><span>جمعِ ردیف‌ها</span><b class="st-num" data-subtotal><?= formatMoney($tot['subtotal']) ?></b></div>
            <label class="st-sum-line"><span>تخفیفِ فاکتور</span><input type="text" name="discount" value="<?= h((string)$form['discount']) ?>" inputmode="numeric" dir="ltr" data-discount></label>
            <label class="st-sum-line"><span>حمل و هزینه‌ی دیگر</span><input type="text" name="extra" value="<?= h((string)$form['extra']) ?>" inputmode="numeric" dir="ltr" data-extra></label>
            <div class="st-sum-line st-sum-total"><span>مبلغِ فاکتور</span><b class="st-num" data-total><?= formatMoney($tot['total']) ?></b></div>
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
                <label class="st-field"><span>مبلغ (برای «بخشی»)</span><input type="text" name="pay_amount" value="<?= h((string)$form['pay_amount']) ?>" inputmode="numeric" dir="ltr"></label>
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
<?= BizDocView::productDatalist($userId) ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
