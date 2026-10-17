<?php
/**
 * ⛔ پیگیریِ تعمیر برای خودِ مشتری — لینکِ امضاشده، **بی‌ورود** (`BizShare::verifyRepair()`).
 *
 * کنارِ `view.php` و `prices.php` تنها صفحه‌های بی‌ورودِ فروشگاه (جز ورود/خروج و برگشت از درگاه). پس:
 * - امضای HMAC با نمکِ فروشگاه و پیشوندِ جدا از فاکتور (`rep:`)؛ مقایسه‌ی زمان‌ثابت.
 * - فقط آن‌چه مشتری خودش می‌داند: دستگاه، چهار رقمِ آخرِ IMEI، مرحله، تاریخِ پذیرش و وعده، برآورد.
 *   ⛔ نه نام و تلفنِ مشتری، نه تعمیرکار، نه یادداشتِ داخلی، نه مبلغِ فاکتور.
 * - هر شکست یک ۴۰۴ِ خنثی؛ `noindex`، `no-store`، بی‌لینک به داخلِ فروشگاه.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_repairs.php';
require_once __DIR__ . '/../includes/biz_comms.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$shopId = (int)getParam('s', '0');
$repId  = (int)getParam('r', '0');
$rep    = BizShare::verifyRepair($shopId, $repId, (string)getParam('t')) ? BizRepairs::get($shopId, $repId) : null;
if (!$rep) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><title>پیدا نشد</title>'
       . '<body style="font-family:sans-serif;text-align:center;padding:3rem">این قبض پیدا نشد یا لینکش دیگر معتبر نیست.</body></html>';
    exit;
}
$shop  = Biz::settings($shopId);
$steps = ['received', 'repairing', 'ready', 'delivered'];
$cur   = (string)$rep['status'];
$at    = array_search($cur === 'waiting' ? 'repairing' : $cur, $steps, true);
$imei  = (string)($rep['imei'] ?? '');
$font  = assetUrls(['fonts/Vazirmatn.woff2'])[0] ?? '';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= h('پیگیریِ تعمیر · ' . ($shop['shop_name'] ?: '')) ?></title>
<style>
@font-face { font-family: 'Vazirmatn'; src: url('<?= h($font) ?>') format('woff2'); font-weight: 100 900; font-display: swap; }
:root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f8fafc; --card: #fff; --accent: #4f46e5; --ok: #059669; --warn: #b45309; --bad: #b91c1c; }
@media (prefers-color-scheme: dark) { :root { --ink: #f3f4f6; --muted: #9ca3af; --line: #374151; --bg: #0b1020; --card: #111827; --accent: #818cf8; --ok: #34d399; --warn: #fbbf24; --bad: #f87171; } }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Vazirmatn', Tahoma, sans-serif; background: var(--bg); color: var(--ink); line-height: 1.7; }
.tr { max-width: 560px; margin: 0 auto; padding: 16px; }
.tr-head { text-align: center; padding: 8px 0 16px; }
.tr-head h1 { margin: 0; font-size: 20px; }
.tr-head p { margin: 2px 0; color: var(--muted); font-size: 13px; }
.tr-card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 16px; margin-bottom: 12px; }
.tr-state { font-size: 18px; font-weight: 800; margin: 0 0 4px; }
.tr-state.is-ready { color: var(--ok); } .tr-state.is-waiting { color: var(--warn); } .tr-state.is-cancelled { color: var(--bad); }
.tr-steps { list-style: none; margin: 12px 0 0; padding: 0; display: flex; gap: 6px; }
.tr-steps li { flex: 1; text-align: center; font-size: 12px; color: var(--muted); padding-top: 10px; border-top: 4px solid var(--line); }
.tr-steps li.is-done { color: var(--ink); border-top-color: var(--accent); }
dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 0; font-size: 14px; }
dt { color: var(--muted); } dd { margin: 0; }
.tr-foot { text-align: center; color: var(--muted); font-size: 12px; padding: 8px 0 24px; }
</style>
</head>
<body>
<main class="tr">
    <header class="tr-head">
        <h1><?= h($shop['shop_name'] !== '' ? $shop['shop_name'] : 'پیگیریِ تعمیر') ?></h1>
        <p>پیگیریِ تعمیر · قبضِ شماره‌ی <?= toPersianDigits((string)$rep['number']) ?></p>
        <?php if ($shop['phone'] !== ''): ?><p>تلفن: <span dir="ltr"><?= h(toPersianDigits($shop['phone'])) ?></span></p><?php endif; ?>
    </header>
    <section class="tr-card" role="status">
        <p class="tr-state is-<?= h($cur) ?>"><?= h(BizRepairs::STATUSES[$cur] ?? $cur) ?></p>
        <?php if ($cur === 'ready'): ?><p>گوشیِ شما آماده است؛ با قبضِ پذیرش برای تحویل بیایید.</p><?php endif; ?>
        <?php if ($cur === 'waiting'): ?><p>منتظرِ رسیدنِ قطعه هستیم.</p><?php endif; ?>
        <?php if ($cur !== 'cancelled' && $at !== false): ?>
        <ol class="tr-steps">
            <?php foreach ($steps as $i => $sk): ?><li class="<?= $i <= $at ? 'is-done' : '' ?>"><?= h(BizRepairs::STATUSES[$sk]) ?></li><?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </section>
    <section class="tr-card">
        <dl>
            <dt>دستگاه</dt><dd><?= h((string)$rep['device']) ?><?= $imei !== '' ? ' <span dir="ltr">(…' . h(toPersianDigits(substr($imei, -4))) . ')</span>' : '' ?></dd>
            <dt>پذیرش</dt><dd><?= h(toJalali((string)$rep['received_on'])) ?></dd>
            <?php if (!empty($rep['promised_on'])): ?><dt>وعده‌ی تحویل</dt><dd><?= h(toJalali((string)$rep['promised_on'])) ?></dd><?php endif; ?>
            <?php if ((int)$rep['estimate'] > 0): ?><dt>برآوردِ هزینه</dt><dd><?= formatMoney((int)$rep['estimate']) ?> تومان</dd><?php endif; ?>
        </dl>
    </section>
    <p class="tr-foot">این صفحه هر بار از دفترِ فروشگاه خوانده می‌شود.</p>
</main>
</body>
</html>
