<?php
/**
 * برگرداندنِ سودِ اصلاح‌شده‌ی فروشگاه به عددِ حسابداری فروشگاه.
 *
 * منطق در `StoreShare::revertEdit()` است؛ اینجا فقط لایه‌ی وب.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_share.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر
$res = StoreShare::revertEdit($userId, (int)postParam('transaction_id'));

jsonResponse(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
