<?php
/**
 * تستِ «نوارِ پایین و کیبوردِ مجازی».
 *
 * ⛔ چرا این تست وجود دارد: کاربر گزارش کرد «گاهی منوی پایین می‌رود
 *    وسطِ صفحه». علتش CSS نبود، کیبوردِ مجازی بود — `position: fixed`
 *    نسبت به viewport **چیدمان** جای می‌گیرد و کیبورد آن را کوچک
 *    نمی‌کند (فقط viewport دیداری کوچک می‌شود و صفحه اسکرول می‌شود تا
 *    فیلد دیده شود)، پس کفِ چیدمان جایی وسطِ صفحه‌ی دیداری می‌افتد و
 *    نوار همان‌جا روی محتوا شناور می‌ماند. در مرورگرِ دسکتاپ اصلاً
 *    بازتولید نمی‌شود.
 *
 * ⛔ و رفعش خودش می‌توانست یک خرابیِ تازه بسازد: اگر «فوکوس» را بدونِ
 *    صافیِ نوع بشمریم، تپ روی هر کلیدِ `.switch` (که یک چک‌باکسِ پنهان
 *    است) نوار را ناپدید می‌کند. `window.kbNeedsKeyboard()` تنها جای
 *    این تصمیم است و اینجا **رفتارش** سنجیده می‌شود — قاعده ۳۳ در
 *    `test_api_contract.php` فقط *شکل* را می‌بیند و جهشِ «همیشه true»
 *    از زیرش رد شد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

T::group('کیبوردِ مجازی و نوارِ پایین');

exec('command -v node 2>/dev/null', $o, $rc);
if ($rc !== 0) {
    T::skip('صافیِ نوعِ فیلد', 'node نصب نیست');
    exit(T::report());
}

/** هر قلم: [برچسب، عنصرِ ساختگی، انتظار] */
$cases = [
    ['فیلدِ متنی کیبورد می‌خواهد',        ['tagName' => 'INPUT', 'type' => 'text'],     true],
    ['فیلدِ بی‌نوع هم متنی است',           ['tagName' => 'INPUT'],                       true],
    ['مبلغ (number) کیبورد می‌خواهد',     ['tagName' => 'INPUT', 'type' => 'number'],   true],
    ['جست‌وجو کیبورد می‌خواهد',            ['tagName' => 'INPUT', 'type' => 'search'],   true],
    ['رمز کیبورد می‌خواهد',                ['tagName' => 'INPUT', 'type' => 'password'], true],
    ['یادداشت (textarea) کیبورد می‌خواهد', ['tagName' => 'TEXTAREA'],                    true],
    ['نامِ تگ با حروفِ کوچک هم شناخته شود', ['tagName' => 'textarea'],                   true],
    ['contenteditable کیبورد می‌خواهد',   ['tagName' => 'DIV', 'isContentEditable' => true], true],

    // ⛔ این چهار تا قلبِ ماجرا هستند: فوکوس می‌گیرند بدونِ کیبورد.
    ['کلیدِ `.switch` (چک‌باکس) نه',       ['tagName' => 'INPUT', 'type' => 'checkbox'], false],
    ['رادیو نه',                           ['tagName' => 'INPUT', 'type' => 'radio'],    false],
    ['انتخابگرِ رنگ نه',                   ['tagName' => 'INPUT', 'type' => 'color'],    false],
    ['دکمه نه',                            ['tagName' => 'BUTTON'],                      false],
    ['دکمه‌ی submit نه',                   ['tagName' => 'INPUT', 'type' => 'submit'],   false],
    ['فایل نه',                            ['tagName' => 'INPUT', 'type' => 'file'],     false],

    // `<select>` با انتخابگرِ خودمان باز می‌شود، نه با کیبورد.
    ['`<select>` نه',                      ['tagName' => 'SELECT'],                      false],

    // ⚠ بدونِ این نگهبان، `focusout` روی صفحه‌ی بی‌فوکوس خطا می‌داد.
    ['ورودیِ تهی نه (بدونِ خطا)',          null,                                         false],
];

$payload = json_encode(array_map(static fn($c) => $c[1], $cases), JSON_UNESCAPED_UNICODE);

$dump = __DIR__ . '/kb_js_dump.js';
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('node ' . escapeshellarg($dump), $desc, $pipes);

if (!is_resource($proc)) {
    T::skip('صافیِ نوعِ فیلد', 'اجرای node ممکن نشد');
    exit(T::report());
}

fwrite($pipes[0], $payload);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    T::fail('اجرای `kbNeedsKeyboard` در node', trim($err) !== '' ? trim($err) : "کد خروج {$code}");
    exit(T::report());
}

$got = json_decode($out, true);
if (!is_array($got) || count($got) !== count($cases)) {
    T::fail('پاسخِ node', 'خروجی خوانده نشد: ' . substr((string)$out, 0, 200));
    exit(T::report());
}

foreach ($cases as $i => [$label, , $want]) {
    T::same($want, $got[$i], $label);
}

exit(T::report());
