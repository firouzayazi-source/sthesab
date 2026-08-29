<?php
/**
 * تحویل فایل‌های CSS/JS با فشرده‌سازی gzip و کش طولانی‌مدت.
 *
 * چرا لازم است: روی این هاست ماژول‌های mod_deflate و mod_expires آپاچی
 * موجود نیستند، بنابراین .htaccess نمی‌تواند فشرده‌سازی/کش را اعمال کند.
 * این فایل همان کار را با خود PHP انجام می‌دهد و به ماژول آپاچی وابسته نیست.
 *
 * همچنین چند فایل را در یک درخواست تحویل می‌دهد تا تعداد رفت‌وبرگشت‌ها کم شود.
 */

// فقط همین فایل‌ها مجاز هستند (جلوگیری از دسترسی به مسیرهای دیگر)
$allowed = [
    'css/style.css'            => 'text/css; charset=utf-8',
    'js/app.js'                => 'application/javascript; charset=utf-8',
    'js/jalali-datepicker.js'  => 'application/javascript; charset=utf-8',
    // Chart.js از CDN بیرونی می‌آمد. روی اینترنت داخلی همان یک درخواست
    // گاهی چند ثانیه طول می‌کشید یا اصلاً نمی‌رسید و نمودار خالی می‌ماند.
    // حالا از خود سرور می‌آید، gzip می‌شود و کش یک‌ساله دارد.
    'js/chart.umd.js'          => 'application/javascript; charset=utf-8',
];

$requested = isset($_GET['f']) ? (string)$_GET['f'] : '';
$files = array_filter(array_map('trim', explode(',', $requested)));

if (empty($files)) {
    http_response_code(400);
    exit('No file requested.');
}

$contentType = null;
$paths = [];
$latestMtime = 0;

foreach ($files as $f) {
    if (!isset($allowed[$f])) {
        http_response_code(403);
        exit('Forbidden.');
    }

    $full = __DIR__ . '/' . $f;
    if (!is_file($full)) {
        http_response_code(404);
        exit('Not found.');
    }

    // همه فایل‌های یک درخواست باید هم‌نوع باشند
    if ($contentType === null) {
        $contentType = $allowed[$f];
    } elseif ($contentType !== $allowed[$f]) {
        http_response_code(400);
        exit('Mixed content types.');
    }

    $paths[] = $full;
    $latestMtime = max($latestMtime, (int)filemtime($full));
}

$etag = '"' . md5(implode('|', $files) . '-' . $latestMtime) . '"';

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $latestMtime) . ' GMT');
header('Vary: Accept-Encoding');

// اگر مرورگر همین نسخه را دارد، بدنه را دوباره نفرست
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if (trim($ifNoneMatch) === $etag || ($ifModifiedSince && strtotime($ifModifiedSince) >= $latestMtime)) {
    http_response_code(304);
    exit;
}

$body = '';
foreach ($paths as $p) {
    $body .= file_get_contents($p) . "\n";
}

// مسیرهای نسبی داخل CSS نسبت به آدرسِ خودِ CSS حساب می‌شوند. فایل واقعی
// در assets/css/ است و فونت در assets/fonts/، پس در فایل نوشته‌ایم
// `../fonts/…`. ولی وقتی همین CSS از assets/serve.php تحویل داده شود،
// آدرس پایه یک پله بالاتر است و `../fonts/` بیرون از پوشه‌ی assets
// می‌افتد. اینجا به شکل درستِ همین حالت برمی‌گردد.
if (strpos($contentType, 'text/css') === 0) {
    $body = str_replace('../fonts/', 'fonts/', $body);
}

// فشرده‌سازی اگر مرورگر پشتیبانی کند
$acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
if (function_exists('gzencode') && stripos($acceptEncoding, 'gzip') !== false) {
    $compressed = gzencode($body, 6);
    if ($compressed !== false) {
        header('Content-Encoding: gzip');
        $body = $compressed;
    }
}

header('Content-Length: ' . strlen($body));
echo $body;
