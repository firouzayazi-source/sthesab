<?php
/**
 * «شخصی‌سازیِ» یک دسته‌بندیِ پیش‌فرض — بردنش از فهرستِ عمومی به
 * دسته‌ی شخصیِ هر کاربری که واقعاً از آن استفاده کرده.
 *
 * ⛔ چهار خرابیِ بی‌صدا که این فایل برایشان نوشته شده:
 *
 *    ۱. **نشتیِ بینِ کاربران.** اگر `UPDATE` شرطِ `user_id` نداشته
 *       باشد، تراکنشِ کاربر A به دسته‌ی شخصیِ کاربر B می‌چسبد. تعدادِ
 *       ردیف‌ها همان‌قدر می‌ماند، هیچ خطایی نمی‌دهد، و جمعِ گزارشِ هر
 *       دو نفر عوض می‌شود. **سدِ شمارش این را نمی‌گیرد** — فقط
 *       سنجشِ مالکیت می‌گیرد.
 *
 *    ۲. **جدولِ جامانده.** چهار جدول به دسته اشاره می‌کنند و
 *       `categoryRefTables()` کشفشان می‌کند. اگر یکی جا بماند — مثلاً
 *       `category_pins` که تازه اضافه شده — پینِ کاربر بی‌صدا به
 *       دسته‌ای اشاره می‌کند که دیگر وجود ندارد، یا `DELETE` آخر با
 *       کلیدِ خارجی می‌خورد.
 *
 *    ۳. **تراکنشِ بی‌دسته.** `transactions.category_id` از نوعِ
 *       `ON DELETE SET NULL` است، پس حذفِ دسته پیش از انتقال، همه‌ی
 *       تراکنش‌ها را بی‌صدا بی‌دسته می‌کند و تاریخچه‌ی گزارش می‌پرد.
 *
 *    ۴. **بودجه‌ی نابودشده.** `budgets.category_id` از نوعِ
 *       `ON DELETE CASCADE` است — یعنی حذفِ دسته‌ای که تراکنش ندارد
 *       ولی بودجه دارد، بودجه‌ی کاربران را **با خودش می‌برد**. نگهبانِ
 *       قبلیِ حذف فقط `transactions` را می‌شمرد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('شخصی‌سازیِ دسته‌بندی');
    T::blocked('تست شخصی‌سازی دسته‌بندی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('شخصی‌سازیِ دسته‌بندی');
    T::blocked('تست شخصی‌سازی دسته‌بندی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableHasColumn('categories', 'user_id')) {
    T::group('شخصی‌سازیِ دسته‌بندی');
    T::skip('تست شخصی‌سازی دسته‌بندی', 'migration_user_categories هنوز اجرا نشده');
    exit(T::report());
}

$USERS   = ['__priv_a__', '__priv_b__', '__priv_c__'];
$CATNAME = '__دسته‌ی آزمایشیِ شخصی‌سازی__';

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

$dropCat = function () use ($pdo, $CATNAME) {
    // ردیف‌های ارجاع‌دهنده پیش از خودِ دسته پاک می‌شوند، وگرنه کلیدِ
    // خارجی جلوی حذف را می‌گیرد و پاک‌سازی نیمه‌کاره می‌ماند.
    $ids = $pdo->prepare('SELECT id FROM categories WHERE name = :n');
    $ids->execute(['n' => $CATNAME]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        foreach (array_keys(categoryRefTables()) as $tbl) {
            try { $pdo->prepare("DELETE FROM `$tbl` WHERE category_id = :c")->execute(['c' => $cid]); }
            catch (PDOException $e) { /* بی‌اهمیت */ }
        }
    }
    $pdo->prepare('DELETE FROM categories WHERE name = :n')->execute(['n' => $CATNAME]);
};

$cleanup = function () use ($purge, $dropCat, $USERS) {
    foreach ($USERS as $u) { try { $purge($u); } catch (Throwable $e) { /* ignore */ } }
    try { $dropCat(); } catch (Throwable $e) { /* ignore */ }
};

