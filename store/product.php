<?php
/**
 * یک کالا — تعریف و ویرایش، و برای کالای انبارشدنی: موجودی، موجودیِ اول
 * دوره، انبارگردانی و تاریخچه‌ی حرکت‌ها.
 *
 * همه‌ی نوشتن‌ها فرمِ POST با CSRF و بعد ریدایرکت‌اند (الگوی
 * `admin/errors.php`): بدونِ جاوااسکریپت کار می‌کنند و تازه‌سازیِ صفحه
 * چیزی را دوباره نمی‌فرستد. منطق همه در `includes/biz_catalog.php` است؛
 * این صفحه فقط ورودی را تحویل می‌دهد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId  = (int)Auth::userId();
$id      = (int)getParam('id', '0');
$product = $id > 0 ? BizProducts::get($userId, $id) : null;
if ($id > 0 && !$product) {
    redirectWithMessage(Biz::url('products.php'), 'error', 'کالا پیدا نشد.');
}
$self  = Biz::url('product.php' . ($id > 0 ? '?id=' . $id : ''));
$error = '';
$form  = $product ?? [
    'name' => '', 'sku' => '', 'category' => '', 'unit' => 'عدد', 'buy_price' => '', 'sell_price' => '',
    'min_stock' => '', 'track_stock' => 1, 'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'save') {
        $res = BizProducts::save($userId, $_POST, $id);
        if ($res['ok']) {
            redirectWithMessage(Biz::url('product.php?id=' . (int)$res['id']), 'success', $res['message']);
        }
        $error = $res['message'];
        $form  = array_merge($form, array_intersect_key($_POST, $form));
        $form['track_stock'] = empty($_POST['is_service']) ? 1 : 0;
    } elseif ($product && $action === 'opening') {
        $cost = trim(postParam('opening_cost')) === '' ? (int)$product['buy_price'] : sanitizeAmount(postParam('opening_cost'));
        $res  = BizStock::setOpening($userId, $id, sanitizeQty(postParam('opening_qty')), $cost);
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($product && $action === 'adjust') {
        if (trim(postParam('actual_qty')) === '') {
            redirectWithMessage($self, 'error', 'موجودیِ شمارش‌شده را بنویسید.');
        }
        $res = BizStock::adjustTo($userId, $id, sanitizeQty(postParam('actual_qty')), postParam('note'));
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($product && $action === 'delete_move') {
        $res = BizStock::deleteMove($userId, (int)postParam('move_id'));
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['ok'] ? 'انبارگردانی حذف شد.' : $res['message']);
    } elseif ($product && $action === 'toggle') {
        $on = (int)$product['is_active'] !== 1;
        BizProducts::setActive($userId, $id, $on);
        redirectWithMessage($self, 'success', $on ? 'کالا فعال شد.' : 'کالا غیرفعال شد؛ در فهرستِ «غیرفعال» می‌ماند.');
    } elseif ($product && $action === 'delete') {
        $res = BizProducts::delete($userId, $id);
        redirectWithMessage($res['ok'] ? Biz::url('products.php') : $self, $res['ok'] ? 'success' : 'error', $res['message']);
    } else {
        redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
    }
}

$cats    = BizProducts::categories($userId);
$tracked = $product && (int)$product['track_stock'] === 1;
$moves   = $tracked ? BizStock::moves($userId, $id) : [];
$opening = null;
foreach ($moves as $m) { if ($m['kind'] === 'opening') { $opening = $m; } }
$unit    = $product ? (string)$product['unit'] : 'عدد';
// مبلغِ ذخیره‌شده با جداکننده نشان داده می‌شود؛ `sanitizeAmount()` هنگامِ
// ذخیره همان را پاک می‌کند. ورودیِ خامِ فرمِ ردشده دست‌نخورده برمی‌گردد.
$numVal  = fn($v): string => ($v === '' || $v === null) ? '' : (is_numeric($v) ? number_format((float)$v) : (string)$v);

$pageTitle = $product ? (string)$product['name'] : 'کالای تازه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('products.php')) ?>">‹ کالاها</a>
        <h1 class="st-h1"><?= h($product ? (string)$product['name'] : 'کالای تازه') ?></h1>
    </div>
    <div class="st-head-actions">
        <?php if ($product && (int)$product['is_active'] !== 1): ?><span class="st-badge is-out">غیرفعال</span><?php endif; ?>
        <?php if ($product && (int)$product['track_stock'] === 1): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=kardex&id=' . (int)$product['id'])) ?>">چاپِ کاردکس</a><?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?>
<div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($tracked): ?>
<div class="st-kpis st-kpis-3">
    <div class="st-kpi-card">
        <span class="st-kpi-label">موجودی</span>
        <span class="st-kpi-value st-num"><?= h(BizView::qty($product['stock_qty'], $unit)) ?></span>
        <?php $st = BizProducts::stockState($product); if ($st === 'low' || $st === 'out'): ?>
            <span class="st-badge is-<?= h($st) ?>"><?= $st === 'out' ? 'ناموجود' : 'کمتر از حداقل' ?></span>
        <?php endif; ?>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">میانگینِ بهای خرید</span>
        <span class="st-kpi-value st-num"><?= formatMoney((int)round((float)$product['avg_cost'])) ?></span>
        <span class="st-kpi-sub">تومان برای هر <?= h($unit) ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">ارزشِ موجودی</span>
        <span class="st-kpi-value st-num"><?= formatMoney((int)round(max(0, (float)$product['stock_qty']) * (float)$product['avg_cost'])) ?></span>
        <span class="st-kpi-sub">تومان</span>
    </div>
</div>
<?php endif; ?>

<div class="st-cols">
<form method="post" class="st-card st-form" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <h2 class="st-h2"><?= $product ? 'مشخصاتِ کالا' : 'تعریفِ کالا' ?></h2>

    <label class="st-field">
        <span>نامِ کالا</span>
        <input type="text" name="name" required maxlength="<?= BizProducts::LIMITS['name'] ?>" value="<?= h((string)$form['name']) ?>" <?= $product ? '' : 'autofocus' ?>>
    </label>
    <div class="st-row2">
        <label class="st-field">
            <span>کد یا بارکد <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="sku" dir="ltr" maxlength="<?= BizProducts::LIMITS['sku'] ?>" value="<?= h((string)$form['sku']) ?>">
        </label>
        <label class="st-field">
            <span>دسته <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="category" list="bizCats" maxlength="<?= BizProducts::LIMITS['category'] ?>" value="<?= h((string)$form['category']) ?>" placeholder="مثلاً گوشی، لوازم جانبی">
        </label>
    </div>
    <datalist id="bizCats"><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

    <div class="st-row2">
        <label class="st-field">
            <span>قیمتِ خرید (تومان)</span>
            <input type="text" name="buy_price" inputmode="numeric" dir="ltr" value="<?= h($numVal($form['buy_price'])) ?>">
        </label>
        <label class="st-field">
            <span>قیمتِ فروش (تومان)</span>
            <input type="text" name="sell_price" inputmode="numeric" dir="ltr" value="<?= h($numVal($form['sell_price'])) ?>">
        </label>
    </div>
    <div class="st-row2">
        <label class="st-field">
            <span>واحد</span>
            <select name="unit">
                <?php foreach (array_keys(BizProducts::UNITS) as $u): ?>
                    <option value="<?= h($u) ?>"<?= $u === (string)$form['unit'] ? ' selected' : '' ?>><?= h($u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="st-field">
            <span>هشدارِ کم‌موجودی از <small class="st-muted">(۰ = خاموش)</small></span>
            <input type="text" name="min_stock" inputmode="decimal" dir="ltr" value="<?= h((float)($form['min_stock'] ?: 0) > 0 ? rtrim(rtrim((string)$form['min_stock'], '0'), '.') : '') ?>">
        </label>
    </div>

    <?php if (!$product): ?>
    <fieldset class="st-fieldset">
        <legend>موجودیِ فعلیِ انبار <small class="st-muted">(اختیاری)</small></legend>
        <div class="st-row2">
            <label class="st-field">
                <span>تعداد یا مقدار</span>
                <input type="text" name="opening_qty" inputmode="decimal" dir="ltr" value="<?= h((string)($_POST['opening_qty'] ?? '')) ?>">
            </label>
            <label class="st-field">
                <span>بهای خریدِ هر واحد <small class="st-muted">(خالی = قیمتِ خرید)</small></span>
                <input type="text" name="opening_cost" inputmode="numeric" dir="ltr" value="<?= h((string)($_POST['opening_cost'] ?? '')) ?>">
            </label>
        </div>
    </fieldset>
    <?php endif; ?>

    <label class="st-check">
        <input type="checkbox" name="is_service" value="1"<?= (int)$form['track_stock'] === 1 ? '' : ' checked' ?>>
        <span>خدمت است (مثل نصب یا تعمیر) — موجودی ندارد</span>
    </label>
    <label class="st-field">
        <span>یادداشت <small class="st-muted">(اختیاری)</small></span>
        <textarea name="note" rows="2" maxlength="<?= BizProducts::LIMITS['note'] ?>"><?= h((string)$form['note']) ?></textarea>
    </label>
    <button type="submit" class="st-btn"><?= $product ? 'ذخیره‌ی تغییرات' : 'ثبتِ کالا' ?></button>
</form>

<?php if ($tracked): ?>
<div>
    <form method="post" class="st-card st-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="adjust">
        <h2 class="st-h2">انبارگردانی</h2>
        <p class="st-muted">موجودیِ واقعیِ شمارش‌شده را بنویسید؛ تفاوتش با موجودیِ فعلی ثبت می‌شود.</p>
        <div class="st-row2">
            <label class="st-field">
                <span>موجودیِ واقعی (<?= h($unit) ?>)</span>
                <input type="text" name="actual_qty" inputmode="decimal" dir="ltr" required>
            </label>
            <label class="st-field">
                <span>توضیح <small class="st-muted">(اختیاری)</small></span>
                <input type="text" name="note" maxlength="200" placeholder="مثلاً شکستگی، شمارشِ پایانِ ماه">
            </label>
        </div>
        <button type="submit" class="st-btn st-btn-ghost">ثبتِ انبارگردانی</button>
    </form>

    <form method="post" class="st-card st-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="opening">
        <h2 class="st-h2">موجودیِ اول دوره</h2>
        <p class="st-muted">موجودیِ انبار پیش از شروعِ کار با این پنل. عوض کردنش همه‌ی عددها را از نو حساب می‌کند.</p>
        <div class="st-row2">
            <label class="st-field">
                <span>مقدار (<?= h($unit) ?>)</span>
                <input type="text" name="opening_qty" inputmode="decimal" dir="ltr" value="<?= $opening ? h(formatQty($opening['qty'])) : '' ?>">
            </label>
            <label class="st-field">
                <span>بهای خریدِ هر واحد</span>
                <input type="text" name="opening_cost" inputmode="numeric" dir="ltr" value="<?= $opening ? h((string)(int)$opening['unit_cost']) : '' ?>">
            </label>
        </div>
        <button type="submit" class="st-btn st-btn-ghost">ذخیره‌ی موجودیِ اول دوره</button>
    </form>
</div>
<?php endif; ?>
</div>

<?php if ($tracked): ?>
<section class="st-card">
    <h2 class="st-h2">حرکت‌های انبار</h2>
    <?php if (!$moves): ?>
        <p class="st-empty">هنوز هیچ حرکتی ثبت نشده است.</p>
    <?php else: ?>
    <ul class="st-list">
        <?php foreach ($moves as $m): $mq = (float)$m['qty']; ?>
        <li class="st-list-row">
            <span>
                <b><?= h(BizStock::KINDS[$m['kind']] ?? (string)$m['kind']) ?></b>
                <span class="st-muted-i"><?= h(toJalali((string)$m['move_date'])) ?></span>
                <?php if ((string)$m['note'] !== ''): ?><span class="st-muted-i">· <?= h((string)$m['note']) ?></span><?php endif; ?>
            </span>
            <span class="st-move-end">
                <span class="st-num <?= $mq < 0 ? 'is-neg' : 'is-pos' ?>"><?= $mq > 0 ? '+' : '−' ?><?= h(formatQty(abs($mq))) ?></span>
                <?php if ($m['kind'] === 'adjust'): ?>
                <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این انبارگردانی حذف شود؟');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_move">
                    <input type="hidden" name="move_id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="st-link-btn" aria-label="حذفِ این حرکت">حذف</button>
                </form>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($product): ?>
<section class="st-card st-danger">
    <div class="st-danger-row">
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle">
            <button type="submit" class="st-btn st-btn-ghost"><?= (int)$product['is_active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
        </form>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('کالا و همه‌ی حرکت‌های انبارش حذف شود؟ این کار برگشت ندارد.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="st-btn st-btn-danger">حذفِ کالا</button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
