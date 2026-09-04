<?php
/**
 * اشتراک و پرداخت — یک مقوله‌ی کاملاً جدا از «کاربران».
 *
 * قیمت و شماره کارت و کلیدِ اعمالِ محدودیت اینجاست، به‌علاوه‌ی
 * پرداخت‌هایی که کاربر اعلام کرده و منتظر تأییدند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plan.php';

Auth::initSession();
Auth::requireAdmin();

$currentUserId = Auth::userId();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'update_plan_setting') {
        setSetting(PLAN_ENFORCE_SETTING, postParam('plan_enforced') === '1' ? '1' : '0');
        setSetting(PLAN_PRICE_SETTING, (string)max(0, (int)sanitizeAmount(postParam('plan_price'))));
        setSetting(PLAN_CARD_SETTING,  digitsOnly(postParam('plan_card'), 19));
        setSetting(PLAN_OWNER_SETTING, mb_substr(trim(postParam('plan_owner')), 0, 100));
        redirectWithMessage('billing.php', 'success', 'تنظیمات اشتراک بروزرسانی شد.');
    } elseif ($action === 'review_payment') {
        $pid = (int)postParam('payment_id');
        if (postParam('decision') === 'approve') {
            $r = approvePayment($pid, $currentUserId);
            redirectWithMessage('billing.php', $r['ok'] ? 'success' : 'error',
                $r['ok'] ? 'پرداخت تأیید شد. اشتراک تا ' . toJalali($r['until']) . ' تمدید شد.'
                         : ($r['error'] ?? 'تأیید انجام نشد.'));
        }
        $ok = rejectPayment($pid, $currentUserId, postParam('reason'));
        redirectWithMessage('billing.php', $ok ? 'success' : 'error',
            $ok ? 'پرداخت رد شد.' : 'رد کردن انجام نشد.');
    }
}

$planOn    = planEnforced();
$planPrice = planMonthlyPrice();
$planCard  = getSetting(PLAN_CARD_SETTING, '');
$planOwner = getSetting(PLAN_OWNER_SETTING, '');
$pending   = pendingPayments();

$pageTitle = 'اشتراک و پرداخت';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if (!plansAvailable()): ?>
    <div class="card">
        <p class="hint">
            جدولِ پرداخت‌ها هنوز ساخته نشده است. ابتدا migration ها را اعمال کنید
            (<code>deploy/migrate.sh --apply</code>).
        </p>
    </div>
<?php endif; ?>

<?php if (plansAvailable()): ?>
<div class="card">
    <h2 class="card-title">اشتراک و پرداخت</h2>
    <?php /* ⛔ «اعمال محدودیت» پیش‌فرض خاموش است. یک به‌روزرسانی نباید
             چیزی را از کاربرِ فعلی بگیرد؛ روشن کردنش تصمیمِ مالکِ نصب
             است، نه پیش‌فرضِ کد. */ ?>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_plan_setting">

        <div class="form-group">
            <label for="plan_price">قیمت هر ماه (تومان)</label>
            <input type="text" id="plan_price" name="plan_price" inputmode="numeric"
                   value="<?= $planPrice > 0 ? h(formatMoney($planPrice)) : '' ?>" placeholder="مثلاً ۵۰٬۰۰۰">
        </div>
        <div class="form-group">
            <label for="plan_card">شماره کارت برای واریز</label>
            <input type="text" id="plan_card" name="plan_card" inputmode="numeric" maxlength="19"
                   value="<?= h($planCard) ?>" placeholder="۱۶ رقم">
        </div>
        <div class="form-group">
            <label for="plan_owner">به نام</label>
            <input type="text" id="plan_owner" name="plan_owner" maxlength="100" value="<?= h($planOwner) ?>">
        </div>

        <label class="switch" style="margin-bottom:14px;">
            <input type="checkbox" name="plan_enforced" value="1" <?= $planOn ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">اعمال محدودیت طرح رایگان</span>
        </label>
        <p class="hint" style="margin-bottom:12px;">
            تا وقتی خاموش است، همه‌ی کاربران همه‌ی امکانات را دارند و
            اشتراک فقط حمایتی است. با روشن کردنش، بخش معاملات، API و
            یادآوری ایمیلی فقط برای مشترکان می‌ماند — هسته‌ی اپ (تراکنش،
            حساب، چک، طلب و بدهی) هرگز بسته نمی‌شود.
        </p>

        <button type="submit" class="btn btn-secondary btn-sm">ذخیره</button>
    </form>
</div>

<?php /* از اینجا به بعد کارتِ اشتراک است — فرمِ خودش را دارد. */ ?>
<div class="card">
    <h2 class="card-title">اشتراک و پرداخت‌ها</h2>

    <?php if ($pending): ?>
        <h3 class="danger-title" style="color:var(--ink); margin-top:20px;">
            پرداخت‌های در انتظار (<?= toPersianDigits(count($pending)) ?>)
        </h3>
        <?php foreach ($pending as $p): ?>
            <div class="pay-row">
                <div>
                    <strong><?= h($p['full_name']) ?></strong>
                    <span class="hint">(<?= h($p['username']) ?>)</span><br>
                    <span class="hint">
                        <?= toPersianDigits($p['months']) ?> ماه ·
                        <?= formatMoney($p['amount']) ?> تومان ·
                        کد: <?= h($p['reference']) ?>
                    </span>
                </div>
                <div style="display:flex; gap:6px;">
                    <form method="POST" style="display:inline;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="review_payment">
                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="btn btn-primary btn-sm">تأیید</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="review_payment">
                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="decision" value="reject">
                        <button type="submit" class="btn btn-secondary btn-sm">رد</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
