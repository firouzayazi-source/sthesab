<?php
/**
 * ⛔ محیطِ فروشگاهی (Business Mode) — تنها جای تصمیمِ «این حساب کدام
 *    تجربه را می‌بیند».
 *
 * **خواسته‌ی مالکِ نصب:** «حسابداری شخصی تغییر نمی‌خوام، کاملاً جدا باشه…
 * برای ورود هم بهتره `/store` باشه، یعنی آدرس‌ها هم با هم قاطی نشه.»
 *
 * معماری: **هسته‌ی مشترک، تجربه‌ی جدا.** ورود، نشست، حساب‌ها، لاگ و
 * ممیزی همان‌اند؛ ولی حسابِ فروشگاهی فقط `/store/` را می‌بیند و حسابِ
 * شخصی هیچ چیزی از `/store/` نمی‌بیند.
 *
 * سه قاعده که این فایل را شکل می‌دهند:
 *
 * ۱. **یک حساب، یک تجربه** (`users.account_type`). حسابِ فروشگاهی حسابِ
 *    جداست، نه یک حالت روی حسابِ شخصی — وگرنه پولِ مغازه و خانه در یک
 *    `walletBalances()` قاطی می‌شد و همه‌ی گزارش‌های شخصی دروغ می‌گفتند.
 *
 * ۲. **⛔ صفر کوئری برای حسابِ شخصی.** نوعِ حساب هنگامِ ساختنِ نشست یک
 *    بار خوانده و در نشست نوشته می‌شود؛ `gate()` فقط همان را می‌خواند.
 *    پس بودجه‌ی کوئریِ هیچ صفحه‌ی شخصی‌ای تکان نمی‌خورد.
 *    - و برای اینکه تغییرِ نوع به نشستِ زنده برسد، `setType()` همه‌ی
 *      دسترسی‌های آن کاربر را باطل می‌کند (`revokeAllAccessFor()`). یعنی
 *      `refreshAccountState()` — تابعی که هر دقیقه روی **همه‌ی** کاربران
 *      اجرا می‌شود — عمداً دست نخورد.
 *
 * ۳. **⛔ دروازه با پیش‌فرضِ بسته** (`gate()`). حسابِ فروشگاهی فقط به
 *    `store/` و فهرستِ بسته‌ی `SHARED` می‌رسد. پیش‌فرضِ باز یعنی هر
 *    قابلیتِ شخصیِ فردا — و بدتر، اندپوینتِ «معاملات» که پول را دو بار
 *    می‌شمرد — بی‌صدا به حسابِ فروشگاهی نشت می‌کرد.
 */

require_once __DIR__ . '/db.php';

final class Biz
{
    /** ⛔ تنها مرجعِ نوع‌های حساب. کلیدِ اول پیش‌فرض است. */
    public const TYPES = [
        'personal' => 'شخصی',
        'business' => 'فروشگاهی',
    ];

    /** پوشه‌ی محیطِ فروشگاهی، نسبت به `APP_BASE_PATH`. */
    public const DIR = 'store';

    /**
     * ⛔ فهرستِ بسته‌ی اسکریپت‌های بیرون از `store/` که حسابِ فروشگاهی
     *    هنوز حق دارد به آن‌ها برسد. هر چیزِ دیگری بسته است.
     *
     * - `logout.php` — خروج هرگز نباید بن‌بست شود، از هر دری که باشد.
     * - `health.php` — سنجشِ سلامت؛ هیچ داده‌ای از کاربر نمی‌دهد.
     */
    public const SHARED = ['logout.php', 'health.php'];

    /**
     * ⛔ منوی محیطِ فروشگاهی — فهرستِ بسته، مثلِ `admin/_nav.php`.
     *    فایلی که هنوز ساخته نشده رندر نمی‌شود (دکمه‌ی بی‌کار از نبودنش
     *    بدتر است).
     */
    public const NAV = [
        'index.php'    => 'داشبورد',
        'settings.php' => 'تنظیمات فروشگاه',
    ];

    /** نقش‌هایی که حسابِ فروشگاهی می‌تواند داشته باشد. */
    private const BUSINESS_ROLES = ['user', 'colleague'];

    /** ستونِ نوعِ حساب آمده است؟ (migration_business_mode) */
    public static function available(): bool
    {
        return function_exists('tableHasColumn')
            ? tableHasColumn('users', 'account_type')
            : self::columnProbe();
    }

