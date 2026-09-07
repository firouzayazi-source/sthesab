<?php
/**
 * وضعیت اشتراک و اعلامِ پرداخت.
 *
 * ⛔ اگر قیمت یا شماره‌ی کارت تنظیم نشده باشد، این صفحه **صریح
 *    می‌گوید هنوز راه نیفتاده** — نه اینکه فرمی نشان بدهد که کاربر
 *    پرش کند و پولی جایی نرود. فرمی که کار نمی‌کند از نبودنش بدتر
 *    است.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plan.php';

Auth::initSession();
Auth::requireLogin();

$userId = (int)Auth::userId();

/**
 * ⛔ کد از راهِ یک POST معمولی و رفت‌وبرگشتِ سرور اعمال می‌شود، نه با
 *    حسابِ قیمت در جاوااسکریپت. حسابِ تخفیف فقط در
 *    `discountFinalPrice()` است؛ نسخه‌ی دومش در مرورگر یعنی کاربر یک
 *    مبلغ می‌دید و مبلغِ دیگری ثبت می‌شد — و آن اختلاف تا وقتی کسی
 *    شکایت نکند دیده نمی‌شد.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $act = postParam('action');

    if ($act === 'clear_code') {
        unset($_SESSION['pro_discount']);
        redirectWithMessage('pro.php', 'success', 'کد تخفیف برداشته شد.');
    } elseif ($act === 'apply_code') {
        $chk = discountCheck(postParam('code'), $userId);
        if (!$chk['ok']) {
            unset($_SESSION['pro_discount']);
            redirectWithMessage('pro.php', 'error', $chk['error'] ?? 'کد معتبر نیست.');
        }

        // ⛔ کدِ ۱۰۰٪ همان لحظه اعمال می‌شود و اصلاً وارد مسیرِ خرید
        //    نمی‌شود: کاربری که «۱۰۰٪ تخفیف» می‌گیرد نباید فرمِ واریز و
        //    انتظارِ تأییدِ مدیر ببیند.
        if (discountIsFree($chk['code'])) {
            $r = redeemDiscountCode($userId, $chk['code']['code']);
            unset($_SESSION['pro_discount']);
            redirectWithMessage('pro.php', $r['ok'] ? 'success' : 'error',
                $r['ok']
                    ? (planIsForever($r['until'] ?? null)
                        ? 'کد پذیرفته شد — دسترسی کامل و مادام‌العمر برای شما فعال شد.'
                        : 'کد پذیرفته شد — دسترسی کامل تا ' . toJalali($r['until']) . ' فعال شد.')
                    : ($r['error'] ?? 'اعمال کد انجام نشد.'));
        }

        // ⚠ فقط خودِ **کد** در نشست می‌ماند، نه قیمتِ حساب‌شده: با ذخیره‌ی
        //   مبلغ، تغییرِ قیمت یا تمام شدنِ ظرفیت تا پایانِ نشست دیده
        //   نمی‌شد و کاربر روی عددی حساب می‌کرد که دیگر درست نبود.
        $_SESSION['pro_discount'] = $chk['code']['code'];
        redirectWithMessage('pro.php', 'success',
            'کد اعمال شد — ' . toPersianDigits((int)$chk['code']['percent']) . '٪ تخفیف.');
    }
}

$plan   = userPlan($userId);
$price  = planMonthlyPrice();
$card   = getSetting(PLAN_CARD_SETTING, '');
$owner  = getSetting(PLAN_OWNER_SETTING, '');
$ready  = plansAvailable() && $price > 0 && $card !== '';
$rows   = userPayments($userId);

// ⚠ کدِ داخلِ نشست هر بار **از نو** سنجیده می‌شود. اگر مدیر بین دو
//   بازدید خاموشش کرده یا ظرفیتش تمام شده باشد، کاربر نباید تخفیفی
//   ببیند که دیگر وجود ندارد و هنگام ثبت رد شود.
$appliedCode = null;
if (!empty($_SESSION['pro_discount'])) {
    $again = discountCheck((string)$_SESSION['pro_discount'], $userId);
    if ($again['ok'] && !discountIsFree($again['code'])) {
        $appliedCode = $again['code'];
    } else {
        unset($_SESSION['pro_discount']);
    }
}

// جعبه‌ی کد فقط وقتی رندر می‌شود که واقعاً کدی برای استفاده باشد —
// وگرنه یک فیلدِ همیشگی که هر چه در آن بزنی «کد نامعتبر» می‌گوید.
$showCodeBox = $appliedCode !== null || hasUsableDiscountCode();

$pageTitle = 'اشتراک';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/profile.php" class="page-back js-page-back">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M11 6l-6 6 6 6"/></svg>
    <span>بازگشت</span>
</a>

<div class="card">
    <h2 class="card-title">وضعیت شما</h2>
    <?php if ($plan['is_pro']): ?>
        <p class="plan-state plan-state-on">
            اشتراک فعال است
            <?php if (!empty($plan['is_forever'])): ?>
                — مادام‌العمر
            <?php elseif ($plan['days_left'] !== null): ?>
                — <?= toPersianDigits($plan['days_left']) ?> روز باقی مانده
            <?php endif; ?>
        </p>
        <?php /* ⛔ برای مادام‌العمر تاریخ نوشته نمی‌شود: `9999-12-31` روی
                 تقویم شمسی «۹۳۷۸/۱۰/۱۱» می‌شود و کاربر آن را یک باگ
                 می‌خواند، نه یک هدیه. */ ?>
        <?php if (empty($plan['is_forever'])): ?>
            <p class="hint">تا <?= toPersianDigits(toJalali($plan['pro_until'])) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="plan-state">طرح رایگان</p>
        <?php if (!planEnforced()): ?>
            <?php /* صادق باش: امروز هیچ چیزی بسته نیست. */ ?>
            <p class="hint">همه‌ی امکانات در دسترس شماست؛ اشتراک فعلاً حمایتی است.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php /* ⛔ جعبه‌ی کد **بیرون** از شرطِ «قیمت و کارت تنظیم شده» است، و این
         عمدی است: «۱۰۰ نفر اول رایگان‌اند» باید روزِ اول کار کند —
         همان روزی که هنوز هیچ شماره کارتی وارد نشده و فروشی در کار
         نیست. با گذاشتنش داخلِ آن شرط، کد دقیقاً وقتی از کار می‌افتاد
         که بیشترین کاربرد را داشت. */ ?>
