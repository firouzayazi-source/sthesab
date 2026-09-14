<?php
/**
 * «دقیقه‌ی اول» و یادآوریِ پشتیبان.
 *
 * ⛔ چرا این دو با هم: هر دو **بی‌صدا** خراب می‌شوند و هیچ‌کدام خطا
 *    نمی‌دهند.
 *
 *    ۱. کارتِ موجودی اولیه اگر بعد از جواب دادنِ کاربر سرِ جایش بماند،
 *       تبدیل می‌شود به همان «هشدارِ همیشگی» که آدم را عادت می‌دهد
 *       نادیده‌اش بگیرد. و اگر زودتر از موعد برود، کاربرِ تازه هرگز
 *       نمی‌فهمد چرا حساب‌هایش منفی است.
 *
 *    ۲. یادآوریِ پشتیبان اگر هر روز تکرار شود، اولین چیزی است که کاربر
 *       خاموشش می‌کند؛ و اگر به کسی که همین دیروز بکاپ گرفته هم برود،
 *       دروغ گفته و دیگر باور نمی‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('دقیقه‌ی اول و پشتیبان');
    T::blocked('تست دقیقه‌ی اول', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/user_data.php';

$pdo = Database::getConnection();

if (!tableHasColumn('users', 'balance_setup_at')) {
    T::group('دقیقه‌ی اول و پشتیبان');
    T::skip('تست دقیقه‌ی اول', 'migration_onboarding.sql هنوز اجرا نشده');
    exit(T::report());
}

// ---------- قربانی‌ها را خودمان می‌سازیم ----------
// ⚠ تکیه بر داده‌ی موجود یعنی روی دیتابیسِ خالی تست بی‌صدا رد می‌شود و
//   هیچ چیزی را نگه نمی‌دارد.
$USERS = ['__onb_a__', '__onb_b__'];

// ⛔ فهرستِ جدول‌ها از **خودِ دیتابیس** کشف می‌شود، نه دستی. نسخه‌ی
//    دستی با آمدنِ هر جدولِ تازه یک ردیفِ جامانده به جا می‌گذارد، کلیدِ
//    خارجی حذفِ کاربر را رد می‌کند، و آن‌وقت اجرای بعدیِ **کلِ تست** با
//    «کاربر ساخته نشد» رد می‌شود — بی‌آنکه کسی به این پاک‌سازی شک کند.
//    دقیقاً همان چیزی که یک بار در `test_api_auth.php` اتفاق افتاد.
$wipe = function () use ($pdo, $USERS) {
    foreach ($USERS as $u) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $st->execute(['u' => $u]);
        $id = $st->fetchColumn();
        if (!$id) { continue; }

        // چند دور، چون ترتیبِ کلیدهای خارجی از قبل معلوم نیست — همان
        // الگوی `deleteUserAccount()`.
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
$wipe();

$mk = function (string $u) use ($pdo): int {
    $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES (:n, :u, :p, "user", 1)')
        ->execute(['n' => 'تست ' . $u, 'u' => $u, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};

$addWallet = function (int $uid, string $name, string $kind, int $init) use ($pdo): int {
    $pdo->prepare('INSERT INTO wallets (user_id, name, kind, initial_balance, is_active, sort_order)
                   VALUES (:u, :n, :k, :b, 1, 0)')
        ->execute(['u' => $uid, 'n' => $name, 'k' => $kind, 'b' => $init]);
    return (int)$pdo->lastInsertId();
};

$addTx = function (int $uid, int $n) use ($pdo): void {
    $st = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, title, transaction_date)
                         VALUES (:u, "expense", 1000, "تست", CURDATE())');
    for ($i = 0; $i < $n; $i++) { $st->execute(['u' => $uid]); }
};

$backupCount = function (int $uid) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND kind = 'backup'");
    $st->execute(['u' => $uid]);
    return (int)$st->fetchColumn();
};

$backupTitle = function (int $uid) use ($pdo): string {
    $st = $pdo->prepare("SELECT title FROM notifications WHERE user_id = :u AND kind = 'backup'
                         ORDER BY id DESC LIMIT 1");
    $st->execute(['u' => $uid]);
    return (string)$st->fetchColumn();
};

$uidA = $mk('__onb_a__');
$uidB = $mk('__onb_b__');

try {
    // ===============================================================
    T::group('کارتِ «دقیقه‌ی اول» — کِی دیده می‌شود');

    // ⚠ `activeWallets()` در هر درخواست کش می‌شود، ولی هر بارِ اجرای این
    //   تست یک پروسه‌ی تازه است و هر تغییرِ حساب اینجا با کوئریِ مستقیم
    //   انجام می‌شود، پس شرطِ اصلی (`initial_balance`) را خودِ
    //   `openingBalanceHint()` تازه می‌خواند نه از کش.
    $wA = $addWallet($uidA, 'کیف پول', 'cash', 0);

    $hint = openingBalanceHint($uidA);
    T::ok($hint !== null, 'کاربرِ تازه با حسابِ صفر، کارت را می‌بیند');
    T::same($wA, $hint['wallet_id'] ?? 0, 'و کارت روی حسابِ پیش‌فرض تنظیم می‌شود');
    T::same('کیف پول', $hint['wallet_name'] ?? '', 'نامِ همان حساب را می‌گوید');
    T::same(false, $hint['more'] ?? true, 'با یک حساب، لینکِ «حساب‌های دیگر» لازم نیست');

    // ---------------------------------------------------------------
    // ⛔ جهش: شرطِ `balance_setup_at` را بردار.
    //    بدونِ این بررسی، کاربری که واقعاً موجودی‌اش صفر است و «نیازی
    //    نیست» زده، **هر روز** همان کارت را می‌بیند — و کارتی که نمی‌شود
    //    از دستش خلاص شد، همان هشدارِ همیشگی است.
    T::group('⛔ جوابِ کاربر باید کارت را ببندد');

    markBalanceSetup($uidA);
    T::same(null, openingBalanceHint($uidA),
        '⛔ بعد از «نیازی نیست»، کارت دیگر دیده نمی‌شود — با اینکه حساب هنوز صفر است');

    $st = $pdo->prepare('SELECT balance_setup_at FROM users WHERE id = :u');
    $st->execute(['u' => $uidA]);
    $firstMark = (string)$st->fetchColumn();
    T::ok($firstMark !== '', 'و نشانه‌اش در دیتابیس نوشته شده');

    // ⚠ صدا زدنِ دوباره نباید تاریخ را جلو ببرد: شرطِ `IS NULL` در
    //   `markBalanceSetup()` همین را تضمین می‌کند. بدونش، «کِی کاربر
    //   جواب داد» با هر تعدیلِ بعدیِ موجودی بازنویسی می‌شد.
    //
    // ⛔ و اینجا **باید** تاریخ را عمداً به گذشته برد. نسخه‌ی اول فقط
    //    دو بار پشت سر هم صدا می‌زد و مقایسه می‌کرد — ولی هر دو در یک
    //    ثانیه اجرا می‌شدند، پس `NOW()` عینِ همان مقدار را می‌داد و تست
    //    با برداشتنِ کاملِ شرطِ `IS NULL` هم سبز می‌ماند. یعنی تست نسبت
    //    به همان چیزی که برایش نوشته شده بود **کور** بود. جهش نشانش داد.
    $pdo->prepare("UPDATE users SET balance_setup_at = '2020-01-01 08:00:00' WHERE id = :u")
        ->execute(['u' => $uidA]);
    markBalanceSetup($uidA);
    $st->execute(['u' => $uidA]);
    T::same('2020-01-01 08:00:00', (string)$st->fetchColumn(),
        '⛔ صدا زدنِ دوباره تاریخِ ثبت‌شده را دست نمی‌زند');

    // و برگرداندنش به مقدارِ واقعی برای بررسی‌های بعدی
    $pdo->prepare('UPDATE users SET balance_setup_at = :d WHERE id = :u')
        ->execute(['d' => $firstMark, 'u' => $uidA]);

    // ---------------------------------------------------------------
    // ⛔ جهش: شرطِ `initial_balance` را بردار.
    //    کسی که از راهِ دیگری (فرمِ ساختِ حساب) عدد وارد کرده، دیگر
    //    نباید پرسیده شود — وگرنه کارت به کاری که انجام شده گیر می‌دهد.
    T::group('⛔ حسابی که موجودی اولیه دارد یعنی جواب داده شده');

    $wB = $addWallet($uidB, 'کیف پول', 'cash', 0);
    T::ok(openingBalanceHint($uidB) !== null, 'کاربر دوم فعلاً کارت را می‌بیند');

    $pdo->prepare('UPDATE wallets SET initial_balance = 5000000 WHERE id = :w')->execute(['w' => $wB]);
    T::same(null, openingBalanceHint($uidB),
        '⛔ با پر شدنِ موجودی اولیه، کارت خودبه‌خود می‌رود');

    // ---------------------------------------------------------------
    T::group('⛔ جداسازی کاربران');

    // کاربر A جواب داده، B عدد وارد کرده — هیچ‌کدام نباید روی دیگری اثر
    // بگذارد. کوئری بدونِ شرطِ `user_id` اینجا گیر می‌افتد.
    $pdo->prepare('UPDATE wallets SET initial_balance = 0 WHERE id = :w')->execute(['w' => $wB]);
    $pdo->prepare('UPDATE users SET balance_setup_at = NULL WHERE id = :u')->execute(['u' => $uidB]);
    $st->execute(['u' => $uidA]);
    T::ok((string)$st->fetchColumn() !== '', 'نشانه‌ی کاربر A با کارِ B پاک نشده');
    T::ok(openingBalanceHint($uidB) !== null, 'و کاربر B مستقلاً کارتش را می‌بیند');
    T::same(null, openingBalanceHint($uidA), 'و کاربر A همچنان نمی‌بیندش');

    // ---------------------------------------------------------------
    T::group('چند حساب، و کاربرِ بی‌حساب');

    $addWallet($uidB, 'بانک ملت', 'bank', 0);
    $hintB = openingBalanceHint($uidB);
    T::ok($hintB !== null, 'با دو حساب هم کارت هست');
    T::same(true, $hintB['more'] ?? false, 'و لینکِ «حساب‌های دیگر» روشن می‌شود');
    T::same($wB, $hintB['wallet_id'] ?? 0, '⛔ ولی ورودی روی همان حسابِ نقدیِ پیش‌فرض است');

    T::same(null, openingBalanceHint(0), 'شناسه‌ی نامعتبر هیچ کارتی نمی‌سازد');

    // ===============================================================
    if (!Notify::available()) {
        T::group('یادآوری پشتیبان');
        T::skip('تست یادآوری پشتیبان', 'migration_notifications.sql هنوز اجرا نشده');
    } else {
        $today = today();

        // -----------------------------------------------------------
        // ⛔ جهش: کفِ `BACKUP_MIN_ROWS` را بردار.
        //    کاربری که سه تراکنش دارد چیزی برای از دست دادن ندارد؛ اعلانِ
        //    بی‌ربط کلِ مرکزِ اعلان را بی‌اعتبار می‌کند.
        T::group('⛔ کاربرِ تازه یادآوریِ پشتیبان نمی‌گیرد');

        $addTx($uidA, 3);
        Notify::generateFor($uidA, true);
        T::same(0, $backupCount($uidA),
            '⛔ با ۳ تراکنش هیچ یادآوریِ پشتیبانی ساخته نمی‌شود');

        // -----------------------------------------------------------
        T::group('کسی که داده دارد و هرگز بکاپ نگرفته');

        $addTx($uidA, Notify::BACKUP_MIN_ROWS);
        Notify::generateFor($uidA, true);
        T::same(1, $backupCount($uidA), 'یادآوری ساخته می‌شود');
        T::ok(str_contains($backupTitle($uidA), 'هنوز'),
            'و متنش می‌گوید هرگز بکاپ نگرفته، نه «یک ماه گذشته»');

        // -----------------------------------------------------------
        // ⛔ جهش: `dedup_key` را بردار.
        //    بدونش همان یک جمله هر بازدیدِ صفحه تکرار می‌شد و فهرستِ
        //    اعلان در یک هفته غیرقابل خواندن می‌شد.
        T::group('⛔ ماهی یکی، نه هر بار');

        Notify::generateFor($uidA, true);
        Notify::generateFor($uidA, true);
        T::same(1, $backupCount($uidA),
            '⛔ سه بار اجرا، همچنان یک اعلان');

        // -----------------------------------------------------------
        // ⛔ جهش: `last_backup_at` را نادیده بگیر.
        //    یادآوری‌ای که به کسی برود که همین حالا بکاپ گرفته، دروغ
        //    گفته — و اولین چیزی است که خاموش می‌شود.
        T::group('⛔ کسی که تازه بکاپ گرفته یادآوری نمی‌گیرد');

        $pdo->prepare("DELETE FROM notifications WHERE user_id = :u AND kind = 'backup'")
            ->execute(['u' => $uidA]);
        markBackupTaken($uidA);
        T::ok(lastBackupAt($uidA) !== null, 'نشانه‌ی بکاپ نوشته شد');

        Notify::generateFor($uidA, true);
        T::same(0, $backupCount($uidA),
            '⛔ بلافاصله بعد از بکاپ، هیچ یادآوری‌ای ساخته نمی‌شود');

        // -----------------------------------------------------------
        T::group('و بعد از گذشتِ بازه، دوباره');

        $days = Notify::BACKUP_AFTER_DAYS + 10;
        $pdo->prepare("UPDATE users SET last_backup_at = DATE_SUB(NOW(), INTERVAL {$days} DAY)
                       WHERE id = :u")->execute(['u' => $uidA]);
        Notify::generateFor($uidA, true);
        T::same(1, $backupCount($uidA), 'بعد از ' . $days . ' روز دوباره یادآوری می‌آید');
        T::ok(!str_contains($backupTitle($uidA), 'هنوز'),
            '⛔ و این بار متنش «یک ماه گذشته» است نه «هرگز» — دو حالتِ جدا');

        // -----------------------------------------------------------
        // ⛔ مرزِ خودِ بازه — و اینجا عددِ **ثابت** لازم است.
        //
        //    نسخه‌ی اول `BACKUP_AFTER_DAYS - 1` می‌نوشت، یعنی مرزِ تست با
        //    خودِ ثابت جابه‌جا می‌شد: با عوض کردنِ ۳۰ به ۲۰، تست هم مرزش
        //    را عوض می‌کرد و سبز می‌ماند. **تستی که مقدارِ خودش را از کدِ
        //    زیرِ آزمون می‌گیرد، آن مقدار را نمی‌سنجد.** جهش نشانش داد.
        T::group('مرزِ بازه');

        T::same(30, Notify::BACKUP_AFTER_DAYS,
            '⛔ بازه یک **ماه** است — تصمیمِ محصول، نه جزئیاتِ پیاده‌سازی');

        $pdo->prepare("DELETE FROM notifications WHERE user_id = :u AND kind = 'backup'")
            ->execute(['u' => $uidA]);
        $pdo->prepare("UPDATE users SET last_backup_at = DATE_SUB(NOW(), INTERVAL 25 DAY)
                       WHERE id = :u")->execute(['u' => $uidA]);
        Notify::generateFor($uidA, true);
        T::same(0, $backupCount($uidA),
            '۲۵ روز پس از بکاپ هنوز یادآوری نمی‌آید');

        // -----------------------------------------------------------
        T::group('⛔ اعلانِ هر کاربر مالِ خودش');

        $addTx($uidB, Notify::BACKUP_MIN_ROWS + 1);
        Notify::generateFor($uidB, true);
        T::same(1, $backupCount($uidB), 'کاربر B یادآوریِ خودش را می‌گیرد');
        T::same(0, $backupCount($uidA), 'و چیزی به کاربر A اضافه نمی‌شود');
    }
} finally {
    $wipe();
}

exit(T::report());
