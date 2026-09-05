<?php
/**
 * خروجی کاملِ داده‌ی کاربر، به‌صورت یک فایل JSON.
 *
 * ⛔ POST است نه GET، با CSRF — با اینکه چیزی نمی‌نویسد. دلیلش این است
 *    که یک لینکِ GET را می‌شود در `<img src>` یا یک صفحه‌ی دیگر جاسازی
 *    کرد و مرورگرِ کاربرِ واردشده خودش صدایش می‌زند؛ اینجا خروجی
 *    **همه‌ی** داده‌ی اوست، پس ارزشِ سخت‌گیری را دارد.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';

Auth::initSession();

/**
 * ⛔ شکست نباید کاربر را در یک صفحه‌ی JSON خامِ بی‌راهِ‌برگشت رها کند.
 *
 *    این اندپوینت با یک فرمِ معمولی صدا زده می‌شود، نه با fetch؛ پس
 *    خروجیِ `jsonResponse` یعنی مرورگر به یک صفحه‌ی سفید با یک خطِ
 *    JSON می‌رود که نه دکمه‌ای دارد، نه منویی، و در اپِ نصب‌شده حتی
 *    دکمه‌ی بازگشت هم نیست. همان چیزی که کاربر گزارش کرد.
 *    حالا برمی‌گردد به `backup.php` با پیامِ روشن.
 */
function exportFail(string $message, int $status = 400): void
{
    // ⚠ `Csrf::isJsonRequest()` خصوصی است، پس همان شرط اینجا تکرار
    //   می‌شود: هر دو سرآیند لازم‌اند چون `fetch` های `app.js`
    //   `X-Requested-With` می‌فرستند ولی `Accept: application/json` نه.
    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
           || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        jsonResponse(['success' => false, 'message' => $message], $status);
    }
    redirectWithMessage('../backup.php', 'error', $message);
}

// ⚠ اینجا عمداً ۴۰۱ است، نه هدایت به صفحه‌ی ورود: قاعده‌ی `api/` این
//   است که هر اندپوینت بدونِ ورود ۴۰۱ بدهد، و `test_api_auth` هر ۵۹
//   تا را با هم می‌سنجد. کاربرِ واقعی هم به اینجا نمی‌رسد، چون
//   `backup.php` خودش `requireLogin()` دارد.
if (!Auth::isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exportFail('درخواست نامعتبر است.', 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

try {
    $data = exportUserData($userId);
} catch (Throwable $e) {
    error_log('Export Data Error: ' . $e->getMessage());
    exportFail('خروجی گرفته نشد. اگر تکرار شد به پشتیبانی خبر بدهید.', 500);
}

if (!$data) {
    exportFail('کاربر یافت نشد.', 404);
}

$json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

// نامِ فایل: فقط تاریخِ شمسیِ همان روز و پسوندِ اختصاصیِ برنامه —
// `1405-06-12.sthesab`. کوتاه است تا در فهرستِ دانلود خوانده شود.
//
// ⚠ ارقام **لاتین**اند: نامِ حاوی ارقامِ فارسی در سرآیندِ `filename=`
//   غیرمجاز است و مرورگر بی‌سروصدا کنارش می‌گذارد و فایل را «download»
//   ذخیره می‌کند. با کروم آزموده و دیده شد. `/` هم به `-` می‌رود تا
//   روی ویندوز باز شود.
$name = backupFileName();

// ⛔ نشانه‌ی «آخرین پشتیبان» **پیش از** فرستادنِ بدنه نوشته می‌شود، چون
//    بعد از `echo` هر کوئریِ ناموفقی بی‌صدا گم می‌شود (خروجی از قبل رفته
//    و هدرها بسته شده‌اند). یادآوریِ ماهانه از همین ستون می‌آید؛ اگر
//    نوشته نشود، کسی که همین حالا بکاپ گرفته باز هم یادآوری می‌گیرد و
//    یادآوری‌ای که دروغ بگوید همان اولین باری است که خاموشش می‌کنند.
markBackupTaken($userId);

// ⛔ گزیپِ خروجی که در db.php روشن شده باید اینجا خاموش شود، وگرنه
//    مرورگر فایل را دوبار فشرده می‌گیرد و چیزی که ذخیره می‌شود قابل
//    باز کردن نیست.
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header_remove('Content-Encoding');

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($json));
header('Cache-Control: no-store, private');

echo $json;
