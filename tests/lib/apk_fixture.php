<?php
/**
 * ساختنِ APKِ ساختگی برای تستِ `includes/apk_meta.php`.
 *
 * ⚠ نمونه‌ی ساختگی به‌تنهایی کافی نیست — تجزیه‌گری که روی خروجیِ نویسنده‌ی
 *   خودمان سبز شود ممکن است همان اشتباهِ نویسنده را داشته باشد. دو سدِ
 *   دیگر هم هست: این نویسنده هنگامِ ساخت با `pyaxmlparser` (تجزیه‌گرِ
 *   مستقل) سنجیده شد، و ساختِ گیت‌هاب خواننده‌ی سایت را روی APKِ واقعیِ
 *   همان ساخت با `aapt2` مقایسه می‌کند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * @param array{code:int, name:string, package:string, hex?:bool} $m
 * @param bool $utf8  استخرِ رشته‌ی UTF-8 (aapt2) یا UTF-16 (aaptِ قدیمی)
 * @param bool $names نامِ ویژگی‌ها در رشته‌ها هم باشد، یا فقط شناسه‌ی منبع
 */
function axmlFixture(array $m, bool $utf8 = false, bool $names = true): string
{
    $strings = [
        $names ? 'versionCode' : '', $names ? 'versionName' : '', 'package', 'manifest',
        (string)$m['name'], (string)$m['package'],
        'android', 'http://schemas.android.com/apk/res/android',
    ];
    [$iName, $iPkg, $iPre, $iUri, $iMan] = [4, 5, 6, 7, 3];

    // استخرِ رشته
    $data = '';
    $offs = [];
    foreach ($strings as $s) {
        $offs[] = strlen($data);
        if ($utf8) {
            $u16 = strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2;
            $data .= chr($u16) . chr(strlen($s)) . $s . "\0";
        } else {
            $e = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
            $data .= pack('v', strlen($e) / 2) . $e . "\0\0";
        }
    }
    while (strlen($data) % 4) { $data .= "\0"; }
    $count = count($strings);
    $start = 28 + 4 * $count;
    $pool  = pack('vvVVVVVV', 0x0001, 28, $start + strlen($data), $count, 0, $utf8 ? 0x100 : 0, $start, 0);
    foreach ($offs as $o) { $pool .= pack('V', $o); }
    $pool .= $data;

    $resMap = pack('vvV', 0x0180, 8, 16) . pack('VV', 0x0101021b, 0x0101021c);
    $ns     = pack('vvVVVVV', 0x0100, 16, 24, 1, 0xFFFFFFFF, $iPre, $iUri);

    $attr = static fn(int $ns, int $name, int $raw, int $type, int $val): string
        => pack('VVVvCCV', $ns, $name, $raw, 8, 0, $type, $val);
    $attrs = $attr($iUri, 0, 0xFFFFFFFF, !empty($m['hex']) ? 0x11 : 0x10, (int)$m['code'])
           . $attr($iUri, 1, $iName, 0x03, $iName)
           . $attr(0xFFFFFFFF, 2, $iPkg, 0x03, $iPkg);
    $el = pack('vvVVV', 0x0102, 16, 16 + 20 + strlen($attrs), 2, 0xFFFFFFFF)
        . pack('VVvvvvvv', 0xFFFFFFFF, $iMan, 20, 20, 3, 0, 0, 0) . $attrs;
    $end   = pack('vvVVVVV', 0x0103, 16, 24, 3, 0xFFFFFFFF, 0xFFFFFFFF, $iMan);
    $endNs = pack('vvVVVVV', 0x0101, 16, 24, 3, 0xFFFFFFFF, $iPre, $iUri);

    $body = $pool . $resMap . $ns . $el . $end . $endNs;
    return pack('vvV', 0x0003, 8, 8 + strlen($body)) . $body;
}

/**
 * یک zip با عضوهای داده‌شده. `$method` برای AndroidManifest.xml است
 * (۸ = deflate، ۰ = stored)؛ عضوهای دیگر همیشه deflate.
 *
 * @param array<string,string> $entries
 */
function zipFixture(array $entries, int $method = 8): string
{
    $out = '';
    $cd  = '';
    foreach ($entries as $name => $data) {
        $m    = $name === 'AndroidManifest.xml' ? $method : 8;
        $comp = $m === 8 ? gzdeflate($data) : $data;
        $crc  = crc32($data) & 0xFFFFFFFF;
        $off  = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $m, 0, 0, $crc, strlen($comp), strlen($data), strlen($name), 0)
              . $name . $comp;
        $cd  .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $m, 0, 0, $crc, strlen($comp), strlen($data),
                     strlen($name), 0, 0, 0, 0, 0, $off) . $name;
    }
    $n = count($entries);
    return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}

/** @param array{code:int, name:string, package:string, hex?:bool} $m */
function apkFixture(array $m, bool $utf8 = false, bool $names = true, int $method = 8): string
{
    return zipFixture([
        'classes.dex'         => str_repeat("dex\n", 64),
        'AndroidManifest.xml' => axmlFixture($m, $utf8, $names),
        'resources.arsc'      => str_repeat("\x02\x00", 50),
    ], $method);
}
