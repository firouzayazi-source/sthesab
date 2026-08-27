<?php
/**
 * تحویل امن فایل پیوست — فقط به صاحب همان تراکنش.
 * فایل‌ها مستقیم از پوشه uploads قابل دسترسی نیستند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit('ابتدا وارد شوید.'); }

$userId = Auth::userId();
$id = (int)getParam('id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT file_name, original_name, mime_type FROM attachments WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$att = $stmt->fetch();

if (!$att) { http_response_code(404); exit('یافت نشد.'); }

$path = __DIR__ . '/../uploads/' . basename($att['file_name']);
if (!is_file($path)) { http_response_code(404); exit('فایل روی سرور نیست.'); }

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$mime = in_array($att['mime_type'], $allowedMimes, true) ? $att['mime_type'] : 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]/u', '_', $att['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
