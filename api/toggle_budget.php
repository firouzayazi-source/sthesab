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
$stmt = $pdo->prepare('SELECT id, is_active FROM budgets WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$b = $stmt->fetch();
if (!$b) { jsonResponse(['success' => false, 'message' => 'بودجه یافت نشد.'], 404); }

$new = (int)$b['is_active'] === 1 ? 0 : 1;
$upd = $pdo->prepare('UPDATE budgets SET is_active = :s WHERE id = :id AND user_id = :u');
$upd->execute(['s' => $new, 'id' => $id, 'u' => $userId]);

jsonResponse(['success' => true, 'is_active' => $new, 'message' => $new === 1 ? 'فعال شد.' : 'غیرفعال شد.']);
