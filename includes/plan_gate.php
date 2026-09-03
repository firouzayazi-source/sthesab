<?php
/**
 * گیتِ اشتراک — «دیده می‌شود، ولی باز نمی‌شود».
 *
 * ⛔ قابلیتِ پولی **پنهان نمی‌شود**. کاربری که نمی‌داند چیزی هست،
 *    هرگز برایش پول نمی‌دهد؛ آنچه می‌فروشد، دیدنِ همان چیزی است که
 *    ندارد. پس منو سرِ جایش می‌ماند، فقط با یک قفل، و زدنش صفحه‌ی
 *    «اشتراک» را نشان می‌دهد نه یک خطا.
 *
 * ⛔ و گیتِ صفحه به‌تنهایی کافی نیست: هر اندپوینتی که همان قابلیت را
 *    می‌نویسد هم باید بسته باشد، وگرنه قفل فقط تزئین است و با یک
 *    درخواستِ مستقیم دور زده می‌شود. `apiRequirePlan()` برای همان است.
 *
 * ⚠ تا وقتی `planEnforced()` خاموش است هیچ‌کدام از این‌ها کاری
 *   نمی‌کنند — نصب‌های موجود دست‌نخورده می‌مانند.
 */

require_once __DIR__ . '/plan.php';

/**
 * برچسبِ فارسیِ هر قابلیت — تنها جایی که این نام‌ها نوشته می‌شوند.
 */
function planFeatureLabel(string $feature): string
{
    $labels = [
        'trades'    => 'معاملات خرید و فروش',
        'cheques'   => 'چک‌ها',
        'debts'     => 'طلب و بدهی',
        'recurring' => 'تراکنش‌های دوره‌ای',
        'reminders' => 'یادآوری سررسید',
        'api'       => 'اپ موبایل',
    ];
    return $labels[$feature] ?? $feature;
}

/**
 * اگر قابلیت بسته است، صفحه‌ی قفل را نشان بده و همان‌جا تمام کن.
 *
 * ⚠ **بعد از** `include header.php` صدا زده نمی‌شود؛ خودش سرآیند و
 *   فوتر را می‌آورد تا صفحه‌ی قفل هم منو و راهِ برگشت داشته باشد.
 */
function requirePlanOrLock(string $feature): void
{
    $userId = (int)Auth::userId();
    if (planAllows($userId, $feature)) { return; }

    $label     = planFeatureLabel($feature);
    $pageTitle = $label;
    include __DIR__ . '/header.php';
    ?>
    <div class="card plan-lock">
        <div class="plan-lock-icon">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10.5" rx="2.5"/><path d="M8 10.5V7a4 4 0 018 0v3.5"/></svg>
        </div>
        <h2 class="plan-lock-title"><?= h($label) ?> بخشی از اشتراک است</h2>
        <p class="plan-lock-text">
            این بخش با اشتراک باز می‌شود. ثبت تراکنش، حساب‌ها، بودجه‌بندی،
            پس‌انداز، دارایی و گزارش‌ها همیشه رایگان‌اند و دست‌نخورده
            می‌مانند.
        </p>
        <?php if (planMonthlyPrice() > 0): ?>
            <p class="plan-lock-price">
                از ماهی <strong><?= toPersianDigits(formatMoney(planMonthlyPrice())) ?></strong>
                <?= h(APP_CURRENCY) ?>
            </p>
        <?php endif; ?>
        <a href="<?= APP_BASE_PATH ?>/pro.php" class="btn btn-primary btn-large">تهیه اشتراک</a>
        <a href="<?= APP_BASE_PATH ?>/index.php" class="link-back plan-lock-back">بازگشت به خانه</a>
    </div>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}

/**
 * همان کار، برای اندپوینت‌های `api/`.
 *
 * ⛔ بدون این، قفلِ صفحه فقط ظاهری است: کسی که آدرسِ اندپوینت را
 *    بداند می‌تواند مستقیم صدایش بزند. کدِ ۴۰۲ عمدی است — یعنی
 *    «پرداخت لازم است»، نه «اجازه نداری».
 */
function apiRequirePlan(string $feature): void
{
    if (planAllows((int)Auth::userId(), $feature)) { return; }
    jsonResponse([
        'success'  => false,
        'message'  => planFeatureLabel($feature) . ' بخشی از اشتراک است. برای استفاده اشتراک تهیه کنید.',
        'need_pro' => true,
    ], 402);
}

/**
 * برای منو: قابلیت بسته است؟ (تا کنارش قفل نشان داده شود)
 *
 * ⚠ عمداً «پنهان کن» برنمی‌گرداند بلکه «قفل بزن» — دلیلش بالای همین
 *   فایل نوشته شده.
 */
function planLocked(string $feature): bool
{
    return !planAllows((int)Auth::userId(), $feature);
}
