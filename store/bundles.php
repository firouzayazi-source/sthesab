<?php
/**
 * بسته‌ی فروش — منطق فقط `BizBundles` (الگو؛ بی‌موجودی). فروشش در فاکتورِ فروش با نامِ بسته.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_bundles.php';

$userId = Biz::ownerId();
$self   = Biz::url('bundles.php');
$edit   = (int)getParam('id', '0');
$error  = '';
$form   = ['name' => '', 'sku' => '', 'price' => '', 'items' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && BizBundles::ready()) {
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (postParam('action') === 'delete') {
        BizBundles::delete($userId, (int)postParam('id'));
        redirectWithMessage($self, 'success', 'بسته حذف شد؛ فاکتورهای قبلی دست نخوردند.');
    }
    $items = [];
    foreach ((array)($_POST['pid'] ?? []) as $k => $pid) {
        if ((int)$pid > 0) { $items[(int)$pid] = (string)($_POST['qty'][$k] ?? '1'); }
    }
    $form = ['name' => (string)postParam('name'), 'sku' => (string)postParam('sku'), 'price' => (string)postParam('price'), 'items' => $items,
             'inactive' => postParam('inactive') === '1'];
    $r = BizBundles::save($userId, $form, (int)postParam('id'));
    if ($r['ok']) { redirectWithMessage($self, 'success', $r['message']); }
    $error = $r['message'];
}

$all = BizBundles::all($userId);
if ($edit > 0 && $error === '') {
    foreach ($all as $b) {
        if ((int)$b['id'] === $edit) {
            $form = ['name' => (string)$b['name'], 'sku' => (string)$b['sku'], 'price' => (int)$b['price'] > 0 ? number_format((int)$b['price']) : '',
                     'items' => array_column($b['items'], 'qty', 'product_id'), 'inactive' => (int)$b['is_active'] !== 1];
        }
    }
}
$prods = array_values(array_filter(BizProducts::all($userId, '', '', 3000)['rows'],
    fn($p) => (int)$p['is_active'] === 1 && BizProducts::typeOf($p) !== 'phone'));
$rows = array_pad(array_map(null, array_keys($form['items']), array_values($form['items'])), max(4, count($form['items']) + 1), [0, '']);

$pageTitle = 'بسته‌ی فروش';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('products.php')) ?>">‹ محصولات</a>
        <h1 class="st-h1">بسته‌ی فروش</h1>
        <p class="st-muted">چند کالا با یک قیمتِ یک‌جا (مثلاً قاب + گلس + شارژر). در فاکتورِ فروش نامِ بسته را بزنید؛ هنگامِ ثبت به ردیف‌های همان کالاها باز می‌شود و موجودیِ هر کدام جدا کم می‌شود. گوشیِ IMEIدار جزءِ بسته نیست — آن را جدا روی فاکتور بیاورید.</p>
    </div>
</div>
<?php if (!BizBundles::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>
<form method="post" action="<?= h($self) ?>" class="st-card st-form" id="form">
    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $edit ?>">
    <h2 class="st-h2"><?= $edit > 0 ? 'ویرایشِ بسته' : 'بسته‌ی تازه' ?></h2>
    <div class="st-row3">
        <label class="st-field"><span>نامِ بسته</span><input type="text" name="name" maxlength="150" value="<?= h((string)$form['name']) ?>" required placeholder="مثلاً «پکِ لوازم A55»"></label>
        <label class="st-field"><span>کد/بارکد (اختیاری)</span><input type="text" name="sku" maxlength="60" dir="ltr" value="<?= h((string)$form['sku']) ?>"></label>
        <label class="st-field"><span>قیمتِ یک‌جا <small class="st-muted">(خالی = جمعِ قیمتِ فروشِ اجزا)</small></span><input type="text" name="price" inputmode="numeric" dir="ltr" data-money value="<?= h((string)$form['price']) ?>"></label>
    </div>
    <?php foreach ($rows as $i => [$pid, $qty]): ?>
    <div class="st-row2">
        <label class="st-field"><span>کالا</span><select name="pid[<?= $i ?>]"><option value="0">—</option>
            <?php foreach ($prods as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === (int)$pid ? ' selected' : '' ?>><?= h((string)$p['name']) ?> · <?= formatMoney((int)$p['sell_price']) ?></option><?php endforeach; ?>
        </select></label>
        <label class="st-field"><span>تعداد</span><input type="text" name="qty[<?= $i ?>]" inputmode="decimal" dir="ltr" data-num value="<?= h($qty === '' ? '' : formatQty((float)$qty)) ?>"></label>
    </div>
    <?php endforeach; ?>
    <?php if ($edit > 0): ?><label class="st-check"><input type="checkbox" name="inactive" value="1"<?= !empty($form['inactive']) ? ' checked' : '' ?>><span>غیرفعال (در فاکتور پیشنهاد نشود)</span></label><?php endif; ?>
    <button type="submit" class="st-btn">ذخیره‌ی بسته</button>
</form>

<?php if ($all): ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>بسته</th><th>اجزا</th><th class="st-th-num">قیمت</th><th></th></tr></thead>
        <tbody><?php foreach ($all as $b): ?>
            <tr<?= (int)$b['is_active'] !== 1 ? ' class="is-muted"' : '' ?>>
                <td><a class="st-row-link" href="<?= h($self . '?id=' . (int)$b['id']) ?>#form"><?= h((string)$b['name']) ?></a><?= (string)$b['sku'] !== '' ? ' <small class="st-muted-i" dir="ltr">' . h((string)$b['sku']) . '</small>' : '' ?></td>
                <td><?= h(implode('، ', array_map(fn($i) => $i['name'] . ((float)$i['qty'] != 1 ? ' ×' . formatQty((float)$i['qty']) : ''), $b['items']))) ?></td>
                <td class="st-td-num"><span class="st-num"><?= formatMoney(BizBundles::price($b)) ?></span></td>
                <td><form method="post" action="<?= h($self) ?>" class="st-inline" onsubmit="return confirm('این بسته حذف شود؟');"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button type="submit" class="st-link-btn st-link-danger">حذف</button></form></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
