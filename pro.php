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
$plan   = userPlan($userId);
$price  = planMonthlyPrice();
$card   = getSetting(PLAN_CARD_SETTING, '');
$owner  = getSetting(PLAN_OWNER_SETTING, '');
$ready  = plansAvailable() && $price > 0 && $card !== '';
$rows   = userPayments($userId);

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
            <?php if ($plan['days_left'] !== null): ?>
                — <?= toPersianDigits($plan['days_left']) ?> روز باقی مانده
            <?php endif; ?>
        </p>
        <p class="hint">تا <?= toPersianDigits(toJalali($plan['pro_until'])) ?></p>
    <?php else: ?>
        <p class="plan-state">طرح رایگان</p>
        <?php if (!planEnforced()): ?>
            <?php /* صادق باش: امروز هیچ چیزی بسته نیست. */ ?>
            <p class="hint">همه‌ی امکانات در دسترس شماست؛ اشتراک فعلاً حمایتی است.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

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
            <div class="form-group">
                <label for="pay_months">دوره</label>
                <select id="pay_months" name="months">
                    <?php foreach (PLAN_PERIODS as $m => $mult): ?>
                        <option value="<?= (int)$m ?>">
                            <?= toPersianDigits($m) ?> ماه —
                            <?= formatMoney($price * $mult) ?> تومان
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
