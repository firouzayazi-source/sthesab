<?php
/**
 * ⛔ پوسته‌ی محیطِ فروشگاهی — سرآیند و نوارِ کناریِ سمتِ راست.
 *
 * عمداً **هیچ** چیزی از پوسته‌ی شخصی (`header.php`/`sidebar.php`/
 * `footer.php`/`app.js`/`style.css`) لود نمی‌کند: آن‌ها منو، شیتِ ثبتِ
 * تراکنش، پیش‌گیریِ صفحه‌های شخصی، معرفیِ اولیه و صفِ پیامکِ بانک را با
 * خودشان می‌آوردند. استایلِ این محیط فایلِ خودش است (`store.css`).
 *
 * **خواسته‌ی مالکِ نصب:** «ساید بار سمت راست اضافه کن». روی دسکتاپ نوارِ
 * کناریِ ثابت، روی گوشی همان نوار به‌شکلِ کشو (با دکمه‌ی «منو»).
 * ⛔ کشو بی‌جاوااسکریپت است: یک چک‌باکسِ پنهان + `<label>`. کشویی که با
 *    نرسیدنِ اسکریپت باز نشود همان «دکمه‌ی بی‌کار» است.
 *
 * ورودیِ مستند فقط `$pageTitle` است؛ هر متغیرِ سراسریِ دیگرِ این فایل
 * پیشوندِ `__` دارد (قاعده ۵۰).
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

$__bizUid   = (int)Auth::userId();
$__bizSet   = Biz::settings($__bizUid);
$__bizShop  = $__bizSet['shop_name'] !== '' ? $__bizSet['shop_name'] : Auth::fullName();
$__bizPage  = Biz::navCurrent(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$__bizFlash = getFlash();
// آیکونِ هر قلمِ منو — فقط شکل، نه فهرستِ صفحه‌ها (آن `Biz::NAV` است)
$__bizIcons = [
    'index.php'          => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    'products.php'       => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'products-io.php'    => '<path d="M4 4h16v16H4z"/><path d="M8 9l3 3-3 3"/><path d="M13 15h3"/>',
    'parties.php'        => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6 6 0 0 1 3.5 6"/>',
    'reports.php'        => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
    'settings.php'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3.1 15H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.1V4a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'print-settings.php' => '<path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h7"/><circle cx="18" cy="15" r="3"/>',
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $__bizShop : $__bizShop) ?></title>
    <?php foreach (assetUrls(['css/store.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <meta name="theme-color" content="#1c1917">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h($__bizShop) ?>">
</head>
<body class="st-body">
<input type="checkbox" id="stNavToggle" class="st-nav-toggle" aria-hidden="true" tabindex="-1">
<aside class="st-side" aria-label="منوی فروشگاه">
    <a class="st-brand st-side-brand" href="<?= h(Biz::url()) ?>">
        <span class="st-brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V9z"/><path d="M5 13v7h14v-7"/><path d="M10 20v-4h4v4"/></svg>
        </span>
        <span class="st-brand-name"><?= h($__bizShop) ?></span>
    </a>
    <nav class="st-side-nav">
        <?php foreach (Biz::NAV as $__group => $__items):
            $__shown = array_filter($__items, fn($f) => is_file(__DIR__ . '/../' . Biz::DIR . '/' . $f), ARRAY_FILTER_USE_KEY);
            if (!$__shown) { continue; } ?>
        <div class="st-side-group">
            <p class="st-side-title"><?= h($__group) ?></p>
            <?php foreach ($__shown as $__file => $__label): ?>
            <a href="<?= h(Biz::url($__file)) ?>"
               class="st-side-item<?= $__bizPage === $__file ? ' is-active' : '' ?>"
               <?= $__bizPage === $__file ? 'aria-current="page"' : '' ?>>
                <svg class="st-side-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $__bizIcons[$__file] ?? '<circle cx="12" cy="12" r="4"/>' ?></svg>
                <span><?= h($__label) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>
    <div class="st-side-foot">
        <?php /* حسابِ «شخصی + فروشگاه» یک راهِ برگشت به حساب لندِ خودش دارد؛
                 همان نشست است و ورودِ دوباره نمی‌خواهد. */ ?>
        <?php if (!Biz::isStoreOnly()): ?>
        <a class="st-switch" href="<?= h(Biz::personalUrl()) ?>" title="برگشت به دفترِ شخصی">دفتر شخصی</a>
        <?php endif; ?>
        <a class="st-logout" href="<?= h(Biz::url('logout.php')) ?>">خروج</a>
    </div>
</aside>
<label for="stNavToggle" class="st-side-scrim" aria-hidden="true"></label>
<div class="st-shell">
<header class="st-top">
    <div class="st-top-in">
        <label for="stNavToggle" class="st-menu-btn" role="button" aria-label="منو">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </label>
        <a class="st-brand" href="<?= h(Biz::url()) ?>">
            <span class="st-brand-name"><?= h($__bizShop) ?></span>
        </a>
        <a class="st-logout" href="<?= h(Biz::url('logout.php')) ?>">خروج</a>
    </div>
</header>
<main class="st-main">
<?php if ($__bizFlash !== null): ?>
    <div class="st-flash st-flash-<?= $__bizFlash['type'] === 'success' ? 'ok' : 'err' ?>" role="status"><?= h($__bizFlash['message']) ?></div>
<?php endif; ?>
