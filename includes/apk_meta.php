<?php
/**
 * خواندنِ `versionCode` / `versionName` / `package` از **خودِ فایلِ APK**.
 *
 * ⛔ **چرا از خودِ فایل، نه از یک عددِ دستی یا متنِ Release.** نوارِ
 *    «نسخه‌ی تازه آماده است» روی مقایسه‌ی همین عدد با نسخه‌ی نصب‌شده
 *    سوار است. اگر عدد از جای دیگری بیاید (یک فایلِ کنارِ APK، یا متنِ
 *    توضیحِ Release) دیر یا زود با فایلی که واقعاً دانلود می‌شود
 *    نمی‌خواند و خرابی **بی‌صداست**: یا کاربرِ به‌روز هر روز پیامِ
 *    «به‌روزرسانی کن» می‌گیرد (و بعد از نصب هم می‌گیرد، چون فایل همان
 *    قدیمی بود)، یا کاربرِ عقب‌مانده هرگز نمی‌گیرد.
 *
 * ⛔ **بی‌افزونه‌ی `zip`.** پیش‌نیازهای این پروژه `zlib` را دارند نه
 *    `zip`، پس فهرستِ مرکزیِ zip دستی خوانده و عضو با `gzinflate()` باز
 *    می‌شود. APK همان zip است؛ `AndroidManifest.xml` داخلش XMLِ دودوییِ
 *    اندروید (AXML) است که اینجا تجزیه می‌شود.
 *
 * ⚠ این تجزیه‌گر در ساختِ گیت‌هاب **روی APKِ واقعیِ همان ساخت** با
 *   `aapt2` سنجیده می‌شود (`.github/workflows/android.yml`) — یعنی اگر
 *   روزی قالبِ خروجیِ aapt2 عوض شود، ساخت قرمز می‌شود نه نوار.
 *
 * هرگز استثنا نمی‌دهد: فایلِ خراب یا ناشناخته `null` است، و `null` یعنی
 * «نوارِ به‌روزرسانی نیاید» — سمتِ امنِ خطا.
 */

/**
 * نامِ بسته‌ی اپِ ما — **مصرف‌کننده**ی `applicationId` در
 * `mobile/app/build.gradle.kts`، نه مرجعِ دوم؛ قاعده ۱۲ یکی بودنشان را
 * می‌سنجد. APKی با نامِ دیگر روی دامنه یعنی «به‌روزرسانی» یک اپِ **دیگر**
 * کنارِ اپِ کاربر نصب می‌کند.
 */
const ANDROID_PACKAGE = 'ir.stland.hesabland';

/** سقفِ حجمِ AndroidManifest.xml باز‌شده — فایلِ واقعی چند کیلوبایت است. */
const APK_MANIFEST_MAX = 2 * 1024 * 1024;

/**
 * @return array{version_code:int, version_name:string, package:string}|null
 */
function apkManifestInfo(string $path): ?array
{
    try {
        $xml = apkZipEntry($path, 'AndroidManifest.xml');
        if ($xml === null) { return null; }
        return axmlManifestAttrs($xml);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * یک عضوِ zip را بی‌افزونه‌ی `zip` برمی‌گرداند.
 *
 * ⚠ از **فهرستِ مرکزی** خوانده می‌شود، نه از هدرهای محلی: APKِ امضای v2/v3
 *   بلوکِ امضا را بینِ داده‌ها و فهرستِ مرکزی می‌گذارد، و هدرِ محلی
 *   ممکن است اندازه‌ها را صفر گذاشته باشد (بیتِ ۳ — data descriptor).
 */
function apkZipEntry(string $path, string $want): ?string
{
    if (!is_file($path) || !is_readable($path)) { return null; }
    $size = filesize($path);
    if ($size === false || $size < 22) { return null; }

    $fh = fopen($path, 'rb');
    if ($fh === false) { return null; }
    try {
        // EOCD در ۶۵۵۵۷ بایتِ آخر است (۲۲ + کامنتِ حداکثر ۶۵۵۳۵).
        $tailLen = (int)min($size, 65557);
        fseek($fh, $size - $tailLen);
        $tail = (string)fread($fh, $tailLen);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) - $eocd < 22) { return null; }

        $e = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoff', substr($tail, $eocd + 4, 16));
        if (!$e || $e['cdoff'] + $e['cdsize'] > $size) { return null; }

        fseek($fh, $e['cdoff']);
        $cd = (string)fread($fh, $e['cdsize']);
        $p  = 0;
        for ($i = 0; $i < $e['total'] && $p + 46 <= strlen($cd); $i++) {
            if (substr($cd, $p, 4) !== "PK\x01\x02") { return null; }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voff',
                        substr($cd, $p + 4, 42));
            $name = substr($cd, $p + 46, $h['nlen']);
            $p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if ($name !== $want) { continue; }

            if ($h['usize'] > APK_MANIFEST_MAX || $h['csize'] > APK_MANIFEST_MAX) { return null; }

            fseek($fh, $h['off']);
            $lh = (string)fread($fh, 30);
            if (substr($lh, 0, 4) !== "PK\x03\x04") { return null; }
            $l = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr($lh, 4, 26));
            fseek($fh, $h['off'] + 30 + $l['nlen'] + $l['xlen']);
            $raw = $h['csize'] > 0 ? (string)fread($fh, $h['csize']) : '';

            if ($h['method'] === 0) {
                $data = $raw;
            } elseif ($h['method'] === 8) {
                $data = @gzinflate($raw);
                if ($data === false) { return null; }
            } else {
                return null;
            }
            // ⚠ CRC سنجیده می‌شود: عضوِ بریده (دانلودِ نصفه) باز می‌شود ولی
            //   عددی که از آن خوانده شود بی‌معناست.
            if (strlen($data) !== $h['usize'] || (crc32($data) & 0xFFFFFFFF) !== $h['crc']) { return null; }
            return $data;
        }
        return null;
    } finally {
        fclose($fh);
    }
}

