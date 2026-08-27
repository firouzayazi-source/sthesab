<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$userId = Auth::userId();
$goalId = (int)getParam('goal_id');

$pdo = Database::getConnection();
$own = $pdo->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :u');
$own->execute(['id' => $goalId, 'u' => $userId]);
if (!$own->fetch()) { http_response_code(404); exit; }

$stmt = $pdo->prepare('SELECT id, amount, note, entry_date FROM savings_entries WHERE goal_id = :g ORDER BY entry_date DESC, created_at DESC');
$stmt->execute(['g' => $goalId]);
$rows = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');

if (empty($rows)) {
    echo '<p class="empty-row" style="padding:14px 0;">هنوز واریز یا برداشتی ثبت نشده.</p>';
    exit;
}

foreach ($rows as $r) {
    $isDeposit = (int)$r['amount'] >= 0;
    ?>
    <div class="cat-detail-row">
        <span class="cat-detail-date"><?= toJalali($r['entry_date']) ?></span>
        <span class="cat-detail-title"><?= $isDeposit ? 'واریز' : 'برداشت' ?><?= !empty($r['note']) ? ' — ' . h($r['note']) : '' ?></span>
        <span class="cat-detail-amount <?= $isDeposit ? 'amount-income' : 'amount-expense' ?>"><?= $isDeposit ? '+' : '−' ?><?= formatMoney(abs((int)$r['amount'])) ?></span>
        <button class="delete-btn js-delete-savings-entry" data-id="<?= (int)$r['id'] ?>" style="padding:2px 6px; font-size:11px;">حذف</button>
    </div>
    <?php
}
