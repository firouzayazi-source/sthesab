<?php
/**
 * نوارِ بخش‌های پنل مدیریت.
 *
 * ⛔ همه‌ی صفحه‌های `admin/` همین را include می‌کنند، پس فهرستِ بخش‌ها
 *    **یک جا** تعریف می‌شود. با کپی کردنش در هر صفحه، اضافه شدنِ یک
 *    بخشِ تازه یعنی چند جا یادت برود و کاربر بخشی را ببیند که از صفحه‌ی
 *    بغلی نمی‌بیند.
 *
 * ⚠ صفحه‌ای که هنوز روی دیسک نیست رندر نمی‌شود؛ وگرنه لینکش به ۴۰۴ِ
 *   خامِ nginx می‌رفت (همان درسِ `Auth::requireLogin()`).
 */
$__adminTabs = [
    'users.php'      => 'کاربران',
    'access.php'     => 'ورود و پیامک',
    'billing.php'    => 'اشتراک و پرداخت',
    'categories.php' => 'دسته‌بندی‌ها',
    'insights.php'   => 'آمار استفاده',
];
$__here = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<nav class="admin-tabs" aria-label="بخش‌های مدیریت">
    <?php foreach ($__adminTabs as $__file => $__label): ?>
        <?php if (!is_file(__DIR__ . '/' . $__file)) { continue; } ?>
        <a href="<?= APP_BASE_PATH ?>/admin/<?= h($__file) ?>"
           class="admin-tab <?= $__here === $__file ? 'is-active' : '' ?>"
           <?= $__here === $__file ? 'aria-current="page"' : '' ?>><?= h($__label) ?></a>
    <?php endforeach; ?>
</nav>
<?php
/**
 * ⛔ نسخه‌ی مستقرشده، همین‌جا و برای مدیر.
 *
 * بدونِ این، «آیا deploy من روی سرور نشست؟» فقط با SSH جواب داشت — و
 * تا وقتی جواب نداشته باشد، هر رفتارِ قدیمی به‌حسابِ باگ گذاشته می‌شود
 * و دنبالِ مشکلی می‌گردیم که اصلاً وجود ندارد. `deploy.sh` این مقدار
 * را هنگام استقرار می‌نویسد، پس اگر عوض نشده باشد یعنی استقرار انجام
 * نشده.
 *
 * ⚠ از `appVersion()` می‌آید که فایلِ `var/version.txt` را می‌خواند، نه
 *   از `git` — pool این اپ `exec` را غیرفعال کرده.
 */
require_once __DIR__ . '/../includes/user_data.php';
?>
<p class="deploy-stamp">نسخه‌ی مستقرشده: <span class="ltr-num"><?= h(appVersion()) ?></span></p>
