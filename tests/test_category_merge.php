<?php
/**
 * ادغامِ دو دسته‌بندیِ هم‌معنا — «حمل و نقل» و «حمل‌ونقل» یکی می‌شوند.
 *
 * ⛔ شش خرابیِ بی‌صدا که این فایل برایشان نوشته شده:
 *
 *    ۱. **نشتیِ بینِ کاربران.** در دامنه‌ی کاربر، اگر `UPDATE` شرطِ
 *       `user_id` نداشته باشد تراکنشِ یک نفر به دسته‌ی نفرِ دیگر
 *       می‌چسبد. تعدادِ ردیف‌ها همان‌قدر می‌ماند و هیچ خطایی نیست.
 *
 *    ۲. **ادغامِ هزینه در درآمد.** هیچ خطایی نمی‌دهد و فقط گزارشِ
 *       درآمدِ ماه را با خرج باد می‌کند — یعنی عددی که کاربر رویش
 *       تصمیم می‌گیرد، بی‌آنکه بفهمد، دروغ می‌شود.
 *
 *    ۳. **برخوردِ کلیدِ یکتا.** `category_pins` کلیدِ اصلی‌اش
 *       `(user_id, category_id)` است و `budgets` کلیدِ یکتای
 *       `(user_id, category_id, period_type)`. اگر کسی **هر دو** دسته
 *       را پین کرده باشد، `UPDATE` با خطای کلیدِ تکراری می‌میرد —
 *       ولی فقط روی نصبی که این اتفاق افتاده. یعنی در آزمایش سالم به
 *       نظر می‌رسد و روی دیتابیسِ واقعی می‌ترکد.
 *
 *    ۴. **انداختنِ بی‌صدای یک بودجه.** ردیفِ `budgets` عددی است که
 *       کاربر تایپ کرده؛ حلِ برخورد با «یکی را بینداز» یعنی نابود
 *       کردنِ کارِ او. اینجا صریح امتناع می‌شود.
 *
 *    ۵. **حذفِ مبدأ پیش از انتقال.** `transactions.category_id` از
 *       نوعِ `SET NULL` است و `budgets.category_id` از نوعِ `CASCADE`
 *       — یعنی حذفِ زودهنگام هم تاریخچه را بی‌دسته می‌کند هم بودجه‌ها
 *       را می‌برد.
 *
 *    ۶. **کاربر عادی یک دسته‌ی پیش‌فرض را از فهرستِ همه بردارد.**
 *       مبدأ در دامنه‌ی کاربر باید حتماً شخصیِ خودش باشد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('ادغامِ دسته‌بندی');
    T::blocked('تست ادغام دسته‌بندی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('ادغامِ دسته‌بندی');
    T::blocked('تست ادغام دسته‌بندی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableHasColumn('categories', 'user_id')) {
    T::group('ادغامِ دسته‌بندی');
    T::skip('تست ادغام دسته‌بندی', 'migration_user_categories هنوز اجرا نشده');
    exit(T::report());
}

$USERS = ['__merge_a__', '__merge_b__'];
$NAMES = ['__ادغام مبدأ__', '__ادغام مقصد__', '__ادغام شخصی__', '__ادغام درآمد__'];

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    $t = userDataTables();
    for ($i = 0; $i < 5 && $t; $i++) {
        $left = [];
        foreach ($t as $x) {
            try { $pdo->prepare("DELETE FROM `$x` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { $left[] = $x; }
        }
        if (count($left) === count($t)) { break; }
        $t = $left;
    }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};

$dropCats = function () use ($pdo, $NAMES) {
    foreach ($NAMES as $n) {
        $ids = $pdo->prepare('SELECT id FROM categories WHERE name = :n');
        $ids->execute(['n' => $n]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            foreach (array_keys(categoryRefTables()) as $tbl) {
                try { $pdo->prepare("DELETE FROM `$tbl` WHERE category_id = :c")->execute(['c' => $cid]); }
                catch (PDOException $e) { /* بی‌اهمیت */ }
            }
        }
        $pdo->prepare('DELETE FROM categories WHERE name = :n')->execute(['n' => $n]);
    }
};

