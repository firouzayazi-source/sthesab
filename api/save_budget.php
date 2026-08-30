<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId     = Auth::userId();
$budgetId   = (int)postParam('budget_id');
$categoryId = (int)postParam('category_id');
$periodType = postParam('period_type', 'monthly');
$amount     = sanitizeAmount(postParam('amount'));
$startDate  = postParam('start_date');
$endDate    = postParam('end_date');

$errors = [];

if (!in_array($periodType, ['weekly', 'monthly', 'yearly', 'custom'], true)) {
    $periodType = 'monthly';
}
if ($amount <= 0) {
    $errors[] = 'مبلغ بودجه باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ بیش از حد بزرگ است.';
}
if ($periodType === 'custom') {
    if (!isValidDate($startDate) || !isValidDate($endDate)) {
        $errors[] = 'برای بازه دلخواه، تاریخ شروع و پایان الزامی است.';
    } elseif ($startDate > $endDate) {
        $errors[] = 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.';
    }
} else {
    $startDate = null;
    $endDate = null;
}

$pdo = Database::getConnection();

$catChk = $pdo->prepare(
    'SELECT id FROM categories
     WHERE id = :id AND type = "expense" AND is_active = 1 AND ' . categoryScopeSql()
);
$catChk->execute(['id' => $categoryId] + categoryScopeParams($userId));
if (!$catChk->fetch()) {
    $errors[] = 'دسته‌بندی نامعتبر است. بودجه فقط برای دسته‌های هزینه تعریف می‌شود.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

try {
    if ($budgetId > 0) {
        $own = $pdo->prepare('SELECT id FROM budgets WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $budgetId, 'u' => $userId]);
        if (!$own->fetch()) {
            jsonResponse(['success' => false, 'message' => 'بودجه یافت نشد.'], 404);
        }

        $stmt = $pdo->prepare('
            UPDATE budgets SET category_id = :c, period_type = :p, amount = :a, start_date = :s, end_date = :e
            WHERE id = :id AND user_id = :u
        ');
        $stmt->execute(['c' => $categoryId, 'p' => $periodType, 'a' => $amount, 's' => $startDate, 'e' => $endDate, 'id' => $budgetId, 'u' => $userId]);
        jsonResponse(['success' => true, 'message' => 'بودجه بروزرسانی شد.']);
    }

    $dupChk = $pdo->prepare('SELECT id FROM budgets WHERE user_id = :u AND category_id = :c AND period_type = :p');
    $dupChk->execute(['u' => $userId, 'c' => $categoryId, 'p' => $periodType]);
    if ($dupChk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'برای این دسته‌بندی، بودجه‌ی ' . budgetPeriodLabel($periodType) . ' قبلاً تعریف شده است.'], 422);
    }

    $stmt = $pdo->prepare('
        INSERT INTO budgets (user_id, category_id, period_type, amount, start_date, end_date)
        VALUES (:u, :c, :p, :a, :s, :e)
    ');
    $stmt->execute(['u' => $userId, 'c' => $categoryId, 'p' => $periodType, 'a' => $amount, 's' => $startDate, 'e' => $endDate]);
    jsonResponse(['success' => true, 'message' => 'بودجه ثبت شد.']);
} catch (PDOException $e) {
    error_log('Save Budget Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
