<?php
/**
 * آمارِ صفحه‌ی مدیر — «آخرین استفاده»، قیفِ شروع، و پذیرشِ قابلیت‌ها.
 *
 * ⛔ **خرابیِ واقعی که این تست برایش نوشته شد، و مالکِ نصب گزارشش کرد:**
 *    «آخرین استفاده» فقط از جدولِ `transactions` خوانده می‌شد. روی همان
 *    دیتابیس سنجیده شد: کاربری با سه چک، سه طلب و یک یادآور — همه ثبت‌شده
 *    در یک روزِ مشخص — روی صفحه «هرگز شروع نکرده» بود و زیرِ نامش نوشته
 *    شده بود «هنوز شروع نکرده». نه خطایی، نه نشانه‌ای، فقط یک عددِ غلط که
 *    تصمیمِ محصولی رویش سوار می‌شد.
 *
 *    و برای **این** اپ بدترین شکلِ ممکن بود: چک و طلب و بدهی همان چیزی‌اند
 *    که اپ را از رقبای خارجی جدا می‌کنند، پس دقیقاً کاربری که بیشترین
 *    استفاده را از تفاوتِ اپ می‌کرد، نامرئی می‌شد.
 *
 * ⛔ و سمتِ دیگرِ همان خطر: اگر «فعالیت» را از **همه‌ی** جدول‌ها بخوانیم،
 *    `wallets` و `banks` و `asset_types` — که هنگامِ ثبت‌نام خودکار ساخته
 *    می‌شوند — هر کاربرِ تازه‌ای را در همان ثانیه‌ی اول «فعال» می‌کنند و
 *    قیفِ شروع دقیقاً همان افتی را پنهان می‌کند که برای دیدنش ساخته شده.
 *    پس فهرست **گزینشی و بسته** است، و گروهِ اول همین را نگه می‌دارد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('آمارِ صفحه‌ی مدیر');
    T::blocked('تست آمار مدیر', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/admin_insights.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('آمارِ صفحه‌ی مدیر');
    T::blocked('تست آمار مدیر', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
// قربانی‌ها را خودمان می‌سازیم — تکیه بر داده‌ی موجود یعنی روی دیتابیسِ
// خالی تست بی‌صدا رد می‌شود و هیچ چیزی را نگه نمی‌دارد.
$USERS = ['__stat_tx__', '__stat_cheque__', '__stat_idle__', '__stat_empty__'];

$wipe = function () use ($pdo, $USERS) {
    foreach ($USERS as $u) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $st->execute(['u' => $u]);
        $id = $st->fetchColumn();
        if (!$id) { continue; }
        // فهرست از خودِ دیتابیس کشف می‌شود، نه دستی — همان درسِ
        // `test_api_auth`: یک ردیفِ جامانده کلیدِ خارجی را نگه می‌دارد و
        // بعد **کلِ** تست با «کاربر ساخته نشد» رد می‌شود.
        $tables = userDataTables();
        for ($pass = 0; $pass < 4 && $tables; $pass++) {
            $left = [];
            foreach ($tables as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
                catch (PDOException $e) { $left[] = $t; }
            }
            if (count($left) === count($tables)) { break; }
            $tables = $left;
        }
        $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
    }
};

$mk = function (string $u) use ($pdo): int {
    $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES (:n, :u, :p, "user", 1)')
        ->execute(['n' => 'تست ' . $u, 'u' => $u, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};

/** ردیفِ همان کاربر از خروجیِ `userActivity()`. */
$row = function (string $username) {
    foreach (userActivity() as $r) {
        if ($r['username'] === $username) { return $r; }
    }
    return null;
};

$fail = null;

