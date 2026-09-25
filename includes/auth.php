<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/login_throttle.php';

class Auth
{
    public static function initSession(): void
    {
        Log::stage('auth');
        if (session_status() === PHP_SESSION_NONE) {
            $secure = defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS;

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            ini_set('session.use_strict_mode', '1');

            // مهلت را *اپ* تعیین می‌کند (isLoggedIn روی session_minutes)، نه PHP.
            //
            // پیش‌فرض PHP برای جمع‌آوری فایل نشست ۲۴ دقیقه است، در حالی که
            // کاربر در پروفایل می‌تواند «تا یک هفته وارد بمانم» را انتخاب
            // کند. یعنی آن گزینه عملاً دروغ بود: بعد از ۲۵ دقیقه بی‌کاری،
            // PHP فایل نشست را پاک می‌کرد و کاربر بیرون می‌افتاد.
            //
            // بدتر از بیرون افتادن: توکن CSRF هم داخل همان فایل است. پس
            // فرمی که روی صفحه باز مانده بود، با submit شدن پیام «درخواست
            // نامعتبر است» می‌گرفت — یک صفحه‌ی سفید و بن‌بست. روی گوشی که
            // اپ ساعت‌ها باز می‌ماند این حالت شایع است.
            //
            // عدد برابرِ بزرگ‌ترین گزینه‌ی محدودِ SESSION_WINDOWS است
            // (یک ماه). گزینه‌ی «بدون مهلت» از این هم بلندتر است، ولی
            // نیازی به بالا بردن این عدد ندارد: اگر PHP فایل نشست را جمع
            // کند، همان درخواستِ بعدی از کوکیِ دستگاه دوباره وارد می‌شود
            // و کاربر چیزی نمی‌بیند.
            //
            // این pool مسیر نشست خودش را دارد (`session.save_path` در
            // hesab.conf)، پس این مقدار روی هیچ سرویس دیگری اثر ندارد.
            ini_set('session.gc_maxlifetime', (string)(30 * 86400));

            session_name('DAFTAR_SESSION');
            session_start();
        }

        // تنها جایش `applyAppTimezone()` در db.php است و همان‌جا هم هنگام
        // لود اجرا می‌شود؛ این فراخوانی برای مسیری است که بین لودِ db.php و
        // اینجا کسی منطقه‌ی زمانی را عوض کرده باشد. نسخه‌ی دومِ این تصمیم
        // اینجا نوشته نمی‌شود.
        applyAppTimezone();

        // صفحه‌های این اپ همه شخصی و همه پر از عدد لحظه‌ای‌اند. بدون این
        // هدر، مرورگر (به‌ویژه سافاری روی آیفون) نسخه‌ی کش‌شده را نشان
        // می‌داد: کاربر چیزی را حذف می‌کرد، صفحه تازه می‌شد و باز همان
        // عدد قدیمی بود — انگار اصلاً اعمال نشده. فایل‌های CSS/JS مستقیم از
        // nginx می‌آیند و کش طولانی خودشان را دارند.
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }

