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
require_once __DIR__ . '/notify.php';

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

/**
 * دوره‌هایی که **مدیر** می‌تواند بدون پرداخت بدهد — تنها مرجع.
 *
 * ⛔ عمداً از `PLAN_PERIODS` جداست و نباید یکی شوند: آن یکی فهرستِ
 *    **فروش** است (ضریبِ تخفیف دارد و روی صفحه‌ی خرید دیده می‌شود)، این
 *    یکی فهرستِ **هدیه**. اگر یکی می‌شدند، اضافه کردنِ «۲ ماهه» برای
 *    مدیر یک گزینه‌ی فروشِ بی‌قیمت هم می‌ساخت.
 *
 * ⚠ کلیدِ `0` یعنی **مادام‌العمر**، نه «صفر ماه».
 */
const PLAN_GRANT_PERIODS = [
    1  => 'یک ماهه',
    2  => 'دو ماهه',
    3  => 'سه ماهه',
    6  => 'شش ماهه',
    12 => 'یک ساله',
    0  => 'مادام‌العمر',
];

/**
 * ⛔ «مادام‌العمر» یک تاریخِ دور است، نه یک کلیدِ بولین.
 *
 * همان استدلالی که `pro_until` را از اول تاریخ کرد: با یک ستونِ بولینِ
 * `is_lifetime` جدا، دو منبعِ حقیقت می‌شد و هر کوئری‌ای که فقط
 * `pro_until >= CURDATE()` را می‌دید باید هم عوض می‌شد — و آن‌که عوض
 * نمی‌شد، بی‌صدا کاربرِ مادام‌العمر را منقضی نشان می‌داد.
 *
 * ⚠ بیشترین مقدارِ مجازِ `DATE` در MySQL همین است.
 */
const PLAN_FOREVER_DATE = '9999-12-31';

/**
 * آستانه‌ی «بی‌پایان» — تنها مرجع، و هم PHP هم SQL از همین می‌خوانند.
 *
 * ⚠ عمداً پایین‌تر از خودِ `PLAN_FOREVER_DATE` است تا اگر روزی مقدارِ
 *   دیگری (مثلاً ۲۱۰۰) به‌عنوان بی‌پایان ثبت شده باشد هم شناخته شود.
 *   عددِ ثابتِ دوم ننویسید: اگر PHP و SQL دو آستانه داشته باشند، یکی
 *   کاربر را بی‌پایان می‌بیند و دیگری نه.
 */
const PLAN_FOREVER_THRESHOLD = '2200-01-01';

/** آیا این تاریخِ انقضا در عمل «بی‌پایان» است؟ */
function planIsForever(?string $until): bool
{
    // ⚠ مقایسه‌ی رشته‌ای بس است چون قالب `Y-m-d` مرتب‌شدنی است.
    return $until !== null && $until !== '' && $until >= PLAN_FOREVER_THRESHOLD;
}

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
 * @return array{plan:string, pro_until:?string, is_pro:bool, is_forever:bool, days_left:?int}
 */
