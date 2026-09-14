<?php
/**
 * لغوِ حذف — «حذف شد، لغو؟» به‌جای «مطمئنید؟».
 *
 * ⛔ خطرِ اصلیِ این قابلیت **وعده‌ی توخالی** است. مودالِ تأیید برداشته
 *    شده چون قرار است برگشت ممکن باشد؛ اگر برگشت نصفه باشد، کاربر
 *    چیزی را حذف می‌کند که هرگز اجازه‌ی حذفش را نمی‌داد — و بدتر:
 *    «لغو» را می‌زند، «برگردانده شد» می‌بیند، و **نمی‌فهمد** که نصفش
 *    برنگشته.
 *
 *    پس مهم‌ترین بررسیِ اینجا این نیست که پدر برمی‌گردد، بلکه این است
 *    که **فرزندانِ CASCADE هم برمی‌گردند** — با همان شناسه، تا پیوندهای
 *    جاهای دیگر نشکنند.
 *
 * ⛔ و دومین بررسی: جداسازی کاربران. عکس در نشست می‌ماند، پس اگر
 *    `user_id` از عکس خوانده شود (نه از نشست)، یک عکسِ دست‌کاری‌شده
 *    می‌تواند ردیفی به نامِ کاربرِ دیگری بسازد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('لغوِ حذف');
    T::blocked('تست لغو', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/undo.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('لغوِ حذف');
    T::blocked('تست لغو', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// `Undo` فقط با آرایه‌ی `$_SESSION` کار می‌کند، پس در CLI هم آزمودنی است.
$_SESSION = [];

T::group('لغوِ حذف');

$U1 = '__test_undo_a';
$U2 = '__test_undo_b';

/** کاربرِ تست را با هر چیزی که به او وصل است پاک می‌کند. */
$purge = function (string $name) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $name]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        // ⚠ از خودِ دیتابیس کشف می‌شود، نه فهرستِ دستی — همان درسی که
        //   پاک‌سازیِ `test_api_auth` داد: یک جدولِ تازه و ساختِ کاربر
        //   بی‌صدا شکست می‌خورد.
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* ترتیبِ کلید خارجی */ }
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* دورِ دوم برای فرزندها */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $name]);
};
$cleanup = function () use ($purge, $U1, $U2) { $purge($U1); $purge($U2); };
$cleanup();
register_shutdown_function($cleanup);

