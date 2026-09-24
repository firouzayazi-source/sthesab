<?php
/**
 * تستِ فهرستِ بانک‌ها (`bankPresets()`) — بدونِ دیتابیس.
 *
 * ⛔ رنگِ کارتِ حساب از همین فهرست می‌آید (`bank_code` → رنگ)، پس هر
 *    رنگِ نامعتبر یعنی کارتی بی‌گرادیان، بی‌هیچ خطایی. و «سایر» باید آخر
 *    بماند، وگرنه در منوی انتخابِ بانک وسطِ فهرست می‌افتد.
 * ⛔ و هر بانک (جز «سایر») لوگوی رسمیِ خودش را در `assets/banks/` دارد —
 *    به خواستِ صریحِ مالکِ نصب، جای قاعده‌ی قبلیِ «بدونِ لوگو» را گرفت.
 *    لوگوی جامانده **بی‌صداست**: کارت بی‌لوگو رندر می‌شود و کسی نمی‌فهمد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/functions.php';

T::group('فهرستِ بانک‌ها');

$all = bankPresets();
T::ok(isset($all['blu']), 'بلوبانک در فهرست هست');
T::same('بلوبانک', bankPreset('blu')['name'] ?? null, 'نامِ بلوبانک درست است');
T::same('other', array_key_last($all), '«سایر» آخرین گزینه است');

$bad = [];
$names = [];
foreach ($all as $code => $b) {
    if (!preg_match('/^[a-z_]+$/', (string)$code)) { $bad[] = "کدِ نامعتبر: {$code}"; }
    if (count($b) !== 3) { $bad[] = "{$code}: سه مقدار ندارد"; continue; }
    foreach ([$b[1], $b[2]] as $c) {
        if (!preg_match('/^#[0-9a-f]{6}$/i', $c)) { $bad[] = "{$code}: رنگِ نامعتبر {$c}"; }
    }
    $names[] = $b[0];
}
T::bulk(count($all), $bad, 'هر بانک کدِ معتبر و دو رنگِ hex دارد');
T::same(count($names), count(array_unique($names)), 'نامِ تکراری در فهرست نیست');

T::group('لوگوی رسمیِ بانک‌ها');

if (!defined('APP_BASE_PATH')) { define('APP_BASE_PATH', ''); }
$dir = __DIR__ . '/../assets/banks/';
$missing = []; $unsafe = [];
foreach ($all as $code => $b) {
    if ($code === 'other') { continue; }
    $f = $dir . $code . '.svg';
    if (!is_file($f)) { $missing[] = "{$code}: فایلِ لوگو نیست"; continue; }
    $svg = (string)file_get_contents($f);
    /* ⛔ SVG می‌تواند اسکریپت داشته باشد. این فایل‌ها با `<img>` بار
       می‌شوند (که اسکریپت را اجرا نمی‌کند)، ولی اگر کسی روزی آن‌ها را
       درون‌خطی کند، همین بررسی جلوی یک XSSِ ماندگار را می‌گیرد. */
    if (!preg_match('/^<svg\b[^>]*\bviewBox=/', ltrim($svg))) { $unsafe[] = "{$code}: با <svg viewBox> شروع نمی‌شود"; }
    if (preg_match('/<script|\bon[a-z]+\s*=|javascript:|<foreignObject|href\s*=\s*"(?!#)/i', $svg)) {
        $unsafe[] = "{$code}: محتوای فعال یا ارجاعِ بیرونی دارد";
    }
    if (strlen($svg) > 60000) { $unsafe[] = "{$code}: بیش از ۶۰ کیلوبایت"; }
}
T::bulk(count($all) - 1, $missing, '⛔ هر بانک (جز «سایر») لوگوی خودش را دارد');
T::bulk(count($all) - 1, $unsafe, '⛔ هر لوگو یک SVGِ ایستا و کوچک است');

$orphans = [];
foreach (glob($dir . '*.svg') ?: [] as $f) {
    if (!isset($all[basename($f, '.svg')])) { $orphans[] = basename($f); }
}
T::same([], $orphans, 'هیچ فایلِ لوگوی بی‌صاحبی در پوشه نیست');

T::ok(str_starts_with((string)bankLogoUrl('melli'), '/assets/banks/melli.svg?v='),
    'bankLogoUrl() آدرسِ نسخه‌دار می‌دهد', 'بدونِ ?v= لوگوی عوض‌شده تا یک سال از کشِ immutable می‌آمد');
T::same(null, bankLogoUrl('other'), '«سایر» لوگو ندارد');
T::same(null, bankLogoUrl('../../config/config'), '⛔ کدِ ناشناخته هرگز به مسیرِ فایل نمی‌رسد');
T::same(null, bankLogoUrl(null), 'کدِ خالی → null');

exit(T::report());
