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


// ⚠ اینجا عمداً ۴۰۱ است، نه هدایت به صفحه‌ی ورود: قاعده‌ی `api/` این
//   است که هر اندپوینت بدونِ ورود ۴۰۱ بدهد، و `test_api_auth` هر ۵۹
//   تا را با هم می‌سنجد. کاربرِ واقعی هم به اینجا نمی‌رسد، چون
//   `backup.php` خودش `requireLogin()` دارد.
if (!Auth::isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failJsonOrRedirect('درخواست نامعتبر است.', 405, '../backup.php');
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
// ⛔ منطق در `sendUserExport()` — صفحه‌ی حسابِ فروشگاه هم از همان می‌گذرد
$err = sendUserExport($userId);
failJsonOrRedirect($err, $err === 'کاربر یافت نشد.' ? 404 : 500, '../backup.php');
