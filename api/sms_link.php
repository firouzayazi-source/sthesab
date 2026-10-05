<?php
/**
 * ⛔ جفت کردنِ گوشی برای ثبتِ پیامک در پس‌زمینه (`SmsSync::link()`).
 *
 * اپ اندروید وقتی هنوز کلید ندارد، صفحه را با `#smslink=<کد>` باز می‌کند
 * (فرگمنت، پس کد در لاگِ سرور و تاریخچه‌ی شبکه نمی‌آید). این‌جا کد به
 * **کاربرِ همین نشست** بسته می‌شود؛ اپ بعداً با همان کد کلید را می‌گیرد
 * (`POST api/v1/sms/claim`). صفحه‌ی دیگری کد را ندارد، پس نمی‌تواند گوشیِ
 * کسی را به حسابِ خودش وصل کند و پیامک‌های بانکش را بگیرد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_sync.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

$r = SmsSync::link($userId, strtolower(trim((string)postParam('nonce'))));
jsonResponse(['success' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
