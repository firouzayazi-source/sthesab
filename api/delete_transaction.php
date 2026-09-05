<?php
/**
 * حذف تراکنش — لایه‌ی وب. منطق در includes/transactions.php است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transactions.php';
require_once __DIR__ . '/../includes/undo.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}

Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر
$txId   = (int)postParam('transaction_id');

// ⛔ عکس **پیش از** حذف گرفته می‌شود، وگرنه چیزی برای برگرداندن نمی‌ماند.
//    پیوست‌ها با CASCADE می‌روند ولی فایلشان روی دیسک می‌ماند، پس
//    برگرداندنِ ردیف‌ها کافی است و پیوست هم واقعاً برمی‌گردد.
$undo = Undo::capture('transactions', $txId, $userId);

$result = txDelete($userId, $txId);

jsonResponse(
    ['success' => $result['ok'], 'message' => $result['message'],
     'undo_token' => $result['ok'] ? $undo : null],
    $result['ok'] ? 200 : $result['status']
);
