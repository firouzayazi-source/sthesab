<?php
/**
 * تستِ خروجیِ کاملِ داده و حذفِ کاملِ حساب.
 *
 * ⛔ هر دو یک شکستِ مشترک دارند و هر دو **بی‌صدا**ست:
 *
 *   • خروجی‌ای که یک جدول را جا انداخته، فایلی است که کاربر فکر می‌کند
 *     همه چیزش را دارد. تا روزی که واقعاً لازمش شود نمی‌فهمد.
 *   • حذفی که یک جدول را جا انداخته، داده‌ی کسی است که رفته و فکر
 *     می‌کند پاک شده. برای فروش، این تعهد است نه ویژگی.
 *
 * به همین دلیل فهرستِ جدول‌ها **از خودِ دیتابیس** کشف می‌شود و این تست
 * همان کشف را می‌سنجد: هر جدولی که ستون `user_id` دارد باید دیده شود.
 *
 * ⛔⛔ هشدار به کسی که این فایل را جهش‌سنجی می‌کند:
 *     نسخه‌ی جهش‌یافته‌ی `deleteUserAccount()` — مثلاً بدونِ شرطِ
 *     `user_id` — **کلِ دیتابیس را پاک می‌کند و کامیت می‌کند**. تست
 *     بعدش خرابی را می‌بیند، ولی داده از قبل رفته. یک بار همین اتفاق
 *     روی دیتابیسِ توسعه افتاد.
 *
 *     جهش را روی یک دیتابیسِ موقت اجرا کنید، نه روی دیتابیسی که
 *     داده‌ی واقعی دارد. `deleteUserAccount()` حالا خودش هم سدی دارد
 *     که پیش از commit می‌سنجد بیش از سهمِ این کاربر حذف نشده باشد —
 *     ولی به آن تکیه نکنید، چون هدفِ جهش دقیقاً برداشتنِ همان سد
 *     می‌تواند باشد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('خروجی و حذفِ داده');
    T::blocked('تست داده‌ی کاربر', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('خروجی و حذفِ داده');
    T::blocked('تست داده‌ی کاربر', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
T::group('کشفِ جدول‌ها از خودِ دیتابیس');

$tables = userDataTables();
T::ok(count($tables) >= 15, 'جدول‌های داده‌ی کاربر پیدا شدند', count($tables) . ' جدول');

// ⛔ چند جدولِ کلیدی باید حتماً در فهرست باشند. اگر روزی کشف بشکند و
//    فهرست خالی یا ناقص شود، این می‌گیردش — «تعداد بیشتر از ۱۵» به
//    تنهایی نمی‌گیرد.
$mustHave = ['transactions', 'wallets', 'cheques', 'debts', 'categories', 'trades'];
$missing = array_values(array_diff($mustHave, $tables));
T::bulk(count($mustHave), $missing, 'جدول‌های اصلی در فهرست هستند');

// جدولِ بی‌کاربر نباید بیاید
T::ok(!in_array('app_settings', $tables, true), 'جدولِ بدون user_id در فهرست نیست');
T::ok(!in_array('schema_migrations', $tables, true), 'جدولِ ردیابی migration در فهرست نیست');

// ---------------------------------------------------------------
$TESTU = '__test_userdata';
$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (array_reverse(userDataTables()) as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* کلید خارجی — دورِ بعد */ }
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* بی‌خیال */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();
register_shutdown_function($cleanup);

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست داده', 'user', 1)"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

$walletId = ensureDefaultWallet($userId);
$catId = (int)$pdo->query('SELECT id FROM categories WHERE type = "expense" LIMIT 1')->fetchColumn();

$pdo->prepare(
    'INSERT INTO transactions (user_id, type, title, amount, category_id, wallet_id, transaction_date)
     VALUES (:u, "expense", "تراکنش تست خروجی", 12345, :c, :w, CURDATE())'
)->execute(['u' => $userId, 'c' => $catId ?: null, 'w' => $walletId]);

$pdo->prepare(
    "INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, due_date, is_settled)
     VALUES (:u, 'payable', 'طرفِ تست', 900000, 0, CURDATE(), CURDATE(), 0)"
)->execute(['u' => $userId]);

// ---------------------------------------------------------------
T::group('خروجی کامل است و رمز در آن نیست');

$dump = exportUserData($userId);

T::ok(isset($dump['profile']), 'پروفایل در خروجی هست');
T::ok(!array_key_exists('password_hash', $dump['profile'] ?? []),
    '⛔ هشِ رمز در خروجی نیست');
T::same($TESTU, $dump['profile']['username'] ?? null, 'نام کاربری درست است');

