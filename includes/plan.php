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
    //
    // ⛔ `sms_login` = **ورودِ همیشگی** با کد پیامکی، نه ثبت‌نام. ساختنِ
    //    حساب با شماره همیشه رایگان است و از این فهرست رد نمی‌شود؛ اگر
    //    می‌شد، کاربرِ تازه باید پیش از داشتنِ حساب پول می‌داد. جزئیاتِ
    //    این مرز و استثنای «حسابِ بی‌رمز» در `SmsLogin::loginAllowedFor()`
    //    است — تنها جایی که این گیت خوانده می‌شود.
    $proOnly = ['trades', 'cheques', 'debts', 'recurring', 'api', 'reminders', 'sms_login'];

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
function submitPayment(int $userId, int $months, string $reference, string $note = '',
                      string $rawCode = ''): array
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

    // ⚠ روی نصبی که هنوز `migration_discount_codes` را نخورده، کد
    //   بی‌صدا نادیده گرفته می‌شود و خرید مثل قبل کار می‌کند.
    $code = discountCodesAvailable() ? normalizeDiscountCode($rawCode) : '';

    $pdo->beginTransaction();
    try {
        $row = null;
        if ($code !== '') {
            // ⛔ کد **دوباره** و زیرِ قفل سنجیده می‌شود، نه با اعتماد به
            //    چیزی که صفحه فرستاده. آن یکی برای نشان دادنِ قیمت بود؛
            //    این یکی تصمیم است. بینِ دیدنِ صفحه و زدنِ دکمه ممکن است
            //    ظرفیت تمام شده باشد.
            $lock = $pdo->prepare('SELECT * FROM discount_codes WHERE code = :c FOR UPDATE');
            $lock->execute(['c' => $code]);
            $row = $lock->fetch();

            $chk = $row ? discountCheck($code, $userId) : ['ok' => false, 'error' => 'چنین کدی وجود ندارد.'];
            if (!$chk['ok']) { throw new RuntimeException($chk['error']); }

            // ⛔ کدِ ۱۰۰٪ از این مسیر نمی‌رود: آن یکی همان لحظه دسترسی
            //    می‌دهد و اصلاً پرداختی در کار نیست. اگر اینجا می‌آمد،
            //    کاربر یک پرداختِ صفر تومانی ثبت می‌کرد و منتظرِ تأییدِ
            //    مدیر می‌ماند — برای چیزی که باید فوری باشد.
            if (discountIsFree($row)) {
                throw new RuntimeException('این کد دسترسی رایگان می‌دهد؛ از دکمه‌ی «فعال‌سازی رایگان» استفاده کنید.');
            }

            if (!consumeDiscountUse($pdo, (int)$row['id'])) {
                throw new RuntimeException('ظرفیت این کد تمام شده است.');
            }
        }

        $amount = discountFinalPrice($row, $months)['final'];

        $params = [
            'u' => $userId,
            'a' => $amount,
            'm' => $months,
            'r' => mb_substr($reference, 0, 120),
            'n' => mb_substr(trim($note), 0, 255) ?: null,
        ];
        // ⚠ ستونِ کد فقط وقتی در کوئری می‌آید که واقعاً وجود داشته باشد،
        //   وگرنه نصبِ migration‌نخورده روی ثبتِ ساده‌ی پرداخت می‌شکست.
        if (discountCodesAvailable()) {
            $sql = "INSERT INTO payments (user_id, amount, months, method, discount_code, reference, note, status)
                    VALUES (:u, :a, :m, 'card', :c, :r, :n, 'pending')";
            $params['c'] = $code !== '' ? $code : null;
        } else {
            $sql = "INSERT INTO payments (user_id, amount, months, method, reference, note, status)
                    VALUES (:u, :a, :m, 'card', :r, :n, 'pending')";
        }
        $pdo->prepare($sql)->execute($params);
        $id = (int)$pdo->lastInsertId();

        $pdo->commit();
        return ['ok' => true, 'id' => $id, 'amount' => $amount];
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        $pdo->rollBack();
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
    $pdo = Database::getConnection();
    try {
        $pdo->beginTransaction();

        // ⛔ کدِ پرداخت **پیش از** عوض کردنِ وضعیت خوانده می‌شود: بعدش
        //    ردیف دیگر `pending` نیست و ظرفیتِ گرفته‌شده بی‌صاحب می‌ماند.
        // ⚠ ستونِ کد با `migration_discount_codes` می‌آید؛ نصبی که هنوز
        //   آن را ندارد نباید اینجا بشکند.
        $codeCol = discountCodesAvailable() ? 'discount_code' : "'' AS discount_code";
        $before = $pdo->prepare("SELECT user_id, {$codeCol} FROM payments
                                 WHERE id = :i AND status = 'pending' FOR UPDATE");
        $before->execute(['i' => $paymentId]);
        $prev = $before->fetch();
        if (!$prev) { $pdo->rollBack(); return false; }

        $st = $pdo->prepare(
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
        if ($st->rowCount() !== 1) { $pdo->rollBack(); return false; }

        // ⛔ ظرفیتِ کد پس داده می‌شود. بدونِ این، کدی که «۱۰ نفر اول» را
        //    هدف گرفته با ۱۰ پرداختِ ردشده تمام می‌شد بی‌آنکه حتی یک نفر
        //    چیزی گرفته باشد — و مالکِ نصب فقط می‌دید کد تمام شده.
        releaseDiscountUse($pdo, (string)($prev['discount_code'] ?? ''));

        $uid = (int)$prev['user_id'];
        $pdo->commit();

        // ⚠ کاربر باید بفهمد که رد شده، وگرنه منتظر می‌ماند و دوباره
        //   اعلامِ پرداخت می‌کند. دلیلِ رد هم می‌رود تا لازم نباشد بپرسد.
        //   ⛔ بعد از commit، مثل `approvePayment()`.
        Notify::push($uid, 'payment', 'پرداخت شما تأیید نشد',
            $reason !== '' ? mb_substr($reason, 0, 300) : 'برای پیگیری با پشتیبانی تماس بگیرید.',
            'pro.php', 'payment:' . $paymentId . ':rejected');

        return true;
    } catch (Throwable $e) {
        // ⚠ بدونِ این rollback، یک خطای وسطِ کار تراکنش را باز می‌گذاشت و
        //   ردیفِ قفل‌شده تا پایانِ درخواست دستِ کسی نمی‌آمد.
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('rejectPayment: ' . $e->getMessage());
        return false;
    }
}

/* ============================================================
   کد تخفیف — و سقفِ «n نفر اول»
   ============================================================

   دو کارِ جدا با یک جدول، و تفاوتشان فقط در درصد است:

     ۱۰۰٪  → دسترسی کامل و رایگان برای `months` ماه. کاربر کد را
             وارد می‌کند و **همان لحظه** Pro می‌شود؛ نه پرداختی، نه
             انتظارِ تأییدِ مدیر. این همان چیزی است که «۱۰۰ نفر اول»
             می‌خواهد.
     کمتر  → تخفیف روی قیمتِ خرید. کد همراهِ اعلامِ پرداخت ثبت
             می‌شود و مدیر مبلغِ تخفیف‌خورده را می‌بیند.

   ⛔ همه‌ی این تصمیم‌ها در همین چند تابع‌اند و صفحه‌ها فقط صدایشان
      می‌زنند (مثل `categoryScopeSql()`). با نسخه‌ی دومِ «آیا این کد
      معتبر است؟» در `pro.php`، صفحه‌ی خرید و اندپوینتِ ثبت دیر یا زود
      دو جواب متفاوت می‌دادند — و آن‌وقت کاربر تخفیف را روی صفحه
      می‌دید ولی هنگام ثبت نمی‌گرفت. */

/** بلندترین طولِ مجازِ کد — با ستونِ `discount_codes.code` یکی است. */
const DISCOUNT_CODE_MAX_LEN = 40;

/**
 * الفبای ساختِ خودکار.
 *
 * ⚠ `O`/`0` و `I`/`1` عمداً نیستند: کد را کاربر از روی یک پیام یا
 *   بنر **تایپ** می‌کند و این دو جفت رایج‌ترین اشتباهِ تایپ‌اند —
 *   نتیجه‌اش «کد نامعتبر است» برای کسی که کارِ درست را کرده.
 */
const DISCOUNT_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * ⛔ تنها جایی که یک کد «شکلِ رسمی» می‌گیرد.
 *
 * ذخیره و جست‌وجو هر دو از همین رد می‌شوند، وگرنه مدیر `nowruz` را
 * ثبت می‌کند، کاربر `NOWRUZ ` را می‌زند و «کد پیدا نشد» می‌گیرد —
 * خرابیِ کاملاً بی‌صدا، چون هر دو طرف مطمئن‌اند درست تایپ کرده‌اند.
 *
 * ⚠ ارقامِ فارسی هم لاتین می‌شوند: کاربرِ ایرانی با کیبوردِ فارسی
 *   «۱۰۰» می‌نویسد و آن با `100` یکی نیست.
 */
function normalizeDiscountCode(string $raw): string
{
    $s = toLatinDigits(trim($raw));
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9_-]/', '', $s) ?? '';
    return mb_substr($s, 0, DISCOUNT_CODE_MAX_LEN);
}

/** آیا جدولِ کد تخفیف آمده است؟ (پیش از اجرای migration نه) */
function discountCodesAvailable(): bool
{
    return plansAvailable()
        && tableExists('discount_codes')
        && tableHasColumn('payments', 'discount_code');
}

/**
 * وضعیتِ یک کد — تنها جای این تصمیم.
 *
 * @return string یکی از: active | off | expired | used_up
 */
function discountCodeState(array $row): string
{
    if ((int)($row['is_active'] ?? 0) !== 1) { return 'off'; }
    // ⚠ مقایسه‌ی رشته‌ای بس است چون قالبِ `Y-m-d` مرتب‌شدنی است؛ و
    //   تاریخِ «امروز» از همان ساعتِ PHP می‌آید که بقیه‌ی صفحه از آن
    //   استفاده می‌کند. برای انقضای **روز** دقتِ ثانیه معنا ندارد.
    $exp = (string)($row['expires_at'] ?? '');
    if ($exp !== '' && $exp < date('Y-m-d')) { return 'expired'; }

    $max = (int)($row['max_uses'] ?? 0);
    if ($max > 0 && (int)($row['used_count'] ?? 0) >= $max) { return 'used_up'; }

    return 'active';
}

/** برچسبِ فارسیِ وضعیت — یک جا، تا صفحه‌ها متنِ خودشان را نسازند. */
function discountCodeStateLabel(string $state): string
{
    return [
        'active'  => 'فعال',
        'off'     => 'خاموش',
        'expired' => 'منقضی',
        'used_up' => 'ظرفیت تمام',
    ][$state] ?? $state;
}

/** ردیفِ یک کد، یا `null`. */
function findDiscountCode(string $raw): ?array
{
    if (!discountCodesAvailable()) { return null; }
    $code = normalizeDiscountCode($raw);
    if ($code === '') { return null; }

    try {
        // ⚠ کد همیشه به‌صورت پارامترِ bind شده مقایسه می‌شود، نه
        //   ستون‌به‌ستون: مقایسه‌ی دو ستون هر دو با «قابلیتِ تبدیلِ ۲»
        //   روی نصبی با collation متفاوت «Illegal mix of collations»
        //   می‌داد.
        $st = Database::getConnection()->prepare('SELECT * FROM discount_codes WHERE code = :c');
        $st->execute(['c' => $code]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * قیمتِ یک دوره بعد از این کد.
 *
 * ⛔ تنها جای این حساب. `pro.php` عدد را نشان می‌دهد و
 *    `submitPayment()` همان را ذخیره می‌کند؛ با دو نسخه، کاربر یک
 *    مبلغ می‌دید و مبلغِ دیگری در پرداختش ثبت می‌شد.
 *
 * ⚠ تخفیف رو به **پایین** گرد می‌شود (`intdiv`)، پس مبلغِ نهایی هرگز
 *   از چیزی که روی صفحه نوشته شده بیشتر نمی‌شود.
 *
 * @return array{price:int, discount:int, final:int}
 */
function discountFinalPrice(?array $code, int $months): array
{
    $price = planMonthlyPrice() * (PLAN_PERIODS[$months] ?? $months);
    $pct   = $code ? max(0, min(100, (int)$code['percent'])) : 0;
    $off   = intdiv($price * $pct, 100);
    return ['price' => $price, 'discount' => $off, 'final' => max(0, $price - $off)];
}

/** آیا این کد «دسترسی رایگان» است، یا تخفیف روی خرید؟ */
function discountIsFree(array $code): bool
{
    return (int)$code['percent'] >= 100;
}

/**
 * آیا این کاربر می‌تواند همین حالا این کد را به کار ببرد؟
 *
 * @return array{ok:bool, error?:string, code?:array}
 */
function discountCheck(string $raw, int $userId): array
{
    if (!discountCodesAvailable()) {
        return ['ok' => false, 'error' => 'کد تخفیف روی این نصب فعال نیست.'];
    }
    $row = findDiscountCode($raw);

    // ⚠ پیامِ «پیدا نشد» و «تمام شده» عمداً یکی **نیست**: کاربری که کد
    //   درست را دیر زده باید بفهمد ظرفیت تمام شده، وگرنه فکر می‌کند
    //   اشتباه تایپ کرده و ده بار دوباره امتحان می‌کند. اینجا چیزی هم
    //   لو نمی‌رود — کد را خودمان پخش کرده‌ایم.
    if (!$row) { return ['ok' => false, 'error' => 'چنین کدی وجود ندارد.']; }

    $state = discountCodeState($row);
    if ($state === 'used_up') {
        return ['ok' => false, 'error' => 'ظرفیت این کد تمام شده است.'];
    }
    if ($state === 'expired') {
        return ['ok' => false, 'error' => 'مهلت این کد تمام شده است.'];
    }
    if ($state !== 'active') {
        return ['ok' => false, 'error' => 'این کد فعال نیست.'];
    }

    if (discountCodeUsedBy($row['code'], $userId)) {
        return ['ok' => false, 'error' => 'شما یک بار از این کد استفاده کرده‌اید.'];
    }

    return ['ok' => true, 'code' => $row];
}

/** آیا این کاربر قبلاً این کد را به کار برده؟ (پرداختِ ردشده حساب نمی‌شود) */
function discountCodeUsedBy(string $code, int $userId): bool
{
    try {
        $st = Database::getConnection()->prepare(
            "SELECT 1 FROM payments
             WHERE user_id = :u AND discount_code = :c AND status <> 'rejected' LIMIT 1"
        );
        $st->execute(['u' => $userId, 'c' => $code]);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * یک ظرفیت از کد برمی‌دارد — **فقط داخلِ تراکنشی که کد را قفل کرده**.
 *
 * ⛔ شرطِ سقف روی خودِ `UPDATE` هم هست، نه فقط در PHP. این افزونه است
 *    (فراخواننده از قبل سنجیده) ولی آخرین سدِ «۱۰ نفر اول» همین است:
 *    اگر روزی مسیرِ تازه‌ای بدونِ سنجش صدایش بزند، دیتابیس خودش جلویش
 *    را می‌گیرد و `rowCount()` صفر برمی‌گردد.
 */
function consumeDiscountUse(PDO $pdo, int $codeId): bool
{
    $st = $pdo->prepare(
        'UPDATE discount_codes SET used_count = used_count + 1
         WHERE id = :i AND (max_uses = 0 OR used_count < max_uses)'
    );
    $st->execute(['i' => $codeId]);
    return $st->rowCount() === 1;
}

/**
 * ظرفیتِ گرفته‌شده را پس می‌دهد.
 *
 * ⛔ بدونِ این، کدی که ۱۰ ظرفیت دارد با ۱۰ پرداختِ **ردشده** تمام
 *    می‌شد، بی‌آنکه حتی یک نفر چیزی گرفته باشد — و مالکِ نصب فقط
 *    می‌دید کد «تمام شده» است بی‌آنکه بفهمد چرا.
 *
 * ⚠ کفِ صفر: `used_count` بدون علامت است و کم کردن از صفر روی
 *   MySQL خطا می‌دهد (یا با حالتِ غیر-strict بی‌صدا به صفر می‌رود).
 */
function releaseDiscountUse(PDO $pdo, string $code): void
{
    if ($code === '') { return; }
    $pdo->prepare(
        'UPDATE discount_codes SET used_count = GREATEST(used_count, 1) - 1 WHERE code = :c'
    )->execute(['c' => $code]);
}

/**
 * کدِ ۱۰۰٪ را همین حالا اعمال می‌کند: دسترسی کامل، رایگان.
 *
 * ⛔ کلِ کار داخلِ یک تراکنش است که با `SELECT ... FOR UPDATE` روی خودِ
 *    ردیفِ کد شروع می‌شود. بدونِ آن قفل، دو نفر که هم‌زمان آخرین ظرفیت
 *    را می‌زنند هر دو «مانده: ۱» می‌دیدند و هر دو می‌گرفتند — یعنی
 *    «۱۰ نفر اول» بی‌صدا می‌شد ۱۱ نفر.
 *
 * ⚠ عمداً به `plansAvailable()` و «قیمت و کارت تنظیم شده» بند نیست:
 *   «۱۰۰ نفر اولِ نصب رایگان‌اند» باید روزِ اول کار کند، وقتی هنوز هیچ
 *   شماره کارتی وارد نشده.
 *
 * @return array{ok:bool, until?:string, months?:int, error?:string}
 */
function redeemDiscountCode(int $userId, string $raw): array
{
    if (!discountCodesAvailable()) {
        return ['ok' => false, 'error' => 'کد تخفیف روی این نصب فعال نیست.'];
    }
    $code = normalizeDiscountCode($raw);
    if ($code === '') { return ['ok' => false, 'error' => 'کد را وارد کنید.']; }

    $pdo = Database::getConnection();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM discount_codes WHERE code = :c FOR UPDATE');
        $st->execute(['c' => $code]);
        $row = $st->fetch();

        if (!$row) { throw new RuntimeException('چنین کدی وجود ندارد.'); }

        $state = discountCodeState($row);
        if ($state === 'used_up') { throw new RuntimeException('ظرفیت این کد تمام شده است.'); }
        if ($state === 'expired') { throw new RuntimeException('مهلت این کد تمام شده است.'); }
        if ($state !== 'active')  { throw new RuntimeException('این کد فعال نیست.'); }

        if (!discountIsFree($row)) {
            throw new RuntimeException('این کد روی خرید تخفیف می‌دهد و به‌تنهایی دسترسی نمی‌سازد.');
        }

        // ⚠ اینجا `FOR UPDATE` است تا دو تپِ هم‌زمانِ **یک کاربر** هم دو
        //   بار دسترسی ندهد. کوئری با `user_id` شروع می‌شود که ایندکس
        //   دارد، پس چیزِ زیادی قفل نمی‌شود.
        $dup = $pdo->prepare(
            "SELECT 1 FROM payments
             WHERE user_id = :u AND discount_code = :c AND status <> 'rejected'
             LIMIT 1 FOR UPDATE"
        );
        $dup->execute(['u' => $userId, 'c' => $code]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('شما یک بار از این کد استفاده کرده‌اید.');
        }

        if (!consumeDiscountUse($pdo, (int)$row['id'])) {
            throw new RuntimeException('ظرفیت این کد تمام شده است.');
        }

        $months = (int)$row['months'];
        planExtendUntil($pdo, $userId, $months);

        // ⛔ یک ردیف در `payments` هم ثبت می‌شود — نه برای پول، برای
        //    **رد**: شش ماه بعد باید معلوم باشد چرا این کاربر Pro است.
        //    همان استدلالِ `admin_grant`. و شمارشِ «چه کسی این کد را
        //    زده» هم از همین‌جا درمی‌آید، بدونِ هیچ جدولِ تازه‌ای.
        $pdo->prepare(
            "INSERT INTO payments
                (user_id, amount, months, method, discount_code, reference, note, status, reviewed_at)
             VALUES (:u, 0, :m, 'discount', :c, :c2, :n, 'approved', NOW())"
        )->execute([
            'u'  => $userId,
            'm'  => max(0, $months),
            'c'  => $code,
            'c2' => $code,
            'n'  => mb_substr('دسترسی رایگان با کد تخفیف', 0, 255),
        ]);

        $u = $pdo->prepare('SELECT pro_until FROM users WHERE id = :u');
        $u->execute(['u' => $userId]);
        $until = (string)$u->fetchColumn();

        $pdo->commit();

        // ⛔ بعد از commit — همان قاعده‌ی `approvePayment()`: خطای اعلان
        //    نباید دسترسیِ داده‌شده را پس بگیرد.
        Notify::push(
            $userId,
            'payment',
            'دسترسی کامل برای شما فعال شد',
            planIsForever($until)
                ? 'با کد تخفیف، اشتراک شما مادام‌العمر شد.'
                : 'با کد تخفیف، اشتراک تا ' . toJalali($until) . ' فعال شد.',
            'pro.php',
            'discount:' . $code . ':' . $userId
        );

        return ['ok' => true, 'until' => $until, 'months' => $months];
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('redeemDiscountCode: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'اعمالِ کد انجام نشد.'];
    }
}

/** یک کدِ خواندنی و بی‌ابهام می‌سازد (مثل `HESAB-7K4Q`). */
function generateDiscountCode(string $prefix = ''): string
{
    $body = '';
    $n = mb_strlen(DISCOUNT_CODE_ALPHABET);
    for ($i = 0; $i < 6; $i++) {
        $body .= DISCOUNT_CODE_ALPHABET[random_int(0, $n - 1)];
    }
    $prefix = normalizeDiscountCode($prefix);
    return normalizeDiscountCode($prefix === '' ? $body : $prefix . '-' . $body);
}

/**
 * ساخت یا ویرایشِ یک کد.
 *
 * @return array{ok:bool, code?:string, error?:string}
 */
function saveDiscountCode(array $in, int $adminId): array
{
    if (!discountCodesAvailable()) {
        return ['ok' => false, 'error' => 'جدولِ کد تخفیف هنوز ساخته نشده — migration را اجرا کنید.'];
    }

    $code = normalizeDiscountCode((string)($in['code'] ?? ''));
    if (mb_strlen($code) < 3) {
        return ['ok' => false, 'error' => 'کد باید دست‌کم ۳ کاراکتر باشد (حروف انگلیسی و عدد).'];
    }

    $percent = (int)($in['percent'] ?? 100);
    if ($percent < 1 || $percent > 100) {
        return ['ok' => false, 'error' => 'درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.'];
    }

    // ⚠ فهرستِ مدت از `PLAN_GRANT_PERIODS` می‌آید — همان فهرستی که
    //   هدیه‌ی مدیر از آن می‌خواند. فهرستِ دوم نسازید، وگرنه گزینه‌ای
    //   که مدیر می‌بیند هنگام ذخیره بی‌صدا رد می‌شود.
    $months = (int)($in['months'] ?? 1);
    if (!array_key_exists($months, PLAN_GRANT_PERIODS)) {
        return ['ok' => false, 'error' => 'مدت انتخابی معتبر نیست.'];
    }

    $maxUses = max(0, (int)sanitizeAmount((string)($in['max_uses'] ?? '0')));

    // تاریخِ ورودی شمسی است (مثل هر تاریخِ دیگری که کاربر می‌بیند) و
    // اینجا میلادی ذخیره می‌شود — همان قاعده‌ی همیشگیِ اپ.
    $expiresIn = trim((string)($in['expires_at'] ?? ''));
    $expires   = $expiresIn === '' ? null : jalaliStringToGregorian($expiresIn);
    if ($expiresIn !== '' && $expires === null) {
        return ['ok' => false, 'error' => 'تاریخ انقضا معتبر نیست (مثلاً ۱۴۰۴/۱۲/۲۹).'];
    }

    try {
        Database::getConnection()->prepare(
            'INSERT INTO discount_codes (code, percent, months, max_uses, expires_at, note, created_by)
             VALUES (:c, :p, :m, :x, :e, :n, :a)
             ON DUPLICATE KEY UPDATE
                 percent = VALUES(percent), months = VALUES(months),
                 max_uses = VALUES(max_uses), expires_at = VALUES(expires_at),
                 note = VALUES(note), is_active = 1'
        )->execute([
            'c' => $code,
            'p' => $percent,
            'm' => $months,
            'x' => $maxUses,
            'e' => $expires,
            'n' => mb_substr(trim((string)($in['note'] ?? '')), 0, 255) ?: null,
            'a' => $adminId,
        ]);
    } catch (PDOException $e) {
        error_log('saveDiscountCode: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ذخیره‌ی کد انجام نشد.'];
    }

    // ⚠ `used_count` عمداً در `ON DUPLICATE KEY UPDATE` نیست: ویرایشِ یک
    //   کد نباید شمارنده‌اش را صفر کند، وگرنه مدیری که فقط توضیحش را
    //   عوض می‌کند ظرفیت را بی‌سروصدا از نو باز می‌کند.
    return ['ok' => true, 'code' => $code];
}

/** روشن یا خاموش کردنِ یک کد. حذف نمی‌شود — تاریخچه‌ی پرداخت به آن اشاره دارد. */
function setDiscountCodeActive(int $id, bool $on): bool
{
    if (!discountCodesAvailable()) { return false; }
    try {
        $st = Database::getConnection()->prepare(
            'UPDATE discount_codes SET is_active = :a WHERE id = :i'
        );
        $st->execute(['a' => $on ? 1 : 0, 'i' => $id]);
        return $st->rowCount() >= 0;
    } catch (PDOException $e) {
        return false;
    }
}

/** همه‌ی کدها برای پنل مدیر، تازه‌ترین اول. */
function allDiscountCodes(int $limit = 100): array
{
    if (!discountCodesAvailable()) { return []; }
    try {
        $st = Database::getConnection()->prepare(
            'SELECT * FROM discount_codes ORDER BY is_active DESC, created_at DESC LIMIT :n'
        );
        $st->bindValue('n', max(1, min(500, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** آیا اصلاً کدِ قابل استفاده‌ای هست؟ (تا جعبه‌ی بی‌فایده روی صفحه نیاید) */
function hasUsableDiscountCode(): bool
{
    if (!discountCodesAvailable()) { return false; }
    foreach (allDiscountCodes() as $row) {
        if (discountCodeState($row) === 'active') { return true; }
    }
    return false;
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
