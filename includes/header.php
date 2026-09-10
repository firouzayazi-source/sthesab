<?php
// ⚠ اینجا لود می‌شود نه در تک‌تکِ صفحه‌ها: زنگِ اعلان در نوارِ بالاست و
//   نوارِ بالا در همین فایل است. با لودِ صفحه‌به‌صفحه، زنگ روی بعضی
//   صفحه‌ها می‌آمد و روی بعضی نه — و کاربر فکر می‌کرد اعلانش پرید.
require_once __DIR__ . '/notify.php';

if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}
/* عرضِ صفحه — همان الگوی `$pageTitle`: صفحه پیش از این include
   مقدارش را می‌گذارد و اینجا فقط پیش‌فرض گرفته می‌شود. پیش‌فرض
   **باریک** است، پس هیچ صفحه‌ای با افزودنِ این خط عوض نمی‌شود. */
if (!isset($pageWide)) {
    $pageWide = false;
}
// فشرده‌سازی خروجی در includes/db.php و پیش از هر خروجی فعال می‌شود
?>
<!DOCTYPE html>
<!-- js-loading را app.js بلافاصله برمی‌دارد. اگر برنداشت یعنی اسکریپت
     نرسیده و صفحه با اینکه کامل به نظر می‌رسد، هیچ دکمه‌ای ندارد —
     استایل .js-loading همین را بعد از یک مکث کوتاه نشان می‌دهد. -->
<html lang="fa" dir="rtl" class="js-loading">
<head>
    <script>
        /* حالت شب سه‌حالته: auto (پیش‌فرض) / light / dark
           در حالت auto، اپ زنده از تنظیمات گوشی پیروی می‌کند. */
        (function () {
            try {
                var mode = localStorage.getItem('daftar_theme') || 'auto';
                var sysDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var dark = (mode === 'dark') || (mode === 'auto' && sysDark);
                if (dark) { document.documentElement.setAttribute('data-theme', 'dark'); }
                document.documentElement.setAttribute('data-theme-mode', mode);
            } catch (e) {}
        })();
    </script>
    <meta charset="UTF-8">
    <!-- viewport-fit=cover لازم است وگرنه iOS مقدار env(safe-area-inset-*)
         را صفر می‌دهد و همه‌ی محاسبه‌های حاشیه‌ی امن بی‌اثر می‌مانند. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
    <!-- فونت داخل CSS تعریف شده، پس مرورگر تا وقتی style.css را نگرفته و
         تجزیه نکرده خبر ندارد لازمش دارد. با preload هر دو با هم دانلود
         می‌شوند و متن یک رفت‌وبرگشت زودتر با فونت درست می‌نشیند. -->
    <link rel="preload" href="<?= APP_BASE_PATH ?>/assets/fonts/Vazirmatn.woff2" as="font" type="font/woff2" crossorigin>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <!-- اسکریپت با defer در head می‌آید نه ته صفحه: این‌طور مرورگر همان
         اول شروع به گرفتنش می‌کند و موازی با خواندن HTML دانلود می‌شود،
         ولی اجرایش مثل قبل بعد از ساخته‌شدن کل صفحه است. -->
    <script>window.APP_BASE = '<?= APP_BASE_PATH ?>';</script>
    <?php foreach (assetUrls(['js/jalali-datepicker.js', 'js/app.js']) as $__u): ?>
    <script defer src="<?= h($__u) ?>"></script>
    <?php endforeach; ?>
    <link rel="apple-touch-icon" href="<?= iconUrl('icon-180.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= iconUrl('icon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= iconUrl('icon-16.png') ?>">
    <link rel="manifest" href="<?= APP_BASE_PATH ?>/assets/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
    <meta name="theme-color" content="#0b0b0b">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="باز کردن منو">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 6H20M4 12H20M4 18H20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <h1 class="page-title"><?= h($pageTitle) ?></h1>
            <div class="topbar-user">
                <?php
                    /* ⛔ زنگ فقط وقتی رندر می‌شود که جدولش آمده باشد —
                       لینکی که به صفحه‌ی «هنوز ساخته نشده» می‌رود از
                       نبودنش بدتر است. تولیدِ اعلان روزی یک بار و از
                       همین‌جا انجام می‌شود، پس هیچ cron ای لازم نیست
                       (همان الگوی `processRecurringTransactions`). */
                    $__notifOn = false;
                    $__unread  = 0;
                    if (Auth::isLoggedIn() && Notify::available()) {
                        $__notifOn = true;
                        Notify::generateFor((int)Auth::userId());
                        $__unread = Notify::unreadCount((int)Auth::userId());
                    }
                ?>
                <?php if ($__notifOn): ?>
                <a href="<?= APP_BASE_PATH ?>/notifications.php" class="theme-toggle notif-bell" aria-label="اعلان‌ها">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
                    <?php if ($__unread > 0): ?>
                        <span class="notif-badge"><?= toPersianDigits((string)min($__unread, 99)) ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="<?= APP_BASE_PATH ?>/search.php" class="theme-toggle" aria-label="جستجو">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </a>
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="تغییر حالت شب و روز">
                    <svg class="theme-icon-moon" width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg class="theme-icon-sun" width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <a href="<?= APP_BASE_PATH ?>/profile.php" class="user-name" style="text-decoration:none;"><?= h(Auth::fullName()) ?></a>
                <span class="user-role-badge <?= Auth::isAdmin() ? 'badge-admin' : 'badge-user' ?>">
                    <?= Auth::isAdmin() ? 'مدیر' : 'کاربر' ?>
                </span>
            </div>
        </header>

        <?php /* صفحه‌ای که جدولِ پهن دارد پیش از این include مقدارِ
                 `$pageWide = true` می‌گذارد؛ بقیه دست‌نخورده می‌مانند. */ ?>
        <div class="page-content<?= $pageWide ? ' is-wide' : '' ?>">
            <?php /* پیشنهادِ نصبِ اپ اندروید — بالای همه چیز، ولی فقط
                     روی اندروید و فقط وقتی APK واقعاً وجود دارد. خودش
                     تصمیم می‌گیرد که رندر شود یا نه. */ ?>
            <?= androidInstallBanner() ?>
            <?php $flash = getFlash(); ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endif; ?>