$cleanup = function () use ($purge, $dropCats, $USERS) {
    foreach ($USERS as $u) { try { $purge($u); } catch (Throwable $e) { /* ignore */ } }
    try { $dropCats(); } catch (Throwable $e) { /* ignore */ }
};

/** تعداد ردیف‌های یک جدول که به یک دسته اشاره می‌کنند (اختیاری: برای یک کاربر). */
$refCount = function (string $table, int $catId, ?int $uid = null) use ($pdo): int {
    $sql  = "SELECT COUNT(*) FROM `{$table}` WHERE category_id = :c";
    $args = ['c' => $catId];
    if ($uid !== null) { $sql .= ' AND user_id = :u'; $args['u'] = $uid; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
};

$catExists = function (int $id) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :i');
    $st->execute(['i' => $id]);
    return (int)$st->fetchColumn() > 0;
};

try {
    $cleanup();

    $mkUser = function (string $u) use ($pdo): int {
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تست ادغام', :u, :p, 'user', 1)")
            ->execute(['u' => $u, 'p' => password_hash('MergePass12345', PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    };
    [$uA, $uB] = [$mkUser($USERS[0]), $mkUser($USERS[1])];

    $mkCat = function (string $name, ?int $owner, string $type = 'expense') use ($pdo): int {
        $pdo->prepare('INSERT INTO categories (user_id, name, type, is_active)
                       VALUES (:u, :n, :t, 1)')
            ->execute(['u' => $owner, 'n' => $name, 't' => $type]);
        return (int)$pdo->lastInsertId();
    };

    $mkWallet = function (int $uid) use ($pdo): int {
        $pdo->prepare("INSERT INTO wallets (user_id, name, kind, initial_balance, is_active, sort_order)
                       VALUES (:u, 'کیف پول', 'cash', 0, 1, 0)")->execute(['u' => $uid]);
        return (int)$pdo->lastInsertId();
    };
    $wA = $mkWallet($uA);
    $wB = $mkWallet($uB);

    $mkTx = function (int $uid, int $wid, int $catId) use ($pdo): void {
        $pdo->prepare("INSERT INTO transactions (user_id, wallet_id, category_id, type, amount, title, transaction_date)
                       VALUES (:u, :w, :c, 'expense', 1000, 'تستِ ادغام', CURDATE())")
            ->execute(['u' => $uid, 'w' => $wid, 'c' => $catId]);
    };

    // ===========================================================
    T::group('کشفِ کلیدهای یکتا');

    $uniq = categoryUniqueKeys();
    // ⛔ بدونِ این کشف، برخوردِ کلید فقط روی نصبی می‌ترکید که کسی
    //    هر دو دسته را پین کرده — یعنی در آزمایش نامرئی.
    T::ok(isset($uniq['category_pins']), 'کلیدِ یکتای category_pins کشف می‌شود');
    T::ok(isset($uniq['budgets']),       'کلیدِ یکتای budgets کشف می‌شود');
    T::ok(!isset($uniq['transactions']), 'transactions کلیدِ یکتا روی category_id ندارد');

    // ===========================================================
    T::group('⛔ دامنه‌ی مدیر — ردیف‌های همه‌ی کاربران جابه‌جا می‌شوند');

    $from = $mkCat($NAMES[0], null);
    $into = $mkCat($NAMES[1], null);

    $mkTx($uA, $wA, $from); $mkTx($uA, $wA, $from); $mkTx($uA, $wA, $from);  // A: ۳ تا
    $mkTx($uB, $wB, $from);                                                  // B: ۱ تا
    $mkTx($uA, $wA, $into);                                                  // A روی مقصد: ۱ تا

    $pdo->prepare("INSERT INTO budgets (user_id, category_id, amount, period_type, is_active)
                   VALUES (:u, :c, 500000, 'monthly', 1)")->execute(['u' => $uA, 'c' => $from]);
    if (tableExists('category_pins')) {
        $pdo->prepare('INSERT INTO category_pins (user_id, category_id) VALUES (:u, :c)')
            ->execute(['u' => $uB, 'c' => $from]);
    }

    $res = mergeCategories($from, $into, null);
    T::ok($res['ok'], 'ادغامِ دو پیش‌فرض انجام می‌شود', $res['message']);
    T::same(false, $catExists($from), '⛔ دسته‌ی مبدأ حذف می‌شود');
    T::same(true,  $catExists($into), 'دسته‌ی مقصد سرِ جایش می‌ماند');
    T::same(0, $refCount('transactions', $from), 'هیچ تراکنشی روی شناسه‌ی قدیمی نمی‌ماند');
    T::same(5, $refCount('transactions', $into), 'هر ۵ تراکنش زیرِ مقصد جمع شدند');

    // ⛔ نشتی: سهمِ هر کاربر باید دقیقاً همان باشد که بود.
    T::same(4, $refCount('transactions', $into, $uA), 'سهمِ کاربر A دست‌نخورده است (۳+۱)');
    T::same(1, $refCount('transactions', $into, $uB), 'سهمِ کاربر B دست‌نخورده است');

    T::same(1, $refCount('budgets', $into, $uA), 'بودجه هم منتقل شد، نه حذف');
    if (tableExists('category_pins')) {
        T::same(1, $refCount('category_pins', $into, $uB), 'پین هم منتقل شد');
    }

    // ⛔ هیچ تراکنشی بی‌دسته نشد — همان دامِ `ON DELETE SET NULL`.
    $orphan = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id IN (:a, :b) AND category_id IS NULL');
    $orphan->execute(['a' => $uA, 'b' => $uB]);
    T::same(0, (int)$orphan->fetchColumn(), '⛔ هیچ تراکنشی بی‌دسته نشد');

    // ===========================================================
    T::group('نگهبان‌ها');

    $dropCats();
    $from = $mkCat($NAMES[0], null);
    $into = $mkCat($NAMES[1], null);
    $inc  = $mkCat($NAMES[3], null, 'income');

    $r = mergeCategories($from, $from, null);
    T::same(false, $r['ok'], 'ادغامِ یک دسته در خودش رد می‌شود');

    // ⛔ نوعِ ناهمخوان: گزارشِ درآمد را با خرج باد می‌کند، بی‌هیچ خطایی.
    $r = mergeCategories($from, $inc, null);
    T::same(false, $r['ok'], '⛔ ادغامِ هزینه در درآمد رد می‌شود');
    T::ok(mb_strpos($r['message'], 'نوع') !== false, 'و پیامش می‌گوید چرا');
    T::same(true, $catExists($from), 'و هیچ چیزی حذف نشده');

    $r = mergeCategories(999999, $into, null);
    T::same(false, $r['ok'], 'شناسه‌ی ناموجود رد می‌شود');

    // ===========================================================
    T::group('⛔ دامنه‌ی کاربر — فقط دسته‌ی شخصیِ خودش');

    $dropCats();
    $shared   = $mkCat($NAMES[1], null);          // پیش‌فرضِ برنامه
    $personal = $mkCat($NAMES[2], $uA);           // شخصیِ کاربر A
    $otherOwn = $mkCat($NAMES[0], $uB);           // شخصیِ کاربر B

    $mkTx($uA, $wA, $personal); $mkTx($uA, $wA, $personal);
    $mkTx($uB, $wB, $otherOwn);

    // ⛔ کاربر عادی نباید بتواند یک پیش‌فرض را از فهرستِ همه بردارد.
    $r = mergeCategories($shared, $personal, $uA);
    T::same(false, $r['ok'], '⛔ مبدأِ پیش‌فرض در دامنه‌ی کاربر رد می‌شود');
    T::ok(mb_strpos($r['message'], 'شخصی') !== false, 'و پیامش می‌گوید مبدأ باید شخصی باشد');
    T::same(true, $catExists($shared), 'و پیش‌فرض دست‌نخورده می‌ماند');

    // ⛔ دسته‌ی شخصیِ کاربرِ دیگر هم مبدأ نمی‌شود.
    $r = mergeCategories($otherOwn, $shared, $uA);
    T::same(false, $r['ok'], '⛔ دسته‌ی شخصیِ کاربرِ دیگر مبدأ نمی‌شود');
    T::same(1, $refCount('transactions', $otherOwn, $uB), 'و ردیف‌های او دست‌نخورده‌اند');

    // مسیرِ درست: شخصیِ خودش → پیش‌فرض
    $r = mergeCategories($personal, $shared, $uA);
    T::ok($r['ok'], 'ادغامِ دسته‌ی شخصی در پیش‌فرض انجام می‌شود', $r['message']);
    T::same(false, $catExists($personal), 'دسته‌ی شخصیِ مبدأ حذف شد');
    T::same(2, $refCount('transactions', $shared, $uA), 'هر دو تراکنشِ A منتقل شدند');
    T::same(0, $refCount('transactions', $shared, $uB), '⛔ ردیفی از کاربر B به اینجا نیامد');

    // ===========================================================
    T::group('⛔ برخوردِ کلیدِ یکتا');

    if (tableExists('category_pins')) {
        $dropCats();
        $from = $mkCat($NAMES[0], null);
        $into = $mkCat($NAMES[1], null);

        // کاربر A **هر دو** را پین کرده — دقیقاً حالتی که `UPDATE` خام
        // را با «Duplicate entry» می‌کشت.
        $pdo->prepare('INSERT INTO category_pins (user_id, category_id) VALUES (:u, :c)')
            ->execute(['u' => $uA, 'c' => $from]);
        $pdo->prepare('INSERT INTO category_pins (user_id, category_id) VALUES (:u, :c)')
            ->execute(['u' => $uA, 'c' => $into]);

        $r = mergeCategories($from, $into, null);
        T::ok($r['ok'], '⛔ پینِ تکراری ادغام را نمی‌شکند', $r['message']);
        T::same(1, $refCount('category_pins', $into, $uA), 'و از دو پین یکی می‌ماند');
        T::ok($r['dropped'] >= 1, 'و تعدادِ انداخته‌شده گزارش می‌شود');
        T::same(false, $catExists($from), 'و مبدأ حذف شد');
    } else {
        T::skip('برخوردِ پین', 'جدول category_pins نیامده');
    }

    // ⛔ بودجه‌ی تکراری صریح امتناع می‌شود، نه اینکه یکی بی‌صدا بیفتد.
    $dropCats();
    $from = $mkCat($NAMES[0], null);
    $into = $mkCat($NAMES[1], null);
    $pdo->prepare("INSERT INTO budgets (user_id, category_id, amount, period_type, is_active)
                   VALUES (:u, :c, 111000, 'monthly', 1)")->execute(['u' => $uA, 'c' => $from]);
    $pdo->prepare("INSERT INTO budgets (user_id, category_id, amount, period_type, is_active)
                   VALUES (:u, :c, 222000, 'monthly', 1)")->execute(['u' => $uA, 'c' => $into]);
    $mkTx($uA, $wA, $from);

    $r = mergeCategories($from, $into, null);
    T::same(false, $r['ok'], '⛔ بودجه‌ی تکراری ادغام را رد می‌کند');
    T::ok(mb_strpos($r['message'], 'budgets') !== false, 'و پیام نامِ جدول را می‌گوید');
    T::same(true, $catExists($from), 'و هیچ چیزی حذف نشد');
    T::same(1, $refCount('transactions', $from, $uA), '⛔ و هیچ ردیفی هم جابه‌جا نشد (rollback)');
    $amt = $pdo->prepare('SELECT amount FROM budgets WHERE category_id = :c AND user_id = :u');
    $amt->execute(['c' => $from, 'u' => $uA]);
    T::same('111000', (string)(int)$amt->fetchColumn(), '⛔ بودجه‌ی کاربر دست‌نخورده ماند');

} catch (Throwable $e) {
    T::ok(false, 'اجرای تستِ ادغام', $e->getMessage());
} finally {
    $cleanup();
}

exit(T::report());
