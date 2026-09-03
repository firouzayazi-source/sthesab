<?php
/**
 * طرحِ اشتراک — مدلِ کامل، اجرای خاموش.
 *
 * ⛔ **هیچ قابلیتی امروز محدود نمی‌شود، و این عمدی است.** نصب‌هایی که
 *    الان کار می‌کنند همه چیز را دارند؛ اگر یک به‌روزرسانی ناگهان
 *    چیزی را از کاربرِ فعلی بگیرد، بدترین کاری است که می‌شود کرد.
 *    `planEnforced()` پیش‌فرض **خاموش** است و فقط مالکِ نصب — همان کسی
 *    که می‌خواهد بفروشد — روشنش می‌کند.
 *
 *    یعنی امروز این لایه فقط **می‌شمارد و ثبت می‌کند**: چه کسی Pro
 *    است، تا کِی، و چه پرداختی شده. محدود کردن یک تصمیمِ جداست.
 *
 * ⛔ انقضا با **تاریخ** است نه با کلیدِ روشن/خاموش. با بولین، اولین
 *    باری که کسی تمدید نکند باید یک کارِ زمان‌بندی‌شده خاموشش کند — و
 *    آن کار روزی از کار می‌افتد و همه بی‌سروصدا Pro می‌مانند. با
 *    تاریخ، انقضا خودش اتفاق می‌افتد و هیچ cron ای لازم نیست.
 *
 * ⛔ **کارت‌به‌کارت با تأیید دستی، نه درگاه.** درگاه پرداخت ایرانی
 *    نماد اعتماد و شخصیت حقوقی می‌خواهد؛ تا آن روز، این کار می‌کند و
 *    هیچ وابستگیِ بیرونی ندارد. `payments.method` رشته است تا وقتی
 *    درگاه آمد، مقدارِ تازه اضافه شود بی‌آنکه ساختار عوض شود.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** کلیدهای `app_settings` که این لایه می‌خواند. */
const PLAN_ENFORCE_SETTING = 'plan_enforced';
const PLAN_PRICE_SETTING   = 'plan_price_month';
const PLAN_CARD_SETTING    = 'plan_card_number';
const PLAN_OWNER_SETTING   = 'plan_card_owner';

/** دوره‌هایی که کاربر می‌تواند بخرد: ماه => ضریبِ قیمت. */
const PLAN_PERIODS = [
    1  => 1,
    6  => 5,    // یک ماه رایگان
    12 => 10,   // دو ماه رایگان
];

/** آیا ستون‌های طرح آمده‌اند؟ (پیش از اجرای migration نه) */
function plansAvailable(): bool
{
    return tableHasColumn('users', 'pro_until') && tableExists('payments');
}

/**
 * آیا محدودیت‌های طرح **اجرا** می‌شوند؟
 *
 * ⛔ پیش‌فرض خاموش. یک به‌روزرسانی نباید چیزی را از کاربرِ فعلی بگیرد.
 */
function planEnforced(): bool
{
    return getSetting(PLAN_ENFORCE_SETTING, '0') === '1';
}

/** قیمتِ یک ماه به تومان (۰ یعنی هنوز تعیین نشده). */
function planMonthlyPrice(): int
{
    return max(0, (int)getSetting(PLAN_PRICE_SETTING, '0'));
}

/**
 * وضعیتِ طرحِ یک کاربر.
 *
 * @return array{plan:string, pro_until:?string, is_pro:bool, days_left:?int}
 */
