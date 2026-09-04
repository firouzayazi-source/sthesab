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
    } elseif ($action === 'grant_pro') {
        // ⛔ دسترسیِ رایگان از همان `grantPro()` رد می‌شود، نه یک
        //    `UPDATE` محلی: قاعده‌ی «تمدید از بیشترِ امروز و انقضای
        //    فعلی» و ثبتِ ردِ کار هر دو آنجا هستند.
        $r = grantPro((int)postParam('grant_user'), (int)postParam('grant_months'),
                      $currentUserId, postParam('grant_note'));
        redirectWithMessage('billing.php', $r['ok'] ? 'success' : 'error',
            $r['ok']
                ? (planIsForever($r['until'] ?? null)
                    ? 'دسترسی کامل و مادام‌العمر فعال شد.'
                    : 'دسترسی کامل تا ' . toJalali($r['until']) . ' فعال شد.')
                : ($r['error'] ?? 'ثبت دسترسی انجام نشد.'));
    } elseif ($action === 'revoke_pro') {
        $r = revokePro((int)postParam('grant_user'), $currentUserId);
        redirectWithMessage('billing.php', $r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'دسترسی پس گرفته شد.' : ($r['error'] ?? 'انجام نشد.'));
    }
}

$planOn    = planEnforced();
$planPrice = planMonthlyPrice();
$planCard  = getSetting(PLAN_CARD_SETTING, '');
$planOwner = getSetting(PLAN_OWNER_SETTING, '');
$pending   = pendingPayments();

// فهرستِ کاربران برای دادنِ دسترسی، همراه با وضعیتِ فعلیِ هرکدام —
// بدونِ وضعیت، مدیر نمی‌داند به چه کسی از قبل داده و دوباره می‌دهد.
$grantUsers = [];
if (plansAvailable()) {
    $grantUsers = Database::getConnection()->query(
        "SELECT id, username, full_name, plan, pro_until,
                (pro_until IS NOT NULL AND pro_until >= CURDATE()) AS active
         FROM users WHERE is_active = 1 ORDER BY full_name, username"
    )->fetchAll();
}

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

<?php /* ⛔ دادنِ دسترسی بدونِ پرداخت — برای کسانی که مالکِ نصب خودش
         می‌خواهد مهمانشان کند (خانواده، آزمونِ یک کاربر، جبرانِ یک
         خرابی). بدونِ این، تنها راه دست بردن مستقیم در دیتابیس بود.

         ⚠ هر بار یک ردیف در `payments` با `method = 'admin_grant'` ثبت
           می‌شود؛ نه برای پول، برای **رد**: شش ماه بعد باید معلوم باشد
           چرا این کاربر Pro است و چه کسی این را داده. */ ?>
<div class="card">
    <h2 class="card-title">دادن دسترسی کامل (بدون پرداخت)</h2>
    <?php if (!$grantUsers): ?>
        <p class="hint">کاربر فعالی برای انتخاب نیست.</p>
    <?php else: ?>
    <form method="POST">
        <?= Csrf::field() ?>
        <?php /* ⛔ `action` از خودِ دکمه می‌آید، نه از یک `<input hidden>`
                 به‌علاوه‌ی جاوااسکریپت. وسوسه‌ی اول این بود که دکمه‌ی
                 «پس گرفتن» با `this.form.action.value = …` مقدار را عوض
                 کند — که **کار نمی‌کند و هیچ خطایی هم نمی‌دهد**:
                 `form.action` خودِ آدرسِ فرم است نه فیلدی به نامِ action،
                 پس آن انتساب بی‌اثر می‌ماند و دکمه‌ی «پس گرفتن»
                 بی‌سروصدا **دسترسی می‌داد**. با دو دکمه‌ی name-دار هیچ
                 ابهامی نمی‌ماند و بدونِ جاوااسکریپت هم درست کار می‌کند. */ ?>

        <div class="form-group">
            <label for="grant_user">کاربر</label>
            <select id="grant_user" name="grant_user" required>
                <?php foreach ($grantUsers as $u): ?>
                    <?php
                        // وضعیتِ فعلی کنارِ نام: «فعال تا …» یا «مادام‌العمر»
                        $state = '';
                        if ((int)$u['active'] === 1) {
                            $state = planIsForever($u['pro_until'])
                                   ? ' — مادام‌العمر'
                                   : ' — فعال تا ' . toJalali($u['pro_until']);
                        }
                    ?>
                    <option value="<?= (int)$u['id'] ?>">
                        <?= h($u['full_name'] !== '' ? $u['full_name'] : $u['username']) ?>
                        (<?= h($u['username']) ?>)<?= h($state) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="grant_months">مدت</label>
            <select id="grant_months" name="grant_months">
                <?php /* فهرست از `PLAN_GRANT_PERIODS` می‌آید — فهرستِ دوم
                         نسازید، وگرنه گزینه‌ای که مدیر می‌بیند هنگام ذخیره
                         بی‌صدا رد می‌شود. */ ?>
                <?php foreach (PLAN_GRANT_PERIODS as $m => $label): ?>
                    <option value="<?= (int)$m ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">
                مدت به اشتراکِ فعلی <strong>اضافه</strong> می‌شود، نه اینکه جایش را بگیرد —
                پس کاربری که هنوز اعتبار دارد چیزی از دست نمی‌دهد.
            </p>
        </div>

        <div class="form-group">
            <label for="grant_note">توضیح (اختیاری)</label>
            <input type="text" id="grant_note" name="grant_note" maxlength="255"
                   placeholder="مثلاً: کاربر آزمایشی، یا جبران خرابی">
        </div>

        <div class="sms-actions">
            <button type="submit" name="action" value="grant_pro"
                    class="btn btn-primary btn-sm">فعال کن</button>
            <button type="submit" name="action" value="revoke_pro"
                    class="btn btn-secondary btn-sm"
                    onclick="return confirm('دسترسی این کاربر پس گرفته شود؟');">
                پس گرفتن دسترسی
            </button>
        </div>
    </form>
    <?php endif; ?>
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
