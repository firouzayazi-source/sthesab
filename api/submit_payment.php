<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plan.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

$res = submitPayment(
    $userId,
    (int)postParam('months', '1'),
    postParam('reference'),
    postParam('note')
);

if (!$res['ok']) {
    jsonResponse(['success' => false, 'message' => $res['error'] ?? 'ثبت نشد.'], 422);
}

jsonResponse([
    'success' => true,
    'message' => 'پرداخت شما ثبت شد و پس از بررسی تأیید می‌شود.',
]);