/**
 * XMLِ دودوییِ اندروید → ویژگی‌های عنصرِ ریشه‌ی `<manifest>`.
 *
 * ⚠ نامِ ویژگی به **دو** راه شناخته می‌شود: رشته‌ی نام (`versionCode`) و
 *   شناسه‌ی منبعِ اندروید در نقشه‌ی منابع (`0x0101021b`). aapt2 هر دو را
 *   می‌نویسد، ولی ابزارهای کوچک‌کننده گاهی رشته‌ی نامِ ویژگی را خالی
 *   می‌کنند و فقط شناسه می‌ماند.
 *
 * @return array{version_code:int, version_name:string, package:string}|null
 */
function axmlManifestAttrs(string $b): ?array
{
    $len = strlen($b);
    if ($len < 8) { return null; }
    $u16 = static fn(int $o): int => $o + 2 <= $len ? unpack('v', $b, $o)[1] : -1;
    $u32 = static fn(int $o): int => $o + 4 <= $len ? unpack('V', $b, $o)[1] : -1;

    if ($u16(0) !== 0x0003) { return null; }          // RES_XML_TYPE

    $strings = [];
    $resIds  = [];
    $o = $u16(2);                                      // headerSize
    while ($o + 8 <= $len) {
        $type  = $u16($o);
        $hsize = $u16($o + 2);
        $csize = $u32($o + 4);
        if ($csize < 8 || $o + $csize > $len) { return null; }

        if ($type === 0x0001) {                         // RES_STRING_POOL_TYPE
            $strings = axmlStringPool(substr($b, $o, $csize));
            if ($strings === null) { return null; }
        } elseif ($type === 0x0180) {                   // RES_XML_RESOURCE_MAP_TYPE
            for ($k = $o + $hsize; $k + 4 <= $o + $csize; $k += 4) { $resIds[] = $u32($k); }
        } elseif ($type === 0x0102) {                   // RES_XML_START_ELEMENT_TYPE
            $ext     = $o + $hsize;
            $nameIdx = $u32($ext + 4);
            if (($strings[$nameIdx] ?? '') !== 'manifest') { return null; }   // ریشه همیشه manifest است
            $attrStart = $u16($ext + 8);
            $attrSize  = $u16($ext + 10);
            $attrCount = $u16($ext + 12);
            if ($attrSize < 20) { return null; }

            $out = ['version_code' => 0, 'version_name' => '', 'package' => ''];
            for ($a = 0; $a < $attrCount; $a++) {
                $ao = $ext + $attrStart + $a * $attrSize;
                if ($ao + 20 > $o + $csize) { return null; }
                $nIdx  = $u32($ao + 4);
                $raw   = $u32($ao + 8);
                $dtype = ord($b[$ao + 15]);
                $data  = $u32($ao + 16);
                $aname = $strings[$nIdx] ?? '';
                $rid   = $resIds[$nIdx] ?? 0;

                $str = ($raw !== 0xFFFFFFFF && isset($strings[$raw])) ? $strings[$raw]
                     : ($dtype === 0x03 && isset($strings[$data]) ? $strings[$data] : null);

                if ($rid === 0x0101021b || $aname === 'versionCode') {
                    // ⚠ عددِ صحیح (0x10 دهدهی، 0x11 هگز). نسخه‌ی رشته‌ای را
                    //   aapt2 نمی‌سازد ولی اگر بود، فقط رقم پذیرفته می‌شود.
                    if ($dtype === 0x10 || $dtype === 0x11) {
                        $out['version_code'] = $data;
                    } elseif ($str !== null && preg_match('/^\d{1,9}$/', $str)) {
                        $out['version_code'] = (int)$str;
                    }
                } elseif ($rid === 0x0101021c || $aname === 'versionName') {
                    $out['version_name'] = (string)($str ?? '');
                } elseif ($aname === 'package' && $str !== null) {
                    $out['package'] = $str;
                }
            }
            return $out['version_code'] > 0 && $out['package'] !== '' ? $out : null;
        }
        $o += $csize;
    }
    return null;
}

