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
$tradeId = (int)postParam('trade_id');
$pdo = Database::getConnection();

try {
    // فروش‌ها با ON DELETE CASCADE خودشان پاک می‌شوند
    $st = $pdo->prepare('DELETE FROM trades WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $tradeId, 'u' => $userId]);
    if ($st->rowCount() === 0) { jsonResponse(['success' => false, 'message' => 'معامله یافت نشد.'], 404); }
    jsonResponse(['success' => true, 'message' => 'معامله حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Trade Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
