<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$userId = Auth::userId();
$date = getParam('date');

if (!isValidDate($date)) { http_response_code(422); exit('تاریخ نامعتبر'); }

header('Content-Type: text/html; charset=utf-8');

$pdo = Database::getConnection();

// تراکنش‌های واقعی همان روز
$txStmt = $pdo->prepare('
    SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.category_id,
           c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :u AND t.transaction_date = :d
    ORDER BY t.created_at ASC
');
$txStmt->execute(['u' => $userId, 'd' => $date]);
$transactions = $txStmt->fetchAll();

// رویدادهای سررسیدی همان روز
$events = financialEvents($userId, $date, $date);

if (empty($transactions) && empty($events)) {
    echo '<p class="empty-row">در این روز چیزی ثبت نشده است.</p>';
    exit;
}

if (!empty($events)) {
    echo '<h3 class="day-section-title">سررسیدها</h3>';
    foreach ($events as $e) {
        $cls = $e['direction'] === 'in' ? 'amount-income' : 'amount-expense';
        $sign = $e['direction'] === 'in' ? '+' : '−';
        ?>
        <a href="<?= h($e['url']) ?>" class="event-row">
            <span class="event-dot event-dot-<?= h($e['direction']) ?>"></span>
            <span class="event-body">
                <span class="event-title"><?= h($e['title']) ?></span>
                <span class="event-meta"><?= eventKindLabel($e['kind']) ?><?= $e['is_overdue'] ? ' · سررسید گذشته' : '' ?></span>
            </span>
            <span class="event-amount <?= $cls ?>"><?= $sign ?><?= formatMoney($e['amount']) ?></span>
        </a>
        <?php
    }
}

if (!empty($transactions)) {
    echo '<h3 class="day-section-title">تراکنش‌های ثبت‌شده</h3>';
    foreach ($transactions as $tx) {
        renderTransactionRow($tx);
    }
}