// هر جدولی که در فهرست هست و معاف نیست، باید کلیدی در خروجی داشته باشد
$expect = array_values(array_diff(userDataTables(), USER_EXPORT_SKIP));
$absent = array_values(array_diff($expect, array_keys($dump['tables'] ?? [])));
T::bulk(count($expect), $absent, 'هر جدولِ داده‌ی کاربر در خروجی کلید دارد');

// جدول‌های اعتبارنامه نباید بیایند
$leaked = array_values(array_intersect(USER_EXPORT_SKIP, array_keys($dump['tables'] ?? [])));
T::bulk(count(USER_EXPORT_SKIP), $leaked, 'جدول‌های اعتبارنامه در خروجی نیستند');

// و داده‌ی واقعی باید داخلش باشد، نه فقط کلیدِ خالی
$titles = array_column($dump['tables']['transactions'] ?? [], 'title');
T::ok(in_array('تراکنش تست خروجی', $titles, true),
    'تراکنشِ واقعی در خروجی هست', 'تعداد: ' . count($titles));
T::same(1, count($dump['tables']['debts'] ?? []), 'بدهی هم در خروجی هست');
T::ok(($dump['summary']['transactions'] ?? 0) >= 1, 'خلاصه‌ی شمارش ساخته شده');

// خروجیِ کاربرِ دیگر داخلش نیست
$otherRows = 0;
foreach ($dump['tables'] as $t => $rows) {
    foreach ($rows as $r) {
        if (array_key_exists('user_id', $r) && (int)$r['user_id'] !== $userId) { $otherRows++; }
    }
}
T::same(0, $otherRows, '⛔ هیچ ردیفی از کاربرِ دیگر در خروجی نیست');

// JSON باید واقعاً ساخته شود (منابع و مقدارهای دودویی خرابش نکنند)
$json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
T::ok($json !== false && strlen($json) > 200, 'خروجی به JSON تبدیل می‌شود',
    'خطا: ' . json_last_error_msg());

// ---------------------------------------------------------------
T::group('حذف، چیزی جا نمی‌گذارد');

$res = deleteUserAccount($userId);
T::ok($res['ok'], 'حذف انجام شد', $res['reason'] ?? '');

$left = [];
foreach (userDataTables() as $t) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id = :u");
    $st->execute(['u' => $userId]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) { $left[] = "$t: $n ردیف"; }
}
T::bulk(count(userDataTables()), $left, '⛔ هیچ ردیفی از این کاربر در هیچ جدولی نمانده');

$st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :u');
$st->execute(['u' => $userId]);
T::same(0, (int)$st->fetchColumn(), 'خودِ ردیفِ کاربر هم پاک شد');

// ---------------------------------------------------------------
T::group('حذف به کاربرِ دیگر سرایت نمی‌کند');

// ⚠ اگر شرطِ user_id از کوئریِ حذف بیفتد، حذفِ یک حساب کلِ دیتابیس را
//   خالی می‌کند. این بدترین باگِ ممکنِ این فایل است.
$OTHER = '__test_userdata_other';
$pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $OTHER]);
$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر دیگر', 'user', 1)"
)->execute(['u' => $OTHER, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$otherId = (int)$pdo->lastInsertId();
$otherWallet = ensureDefaultWallet($otherId);
$pdo->prepare(
    'INSERT INTO transactions (user_id, type, title, amount, wallet_id, transaction_date)
     VALUES (:u, "income", "تراکنشِ کاربرِ دیگر", 5000, :w, CURDATE())'
)->execute(['u' => $otherId, 'w' => $otherWallet]);

$before = (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();

// یک کاربرِ سومِ خالی می‌سازیم و حذفش می‌کنیم
$THIRD = '__test_userdata_third';
$pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $THIRD]);
$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر سوم', 'user', 1)"
)->execute(['u' => $THIRD, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$thirdId = (int)$pdo->lastInsertId();
ensureDefaultWallet($thirdId);

deleteUserAccount($thirdId);

$after = (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
T::same($before, $after, '⛔ حذفِ یک کاربر، تراکنشِ کاربرِ دیگر را دست نمی‌زند');

$st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
$st->execute(['u' => $otherId]);
T::same(1, (int)$st->fetchColumn(), 'تراکنشِ کاربرِ دیگر سر جایش است');

// پاک‌سازی
foreach (array_reverse(userDataTables()) as $t) {
    try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $otherId]); }
    catch (PDOException $e) { /* بی‌خیال */ }
}
foreach (userDataTables() as $t) {
    try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $otherId]); }
    catch (PDOException $e) { /* بی‌خیال */ }
}
$pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $otherId]);

exit(T::report());
