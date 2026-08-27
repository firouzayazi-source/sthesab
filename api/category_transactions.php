<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    exit;
}

$userId = Auth::userId();
$categoryId = (int)getParam('category_id');
$type = getParam('type');
$fromDate = getParam('from_date');
$toDate = getParam('to_date');

if ($categoryId <= 0 || !in_array($type, ['income', 'expense'], true) || !isValidDate($fromDate) || !isValidDate($toDate)) {
    http_response_code(422);
    exit('پارامترهای نامعتبر.');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare('
    SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.created_at, t.category_id, c.name AS category_name
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id AND t.category_id = :category_id AND t.type = :type
      AND t.transaction_date BETWEEN :from_date AND :to_date
    ORDER BY t.transaction_date DESC, t.created_at DESC
');
$stmt->execute([
    'user_id' => $userId, 'category_id' => $categoryId, 'type' => $type,
    'from_date' => $fromDate, 'to_date' => $toDate,
]);
$rows = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');

if (empty($rows)) {
    echo '<p class="empty-row">تراکنشی یافت نشد.</p>';
    exit;
}

foreach ($rows as $tx) {
    ?>
    <div class="cat-detail-row">
        <span class="cat-detail-date"><?= toJalali($tx['transaction_date']) ?></span>
        <span class="cat-detail-title"><?= h($tx['title']) ?></span>
        <span class="cat-detail-amount amount-<?= h($tx['type']) ?>"><?= formatMoney($tx['amount']) ?></span>
    </div>
    <?php if (!empty($tx['note'])): ?>
        <div class="cat-detail-note"><?= h($tx['note']) ?></div>
    <?php endif; ?>
    <?php
}
