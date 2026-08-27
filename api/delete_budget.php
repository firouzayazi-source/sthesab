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
$id = (int)postParam('budget_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id FROM budgets WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
if (!$stmt->fetch()) { jsonResponse(['success' => false, 'message' => 'بودجه یافت نشد.'], 404); }

try {
    $del = $pdo->prepare('DELETE FROM budgets WHERE id = :id AND user_id = :u');
    $del->execute(['id' => $id, 'u' => $userId]);
    jsonResponse(['success' => true, 'message' => 'بودجه حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Budget Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