try {
    $wipe();

    $uTx     = $mk('__stat_tx__');      // فقط تراکنش، همین امروز
    $uCheque = $mk('__stat_cheque__');  // هیچ تراکنشی ندارد، فقط چک و طلب
    $uIdle   = $mk('__stat_idle__');    // رکورد دارد ولی خیلی قدیمی
    $uEmpty  = $mk('__stat_empty__');   // حساب دارد و هیچ چیز دیگر

    // ⛔ `__stat_empty__` عمداً یک کیف پول می‌گیرد — دقیقاً همان چیزی که
    //    `createUserAccount()` به هر کاربرِ تازه می‌دهد. اگر `wallets`
    //    «فعالیت» شمرده شود، این کاربر «فعالِ امروز» می‌شود و همان باگی
    //    که بالا توضیح داده شد برمی‌گردد.
    $addWallet = function (int $uid, string $name) use ($pdo): int {
        $pdo->prepare('INSERT INTO wallets (user_id, name, kind, initial_balance, is_active, sort_order)
                       VALUES (:u, :n, "cash", 0, 1, 0)')
            ->execute(['u' => $uid, 'n' => $name]);
        return (int)$pdo->lastInsertId();
    };
    foreach ([$uTx, $uCheque, $uIdle, $uEmpty] as $uid) { $addWallet($uid, 'کیف پول'); }

    $txStmt = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, title, transaction_date)
                             VALUES (:u, "expense", 1000, "تست", CURDATE())');
    for ($i = 0; $i < 6; $i++) { $txStmt->execute(['u' => $uTx]); }

    $chStmt = $pdo->prepare('INSERT INTO cheques (user_id, direction, amount, due_date, counterparty_name)
                             VALUES (:u, "received", 5000, CURDATE(), "طرف")');
    for ($i = 0; $i < 3; $i++) { $chStmt->execute(['u' => $uCheque]); }
    $dbStmt = $pdo->prepare('INSERT INTO debts (user_id, direction, amount, counterparty_name, entry_date, due_date)
                             VALUES (:u, "payable", 7000, "طرف", CURDATE(), CURDATE())');
    for ($i = 0; $i < 3; $i++) { $dbStmt->execute(['u' => $uCheque]); }

    // کاربرِ کم‌فعال: یک رکوردِ خیلی قدیمی
    $pdo->prepare('INSERT INTO people (user_id, name, role, created_at)
                   VALUES (:u, "طرفِ قدیمی", "سایر", DATE_SUB(NOW(), INTERVAL :d DAY))')
        ->execute(['u' => $uIdle, 'd' => ACTIVE_DAYS + 20]);

    // ---------------------------------------------------------------
    T::group('⛔ فهرستِ جدول‌های فعالیت بسته است');

    // هر جدولی که ستونِ `user_id` دارد باید در **یکی** از دو فهرست باشد.
    // بدونِ این، جدولِ فردا بی‌صدا بیرونِ آمار می‌ماند — همان قاعده‌ی
    // `MIGRATIONS` و `EXPECT` و `BUDGET`.
    $known   = array_merge(ACTIVITY_TABLES, array_keys(NON_ACTIVITY_TABLES));
    $unknown = array_values(array_diff(userDataTables(), $known));
    T::bulk(count(userDataTables()), array_map(
        fn($t) => "{$t} — نه در ACTIVITY_TABLES است نه در NON_ACTIVITY_TABLES",
        $unknown
    ), 'هر جدولِ کاربردار در یکی از دو فهرست طبقه‌بندی شده');

    $both = array_values(array_intersect(ACTIVITY_TABLES, array_keys(NON_ACTIVITY_TABLES)));
    T::bulk(count(ACTIVITY_TABLES), array_map(fn($t) => "{$t} در هر دو فهرست است", $both),
            'هیچ جدولی در هر دو فهرست نیست');

    // ⛔ سه جدولِ seed شده باید **بیرون** بمانند، و این بررسی جدا لازم
    //    است: بدونِ آن، «فهرست بسته است» با گذاشتنِ همه در ACTIVITY هم
    //    سبز می‌ماند.
    foreach (['wallets', 'banks', 'asset_types'] as $seeded) {
        T::ok(!in_array($seeded, ACTIVITY_TABLES, true),
              "{$seeded} فعالیت شمرده نمی‌شود (هنگام ثبت‌نام خودکار ساخته می‌شود)");
    }
    T::ok(in_array('transactions', ACTIVITY_TABLES, true), 'transactions در فهرستِ فعالیت است');
    T::ok(in_array('cheques', ACTIVITY_TABLES, true), 'cheques در فهرستِ فعالیت است');
    T::ok(in_array('debts', ACTIVITY_TABLES, true), 'debts در فهرستِ فعالیت است');

    // ---------------------------------------------------------------
    T::group('⛔ «آخرین استفاده» فقط تراکنش نیست');

    $r = $row('__stat_cheque__');
    T::ok($r !== null, 'کاربرِ چک‌دار در خروجی هست');
    T::same(0, (int)$r['tx'], 'تراکنشش صفر است');
    T::same(6, (int)$r['records'], 'شش رکورد دارد (سه چک + سه طلب)');
    T::ok($r['days_since'] !== null, '⛔ «آخرین استفاده» دارد — همان باگی که گزارش شد');
    T::same(0, (int)$r['days_since'], 'امروز ثبت کرده، پس صفر روز');
    T::same('active', $r['state'], '⛔ «فعال» است، نه «هرگز شروع نکرده»');

    $r = $row('__stat_tx__');
    T::same(6, (int)$r['tx'], 'کاربرِ تراکنشی شش تراکنش دارد');
    T::same(6, (int)$r['records'], 'و همان شش رکوردش است');
    T::same('active', $r['state'], 'فعال است');

    $r = $row('__stat_idle__');
    T::same(1, (int)$r['records'], 'کاربرِ کم‌فعال یک رکورد دارد');
    T::ok((int)$r['days_since'] > ACTIVE_DAYS, 'فاصله‌اش از مرز بیشتر است');
    T::same('stale', $r['state'], 'پس «کم‌فعال» است، نه «فعال»');

    // ---------------------------------------------------------------
    T::group('⛔ کیف پولِ پیش‌فرض «فعالیت» نیست');

    $r = $row('__stat_empty__');
    T::same(0, (int)$r['records'], 'کاربری که فقط کیف پولِ پیش‌فرض دارد، صفر رکورد');
    T::same(null, $r['days_since'], 'پس «آخرین استفاده» ندارد');
    T::same('never', $r['state'], '⛔ «هرگز شروع نکرده» — وگرنه قیف دروغ می‌گفت');

    // ---------------------------------------------------------------
    T::group('⛔ تاریخِ جلوتر از امروز «روزِ پیش» خوانده نمی‌شود');

    // ⚠ `diff()->days` قدرِ مطلق می‌داد: یک `created_at`ِ جلوتر (پرشِ
    //   ساعتِ سرور، یا ردیفی که پیش از هم‌تراز شدنِ منطقه‌ی زمانی نوشته
    //   شده) به‌صورت «۳ روز پیش» خوانده می‌شد — عددی که هیچ‌کس به آن شک
    //   نمی‌کند چون شکلش کاملاً عادی است.
    $pdo->prepare('UPDATE people SET created_at = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE user_id = :u')
        ->execute(['u' => $uIdle]);
    $r = $row('__stat_idle__');
    T::same(0, (int)$r['days_since'], 'تاریخِ آینده به صفر («امروز») بریده می‌شود');
    T::same('active', $r['state'], 'و «فعال» می‌ماند، نه «۳ روز پیش»');
    $pdo->prepare('UPDATE people SET created_at = DATE_SUB(NOW(), INTERVAL :d DAY) WHERE user_id = :u')
        ->execute(['u' => $uIdle, 'd' => ACTIVE_DAYS + 20]);

    // ---------------------------------------------------------------
    T::group('⛔ قیفِ شروع روی رکورد شمرده می‌شود، نه تراکنش');

    $act    = userActivity();
    $funnel = onboardingFunnel($act);

    T::same(count($act), (int)$funnel[0]['n'], 'پله‌ی اول همه‌ی کاربران است');
    T::same(100, (int)$funnel[0]['pc'], 'و صد درصد');

    $names = array_column($act, 'username');
    $oneOf = function (string $u) use ($act) {
        foreach ($act as $r) { if ($r['username'] === $u) { return $r; } }
        return null;
    };
    // کاربرِ چک‌دار باید در پله‌ی دوم **و سوم** شمرده شود
    T::ok((int)$oneOf('__stat_cheque__')['records'] >= 5,
          'کاربرِ چک‌دار ≥۵ رکورد دارد، پس در پله‌ی سوم هم می‌آید');

    // ⛔ پله‌ی چهارم باید زیرمجموعه‌ی پله‌ی دوم بماند، وگرنه
    //    `funnelVerdict()` درصدی بالای ۱۰۰ می‌سازد و حکمش بی‌معنا می‌شود.
    T::ok((int)$funnel[3]['n'] <= (int)$funnel[1]['n'],
          '⛔ «فعال» هرگز از «شروع کرده» بیشتر نمی‌شود');
    T::ok((int)$funnel[2]['n'] <= (int)$funnel[1]['n'],
          '«۵ رکورد» هرگز از «شروع کرده» بیشتر نمی‌شود');

    // برچسبِ پله‌ی چهارم از همان ثابت می‌آید
    T::ok(strpos($funnel[3]['label'], toPersianDigits((string)ACTIVE_DAYS)) !== false,
          'برچسبِ پله‌ی چهارم از ACTIVE_DAYS می‌آید، نه عددِ سخت‌کد');

    // ---------------------------------------------------------------
    T::group('⛔ پذیرشِ قابلیت: کیف پولِ پیش‌فرض «استفاده» نیست');

    $ad    = featureAdoption(count($act));
    $byLbl = [];
    foreach ($ad as $f) { $byLbl[$f['label']] = $f; }

    $walletRow = null;
    foreach ($ad as $f) { if (strpos($f['label'], 'حساب') === 0) { $walletRow = $f; } }
    T::ok($walletRow !== null, 'ردیفِ حساب در فهرست هست');
    T::ok(strpos($walletRow['label'], 'پیش‌فرض') !== false,
          'برچسبش می‌گوید حسابِ دوم است، نه «حساب و کیف پول»');

    // هر چهار کاربرِ تست یک کیف پول دارند و هیچ‌کدام نباید شمرده شوند
    $before = (int)$walletRow['users'];
    $addWallet($uEmpty, 'حساب دوم');
    $ad2 = featureAdoption(count($act));
    $after = 0;
    foreach ($ad2 as $f) { if (strpos($f['label'], 'حساب') === 0) { $after = (int)$f['users']; } }
    T::same($before + 1, $after, '⛔ فقط کاربری شمرده می‌شود که حسابِ دوم ساخته');

    // ---------------------------------------------------------------
    T::group('⛔ آستانه‌ی «فعال» فقط یک جا تعریف شده');

    T::same(14, ACTIVE_DAYS, 'مقدارِ ثابت همان ۱۴ روز است');

    // ⚠ عددِ مرزی **ثابت** نوشته می‌شود، نه `ACTIVE_DAYS - 1` — وگرنه
    //   تست مقدارش را از کدِ زیرِ آزمون می‌گیرد و با عوض شدنِ ثابت مرزش
    //   هم جابه‌جا می‌شود. همان دامی که یک بار سرِ `BACKUP_AFTER_DAYS`
    //   افتادیم.
    $pdo->prepare('UPDATE people SET created_at = DATE_SUB(NOW(), INTERVAL 14 DAY) WHERE user_id = :u')
        ->execute(['u' => $uIdle]);
    T::same('active', $row('__stat_idle__')['state'], 'دقیقاً ۱۴ روز هنوز «فعال» است');

    $pdo->prepare('UPDATE people SET created_at = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE user_id = :u')
        ->execute(['u' => $uIdle]);
    T::same('stale', $row('__stat_idle__')['state'], '۱۵ روز دیگر «فعال» نیست');

    // صفحه نباید عددِ خودش را داشته باشد
    $page = (string)@file_get_contents(__DIR__ . '/../admin/insights.php');
    T::ok(strpos($page, '۱۴ روز') === false,
          'admin/insights.php عددِ ۱۴ را سخت‌کد نکرده');

} catch (Throwable $e) {
    $fail = $e->getMessage();
} finally {
    try { $wipe(); } catch (Throwable $e) { /* ignore */ }
}

if ($fail !== null) {
    T::group('آمارِ صفحه‌ی مدیر');
    T::ok(false, 'تست بدونِ خطا اجرا شد', $fail);
}

exit(T::report());
