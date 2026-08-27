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
$goalId = (int)postParam('goal_id');
$title  = postParam('title');
$target = sanitizeAmount(postParam('target_amount'));
$date   = postParam('target_date');
$color  = postParam('color', '#16794f');

$errors = [];
if ($title === '' || mb_strlen($title) > 150) { $errors[] = 'عنوان هدف الزامی است.'; }
if ($target <= 0) { $errors[] = 'مبلغ هدف باید بزرگ‌تر از صفر باشد.'; }
if ($target > 999999999999) { $errors[] = 'مبلغ بیش از حد بزرگ است.'; }
if ($date !== '' && !isValidDate($date)) { $errors[] = 'تاریخ نامعتبر است.'; }
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = '#16794f'; }

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$pdo = Database::getConnection();

try {
    if ($goalId > 0) {
        $own = $pdo->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $goalId, 'u' => $userId]);
        if (!$own->fetch()) { jsonResponse(['success' => false, 'message' => 'هدف یافت نشد.'], 404); }

        $stmt = $pdo->prepare('UPDATE savings_goals SET title = :t, target_amount = :a, target_date = :d, color = :c WHERE id = :id AND user_id = :u');
        $stmt->execute(['t' => $title, 'a' => $target, 'd' => $date !== '' ? $date : null, 'c' => $color, 'id' => $goalId, 'u' => $userId]);
        jsonResponse(['success' => true, 'message' => 'هدف بروزرسانی شد.']);
    }

    $stmt = $pdo->prepare('INSERT INTO savings_goals (user_id, title, target_amount, target_date, color) VALUES (:u, :t, :a, :d, :c)');
    $stmt->execute(['u' => $userId, 't' => $title, 'a' => $target, 'd' => $date !== '' ? $date : null, 'c' => $color]);
    jsonResponse(['success' => true, 'message' => 'هدف ساخته شد.']);
} catch (PDOException $e) {
    error_log('Save Goal Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
