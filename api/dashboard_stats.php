<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

$pdo = Database::getConnection();
$userId = Auth::userId();

function getPeriodStatsApi(PDO $pdo, int $userId, string $fromDate, string $toDate): array
{
    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN type = "income" THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expense
        FROM transactions
        WHERE user_id = :user_id AND transaction_date BETWEEN :from_date AND :to_date
    ');
    $stmt->execute(['user_id' => $userId, 'from_date' => $fromDate, 'to_date' => $toDate]);
    $row = $stmt->fetch();

    $income  = (int)$row['income'];
    $expense = (int)$row['expense'];

    return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
}

$today      = today();
$weekStart  = startOfWeek();
$monthStart = startOfJalaliMonth();
$yearStart  = startOfJalaliYear();

jsonResponse([
    'success' => true,
    'data' => [
        'today' => getPeriodStatsApi($pdo, $userId, $today, $today),
        'week'  => getPeriodStatsApi($pdo, $userId, $weekStart, $today),
        'month' => getPeriodStatsApi($pdo, $userId, $monthStart, $today),
        'year'  => getPeriodStatsApi($pdo, $userId, $yearStart, $today),
    ],
]);