    private static function columnProbe(): bool
    {
        try {
            Database::getConnection()->query('SELECT account_type FROM users LIMIT 0');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * نوعِ حسابِ یک کاربر از دیتابیس — فقط هنگامِ ساختنِ نشست صدا زده
     * می‌شود، نه روی هر صفحه.
     *
     * ⛔ فقط «ستون ناشناخته» (42S22) یعنی `personal`. هر خطای دیگری بالا
     *    می‌رود: اگر یک قطعیِ گذرا حسابِ فروشگاهی را «شخصی» می‌خواند،
     *    صاحبِ مغازه بی‌صدا وسطِ محیطِ شخصی فرود می‌آمد.
     */
    public static function typeFor(int $userId): string
    {
        try {
            $st = Database::getConnection()->prepare(
                'SELECT account_type FROM users WHERE id = :id LIMIT 1'
            );
            $st->execute(['id' => $userId]);
            $v = $st->fetchColumn();
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '42S22') { return 'personal'; }
            throw $e;
        }
        return is_string($v) && isset(self::TYPES[$v]) ? $v : 'personal';
    }

    /** حسابِ همین نشست فروشگاهی است؟ — فقط از نشست، بدونِ کوئری. */
    public static function isBusiness(): bool
    {
        return !empty($_SESSION['user_id'])
            && ($_SESSION['account_type'] ?? '') === 'business';
    }

    /** آدرسِ مطلقِ یک صفحه‌ی فروشگاهی (قاعده ۱۶: هرگز نسبی). */
    public static function url(string $page = ''): string
    {
        $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        return $base . '/' . self::DIR . '/' . ltrim($page, '/');
    }

    /** مسیرِ اسکریپتِ جاری نسبت به ریشه‌ی اپ، مثل `store/index.php`. */
    public static function currentScript(): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $base   = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        if ($base !== '' && strncmp($script, $base, strlen($base)) === 0) {
            $script = substr($script, strlen($base));
        }
        return ltrim($script, '/');
    }

