<?php
/**
 * تست کد مرده و کد خراب.
 *
 * سه چیز را می‌گیرد که `php -l` هیچ‌کدام را نمی‌بیند:
 *
 *   ۱. **متغیری که هرگز مقدار نگرفته ولی استفاده شده.**
 *      این دقیقاً همان باگی است که دو بار زد: `api/add_transaction.php` و
 *      `api/update_transaction.php` تابع `categoryScopeParams($userId)`
 *      را صدا می‌زدند در حالی که `$userId` در آن فایل‌ها تعریف نشده بود.
 *      نتیجه خطای کشنده‌ی PHP و پاسخ ۵۰۰ بود، و در مرورگر فقط
 *      «خطا در ارتباط با سرور».
 *
 *   ۲. **تابعی که تعریف شده ولی هیچ‌جا صدا زده نمی‌شود** — یا فراموش
 *      شده، یا اسمش عوض شده و نسخه‌ی قدیمی مانده.
 *
 *   ۳. **اندپوینتی در api/ که هیچ‌جای اپ صدایش نمی‌زند.**
 *
 * ۲ و ۳ «شکست» نیستند بلکه گزارش می‌شوند، چون کد مرده خطر امنیتی
 * فوری نیست و گاهی عمدی است. فقط ۱ شکست می‌دهد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

/** فایل‌های خودِ اپ — بدون tests/ و بدون کتابخانه‌ی بیرونی. */
$appFiles = array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/api/*.php') ?: [],
    glob($root . '/includes/*.php') ?: [],
    glob($root . '/admin/*.php') ?: []
);
sort($appFiles);

// ===============================================================
T::group('متغیرِ استفاده‌شده ولی هرگز مقدارنگرفته');

/**
 * متغیرهایی که در فایل *خوانده* می‌شوند ولی هیچ‌جای همان فایل مقدار
 * نمی‌گیرند.
 *
 * عمداً ساده و محافظه‌کارانه است: دامنه‌ی تابع را جدا نمی‌کند. یعنی
 * اگر متغیری در هر جای فایل مقدار بگیرد، پذیرفته می‌شود. با همین هم
 * آن باگ گرفته می‌شد (`$userId` در آن دو فایل *اصلاً* وجود نداشت)، و
 * در عوض هشدار الکی نمی‌دهد.
 */
/**
 * آیا این متغیر داخل *فهرست پارامترِ* یک تعریف است (نه آرگومانِ یک
 * فراخوانی)؟
 *
 * پرانتزِ دربرگیرنده را پیدا می‌کند و به توکنِ پیش از آن نگاه می‌کند:
 *   function f($x)   fn($x)   catch (E $x)   function ($x) use ($y)
 * در برابر:
 *   f($x)   $obj->m($x)   Foo::bar($x)
 */
