<?php
/**
 * سرورِ ساختگیِ «سایت» برای `test_store_io.php` — روترِ `php -S`.
 * فقط زیرِ `cli-server`؛ از وب ۴۰۴ (nginx هم `/tests/` را می‌بندد).
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit('Not found.');
}
$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$page = (int)($_GET['page'] ?? 1);

switch ($path) {
case '/shop':
case '/shop/':
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>shop</title></head><body>فروشگاه</body></html>';
    break;
case '/wp-json/wc/store/v1/products':
    header('Content-Type: application/json');
    $items = [];
    $from = ($page - 1) * 100;
    $total = 130;
    for ($i = $from; $i < min($total, $from + 100); $i++) {
        $items[] = [
            'name' => 'محصول &amp; شماره ' . ($i + 1),
            'sku' => 'W-' . ($i + 1),
            'prices' => ['price' => (string)(1500000 + $i), 'regular_price' => (string)(2000000 + $i * 10),
                         'currency_code' => 'IRR', 'currency_minor_unit' => 0],
            'categories' => [['name' => 'گوشی']],
        ];
    }
    echo json_encode($items, JSON_UNESCAPED_UNICODE);
    break;
case '/sheet.csv':
    header('Content-Type: text/csv; charset=utf-8');
    echo "\xEF\xBB\xBFنام کالا,قیمت فروش,موجودی\nکابل,85000,4\n";
    break;
case '/to-internal':
    header('Location: http://10.0.0.5/secret', true, 302);
    break;
case '/big':
    header('Content-Type: text/csv');
    echo str_repeat("a,b\n", 1600000);
    break;
default:
    http_response_code(404);
    echo 'no';
}
