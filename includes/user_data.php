<?php
/**
 * خروجی گرفتن از همه‌ی داده‌ی یک کاربر، و حذف کاملِ حساب.
 *
 * ⛔ چرا لازم است: کاربری که حس کند داده‌اش گروگان است، پول نمی‌دهد.
 *    «هر وقت خواستی همه‌اش را ببر، و هر وقت خواستی پاکش کن» تنها چیزی
 *    است که این حس را از بین می‌برد — و برای فروش، پیش‌نیاز است نه
 *    تزئین.
 *
 * ⛔ فهرستِ جدول‌ها از خودِ دیتابیس کشف می‌شود، نه از یک آرایه‌ی دستی.
 *    دلیلش همان درسی است که در کلِ این پروژه تکرار شده: فهرستِ دوم دیر
 *    یا زود عقب می‌افتد. اینجا عقب افتادنش دو خرابیِ بی‌صدا می‌سازد —
 *    خروجی‌ای که کامل نیست، و حذفی که چیزی از کاربر جا می‌گذارد.
 *    هر جدولی که ستون `user_id` داشته باشد خودبه‌خود شامل می‌شود.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

/**
 * جدول‌هایی که در **خروجی** نمی‌آیند.
 *
 * این‌ها داده‌ی کاربر نیستند بلکه اعتبارنامه‌اند: هشِ توکن، توکنِ
 * بازیابی، و کوکیِ دستگاه. گذاشتنشان در فایلی که کاربر دانلود می‌کند و
 * ممکن است جایی بفرستد، فقط سطحِ حمله را بزرگ می‌کند و هیچ فایده‌ای
 * ندارد.
 *
 * ⚠ در **حذف** همه‌شان می‌آیند — آنجا چیزی نباید جا بماند.
 */
const USER_EXPORT_SKIP = ['api_tokens', 'password_resets', 'trusted_devices'];

/** ستون‌هایی که هرگز نباید از `users` بیرون بروند. */
const USER_SECRET_COLUMNS = ['password_hash'];

/**
 * هر جدولی که ستون `user_id` دارد.
 *
 * @return string[] مرتب‌شده، تا خروجی بین اجراها یکسان بماند
 */
function userDataTables(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $st = Database::getConnection()->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'
         ORDER BY TABLE_NAME"
    );
    return $cache = array_map(fn($r) => $r['TABLE_NAME'], $st->fetchAll());
}

/**
 * همه‌ی داده‌ی کاربر، آماده‌ی تبدیل به JSON.
 *
 * ⚠ ستون‌های رمزشده **باز** می‌شوند. خروجی برای خودِ کاربر است و
 *   `enc:v1:…` برایش بی‌معناست؛ فایلی که نمی‌شود خواند خروجی نیست.
 */
function exportUserData(int $userId): array
{
    $pdo = Database::getConnection();

    $u = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $u->execute(['id' => $userId]);
    $profile = $u->fetch();
    if (!$profile) { return []; }

    foreach (USER_SECRET_COLUMNS as $c) { unset($profile[$c]); }

    $out = [
        'app'         => 'دفتر مالی',
        'version'     => appVersion(),
        'exported_at' => date('c'),
        'exported_jalali' => toJalali(date('Y-m-d')),
        'profile'     => $profile,
        'tables'      => [],
    ];

    foreach (userDataTables() as $t) {
        if (in_array($t, USER_EXPORT_SKIP, true)) { continue; }

        // ⚠ نامِ جدول از information_schema همین دیتابیس می‌آید، نه از
        //   ورودی کاربر — پس درج مستقیمش در SQL امن است. مقدارِ
        //   `user_id` همیشه bind می‌شود.
        $st = $pdo->prepare("SELECT * FROM `{$t}` WHERE user_id = :u");
        $st->execute(['u' => $userId]);
        $rows = $st->fetchAll();

        if ($t === 'wallets') {
            foreach ($rows as $i => $r) {
                $rows[$i] = Crypto::decryptRow($r, Crypto::WALLET_FIELDS);
            }
        }

        $out['tables'][$t] = $rows;
    }

    // شمارشِ خلاصه، تا کاربر بدون باز کردنِ فایل هم بفهمد چه گرفته
    $out['summary'] = array_map('count', $out['tables']);

    return $out;
}

