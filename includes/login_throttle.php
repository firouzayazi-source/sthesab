<?php
/**
 * محدودیت تلاش ناموفق ورود.
 *
 * چرا جداست و داخل Auth نیست: به همان دلیلی که PasswordReset جداست —
 * تا بشود بدون بالا آوردن نشست و صفحه، مستقیم آزمودش
 * (`tests/test_login_throttle.php`).
 *
 * دو کلید جدا شمرده می‌شوند و هر کدام سقف خودش را دارد:
 *
 *   نام کاربری  — جلوی حمله‌ی پخش‌شده روی یک حساب از چند IP را می‌گیرد
 *   IP          — جلوی امتحان کردن نام‌های کاربریِ تصادفی از یک نقطه را
 *
 * فقط یکی از این دو کافی نیست: با شمردنِ تنها IP، مهاجم از چند آدرس
 * می‌آید و رد می‌شود؛ با شمردنِ تنها نام کاربری، مهاجم می‌تواند عمداً
 * چند بار رمز غلط بزند و حسابِ *یک نفر دیگر* را قفل کند.
 *
 * پنجره کشویی است، نه قفلِ ساعت‌دار: وقتی تلاش‌های قدیمی از پنجره بیرون
 * بروند، خودبه‌خود باز می‌شود. هیچ ردیفی «آزاد شد» علامت نمی‌خورد و
 * هیچ کارِ زمان‌بندی‌شده‌ای لازم نیست.
 *
 * ⚠ همه‌ی زمان‌ها در دیتابیس ساخته و مقایسه می‌شوند (`NOW()`), نه در PHP.
 * یک بار در بازیابی رمز، ساختنِ زمان در PHP و مقایسه در MySQL باعث شد
 * لینک بلافاصله «منقضی» شود، چون Auth::initSession منطقه‌ی زمانی PHP را
 * عوض می‌کرد.
 */
class LoginThrottle
{
    /** تلاش ناموفق مجاز برای یک نام کاربری در پنجره. */
    public const MAX_PER_USER = 5;

    /** تلاش ناموفق مجاز از یک IP در پنجره — بالاتر، چون پشت NAT ممکن است
     *  چند نفر واقعی باشند (یک خانه، یک دفتر، اینترنت موبایل). */
    public const MAX_PER_IP = 20;

    /** طول پنجره به دقیقه. */
    public const WINDOW_MIN = 15;

    /**
     * آیا این جدول اصلاً هست؟ اگر migration اجرا نشده باشد، محدودیت
     * بی‌سروصدا خاموش می‌ماند و ورود مثل قبل کار می‌کند — نه اینکه کل
     * صفحه‌ی ورود با خطا بخوابد.
     */
    public static function available(): bool
    {
        // ترجیح با `tableExists()` است چون نقشه‌ی ساختار را در همان درخواست
        // کش می‌کند. ولی این کلاس عمداً به functions.php وابسته نیست —
        // مسیر ورود باید سبک بماند و از هر جایی قابل فراخوانی باشد — پس
        // اگر آن تابع نبود، خودش یک بار می‌پرسد و جواب را نگه می‌دارد.
        if (function_exists('tableExists')) {
            return tableExists('login_attempts');
        }

        static $has = null;
        if ($has !== null) { return $has; }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1'
            );
            $st->execute(['t' => 'login_attempts']);
            $has = (bool)$st->fetchColumn();
        } catch (PDOException $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * اگر قفل است، تعداد دقیقه‌ی باقی‌مانده تا باز شدن؛ وگرنه null.
     *
     * «باقی‌مانده» یعنی تا وقتی قدیمی‌ترین تلاشِ داخل پنجره از پنجره
     * بیرون برود — همان لحظه‌ای که شمارش دوباره زیر سقف می‌رود.
     */
    public static function lockedFor(string $username, string $ip): ?int
    {
        if (!self::available()) { return null; }

        $username = self::normalizeUsername($username);
        $ip       = self::normalizeIp($ip);
        $w        = self::WINDOW_MIN;

        try {
            $pdo = Database::getConnection();

            $st = $pdo->prepare(
                "SELECT COUNT(*) AS c,
                        TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL $w MINUTE) AS wait_s
                 FROM login_attempts
                 WHERE username_tried = :u AND created_at > (NOW() - INTERVAL $w MINUTE)"
            );
            $st->execute(['u' => $username]);
            $row = $st->fetch();
            if ((int)($row['c'] ?? 0) >= self::MAX_PER_USER) {
                return self::toMinutes($row['wait_s'] ?? 0);
            }

            $st = $pdo->prepare(
                "SELECT COUNT(*) AS c,
                        TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL $w MINUTE) AS wait_s
                 FROM login_attempts
                 WHERE request_ip = :ip AND created_at > (NOW() - INTERVAL $w MINUTE)"
            );
            $st->execute(['ip' => $ip]);
            $row = $st->fetch();
            if ((int)($row['c'] ?? 0) >= self::MAX_PER_IP) {
                return self::toMinutes($row['wait_s'] ?? 0);
            }
        } catch (PDOException $e) {
            return null;    // خرابیِ دیتابیس نباید ورود را ببندد
        }

        return null;
    }

    /** یک تلاش ناموفق را ثبت می‌کند. */
    public static function recordFailure(string $username, string $ip): void
    {
        if (!self::available()) { return; }
        try {
            Database::getConnection()->prepare(
                'INSERT INTO login_attempts (username_tried, request_ip) VALUES (:u, :ip)'
            )->execute([
                'u'  => self::normalizeUsername($username),
                'ip' => self::normalizeIp($ip),
            ]);
        } catch (PDOException $e) { /* ثبت نشدنِ شمارنده نباید ورود را بشکند */ }
    }

    /**
     * پس از ورود موفق، سابقه‌ی همان نام کاربری پاک می‌شود.
     *
     * سابقه‌ی IP عمداً پاک نمی‌شود: یک ورودِ موفق نباید پاکسازیِ رایگانِ
     * شمارنده‌ی IP باشد، وگرنه مهاجمی که یک حساب معتبر دارد می‌تواند بین
     * هر ۲۰ حدس یک بار وارد شود و شمارنده را صفر کند.
     */
    public static function clear(string $username): void
    {
        if (!self::available()) { return; }
        try {
            Database::getConnection()
                ->prepare('DELETE FROM login_attempts WHERE username_tried = :u')
                ->execute(['u' => self::normalizeUsername($username)]);
        } catch (PDOException $e) { /* ignore */ }
    }

    /**
     * ردیف‌های قدیمی‌تر از پنجره را پاک می‌کند. جدول کوچک می‌ماند بدون
     * اینکه کار زمان‌بندی‌شده‌ای لازم باشد.
     */
    public static function prune(): int
    {
        if (!self::available()) { return 0; }
        $w = self::WINDOW_MIN;
        try {
            $st = Database::getConnection()->prepare(
                "DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL $w MINUTE)"
            );
            $st->execute();
            return $st->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }

    // ---------------------------------------------------------------

    /** ورودی فرم است، پس هم بریده می‌شود هم بی‌تفاوت به بزرگ و کوچک. */
    private static function normalizeUsername(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username)), 0, 190);
    }

    private static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        return $ip === '' ? '0.0.0.0' : substr($ip, 0, 45);
    }

    /** ثانیه → دقیقه، همیشه دست‌کم ۱ تا پیام «۰ دقیقه» ندهد. */
    private static function toMinutes($seconds): int
    {
        return max(1, (int)ceil(((int)$seconds) / 60));
    }
}
