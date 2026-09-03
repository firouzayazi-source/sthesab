<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/login_throttle.php';

class Auth
{
    public static function initSession(): void
    {
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

        if (defined('APP_TIMEZONE')) {
            date_default_timezone_set(APP_TIMEZONE);
        }

        // صفحه‌های این اپ همه شخصی و همه پر از عدد لحظه‌ای‌اند. بدون این
        // هدر، مرورگر (به‌ویژه سافاری روی آیفون) نسخه‌ی کش‌شده را نشان
        // می‌داد: کاربر چیزی را حذف می‌کرد، صفحه تازه می‌شد و باز همان
        // عدد قدیمی بود — انگار اصلاً اعمال نشده. فایل‌های CSS/JS مستقیم از
        // nginx می‌آیند و کش طولانی خودشان را دارند.
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }
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
        $user = null;
        $queries = [
            'SELECT id, full_name, username, password_hash, role, is_active
             FROM users WHERE username = :id OR (email IS NOT NULL AND email = :id2) LIMIT 1',
            'SELECT id, full_name, username, password_hash, role, is_active
             FROM users WHERE username = :id LIMIT 1',
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(
                    str_contains($sql, ':id2')
                        ? ['id' => $identifier, 'id2' => $identifier]
                        : ['id' => $identifier]
                );
                $user = $stmt->fetch();
                break;
            } catch (PDOException $e) {
                continue;   // ستون email هنوز نیست
            }
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // نامِ ناموجود هم شمرده می‌شود، وگرنه امتحان کردن نام‌های
            // تصادفی هیچ هزینه‌ای ندارد. پیام هم عمداً همان پیام قبلی
            // می‌ماند تا وجود یا نبودِ حساب لو نرود.
            LoginThrottle::recordFailure($identifier, $ip);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ((int)$user['is_active'] !== 1) {
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
    public static function establishSession(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id']         = (int)$user['id'];
        $_SESSION['full_name']       = $user['full_name'];
        $_SESSION['username']        = $user['username'];
        $_SESSION['role']            = $user['role'];
        $_SESSION['session_minutes'] = self::sessionMinutesFor((int)$user['id']);
        $_SESSION['login_time']      = time();
        $_SESSION['last_seen']       = time();
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
            self::slideTrustedCookie();
            if (time() - (int)($_SESSION['last_seen'] ?? 0) > 60) {
                $_SESSION['last_seen'] = time();
            }
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

        // تمدید فقط هر یک دقیقه یک‌بار نوشته می‌شود تا هزینه نداشته باشد
        if (time() - $lastSeen > 60) {
            $_SESSION['last_seen'] = time();
        }

        self::slideTrustedCookie();

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
            header('Location: login.php');
            exit;
        }
    }

    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            die('شما دسترسی لازم برای مشاهده این صفحه را ندارید.');
        }
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