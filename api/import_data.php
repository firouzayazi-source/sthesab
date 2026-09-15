<?php
/**
 * بازگرداندنِ فایلِ بکاپ — پاکتِ وب برای `importUserData()`.
 *
 * ⛔ منطق اینجا نیست و نباید بیاید: `includes/user_import.php` تنها جای
 *    آن تصمیم است، تا اگر روزی راهِ دومی (اپ موبایل، خطِ فرمان) اضافه
 *    شد نسخه‌ی دومی از «چه چیزی جایگزینِ چه چیزی می‌شود» ساخته نشود.
 *
 * ⛔ مثل `api/export_data.php`، شکست کاربر را در یک صفحه‌ی JSONِ خامِ
 *    بی‌راهِ‌برگشت رها نمی‌کند: این اندپوینت با یک فرمِ معمولی صدا زده
 *    می‌شود، پس هر شکستی به `backup.php` برمی‌گردد با پیامِ روشن.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';

Auth::initSession();

/** همان الگوی `exportFail()` — و به همان دلیل. */
function importFail(string $message, int $status = 400): void
{
    // ⚠ `Csrf::isJsonRequest()` خصوصی است، پس همان شرط تکرار می‌شود:
    //   هر دو سرآیند لازم‌اند چون `fetch` های `app.js`
    //   `X-Requested-With` می‌فرستند ولی `Accept: application/json` نه.
    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
           || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        jsonResponse(['success' => false, 'message' => $message], $status);
    }
    redirectWithMessage('../backup.php', 'error', $message);
}

// ⚠ بدونِ ورود عمداً ۴۰۱ است نه هدایت — قاعده‌ی `api/`، که
//   `test_api_auth` هر اندپوینت را با آن می‌سنجد. کاربرِ واقعی به اینجا
//   نمی‌رسد چون `backup.php` خودش `requireLogin()` دارد.
if (!Auth::isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    importFail('درخواست نامعتبر است.', 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

// ⛔ سدِ تایپی، پیش از هر کارِ دیگر. بازگرداندن داده‌ی فعلی را می‌برد و
//    `Undo` نمی‌تواند برش گرداند، پس تیک زدن کافی نیست.
//    ⚠ نیم‌فاصله و فاصله‌ی اضافه پاک می‌شوند، وگرنه کاربری که درست تایپ
//      کرده «عبارت را درست وارد کنید» می‌گیرد و نمی‌فهمد چرا.
$typed = trim(str_replace("\u{200C}", '', (string)postParam('confirm')));
if ($typed !== IMPORT_CONFIRM_PHRASE) {
    importFail('برای بازگرداندن باید عبارتِ «' . IMPORT_CONFIRM_PHRASE . '» را دقیقاً تایپ کنید.', 422);
}

if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['backup']['error'] ?? -1;
    $msg  = ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
        ? 'فایل از حدِ مجازِ سرور بزرگ‌تر است.'
        : 'فایلی انتخاب نشد.';
    importFail($msg, 422);
}

if ($_FILES['backup']['size'] > IMPORT_MAX_BYTES) {
    importFail('فایل خیلی بزرگ است.', 422);
}

$raw = (string)file_get_contents($_FILES['backup']['tmp_name']);

$parsed = parseBackupFile($raw);
if (!$parsed['ok']) {
    importFail($parsed['message'], 422);
}

try {
    $res = importUserData($userId, $parsed['data']);
} catch (Throwable $e) {
    error_log('Import Data Fatal: ' . $e->getMessage());
    importFail('بازگرداندن انجام نشد و داده‌ی فعلی دست‌نخورده ماند.', 500);
}

if (!$res['ok']) {
    importFail($res['message'], 422);
}

// ⛔ نتیجه عدد می‌دهد، نه فقط «انجام شد». کاربری که دفترش را برگردانده
//    باید همان لحظه ببیند چند ردیف نشست؛ وگرنه باید خودش برود بشمارد و
//    تا آن موقع نمی‌داند کار کرده یا نه.
$total = array_sum($res['inserted'] ?? []);
$note  = 'داده‌ی شما برگشت: ' . toPersianDigits((string)$total) . ' ردیف.';

if (!empty($res['skipped'])) {
    $note .= ' (اطلاعاتِ ورود و پرداخت از فایل وارد نمی‌شوند.)';
}
if (!empty($res['dropped'])) {
    $note .= ' چند ستون در این نسخه وجود نداشت و وارد نشد: '
        . implode('، ', array_slice($res['dropped'], 0, 6)) . '.';
}

redirectWithMessage('../backup.php', 'success', $note);
