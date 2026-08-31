<?php
/**
 * ثبت تراکنش — لایه‌ی وب.
 *
 * منطق در includes/transactions.php است و api/v1 هم از همان‌جا رد
 * می‌شود. اینجا فقط احراز هویت نشستی، CSRF، و پاکت پاسخِ وب است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transactions.php';

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

$result = txCreate($userId, [
    'type'             => postParam('type'),
    'amount'           => postParam('amount'),
    'title'            => postParam('title'),
    'note'             => postParam('note'),
    'transaction_date' => postParam('transaction_date'),
    'category_id'      => postParam('category_id'),
    'wallet_id'        => postParam('wallet_id'),
]);

if (!$result['ok']) {
    jsonResponse(['success' => false, 'message' => $result['message']], $result['status']);
}

// وب همیشه ۲۰۰ می‌گرفت، نه ۲۰۱ — دست نمی‌زنیم تا app.js عوض نشود.
jsonResponse([
    'success' => true,
    'message' => $result['message'],
    'today'   => today(),
]);
