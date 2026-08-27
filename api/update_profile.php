<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId   = Auth::userId();
$fullName = trim(postParam('full_name'));
$username = trim(postParam('username'));
$current  = postParam('current_password');

$errors = [];
if ($fullName === '' || mb_strlen($fullName) > 100) { $errors[] = 'نام نمایشی الزامی است.'; }
if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
    $errors[] = 'نام کاربری باید ۳ تا ۵۰ کاراکتر و فقط شامل حروف انگلیسی، عدد و زیرخط باشد.';
}
if ($current === '') { $errors[] = 'برای تأیید، رمز فعلی را وارد کنید.'; }

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$pdo = Database::getConnection();

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    jsonResponse(['success' => false, 'message' => 'رمز فعلی اشتباه است.'], 403);
}

// نام کاربری نباید تکراری باشد
$dup = $pdo->prepare('SELECT id FROM users WHERE username = :un AND id != :id');
$dup->execute(['un' => $username, 'id' => $userId]);
if ($dup->fetch()) {
    jsonResponse(['success' => false, 'message' => 'این نام کاربری قبلاً استفاده شده است.'], 422);
}

try {
    $upd = $pdo->prepare('UPDATE users SET full_name = :fn, username = :un WHERE id = :id');
    $upd->execute(['fn' => $fullName, 'un' => $username, 'id' => $userId]);

    $_SESSION['full_name'] = $fullName;
    $_SESSION['username']  = $username;
    Auth::rememberUsername($username);

    jsonResponse(['success' => true, 'message' => 'اطلاعات بروزرسانی شد.']);
} catch (PDOException $e) {
    error_log('Update Profile Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