try {
    $cleanup();

    $mk = function (string $u) use ($pdo): int {
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تست شخصی‌سازی', :u, :p, 'user', 1)")
            ->execute(['u' => $u, 'p' => password_hash('PrivPass12345', PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    };
    [$uA, $uB, $uC] = [$mk($USERS[0]), $mk($USERS[1]), $mk($USERS[2])];

    // --- دسته‌ی پیش‌فرض (مالِ همه) ---
    $mkDefault = function () use ($pdo, $CATNAME): int {
        $cols = ['user_id', 'name', 'type', 'is_active'];
        $vals = ['NULL', ':n', "'expense'", '1'];
        $args = ['n' => $CATNAME];
        if (tableHasColumn('categories', 'icon'))  { $cols[] = 'icon';  $vals[] = "'shield'";  }
        if (tableHasColumn('categories', 'color')) { $cols[] = 'color'; $vals[] = "'#123456'"; }
        $pdo->prepare('INSERT INTO categories (' . implode(',', $cols) . ')
                       VALUES (' . implode(',', $vals) . ')')->execute($args);
        return (int)$pdo->lastInsertId();
    };
    $catId = $mkDefault();

    $wallet = function (int $uid) use ($pdo): int {
        $pdo->prepare("INSERT INTO wallets (user_id, name, kind, initial_balance, is_active, sort_order)
                       VALUES (:u, 'کیف پول', 'cash', 0, 1, 0)")->execute(['u' => $uid]);
        return (int)$pdo->lastInsertId();
    };
    $wA = $wallet($uA);
    $wB = $wallet($uB);

    $tx = function (int $uid, int $wid, int $cid) use ($pdo) {
        $pdo->prepare("INSERT INTO transactions (user_id, wallet_id, category_id, type, amount, title, transaction_date)
                       VALUES (:u, :w, :c, 'expense', 1000, 'آزمایشی', CURDATE())")
            ->execute(['u' => $uid, 'w' => $wid, 'c' => $cid]);
    };

    // A: دو تراکنش + یک بودجه + یک پین ؛ B: یک تراکنش ؛ C: هیچ.
    $tx($uA, $wA, $catId);
    $tx($uA, $wA, $catId);
    $tx($uB, $wB, $catId);
    $expectRows = 3;

    if (tableExists('budgets')) {
        $pdo->prepare("INSERT INTO budgets (user_id, category_id, amount, period_type)
                       VALUES (:u, :c, 500000, 'monthly')")->execute(['u' => $uA, 'c' => $catId]);
        $expectRows++;
    }
    if (tableExists('category_pins')) {
        $pdo->prepare('INSERT INTO category_pins (user_id, category_id) VALUES (:u, :c)')
            ->execute(['u' => $uA, 'c' => $catId]);
        $expectRows++;
    }

    // ---------------------------------------------------------------
    T::group('⛔ پیش از کار: چه کسانی از این دسته استفاده کرده‌اند');

    $refs = categoryRefTables();
    T::ok(isset($refs['transactions']), 'transactions در فهرستِ کشف‌شده هست');
    T::ok(count($refs) >= 2, 'بیش از یک جدول به دسته‌بندی اشاره می‌کند',
          'کشف‌شده: ' . implode('، ', array_keys($refs)));
    T::ok(!isset($refs['categories']), 'خودِ جدولِ categories در فهرست نیست');

    $usage = categoryUsage($catId);
    T::same(2, count($usage['users']), 'دو کاربر از آن استفاده کرده‌اند (نه سه)');
    T::ok(in_array($uA, $usage['users'], true) && in_array($uB, $usage['users'], true),
          'هر دو کاربرِ درست شناخته شدند');
    T::ok(!in_array($uC, $usage['users'], true),
          '⛔ کاربری که استفاده نکرده در فهرست نیست');
    T::same($expectRows, $usage['rows'], 'شمارشِ ردیف‌ها از همه‌ی جدول‌ها');
    T::same([], $usage['blocked'], 'هیچ ردیفِ بی‌صاحبی نیست');

    // ---------------------------------------------------------------
    T::group('شخصی‌سازی');

    $res = privatizeDefaultCategory($catId);
    T::ok($res['ok'], 'عملیات موفق بود', $res['message']);
    T::same(2, $res['users'], 'برای دو کاربر نسخه‌ی شخصی ساخته شد');
    T::same($expectRows, $res['rows'], 'همه‌ی ردیف‌ها منتقل شدند');

    $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :c');
    $st->execute(['c' => $catId]);
    T::same(0, (int)$st->fetchColumn(), '⛔ ردیفِ پیش‌فرض دیگر وجود ندارد');

    $own = function (int $uid) use ($pdo, $CATNAME) {
        $st = $pdo->prepare('SELECT * FROM categories WHERE user_id = :u AND name = :n');
        $st->execute(['u' => $uid, 'n' => $CATNAME]);
        return $st->fetchAll();
    };
    $ca = $own($uA);
    $cb = $own($uB);
    $cc = $own($uC);

    T::same(1, count($ca), 'کاربر A دقیقاً یک نسخه‌ی شخصی گرفت');
    T::same(1, count($cb), 'کاربر B دقیقاً یک نسخه‌ی شخصی گرفت');
    T::same(0, count($cc), '⛔ کاربر C — که استفاده نکرده بود — هیچ نسخه‌ای نگرفت');
    T::ok((int)$ca[0]['id'] !== (int)$cb[0]['id'], 'نسخه‌ی A و B دو ردیفِ جدا هستند');
    T::same('expense', $ca[0]['type'], 'نوعِ دسته حفظ شد');
    if (tableHasColumn('categories', 'icon')) {
        T::same('shield', $ca[0]['icon'], 'آیکون حفظ شد');
    }
    if (tableHasColumn('categories', 'color')) {
        T::same('#123456', $ca[0]['color'], 'رنگ حفظ شد');
    }

    // ---------------------------------------------------------------
    T::group('⛔ هیچ ردیفی به کاربرِ دیگری نچسبید');

    $idA = (int)$ca[0]['id'];
    $idB = (int)$cb[0]['id'];

    $cross = [];
    $mine  = [];
    foreach (array_keys($refs) as $tbl) {
        foreach ([[$uA, $idA, $idB], [$uB, $idB, $idA]] as [$uid, $ownId, $otherId]) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE user_id = :u AND category_id = :c");
            $st->execute(['u' => $uid, 'c' => $otherId]);
            if ((int)$st->fetchColumn() > 0) { $cross[] = $tbl; }

            $st->execute(['u' => $uid, 'c' => $ownId]);
            $mine[$uid] = ($mine[$uid] ?? 0) + (int)$st->fetchColumn();
        }
    }
    T::bulk(count($refs) * 2, $cross, '⛔ هیچ ردیفی به دسته‌ی کاربرِ دیگر وصل نشد');
    T::same($expectRows - 1, $mine[$uA] ?? 0, 'همه‌ی ردیف‌های A زیرِ دسته‌ی خودش‌اند');
    T::same(1, $mine[$uB] ?? 0, 'ردیفِ B زیرِ دسته‌ی خودش است');

    $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND category_id IS NULL');
    $st->execute(['u' => $uA]);
    T::same(0, (int)$st->fetchColumn(), '⛔ هیچ تراکنشی بی‌دسته نشد (SET NULL نخورد)');

    if (tableExists('budgets')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM budgets WHERE user_id = :u');
        $st->execute(['u' => $uA]);
        T::same(1, (int)$st->fetchColumn(), '⛔ بودجه پاک نشد (CASCADE نخورد)');
    }
    if (tableExists('category_pins')) {
        $st = $pdo->prepare('SELECT category_id FROM category_pins WHERE user_id = :u');
        $st->execute(['u' => $uA]);
        T::same($idA, (int)$st->fetchColumn(), '⛔ پین به نسخه‌ی شخصیِ خودش منتقل شد');
    }

    // ---------------------------------------------------------------
    T::group('⛔ فهرستِ هر کاربر بعد از کار');

    $visible = function (int $uid) use ($pdo, $CATNAME): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM categories c
                             WHERE c.name = :n AND ' . categoryScopeSql('c.'));
        $st->execute(categoryScopeParams($uid) + ['n' => $CATNAME]);
        return (int)$st->fetchColumn();
    };
    T::same(1, $visible($uA), 'کاربر A همچنان دسته را می‌بیند (حالا شخصی)');
    T::same(1, $visible($uB), 'کاربر B همچنان می‌بیند');
    T::same(0, $visible($uC), '⛔ کاربر C دیگر آن را در فهرستش نمی‌بیند');

    // ---------------------------------------------------------------
    T::group('دوباره اجرا کردن، و نسخه‌ی شخصیِ از قبل موجود');

    $again = privatizeDefaultCategory($catId);
    T::ok(!$again['ok'], 'اجرای دوباره روی همان شناسه رد می‌شود');

    // ⛔ «رد شد» کافی نیست، **علتش** هم سنجیده می‌شود: با برداشتنِ
    //    نگهبانِ `user_id IS NULL` این فراخوانی باز هم `ok=false`
    //    می‌دهد — ولی از راهِ سدِ شمارشِ پیش از commit، نه از راهِ
    //    نگهبان. یعنی بررسیِ ساده به هر دو یک جواب می‌داد و **جهشش
    //    زنده می‌ماند**. متنِ پیام این دو را از هم جدا می‌کند.
    $onPersonal = privatizeDefaultCategory($idA);
    T::ok(!$onPersonal['ok'],
          '⛔ روی یک دسته‌ی شخصی اصلاً کار نمی‌کند (فقط پیش‌فرض)');
    T::ok(strpos($onPersonal['message'], 'پیش‌فرض با این شناسه پیدا نشد') !== false,
          '⛔ و به دلیلِ درست رد می‌شود، نه اتفاقی', $onPersonal['message']);

    $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
    $st->execute(['id' => $idA]);
    T::same(1, (int)$st->fetchColumn(), 'دسته‌ی شخصیِ A دست‌نخورده ماند');

    // کاربر C از قبل یک دسته‌ی شخصیِ هم‌نام دارد → نسخه‌ی دوم ساخته نشود.
    $cat2 = $mkDefault();
    $pdo->prepare("INSERT INTO categories (user_id, name, type, is_active)
                   VALUES (:u, :n, 'expense', 1)")->execute(['u' => $uC, 'n' => $CATNAME]);
    $wC = $wallet($uC);
    $tx($uC, $wC, $cat2);

    $res2 = privatizeDefaultCategory($cat2);
    T::ok($res2['ok'], 'شخصی‌سازیِ دوم موفق بود', $res2['message']);
    T::same(1, count($own($uC)), '⛔ دسته‌ی هم‌نامِ موجود دوباره ساخته نشد');

    $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND category_id = :c');
    $st->execute(['u' => $uC, 'c' => (int)$own($uC)[0]['id']]);
    T::same(1, (int)$st->fetchColumn(), 'تراکنشش به همان دسته‌ی موجود وصل شد');

    // ---------------------------------------------------------------
    // ⛔ نگهبانِ حذف باید **همه‌ی** جدول‌ها را ببیند، نه فقط تراکنش‌ها.
    //    `budgets.category_id` کلیدِ خارجی با `ON DELETE CASCADE` دارد،
    //    پس دسته‌ای که هیچ تراکنشی ندارد ولی رویش بودجه بسته شده، با
    //    حذف، **بودجه‌ی کاربر را هم می‌برد** — بی‌هیچ خطایی.
    if (tableExists('budgets')) {
        T::group('⛔ نگهبانِ حذف: دسته‌ای که فقط بودجه دارد');

        $cat3 = $mkDefault();
        $pdo->prepare("INSERT INTO budgets (user_id, category_id, amount, period_type)
                       VALUES (:u, :c, 700000, 'weekly')")->execute(['u' => $uB, 'c' => $cat3]);

        $u3 = categoryUsage($cat3);
        $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE category_id = :c');
        $st->execute(['c' => $cat3]);
        T::same(0, (int)$st->fetchColumn(), 'این دسته هیچ تراکنشی ندارد');
        T::ok($u3['rows'] > 0,
              '⛔ ولی نگهبان می‌بیند که خالی نیست — پس حذف نمی‌شود',
              'per: ' . json_encode($u3['per'], JSON_UNESCAPED_UNICODE));
        T::ok(isset($u3['per']['budgets']), 'و صریح می‌گوید کجا: budgets');

        // و شخصی‌سازی همان را درست منتقل می‌کند.
        $r3 = privatizeDefaultCategory($cat3);
        T::ok($r3['ok'], 'شخصی‌سازی‌اش هم کار می‌کند', $r3['message']);
        $st = $pdo->prepare('SELECT COUNT(*) FROM budgets WHERE user_id = :u AND period_type = :p');
        $st->execute(['u' => $uB, 'p' => 'weekly']);
        T::same(1, (int)$st->fetchColumn(), '⛔ بودجه سرِ جایش ماند');
    }

} catch (Throwable $e) {
    T::group('خطا');
    T::ok(false, 'اجرای تست', $e->getMessage());
} finally {
    $cleanup();
}

exit(T::report());