$mkUser = function (string $name) use ($pdo): int {
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active)
                   VALUES (:u, :p, 'کاربر تست لغو', 'user', 1)")
        ->execute(['u' => $name, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};
$uidA = $mkUser($U1);
$uidB = $mkUser($U2);

/** یک بدهی با دو پرداخت — پدر و فرزندِ CASCADE. */
$mkDebt = function (int $uid) use ($pdo): array {
    $pdo->prepare('INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date, is_settled)
                   VALUES (:u, "payable", "طرفِ تست", 9000000, :e, :d, 0)')
        ->execute(['u' => $uid, 'e' => today(), 'd' => today()]);
    $did = (int)$pdo->lastInsertId();
    $pay = [];
    foreach ([1000000, 2000000] as $amt) {
        $pdo->prepare('INSERT INTO debt_payments (user_id, debt_id, amount, payment_date)
                       VALUES (:u, :d, :a, :t)')
            ->execute(['u' => $uid, 'd' => $did, 'a' => $amt, 't' => today()]);
        $pay[] = (int)$pdo->lastInsertId();
    }
    return [$did, $pay];
};

$countPayments = function (int $debtId) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM debt_payments WHERE debt_id = :d');
    $st->execute(['d' => $debtId]);
    return (int)$st->fetchColumn();
};
$debtExists = function (int $id) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM debts WHERE id = :i');
    $st->execute(['i' => $id]);
    return (int)$st->fetchColumn() === 1;
};

// ---------------------------------------------------------------
T::group('⛔ فرزندانِ CASCADE هم برمی‌گردند، نه فقط پدر');

[$debtId, $payIds] = $mkDebt($uidA);
T::same(2, $countPayments($debtId), 'پیش از حذف دو پرداخت هست');

$token = Undo::capture('debts', $debtId, $uidA);
T::ok(is_string($token) && $token !== '', 'عکس گرفته شد');

$pdo->prepare('DELETE FROM debts WHERE id = :i')->execute(['i' => $debtId]);
T::ok(!$debtExists($debtId), 'بدهی واقعاً حذف شد');
T::same(0, $countPayments($debtId), 'و پرداخت‌ها هم با CASCADE رفتند');

$r = Undo::restore((string)$token, $uidA);
T::ok($r['ok'], 'لغو موفق بود', $r['message']);
T::ok($debtExists($debtId), 'بدهی برگشت');
T::same(2, $countPayments($debtId),
    '⛔ **هر دو پرداخت** هم برگشتند — وگرنه «برگردانده شد» یک دروغِ نصفه بود');

// ⛔ با همان شناسه، وگرنه هر پیوندی که جای دیگری به این ردیف اشاره
//    می‌کند بی‌صدا به ردیفِ اشتباه یا هیچ‌جا وصل می‌شد.
$st = $pdo->prepare('SELECT id FROM debt_payments WHERE debt_id = :d ORDER BY id');
$st->execute(['d' => $debtId]);
T::same($payIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
    '⛔ و با همان شناسه‌های قبلی برگشتند');

// ---------------------------------------------------------------
T::group('⛔ دو بار «لغو» ردیفِ تکراری نمی‌سازد');

// ⚠ این را **دو** نگهبان با هم نگه می‌دارند و برداشتنِ هرکدام
//   به‌تنهایی دیده نمی‌شود (آزمونِ جهش نشان داد): سوزاندنِ توکن بعد از
//   لغو، و کلیدِ یکتای خودِ دیتابیس که درجِ دوباره را رد می‌کند. این
//   ضعفِ تست نیست، خودِ طرح است — ولی نوشتنش لازم است تا کسی جهشِ
//   زنده‌مانده را «پس این خط لازم نیست» نخواند.
//   (همان حالتی که سقفِ سه‌جمله‌ایِ `test_highlights` دارد.)
$r2 = Undo::restore((string)$token, $uidA);
T::ok(!$r2['ok'], 'لغوِ دوم رد می‌شود', $r2['message']);
T::same(2, $countPayments($debtId), 'و چیزی تکراری اضافه نشده');

// ---------------------------------------------------------------
T::group('⛔ جداسازی کاربران');

// عکسِ ردیفِ کاربرِ دیگر اصلاً گرفته نمی‌شود.
[$debtB, ] = $mkDebt($uidB);
T::same(null, Undo::capture('debts', $debtB, $uidA),
    '⛔ کاربر نمی‌تواند از ردیفِ کاربرِ دیگر عکس بگیرد');

// و توکنِ کاربرِ دیگر قابل استفاده نیست.
$tokB = Undo::capture('debts', $debtB, $uidB);
$pdo->prepare('DELETE FROM debts WHERE id = :i')->execute(['i' => $debtB]);
$rx = Undo::restore((string)$tokB, $uidA);
T::ok(!$rx['ok'], '⛔ توکنِ کاربرِ دیگر برگردانده نمی‌شود', $rx['message']);
T::ok(!$debtExists($debtB), 'و ردیفش هم برنگشت');

// ⛔ و عکسِ **دست‌کاری‌شده** هم نباید ردیفی به نامِ کسِ دیگری بسازد.
//
//    عکس در `$_SESSION` می‌ماند — یعنی روی دیسکِ سرور، در پوشه‌ی
//    نشست. اگر `user_id` از خودِ عکس خوانده شود (نه از نشستِ زنده)،
//    هر راهی که به آن فایل برسد می‌تواند ردیفی به نامِ کاربرِ دیگری
//    بنشاند. `insertRow()` به همین دلیل `user_id` را **همیشه**
//    بازنویسی می‌کند.
//
//    ⚠ این بررسی از یک جهشِ زنده‌مانده درآمد: در همه‌ی حالت‌های عادی
//      `user_id`ِ عکس با کاربرِ زنده یکی است، پس خواندنش از هر کدام
//      نتیجه‌ی یکسان می‌داد و تست تفاوتی نمی‌دید. تنها جایی که آن خط
//      واقعاً کار می‌کند وقتی است که این دو **فرق کنند**.
[$debtT, ] = $mkDebt($uidA);
$tokT = Undo::capture('debts', $debtT, $uidA);
$pdo->prepare('DELETE FROM debts WHERE id = :i')->execute(['i' => $debtT]);

foreach ($_SESSION['undo_snapshots'][$tokT]['groups'] as $gi => $g) {
    foreach ($g['rows'] as $ri => $row) {
        $_SESSION['undo_snapshots'][$tokT]['groups'][$gi]['rows'][$ri]['user_id'] = $uidB;
    }
}

$rt = Undo::restore((string)$tokT, $uidA);
T::ok($rt['ok'], 'عکسِ دست‌کاری‌شده برگردانده می‌شود…', $rt['message']);

$own = $pdo->prepare('SELECT user_id FROM debts WHERE id = :i');
$own->execute(['i' => $debtT]);
T::same($uidA, (int)$own->fetchColumn(),
    '⛔ …ولی به نامِ کاربرِ زنده، نه آن‌که در عکس نوشته شده');

// ---------------------------------------------------------------
T::group('توکنِ ناموجود صفحه را نمی‌شکند');

$rn = Undo::restore('یک-توکنِ-کاملاً-ساختگی', $uidA);
T::ok(!$rn['ok'], 'توکنِ ناشناس رد می‌شود');
T::ok(($rn['message'] ?? '') !== '', 'و دلیلش گفته می‌شود');

$re = Undo::restore('', $uidA);
T::ok(!$re['ok'], 'توکنِ خالی هم رد می‌شود، نه خطا');

// ---------------------------------------------------------------
T::group('⛔ عکسِ ناممکن یعنی «تأیید»، نه «لغوِ توخالی»');

// ردیفی که وجود ندارد → توکن `null` → صفحه به `confirm()` برمی‌گردد.
T::same(null, Undo::capture('debts', 999999999, $uidA),
    'ردیفِ ناموجود توکن نمی‌گیرد');
T::same(null, Undo::capture('debts', 0, $uidA), 'شناسه‌ی صفر هم');
// ⚠ نامِ جدول هم دو نگهبان دارد (`capture()` و `collect()`)، پس
//   برداشتنِ یکی به‌تنهایی دیده نمی‌شود. عمدی است: نامِ جدول از
//   `information_schema` هم می‌آید، نه فقط از کدِ خودمان.
T::same(null, Undo::capture('؛ DROP TABLE debts', 1, $uidA),
    '⛔ نامِ جدولِ عجیب اصلاً پذیرفته نمی‌شود');

exit(T::report());