/** @return list<string>|null */
function axmlStringPool(string $c): ?array
{
    $n = strlen($c);
    if ($n < 28) { return null; }
    $h = unpack('vtype/vhsize/Vsize/Vcount/Vstyles/Vflags/Vstart/Vsstart', $c);
    if (!$h || $h['count'] > 100000) { return null; }
    $utf8 = ($h['flags'] & 0x100) !== 0;
    $out  = [];
    for ($i = 0; $i < $h['count']; $i++) {
        $po = $h['hsize'] + $i * 4;
        if ($po + 4 > $n) { return null; }
        $so = $h['start'] + unpack('V', $c, $po)[1];
        if ($so >= $n) { return null; }
        if ($utf8) {
            // دو طول: اول به واحدِ UTF-16 (کنار گذاشته می‌شود)، بعد به بایت.
            $x = ord($c[$so]);
            $so += ($x & 0x80) ? 2 : 1;
            if ($so + 1 > $n) { return null; }
            $bl = ord($c[$so]);
            if ($bl & 0x80) {
                if ($so + 2 > $n) { return null; }
                $bl = (($bl & 0x7F) << 8) | ord($c[$so + 1]);
                $so += 2;
            } else {
                $so += 1;
            }
            if ($so + $bl > $n) { return null; }
            $out[] = substr($c, $so, $bl);
        } else {
            if ($so + 2 > $n) { return null; }
            $cl = unpack('v', $c, $so)[1];
            $so += 2;
            if ($cl & 0x8000) {
                if ($so + 2 > $n) { return null; }
                $cl = (($cl & 0x7FFF) << 16) | unpack('v', $c, $so)[1];
                $so += 2;
            }
            if ($so + $cl * 2 > $n) { return null; }
            $out[] = axmlUtf16(substr($c, $so, $cl * 2));
        }
    }
    return $out;
}

/**
 * UTF-16LE → UTF-8 بی‌وابستگی به `mbstring`/`iconv`: این فایل در ساختِ
 * گیت‌هاب هم اجرا می‌شود و آنجا نصب بودنِ افزونه‌ها را فرض نمی‌کنیم.
 */
function axmlUtf16(string $b): string
{
    $out = '';
    $n = strlen($b) - 1;
    for ($i = 0; $i < $n; $i += 2) {
        $u = ord($b[$i]) | (ord($b[$i + 1]) << 8);
        if ($u >= 0xD800 && $u <= 0xDBFF && $i + 3 < strlen($b)) {
            $lo = ord($b[$i + 2]) | (ord($b[$i + 3]) << 8);
            if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                $u = 0x10000 + (($u - 0xD800) << 10) + ($lo - 0xDC00);
                $i += 2;
            }
        }
        if ($u < 0x80)        { $out .= chr($u); }
        elseif ($u < 0x800)   { $out .= chr(0xC0 | ($u >> 6)) . chr(0x80 | ($u & 0x3F)); }
        elseif ($u < 0x10000) { $out .= chr(0xE0 | ($u >> 12)) . chr(0x80 | (($u >> 6) & 0x3F)) . chr(0x80 | ($u & 0x3F)); }
        else                  { $out .= chr(0xF0 | ($u >> 18)) . chr(0x80 | (($u >> 12) & 0x3F))
                                      . chr(0x80 | (($u >> 6) & 0x3F)) . chr(0x80 | ($u & 0x3F)); }
    }
    return $out;
}