<?php if ($showCodeBox): ?>
<div class="card">
    <h2 class="card-title">کد تخفیف</h2>

    <?php if ($appliedCode): ?>
        <div class="code-applied">
            <div>
                <span class="code-tag"><?= h($appliedCode['code']) ?></span>
                <span class="code-applied-text">
                    <?= toPersianDigits((int)$appliedCode['percent']) ?>٪ تخفیف روی خرید
                </span>
            </div>
            <form method="POST">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear_code">
                <button type="submit" class="btn btn-secondary btn-sm">برداشتن</button>
            </form>
        </div>
    <?php else: ?>
        <p class="hint" style="margin-bottom:10px;">
            اگر کد تخفیف دارید، اینجا وارد کنید.
        </p>
        <form method="POST" class="code-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="apply_code">
            <input type="text" name="code" class="code-input" required
                   maxlength="<?= DISCOUNT_CODE_MAX_LEN ?>"
                   autocapitalize="characters" autocomplete="off"
                   autocorrect="off" spellcheck="false" placeholder="کد را وارد کنید">
            <button type="submit" class="btn btn-primary">اعمال</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$ready): ?>
    <div class="card">
        <h2 class="card-title">هنوز راه نیفتاده</h2>
        <p class="hint">
            مدیرِ این نصب هنوز قیمت و شماره کارت را وارد نکرده است.
            فعلاً کاری لازم نیست.
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <h2 class="card-title">تمدید اشتراک</h2>

        <div class="pay-card">
            <p class="hint" style="margin-bottom:6px;">مبلغ را به این کارت واریز کنید:</p>
            <p class="pay-number"><?= toPersianDigits(formatCardNumber($card)) ?></p>
            <?php if ($owner !== ''): ?>
                <p class="hint">به نام <?= h($owner) ?></p>
            <?php endif; ?>
        </div>

        <form id="payForm" autocomplete="off" style="margin-top:16px;">
            <?= Csrf::field() ?>
            <?php /* کدِ اعمال‌شده همراهِ فرم می‌رود، ولی سرور دوباره و زیرِ
                     قفل می‌سنجدش — این فیلد فقط حمل می‌کند، تصمیم
                     نمی‌گیرد. */ ?>
            <input type="hidden" name="discount_code" value="<?= h($appliedCode['code'] ?? '') ?>">
            <div class="form-group">
                <label for="pay_months">دوره</label>
                <select id="pay_months" name="months">
                    <?php foreach (PLAN_PERIODS as $m => $mult): ?>
                        <?php /* ⛔ قیمت از `discountFinalPrice()` می‌آید، همان
                                 تابعی که `submitPayment()` هم از آن می‌خواند.
                                 با حسابِ محلی، عددِ روی صفحه و عددِ ثبت‌شده
                                 دیر یا زود فرق می‌کردند. */ ?>
                        <?php $q = discountFinalPrice($appliedCode, (int)$m); ?>
                        <option value="<?= (int)$m ?>">
                            <?= toPersianDigits($m) ?> ماه —
                            <?php if ($q['discount'] > 0): ?>
                                <?= formatMoney($q['final']) ?> تومان
                                (به‌جای <?= formatMoney($q['price']) ?>)
                            <?php else: ?>
                                <?= formatMoney($q['final']) ?> تومان
                            <?php endif; ?>
                            <?php if ($mult < $m): ?>(<?= toPersianDigits($m - $mult) ?> ماه هدیه)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pay_ref">شماره پیگیری یا چهار رقم آخر کارتِ شما</label>
                <input type="text" id="pay_ref" name="reference" required maxlength="120"
                       inputmode="numeric" placeholder="مثلاً ۱۲۳۴۵۶۷۸">
            </div>
            <div class="form-group">
                <label for="pay_note">توضیح (اختیاری)</label>
                <input type="text" id="pay_note" name="note" maxlength="255">
            </div>

            <div id="payMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="paySubmit">اعلام پرداخت</button>
        </form>

        <p class="hint" style="margin-top:10px;">
            بعد از بررسی و تأیید، اشتراک شما تمدید می‌شود. اگر تاریخِ فعلی
            هنوز تمام نشده باشد، دوره‌ی تازه از همان ادامه پیدا می‌کند —
            روزی از دست نمی‌رود.
        </p>
    </div>
<?php endif; ?>

<?php if ($rows): ?>
<div class="card">
    <h2 class="card-title">پرداخت‌های شما</h2>
    <?php foreach ($rows as $r): ?>
        <div class="pay-row">
            <div>
                <strong><?= toPersianDigits($r['months']) ?> ماه</strong>
                <span class="hint"> · <?= toPersianDigits(toJalali(substr($r['created_at'], 0, 10))) ?></span>
            </div>
            <span class="status-badge <?= $r['status'] === 'approved' ? 'status-badge-in'
                : ($r['status'] === 'rejected' ? 'status-badge-out' : 'status-badge-muted') ?>">
                <?= $r['status'] === 'approved' ? 'تأیید شد'
                    : ($r['status'] === 'rejected' ? 'رد شد' : 'در انتظار بررسی') ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">
<?php include __DIR__ . '/includes/footer.php'; ?>
