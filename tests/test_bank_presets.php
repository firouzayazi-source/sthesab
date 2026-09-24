<?php
/**
 * تستِ فهرستِ بانک‌ها (`bankPresets()`) — بدونِ دیتابیس.
 *
 * ⛔ رنگِ کارتِ حساب از همین فهرست می‌آید (`bank_code` → رنگ)، پس هر
 *    رنگِ نامعتبر یعنی کارتی بی‌گرادیان، بی‌هیچ خطایی. و «سایر» باید آخر
 *    بماند، وگرنه در منوی انتخابِ بانک وسطِ فهرست می‌افتد.
 * ⛔ و هیچ فایلِ لوگوی بانکی در مخزن نیست — علامتِ تجاری است (CLAUDE.md،
 *    «حساب بانکی و نمای کارت»). کارت با رنگ و طرحِ خودمان ساخته می‌شود.
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

$logos = glob(__DIR__ . '/../assets/**/*bank*.{png,svg,jpg,webp}', GLOB_BRACE) ?: [];
T::same([], $logos, '⛔ هیچ فایلِ لوگوی بانکی در مخزن نیست');

exit(T::report());
