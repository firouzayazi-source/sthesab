<?php
/**
 * تنظیماتِ فروشگاه — سربرگِ فاکتور، و صندوق و حساب‌های خودِ فروشگاه.
 *
 * ⛔ صندوق‌ها `biz_accounts`اند، نه حساب‌های حساب لند (`wallets`): پولِ
 *    مغازه دفترِ خودش را دارد و در `walletBalances()` نمی‌نشیند.
 *
 * فرمِ POST با CSRF و بعد ریدایرکت (الگوی `admin/errors.php`): بدونِ
 * جاوااسکریپت هم کار می‌کند و تازه‌سازیِ صفحه فرم را دوباره نمی‌فرستد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId   = (int)Auth::userId();
$error    = '';
$cashErr  = '';
$values   = Biz::settings($userId);
$editAcc  = (int)getParam('acc', '0');
$cashForm = ['name' => '', 'kind' => 'cash', 'opening_balance' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && postParam('action') === 'cash_save') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $res = BizCash::save($userId, $_POST, (int)postParam('acc_id'));
    if ($res['ok']) {
        redirectWithMessage(Biz::url('settings.php') . '#cash', 'success', $res['message']);
    }
    $cashErr  = $res['message'];
    $editAcc  = (int)postParam('acc_id');
    $cashForm = array_merge($cashForm, array_intersect_key($_POST, $cashForm));
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && postParam('action') === 'cash_toggle') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $res = BizCash::setActive($userId, (int)postParam('acc_id'), postParam('on') === '1');
    redirectWithMessage(Biz::url('settings.php') . '#cash', $res['ok'] ? 'success' : 'error', $res['message']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $in = [];
    foreach (array_keys(Biz::SETTING_LIMITS) as $k) {
        $in[$k] = (string)($_POST[$k] ?? '');
    }
    $res = Biz::saveSettings($userId, $in);
    if ($res['ok']) {
        redirectWithMessage(Biz::url('settings.php'), 'success', $res['message']);
    }
    $error  = $res['message'];
    $values = $in;
}

$accounts = BizCash::list($userId);
if ($editAcc > 0 && $cashErr === '') {
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $editAcc) {
            $cashForm = ['name' => $a['name'], 'kind' => $a['kind'], 'opening_balance' => (string)(int)$a['opening_balance']];
        }
    }
}

$pageTitle = 'تنظیمات فروشگاه';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">تنظیمات فروشگاه</h1>
<h2 class="st-h2 st-section-title">سربرگِ فاکتور</h2>

<?php if ($error !== ''): ?>
<div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <p class="st-muted">این‌ها بالای فاکتورهای چاپی می‌نشینند.</p>

    <label class="st-field">
        <span>نامِ فروشگاه</span>
        <input type="text" name="shop_name" required maxlength="<?= Biz::SETTING_LIMITS['shop_name'] ?>"
               value="<?= h($values['shop_name']) ?>" autocomplete="organization">
    </label>
    <label class="st-field">
        <span>تلفن</span>
        <input type="text" name="phone" dir="ltr" inputmode="tel" maxlength="<?= Biz::SETTING_LIMITS['phone'] ?>"
               value="<?= h($values['phone']) ?>" autocomplete="tel">
    </label>
    <label class="st-field">
        <span>آدرس</span>
        <input type="text" name="address" maxlength="<?= Biz::SETTING_LIMITS['address'] ?>"
               value="<?= h($values['address']) ?>" autocomplete="street-address">
    </label>
    <label class="st-field">
        <span>متنِ پایینِ فاکتور <small class="st-muted">(اختیاری)</small></span>
        <textarea name="invoice_footer" rows="3" maxlength="<?= Biz::SETTING_LIMITS['invoice_footer'] ?>"><?= h($values['invoice_footer']) ?></textarea>
    </label>

    <button type="submit" class="st-btn">ذخیره</button>
</form>

<section class="st-card" id="cash">
    <h2 class="st-h2">صندوق و حساب‌های فروشگاه</h2>
    <p class="st-muted">پولِ فروشگاه اینجا می‌نشیند و هیچ ربطی به حساب‌های دفترِ شخصی ندارد. موجودیِ اولیه همان پولی است که امروز در صندوق یا حساب دارید.</p>
    <ul class="st-list">
        <?php foreach ($accounts as $a): $on = (int)$a['is_active'] === 1; ?>
        <li class="st-list-row<?= $on ? '' : ' is-inactive' ?>">
            <span>
                <b><?= h($a['name']) ?></b>
                <span class="st-muted-i"><?= h(BizCash::KINDS[$a['kind']] ?? (string)$a['kind']) ?><?= $on ? '' : ' · غیرفعال' ?></span>
            </span>
            <span class="st-move-end">
                <span class="st-num<?= (int)$a['balance'] < 0 ? ' is-neg' : '' ?>"><?= formatMoney((int)$a['balance']) ?></span>
                <a class="st-link-btn" href="<?= h(Biz::url('settings.php?acc=' . (int)$a['id'])) ?>#cash">ویرایش</a>
                <form method="post" action="<?= h(Biz::url('settings.php')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="cash_toggle">
                    <input type="hidden" name="acc_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                    <button type="submit" class="st-link-btn"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
                </form>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($cashErr !== ''): ?>
    <div class="st-flash st-flash-err" role="alert"><?= h($cashErr) ?></div>
    <?php endif; ?>
    <form method="post" class="st-form st-subform" action="<?= h(Biz::url('settings.php')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="cash_save">
        <input type="hidden" name="acc_id" value="<?= $editAcc ?>">
        <h3 class="st-h3"><?= $editAcc > 0 ? 'ویرایشِ حساب' : 'افزودنِ صندوق یا حساب' ?></h3>
        <div class="st-row3">
            <label class="st-field">
                <span>نام</span>
                <input type="text" name="name" required maxlength="<?= BizCash::NAME_MAX ?>" value="<?= h((string)$cashForm['name']) ?>" placeholder="مثلاً کارت‌خوانِ ملت">
            </label>
            <label class="st-field">
                <span>نوع</span>
                <select name="kind">
                    <?php foreach (BizCash::KINDS as $k => $l): ?>
                        <option value="<?= h($k) ?>"<?= $k === $cashForm['kind'] ? ' selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="st-field">
                <span>موجودیِ اولیه (تومان)</span>
                <input type="text" name="opening_balance" inputmode="numeric" dir="ltr" value="<?= h((string)$cashForm['opening_balance']) ?>">
            </label>
        </div>
        <div class="st-danger-row">
            <button type="submit" class="st-btn"><?= $editAcc > 0 ? 'ذخیره‌ی تغییرات' : 'افزودن' ?></button>
            <?php if ($editAcc > 0): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('settings.php')) ?>#cash">انصراف</a><?php endif; ?>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
