<?php
/**
 * یک طرف‌حساب — تعریف و ویرایش. مانده‌ی اول دوره با یک **جهتِ صریح**
 * وارد می‌شود («او بدهکار است» / «ما بدهکاریم»)، نه با علامتِ منفی که روی
 * کیبوردِ موبایل گم می‌شود.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId = (int)Auth::userId();
$id     = (int)getParam('id', '0');
$party  = $id > 0 ? BizParties::get($userId, $id) : null;
if ($id > 0 && !$party) {
    redirectWithMessage(Biz::url('parties.php'), 'error', 'طرف‌حساب پیدا نشد.');
}
$self  = Biz::url('party.php' . ($id > 0 ? '?id=' . $id : ''));
$error = '';
$kindIn = getParam('kind');

$form = [
    'name'           => $party['name'] ?? '',
    'kind'           => $party['kind'] ?? (isset(BizParties::KINDS[$kindIn]) ? $kindIn : 'customer'),
    'phone'          => $party['phone'] ?? '',
    'address'        => $party['address'] ?? '',
    'note'           => $party['note'] ?? '',
    'opening_amount' => $party ? (string)abs((int)$party['opening_balance']) : '',
    'opening_side'   => $party && (int)$party['opening_balance'] < 0 ? 'we' : 'they',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'save') {
        $res = BizParties::save($userId, $_POST, $id);
        if ($res['ok']) {
            redirectWithMessage(Biz::url('party.php?id=' . (int)$res['id']), 'success', $res['message']);
        }
        $error = $res['message'];
        foreach ($form as $k => $_) { if (isset($_POST[$k])) { $form[$k] = (string)$_POST[$k]; } }
    } elseif ($party && $action === 'toggle') {
        $on = (int)$party['is_active'] !== 1;
        BizParties::setActive($userId, $id, $on);
        redirectWithMessage($self, 'success', $on ? 'طرف‌حساب فعال شد.' : 'طرف‌حساب غیرفعال شد.');
    } elseif ($party && $action === 'delete') {
        $res = BizParties::delete($userId, $id);
        redirectWithMessage($res['ok'] ? Biz::url('parties.php') : $self, $res['ok'] ? 'success' : 'error', $res['message']);
    } else {
        redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
    }
}

$pageTitle = $party ? (string)$party['name'] : 'طرف‌حسابِ تازه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('parties.php')) ?>">‹ طرف‌حساب‌ها</a>
        <h1 class="st-h1"><?= h($party ? (string)$party['name'] : 'طرف‌حسابِ تازه') ?></h1>
    </div>
    <div class="st-head-actions">
        <?php if ($party && (int)$party['is_active'] !== 1): ?><span class="st-badge is-out">غیرفعال</span><?php endif; ?>
        <?php if ($party): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=party&id=' . (int)$party['id'])) ?>">چاپِ صورت‌حساب</a><?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?>
<div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($party): $bal = (int)$party['balance']; ?>
<div class="st-kpis st-kpis-1">
    <div class="st-kpi-card">
        <span class="st-kpi-label">مانده</span>
        <?php if ($bal === 0): ?>
            <span class="st-kpi-value">تسویه</span>
        <?php else: ?>
            <span class="st-kpi-value st-num <?= $bal > 0 ? 'is-pos' : 'is-neg' ?>"><?= formatMoney(abs($bal)) ?></span>
            <span class="st-kpi-sub"><?= $bal > 0 ? 'تومان — او به فروشگاه بدهکار است' : 'تومان — فروشگاه به او بدهکار است' ?></span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="post" class="st-card st-form st-form-narrow" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">

    <label class="st-field">
        <span>نام</span>
        <input type="text" name="name" required maxlength="<?= BizParties::LIMITS['name'] ?>" value="<?= h((string)$form['name']) ?>" <?= $party ? '' : 'autofocus' ?>>
    </label>
    <fieldset class="st-fieldset">
        <legend>نوع</legend>
        <div class="st-seg">
            <?php foreach (BizParties::KINDS as $k => $l): ?>
            <label class="st-seg-opt">
                <input type="radio" name="kind" value="<?= h($k) ?>"<?= $k === $form['kind'] ? ' checked' : '' ?>>
                <span><?= h($l) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <div class="st-row2">
        <label class="st-field">
            <span>تلفن <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="phone" dir="ltr" inputmode="tel" maxlength="<?= BizParties::LIMITS['phone'] ?>" value="<?= h((string)$form['phone']) ?>">
        </label>
        <label class="st-field">
            <span>آدرس <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="address" maxlength="<?= BizParties::LIMITS['address'] ?>" value="<?= h((string)$form['address']) ?>">
        </label>
    </div>
    <fieldset class="st-fieldset">
        <legend>مانده‌ی اول دوره <small class="st-muted">(اختیاری)</small></legend>
        <div class="st-row2">
            <label class="st-field">
                <span>مبلغ (تومان)</span>
                <input type="text" name="opening_amount" inputmode="numeric" dir="ltr" value="<?= h((string)$form['opening_amount'] === '0' ? '' : (string)$form['opening_amount']) ?>">
            </label>
            <label class="st-field">
                <span>جهت</span>
                <select name="opening_side">
                    <option value="they"<?= $form['opening_side'] === 'they' ? ' selected' : '' ?>>او به فروشگاه بدهکار است</option>
                    <option value="we"<?= $form['opening_side'] === 'we' ? ' selected' : '' ?>>فروشگاه به او بدهکار است</option>
                </select>
            </label>
        </div>
    </fieldset>
    <label class="st-field">
        <span>یادداشت <small class="st-muted">(اختیاری)</small></span>
        <textarea name="note" rows="2" maxlength="<?= BizParties::LIMITS['note'] ?>"><?= h((string)$form['note']) ?></textarea>
    </label>
    <button type="submit" class="st-btn"><?= $party ? 'ذخیره‌ی تغییرات' : 'ثبتِ طرف‌حساب' ?></button>
</form>

<?php if ($party): ?>
<section class="st-card st-danger">
    <div class="st-danger-row">
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle">
            <button type="submit" class="st-btn st-btn-ghost"><?= (int)$party['is_active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
        </form>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این طرف‌حساب حذف شود؟ این کار برگشت ندارد.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="st-btn st-btn-danger">حذف</button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