function isParamList(array $tokens, int $at): bool
{
    // به عقب تا پرانتزِ بازِ همین گروه
    $depth = 0;
    for ($j = $at - 1; $j >= 0; $j--) {
        $tk = $tokens[$j];
        if ($tk === ')') { $depth++; continue; }
        if ($tk === '(') {
            if ($depth === 0) { break; }
            $depth--; continue;
        }
    }
    if ($j < 0) { return false; }

    // توکنِ معنادار پیش از پرانتز
    $k = $j - 1;
    while ($k >= 0 && is_array($tokens[$k])
           && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $k--; }
    $before = $tokens[$k] ?? null;

    if (is_array($before) && in_array($before[0], [T_FUNCTION, T_FN, T_CATCH], true)) {
        return true;                       // function ($x)  /  fn($x)  /  catch (…)
    }
    if (is_array($before) && $before[0] === T_STRING) {
        // نامِ تابع — فقط اگر خودش بعد از `function` باشد یعنی تعریف است
        $m = $k - 1;
        while ($m >= 0 && is_array($tokens[$m])
               && in_array($tokens[$m][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $m--; }
        return is_array($tokens[$m] ?? null) && $tokens[$m][0] === T_FUNCTION;
    }
    // `use ($x)` بعد از closure: متغیر از دامنه‌ی بیرونی می‌آید، پس
    // «استفاده» است نه تخصیص — و در همان فایل باید جایی مقدار گرفته باشد.
    return false;
}

function unassignedVars(string $file): array
{
    $tokens = token_get_all((string)file_get_contents($file));
    $n = count($tokens);

    // چیزهایی که خودِ PHP تعریفشان می‌کند
    $builtin = [
        'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE',
        '_SESSION', '_REQUEST', '_ENV', 'this', 'argv', 'argc',
        'http_response_header', 'php_errormsg',
    ];

    $assigned = [];
    $used     = [];

    // ---- پیش‌پویش: تخصیصِ تجزیه‌ای ----
    // `[$a, $b] = f()` و `list($a, $b) = f()` هم تخصیص‌اند. بدون این،
    // تحلیلگر برای هر کدام هشدار الکی می‌داد — و ابزاری که الکی هشدار
    // بدهد بعد از دو بار نادیده گرفته می‌شود و دیگر چیزی نمی‌گیرد.
    $destructured = [];
    for ($i = 0; $i < $n; $i++) {
        $isOpen = ($tokens[$i] === '[')
               || (is_array($tokens[$i]) && $tokens[$i][0] === T_LIST);
        if (!$isOpen) { continue; }

        // پیدا کردن پرانتز/براکتِ متناظر
        $start = $i;
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_LIST) {
            while ($start < $n && $tokens[$start] !== '(') { $start++; }
            if ($start >= $n) { continue; }
        }
        $open  = $tokens[$start];
        $close = $open === '[' ? ']' : ')';
        $depth = 0; $end = $start;
        for ($j = $start; $j < $n; $j++) {
            if ($tokens[$j] === $open)  { $depth++; }
            if ($tokens[$j] === $close) { $depth--; if ($depth === 0) { $end = $j; break; } }
        }
        if ($end <= $start) { continue; }

        // بعدش «=» است (و نه «==» یا «=>»)؟
        $k = $end + 1;
        while ($k < $n && is_array($tokens[$k])
               && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $k++; }
        if (($tokens[$k] ?? null) !== '=') { continue; }

        for ($j = $start; $j < $end; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                $destructured[$j] = true;
            }
        }
    }

    // ---- پیش‌پویش: پارامترهای خروجیِ ارجاعیِ توابع داخلی PHP ----
    // `preg_match($re, $s, $m)` متغیر `$m` را *می‌سازد*، ولی از روی نحو
    // شبیه یک آرگومانِ معمولی است. بدون این، تحلیلگر برایش هشدار الکی
    // می‌داد. فقط توابعی که واقعاً در این پروژه استفاده می‌شوند.
    // قالب: نامِ تابع => ایندکسِ آرگومانی که ارجاعی است (از صفر)
    $refFuncs = [
        'preg_match'           => 2,
        'preg_match_all'       => 2,
        'preg_replace'         => 4,
        'str_replace'          => 3,
        'fsockopen'            => 2,   // $errno و بعدش $errstr
        'stream_socket_client' => 1,
        'similar_text'         => 2,
        'parse_str'            => 1,
    ];
    $byRefArg = [];
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) { continue; }
        $fn = strtolower($tokens[$i][1]);
        if (!isset($refFuncs[$fn])) { continue; }

        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (($tokens[$j] ?? null) !== '(') { continue; }

        // شمردنِ آرگومان‌ها در عمق ۱ و علامت زدنِ آن‌هایی که ارجاعی‌اند
        $depth = 0; $arg = 0;
        for ($k = $j; $k < $n; $k++) {
            $tk = $tokens[$k];
            if ($tk === '(' || $tk === '[') { $depth++; continue; }
            if ($tk === ')' || $tk === ']') { $depth--; if ($depth === 0) { break; } continue; }
            if ($depth === 1 && $tk === ',') { $arg++; continue; }
            if ($depth === 1 && $arg >= $refFuncs[$fn]
                && is_array($tk) && $tk[0] === T_VARIABLE) {
                $byRefArg[$k] = true;
            }
        }
    }

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE) { continue; }

        $name = ltrim($t[1], '$');
        if (in_array($name, $builtin, true)) { continue; }

        if (isset($destructured[$i])) { $assigned[$name] = true; continue; }
        if (isset($byRefArg[$i]))     { $assigned[$name] = true; continue; }

        // ویژگیِ ایستای یک کلاس (`Mailer::$lastError`) متغیرِ محلی نیست.
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p])
               && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $p--; }
        if (is_array($tokens[$p] ?? null) && $tokens[$p][0] === T_DOUBLE_COLON) { continue; }

        // ---- آیا اینجا مقدار می‌گیرد؟ ----
        // شکل‌های پذیرفته: $x =، $x[..] =، $x .= و بقیه‌ی عملگرهای ترکیبی،
        // $x++/--، foreach (... as $x)، catch (E $x)، پارامتر تابع،
        // global $x، static $x، list($x) = / [$x] =
        $isAssign = false;

        // به جلو نگاه می‌کنیم و از [..] و ->prop و ::prop رد می‌شویم
        $j = $i + 1;
        $depth = 0;
        while ($j < $n) {
            $tk = $tokens[$j];
            if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; continue; }
            if ($tk === '[') { $depth++; $j++; continue; }
            if ($tk === ']') { $depth--; $j++; continue; }
            if ($depth > 0) { $j++; continue; }
            break;
        }
        $next = $tokens[$j] ?? null;

        if ($next === '=') {
            $isAssign = true;
        } elseif (is_array($next) && in_array($next[0], [
            T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL,
            T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
            T_COALESCE_EQUAL, T_POW_EQUAL, T_INC, T_DEC,
        ], true)) {
            $isAssign = true;
        }

        // ---- به عقب نگاه می‌کنیم: as / catch / global / static / پارامتر ----
        if (!$isAssign) {
            $k = $i - 1;
            while ($k >= 0 && is_array($tokens[$k])
                   && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $k--; }
            $prev = $tokens[$k] ?? null;

            if (is_array($prev) && in_array($prev[0], [T_AS, T_GLOBAL, T_STATIC, T_DOUBLE_ARROW], true)) {
                $isAssign = true;
            } elseif (is_array($prev) && in_array($prev[0], [T_STRING, T_ARRAY, T_CALLABLE, T_NS_SEPARATOR], true)) {
                // نوعِ پارامتر یا نوعِ catch:  function f(int $x)  /  catch (PDOException $e)
                $isAssign = true;
            } elseif ($prev === '?' || $prev === '&' || $prev === '|'
                      || (is_array($prev) && defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
                          && $prev[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)) {
                // ?int $x  /  &$ref  /  foreach (… as &$x)  /  catch (A|B $e)
                //
                // از PHP 8.1 توکنِ «&» پیش از متغیر جدا شده
                // (T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) و مقایسه‌ی رشته‌ای
                // دیگر نمی‌گیردش — همین باعث هشدار الکی روی
                // `foreach ([…] as &$bucketSet)` شد.
                $isAssign = true;
            } elseif ($prev === '(' || $prev === ',') {
                // ⚠ اینجا نقطه‌ی حساس است.
                //
                // نسخه‌ی اول هر `(` را «پارامترِ تابع» فرض می‌کرد و
                // محافظه‌کارانه می‌پذیرفت. نتیجه این شد که
                // `categoryScopeParams($userId)` — یعنی دقیقاً همان باگی
                // که این تست برایش نوشته شده — «تخصیص» حساب می‌شد و تست
                // سبز می‌ماند. با آزمون جهش معلوم شد.
                //
                // پس باید *تعریف* از *فراخوانی* جدا شود: پرانتزِ دربرگیرنده
                // را پیدا می‌کنیم و می‌بینیم مالِ function/fn/catch است یا نه.
                $isAssign = isParamList($tokens, $i);
            }
        }

        if ($isAssign) { $assigned[$name] = true; }
        else           { $used[$name] = ($used[$name] ?? 0) + 1; }
    }

    $bad = [];
    foreach ($used as $name => $count) {
        if (!isset($assigned[$name])) { $bad[] = '$' . $name; }
    }
    return $bad;
}

