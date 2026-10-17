<?php
/**
 * ⛔ صفحه‌ی عمومیِ یک کالا (همان که سایتِ مقایسه به آن پیوند می‌دهد) — امضاشده، **بی‌ورود** (`BizFeed::verifyItem()`).
 * فقط نام، دسته، قیمتِ فروش، «موجود» و نشانیِ فروشگاه؛ کالایی که دیگر در صافیِ خروجی نیست ۴۰۴ِ خنثی.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_feed.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$shopId = (int)getParam('s', '0');
$v = BizFeed::verifyItem($shopId, (int)getParam('p', '0'), (string)getParam('t'));
if ($v === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><title>پیدا نشد</title>'
       . '<body style="font-family:sans-serif;text-align:center;padding:3rem">این کالا پیدا نشد یا دیگر عرضه نمی‌شود.</body></html>';
    exit;
}
$r = $v['row'];
$shop = Biz::settings($shopId);
$font = assetUrls(['fonts/Vazirmatn.woff2'])[0] ?? '';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($r['name'] . ' · ' . ($shop['shop_name'] ?: '')) ?></title>
<meta property="og:title" content="<?= h($r['name']) ?>">
<meta property="product:price:amount" content="<?= (int)$r['price'] ?>">
<meta property="product:price:currency" content="IRT">
<meta property="product:availability" content="<?= $r['available'] ? 'instock' : 'outofstock' ?>">
<style>
@font-face { font-family: 'Vazirmatn'; src: url('<?= h($font) ?>') format('woff2'); font-weight: 100 900; font-display: swap; }
:root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f8fafc; --card: #fff; --accent: #4f46e5; --ok: #059669; }
@media (prefers-color-scheme: dark) { :root { --ink: #f3f4f6; --muted: #9ca3af; --line: #374151; --bg: #0b1020; --card: #111827; --accent: #818cf8; --ok: #34d399; } }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Vazirmatn', Tahoma, sans-serif; background: var(--bg); color: var(--ink); line-height: 1.8; }
.it { max-width: 560px; margin: 0 auto; padding: 16px; }
.it-card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 12px; }
.it h1 { font-size: 20px; margin: 0 0 6px; }
.it-price { font-size: 24px; font-weight: 800; color: var(--accent); }
.it-ok { color: var(--ok); font-weight: 700; } .it-no { color: var(--muted); }
.it-muted { color: var(--muted); font-size: 13px; }
</style>
</head>
<body>
<main class="it">
    <section class="it-card">
        <?php if ($r['category'] !== ''): ?><p class="it-muted"><?= h($r['category']) ?></p><?php endif; ?>
        <h1><?= h($r['name']) ?></h1>
        <p class="it-price"><?= formatMoney($r['price']) ?> تومان</p>
        <p class="<?= $r['available'] ? 'it-ok' : 'it-no' ?>"><?= $r['available'] ? 'موجود' : 'ناموجود' ?></p>
    </section>
    <section class="it-card">
        <b><?= h($shop['shop_name'] !== '' ? $shop['shop_name'] : 'فروشگاه') ?></b>
        <?php if ($shop['phone'] !== ''): ?><p>تلفن: <a href="tel:<?= h(toLatinDigits($shop['phone'])) ?>" dir="ltr"><?= h(toPersianDigits($shop['phone'])) ?></a></p><?php endif; ?>
        <?php if ($shop['address'] !== ''): ?><p><?= h($shop['address']) ?></p><?php endif; ?>
        <p class="it-muted">قیمت لحظه‌ای از دفترِ فروشگاه خوانده می‌شود و ممکن است تغییر کند.</p>
    </section>
</main>
</body>
</html>
