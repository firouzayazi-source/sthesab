<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
// ⛔ منطق در `changeOwnPassword()` — صفحه‌ی حسابِ فروشگاه هم از همان می‌گذرد
$r = changeOwnPassword($userId, postParam('current_password'), postParam('new_password'), postParam('new_password_confirm'));
jsonResponse(['success' => $r['ok'], 'message' => $r['message']], $r['status']);