$bad = [];
foreach ($appFiles as $f) {
    foreach (unassignedVars($f) as $v) {
        $bad[] = str_replace($root . '/', '', $f) . " — {$v} هرگز مقدار نمی‌گیرد";
    }
}
T::bulk(count($appFiles), $bad, 'هیچ متغیری بدون مقدار گرفتن استفاده نشده');

// ===============================================================
T::group('گزارش کد مرده (شکست نیست، فقط اطلاع)');

// ---- توابعی که هیچ‌جا صدا زده نمی‌شوند ----
$allSource = '';
foreach (array_merge($appFiles, glob($root . '/assets/js/app.js') ?: []) as $f) {
    $allSource .= "\n" . (string)file_get_contents($f);
}

$defined = [];
foreach ($appFiles as $f) {
    if (preg_match_all('/^function\s+([A-Za-z_]\w*)\s*\(/m', (string)file_get_contents($f), $m)) {
        foreach ($m[1] as $fn) { $defined[$fn] = str_replace($root . '/', '', $f); }
    }
}

$unused = [];
foreach ($defined as $fn => $where) {
    // یک بار برای خودِ تعریف، پس «استفاده» یعنی بیش از یک بار
    if (preg_match_all('/\b' . preg_quote($fn, '/') . '\s*\(/', $allSource) <= 1) {
        $unused[] = "{$fn}()  در {$where}";
    }
}
T::ok(true, 'توابع بررسی شدند', count($defined) . ' تابع، ' . count($unused) . ' بی‌استفاده');
foreach ($unused as $u) { printf("        \033[0;90m· %s\033[0m\n", $u); }

// ---- اندپوینت‌هایی که هیچ‌جا صدا زده نمی‌شوند ----
$jsAndPhp = '';
// api/ هم باید اسکن شود: یک اندپوینت ممکن است از اندپوینتِ دیگری
// لینک شود — `view_attachment.php` از `transaction_attachments.php`
// می‌آید و بدون این، «یتیم» گزارش می‌شد.
foreach (array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/admin/*.php') ?: [],
    glob($root . '/includes/*.php') ?: [],
    glob($root . '/api/*.php') ?: [],
    glob($root . '/assets/js/*.js') ?: []
) as $f) {
    $jsAndPhp .= "\n" . (string)file_get_contents($f);
}

$orphanApis = [];
foreach (glob($root . '/api/*.php') ?: [] as $f) {
    $name = basename($f);
    if (strpos($jsAndPhp, $name) === false) { $orphanApis[] = $name; }
}
T::ok(true, 'اندپوینت‌ها بررسی شدند',
    count(glob($root . '/api/*.php') ?: []) . ' اندپوینت، ' . count($orphanApis) . ' بدون فراخوان');
foreach ($orphanApis as $o) { printf("        \033[0;90m· %s\033[0m\n", $o); }

exit(T::report());