function userPlan(int $userId): array
{
    $none = ['plan' => 'free', 'pro_until' => null, 'is_pro' => false, 'days_left' => null];
    if (!plansAvailable()) { return $none; }

    try {
        $st = Database::getConnection()->prepare(
            'SELECT plan, pro_until,
                    (pro_until IS NOT NULL AND pro_until >= CURDATE()) AS active,
                    DATEDIFF(pro_until, CURDATE()) AS days_left
             FROM users WHERE id = :u'
        );
        $st->execute(['u' => $userId]);
        $r = $st->fetch();
    } catch (PDOException $e) {
        return $none;
    }
    if (!$r) { return $none; }

    return [
        'plan'      => (string)($r['plan'] ?? 'free'),
        'pro_until' => $r['pro_until'],
        // ⚠ مقایسه‌ی تاریخ در **دیتابیس** انجام می‌شود نه در PHP. یک بار
        //   در همین پروژه، ساختن زمان در PHP و مقایسه در MySQL باعث شد
        //   لینک بازیابی رمز بلافاصله «منقضی» شود، چون منطقه‌ی زمانیِ
        //   این دو یکی نبود.
        'is_pro'    => (int)($r['active'] ?? 0) === 1,
        'days_left' => $r['days_left'] === null ? null : (int)$r['days_left'],
    ];
}

/** آیا این کاربر امروز Pro است؟ */
function isPro(int $userId): bool
{
    return userPlan($userId)['is_pro'];
}

/**
 * آیا این کاربر به این قابلیت دسترسی دارد؟
 *
 * ⛔ تا وقتی `planEnforced()` خاموش است، **همیشه بله**. این تنها جایی
 *    است که این تصمیم گرفته می‌شود؛ هر گیتِ تازه‌ای باید از همین رد
 *    شود، وگرنه روشن کردنِ اجرا بعضی جاها اثر می‌کند و بعضی جاها نه.
 */
function planAllows(int $userId, string $feature): bool
{
    if (!planEnforced()) { return true; }
    if (isPro($userId))  { return true; }

    // قابلیت‌هایی که در طرحِ رایگان بسته‌اند. عمداً کوتاه است: هسته‌ی
    // اپ (تراکنش، حساب، چک، طلب و بدهی) هرگز نباید پولی شود، وگرنه
    // اپ برای کسی که پول نمی‌دهد بی‌فایده است و اصلاً امتحانش نمی‌کند.
    $proOnly = ['trades', 'api', 'reminders'];

    return !in_array($feature, $proOnly, true);
}

/**
 * ثبتِ یک پرداختِ اعلام‌شده توسط کاربر.
 *
 * ⚠ این فقط یک **ادعا**ست تا وقتی مدیر تأییدش کند. هیچ چیزی روی
 *   `pro_until` نمی‌نشیند.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function submitPayment(int $userId, int $months, string $reference, string $note = ''): array
{
    if (!plansAvailable()) { return ['ok' => false, 'error' => 'این قابلیت هنوز فعال نیست.']; }
    if (!isset(PLAN_PERIODS[$months])) { return ['ok' => false, 'error' => 'دوره‌ی انتخابی معتبر نیست.']; }

    $reference = trim($reference);
    if (mb_strlen($reference) < 4) {
        return ['ok' => false, 'error' => 'شماره پیگیری یا چهار رقم آخر کارت را وارد کنید.'];
    }

    $pdo = Database::getConnection();

    // ⚠ جلوگیری از انبوهِ درخواستِ باز: یک پرداختِ در انتظار در هر زمان
    //   کافی است. بدون این، کاربرِ بی‌حوصله ده بار می‌فرستد و صفحه‌ی
    //   مدیر پر از تکراری می‌شود.
    $st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = :u AND status = 'pending'");
    $st->execute(['u' => $userId]);
    if ((int)$st->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'یک پرداختِ در انتظارِ بررسی دارید.'];
    }

    try {
        $st = $pdo->prepare(
            "INSERT INTO payments (user_id, amount, months, method, reference, note, status)
             VALUES (:u, :a, :m, 'card', :r, :n, 'pending')"
        );
        $st->execute([
            'u' => $userId,
            'a' => planMonthlyPrice() * (PLAN_PERIODS[$months] ?? $months),
            'm' => $months,
            'r' => mb_substr($reference, 0, 120),
            'n' => mb_substr(trim($note), 0, 255) ?: null,
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (PDOException $e) {
        error_log('submitPayment: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ثبت نشد. دوباره تلاش کنید.'];
    }
}

/**
 * تأییدِ پرداخت توسط مدیر — و تمدیدِ اشتراک.
 *
 * ⛔ تمدید از **بیشترِ** «امروز» و «تاریخ انقضای فعلی» شروع می‌شود.
 *    اگر همیشه از امروز حساب می‌شد، کسی که یک ماه زودتر تمدید کند
 *    روزهای باقی‌مانده‌اش را از دست می‌داد — و آن یعنی تنبیهِ کاربرِ
 *    خوش‌حساب.
 *
 * ⚠ همه‌ی حسابِ تاریخ در دیتابیس انجام می‌شود، نه در PHP.
 *
 * @return array{ok:bool, until?:string, error?:string}
 */
