<?php
/**
 * تحویلِ فایلِ پیوستِ تیکت — فقط به صاحبِ همان تیکت، یا به مدیر.
 *
 * ⛔ پوشه‌ی `uploads/` مستقیم از وب باز نیست؛ تنها راهِ رسیدن به فایل
 *    همین اندپوینت است و مالکیت را خودش می‌سنجد. با لینکِ مستقیم، حدسِ
 *    نامِ فایل کافی بود تا اسکرین‌شاتِ حسابِ کسِ دیگری دیده شود.
 *
 * ⚠ فقط-خواندنی است، پس عمداً `Csrf::verifyOrFail()` ندارد — همان
 *   قاعده‌ی `api/view_attachment.php`. اگر روزی نویسنده شد، باید اضافه
 *   شود.
 *
 * ⛔ مدیر هم می‌تواند ببیند، و این لازم است: کاربر اسکرین‌شات را
 *    می‌فرستد **برای اینکه پشتیبانی ببیندش**. ولی شرطش
 *    `Auth::isAdmin()` است نه یک پارامترِ ورودی.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit('ابتدا وارد شوید.'); }

$userId = (int)Auth::userId();
$id     = (int)getParam('id');

if ($id < 1 || !tableExists('support_attachments')) { http_response_code(404); exit('یافت نشد.'); }

$sql    = 'SELECT file_name, original_name, mime_type FROM support_attachments WHERE id = :id';
$params = ['id' => $id];
if (!Auth::isAdmin()) { $sql .= ' AND user_id = :u'; $params['u'] = $userId; }

$st = Database::getConnection()->prepare($sql);
$st->execute($params);
$att = $st->fetch();

if (!$att) { http_response_code(404); exit('یافت نشد.'); }

$path = __DIR__ . '/../uploads/' . basename((string)$att['file_name']);
if (!is_file($path)) { http_response_code(404); exit('فایل روی سرور نیست.'); }

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$mime = in_array($att['mime_type'], $allowed, true) ? $att['mime_type'] : 'application/octet-stream';

// بافرِ gzip که `db.php` روشن می‌کند باید پیش از فرستادنِ فایل بسته شود،
// وگرنه چیزی که ذخیره می‌شود دوبار فشرده است و باز نمی‌شود.
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="'
    . preg_replace('/[^\w.\-]/u', '_', (string)$att['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
