<?php
/**
 * تست دسته‌بندی شخصیِ کاربر.
 *
 * سه چیز که اگر بشکنند بی‌سروصدا می‌شکنند:
 *  ۱. کاربر باید پیش‌فرض‌های برنامه را ببیند، به‌علاوه‌ی دسته‌های خودش
 *  ۲. دسته‌ی شخصیِ کاربر دیگر **نباید** دیده شود — این همان قاعده‌ی
 *     جداسازی کاربران است که کل اپ رویش بنا شده
 *  ۳. دسته‌ی پیش‌فرض هرگز مالِ کسی نیست، پس با کوئریِ حذفِ کاربر
 *     (که user_id = :u دارد) پیدا نمی‌شود
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('دسته‌بندی شخصی');
    T::blocked('تست دسته‌بندی شخصی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('دسته‌بندی شخصی');
    T::blocked('تست دسته‌بندی شخصی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableHasColumn('categories', 'user_id')) {
    T::group('دسته‌بندی شخصی');
    T::skip('تست دسته‌بندی شخصی', 'migration_user_categories اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
$USERS = ['__test_cat_a', '__test_cat_b'];
$MARK  = '__test_cat_';

$cleanup = function () use ($pdo, $USERS, $MARK) {
    $pdo->prepare("DELETE FROM categories WHERE name LIKE :m")->execute(['m' => $MARK . '%']);
    foreach ($USERS as $u) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $st->execute(['u' => $u]);
        if ($id = $st->fetchColumn()) {
            $pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $id]);
        }
        $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $u]);
    }
};
$cleanup();

$mkUser = function (string $username) use ($pdo): int {
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role)
         VALUES (:u, :p, 'کاربر تست دسته', 'user')"
    )->execute(['u' => $username, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};

$userA = $mkUser($USERS[0]);
$userB = $mkUser($USERS[1]);

$mkCat = function (?int $ownerId, string $name, string $type) use ($pdo, $MARK): int {
    $pdo->prepare(
        'INSERT INTO categories (user_id, name, type, is_active) VALUES (:u, :n, :t, 1)'
    )->execute(['u' => $ownerId, 'n' => $MARK . $name, 't' => $type]);
    return (int)$pdo->lastInsertId();
};

$defaultId = $mkCat(null,   'default', 'expense');
$aId       = $mkCat($userA, 'mine_a',  'expense');
$bId       = $mkCat($userB, 'mine_b',  'expense');

/** دسته‌هایی که یک کاربر می‌بیند — دقیقاً همان شرطی که کل اپ استفاده می‌کند. */
$visibleTo = function (int $userId) use ($pdo): array {
    $st = $pdo->prepare(
        'SELECT id FROM categories WHERE is_active = 1 AND ' . categoryScopeSql()
    );
    $st->execute(categoryScopeParams($userId));
    return array_map('intval', array_column($st->fetchAll(), 'id'));
};

// ---------------------------------------------------------------
T::group('چه کسی چه چیزی را می‌بیند');

$seenByA = $visibleTo($userA);
$seenByB = $visibleTo($userB);

T::ok(in_array($defaultId, $seenByA, true), 'کاربر دسته‌ی پیش‌فرض برنامه را می‌بیند');
T::ok(in_array($aId, $seenByA, true),       'کاربر دسته‌ی خودش را می‌بیند');
T::ok(!in_array($bId, $seenByA, true),      'کاربر دسته‌ی شخصیِ کاربر دیگر را نمی‌بیند');

T::ok(in_array($defaultId, $seenByB, true), 'کاربر دوم هم پیش‌فرض را می‌بیند');
T::ok(in_array($bId, $seenByB, true),       'کاربر دوم دسته‌ی خودش را می‌بیند');
T::ok(!in_array($aId, $seenByB, true),      'کاربر دوم دسته‌ی کاربر اول را نمی‌بیند');

// ---------------------------------------------------------------
T::group('پیش‌فرض‌ها دست کاربر عادی نیستند');

// همان کوئریِ حذف که api/manage_reference.php می‌زند
$deletable = function (int $userId, int $catId) use ($pdo): bool {
    $st = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $catId, 'u' => $userId]);
    return (bool)$st->fetch();
};

T::ok(!$deletable($userA, $defaultId), 'دسته‌ی پیش‌فرض با کوئری حذفِ کاربر پیدا نمی‌شود');
T::ok(!$deletable($userA, $bId),       'دسته‌ی کاربر دیگر با کوئری حذف پیدا نمی‌شود');
T::ok($deletable($userA, $aId),        'دسته‌ی خودِ کاربر قابل حذف است');

// ---------------------------------------------------------------
T::group('دسته‌ی تازه در محاسبات می‌آید');

// تراکنشی روی دسته‌ی شخصی، و بعد همان کوئریِ گزارش دسته‌بندی
$pdo->prepare(
    "INSERT INTO transactions (user_id, category_id, type, amount, title, transaction_date)
     VALUES (:u, :c, 'expense', 250000, 'تست دسته', :d)"
)->execute(['u' => $userA, 'c' => $aId, 'd' => today()]);

$rep = $pdo->prepare(
    'SELECT c.id, COALESCE(SUM(t.amount), 0) AS total
     FROM categories c
     LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = :user_id AND t.type = "expense"
     WHERE c.type = "expense" AND c.is_active = 1 AND ' . categoryScopeSql('c.') . '
     GROUP BY c.id
     HAVING total > 0'
);
$rep->execute(['user_id' => $userA] + categoryScopeParams($userA));
$rows = $rep->fetchAll();

$totals = [];
foreach ($rows as $r) { $totals[(int)$r['id']] = (int)$r['total']; }

T::same(250000, $totals[$aId] ?? 0, 'مبلغ روی دسته‌ی شخصی در گزارش دسته‌بندی می‌آید');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
