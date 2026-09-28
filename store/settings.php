<?php
/**
 * تنظیماتِ فروشگاه — سربرگِ فاکتور. صندوق‌ها در `accounts.php` هستند.
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
$values   = Biz::settings($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

<section class="st-card">
    <h2 class="st-h2">صندوق و حساب‌های فروشگاه</h2>
    <p class="st-muted">صندوق‌ها حالا صفحه‌ی خودشان را دارند، با موجودی و گردشِ هر کدام.</p>
    <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('accounts.php')) ?>">صندوق و بانک</a>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