        self::captureAppVersion();
    }

    /** کوکیِ «نسخه‌ی اپِ اندرویدِ نصب‌شده روی این دستگاه». */
    public const APP_VERSION_COOKIE = 'daftar_app_vc';

    /**
     * `?appv=10` که اپِ اندروید روی آدرسِ باز شدن می‌گذارد → کوکی.
     *
     * ⛔ **چرا اینجا و نه در `app.js`:** اولین درخواستِ هر بار باز شدنِ
     *    اپ ممکن است به صفحه‌ی ورود ریدایرکت شود و query همان‌جا گم
     *    می‌شود؛ ولی `initSession()` پیش از `requireLogin()` اجرا می‌شود،
     *    پس کوکی روی خودِ پاسخِ ریدایرکت هم می‌نشیند. بدونش کاربری که
     *    نشستش تمام شده بود «نسخه‌ی نامعلوم» می‌شد و نوارِ به‌روزرسانی را
     *    روی اپِ **به‌روز** می‌دید.
     *
     * ⛔ فقط رقم پذیرفته می‌شود و کوکی `httponly` است: مقدار فقط در
     *    `header.php` خوانده می‌شود، و رشته‌ی دلخواهِ آدرس نباید به صفحه
     *    برسد. عدد نه مجوز می‌دهد نه چیزی را باز می‌کند — دروغ گفتنش فقط
     *    نوار را روی همان دستگاه پنهان یا آشکار می‌کند.
     */
    public static function captureAppVersion(): void
    {
        $v = $_GET['appv'] ?? null;
        if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,8}$/', $v) || headers_sent()) { return; }
        setcookie(self::APP_VERSION_COOKIE, $v, [
            'expires'  => time() + 400 * 86400,   // سقفِ کروم؛ هر باز شدنِ اپ تازه‌اش می‌کند
            'path'     => '/',
            'secure'   => defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::APP_VERSION_COOKIE] = $v;
    }

    /** نسخه‌ی اپِ نصب‌شده روی این دستگاه، یا ۰ اگر نامعلوم است. */
    public static function appVersion(): int
    {
        $v = $_COOKIE[self::APP_VERSION_COOKIE] ?? '';
        return is_string($v) && preg_match('/^[1-9][0-9]{0,8}$/', $v) ? (int)$v : 0;
    }

    /**
     * ورود با نام کاربری یا ایمیل.
     *
     * هر دو پذیرفته می‌شوند چون کاربر لازم نیست یادش باشد کدام را ثبت
     * کرده. ایمیل ایندکس یکتا دارد، پس ابهامی پیش نمی‌آید.
     *
     * پیام خطا عمداً برای «کاربر پیدا نشد» و «رمز غلط» یکی است تا این
     * صفحه به ابزار کشف حساب تبدیل نشود.
     */
    /**
     * فقط اعتبارسنجی: سد حدس رمز، پیدا کردن کاربر، بررسی رمز و فعال بودن.
     *
     * عمداً **هیچ کاری با نشست نمی‌کند**. دو مشتری دارد که نیازشان فرق
     * می‌کند: صفحه‌ی ورود وب که بعدش نشست می‌سازد، و API که به‌جای نشست
     * توکن صادر می‌کند. اگر این دو جدا نبودند، منطق سد حدس رمز باید دو
     * بار نوشته می‌شد و همان‌جا از هم دور می‌افتادند.
     *
     * در صورت موفقیت، ردیف کاربر زیر کلید 'user' برمی‌گردد.
     */
    public static function verifyCredentials(string $identifier, string $password, ?string $ip = null): array
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return ['success' => false, 'message' => 'نام کاربری یا ایمیل و رمز عبور را وارد کنید.'];
        }

        $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // ---------- سدِ حدس رمز ----------
        // پیش از هر کاری سنجیده می‌شود: نه فقط چون منطقی است، بلکه چون
        // password_verify عمداً کند است و نباید به مهاجم هدیه شود.
        $lockedFor = LoginThrottle::lockedFor($identifier, $ip);
        if ($lockedFor !== null) {
            // auth.php عمداً functions.php را لازم ندارد، پس تبدیل ارقام
            // مشروط است — بدون این، صفحه‌ی ورود در هر مسیری که فقط auth را
            // لود کرده باشد با «تابع تعریف‌نشده» می‌خوابید.
            $mins = function_exists('toPersianDigits')
                ? toPersianDigits((string)$lockedFor)
                : (string)$lockedFor;
            return [
                'success' => false,
                'locked'  => true,
                'message' => "تلاش‌های ناموفق زیاد بوده است. {$mins} دقیقه دیگر دوباره تلاش کنید.",
            ];
        }

        $pdo = Database::getConnection();

        // از کامل‌ترین کوئری شروع می‌شود و اگر ستونی هنوز با migration
        // اضافه نشده باشد، به نسخه‌ی ساده‌تر می‌افتد.
        // ⚠ مهلتِ نشست عمداً اینجا خوانده نمی‌شود.
        //   `sessionMinutesFor()` جدا و با محافظِ خودش می‌خواندش، تا
        //   افزودنِ هر ستونِ تازه، این زنجیره‌ی جایگزین را شکننده‌تر نکند.
        // ⛔ شماره‌ی موبایل هم یک شناسه‌ی ورود است، دقیقاً مثل ایمیل.
        //    خواسته‌ی صریح این بود که «شماره مثل نام کاربری عمل کند»؛ و
        //    حسابی که با شماره ساخته می‌شود نامِ کاربری‌اش هم همان شماره
        //    است، ولی کاربر می‌تواند بعداً نامش را عوض کند — از آن لحظه
        //    بدونِ این شرط، شماره‌اش دیگر او را وارد نمی‌کرد.
        // ⚠ شماره پیش از مقایسه نرمال می‌شود، وگرنه `+98912…` و
        //   `0912…` دو چیزِ متفاوت بودند و خرابی **بی‌صدا**: پیام همان
        //   «نام کاربری یا رمز عبور اشتباه است» می‌ماند.
        // ⚠ لود کردنش اینجاست نه بالای فایل: `sms_login.php` خودش
        //   `functions.php` را می‌خواهد و `auth.php` عمداً به آن وابسته
        //   نیست (چند مسیر فقط همین یکی را لود می‌کنند).
        require_once __DIR__ . '/sms_login.php';
        $phoneId = SmsLogin::normalizePhone($identifier);

        $user = null;
        $queries = [
            'SELECT id, full_name, username, password_hash, role, is_active
             FROM users
             WHERE username = :id OR (email IS NOT NULL AND email = :id2)
                OR (phone IS NOT NULL AND phone = :id3) LIMIT 1',
            'SELECT id, full_name, username, password_hash, role, is_active
             FROM users WHERE username = :id OR (email IS NOT NULL AND email = :id2) LIMIT 1',
            'SELECT id, full_name, username, password_hash, role, is_active
             FROM users WHERE username = :id LIMIT 1',
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $params = ['id' => $identifier];
                if (str_contains($sql, ':id2')) { $params['id2'] = $identifier; }
                // ⚠ اگر شماره نرمال نشد، مقدارِ خودِ شناسه می‌رود — با یک
                //   شناسه‌ی غیرِ شماره هیچ ردیفی نمی‌خورد، و `null` دادن
                //   به‌جایش شرط را همیشه‌نادرست می‌کرد (فرقی نمی‌کند ولی
                //   خواندنش گمراه‌کننده است).
                if (str_contains($sql, ':id3')) { $params['id3'] = $phoneId ?? $identifier; }
                $stmt->execute($params);
                $user = $stmt->fetch();
                break;
            } catch (PDOException $e) {
                continue;   // ستون email یا phone هنوز نیست
            }
        }

        // ⛔ حسابِ **بی‌رمز** (ثبت‌نام با شماره) هرگز به `password_verify()`
        //    نمی‌رسد.
        //
        //    ⚠ و دقیق بگویم چرا، چون اولین توضیحی که نوشتم **غلط** بود و
        //      آزمونِ جهش نشانش داد: `password_verify($p, null)` روی
        //      PHP 8.4 خطای کشنده **نمی‌دهد**؛ `null` را به رشته‌ی خالی
        //      تبدیل می‌کند، `false` برمی‌گرداند، و فقط یک
        //      `Deprecated: Passing null to parameter #2` می‌نویسد. پس
        //      این نگهبان امروز رفتار را عوض نمی‌کند و جهشش زنده می‌ماند
        //      مگر با سنجشِ خودِ آن هشدار.
        //
        //    دو دلیل که با این حال می‌ماند: (۱) آن هشدار در **هر** تلاشِ
        //    ورود روی چنین حسابی در لاگ می‌نشیند، و هشدارِ همیشگی همان
        //    چیزی است که آدم را عادت می‌دهد هشدارها را نادیده بگیرد؛
        //    (۲) در PHP 9 همین تبدیل به `TypeError` می‌شود، یعنی صفحه‌ی
        //    ورود واقعاً ۵۰۰ می‌دهد. `test_phone_signup` هر دو نیمه را
        //    می‌سنجد: رفتار، و نبودِ آن هشدار.
        //
        //    ⚠ پیامش عمداً همان پیامِ همیشگی است — «این حساب رمز ندارد»
        //      به مهاجم می‌گفت کدام حساب‌ها را با پیامک می‌شود گرفت.
        if ($user && ($user['password_hash'] === null || $user['password_hash'] === '')) {
            LoginThrottle::recordFailure($identifier, $ip);
            Audit::log('auth.login_failed', 'user', (int)$user['id'], ['why' => 'no_password'], null, (int)$user['id']);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // نامِ ناموجود هم شمرده می‌شود، وگرنه امتحان کردن نام‌های
            // تصادفی هیچ هزینه‌ای ندارد. پیام هم عمداً همان پیام قبلی
            // می‌ماند تا وجود یا نبودِ حساب لو نرود.
            LoginThrottle::recordFailure($identifier, $ip);
            // ⛔ خودِ شناسه‌ی تایپ‌شده ثبت نمی‌شود (می‌تواند ایمیل باشد،
            //    یا حدسِ مهاجم)؛ فقط اینکه شکست خورد، از کدام آی‌پی، و
            //    اگر حسابی خورد، شناسه‌ی همان حساب. برای «رمزم را
            //    چند بار غلط زدند؟» همین کافی است.
            Audit::log('auth.login_failed', 'user', $user ? (int)$user['id'] : null,
                ['why' => $user ? 'bad_password' : 'unknown_user'], null, $user ? (int)$user['id'] : null);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ((int)$user['is_active'] !== 1) {
            Audit::log('auth.login_failed', 'user', (int)$user['id'], ['why' => 'inactive'], null, (int)$user['id']);
            return ['success' => false, 'message' => 'حساب کاربری شما غیرفعال شده است.'];
        }

        // رمز درست بود، پس سابقه‌ی همین نام کاربری پاک می‌شود: کسی که چند
        // بار اشتباه زده ولی بالاخره وارد شده، دفعه‌ی بعد از صفر شروع کند.
        LoginThrottle::clear($identifier);
        LoginThrottle::prune();

        return ['success' => true, 'message' => 'ورود موفقیت‌آمیز بود.', 'user' => $user];
    }

    /**
     * ورود وب: اعتبارسنجی مشترک، و بعد ساختن نشست.
     *
     * خروجی‌اش عمداً همان شکل قبلی است (بدون کلید user) تا صفحه‌های
     * موجود دست‌نخورده بمانند.
     */
    public static function attemptLogin(string $identifier, string $password, ?string $ip = null): array
    {
        $result = self::verifyCredentials($identifier, $password, $ip);
        if (!($result['success'] ?? false)) {
            return $result;
        }

        self::establishSession($result['user']);
        return ['success' => true, 'message' => 'ورود موفقیت‌آمیز بود.'];
    }

    /**
     * ساختنِ نشست برای یک کاربرِ **از قبل احراز شده**.
     *
     * ⛔ این تابع هیچ چیزی را احراز نمی‌کند و نباید بکند — کارش فقط
     *    نوشتنِ نشست است. مسئولیتِ اینکه این کاربر واقعاً حقِ ورود دارد
     *    مالِ فراخواننده است (`attemptLogin` با رمز، `SmsLogin` با کد).
     *
     * ⛔ چرا جدا شد: با آمدنِ ورودِ پیامکی باید بار دوم نوشته می‌شد، و
     *    دو نسخه از «ورود» دیر یا زود از هم دور می‌افتند — همان دلیلی که
     *    `verifyCredentials()` از `attemptLogin()` جدا شد. کلیدِ فراموش‌شده
     *    در یکی از دو نسخه (مثلاً `session_minutes`) یعنی مهلتِ نشست برای
     *    آن مسیر بی‌صدا به پیش‌فرض برمی‌گردد.
     */
    public static function establishSession(array $user, string $via = 'password'): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id']         = (int)$user['id'];
        $_SESSION['full_name']       = $user['full_name'];
        $_SESSION['username']        = $user['username'];
        $_SESSION['role']            = $user['role'];
        $_SESSION['session_minutes'] = self::sessionMinutesFor((int)$user['id']);
        $_SESSION['login_time']      = time();
        $_SESSION['last_seen']       = time();

        // ⛔ ورودِ موفق در دفترِ ممیزی — با روشش (رمز / پیامک). ورودِ
        //    خودکار از کوکیِ دستگاهِ مورد اعتماد **عمداً** اینجا نیست:
        //    آن یک اعتبارنامه‌ی تازه نیست، ادامه‌ی همان ورودِ اول است، و
        //    با مهلتِ کوتاهِ نشست هر چند دقیقه یک ردیف می‌ساخت.
        Audit::log('auth.login', 'user', (int)$user['id'], ['via' => $via]);
    }

    /**
     * نشستِ جاری را «تازه‌تر از مهرِ ابطال» می‌کند.
     *
     * برای وقتی که خودِ کاربر `revokeAllAccessFor()` را راه می‌اندازد (تغییر
     * رمز در پروفایل): مهرِ `access_revoked_at` همین حالا نوشته شده و
     * `login_time`ِ این نشست قدیمی‌تر از آن است، پس بدونِ این خط، همان
     * کاربری که کارِ درست را کرده تا یک دقیقه‌ی بعد بیرون می‌افتاد.
     *
     * ⚠ ساعتِ PHP و MySQL روی همین ماشین یکی است و مهر **پیش از** این
     *   خط نوشته می‌شود، پس `login_time` هرگز کوچک‌تر از مهر نمی‌شود.
     */
    public static function renewCurrentSession(): void
    {
        if (empty($_SESSION['user_id'])) { return; }
        $_SESSION['login_time'] = time();
        $_SESSION['last_seen']  = time();
        // ⛔ و اعتمادِ **همین** دستگاه دوباره ساخته می‌شود. `revokeAllAccessFor()`
        //    ردیفِ این دستگاه را هم پاک کرده بود؛ بدونِ این خط نشستِ جاری
        //    می‌ماند ولی کوکیِ دستگاه مرده بود، پس با اولین بستنِ اپ (کوکیِ
        //    نشست `lifetime = 0` است) کاربر بیرون می‌افتاد — درست همان کسی
        //    که کارِ درست را کرده و رمز گذاشته. بی‌هیچ خطایی، و روزها بعد.
        self::trustThisDevice((int)$_SESSION['user_id']);
    }

    /* ============================================================
       مهلت ماندن در حساب
       ============================================================ */

    /**
     * گزینه‌های مهلت، به دقیقه. کلید ۰ یعنی «بدون مهلت».
     *
     * این فهرست **تنها مرجع** است: پروفایل، اندپوینت ذخیره و
     * اعتبارسنجی همه از همین می‌خوانند. اگر گزینه‌ای اینجا نباشد،
     * ذخیره هم نمی‌شود.
     */
    public const SESSION_WINDOWS = [
        0     => 'بدون مهلت — تا وقتی خودم خارج نشوم',
        1     => 'یک دقیقه',
        5     => 'پنج دقیقه',
        15    => 'پانزده دقیقه',
        30    => 'نیم ساعت',
        60    => 'یک ساعت',
        480   => 'هشت ساعت',
        1440  => 'یک روز',
        10080 => 'یک هفته',
        43200 => 'یک ماه',
    ];

    /** مهلتِ کوکیِ «بدون مهلت». ده سال، یعنی عملاً هرگز. */
    private const FOREVER_SECONDS = 10 * 365 * 86400;

    public static function isValidSessionWindow(int $minutes): bool
    {
        return array_key_exists($minutes, self::SESSION_WINDOWS);
    }

    /**
     * مهلتِ انتخابیِ کاربر، به دقیقه. ۰ یعنی بدون مهلت.
     *
     * عمداً از کوئریِ ورود جدا است: آن کوئری چند نسخه‌ی جایگزین دارد
     * تا روی نصبی که هنوز migration نخورده هم کار کند، و اضافه کردن
     * ستون تازه به هر سه نسخه، همان منطق شکننده را شکننده‌تر می‌کرد.
     */
    public static function sessionMinutesFor(int $userId): int
    {
        try {
            $stmt = Database::getConnection()->prepare(
                'SELECT session_minutes FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $val = $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;   // ستون هنوز نیست → بدون مهلت، مثل پیش‌فرض
        }
        if ($val === false || $val === null) { return 0; }
        $minutes = (int)$val;
        return self::isValidSessionWindow($minutes) ? $minutes : 0;
    }

    /** مهلتِ کاربرِ جاری، از نشست. */
    public static function sessionMinutes(): int
    {
        $minutes = (int)($_SESSION['session_minutes'] ?? 0);
        return self::isValidSessionWindow($minutes) ? $minutes : 0;
    }

    /** مهلتِ کوکیِ دستگاه، به ثانیه — بر اساس انتخاب کاربر. */
    private static function trustSeconds(int $minutes): int
    {
        return $minutes === 0 ? self::FOREVER_SECONDS : $minutes * 60;
    }

    public static function isLoggedIn(): bool
    {
        // اگر نشست نیست، شاید این دستگاه «مورد اعتماد» باشد
        if (empty($_SESSION['user_id'])) {
            return self::loginFromTrustedDevice();
        }

        $minutes = self::sessionMinutes();

        // ۰ = بدون مهلت. نشست هرگز با بی‌فعالیتی منقضی نمی‌شود، ولی
        // کوکیِ دستگاه باید همچنان کشویی بماند: کاربری که نشستش زنده
        // می‌ماند هرگز از loginFromTrustedDevice رد نمی‌شود، و بدون این
        // خط کوکی‌اش بی‌سروصدا به سررسیدِ اولش می‌رسید.
        if ($minutes === 0) {
            if (!self::touchSession()) { return false; }
            self::slideTrustedCookie();
            return true;
        }

        // مهلت بر اساس آخرین فعالیت است، نه زمان ورود —
        // یعنی تا وقتی کار می‌کنی بیرونت نمی‌اندازد.
        $lifetime = $minutes * 60;

        $lastSeen = (int)($_SESSION['last_seen'] ?? $_SESSION['login_time'] ?? time());
        if ((time() - $lastSeen) > $lifetime) {
            // ⚠ اینجا عمداً logout() نیست.
            //
            // `logout()` برای وقتی است که کاربر *خودش* دکمه‌ی خروج را
            // می‌زند، پس اعتماد این دستگاه را هم باطل می‌کند — که درست
            // است. ولی اینجا فقط نشست منقضی شده، نه اینکه کاربر خواسته
            // باشد برود.
            //
            // با logout()، هر بار که مهلت یک‌ساعته تمام می‌شد، ردیف
            // trusted_devices پاک می‌شد و loginFromTrustedDevice چیزی
            // برای کار کردن نداشت. یعنی «این دستگاه را ۳۰ روز به خاطر
            // بسپار» دقیقاً با همان چیزی نابود می‌شد که قرار بود از
            // رویش پل بزند — کاربر با وجود روشن بودنِ آن گزینه، هر یک
            // ساعت دوباره رمز می‌خواست.
            self::expireSession();
            return self::loginFromTrustedDevice();
        }

        if (!self::touchSession()) { return false; }

        self::slideTrustedCookie();

        return true;
    }

    /**
     * حداکثر یک بار در دقیقه، ⛔ حسابِ کاربر را از دیتابیس دوباره می‌سنجد.
     *
     * چرا: نشست فقط یک فایل روی دیسک است و **هیچ چیزی در دیتابیس به آن
     * بند نیست**. پس تا پیش از این، غیرفعال کردنِ کاربر در پنلِ مدیر
     * یا برداشتنِ نقشِ مدیر از او هیچ اثری روی مرورگری که همان لحظه
     * وارد بود نداشت: تا وقتی خودش خارج نمی‌شد — و با «بدون مهلت» یعنی
     * هرگز — همه چیز کار می‌کرد. پیامِ «کاربر غیرفعال شد» دروغ بود.
     *
     * همان تیکِ «تمدیدِ last_seen هر یک دقیقه» که از قبل بود، حالا یک
     * کوئریِ سبک هم می‌زند. یعنی بدترین حالت، شصت ثانیه فاصله بینِ
     * تصمیمِ مدیر و بیرون افتادنِ کاربر است، نه یک کوئری در هر درخواست.
     *
     * سه چیز سنجیده می‌شود:
     *   ۱. ردیف هست و `is_active = 1`، وگرنه نشست خالی می‌شود.
     *   ۲. `access_revoked_at` — اگر بعد از `login_time`ِ این نشست باشد،
     *      نشست خالی می‌شود («خروج از همه‌ی دستگاه‌ها»، تغییر رمز).
     *   ۳. `role` — از دیتابیس در نشست تازه می‌شود، پس مدیری که نقشش
     *      گرفته شده تا یک دقیقه‌ی بعد دیگر `isAdmin()` نیست.
     *
     * ⚠ به `loginFromTrustedDevice()` نمی‌رود: آن مسیر خودش `is_active`
     *   را می‌سنجد و ردیفِ دستگاه را هم `revokeAllAccessFor()` پاک کرده.
     *
     * ⚠ خرابیِ دیتابیس کاربر را بیرون نمی‌اندازد (مثل `LoginThrottle`):
     *   صفحه به هر حال بدونِ دیتابیس کار نمی‌کند، و بیرون انداختنِ همه
     *   سرِ یک قطعیِ گذرا فقط خرابی را بزرگ‌تر می‌کند.
     */
    private const RECHECK_SECONDS = 60;

    private static function touchSession(): bool
    {
        if (time() - (int)($_SESSION['last_seen'] ?? 0) <= self::RECHECK_SECONDS) {
            return true;
        }

        if (!self::refreshAccountState((int)$_SESSION['user_id'])) {
            self::expireSession();
            return false;
        }

        $_SESSION['last_seen'] = time();
        return true;
    }

    /**
     * وضعیتِ حساب از دیتابیس. false = این نشست دیگر حق ندارد زنده بماند.
     *
     * ستونِ `access_revoked_at` با `migration_access_revoke` می‌آید؛ روی
     * نصبی که هنوز آن را ندارد، همان دو سنجشِ دیگر انجام می‌شود و چیزی
     * نمی‌شکند (کوئریِ دوم بدون آن ستون است).
     */
    private static function refreshAccountState(int $userId): bool
    {
        $login = (int)($_SESSION['login_time'] ?? 0);

        try {
            $pdo = Database::getConnection();
            try {
                // ⛔ مقایسه‌ی زمان در **دیتابیس**، نه در PHP — همان درسِ
                //    لینکِ بازیابیِ رمز: ساعتِ دو طرف یکی نیست.
                $st = $pdo->prepare(
                    'SELECT is_active, role,
                            (access_revoked_at IS NOT NULL
                             AND access_revoked_at > FROM_UNIXTIME(:login)) AS revoked
                     FROM users WHERE id = :id LIMIT 1'
                );
                $st->execute(['login' => $login, 'id' => $userId]);
                $row = $st->fetch();
            } catch (PDOException $e) {
                // فقط «ستون ناشناخته» (42S22) یعنی migration نیامده. هر
                // خطای دیگری به catchِ بیرونی می‌رود؛ وگرنه یک قطعیِ گذرا
                // بی‌صدا سنجشِ ابطال را خاموش می‌کرد.
                if ((string)$e->getCode() !== '42S22') { throw $e; }
                $st = $pdo->prepare('SELECT is_active, role, 0 AS revoked FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id' => $userId]);
                $row = $st->fetch();
            }
        } catch (PDOException $e) {
            return true;    // خرابیِ دیتابیس نباید همه را بیرون بیندازد
        }

        if (!$row || (int)$row['is_active'] !== 1 || (int)$row['revoked'] === 1) {
            return false;
        }

        $_SESSION['role'] = $row['role'];
        return true;
    }

    /* ============================================================
       دستگاه مورد اعتماد
       ============================================================ */

    private const TRUSTED_COOKIE = 'daftar_device';

    /**
     * کوکی و ردیفِ دستگاه را از «الان» دوباره تا مهلتِ کامل جلو می‌برد.
     *
     * ⛔ **هر سه تکه باید با هم جلو بروند، وگرنه بی‌سروصدا می‌شکند:**
     *   ۱. `expires_at` در دیتابیس  (وگرنه سرور ردش می‌کند)
     *   ۲. `expires` روی کوکی        (وگرنه مرورگر خودش دورش می‌اندازد)
     *   ۳. `last_used_at`            (فقط برای نمایش در فهرست دستگاه‌ها)
     *
     * نسخه‌ی قبلی فقط تکه‌ی سوم را به‌روز می‌کرد، پس مهلت **ثابت** بود:
     * کاربری که هر روز اپ را باز می‌کرد هم دقیقاً ۳۰ روز بعد از ورود
     * دوباره رمز می‌خواست. هیچ خطایی هم دیده نمی‌شد.
     *
     * @return bool آیا واقعاً تمدید شد
     */
    private static function slideTrustedDevice(int $deviceId, int $minutes): bool
    {
        $seconds = self::trustSeconds($minutes);
        $expires = date('Y-m-d H:i:s', time() + $seconds);

        try {
            $upd = Database::getConnection()->prepare(
                'UPDATE trusted_devices SET expires_at = :e, last_used_at = NOW() WHERE id = :id'
            );
            $upd->execute(['e' => $expires, 'id' => $deviceId]);
        } catch (PDOException $e) {
            return false;
        }

        // کوکی با همان مقدار، فقط با سررسیدِ تازه
        if (!empty($_COOKIE[self::TRUSTED_COOKIE]) && !headers_sent()) {
            self::putTrustedCookie((string)$_COOKIE[self::TRUSTED_COOKIE], $seconds);
        }
        return true;
    }

    /**
     * تمدید کوکی برای نشستِ زنده — حداکثر روزی یک بار.
     *
     * بدون سقف، هر بارگذاری صفحه یک UPDATE می‌زد. با سقف، بدترین حالت
     * یک کوئری در روز است و مهلت هم عملاً همیشه تازه می‌ماند.
     */
    private static function slideTrustedCookie(): void
    {
        if (empty($_COOKIE[self::TRUSTED_COOKIE])) { return; }
        if (empty($_SESSION['trusted_device_id']))  { return; }

        $last = (int)($_SESSION['trust_slid_at'] ?? 0);
        if (time() - $last < 86400) { return; }

        if (self::slideTrustedDevice((int)$_SESSION['trusted_device_id'], self::sessionMinutes())) {
            $_SESSION['trust_slid_at'] = time();
        }
    }

    /**
     * بعد از عوض شدنِ مهلت، کوکیِ همین دستگاه را فوراً هم‌تراز می‌کند.
     *
     * بدون این، کاربر «یک ماه» را انتخاب می‌کرد ولی کوکی با سررسیدِ
     * قبلی می‌ماند و تغییر تا ورودِ بعدی هیچ اثری نداشت — یعنی تنظیمی
     * که کار نمی‌کند، بدترین حالتِ ممکن.
     */
    public static function refreshTrustForCurrentDevice(): void
    {
        if (empty($_SESSION['trusted_device_id'])) { return; }
        if (self::slideTrustedDevice((int)$_SESSION['trusted_device_id'], self::sessionMinutes())) {
            $_SESSION['trust_slid_at'] = time();
        }
    }

    /** نوشتن کوکی دستگاه با سررسید مشخص — تنها جای ساختِ این کوکی. */
    private static function putTrustedCookie(string $value, int $seconds): void
    {
        $secure = defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS;
        setcookie(self::TRUSTED_COOKIE, $value, [
            'expires'  => time() + $seconds,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * برای این دستگاه یک توکن بلندمدت صادر می‌کند.
     * روش selector/validator: بخش اول برای پیدا کردن رکورد،
     * بخش دوم فقط به‌صورت هش ذخیره می‌شود.
     */
    public static function trustThisDevice(int $userId): void
    {
        try {
            $selector  = bin2hex(random_bytes(12));   // ۲۴ کاراکتر
            $validator = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            return; // اگر تصادف امن ممکن نبود، بی‌خیال می‌شویم
        }

        $hash = hash('sha256', $validator);

        // مهلت از انتخابِ خودِ کاربر می‌آید، نه از یک عددِ ثابت.
        $seconds = self::trustSeconds(self::sessionMinutesFor($userId));
        $expires = date('Y-m-d H:i:s', time() + $seconds);

        $label = self::deviceLabel();

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('
                INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at, last_used_at)
                VALUES (:u, :s, :h, :l, :e, NOW())
            ');
            $stmt->execute(['u' => $userId, 's' => $selector, 'h' => $hash, 'l' => $label, 'e' => $expires]);
            $_SESSION['trusted_device_id'] = (int)$pdo->lastInsertId();
            $_SESSION['trust_slid_at']     = time();
        } catch (PDOException $e) {
            return; // جدول هنوز ساخته نشده
        }

        self::putTrustedCookie($selector . ':' . $validator, $seconds);
    }

    /**
     * اگر کوکی دستگاه معتبر بود، کاربر را وارد می‌کند.
     */
    private static function loginFromTrustedDevice(): bool
    {
        if (empty($_COOKIE[self::TRUSTED_COOKIE])) {
            return false;
        }

        $parts = explode(':', (string)$_COOKIE[self::TRUSTED_COOKIE], 2);
        if (count($parts) !== 2) {
            self::clearTrustedCookie();
            return false;
        }
        [$selector, $validator] = $parts;

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('
                SELECT td.id, td.user_id, td.token_hash, td.expires_at,
                       u.full_name, u.username, u.role, u.is_active
                FROM trusted_devices td
                JOIN users u ON u.id = td.user_id
                WHERE td.selector = :s LIMIT 1
            ');
            $stmt->execute(['s' => $selector]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }

        if (!$row) {
            self::clearTrustedCookie();
            return false;
        }

        // مقایسه‌ی زمان‌ثابت تا حدس‌زدن توکن ممکن نباشد
        if (!hash_equals($row['token_hash'], hash('sha256', $validator))) {
            // احتمال سوءاستفاده: همه‌ی دستگاه‌های این کاربر باطل شوند
            self::revokeAllDevices((int)$row['user_id']);
            self::clearTrustedCookie();
            return false;
        }

        if (strtotime($row['expires_at']) < time() || (int)$row['is_active'] !== 1) {
            self::revokeDevice((int)$row['id'], (int)$row['user_id']);
            self::clearTrustedCookie();
            return false;
        }

        $userId  = (int)$row['user_id'];
        $minutes = self::sessionMinutesFor($userId);

        session_regenerate_id(true);
        $_SESSION['user_id']           = $userId;
        $_SESSION['full_name']         = $row['full_name'];
        $_SESSION['username']          = $row['username'];
        $_SESSION['role']              = $row['role'];
        $_SESSION['session_minutes']   = $minutes;
        $_SESSION['login_time']        = time();
        $_SESSION['last_seen']         = time();
        $_SESSION['trusted_device_id'] = (int)$row['id'];

        // ⛔ اینجا مهلت **کشویی** می‌شود: سررسید از همین لحظه دوباره
        //    کامل می‌شود، هم در دیتابیس هم روی کوکی. پیش از این فقط
        //    last_used_at به‌روز می‌شد و سررسید دست‌نخورده می‌ماند.
        self::slideTrustedDevice((int)$row['id'], $minutes);
        $_SESSION['trust_slid_at'] = time();

        return true;
    }

    public static function revokeDevice(int $deviceId, int $userId): void
    {
        try {
            $stmt = Database::getConnection()->prepare('DELETE FROM trusted_devices WHERE id = :id AND user_id = :u');
            $stmt->execute(['id' => $deviceId, 'u' => $userId]);
        } catch (PDOException $e) { /* ignore */ }
    }

    public static function revokeAllDevices(int $userId): void
    {
        try {
            $stmt = Database::getConnection()->prepare('DELETE FROM trusted_devices WHERE user_id = :u');
            $stmt->execute(['u' => $userId]);
        } catch (PDOException $e) { /* ignore */ }
    }

    private static function clearTrustedCookie(): void
    {
        setcookie(self::TRUSTED_COOKIE, '', [
            'expires' => time() - 3600,
            'path'    => '/',
            'domain'  => '',
        ]);
    }

    /**
     * برچسب ساده‌ی دستگاه برای اینکه کاربر بفهمد کدام دستگاه است.
     */
    private static function deviceLabel(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $os = 'دستگاه ناشناس';
        if (stripos($ua, 'iPhone') !== false)      { $os = 'آیفون'; }
        elseif (stripos($ua, 'iPad') !== false)    { $os = 'آی‌پد'; }
        elseif (stripos($ua, 'Android') !== false) { $os = 'اندروید'; }
        elseif (stripos($ua, 'Windows') !== false) { $os = 'ویندوز'; }
        elseif (stripos($ua, 'Mac') !== false)     { $os = 'مک'; }
        elseif (stripos($ua, 'Linux') !== false)   { $os = 'لینوکس'; }

        $browser = '';
        if (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome') !== false) { $browser = 'کروم'; }
        elseif (stripos($ua, 'Firefox') !== false) { $browser = 'فایرفاکس'; }
        elseif (stripos($ua, 'Safari') !== false)  { $browser = 'سافاری'; }

        return trim($os . ($browser !== '' ? ' — ' . $browser : ''));
    }

    public static function rememberUsername(string $username): void
    {
        $secure = defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS;
        setcookie('daftar_remember_user', $username, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function getRememberedUsername(): ?string
    {
        return (isset($_COOKIE['daftar_remember_user']) && $_COOKIE['daftar_remember_user'] !== '')
            ? $_COOKIE['daftar_remember_user']
            : null;
    }

    public static function forgetUsername(): void
    {
        setcookie('daftar_remember_user', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
        ]);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            // ⛔ مسیر باید مطلق باشد. `Location: login.php` نسبی است و
            // مرورگر نسبت به **آدرسِ همان درخواست** حلش می‌کند — پس روی
            // `/admin/users.php` می‌شد `/admin/login.php` که وجود ندارد.
            // آن‌وقت `try_files $uri =404` در قاعده‌ی php سایت، درخواست
            // را اصلاً به PHP نمی‌رساند و کاربر صفحه‌ی ۴۰۴ خودِ nginx را
            // می‌دید: بی‌قالب، بی‌منو، و **بدون هیچ ردی در لاگ PHP**.
            // بدترین حالتش این بود: مدیر تنظیماتی را ذخیره می‌کرد،
            // نشستش همان لحظه منقضی شده بود، و به‌جای صفحه‌ی ورود یک
            // ۴۰۴ خام می‌گرفت — بی‌آنکه بفهمد ذخیره شد یا نه.
            $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
            header('Location: ' . $base . '/login.php');
            exit;
        }
    }

    /**
     * ⛔ تنها مرجعِ نقش‌ها — نه یک فهرستِ دوم جایی دیگر.
     *
     * **خواسته‌ی مالکِ نصب:** «امکان اضافه کردن ادمین با دسترسی محدود
     * مثلاً پشتیبانی» و «یک گزینه همکار هم می‌خوام که همین ۴ نفری که
     * حسابداری به حسابشون وصل شده در اون دسته قرار می‌گیرن».
     *
     * - `admin`     — همه‌ی پنلِ مدیریت.
     * - `support`   — **فقط** بخشِ پشتیبانی. هیچ چیزِ دیگری.
     * - `colleague` — یک **برچسب**، نه یک دسترسی: دقیقاً مثل `user`
     *                 رفتار می‌کند. پایین‌تر توضیح داده شده که چرا.
     * - `user`      — کاربر عادی.
     */
    public const ROLES = [
        'admin'     => 'مدیر',
        'support'   => 'پشتیبان',
        'colleague' => 'همکار',
        'user'      => 'کاربر',
    ];

    /**
     * ⛔ فهرستِ بسته‌ی توانایی‌ها. هر `can()`/`requireCap()` باید از
     *    همین‌جا رد شود (قاعده ۵۱ می‌سنجدش) — وگرنه یک غلطِ تایپی در
     *    نامِ توانایی **بی‌صدا** به `false` می‌رسید و صفحه برای همه بسته
     *    می‌شد بی‌آنکه کسی بفهمد چرا.
     */
    public const CAPS = ['admin', 'support'];

    /**
     * ⛔ `isAdmin()` عمداً **فقط** `admin` است و نباید باز شود.
     *
     * وسوسه‌ی طبیعی این بود که `support` را هم اینجا `true` کنیم تا
     * صفحه‌های پشتیبانی باز شوند. آن **بدترین کارِ ممکن** بود: این تابع
     * در ۲۱ جای دیگر هم خوانده می‌شود — کاربران، اشتراک، دسته‌بندی،
     * آمار، سهامداران، شاخه‌ی «پیوستِ هر کاربری را ببین» در
     * `api/view_support_file.php`، و جزئیاتِ `health.php`. یعنی یک
     * «پشتیبان» بی‌سروصدا به **همه چیز** می‌رسید و هیچ خطایی هم
     * نمی‌داد. دسترسیِ محدود از راهِ `can()` می‌آید، نه از باز کردنِ این.
     */
    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
    }

    /**
     * ⛔ تنها جای تصمیمِ «این نقش این کار را می‌تواند؟».
     *
     * ⚠ پیش‌فرضِ ناشناخته **بسته** است: توانایی‌ای که در `CAPS` نباشد
     *   `false` می‌گیرد. اگر پیش‌فرض باز بود، یک نامِ تازه‌ی
     *   اشتباه‌تایپ‌شده صفحه را برای همه باز می‌کرد.
     */
    public static function can(string $cap): bool
    {
        if (!self::isLoggedIn() || !in_array($cap, self::CAPS, true)) { return false; }
        $role = (string)($_SESSION['role'] ?? '');
        if ($role === 'admin') { return true; }          // مدیر همه‌ی توانایی‌ها را دارد
        return $cap === 'support' && $role === 'support';
    }

    /**
     * ⛔ «آیا این کاربر اصلاً بخشِ مدیریت را می‌بیند؟» — تنها جای این
     *    تصمیم، و عمداً از روی `CAPS` حلقه می‌زند نه با یک `||` دستی.
     *
     * نوارِ کناری و شیتِ ابزارها هر دو یک **سرتیتر** («مدیریت») دارند که
     * باید وقتی رندر شود که دست‌کم یک قلمِ زیرش دیده شود. با شرطِ دستیِ
     * `isAdmin() || can('support')` در دو فایل، تواناییِ سومِ فردا در
     * یکی اضافه می‌شد و در آن یکی نه — و خرابی‌اش **بی‌صداست**: کاربر
     * روی گوشی چیزی را می‌بیند که روی دسکتاپ نیست (همان درسِ
     * «سررسیدها» که داخلِ بلوکِ `isAdmin()` افتاده بودند).
     *
     * ⚠ سرتیترِ خالی هم همان‌قدر بد است: «مدیریت» بدونِ هیچ قلمی، همان
     *   «دکمه‌ی بی‌کار» است.
     */
    public static function hasAnyCap(): bool
    {
        foreach (self::CAPS as $cap) {
            if (self::can($cap)) { return true; }
        }
        return false;
    }

    public static function requireAdmin(): void
    {
        self::requireCap('admin');
    }

    public static function requireCap(string $cap): void
    {
        self::requireLogin();
        if (!self::can($cap)) {
            http_response_code(403);
            die('شما دسترسی لازم برای مشاهده این صفحه را ندارید.');
        }
    }

    /** برچسبِ فارسیِ یک نقش — نقشِ ناشناخته «کاربر» خوانده می‌شود. */
    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role] ?? self::ROLES['user'];
    }

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function fullName(): string
    {
        return $_SESSION['full_name'] ?? '';
    }

    public static function role(): string
    {
        return $_SESSION['role'] ?? '';
    }

    public static function hasAnyUser(): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM users');
        $row = $stmt->fetch();
        return (int)$row['cnt'] > 0;
    }

    /**
     * نشست را خالی می‌کند ولی «اعتماد این دستگاه» را نگه می‌دارد.
     *
     * برای انقضای مهلت است، نه خروجِ خواسته‌ی کاربر. نشست نابود نمی‌شود
     * بلکه خالی می‌شود و شناسه‌اش عوض می‌شود — چون بلافاصله بعدش
     * loginFromTrustedDevice می‌خواهد در همان نشست بنویسد، و روی نشستِ
     * destroy شده نمی‌شود نوشت.
     */
    private static function expireSession(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function logout(): void
    {
        // پیش از خالی شدنِ نشست، تا شناسه‌ی کاربر هنوز معلوم باشد.
        if (!empty($_SESSION['user_id'])) {
            Audit::log('auth.logout', 'user', (int)$_SESSION['user_id']);
        }
        // اگر این دستگاه مورد اعتماد بود، اعتمادش هم باطل شود.
        // این فقط برای خروجِ خواسته‌ی کاربر درست است — انقضای مهلت از
        // expireSession() رد می‌شود که به اعتماد دست نمی‌زند.
        if (!empty($_COOKIE[self::TRUSTED_COOKIE])) {
            $parts = explode(':', (string)$_COOKIE[self::TRUSTED_COOKIE], 2);
            if (count($parts) === 2) {
                try {
                    $stmt = Database::getConnection()->prepare('DELETE FROM trusted_devices WHERE selector = :s');
                    $stmt->execute(['s' => $parts[0]]);
                } catch (PDOException $e) { /* ignore */ }
            }
            self::clearTrustedCookie();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }
}