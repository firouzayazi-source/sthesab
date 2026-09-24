<?php
/**
 * روترِ `php -S` که **همان** CSPِ تولید را روی هر پاسخ می‌گذارد.
 *
 * ⛔ چرا: سرورِ توسعه هیچ هدرِ امنیتی‌ای نمی‌فرستد، پس هر چیزی که
 *    فقط زیرِ CSP می‌شکند اینجا **سالم** دیده می‌شود. آپلودِ تصویرِ
 *    پروفایل دقیقاً همین بود: `URL.createObjectURL` آدرسِ `blob:`
 *    می‌داد، `img-src 'self' data:` آن را می‌بست، و روی سرور هر عکسی
 *    «تصویر معتبری نیست» می‌گرفت — در حالی که روی ماشینِ توسعه کار
 *    می‌کرد.
 *
 * سیاست از خودِ `deploy/nginx-csp.sh` خوانده می‌شود، نه نسخه‌ی دوم.
 * (قاعده ۳۵ هم‌خوانیِ آن با `vps-setup.sh` را می‌سنجد.)
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit('Not found.');
}
$__src = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-csp.sh');
if (preg_match('/^CSP="([^"]+)"/m', $__src, $__m)) {
    header('Content-Security-Policy: ' . $__m[1]);
}
return false;