function userPlan(int $userId): array
{
    $none = ['plan' => 'free', 'pro_until' => null, 'is_pro' => false,
             'is_forever' => false, 'days_left' => null];
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
        // ⛔ برای اشتراکِ مادام‌العمر، «روزهای باقی‌مانده» بی‌معناست و
        //    عددش مسخره است (نزدیک سه میلیون روز، با تاریخِ شمسیِ سالِ
        //    ۹۳۷۸ کنارش). پس `null` می‌شود و صفحه به‌جایش «مادام‌العمر»
        //    می‌نویسد. بدونِ این، هدیه‌ی مدیر روی صفحه‌ی کاربر شبیهِ یک
        //    باگ دیده می‌شد.
        'is_forever' => planIsForever($r['pro_until']),
        'days_left' => (planIsForever($r['pro_until']) || $r['days_left'] === null)
                       ? null : (int)$r['days_left'],
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

    // ⛔ قابلیت‌هایی که در طرحِ رایگان بسته‌اند — **تنها فهرستِ این
    //    تصمیم**. هر گیتِ تازه‌ای باید از همین رد شود.
    //
    // ⚠ چک و طلب و بدهی و تراکنشِ دوره‌ای به خواستِ مالکِ نصب اینجا
    //   آمدند. یک نکته را ثبت می‌کنم چون بعداً از روی داده سنجیدنی
    //   است: همین سه تا بیشترین تفاوتِ این اپ با اپ‌های خارجی‌اند، پس
    //   بستنشان یعنی کاربرِ رایگان دقیقاً همان چیزی را نمی‌بیند که
    //   قرار بود قانعش کند. اگر «قیفِ شروع» در آمار استفاده افت کرد،
    //   اول همین فهرست را نگاه کنید — نه امکاناتِ تازه.
    //
    // ⛔ هسته‌ی ثبتِ پول (تراکنش، حساب، دسته‌بندی، بودجه، پس‌انداز،
    //    دارایی، گزارش) عمداً بیرون مانده: اگر کاربر نتواند حتی خرجش
    //    را ثبت کند، اپ برایش بی‌فایده است و اصلاً امتحانش نمی‌کند.
    $proOnly = ['trades', 'cheques', 'debts', 'recurring', 'api', 'reminders'];

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
/**
 * ⛔ تنها جایی که `pro_until` جلو می‌رود.
 *
 * از بیشترِ «امروز» و «انقضای فعلی» شروع می‌شود؛ اگر همیشه از امروز
 * حساب می‌شد، کسی که یک ماه زودتر تمدید کند روزهای باقی‌مانده‌اش را از
 * دست می‌داد — یعنی تنبیهِ کاربرِ خوش‌حساب.
 *
 * ⚠ `$months = 0` یعنی **مادام‌العمر** و مسیرش جداست: `DATE_ADD` با صفر
 *   ماه هیچ کاری نمی‌کند و کاربر بی‌صدا همان انقضای قبلی را نگه می‌داشت.
 *
 * ⚠ همه‌ی حسابِ تاریخ در **دیتابیس** انجام می‌شود، نه در PHP — همان درسی
 *   که لینکِ بازیابیِ رمز داد (منطقه‌ی زمانیِ PHP و MySQL یکی نبود).
 */
function planExtendUntil(PDO $pdo, int $userId, int $months): void
{
    if ($months <= 0) {
        $pdo->prepare("UPDATE users SET plan = 'pro', pro_until = :d WHERE id = :u")
            ->execute(['d' => PLAN_FOREVER_DATE, 'u' => $userId]);
        return;
    }

    // ⛔ اشتراکِ مادام‌العمر دست نمی‌خورد، و این محافظِ یک خرابیِ واقعیِ
    //    **بی‌صدا**ست: `DATE_ADD('9999-12-31', INTERVAL 7 MONTH)` از
    //    بازه‌ی مجازِ `DATE` بیرون می‌زند و MySQL به‌جای خطا **NULL**
    //    برمی‌گرداند. ستون هم nullable است، پس هیچ استثنایی پرتاب
    //    نمی‌شد: مدیری که به کاربرِ مادام‌العمر اشتباهاً «یک ماهه» هم
    //    می‌داد، دسترسیِ او را **کاملاً پاک می‌کرد** و پیامِ «فعال شد»
    //    هم می‌گرفت.
    //
    //    این را آزمونِ جهش پیدا کرد، نه بازبینیِ چشمی — و اول به شکلِ
    //    یک تستِ سبزِ دروغین ظاهر شد: بررسیِ «دوره‌ی نامعتبر» بعد از
    //    هدیه‌ی مادام‌العمر اجرا می‌شد، پس به دلیلِ همین سرریز رد
    //    می‌شد و نه به دلیلِ خودِ اعتبارسنجی.
    $pdo->prepare(
        "UPDATE users
         SET plan = 'pro',
             pro_until = CASE
                 WHEN pro_until IS NOT NULL AND pro_until >= :forever THEN pro_until
                 ELSE DATE_ADD(
                     GREATEST(COALESCE(pro_until, CURDATE()), CURDATE()),
                     INTERVAL :m MONTH)
             END
         WHERE id = :u"
    )->execute(['m' => $months, 'forever' => PLAN_FOREVER_THRESHOLD, 'u' => $userId]);
}

/**
 * هدیه‌ی مدیر: دسترسی کامل بدون پرداخت.
 *
 * ⛔ یک ردیف در `payments` هم ثبت می‌شود (`method = 'admin_grant'`,
 *    `amount = 0`, `status = 'approved'`) — نه برای پول، برای **رد**.
 *    بدونِ آن، شش ماه بعد هیچ‌کس نمی‌فهمید چرا این کاربر Pro است و چه
 *    کسی این را داده؛ و همان قاعده‌ی «پرداخت‌ها حذف نمی‌شوند» یعنی
 *    تاریخچه باید کامل باشد، نه فقط نیمه‌ی پولی‌اش.
 *
 * ⚠ جدولِ تازه‌ای ساخته نشد: `payments` از قبل همین شکل را دارد و صفحه‌ی
 *   مدیر هم از قبل نشانش می‌دهد.
 *
 * @param int $months یکی از کلیدهای `PLAN_GRANT_PERIODS`؛ ۰ یعنی مادام‌العمر
 * @return array{ok:bool, until?:string, error?:string}
 */
function grantPro(int $userId, int $months, int $adminId, string $note = ''): array
{
    if (!plansAvailable()) { return ['ok' => false, 'error' => 'این قابلیت فعال نیست.']; }
    if (!array_key_exists($months, PLAN_GRANT_PERIODS)) {
        return ['ok' => false, 'error' => 'دوره‌ی انتخابی معتبر نیست.'];
    }

    $pdo = Database::getConnection();
    $pdo->beginTransaction();
    try {
        // ⚠ کاربر باید واقعاً باشد، وگرنه یک ردیفِ پرداختِ یتیم می‌ماند.
        $st = $pdo->prepare('SELECT id FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        if (!$st->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'کاربر پیدا نشد.'];
        }

        planExtendUntil($pdo, $userId, $months);

        $pdo->prepare(
            "INSERT INTO payments (user_id, amount, months, method, note, status, reviewed_by, reviewed_at)
             VALUES (:u, 0, :m, 'admin_grant', :n, 'approved', :a, NOW())"
        )->execute([
            'u' => $userId,
            'm' => max(0, $months),
            'n' => mb_substr($note !== '' ? $note : 'دسترسی رایگان توسط مدیر', 0, 255),
            'a' => $adminId,
        ]);

        $st = $pdo->prepare('SELECT pro_until FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        $until = (string)$st->fetchColumn();

        $pdo->commit();

        // ⛔ **بعد از** commit — همان قاعده‌ی `approvePayment()`: خطای
        //    اعلان نباید یک دسترسیِ داده‌شده را پس بگیرد.
        Notify::push(
            $userId,
            'payment',
            'دسترسی کامل برای شما فعال شد',
            planIsForever($until) ? 'اشتراک شما مادام‌العمر است.' : 'اشتراک تا ' . toJalali($until) . ' فعال است.',
            'pro.php',
            'grant:' . $userId . ':' . $until
        );

        return ['ok' => true, 'until' => $until];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('grantPro: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ثبت دسترسی انجام نشد.'];
    }
}

/**
 * پس گرفتنِ دسترسی.
 *
 * ⛔ بدونِ این، «دادن» یک درِ یک‌طرفه بود: مدیری که اشتباهاً مادام‌العمر
 *    داده هیچ راهی برای برگرداندنش نداشت جز دست بردن در دیتابیس.
 *
 * ⚠ ردیف‌های `payments` **پاک نمی‌شوند** — تاریخچه‌ی مالی می‌ماند، حتی
 *   وقتی دسترسی پس گرفته شده. فقط یک ردیفِ تازه‌ی `admin_revoke` برای
 *   رد به آن اضافه می‌شود.
 */
function revokePro(int $userId, int $adminId): array
{
    if (!plansAvailable()) { return ['ok' => false, 'error' => 'این قابلیت فعال نیست.']; }

    $pdo = Database::getConnection();
    try {
        $pdo->prepare("UPDATE users SET plan = 'free', pro_until = NULL WHERE id = :u")
            ->execute(['u' => $userId]);
        $pdo->prepare(
            "INSERT INTO payments (user_id, amount, months, method, note, status, reviewed_by, reviewed_at)
             VALUES (:u, 0, 0, 'admin_revoke', 'دسترسی توسط مدیر پس گرفته شد', 'approved', :a, NOW())"
        )->execute(['u' => $userId, 'a' => $adminId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('revokePro: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'پس گرفتن انجام نشد.'];
    }
}

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
        // ⛔ از تابعِ مشترک رد می‌شود، نه یک `UPDATE` محلی: قاعده‌ی
        //    «تمدید از بیشترِ امروز و انقضای فعلی» یک بار نوشته می‌شود.
        //    با نسخه‌ی دوم، تأییدِ پرداخت و هدیه‌ی مدیر دیر یا زود دو
        //    جور حساب می‌کردند و تفاوتش فقط در عددِ روزهای کاربر دیده
        //    می‌شد — یعنی بی‌صدا.
        planExtendUntil($pdo, (int)$p['user_id'], $months);

        $pdo->prepare(
            "UPDATE payments SET status = 'approved', reviewed_by = :a, reviewed_at = NOW()
             WHERE id = :i"
        )->execute(['a' => $adminId, 'i' => $paymentId]);

        $st = $pdo->prepare('SELECT pro_until FROM users WHERE id = :u');
        $st->execute(['u' => (int)$p['user_id']]);
        $until = (string)$st->fetchColumn();

        $pdo->commit();

        // ⛔ **بعد از** commit، نه داخلِ تراکنش: اعلان کارِ جانبی است و
        //    اگر داخل می‌بود، خطای آن می‌توانست یک پرداختِ تأییدشده را
        //    برگرداند — یعنی کاربر پول داده و اشتراکش فعال نشده.
        Notify::push(
            (int)$p['user_id'],
            'payment',
            'پرداخت شما تأیید شد',
            'اشتراک تا ' . toJalali($until) . ' فعال است.',
            'pro.php',
            'payment:' . $paymentId . ':approved'
        );

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
        if ($st->rowCount() !== 1) { return false; }

        // ⚠ کاربر باید بفهمد که رد شده، وگرنه منتظر می‌ماند و دوباره
        //   اعلامِ پرداخت می‌کند. دلیلِ رد هم می‌رود تا لازم نباشد بپرسد.
        $who = Database::getConnection()->prepare('SELECT user_id FROM payments WHERE id = :i');
        $who->execute(['i' => $paymentId]);
        $uid = (int)$who->fetchColumn();
        Notify::push($uid, 'payment', 'پرداخت شما تأیید نشد',
            $reason !== '' ? mb_substr($reason, 0, 300) : 'برای پیگیری با پشتیبانی تماس بگیرید.',
            'pro.php', 'payment:' . $paymentId . ':rejected');

        return true;
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
