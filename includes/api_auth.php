<?php
/**
 * احراز هویت توکنی برای api/v1 — مخصوص اپ‌های موبایل و هر مشتری غیرمرورگری.
 *
 * ⚠ این لایه عمداً از `Auth` جداست و به نشست PHP دست نمی‌زند.
 *
 * نشست و کوکی و CSRF برای مرورگر ساخته شده‌اند. یک اپ موبایل کوکیِ
 * خودکار ندارد — پس CSRF هم موضوعیت ندارد — و باید بتواند ماه‌ها وارد
 * بماند بی‌آنکه «مهلت بی‌فعالیتیِ» وب رویش اثر بگذارد. آمیختن این دو
 * یعنی هر تغییر در یکی، دیگری را بی‌سروصدا می‌شکند.
 *
 * شکل توکن:  "<selector>.<validator>"  (هگز، بدون کاراکتر خاص)
 * در دیتابیس فقط selector و sha256(validator) می‌نشیند، پس خواندن جدول
 * به کسی توان ساختن توکن نمی‌دهد.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

class ApiAuth
{
    /** عمر توکن. اپ موبایل نباید هر هفته کاربر را بیرون بیندازد. */
    public const TOKEN_DAYS = 90;

    /** بیش از این تعداد توکنِ زنده برای یک کاربر نگه داشته نمی‌شود. */
    private const MAX_TOKENS_PER_USER = 10;

    /**
     * ⛔ دامنه‌های توکنِ محدود. `null` = دسترسیِ کامل (ورودِ اپ با رمز).
     *    `sms` = فقط `api/v1/sms/*` — توکنی که اپ اندروید برای ثبتِ پیامک
     *    در پس‌زمینه نگه می‌دارد (`SmsSync`). اگر لو برود، نه فهرستِ
     *    تراکنش‌ها خوانده می‌شود و نه چیزی حذف.
     */
    public const SCOPES = ['sms'];

    /** کاربرِ درخواست جاری، بعد از یک بار حل شدن. */
    private static ?array $current = null;

    /** آیا جدول توکن‌ها ساخته شده است؟ (پیش از اجرای migration نه) */
    public static function available(): bool
    {
        return tableExists('api_tokens');
    }

    /**
     * توکن تازه برای یک کاربر. رشته‌ی خام فقط همین‌جا و همین یک بار
     * دیده می‌شود؛ بعد از این دیگر بازیابی‌شدنی نیست.
     */
    public static function issue(int $userId, ?string $label = null, ?string $platform = null,
                                 ?string $scope = null): string
    {
        $selector  = bin2hex(random_bytes(12));   // ۲۴ کاراکتر
        $validator = bin2hex(random_bytes(32));   // ۶۴ کاراکتر

        // ⛔ توکنِ محدود بی‌ستونِ `scope` ساخته **نمی‌شود**: آنجا همان
        //    توکن دسترسیِ کامل می‌گرفت — درست برعکسِ چیزی که خواسته شده.
        if ($scope !== null && !in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('scope');
        }
        if ($scope !== null && !self::scopeAvailable()) {
            throw new RuntimeException('api_tokens.scope missing');
        }

        $pdo = Database::getConnection();
        $cols = 'user_id, selector, token_hash, device_label, platform, expires_at';
        $vals = ':u, :s, :h, :l, :p, DATE_ADD(NOW(), INTERVAL :d DAY)';
        $args = [
            'u' => $userId,
            's' => $selector,
            'h' => hash('sha256', $validator),
            'l' => self::cleanLabel($label),
            'p' => self::cleanPlatform($platform),
            'd' => self::TOKEN_DAYS,
        ];
        if ($scope !== null) {
            $cols .= ', scope';
            $vals .= ', :sc';
            $args['sc'] = $scope;
        }
        $pdo->prepare("INSERT INTO api_tokens ({$cols}) VALUES ({$vals})")->execute($args);

        self::pruneUser($userId);

        return $selector . '.' . $validator;
    }

    /**
     * توکن درخواست جاری را می‌سنجد و کاربرش را برمی‌گرداند.
     *
     * همه‌ی زمان‌ها در دیتابیس مقایسه می‌شوند (`expires_at > NOW()`) نه
     * در PHP. یک بار در همین پروژه، ساختن زمان در PHP و مقایسه در MySQL
     * باعث شد لینک بازیابی رمز بلافاصله «منقضی» شود، چون منطقه‌ی زمانی
     * این دو یکی نبود. همان اشتباه اینجا تکرار نمی‌شود.
     */
    public static function user(): ?array
    {
        if (self::$current !== null) { return self::$current ?: null; }
        self::$current = [];

        $raw = self::bearerToken();
        if ($raw === null || !self::available()) { return null; }

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) { return null; }
        [$selector, $validator] = $parts;

        if (!preg_match('/^[a-f0-9]{24}$/', $selector)) { return null; }

        $pdo = Database::getConnection();
        $scopeCol = self::scopeAvailable() ? 't.scope' : 'NULL';
        $stmt = $pdo->prepare('
            SELECT t.id, t.token_hash, ' . $scopeCol . ' AS scope,
                   u.id AS user_id, u.username, u.full_name, u.role, u.is_active
            FROM api_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.selector = :s AND t.revoked_at IS NULL AND t.expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute(['s' => $selector]);
        $row = $stmt->fetch();
        if (!$row) { return null; }

        // مقایسه‌ی زمان‌ثابت — تفاوتِ زمانِ پاسخ نباید چیزی لو بدهد
        if (!hash_equals($row['token_hash'], hash('sha256', $validator))) { return null; }

        if ((int)$row['is_active'] !== 1) { return null; }

        // «آخرین استفاده» فقط برای اینکه کاربر در فهرست دستگاه‌ها ببیند.
        // هر درخواست یک UPDATE بزند هزینه دارد، پس فقط وقتی بیش از یک
        // ساعت گذشته باشد نوشته می‌شود.
        $pdo->prepare('
            UPDATE api_tokens SET last_used_at = NOW()
            WHERE id = :id AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
        ')->execute(['id' => $row['id']]);

        Log::setUser((int)$row['user_id']);
        self::$current = [
            'token_id'  => (int)$row['id'],
            'id'        => (int)$row['user_id'],
            'username'  => $row['username'],
            'full_name' => $row['full_name'],
            'role'      => $row['role'],
            'scope'     => $row['scope'] !== null ? (string)$row['scope'] : null,
        ];

        return self::$current;
    }

    /** آیا ستونِ `scope` ساخته شده است؟ (پیش از migration_sms_sync نه) */
    public static function scopeAvailable(): bool
    {
        return tableHasColumn('api_tokens', 'scope');
    }

    /** دامنه‌ی توکنِ همین درخواست: `null` (کامل) یا یکی از `SCOPES`. */
    public static function scope(): ?string
    {
        $u = self::user();
        return $u ? ($u['scope'] ?? null) : null;
    }

    /** شناسه‌ی کاربر یا null. هرگز از ورودی کاربر خوانده نمی‌شود. */
    public static function userId(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    /** باطل کردن توکنِ همین درخواست (خروج از اپ). */
    public static function revokeCurrent(): bool
    {
        $u = self::user();
        if (!$u) { return false; }

        Database::getConnection()
            ->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL')
            ->execute(['id' => $u['token_id']]);

        self::$current = [];
        return true;
    }

    /** باطل کردن همه‌ی توکن‌های یک کاربر (مثلاً بعد از تغییر رمز). */
    public static function revokeAllFor(int $userId): void
    {
        if (!self::available()) { return; }
        Database::getConnection()
            ->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL')
            ->execute(['u' => $userId]);
    }

    /**
     * سرآیند Authorization را می‌خواند.
     *
     * روی برخی پیکربندی‌های Apache/CGI این سرآیند به PHP نمی‌رسد و در
     * $_SERVER نیست؛ getallheaders() آن حالت را هم پوشش می‌دهد. بدون
     * این، API روی بعضی میزبان‌ها بی‌دلیل ۴۰۱ می‌داد.
     */
    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) { $header = $value; break; }
            }
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) { return null; }
        return $m[1];
    }

    /**
     * توکن‌های منقضی/باطل را پاک می‌کند و سقف تعداد را نگه می‌دارد.
     *
     * بدون سقف، کاربری که مدام اپ را نصب و حذف می‌کند ردیف‌های بی‌پایان
     * می‌سازد و هر کدام یک راه ورودِ زنده‌اند.
     */
    private static function pruneUser(int $userId): void
    {
        $pdo = Database::getConnection();

        $pdo->prepare('
            DELETE FROM api_tokens
            WHERE user_id = :u AND (expires_at <= NOW() OR revoked_at IS NOT NULL)
        ')->execute(['u' => $userId]);

        // قدیمی‌ترها اول می‌روند. LIMIT داخل subquery در MySQL ممنوع است،
        // پس شناسه‌ها جدا خوانده می‌شوند.
        $keep = self::MAX_TOKENS_PER_USER;
        $stmt = $pdo->prepare("
            SELECT id FROM api_tokens
            WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET {$keep}
        ");
        $stmt->execute(['u' => $userId]);
        $extra = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($extra) {
            $in = implode(',', array_map('intval', $extra));
            $pdo->exec("DELETE FROM api_tokens WHERE id IN ({$in})");
        }
    }

    private static function cleanLabel(?string $label): ?string
    {
        $label = trim((string)$label);
        if ($label === '') { return null; }
        return mb_substr($label, 0, 80);
    }

    private static function cleanPlatform(?string $platform): ?string
    {
        $platform = strtolower(trim((string)$platform));
        return in_array($platform, ['android', 'ios', 'web', 'desktop'], true) ? $platform : null;
    }
}
