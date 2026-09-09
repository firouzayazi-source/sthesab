<?php
/**
 * صفحه‌ی حریم خصوصی.
 *
 * ⛔ عمداً کوتاه و بی‌تعارف است. متنِ حقوقیِ بلندی که هیچ‌کس نمی‌خواند
 *    اعتماد نمی‌سازد؛ چهار جمله‌ی راست می‌سازد. هر ادعایی که اینجا
 *    نوشته شده باید در کد قابل نشان دادن باشد — اگر روزی یکی از این‌ها
 *    عوض شد، این صفحه هم باید همان روز عوض شود.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/user_data.php';

Auth::initSession();
Auth::requireLogin();

$pageTitle = 'حریم خصوصی';
include __DIR__ . '/includes/header.php';

$support = getSetting('support_email', '');
$flows   = outboundDataFlows();
?>

<a href="<?= APP_BASE_PATH ?>/profile.php" class="page-back js-page-back">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    بازگشت
</a>

<div class="card">
    <h2 class="card-title">داده‌ی شما کجاست</h2>

    <p class="privacy-p">
        دفتر شما — تراکنش‌ها، حساب‌ها، چک‌ها، طلب و بدهی — روی همان سروری
        می‌ماند که این برنامه رویش نصب است. <strong>هیچ آمار استفاده‌ای
        جمع نمی‌شود</strong>، هیچ ابزار تحلیلی و هیچ اسکریپت یا فونتی از
        سرویس بیرونی روی این صفحه‌ها بار نمی‌شود.
    </p>

    <?php if ($flows === []): ?>
        <p class="privacy-p">
            روی این نصب، <strong>هیچ چیزی به سرویس بیرونی فرستاده
            نمی‌شود</strong> — نه تراکنش، نه شماره حساب، نه ایمیل.
        </p>
    <?php else: ?>
        <p class="privacy-p">
            <strong>ولی این‌ها از سرور بیرون می‌روند</strong>، و اینجا
            نوشته می‌شوند چون همین حالا روی این نصب روشن‌اند:
        </p>
        <?php foreach ($flows as $flow): ?>
            <p class="privacy-p"><?= h($flow['text']) ?></p>
        <?php endforeach; ?>
    <?php endif; ?>

    <p class="privacy-p">
        <strong>پیامک بانک</strong> که برای ثبت سریع می‌چسبانید، اصلاً از
        مرورگر شما بیرون نمی‌رود. خواندنش کاملاً روی همان دستگاه انجام
        می‌شود و فقط فیلدهای فرم پر می‌شوند.
    </p>

    <p class="privacy-p">
        <strong>نرخ طلا و ارز</strong> دستی وارد می‌شود. برنامه به هیچ
        سرویس قیمتی وصل نیست، پس نه چیزی از شما بیرون می‌رود و نه
        کارکردش به اینترنت بیرون بند است.
    </p>

    <p class="privacy-p">
        <strong>شماره کارت، شماره حساب و شبا</strong> در فهرست حساب‌ها
        نمایش داده نمی‌شوند و فقط وقتی نمای کارت را باز کنید دیده
        می‌شوند.
        <?php if (Crypto::available()): ?>
            روی این نصب، این سه مورد در دیتابیس <strong>رمزشده</strong>
            ذخیره می‌شوند.
        <?php else: ?>
            روی این نصب رمزنگاری‌شان روشن نیست؛ مدیر می‌تواند روشنش کند.
        <?php endif; ?>
    </p>

    <p class="privacy-p">
        <strong>ایمیل شما</strong> فقط برای ورود، بازیابی رمز، و — اگر
        خودتان روشن نگه دارید — یادآوری سررسیدها استفاده می‌شود.
    </p>
</div>

<div class="card">
    <h2 class="card-title">اختیارِ شما</h2>
    <p class="privacy-p">
        هر وقت بخواهید می‌توانید <strong>خروجی کاملِ</strong> داده‌تان را
        در یک فایل بگیرید، یا <strong>حساب و همه‌ی داده‌اش را برای همیشه
        پاک کنید</strong>. هر دو در
        <a href="<?= APP_BASE_PATH ?>/profile.php">حساب کاربری</a>،
        بخش «داده‌ی من».
    </p>
    <p class="privacy-p">
        حذف حساب برگشت‌پذیر نیست و پشتیبانی هم نمی‌تواند برش گرداند.
    </p>
</div>

<div class="card">
    <h2 class="card-title">تماس</h2>
    <?php if ($support !== ''): ?>
        <p class="privacy-p">
            سؤال یا مشکل: <a href="mailto:<?= h($support) ?>"><?= h($support) ?></a>
        </p>
    <?php else: ?>
        <p class="privacy-p hint">
            آدرس پشتیبانی هنوز تنظیم نشده است. مدیرِ این نصب می‌تواند
            آن را در پنل مدیریت وارد کند.
        </p>
    <?php endif; ?>
    <p class="hint version-line">نسخه: <?= h(appVersion()) ?></p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