    /**
     * ⛔ دروازه — از `Auth::initSession()` و بعد از ورودِ خودکار با کوکیِ
     *    دستگاه صدا زده می‌شود.
     *
     * حسابِ شخصی: یک خواندنِ نشست و برگشت. هیچ کوئری، هیچ تغییری.
     * حسابِ فروشگاهی بیرون از `store/` و `SHARED`: اندپوینت ← ۴۰۳ JSON،
     * صفحه ← ریدایرکت به داشبوردِ فروشگاه (نه ۴۰۴: لینکِ کهنه‌ی یک اعلان
     * یا ایمیل نباید بن‌بست شود).
     */
    public static function gate(): void
    {
        if (PHP_SAPI === 'cli' || !self::isBusiness()) { return; }

        $script = self::currentScript();
        if (strncmp($script, self::DIR . '/', strlen(self::DIR) + 1) === 0
            || in_array($script, self::SHARED, true)) {
            return;
        }

        if (strncmp($script, 'api/', 4) === 0) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success'    => false,
                'message'    => 'این بخش برای حسابِ فروشگاهی در دسترس نیست.',
                'request_id' => class_exists('Log') ? Log::requestId() : null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . self::url());
        exit;
    }

    /**
     * سرِ هر صفحه‌ی `store/` (جز ورود و خروج).
     *
     * واردنشده ← صفحه‌ی ورودِ فروشگاه. حسابِ شخصی ← ۴۰۴ — کاربرِ عادی
     * نباید هیچ چیزی از محیطِ فروشگاهی ببیند، حتی اینکه وجود دارد.
     */
    public static function requirePage(): void
    {
        if (!Auth::isLoggedIn()) {
            header('Location: ' . self::url('login.php'));
            exit;
        }
        if (!self::isBusiness()) {
            self::notFound();
        }
    }

    /** ۴۰۴ِ خنثی — نه نامِ فروشگاه، نه سربرگ، نه هیچ نشانه‌ای. */
    public static function notFound(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }
        $home = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/index.php';
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">'
           . '<title>پیدا نشد</title></head>'
           . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:48px 16px">'
           . '<p>صفحه‌ای که دنبالش هستید پیدا نشد.</p>'
           . '<p><a href="' . htmlspecialchars($home, ENT_QUOTES) . '">بازگشت</a></p>'
           . '</body></html>';
        exit;
    }

    /** ⛔ تنها قاعده‌ی «این نقش با این نوعِ حساب جور است؟». */
    public static function roleAllowed(string $role, string $type): bool
    {
        return $type !== 'business' || in_array($role, self::BUSINESS_ROLES, true);
    }

    /**
     * ⛔ تنها نویسنده‌ی `users.account_type` — پنلِ مدیر و در پشتیِ خطِ
     *    فرمان هر دو از همین رد می‌شوند.
     *
     * - مدیر و پشتیبان نمی‌توانند فروشگاهی شوند: دروازه آن‌ها را از پنلِ
     *   مدیریت بیرون نگه می‌داشت و آخرین مدیرِ نصب می‌توانست خودش را قفل
     *   کند.
     * - ⛔ هر تغییر همه‌ی دسترسی‌های آن کاربر را باطل می‌کند (نشست، کوکیِ
     *   دستگاه، توکنِ اپ). نوعِ حساب در نشست است؛ بدونِ ابطال، مرورگرِ باز
     *   با تجربه‌ی قبلی می‌ماند.
     *
     * @return array{ok:bool, message:string, changed?:bool}
     */
    public static function setType(int $targetId, string $type, ?int $actorId = null): array
    {
        if (!isset(self::TYPES[$type])) {
            return ['ok' => false, 'message' => 'نوعِ حساب معتبر نیست.'];
        }
        if (!self::available()) {
            return ['ok' => false, 'message' => 'migration_business_mode.sql هنوز اجرا نشده است.'];
        }

        $pdo = Database::getConnection();
        $st  = $pdo->prepare('SELECT id, role, account_type FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $targetId]);
        $u = $st->fetch();
        if (!$u) {
            return ['ok' => false, 'message' => 'کاربر مورد نظر یافت نشد.'];
        }
        if (!self::roleAllowed((string)$u['role'], $type)) {
            return ['ok' => false, 'message' => 'حسابِ مدیر یا پشتیبان نمی‌تواند فروشگاهی شود.'];
        }
        $from = isset(self::TYPES[$u['account_type']]) ? (string)$u['account_type'] : 'personal';
        if ($from === $type) {
            return ['ok' => true, 'changed' => false, 'message' => 'حساب از قبل ' . self::TYPES[$type] . ' است.'];
        }

        $pdo->prepare('UPDATE users SET account_type = :t WHERE id = :id')
            ->execute(['t' => $type, 'id' => $targetId]);

        require_once __DIR__ . '/functions.php';
        revokeAllAccessFor($targetId);

        if (class_exists('Audit')) {
            Audit::log('user.account_type', 'user', $targetId, ['from' => $from, 'to' => $type], $actorId, $targetId);
        }

        return [
            'ok'      => true,
            'changed' => true,
            'message' => 'حساب ' . self::TYPES[$type] . ' شد و از همه‌ی دستگاه‌ها خارج می‌شود.',
        ];
    }

    /* ============================================================
       تنظیماتِ فروشگاه (سربرگِ فاکتور)
       ============================================================ */

    /** سقفِ طولِ هر فیلد — تنها مرجع، هم فرم هم اعتبارسنجی. */
    public const SETTING_LIMITS = [
        'shop_name'      => 120,
        'phone'          => 40,
        'address'        => 300,
        'invoice_footer' => 500,
    ];

    public static function settings(int $userId): array
    {
        $out = array_fill_keys(array_keys(self::SETTING_LIMITS), '');
        try {
            $st = Database::getConnection()->prepare(
                'SELECT shop_name, phone, address, invoice_footer FROM biz_settings WHERE user_id = :u LIMIT 1'
            );
            $st->execute(['u' => $userId]);
            $row = $st->fetch();
        } catch (PDOException $e) {
            return $out;    // جدول هنوز نیامده
        }
        if ($row) {
            foreach ($out as $k => $_) { $out[$k] = (string)($row[$k] ?? ''); }
        }
        return $out;
    }

    /** @return array{ok:bool, message:string} */
    public static function saveSettings(int $userId, array $in): array
    {
        $clean = [];
        foreach (self::SETTING_LIMITS as $k => $max) {
            $raw = (string)($in[$k] ?? '');
            // پای فاکتور چندخطی است؛ بقیه یک‌خطی‌اند و فاصله‌ی اضافه ندارند
            $v = $k === 'invoice_footer'
                ? trim($raw)
                : trim((string)preg_replace('/\s+/u', ' ', $raw));
            if (mb_strlen($v) > $max) {
                return ['ok' => false, 'message' => 'متنِ یکی از فیلدها بیش از ' . $max . ' نویسه است.'];
            }
            $clean[$k] = $v;
        }
        if ($clean['shop_name'] === '') {
            return ['ok' => false, 'message' => 'نامِ فروشگاه الزامی است.'];
        }

        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, shop_name, phone, address, invoice_footer)
             VALUES (:u, :n, :p, :a, :f)
             ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name), phone = VALUES(phone),
                                     address = VALUES(address), invoice_footer = VALUES(invoice_footer)'
        )->execute([
            'u' => $userId, 'n' => $clean['shop_name'], 'p' => $clean['phone'],
            'a' => $clean['address'], 'f' => $clean['invoice_footer'],
        ]);
        return ['ok' => true, 'message' => 'تنظیماتِ فروشگاه ذخیره شد.'];
    }
}
