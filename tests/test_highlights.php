<?php
/**
 * جمله‌های بینشِ صفحه‌ی خانه.
 *
 * ⛔ خطرِ اصلیِ این قابلیت **دروغ گفتن** است، نه نبودن.
 *
 *    یک عددِ غلط در گزارش را کاربر با نگاه کردن به تراکنش‌هایش می‌گیرد،
 *    ولی یک **جمله**ی غلط را باور می‌کند — چون جمله ادعای تفسیر دارد نه
 *    ادعای عدد. و اولین جمله‌ای که کاربر بفهمد بی‌معنا بوده، بقیه را هم
 *    بی‌اعتبار می‌کند: کارت را دیگر نمی‌خواند و کلِ کار هدر می‌رود.
 *
 *    پس مهم‌ترین بررسی‌های اینجا «چه چیزی **نباید** گفته شود» است:
 *      • کاربری که ماهِ قبل هیچ خرجی نداشته، «۱۰۰٪ بیشتر» نمی‌گیرد.
 *      • رشدِ ۵۰۰ به ۲۰۰۰ تومان «۳۰۰٪ رشد» اعلام نمی‌شود.
 *      • کاربرِ تازه هیچ جمله‌ای نمی‌گیرد، نه یک کارتِ خالی.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('جمله‌های بینش');
    T::skip('تست بینش', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('جمله‌های بینش');
    T::skip('تست بینش', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

T::group('جمله‌های بینش');

$U = '__test_highlights';
$cleanup = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U]);
    if ($id = $st->fetchColumn()) {
        foreach (['transactions', 'debts', 'budgets', 'wallets'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدول شاید نباشد */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $U]);
};
$cleanup();
register_shutdown_function($cleanup);

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست بینش', 'user', 1)"
)->execute(['u' => $U, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO wallets (user_id, name, kind, sort_order, is_active)
               VALUES (:u, "کیف پول", "cash", 0, 1)')->execute(['u' => $uid]);

$catStmt = $pdo->query('SELECT id, name FROM categories WHERE type = "expense" AND user_id IS NULL LIMIT 1');
$cat = $catStmt ? $catStmt->fetch() : null;
if (!$cat) {
    T::skip('تست بینش', 'هیچ دسته‌ی هزینه‌ی پیش‌فرضی نیست');
    exit(T::report());
}

[$jy, $jm, $jd] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
$w = monthComparisonWindow($jy, $jm, $jd);

$add = function (string $date, int $amount) use ($pdo, $uid, $cat) {
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, title, transaction_date, category_id)
                   VALUES (:u, "expense", :a, "تست", :d, :c)')
        ->execute(['u' => $uid, 'a' => $amount, 'd' => $date, 'c' => (int)$cat['id']]);
};
$reset = function () use ($pdo, $uid) {
    $pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $uid]);
    $pdo->prepare('DELETE FROM debts WHERE user_id = :u')->execute(['u' => $uid]);
};

// ---------------------------------------------------------------
T::group('⛔ کاربرِ تازه هیچ جمله‌ای نمی‌گیرد');

T::same(0, count(financialHighlights($uid)),
    '⛔ بدونِ هیچ داده‌ای، هیچ جمله‌ای ساخته نمی‌شود (نه کارتِ خالی)');

$add(today(), 5_000_000);
T::same(0, count(financialHighlights($uid)),
    '⛔ با خرجِ این ماه ولی بدونِ ماهِ قبل هم چیزی گفته نمی‌شود — درصد بی‌معناست');

// ---------------------------------------------------------------
T::group('⛔ رشدِ واقعی گفته می‌شود، رشدِ ساختگی نه');

$reset();
$add($w['prev_start'], 1_000_000);
$add($w['cur_start'],  2_000_000);

$hl = financialHighlights($uid);
$grew = null;
foreach ($hl as $h) { if (str_contains($h['text'], $cat['name'])) { $grew = $h; } }

T::ok($grew !== null, 'دسته‌ای که دو برابر شده نام برده می‌شود', 'دسته: ' . $cat['name']);
if ($grew) {
    T::ok(str_contains($grew['text'], '۱۰۰'), 'و درصدِ رشدش درست است');
    T::ok(str_contains((string)$grew['link'], 'category-report'),
        '⛔ جمله به گزارشِ همان دسته لینک دارد — خبری که به اقدام نرسد خوانده نمی‌شود');
}

// ⛔ کفِ مبلغ: ۵۰۰ → ۲۰۰۰ تومان از نظر ریاضی «۳۰۰٪ رشد» است و از نظر
//    معنا آشغال. جمله‌ای که آشغال بگوید، کلِ کارت را بی‌اعتبار می‌کند.
$reset();
$add($w['prev_start'], 500);
$add($w['cur_start'],  2_000);
T::same(null, topGrowingCategory($uid),
    '⛔ رشدِ چند صد تومانی «۳۰۰٪ رشد» اعلام نمی‌شود');

