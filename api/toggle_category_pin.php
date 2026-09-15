<?php
/**
 * پین/برداشتنِ یک دسته‌بندی از ردیفِ چیپِ فرمِ ثبت.
 *
 * ⚠ اندپوینتِ کوچکِ جداست، نه فیلدی در `manage_reference.php` — همان
 *   استدلالِ `toggle_wallet_pin.php`: آن فایل می‌سازد و حذف می‌کند، و
 *   یک تپِ ساده روی آیکونِ پین نباید از آن مسیر رد شود.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$catId  = (int)postParam('category_id');
$pin    = postParam('pinned') === '1';

if (!tableExists('category_pins')) {
    jsonResponse(['success' => false, 'message' => 'این قابلیت هنوز روی سرور اجرا نشده است.'], 400);
}

$pdo = Database::getConnection();

// ⛔ جداسازی کاربران: شناسه از ورودی می‌آید. دسته باید یا پیش‌فرضِ
//    برنامه باشد یا مالِ خودِ همین کاربر — و این تصمیم فقط از
//    `categoryScopeSql()` رد می‌شود، نه یک شرطِ دست‌نویس.
// ⚠ `type` از همین کوئری برمی‌گردد، نه از یک زیرکوئریِ دوم: آن زیرکوئری
//   یک `FROM categories`ِ بی‌شرط می‌شد و قاعده ۵ درست گرفتش — اولین
//   مسیری که فردا کپی‌اش کند، بدونِ scope نشت می‌کرد.
$own = $pdo->prepare('SELECT type FROM categories
                      WHERE id = :id AND is_active = 1 AND ' . categoryScopeSql());
$own->execute(['id' => $catId] + categoryScopeParams($userId));
$catType = $own->fetchColumn();
if ($catType === false) {
    jsonResponse(['success' => false, 'message' => 'دسته‌بندی یافت نشد.'], 404);
}

// ⛔ سقف **اینجا** هم سنجیده می‌شود، نه فقط هنگامِ نمایش در
//    `categoriesForGrid()`. اگر فقط آنجا بریده می‌شد، کاربر ۲۰ دسته پین
//    می‌کرد، هشت‌تا می‌دید و هیچ‌جا نمی‌فهمید بقیه کجا رفتند — همان
//    درسِ `PINNED_WALLET_MAX`.
if ($pin) {
    $cnt = $pdo->prepare(
        'SELECT COUNT(*) FROM category_pins p
         JOIN categories c ON c.id = p.category_id
         WHERE p.user_id = :u AND p.category_id <> :id
           AND c.is_active = 1 AND c.type = :t
           AND ' . categoryScopeSql('c.')
    );
    $cnt->execute(['u' => $userId, 'id' => $catId, 't' => $catType]
                  + categoryScopeParams($userId));
    if ((int)$cnt->fetchColumn() >= CATEGORY_GRID_MAX) {
        jsonResponse([
            'success' => false,
            'message' => 'حداکثر ' . toPersianDigits(CATEGORY_GRID_MAX)
                . ' دسته در ردیفِ فرمِ ثبت جا می‌شود. اول یکی را بردارید.',
        ], 422);
    }
    $pdo->prepare('INSERT IGNORE INTO category_pins (user_id, category_id) VALUES (:u, :c)')
        ->execute(['u' => $userId, 'c' => $catId]);
} else {
    $pdo->prepare('DELETE FROM category_pins WHERE user_id = :u AND category_id = :c')
        ->execute(['u' => $userId, 'c' => $catId]);
}

jsonResponse(['success' => true, 'pinned' => $pin]);
