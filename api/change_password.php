<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId  = Auth::userId();
$current = postParam('current_password');
$new     = postParam('new_password');
$confirm = postParam('new_password_confirm');

$errors = [];
if ($current === '') { $errors[] = 'رمز فعلی را وارد کنید.'; }
if (mb_strlen($new) < 6) { $errors[] = 'رمز جدید باید حداقل ۶ کاراکتر باشد.'; }
if ($new !== $confirm) { $errors[] = 'رمز جدید و تکرار آن یکسان نیستند.'; }
if ($new === $current && $new !== '') { $errors[] = 'رمز جدید نباید با رمز فعلی یکی باشد.'; }

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$row = $stmt->fetch();

if (!$row || !password_verify($current, $row['password_hash'])) {
    jsonResponse(['success' => false, 'message' => 'رمز فعلی اشتباه است.'], 403);
}

try {
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $upd = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
    $upd->execute(['h' => $hash, 'id' => $userId]);

    // با تغییر رمز، همه‌ی دستگاه‌های مورد اعتماد باطل می‌شوند
    Auth::revokeAllDevices($userId);

    jsonResponse(['success' => true, 'message' => 'رمز عبور تغییر کرد. دستگاه‌های مورد اعتماد هم باطل شدند.']);
} catch (PDOException $e) {
    error_log('Change Password Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