// ⛔ و رشدِ کوچک هم گفته نمی‌شود: ۱۰٪ نوسانِ عادیِ زندگی است، نه خبر.
$reset();
$add($w['prev_start'], 2_000_000);
$add($w['cur_start'],  2_100_000);
T::same(null, topGrowingCategory($uid),
    'نوسانِ زیرِ ۲۵٪ خبر نیست');

// و کاهش هرگز «رشد» خوانده نمی‌شود
$reset();
$add($w['prev_start'], 3_000_000);
$add($w['cur_start'],  1_000_000);
T::same(null, topGrowingCategory($uid), 'کاهش به‌عنوان رشد گزارش نمی‌شود');

// ---------------------------------------------------------------
T::group('⛔ سررسیدِ گذشته مقدم بر همه است');

$reset();
$add($w['prev_start'], 1_000_000);
$add($w['cur_start'],  4_000_000);
$pdo->prepare('INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date, is_settled)
               VALUES (:u, "payable", "طرفِ تست", :a, :e, :d, 0)')
    ->execute([
        'u' => $uid, 'a' => 4_000_000,
        'e' => date('Y-m-d', strtotime('-40 days')),
        'd' => date('Y-m-d', strtotime('-12 days')),
    ]);

$hl = financialHighlights($uid);
T::ok(count($hl) > 0, 'جمله‌ای ساخته شد');
T::same('warn', $hl[0]['tone'] ?? '',
    '⛔ اولین جمله هشدارِ سررسیدِ گذشته است — تنها چیزی که همین حالا هزینه دارد');
T::ok(str_contains($hl[0]['text'] ?? '', 'سررسید'), 'و متنش درباره‌ی سررسید است');

// ---------------------------------------------------------------
T::group('⛔ سقفِ سه جمله');

// ⚠ سقف را فقط وقتی می‌شود سنجید که **بیش از سه** نامزد وجود داشته
//   باشد. نسخه‌ی اولِ این تست سه نامزد بیشتر نمی‌ساخت، پس با برداشتنِ
//   کاملِ `array_slice` هم سبز می‌ماند — یعنی چیزی را می‌سنجید که فکر
//   می‌کردیم. (آزمونِ جهش نشانش داد.)
//
//   حالا چهار نامزد هست: سررسیدِ گذشته، بودجه‌ی ردشده، دسته‌ی رشدکرده،
//   و مقایسه‌ی ماه.
//
// ⚠ سقف **دو نگهبان** دارد (`count($out) < 3` و `array_slice`)، پس
//   برداشتنِ هرکدام به‌تنهایی دیده نمی‌شود — آن یکی جایش را می‌گیرد.
//   این ضعفِ تست نیست، خودِ طرح است؛ ولی برای اینکه مطمئن شویم تست
//   **پوچ نیست**، جهشِ «هر دو را بردار» اجرا شد و گرفته شد (۴ جمله
//   به‌جای ۳).
$budgetReady = false;
try {
    // ⚠ ستون `period_type` است نه `period` — نسخه‌ی اولِ همین تست نامِ
    //   غلط داشت و چون `catch` سکوت می‌کرد، تست **بی‌صدا رد می‌شد** و
    //   جهشِ «سقف را بردار» را نمی‌گرفت.
    $pdo->prepare('INSERT INTO budgets (user_id, category_id, period_type, amount, is_active)
                   VALUES (:u, :c, "monthly", :a, 1)')
        ->execute(['u' => $uid, 'c' => (int)$cat['id'], 'a' => 100_000]);
    $budgetReady = true;
} catch (PDOException $e) { /* شکلِ جدول فرق دارد یا نیامده */ }

$candidates = 0;
foreach (budgetStatuses($uid) as $b) { if ((int)($b['percent'] ?? 0) > 100) { $candidates++; } }

if (!$budgetReady || $candidates === 0) {
    T::skip('سقفِ سه جمله', 'ساختِ بودجه‌ی ردشده ممکن نشد، پس نامزدِ چهارم وجود ندارد');
} else {
    $many = financialHighlights($uid);
    T::same(3, count($many),
        '⛔ با چهار نامزد، دقیقاً سه جمله بیرون می‌آید — با ده جمله هیچ‌کدام خوانده نمی‌شوند');
    T::same('warn', $many[0]['tone'] ?? '', 'و هشدارها همچنان اولند');
}

// ---------------------------------------------------------------
T::group('بینش نباید صفحه را بشکند');

// ⚠ حتی با شناسه‌ای که هیچ داده‌ای ندارد، باید آرایه برگردد نه استثنا:
//   این کارت روی **صفحه‌ی خانه** است، یعنی اولین چیزی که کاربر می‌بیند.
$ghost = financialHighlights(0);
T::ok(is_array($ghost), 'برای کاربرِ ناموجود هم آرایه برمی‌گردد، نه خطا');

exit(T::report());
