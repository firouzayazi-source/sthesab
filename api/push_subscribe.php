<?php
/**
 * روشن/خاموش کردنِ اعلان روی گوشی برای **همین** مرورگر.
 *
 * ⛔ آدرس و کلیدِ اشتراک را خودِ مرورگر می‌سازد (`pushManager.subscribe`)
 *    و اینجا فقط ذخیره می‌شود؛ `Push::subscribe()` فقط سرویس‌های پوشِ
 *    شناخته‌شده را می‌پذیرد، وگرنه هر کسی می‌توانست سرور را وادار کند به
 *    هر آدرسی درخواست بفرستد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/push.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

if (!Push::available()) {
    jsonResponse(['success' => false, 'message' => 'اعلان روی گوشی روی این سرور هنوز راه نیفتاده (migration_push).'], 503);
}

// ⛔ کلیدِ عمومیِ VAPID برای اشتراکِ خودکار (پیش‌فرض روشن) در `app.js`.
//    اینجا می‌آید نه در یک `<meta>` روی هر صفحه: فقط دستگاهی که هنوز
//    اشتراک ندارد یک بار می‌پرسد، و هیچ صفحه‌ای خواندنِ فایلِ تازه نمی‌گیرد.
//    کلیدِ **عمومی** است و لو رفتنش چیزی را باز نمی‌کند (سرویسِ پوش آن را
//    به هر حال از مرورگر می‌گیرد).
if (postParam('action') === 'key') {
    $key = Push::publicKey();
    jsonResponse(['success' => $key !== '', 'key' => $key,
                  'message' => $key !== '' ? '' : 'کلیدِ اعلان ساخته نشد.'], $key !== '' ? 200 : 503);
}

$endpoint = trim((string)($_POST['endpoint'] ?? ''));
if ($endpoint === '') {
    jsonResponse(['success' => false, 'message' => 'اشتراکی فرستاده نشد.'], 422);
}

if (postParam('action') === 'unsubscribe') {
    Push::unsubscribe((int)$userId, $endpoint);
    jsonResponse(['success' => true, 'message' => 'اعلان روی این دستگاه خاموش شد.']);
}

$res = Push::subscribe((int)$userId, $endpoint, (string)postParam('p256dh'), (string)postParam('auth'),
    mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120));
jsonResponse(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
