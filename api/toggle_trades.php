<?php
/**
 * روشن/خاموش کردن بخش معاملات — هر کاربر برای خودش.
 * خاموش کردن چیزی را پاک نمی‌کند؛ فقط بخش از منو برداشته می‌شود و
 * با روشن کردن دوباره همه‌ی معامله‌ها سر جایشان هستند.
 */
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

if (!usersHaveColumn($pdo, 'trades_enabled')) {
    jsonResponse(['success' => false, 'message' => 'ستون تنظیمات هنوز ساخته نشده. روی سرور:  bash deploy/migrate.sh --apply'], 500);
}

$enable = postParam('enabled') === '1' ? 1 : 0;

try {
    $pdo->prepare('UPDATE users SET trades_enabled = :e WHERE id = :id')
        ->execute(['e' => $enable, 'id' => $userId]);
    jsonResponse([
        'success' => true,
        'enabled' => $enable === 1,
        'message' => $enable === 1 ? 'بخش معاملات روشن شد.' : 'بخش معاملات خاموش شد.',
    ]);
} catch (PDOException $e) {
    error_log('Toggle Trades Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
