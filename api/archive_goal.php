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
$id = (int)postParam('goal_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id, is_archived FROM savings_goals WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$g = $stmt->fetch();
if (!$g) { jsonResponse(['success' => false, 'message' => 'هدف یافت نشد.'], 404); }

$new = (int)$g['is_archived'] === 1 ? 0 : 1;
$upd = $pdo->prepare('UPDATE savings_goals SET is_archived = :s WHERE id = :id AND user_id = :u');
$upd->execute(['s' => $new, 'id' => $id, 'u' => $userId]);
jsonResponse(['success' => true, 'message' => $new === 1 ? 'بایگانی شد.' : 'از بایگانی خارج شد.']);
