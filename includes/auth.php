<?php
require_once __DIR__ . '/db.php';

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
            session_name('DAFTAR_SESSION');
            session_start();
        }

        if (defined('APP_TIMEZONE')) {
            date_default_timezone_set(APP_TIMEZONE);
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
    public static function attemptLogin(string $identifier, string $password): array
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return ['success' => false, 'message' => 'نام کاربری یا ایمیل و رمز عبور را وارد کنید.'];
        }

        $pdo = Database::getConnection();

        // از کامل‌ترین کوئری شروع می‌شود و اگر ستونی هنوز با migration
        // اضافه نشده باشد، به نسخه‌ی ساده‌تر می‌افتد.
        $user = null;
        $queries = [
            'SELECT id, full_name, username, password_hash, role, is_active, session_hours
             FROM users WHERE username = :id OR (email IS NOT NULL AND email = :id2) LIMIT 1',
            'SELECT id, full_name, username, password_hash, role, is_active, session_hours
             FROM users WHERE username = :id LIMIT 1',
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
                continue;   // ستون email یا session_hours هنوز نیست
            }
        }
        if ($user && !isset($user['session_hours'])) { $user['session_hours'] = 1; }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ((int)$user['is_active'] !== 1) {
            return ['success' => false, 'message' => 'حساب کاربری شما غیرفعال شده است.'];
        }

        session_regenerate_id(true);

        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['session_hours'] = (int)($user['session_hours'] ?? 1);
        $_SESSION['login_time']    = time();
        $_SESSION['last_seen']     = time();

        return ['success' => true, 'message' => 'ورود موفقیت‌آمیز بود.'];
    }

    public static function isLoggedIn(): bool
    {
        // اگر نشست نیست، شاید این دستگاه «مورد اعتماد» باشد
        if (empty($_SESSION['user_id'])) {
            return self::loginFromTrustedDevice();
        }

        // مهلت بر اساس آخرین فعالیت است، نه زمان ورود —
        // یعنی تا وقتی کار می‌کنی بیرونت نمی‌اندازد.
        $hours = (int)($_SESSION['session_hours'] ?? 1);
        if ($hours < 1) { $hours = 1; }
        $lifetime = $hours * 3600;

        $lastSeen = (int)($_SESSION['last_seen'] ?? $_SESSION['login_time'] ?? time());
        if ((time() - $lastSeen) > $lifetime) {
            self::logout();
            return self::loginFromTrustedDevice();
        }

        // تمدید فقط هر یک دقیقه یک‌بار نوشته می‌شود تا هزینه نداشته باشد
        if (time() - $lastSeen > 60) {
            $_SESSION['last_seen'] = time();
        }

        return true;
    }

    /* ============================================================
       دستگاه مورد اعتماد
       ============================================================ */

    private const TRUSTED_COOKIE = 'daftar_device';
    private const TRUSTED_DAYS   = 30;

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
        $expires = date('Y-m-d H:i:s', time() + (self::TRUSTED_DAYS * 86400));

        $label = self::deviceLabel();

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('
                INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at, last_used_at)
                VALUES (:u, :s, :h, :l, :e, NOW())
            ');
            $stmt->execute(['u' => $userId, 's' => $selector, 'h' => $hash, 'l' => $label, 'e' => $expires]);
        } catch (PDOException $e) {
            return; // جدول هنوز ساخته نشده
        }

        $secure = defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS;
        setcookie(self::TRUSTED_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + (self::TRUSTED_DAYS * 86400),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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
                       u.full_name, u.username, u.role, u.is_active, u.session_hours
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

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$row['user_id'];
        $_SESSION['full_name']     = $row['full_name'];
        $_SESSION['username']      = $row['username'];
        $_SESSION['role']          = $row['role'];
        $_SESSION['session_hours'] = (int)($row['session_hours'] ?? 1);
        $_SESSION['login_time']    = time();
        $_SESSION['last_seen']     = time();

        try {
            $upd = Database::getConnection()->prepare('UPDATE trusted_devices SET last_used_at = NOW() WHERE id = :id');
            $upd->execute(['id' => (int)$row['id']]);
        } catch (PDOException $e) { /* ignore */ }

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

    public static function logout(): void
    {
        // اگر این دستگاه مورد اعتماد بود، اعتمادش هم باطل شود
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