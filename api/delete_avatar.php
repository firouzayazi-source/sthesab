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
$pdo = Database::getConnection();

try {
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $name = $stmt->fetch()['avatar'] ?? null;

    $upd = $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id');
    $upd->execute(['id' => $userId]);

    if ($name) {
        $path = __DIR__ . '/../uploads/avatars/' . basename($name);
        if (is_file($path)) { @unlink($path); }
    }

    unset($_SESSION['avatar']);
    jsonResponse(['success' => true, 'message' => 'تصویر حذف شد.']);
} catch (PDOException $e) {
    Log::error('api.delete_avatar', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
