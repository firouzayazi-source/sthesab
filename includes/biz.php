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
 * ۱. **سه نوعِ حساب** (`users.account_type`):
 *    - `personal` — فقط حساب لند (پیش‌فرض).
 *    - `both` — حساب لند **سرِ جایش می‌ماند** و از `/store` هم وارد
 *      فروشگاه می‌شود. **خواسته‌ی مالکِ نصب:** «وقتی قابلیت فروشگاهی کسی
 *      رو فعال می‌کنم حسابلند قبلی سرجاش باشه، با آدرس store بتونه وارد
 *      فروشگاهی بشه.»
 *    - `business` — فقط فروشگاه؛ هیچ صفحه‌ی شخصی‌ای را نمی‌بیند.
 *    ⛔ پولِ مغازه و خانه با این حال قاطی نمی‌شود: فروشگاه دفترِ خودش را
 *       دارد (`biz_accounts`، `biz_products`، …) و هیچ‌کدام در
 *       `walletBalances()` نمی‌نشیند. «یک حساب، یک تجربه» که مرحله‌ی ۱
 *       نوشته بود به همین دلیل بود، و حالا دیوار روی **داده** است نه روی
 *       حساب.
 *
 * ۲. **⛔ صفر کوئری برای صفحه‌های شخصی.** نوعِ حساب هنگامِ ساختنِ نشست یک
 *    بار خوانده و در نشست نوشته می‌شود؛ `gate()` فقط همان را می‌خواند.
 *    صفحه‌های `store/` ولی نوع را **هر بار از دیتابیس** تازه می‌کنند
 *    (`requirePage()`، یک کوئری) — پس روشن و خاموش کردنِ فروشگاه برای یک
 *    حسابِ `personal`/`both` کاربر را از حساب لندش بیرون نمی‌اندازد.
 *    - فقط تغییری که **دروازه** را عوض می‌کند (به `business` یا از آن)
 *      همه‌ی دسترسی‌ها را باطل می‌کند (`revokeAllAccessFor()`)، چون آن
 *      یکی از نشست خوانده می‌شود.
 *
 * ۳. **⛔ دروازه با پیش‌فرضِ بسته** (`gate()`). حسابِ «فقط فروشگاه» فقط به
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
        'both'     => 'شخصی + فروشگاه',
        'business' => 'فقط فروشگاه',
    ];

    /** ⛔ نوع‌هایی که `/store` را می‌بینند — تنها مرجع. */
    public const STORE_TYPES = ['both', 'business'];

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
     * ⛔ منوی محیطِ فروشگاهی — تنها مرجع، گروه‌بندی‌شده (نوارِ کناریِ
     *    سمتِ راست از همین رندر می‌شود). فایلی که هنوز ساخته نشده رندر
     *    نمی‌شود (دکمه‌ی بی‌کار از نبودنش بدتر است).
     */
    public const NAV = [
        'پیشخوان'      => ['index.php' => 'داشبورد'],
        'فروش'         => ['quick-sale.php' => 'فروش سریع', 'sales.php' => 'فاکتورهای فروش'],
        'خرید'         => ['purchases.php' => 'فاکتورهای خرید'],
        'خزانه'        => ['payments.php' => 'دریافت و پرداخت', 'cheques.php' => 'چک‌ها', 'accounts.php' => 'صندوق و بانک'],
        'انبار و کالا' => ['products.php' => 'کالاها', 'categories.php' => 'دسته‌بندی‌ها', 'products-io.php' => 'ورود و خروجِ اکسل'],
        'اشخاص'        => ['parties.php' => 'مشتری و تأمین‌کننده'],
        'گزارش و چاپ'  => ['reports.php' => 'گزارش و چاپ'],
        'تنظیمات'      => ['settings.php' => 'تنظیمات فروشگاه', 'print-settings.php' => 'تنظیمات چاپ', 'invoice-design.php' => 'طراحی فاکتور'],
    ];

    /**
     * منوی پایینِ موبایل — فقط **کلید**ها؛ برچسب از `NAV` می‌آید (فهرستِ
     * دومِ برچسب ساخته نمی‌شود). بقیه در کشوی «منو» است.
     */
    public const TABBAR = ['index.php', 'quick-sale.php', 'sales.php', 'products.php'];

    /** صفحه‌ی جزئیات کدام قلمِ منو را روشن می‌کند (مگر صفحه خودش `$navActive` بگذارد). */
    public const NAV_PARENT = [
        'product.php'      => 'products.php',
        'products-cleanup.php' => 'products.php',
        'party.php'        => 'parties.php',
        'invoice.php'      => 'sales.php',
        'invoice-edit.php' => 'sales.php',
        'return.php'       => 'sales.php',
        'payment.php'      => 'payments.php',
    ];

    /**
     * ⛔ منوی «+ ثبت» (بالای نوارِ کناری) — تنها مرجع؛ `<details>`ِ بومی است،
     *    پس بی‌جاوااسکریپت باز و بسته می‌شود.
     */
    public const NEW_MENU = [
        'quick-sale.php'              => 'فروش سریع',
        'invoice-edit.php?k=sale'     => 'فاکتور فروش',
        'invoice-edit.php?k=purchase' => 'فاکتور خرید',
        'payment.php?k=receipt'       => 'دریافت از مشتری',
        'payment.php?k=payment'       => 'پرداخت به تأمین‌کننده',
        'payment.php?k=expense'       => 'هزینه‌ی فروشگاه',
        'product.php'                 => 'کالای تازه',
        'party.php'                   => 'مشتری یا تأمین‌کننده‌ی تازه',
    ];

    /**
     * قلمِ فعالِ منو که خودِ صفحه تعیین می‌کند — مثلاً صفحه‌ی یک فاکتورِ
     * **خرید** باید «فاکتورهای خرید» را روشن کند، نه «فروش». یک ویژگیِ ایستا
     * است نه متغیرِ سراسری (قاعده ۵۰).
     */
    public static ?string $navActive = null;

    /** @return array<string,string> فایل ← برچسب، به ترتیبِ منو */
    public static function navFlat(): array
    {
        $out = [];
        foreach (self::NAV as $items) { $out += $items; }
        return $out;
    }

    /** قلمِ فعالِ منو برای یک اسکریپت (صفحه‌ی جزئیات → فهرستش). */
    public static function navCurrent(string $page): string
    {
        return self::$navActive ?? (self::NAV_PARENT[$page] ?? $page);
    }

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

    /**
     * حسابِ همین نشست «فقط فروشگاه» است؟ — فقط از نشست، بدونِ کوئری.
     * تنها چیزی که دروازه‌ی صفحه‌های شخصی می‌پرسد.
     */
    public static function isStoreOnly(): bool
    {
        return !empty($_SESSION['user_id'])
            && ($_SESSION['account_type'] ?? '') === 'business';
    }

    /** نشستِ جاری به فروشگاه راه دارد؟ — از نشست؛ برای نمایش، نه دروازه. */
    public static function hasStore(): bool
    {
        return !empty($_SESSION['user_id'])
            && in_array($_SESSION['account_type'] ?? '', self::STORE_TYPES, true);
    }

    /**
     * نوع را از دیتابیس تازه می‌کند و در نشست می‌نویسد — فقط در صفحه‌های
     * `store/` (یک کوئری). بدونِ آن، کاربری که مدیر همین حالا برایش
     * فروشگاه روشن کرده تا ورودِ دوباره ۴۰۴ می‌گرفت.
     */
    public static function refreshType(): string
    {
        if (empty($_SESSION['user_id'])) { return 'personal'; }
        $t = self::typeFor((int)$_SESSION['user_id']);
        $_SESSION['account_type'] = $t;
        return $t;
    }

    /** آدرسِ خانه‌ی حساب لند — برای حسابِ `both` در سرآیندِ فروشگاه. */
    public static function personalUrl(): string
    {
        return (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/index.php';
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
        if (PHP_SAPI === 'cli' || !self::isStoreOnly()) { return; }

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
     * واردنشده ← صفحه‌ی ورودِ فروشگاه. حسابِ بی‌فروشگاه ← ۴۰۴ — کاربرِ
     * عادی نباید هیچ چیزی از محیطِ فروشگاهی ببیند، حتی اینکه وجود دارد.
     * ⛔ نوع از دیتابیس خوانده می‌شود، نه از نشست (بالای فایل، قاعده‌ی ۲).
     */
    public static function requirePage(): void
    {
        if (!Auth::isLoggedIn()) {
            header('Location: ' . self::url('login.php'));
            exit;
        }
        if (!in_array(self::refreshType(), self::STORE_TYPES, true)) {
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
     * - مدیر و پشتیبان نمی‌توانند «فقط فروشگاه» شوند: دروازه آن‌ها را از
     *   پنلِ مدیریت بیرون نگه می‌داشت و آخرین مدیرِ نصب می‌توانست خودش را
     *   قفل کند. «شخصی + فروشگاه» برای هر نقشی مجاز است.
     * - ⛔ فقط تغییری که دروازه را عوض می‌کند (به `business` یا از آن) همه‌ی
     *   دسترسی‌های آن کاربر را باطل می‌کند (نشست، کوکیِ دستگاه، توکنِ اپ):
     *   دروازه از نشست می‌خواند و بدونِ ابطال، مرورگرِ باز با تجربه‌ی قبلی
     *   می‌ماند. روشن/خاموش کردنِ فروشگاه برای `personal`↔`both` کاربر را
     *   بیرون **نمی‌اندازد** — خواسته‌ی صریح همین بود.
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
            return ['ok' => false, 'message' => 'حسابِ مدیر یا پشتیبان نمی‌تواند «فقط فروشگاه» شود؛ «شخصی + فروشگاه» را انتخاب کنید.'];
        }
        $from = isset(self::TYPES[$u['account_type']]) ? (string)$u['account_type'] : 'personal';
        if ($from === $type) {
            return ['ok' => true, 'changed' => false, 'message' => 'حساب از قبل ' . self::TYPES[$type] . ' است.'];
        }

        $pdo->prepare('UPDATE users SET account_type = :t WHERE id = :id')
            ->execute(['t' => $type, 'id' => $targetId]);

        $revoke = ($from === 'business' || $type === 'business');
        if ($revoke) {
            require_once __DIR__ . '/functions.php';
            revokeAllAccessFor($targetId);
        }

        if (class_exists('Audit')) {
            Audit::log('user.account_type', 'user', $targetId, ['from' => $from, 'to' => $type], $actorId, $targetId);
        }

        return [
            'ok'      => true,
            'changed' => true,
            'revoked' => $revoke,
            'message' => 'نوعِ حساب «' . self::TYPES[$type] . '» شد'
                . ($revoke ? ' و کاربر از همه‌ی دستگاه‌ها خارج می‌شود.' : '؛ کاربر از حسابش خارج نمی‌شود.'),
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
        // کدهای رسمی (migration_biz_business_info) — قاعده‌شان `BizCommon::CODES`
        'national_id'    => 20,
        'economic_code'  => 20,
        'reg_no'         => 20,
        'postal_code'    => 20,
    ];

    /** کلیدهای کدِ رسمی در `SETTING_LIMITS` — فقط رقم، نه متنِ آزاد. */
    public const SETTING_CODES = ['national_id', 'economic_code', 'reg_no', 'postal_code'];

    /** کشِ همین درخواست — سرآیند و خودِ صفحه هر دو می‌خوانندش. */
    private static array $settingsCache = [];
    /** پالتِ خامِ همان ردیف — در همان کوئریِ `settings()` خوانده می‌شود. */
    private static array $paletteCache = [];

    /**
     * ⛔ تنها مرجعِ رنگ‌های فروشگاه («همین استایل» — منوی کناریِ طیف‌دار).
     * **اولین کلید پیش‌فرض است** و در `store.css` خودِ `:root` است (بلوکِ
     * `data-st-palette` ندارد). `theme` رنگِ نوارِ وضعیتِ گوشی است و باید
     * همان `--st-rb3`ِ روزِ همان پالت باشد (`test_store_theme` می‌سنجد).
     * ⚠ کهربایی پیش‌فرض ماند تا فروشگاه و حساب لند با هم قاطی نشوند.
     */
    public const PALETTES = [
        'amber'    => ['label' => 'کهربایی', 'theme' => '#431407'],
        'indigo'   => ['label' => 'نیلی',    'theme' => '#2f2a6b'],
        'emerald'  => ['label' => 'زمرد',    'theme' => '#073f2c'],
        'ocean'    => ['label' => 'اقیانوس', 'theme' => '#1e3a8a'],
        'lilac'    => ['label' => 'یاس',     'theme' => '#4c1d95'],
        'graphite' => ['label' => 'شب',      'theme' => '#0e1013'],
    ];

    /** پالتِ این فروشگاه؛ ناشناخته یا خالی → اولین کلید. صفر کوئریِ اضافه. */
    public static function palette(int $userId): string
    {
        self::settings($userId);
        $p = (string)(self::$paletteCache[$userId] ?? '');
        return isset(self::PALETTES[$p]) ? $p : (string)array_key_first(self::PALETTES);
    }

    /** @return array{ok:bool, message:string} */
    public static function savePalette(int $userId, string $key): array
    {
        if (!isset(self::PALETTES[$key])) {
            return ['ok' => false, 'message' => 'این رنگ شناخته نشد.'];
        }
        // ⛔ پیش‌فرض NULL ذخیره می‌شود نه نامش — تا پیش‌فرضِ فردا به همه برسد.
        $val = $key === array_key_first(self::PALETTES) ? null : $key;
        unset(self::$settingsCache[$userId], self::$paletteCache[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, palette) VALUES (:u, :p)
             ON DUPLICATE KEY UPDATE palette = VALUES(palette)'
        )->execute(['u' => $userId, 'p' => $val]);
        return ['ok' => true, 'message' => 'رنگِ فروشگاه «' . self::PALETTES[$key]['label'] . '» شد.'];
    }

    public static function settings(int $userId): array
    {
        if (isset(self::$settingsCache[$userId])) { return self::$settingsCache[$userId]; }
        $out = array_fill_keys(array_keys(self::SETTING_LIMITS), '');
        try {
            // ⚠ `*` نه فهرستِ ستون: ستونِ `palette` (migration_biz_theme) روی
            //    نصبِ عقب‌مانده نیست و نبودنش نباید سربرگ را هم خالی کند.
            $st = Database::getConnection()->prepare(
                'SELECT * FROM biz_settings WHERE user_id = :u LIMIT 1'
            );
            $st->execute(['u' => $userId]);
            $row = $st->fetch();
        } catch (PDOException $e) {
            return $out;    // جدول هنوز نیامده
        }
        if ($row) {
            foreach ($out as $k => $_) { $out[$k] = (string)($row[$k] ?? ''); }
        }
        self::$paletteCache[$userId] = $row ? (string)($row['palette'] ?? '') : '';
        self::$invoiceRaw[$userId]   = $row ? (string)($row['invoice_prefs'] ?? '') : '';
        self::$designRaw[$userId]    = $row ? (string)($row['invoice_design'] ?? '') : '';
        if ($row) { self::$infoReady = array_key_exists('invoice_prefs', $row); }
        return self::$settingsCache[$userId] = $out;
    }

    /** از ردیفِ `SELECT *`ِ `settings()` — بی‌کوئریِ نقشه‌ی ساختار؛ null = هنوز نمی‌دانیم. */
    private static ?bool $infoReady = null;

    /** کدهای رسمی و گزینه‌های فاکتور آمده‌اند؟ (migration_biz_business_info) */
    public static function businessInfoReady(): bool
    {
        return self::$infoReady ??= self::hasColumn('biz_settings', 'invoice_prefs');
    }

    /** ستونی از جدول‌های فروشگاه آمده است؟ — نصبِ migration‌نخورده نشکند. */
    private static function hasColumn(string $table, string $col): bool
    {
        if (function_exists('tableHasColumn')) { return tableHasColumn($table, $col); }
        try {
            Database::getConnection()->query("SELECT `{$col}` FROM `{$table}` LIMIT 0");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /* ============================================================
       گزینه‌های فاکتور — تبِ «فاکتور»ِ تنظیماتِ مالیِ حسابفا
       (migration_biz_business_info → biz_settings.invoice_prefs)
       ============================================================ */

    /**
     * ⛔ تنها مرجعِ گزینه‌های فاکتور. پیش‌فرضِ همه **خاموش** است: هیچ نصبِ
     *    موجودی با آمدنِ این گزینه‌ها رفتارش عوض نمی‌شود.
     *    ⚠ «ثبتِ فاکتور با کمبودِ موجودی» عمداً اینجا نیست: سدِ «موجودی در
     *    هیچ لحظه منفی نمی‌شود» (`BizStock::write()`) تصمیمِ ثابتِ فروشگاه است.
     */
    public const INVOICE_FLAGS = [
        'update_buy_price' => 'پس از صدورِ فاکتورِ خرید، «قیمتِ خرید»ِ کالا به فیِ همان فاکتور به‌روز شود',
        'update_sell_price' => 'پس از صدورِ فاکتورِ فروش، «قیمتِ فروش»ِ کالا به فیِ همان فاکتور به‌روز شود',
        'warn_below_cost'  => 'هشدار وقتی کالا زیرِ بهای خرید فروخته می‌شود',
        'show_profit'      => 'نمایشِ سودِ هر فاکتورِ فروش روی صفحه‌ی فاکتور (روی چاپ نمی‌آید)',
    ];

    private static array $invoiceRaw = [];

    /** JSONِ خامِ طراحیِ فاکتور از همان `SELECT *`ِ `settings()` — صفر کوئریِ اضافه. */
    private static array $designRaw = [];

    public static function invoiceDesignRaw(int $userId): string
    {
        self::settings($userId);
        return self::$designRaw[$userId] ?? '';
    }

    /** بعد از ذخیره‌ی طراحی — همان درخواست مقدارِ تازه را بخواند. */
    public static function forgetSettings(int $userId): void
    {
        unset(self::$settingsCache[$userId], self::$designRaw[$userId], self::$invoiceRaw[$userId]);
    }

    /** @return array{update_buy_price:bool, update_sell_price:bool, warn_below_cost:bool, show_profit:bool, default_party:int} */
    public static function invoicePrefs(int $userId): array
    {
        self::settings($userId);
        $in  = json_decode(self::$invoiceRaw[$userId] ?? '', true);
        $in  = is_array($in) ? $in : [];
        $out = [];
        foreach (self::INVOICE_FLAGS as $k => $_) { $out[$k] = ($in[$k] ?? false) === true; }
        $out['default_party'] = max(0, (int)($in['default_party'] ?? 0));
        return $out;
    }

    /**
     * ⛔ مشتریِ پیش‌فرض فقط اگر طرف‌حسابِ **همین** فروشگاه باشد — شناسه از
     *    فرم می‌آید و نباید مشتریِ فروشگاهِ دیگری روی فاکتور بنشیند.
     */
    public static function saveInvoicePrefs(int $userId, array $in): array
    {
        if (!self::hasColumn('biz_settings', 'invoice_prefs')) {
            return ['ok' => false, 'message' => 'این گزینه‌ها هنوز راه نیفتاده‌اند (migration_biz_business_info).'];
        }
        $clean = [];
        foreach (self::INVOICE_FLAGS as $k => $_) { $clean[$k] = !empty($in[$k]); }
        $party = max(0, (int)($in['default_party'] ?? 0));
        if ($party > 0) {
            $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM biz_parties WHERE id = :id AND user_id = :u AND is_active = 1');
            $st->execute(['id' => $party, 'u' => $userId]);
            if ((int)$st->fetchColumn() === 0) { return ['ok' => false, 'message' => 'مشتریِ پیش‌فرض پیدا نشد.']; }
        }
        $clean['default_party'] = $party;
        unset(self::$settingsCache[$userId], self::$invoiceRaw[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, invoice_prefs) VALUES (:u, :p)
             ON DUPLICATE KEY UPDATE invoice_prefs = VALUES(invoice_prefs)'
        )->execute(['u' => $userId, 'p' => json_encode($clean)]);
        return ['ok' => true, 'message' => 'گزینه‌های فاکتور ذخیره شد.'];
    }

    /** @return array{ok:bool, message:string} */
    public static function saveSettings(int $userId, array $in): array
    {
        require_once __DIR__ . '/biz_catalog.php';
        $clean = [];
        foreach (self::SETTING_LIMITS as $k => $max) {
            $raw = (string)($in[$k] ?? '');
            if (in_array($k, self::SETTING_CODES, true)) {
                $c = BizCommon::code($k, $raw);
                if (!$c['ok']) { return ['ok' => false, 'message' => $c['message']]; }
                $clean[$k] = (string)$c['value'];
                continue;
            }
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

        unset(self::$settingsCache[$userId]);
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO biz_settings (user_id, shop_name, phone, address, invoice_footer)
             VALUES (:u, :n, :p, :a, :f)
             ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name), phone = VALUES(phone),
                                     address = VALUES(address), invoice_footer = VALUES(invoice_footer)'
        )->execute([
            'u' => $userId, 'n' => $clean['shop_name'], 'p' => $clean['phone'],
            'a' => $clean['address'], 'f' => $clean['invoice_footer'],
        ]);
        // ⚠ کدهای رسمی فقط وقتی ستونشان آمده (نصبِ migration‌نخورده نباید بشکند)
        if (self::hasColumn('biz_settings', 'economic_code')) {
            $pdo->prepare(
                'UPDATE biz_settings SET national_id = :ni, economic_code = :ec, reg_no = :rn, postal_code = :pc WHERE user_id = :u'
            )->execute([
                'ni' => $clean['national_id'] !== '' ? $clean['national_id'] : null,
                'ec' => $clean['economic_code'] !== '' ? $clean['economic_code'] : null,
                'rn' => $clean['reg_no'] !== '' ? $clean['reg_no'] : null,
                'pc' => $clean['postal_code'] !== '' ? $clean['postal_code'] : null,
                'u'  => $userId,
            ]);
        }
        return ['ok' => true, 'message' => 'تنظیماتِ فروشگاه ذخیره شد.'];
    }

    /* ============================================================
       تنظیماتِ چاپ — «تنظیم پرینت» (migration_biz_print)
       ============================================================ */

    /** ⛔ تنها مرجعِ گزینه‌ها؛ کلیدِ اولِ هر گروه پیش‌فرض نیست — `PRINT_DEFAULTS` است. */
    public const PRINT_OPTIONS = [
        'paper'  => ['a4' => 'A4', 'a5' => 'A5', '80mm' => 'رولِ ۸۰ میلی‌متری (فیش‌پرینتر)', '58mm' => 'رولِ ۵۸ میلی‌متری'],
        'orient' => ['portrait' => 'عمودی', 'landscape' => 'افقی'],
        'font'   => ['sm' => 'ریز', 'md' => 'معمولی', 'lg' => 'درشت'],
        'margin' => ['narrow' => 'کم', 'normal' => 'معمولی', 'wide' => 'زیاد'],
    ];

    public const PRINT_FLAGS = [
        'show_header'  => 'سربرگ (نامِ فروشگاه)',
        'show_contact' => 'تلفن و نشانی زیرِ سربرگ',
        'show_date'    => 'تاریخ و ساعتِ چاپ',
        'show_footer'  => 'متنِ پای فاکتور (از تنظیماتِ فروشگاه)',
        'show_sign'    => 'جای امضا در پایینِ برگه',
        'show_balance' => 'مانده‌ی قبلی و مانده‌ی کلِ طرف‌حساب روی فاکتور',
    ];

    public const PRINT_DEFAULTS = [
        'paper' => 'a4', 'orient' => 'portrait', 'font' => 'md', 'margin' => 'normal',
        'show_header' => true, 'show_contact' => true, 'show_date' => true, 'show_footer' => true, 'show_sign' => false,
        'show_balance' => true,
    ];

    private static array $printCache = [];

    /**
     * ⛔ مقدارِ ناشناخته (JSONِ خراب، کلیدِ کهنه) بی‌صدا به پیش‌فرض برمی‌گردد،
     *    نه اینکه برگه‌ی چاپ بشکند. نبودِ ستون (migration نخورده) هم همین.
     * @return array<string,string|bool>
     */
    public static function printPrefs(int $userId): array
    {
        if (isset(self::$printCache[$userId])) { return self::$printCache[$userId]; }
        $raw = null;
        try {
            $st = Database::getConnection()->prepare('SELECT print_prefs FROM biz_settings WHERE user_id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            $raw = $st->fetchColumn();
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22' && (string)$e->getCode() !== '42S02') { throw $e; }
        }
        $in = is_string($raw) ? json_decode($raw, true) : null;
        return self::$printCache[$userId] = self::cleanPrint(is_array($in) ? $in : []);
    }

    /** @return array<string,string|bool> */
    private static function cleanPrint(array $in): array
    {
        $out = self::PRINT_DEFAULTS;
        foreach (self::PRINT_OPTIONS as $k => $opts) {
            if (isset($in[$k]) && is_string($in[$k]) && isset($opts[$in[$k]])) { $out[$k] = $in[$k]; }
        }
        foreach (self::PRINT_FLAGS as $k => $_) {
            if (array_key_exists($k, $in)) { $out[$k] = (bool)$in[$k]; }
        }
        return $out;
    }

    /**
     * از یک فرمِ چک‌باکسی: گزینه‌ی نبوده یعنی «خاموش» (تنها تعبیرِ
     * بی‌ابهامِ چک‌باکس)؛ مقدارِ ناشناخته‌ی منو رد می‌شود.
     * @return array{ok:bool, message:string}
     */
    public static function savePrintPrefs(int $userId, array $in): array
    {
        $clean = [];
        foreach (self::PRINT_OPTIONS as $k => $opts) {
            $v = (string)($in[$k] ?? '');
            if (!isset($opts[$v])) { return ['ok' => false, 'message' => 'یکی از گزینه‌های چاپ معتبر نیست.']; }
            $clean[$k] = $v;
        }
        foreach (self::PRINT_FLAGS as $k => $_) { $clean[$k] = !empty($in[$k]); }
        unset(self::$printCache[$userId]);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, print_prefs) VALUES (:u, :p)
             ON DUPLICATE KEY UPDATE print_prefs = VALUES(print_prefs)'
        )->execute(['u' => $userId, 'p' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true, 'message' => 'تنظیماتِ چاپ ذخیره شد.'];
    }
}
