<?php
/**
 * ⛔ پوسته‌ی محیطِ فروشگاهی — سرآیند و منو.
 *
 * عمداً **هیچ** چیزی از پوسته‌ی شخصی (`header.php`/`sidebar.php`/
 * `footer.php`/`app.js`/`style.css`) لود نمی‌کند: آن‌ها منو، شیتِ ثبتِ
 * تراکنش، پیش‌گیریِ صفحه‌های شخصی، معرفیِ اولیه و صفِ پیامکِ بانک را با
 * خودشان می‌آوردند. استایلِ این محیط فایلِ خودش است (`store.css`)، پس
 * `style.css`ِ شخصی حتی یک بایت عوض نشد.
 *
 * ورودیِ مستند فقط `$pageTitle` است؛ هر متغیرِ سراسریِ دیگرِ این فایل
 * پیشوندِ `__` دارد (قاعده ۵۰).
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

$__bizUid   = (int)Auth::userId();
$__bizSet   = Biz::settings($__bizUid);
$__bizShop  = $__bizSet['shop_name'] !== '' ? $__bizSet['shop_name'] : Auth::fullName();
$__bizPage  = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__bizFlash = getFlash();
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
<header class="st-top">
    <div class="st-top-in">
        <a class="st-brand" href="<?= h(Biz::url()) ?>">
            <span class="st-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V9z"/><path d="M5 13v7h14v-7"/><path d="M10 20v-4h4v4"/></svg>
            </span>
            <span class="st-brand-name"><?= h($__bizShop) ?></span>
        </a>
        <nav class="st-nav" aria-label="منوی فروشگاه">
            <?php foreach (Biz::NAV as $__file => $__label):
                if (!is_file(__DIR__ . '/../' . Biz::DIR . '/' . $__file)) { continue; } ?>
                <a href="<?= h(Biz::url($__file)) ?>"
                   class="st-nav-item<?= $__bizPage === $__file ? ' is-active' : '' ?>"
                   <?= $__bizPage === $__file ? 'aria-current="page"' : '' ?>><?= h($__label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php /* حسابِ «شخصی + فروشگاه» یک راهِ برگشت به حساب لندِ خودش دارد؛
                 همان نشست است و ورودِ دوباره نمی‌خواهد. */ ?>
        <?php if (!Biz::isStoreOnly()): ?>
        <a class="st-switch" href="<?= h(Biz::personalUrl()) ?>" title="برگشت به دفترِ شخصی">دفتر شخصی</a>
        <?php endif; ?>
        <a class="st-logout" href="<?= h(Biz::url('logout.php')) ?>">خروج</a>
    </div>
</header>
<main class="st-main">
<?php if ($__bizFlash !== null): ?>
    <div class="st-flash st-flash-<?= $__bizFlash['type'] === 'success' ? 'ok' : 'err' ?>" role="status"><?= h($__bizFlash['message']) ?></div>
<?php endif; ?>
