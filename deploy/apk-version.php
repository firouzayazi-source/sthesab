<?php
/**
 * نسخه‌ی یک فایلِ APK — همان چیزی که سایت برای نوارِ «به‌روزرسانی» می‌خواند.
 *
 *   php deploy/apk-version.php download/hesabland.apk
 *   → 10	1.9	ir.stland.hesabland
 *
 * ⛔ دو مصرف‌کننده دارد و هر دو دلیلِ وجودش‌اند:
 *   - `deploy/apk-publish.sh` پیش از گذاشتنِ فایل روی دامنه می‌پرسد، و
 *     اگر نامِ بسته با اپِ ما نخواند **منتشر نمی‌کند** — وگرنه کاربر با
 *     زدنِ «به‌روزرسانی» یک اپِ **دیگر** کنارِ اپِ خودش نصب می‌کرد.
 *   - ساختِ گیت‌هاب همین را روی APKِ واقعیِ همان ساخت اجرا و با `aapt2`
 *     مقایسه می‌کند؛ پس تجزیه‌گرِ `includes/apk_meta.php` روی خروجیِ
 *     واقعیِ aapt2 سنجیده می‌شود، نه فقط روی نمونه‌ی ساختگیِ تست.
 *
 * کدِ خروج: ۰ خوانده شد، ۱ خوانده نشد، ۲ خوانده شد ولی بسته‌ی دیگری است
 * (فقط با `--expect`).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/apk_meta.php';

$args   = array_slice($argv, 1);
$expect = false;
$files  = [];
foreach ($args as $a) {
    if ($a === '--expect') { $expect = true; continue; }
    $files[] = $a;
}
if (count($files) !== 1) {
    fwrite(STDERR, "  php deploy/apk-version.php download/hesabland.apk\n");
    fwrite(STDERR, "  php deploy/apk-version.php --expect download/hesabland.apk\n");
    exit(1);
}

$info = apkManifestInfo($files[0]);
if ($info === null) {
    fwrite(STDERR, "نسخه از این فایل خوانده نشد (APK نیست، ناقص است، یا manifest ندارد): {$files[0]}\n");
    exit(1);
}

echo $info['version_code'], "\t", $info['version_name'], "\t", $info['package'], "\n";

if ($expect && $info['package'] !== ANDROID_PACKAGE) {
    fwrite(STDERR, "نامِ بسته «{$info['package']}» است، نه «" . ANDROID_PACKAGE . "».\n");
    exit(2);
}
exit(0);
