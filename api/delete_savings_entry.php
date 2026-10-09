<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/undo.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$id = (int)postParam('entry_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id, goal_id, amount FROM savings_entries WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$entry = $stmt->fetch();
if (!$entry) { jsonResponse(['success' => false, 'message' => 'رکورد یافت نشد.'], 404); }

// ⛔ حذفِ واریز نباید موجودیِ هدف را زیرِ صفر ببرد — همان سدی که برداشت دارد.
//    بازرسیِ محاسباتی (مهر ۱۴۰۵): واریزِ ۴٬۰۰۰، برداشتِ ۴٬۰۰۰، حذفِ واریز ⇒ موجودیِ
//    «−۴٬۰۰۰» و پیشرفتِ «−۴۰٪». اول برداشت حذف شود.
if ((int)$entry['amount'] > 0) {
    $cur = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM savings_entries WHERE goal_id = :g AND user_id = :u');
    $cur->execute(['g' => (int)$entry['goal_id'], 'u' => $userId]);
    if ((int)$cur->fetchColumn() - (int)$entry['amount'] < 0) {
        jsonResponse(['success' => false, 'message' => 'با حذفِ این واریز موجودیِ هدف منفی می‌شود — اول برداشتِ بعد از آن را حذف کنید.'], 422);
    }
}

// ⛔ عکس **پیش از** حذف، وگرنه چیزی برای برگرداندن نمی‌ماند.
$undo = Undo::capture('savings_entries', $id, $userId);
try {
    $del = $pdo->prepare('DELETE FROM savings_entries WHERE id = :id AND user_id = :u');
    $del->execute(['id' => $id, 'u' => $userId]);
    jsonResponse(['success' => true, 'message' => 'حذف شد.', 'undo_token' => $undo]);
} catch (PDOException $e) {
    Log::error('api.delete_savings_entry', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
