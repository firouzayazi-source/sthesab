<?php
/**
 * api/v1 — تنها دروازه‌ی مشتری‌های غیرمرورگری (اندروید، iOS، …).
 *
 * چرا یک دروازه به‌جای فایل‌های پراکنده مثل api/:
 *   احراز هویت، پاکت خطا، سنجش متد و ۴۰۴ همه یک بار اینجا نوشته
 *   می‌شوند. در api/ فعلی همین چند خط در هر ۵۴ فایل تکرار شده و کافی
 *   است یکی جا بیفتد تا یک اندپوینت بی‌سروصدا بی‌محافظ بماند.
 *
 * دو شکل آدرس هر دو کار می‌کنند:
 *   /api/v1/transactions            ← با قاعده‌ی rewrite در nginx (تمیز)
 *   /api/v1/index.php/transactions  ← بدون هیچ تغییری در nginx
 * دومی عمدی است: اگر کسی فایل سایت را بازنویسی کند (مثلاً certbot) یا
 * روی میزبان دیگری نصب کند، API نباید کاملاً بخوابد.
 */

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/transactions.php';
require_once __DIR__ . '/routes/reference.php';

/**
 * مسیرِ درخواست، بدون پیشوند و بدون query string.
 */
function v1Path(): string
{
    $path = $_SERVER['PATH_INFO'] ?? '';

    if ($path === '') {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $mark = '/api/v1';
        $pos  = strpos($uri, $mark);
        if ($pos !== false) {
            $path = substr($uri, $pos + strlen($mark));
            // اگر مستقیم index.php صدا زده شده باشد
            if (str_starts_with($path, '/index.php')) {
                $path = substr($path, strlen('/index.php'));
            }
        }
    }

    return trim(rawurldecode($path), '/');
}

// متد → مسیر → تابع. {id} یک عدد می‌گیرد.
$routes = [
    ['GET',    'ping',              'v1Ping'],

    ['POST',   'auth/login',        'v1AuthLogin'],
    ['POST',   'auth/logout',       'v1AuthLogout'],
    ['GET',    'me',                'v1Me'],

    ['GET',    'dashboard',         'v1Dashboard'],

    ['GET',    'transactions',      'v1TransactionsList'],
    ['POST',   'transactions',      'v1TransactionsCreate'],
    ['GET',    'transactions/{id}', 'v1TransactionsShow'],
    ['PATCH',  'transactions/{id}', 'v1TransactionsUpdate'],
    // برخی مشتری‌ها و پراکسی‌ها PATCH/DELETE را قورت می‌دهند؛ POST هم
    // پذیرفته می‌شود تا اپ روی هر شبکه‌ای کار کند.
    ['POST',   'transactions/{id}', 'v1TransactionsUpdate'],
    ['DELETE', 'transactions/{id}', 'v1TransactionsDelete'],

    ['GET',    'wallets',           'v1Wallets'],
    ['GET',    'categories',        'v1Categories'],
];

/** آیا الگو با مسیر می‌خواند؟ در صورت تطابق، پارامترها برمی‌گردند. */
function v1Match(string $pattern, string $path): ?array
{
    $p = $pattern === '' ? [] : explode('/', $pattern);
    $s = $path === ''    ? [] : explode('/', $path);
    if (count($p) !== count($s)) { return null; }

    $params = [];
    foreach ($p as $i => $seg) {
        if ($seg === '{id}') {
            if (!ctype_digit($s[$i])) { return null; }
            $params['id'] = (int)$s[$i];
            continue;
        }
        if ($seg !== $s[$i]) { return null; }
    }

    return $params;
}

$path   = v1Path();
$method = Api::method();

$allowed = [];      // متدهایی که همین مسیر می‌پذیرد — برای پاسخ ۴۰۵
foreach ($routes as [$verb, $pattern, $handler]) {
    $params = v1Match($pattern, $path);
    if ($params === null) { continue; }

    $allowed[] = $verb;
    if ($verb === $method) {
        $handler($params);
        exit;
    }
}

if ($allowed) {
    Api::requireMethod(array_values(array_unique($allowed)));
}

Api::fail('not_found', 'چنین آدرسی در این نسخه از API وجود ندارد.', 404);
