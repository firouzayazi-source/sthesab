<?php
/**
 * برگرداندنِ آخرین حذف — پشتِ نوارِ «لغو».
 *
 * ⛔ هیچ شناسه‌ای از ورودی خوانده نمی‌شود، فقط یک **توکن**. خودِ داده در
 *    نشستِ همین کاربر است، پس کسی نمی‌تواند با حدس زدنِ شناسه ردیفِ
 *    دیگری را زنده کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/undo.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر
$r = Undo::restore((string)postParam('undo_token'), $userId);

jsonResponse(['success' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
