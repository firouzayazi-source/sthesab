<?php
/**
 * بکاپِ داده — صفحه‌ی خودش، با راهِ برگشت.
 *
 * ⛔ دلیلِ وجودِ این صفحه یک بن‌بستِ واقعی است: پیش از این، دکمه‌ی
 *    خروجی یک فرمِ POST بود که مستقیم به `api/export_data.php` می‌رفت.
 *    وقتی همه چیز درست پیش می‌رفت مرورگر فایل را می‌گرفت و صفحه
 *    عوض نمی‌شد؛ ولی هر مسیرِ شکست (توکنِ کهنه، خطای سرور) کاربر را در
 *    یک صفحه‌ی سفید با یک خطِ JSON رها می‌کرد — بی‌منو، بی‌دکمه، و در
 *    اپِ نصب‌شده حتی بی‌دکمه‌ی بازگشتِ مرورگر.
 *
 * ⛔ حالا دو لایه جلویش را می‌گیرد و هر دو لازم‌اند:
 *    ۱. این صفحه دکمه‌ی «بازگشت» دارد که **همیشه** سرِ جایش است —
 *       چه کاربر بکاپ بگیرد، چه پشیمان شود.
 *    ۲. خودِ اندپوینت هم در هر شکستی به همین‌جا برمی‌گردد با پیامِ
 *       روشن، نه به یک صفحه‌ی JSON.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_data.php';

Auth::initSession();
Auth::requireLogin();

$fileName = backupFileName();

$pageTitle = 'بکاپ داده';
include __DIR__ . '/includes/header.php';
?>

<?php /* همان الگوی `references.php`: اگر از داخلِ اپ آمده `history.back()`
         و وگرنه خانه. صفحه‌ای که فقط از پروفایل باز می‌شود بدون این،
         راهِ بسته شدن ندارد. */ ?>
<a href="<?= APP_BASE_PATH ?>/profile.php" class="page-back js-page-back">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    بازگشت
</a>

<div class="card">
    <h2 class="card-title">یک فایل، همه‌ی داده‌ی شما</h2>

    <p class="privacy-p" style="margin-bottom:6px;">
        همه‌ی تراکنش‌ها، حساب‌ها، چک‌ها، طلب و بدهی، بودجه، پس‌انداز،
        دارایی، معاملات و دسته‌بندی‌های شخصی‌تان در یک فایل ذخیره می‌شود.
    </p>
    <p class="hint" style="margin-bottom:16px;">
        رمز عبور و اطلاعاتِ ورودِ شما در این فایل <strong>نیست</strong>.
    </p>

    <div class="backup-file">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        <span class="backup-file-name"><?= h($fileName) ?></span>
    </div>

    <form method="post" action="<?= APP_BASE_PATH ?>/api/export_data.php" style="margin-top:16px;">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-primary btn-block btn-large">دریافت فایل بکاپ</button>
    </form>

    <a href="<?= APP_BASE_PATH ?>/profile.php" class="btn btn-secondary btn-block js-page-back" style="margin-top:10px;">
        بازگشت بدون گرفتن بکاپ
    </a>

    <p class="hint" style="margin-top:16px;">
        فایل با تاریخِ همان روز ساخته می‌شود، پس بکاپ‌های مختلف روی هم
        نمی‌افتند. جایی نگهش دارید که خودتان به آن دسترسی داشته باشید —
        این فایل جای دیگری ذخیره نمی‌شود.
    </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
