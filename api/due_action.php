<?php
/**
 * سه اقدامِ مشترکِ صفحه‌ی سررسیدها: تسویه / تعویق / رد.
 *
 * ⛔ هیچ‌کدام پول جابه‌جا نمی‌کنند. «تسویه» فقط وضعیتِ همین سررسید را
 *    می‌بندد؛ ثبتِ تراکنش یا پرداخت کارِ صفحه‌ی همان بخش است. اگر اینجا
 *    هم پول ثبت می‌شد، یک پرداخت دو جا نوشته می‌شد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر
if (!Schedule::available()) {
    jsonResponse(['success' => false, 'message' => 'جدول سررسیدها هنوز ساخته نشده است.'], 400);
}

$id     = (int)postParam('id');
$action = postParam('action');
if ($id <= 0) { jsonResponse(['success' => false, 'message' => 'سررسید نامعتبر است.'], 422); }

if ($action === 'snooze') {
    // تاریخِ دلخواه اگر داده شده، وگرنه هفت روز جلو.
    $date = postParam('date');
    if (!isValidDate($date)) {
        $days = max(1, min(365, (int)postParam('days', '7')));
        // ⚠ مبنا خودِ سررسید است نه امروز — تعویقِ یک سررسیدِ عقب‌افتاده
        //   باید از تاریخِ خودش جلو برود، وگرنه دو بار تعویق آن را
        //   بی‌دلیل خیلی دور می‌برد.
        $st = Database::getConnection()->prepare(
            'SELECT due_date FROM reminder_occurrences WHERE id = :i AND user_id = :u'
        );
        $st->execute(['i' => $id, 'u' => $userId]);
        $base = (string)$st->fetchColumn();
        if ($base === '') { jsonResponse(['success' => false, 'message' => 'سررسید پیدا نشد.'], 404); }
        if ($base < today()) { $base = today(); }
        $date = date('Y-m-d', strtotime($base . " +{$days} day"));
    }
    $ok = Schedule::snooze($userId, $id, $date);
    jsonResponse(['success' => $ok,
        'message' => $ok ? 'به ' . toJalali($date) . ' موکول شد.' : 'تعویق انجام نشد.'],
        $ok ? 200 : 404);
}

if ($action === 'done' || $action === 'skip') {
    $ok = Schedule::close($userId, $id, $action === 'done' ? 'done' : 'skipped', postParam('note'));
    jsonResponse(['success' => $ok,
        'message' => $ok ? ($action === 'done' ? 'بسته شد.' : 'رد شد.') : 'انجام نشد.'],
        $ok ? 200 : 404);
}

jsonResponse(['success' => false, 'message' => 'اقدام نامعتبر است.'], 422);
