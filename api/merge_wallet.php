<?php
/**
 * انتقالِ همه‌ی تراکنش‌ها و موجودیِ یک حساب به حسابِ دیگرِ همان کاربر.
 *
 * منطق کلاً در `mergeWallet()` است؛ این فایل فقط پاکت است.
 * `wallet_id` و `into_id` هر دو در آن تابع در برابرِ مالکیتِ خودِ کاربر
 * سنجیده می‌شوند — شناسه‌ی حسابِ کاربرِ دیگری «یافت نشد» می‌گیرد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$fromId = (int)postParam('wallet_id');
$intoId = (int)postParam('into_id');
$delete = postParam('delete_source') === '1';

$r = mergeWallet($userId, $fromId, $intoId, $delete);
jsonResponse(['success' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
