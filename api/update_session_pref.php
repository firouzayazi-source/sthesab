<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$hours = (int)postParam('session_hours');
if (!in_array($hours, [1, 8, 24, 168], true)) { $hours = 1; }

try {
    $upd = Database::getConnection()->prepare('UPDATE users SET session_hours = :h WHERE id = :id');
    $upd->execute(['h' => $hours, 'id' => $userId]);
    $_SESSION['session_hours'] = $hours;
    $_SESSION['last_seen'] = time();
    jsonResponse(['success' => true, 'message' => 'ذخیره شد.']);
} catch (PDOException $e) {
    error_log('Session Pref Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'ابتدا migration_p3.sql را اجرا کنید.'], 500);
}