/**
 * حذفِ کاملِ حساب.
 *
 * ⛔ همه در یک تراکنش. حذفِ نصفه‌کاره بدترین حالت است: کاربری که دیگر
 *    نمی‌تواند وارد شود ولی داده‌اش هنوز آنجاست.
 *
 * ⚠ ترتیبِ حذف از روی کلیدهای خارجی حدس زده نمی‌شود بلکه **چند بار
 *   تلاش** می‌شود: هر دور، هر جدولی که می‌شود پاک می‌شود؛ اگر دوری
 *   هیچ پیشرفتی نداشت، یعنی واقعاً گیر کرده و با خطا برمی‌گردیم.
 *   ساختنِ فهرستِ ترتیب به‌صورت دستی همان «فهرستِ دوم»ی می‌شد که با
 *   افزودنِ اولین جدولِ تازه عقب می‌افتاد.
 *
 * @return array{ok:bool, deleted:array<string,int>, reason?:string}
 */
function deleteUserAccount(int $userId): array
{
    $pdo = Database::getConnection();

    $tables = userDataTables();
    $deleted = [];
    $pending = $tables;

    // ⛔ سدِ آخر: پیش از حذف، شمارشِ ردیف‌های **این کاربر** و شمارشِ کلِ
    //    جدول برداشته می‌شود؛ بعد از حذف باید دقیقاً به همان اندازه کم
    //    شده باشد. اگر بیشتر کم شد یعنی شرطِ `user_id` از کوئری افتاده و
    //    داریم داده‌ی بقیه را می‌بریم — آن‌وقت rollback.
    //
    //    این حرفِ نظری نیست: همین اتفاق یک بار افتاد. هنگام آزمونِ جهش،
    //    نسخه‌ی جهش‌یافته‌ی این تابع (بدون شرطِ `user_id`) روی دیتابیسِ
    //    توسعه اجرا شد و **کامیت کرد**. تست بعدش خرابی را دید، ولی داده
    //    از قبل رفته بود. یک تستِ خوب هم جلوی حذفِ کامیت‌شده را نمی‌گیرد؛
    //    خودِ کد باید بگیرد.
    $before = [];
    foreach ($tables as $t) {
        try {
            $mine = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
            $mine->execute(['u' => $userId]);
            $before[$t] = [
                'mine'  => (int)$mine->fetchColumn(),
                'total' => (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(),
            ];
        } catch (PDOException $e) { /* جدول در دسترس نیست */ }
    }

    $pdo->beginTransaction();
    try {
        $passes = 0;
        while ($pending && $passes < count($tables) + 2) {
            $passes++;
            $stuck = [];
            foreach ($pending as $t) {
                try {
                    $st = $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u");
                    $st->execute(['u' => $userId]);
                    $deleted[$t] = $st->rowCount();
                } catch (PDOException $e) {
                    // احتمالاً کلید خارجی — دورِ بعد دوباره تلاش می‌شود
                    $stuck[] = $t;
                }
            }
            if (count($stuck) === count($pending)) {
                throw new RuntimeException(
                    'این جدول‌ها پاک نشدند (کلید خارجی): ' . implode('، ', $stuck)
                );
            }
            $pending = $stuck;
        }

        // سدِ آخر — پیش از commit، نه بعدش.
        foreach ($before as $t => $b) {
            $now = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $removed = $b['total'] - $now;
            if ($removed > $b['mine']) {
                throw new RuntimeException(
                    "جدول {$t}: {$removed} ردیف حذف شد ولی این کاربر فقط {$b['mine']} ردیف داشت"
                    . ' — شرطِ user_id جایی افتاده. هیچ چیزی نوشته نشد.'
                );
            }
        }

        $usersBefore = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $st = $pdo->prepare('DELETE FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        $deleted['users'] = $st->rowCount();

        if ($deleted['users'] !== 1) {
            throw new RuntimeException('ردیفِ کاربر پاک نشد.');
        }
        $usersAfter = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($usersBefore - $usersAfter !== 1) {
            throw new RuntimeException('بیش از یک کاربر حذف شد — هیچ چیزی نوشته نشد.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'deleted' => [], 'reason' => $e->getMessage()];
    }

    return ['ok' => true, 'deleted' => $deleted];
}

/**
 * نسخه‌ی مستقرشده.
 *
 * ⚠ از `git` خوانده نمی‌شود: pool این اپ `exec`/`shell_exec` را
 *   غیرفعال کرده (قانون جداسازی سرور). به‌جایش `deploy.sh` هنگام
 *   استقرار فایل `var/version.txt` را می‌نویسد.
 */
function appVersion(): string
{
    static $v = null;
    if ($v !== null) { return $v; }

    $f = __DIR__ . '/../var/version.txt';
    if (is_readable($f)) {
        $line = trim((string)file_get_contents($f));
        if ($line !== '') { return $v = $line; }
    }
    return $v = 'نسخه‌ی توسعه';
}
