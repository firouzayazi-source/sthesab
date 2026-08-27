<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$userId = Auth::userId();
$txId = (int)getParam('transaction_id');

$pdo = Database::getConnection();
$own = $pdo->prepare('SELECT id FROM transactions WHERE id = :id AND user_id = :u');
$own->execute(['id' => $txId, 'u' => $userId]);
if (!$own->fetch()) { http_response_code(404); exit; }

$stmt = $pdo->prepare('SELECT id, original_name, mime_type, file_size FROM attachments WHERE transaction_id = :tx AND user_id = :u ORDER BY created_at');
$stmt->execute(['tx' => $txId, 'u' => $userId]);
$rows = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');

if (empty($rows)) {
    echo '<p class="hint">پیوستی ندارد.</p>';
    exit;
}

foreach ($rows as $a) {
    $isImage = strpos($a['mime_type'], 'image/') === 0;
    ?>
    <div class="attach-row">
        <a href="<?= APP_BASE_PATH ?>/api/view_attachment.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" class="attach-link">
            <?= $isImage ? '🖼' : '📄' ?> <?= h($a['original_name']) ?>
            <small>(<?= toPersianDigits(round($a['file_size'] / 1024)) ?> کیلوبایت)</small>
        </a>
        <button class="delete-btn js-delete-attachment" data-id="<?= (int)$a['id'] ?>">حذف</button>
    </div>
    <?php
}
