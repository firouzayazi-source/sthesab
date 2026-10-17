<?php
/**
 * ⛔ خروجیِ کالاها برای ترب و سایت‌های مقایسه‌ی قیمت — آدرسِ امضاشده، **بی‌ورود** (`BizFeed::verifyFeed()`).
 * JSON (پیش‌فرض) یا CSV (`f=csv`، با BOM و سدِ فرمولِ اکسل). هر شکست ۴۰۴ِ خنثی؛ هیچ قیمتِ خرید و شمارِ موجودی.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_feed.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$shopId = (int)getParam('s', '0');
$cfg    = BizFeed::verifyFeed($shopId, (string)getParam('t'));
if ($cfg === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}
$items = array_map(fn($r) => BizFeed::item($shopId, $r, $cfg['salt']), BizFeed::rows($shopId, $cfg));
if (getParam('f') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'title', 'category', 'price', 'price_rial', 'currency', 'availability', 'url'];
    fputcsv($out, $cols, ',', '"', '');
    foreach ($items as $it) {
        // ⛔ سدِ فرمولِ اکسل: متنی که با = + - @ شروع شود
        fputcsv($out, array_map(fn($c) => is_string($it[$c]) && preg_match('/^[=+\-@]/', $it[$c]) ? "'" . $it[$c] : $it[$c], $cols), ',', '"', '');
    }
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$shop = Biz::settings($shopId);
echo json_encode(['shop' => $shop['shop_name'], 'updated' => date('c'), 'count' => count($items), 'products' => $items],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
