<?php
/**
 * نرخِ روزِ یک نوع دارایی.
 *
 * ⛔ چرا لازم شد: `assets.unit_price` بهای واحد **هنگام ثبت** است. صفحه‌ی
 *    دارایی همان را جمع می‌زد و «ارزش کل» می‌نامیدش — که در اقتصادِ
 *    تورمی حرفِ بی‌معنایی است. برای کاربر ایرانی طلا و دلار ابزارِ اصلیِ
 *    پس‌انداز است و ارزشِ سکه‌ی پارسال هیچ ربطی به قیمتِ پارسال ندارد.
 *
 * ⚠ نرخ دستی است و از هیچ سرویسِ بیرونی نمی‌آید — همان دلیلی که
 *   Chart.js را محلی کرد: اینترنتِ داخلی و تحریم. سرویسی که نصفِ روزها
 *   در دسترس نباشد، از نبودنش بدتر است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر

if (!tableHasColumn('asset_types', 'current_price')) {
    jsonResponse(['success' => false,
        'message' => 'ستون نرخ هنوز ساخته نشده. روی سرور:  bash deploy/migrate.sh --apply'], 500);
}

$typeId = (int)postParam('type_id');
if ($typeId <= 0) { jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422); }

// خالی یعنی «نرخ را بردار» — آن‌وقت ارزش دوباره به بهای خرید برمی‌گردد.
$raw   = trim((string)postParam('price'));
$price = $raw === '' ? null : sanitizeAmount($raw);
if ($price !== null && $price < 0) {
    jsonResponse(['success' => false, 'message' => 'نرخ نمی‌تواند منفی باشد.'], 422);
}
if ($price === 0) { $price = null; }   // صفر یعنی پاک کردن، نه نرخِ صفر

$pdo = Database::getConnection();

// ⛔ شرطِ `user_id` روی خودِ UPDATE است، نه بررسیِ جدا: بدون آن هر
//    کاربری می‌توانست نرخِ نوعِ دارایی کاربرِ دیگری را عوض کند و ارزشِ
//    دفترِ او را جابه‌جا کند.
try {
    $st = $pdo->prepare(
        'UPDATE asset_types SET current_price = :p, price_updated_at = :d
         WHERE id = :id AND user_id = :u'
    );
    $st->execute([
        'p'  => $price,
        'd'  => $price === null ? null : today(),
        'id' => $typeId,
        'u'  => $userId,
    ]);
    if ($st->rowCount() === 0) {
        // یا مالِ او نیست، یا مقدار عوض نشده. برای تفکیک، وجودش را
        // جدا می‌سنجیم تا پیامِ گمراه‌کننده ندهیم.
        $own = $pdo->prepare('SELECT id FROM asset_types WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $typeId, 'u' => $userId]);
        if (!$own->fetchColumn()) {
            jsonResponse(['success' => false, 'message' => 'این نوع دارایی پیدا نشد.'], 404);
        }
    }
} catch (PDOException $e) {
    Log::error('api.asset_price', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ذخیره رخ داد.'], 500);
}

jsonResponse([
    'success' => true,
    'price'   => $price,
    'message' => $price === null ? 'نرخ برداشته شد.' : 'نرخ روز ذخیره شد.',
]);