function approvePayment(int $paymentId, int $adminId): array
{
    if (!plansAvailable()) { return ['ok' => false, 'error' => 'این قابلیت فعال نیست.']; }

    $pdo = Database::getConnection();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM payments WHERE id = :i AND status = 'pending' FOR UPDATE");
        $st->execute(['i' => $paymentId]);
        $p = $st->fetch();
        if (!$p) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'پرداختِ در انتظار با این شناسه نیست.'];
        }

        $months = max(1, (int)$p['months']);
        $pdo->prepare(
            "UPDATE users
             SET plan = 'pro',
                 pro_until = DATE_ADD(
                     GREATEST(COALESCE(pro_until, CURDATE()), CURDATE()),
                     INTERVAL :m MONTH)
             WHERE id = :u"
        )->execute(['m' => $months, 'u' => (int)$p['user_id']]);

        $pdo->prepare(
            "UPDATE payments SET status = 'approved', reviewed_by = :a, reviewed_at = NOW()
             WHERE id = :i"
        )->execute(['a' => $adminId, 'i' => $paymentId]);

        $st = $pdo->prepare('SELECT pro_until FROM users WHERE id = :u');
        $st->execute(['u' => (int)$p['user_id']]);
        $until = (string)$st->fetchColumn();

        $pdo->commit();
        return ['ok' => true, 'until' => $until];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('approvePayment: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تأیید انجام نشد.'];
    }
}

/** ردِ پرداخت. ردیف می‌ماند — تاریخچه پاک نمی‌شود. */
function rejectPayment(int $paymentId, int $adminId, string $reason = ''): bool
{
    if (!plansAvailable()) { return false; }
    try {
        $st = Database::getConnection()->prepare(
            "UPDATE payments
             SET status = 'rejected', reviewed_by = :a, reviewed_at = NOW(),
                 note = CONCAT(COALESCE(note, ''), :r)
             WHERE id = :i AND status = 'pending'"
        );
        $st->execute([
            'a' => $adminId,
            'r' => $reason !== '' ? ' | رد: ' . mb_substr($reason, 0, 120) : '',
            'i' => $paymentId,
        ]);
        return $st->rowCount() === 1;
    } catch (PDOException $e) {
        error_log('rejectPayment: ' . $e->getMessage());
        return false;
    }
}

/** پرداخت‌های در انتظارِ بررسی، برای پنل مدیر. */
function pendingPayments(int $limit = 50): array
{
    if (!plansAvailable()) { return []; }
    try {
        $st = Database::getConnection()->prepare(
            "SELECT p.*, u.username, u.full_name
             FROM payments p JOIN users u ON u.id = p.user_id
             WHERE p.status = 'pending'
             ORDER BY p.created_at ASC
             LIMIT :n"
        );
        $st->bindValue('n', max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** تاریخچه‌ی پرداخت‌های یک کاربر. */
function userPayments(int $userId, int $limit = 20): array
{
    if (!plansAvailable()) { return []; }
    try {
        $st = Database::getConnection()->prepare(
            'SELECT * FROM payments WHERE user_id = :u ORDER BY created_at DESC LIMIT :n'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('n', max(1, min(100, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
