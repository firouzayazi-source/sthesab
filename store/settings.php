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
require_once __DIR__ . '/../includes/avatar.php';

$userId   = (int)Auth::userId();
$error    = '';
$values   = Biz::settings($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    // ⛔ تصویرِ شخصی از همان `saveUserAvatar()`ِ پروفایلِ حساب لند می‌رود
    //    (includes/avatar.php) — نوع از محتوا، بازکشی با GD، نامِ تصادفی.
    if (postParam('action') === 'avatar') {
        $res = saveUserAvatar($userId, $_FILES['avatar'] ?? null);
        redirectWithMessage(Biz::url('settings.php') . '#avatar', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    if (postParam('action') === 'avatar_delete') {
        $res = deleteUserAvatar($userId);
        redirectWithMessage(Biz::url('settings.php') . '#avatar', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    // رنگِ فروشگاه فرمِ خودش را دارد تا ذخیره‌ی رنگ به سربرگ دست نزند.
    if (postParam('action') === 'palette') {
        $res = Biz::savePalette($userId, (string)postParam('palette'));
        redirectWithMessage(Biz::url('settings.php') . '#palette', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    if (postParam('action') === 'invoice_prefs') {
        $res = Biz::saveInvoicePrefs($userId, $_POST);
        redirectWithMessage(Biz::url('settings.php') . '#invoice', $res['ok'] ? 'success' : 'error', $res['message']);
    }
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

$ready    = Biz::businessInfoReady();
$invPrefs = Biz::invoicePrefs($userId);
// مشتریِ پیش‌فرض — فقط مشتری‌های فعال (تأمین‌کننده روی فاکتورِ فروش نمی‌نشیند)
$customers = $ready ? array_values(array_filter(BizParties::all($userId, '', 500, false)['rows'],
    fn($p) => $p['kind'] !== 'supplier' && (int)$p['is_active'] === 1)) : [];

$pageTitle = 'تنظیمات فروشگاه';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">تنظیمات فروشگاه</h1>

<?php $__av = avatarUrl($_SESSION['avatar'] ?? null); $__avOk = Biz::$avatarReady ?? usersHaveColumn(Database::getConnection(), 'avatar');   // از کوئریِ نوعِ حساب — بی‌کوئریِ ساختار ?>
<h2 class="st-h2 st-section-title" id="avatar">تصویر شخصی</h2>
<div class="st-card st-form">
    <p class="st-muted">جای حرفِ اولِ نامتان، بالای صفحه و در منو می‌نشیند — همان تصویرِ پروفایلِ دفترِ شخصی.</p>
    <?php if (!$__avOk): ?>
    <p class="st-muted">ستونِ تصویر هنوز در دیتابیس ساخته نشده (<code dir="ltr">bash deploy/migrate.sh --apply</code>).</p>
    <?php else: ?>
    <div class="st-avatar-row">
        <span class="st-avatar st-avatar-lg<?= $__av !== '' ? ' has-img' : '' ?>" data-avatar-preview aria-hidden="true"><?php if ($__av !== ''): ?><img class="st-avatar-img" src="<?= h($__av) ?>" alt="" width="72" height="72"><?php else: ?><?= h(mb_substr(trim(Auth::fullName() !== '' ? Auth::fullName() : 'کاربر'), 0, 1)) ?><?php endif; ?></span>
        <form method="post" enctype="multipart/form-data" class="st-avatar-form" action="<?= h(Biz::url('settings.php')) ?>" data-avatar-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="avatar">
            <label class="st-field">
                <span>انتخابِ تصویر</span>
                <input type="file" name="avatar" accept="image/*" required data-avatar-input>
            </label>
            <button type="submit" class="st-btn">ذخیره‌ی تصویر</button>
        </form>
        <?php if ($__av !== ''): ?>
        <form method="post" action="<?= h(Biz::url('settings.php')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="avatar_delete">
            <button type="submit" class="st-btn st-btn-ghost">حذفِ تصویر</button>
        </form>
        <?php endif; ?>
    </div>
    <p class="st-muted">JPG، PNG یا WEBP. مربعِ وسطِ تصویر برداشته و کوچک می‌شود.</p>
    <?php endif; ?>
</div>

<h2 class="st-h2 st-section-title" id="palette">رنگِ فروشگاه</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="palette">
    <p class="st-muted">منوی کناری، دکمه‌ها و کارتِ «فروشِ امروز» با همین رنگ طیف می‌گیرند — روی همه‌ی دستگاه‌ها.</p>
    <div class="st-palettes" role="radiogroup" aria-label="رنگِ فروشگاه">
        <?php $__cur = Biz::palette($userId); foreach (Biz::PALETTES as $__k => $__p): ?>
        <label class="st-pal">
            <input type="radio" name="palette" value="<?= h($__k) ?>"<?= $__k === $__cur ? ' checked' : '' ?>>
            <span class="st-pal-swatch" data-pal="<?= h($__k) ?>" aria-hidden="true"></span>
            <span class="st-pal-name"><?= h($__p['label']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="st-btn">ذخیره‌ی رنگ</button>
</form>
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
    <?php if ($ready): ?>
    <fieldset class="st-fieldset">
        <legend>اطلاعاتِ رسمی <small class="st-muted">(اختیاری — روی سربرگِ فاکتورِ رسمی)</small></legend>
        <div class="st-row2">
            <?php foreach (Biz::SETTING_CODES as $ck): ?>
            <label class="st-field">
                <span><?= h(BizCommon::CODES[$ck][2]) ?></span>
                <input type="text" name="<?= h($ck) ?>" dir="ltr" inputmode="numeric" maxlength="24" value="<?= h((string)($values[$ck] ?? '')) ?>">
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endif; ?>
    <label class="st-field">
        <span>متنِ پایینِ فاکتور <small class="st-muted">(اختیاری)</small></span>
        <textarea name="invoice_footer" rows="3" maxlength="<?= Biz::SETTING_LIMITS['invoice_footer'] ?>"><?= h($values['invoice_footer']) ?></textarea>
    </label>

    <button type="submit" class="st-btn">ذخیره</button>
</form>

<?php if ($ready): ?>
<h2 class="st-h2 st-section-title" id="invoice">گزینه‌های فاکتور</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="invoice_prefs">
    <p class="st-muted">همه پیش‌فرض خاموش‌اند. فروشِ بیش از موجودی همیشه بسته است — موجودیِ انبار هیچ‌وقت منفی نمی‌شود.</p>
    <?php foreach (Biz::INVOICE_FLAGS as $fk => $fl): ?>
    <label class="st-check">
        <input type="checkbox" name="<?= h($fk) ?>" value="1"<?= $invPrefs[$fk] ? ' checked' : '' ?>>
        <span><?= h($fl) ?></span>
    </label>
    <?php endforeach; ?>
    <label class="st-field">
        <span>مشتریِ پیش‌فرضِ فاکتورِ فروش</span>
        <select name="default_party">
            <option value="0">— بی‌پیش‌فرض (گذری یا انتخاب در هر فاکتور) —</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $invPrefs['default_party'] ? ' selected' : '' ?>><?= h((string)$c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="st-btn">ذخیره‌ی گزینه‌ها</button>
</form>
<?php endif; ?>

<section class="st-card">
    <h2 class="st-h2">صندوق و حساب‌های فروشگاه</h2>
    <p class="st-muted">صندوق‌ها حالا صفحه‌ی خودشان را دارند، با موجودی و گردشِ هر کدام.</p>
    <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('accounts.php')) ?>">صندوق و بانک</a>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
