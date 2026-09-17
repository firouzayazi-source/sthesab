<?php
/**
 * هسته‌ی api/v1 — پاکت پاسخ، خواندن ورودی، و خطاها.
 *
 * ⚠ قاعده‌ی مرکزی: **v1 هرگز به شکلی که مشتریِ قدیمی را بشکند تغییر
 * نمی‌کند.**
 *
 * اپی که روی گوشی کاربر نصب شده را نمی‌شود مجبور به به‌روزرسانی کرد.
 * پوشه‌ی `api/` (بدون نسخه) لایه‌ی داخلیِ AJAX خودِ سایت است و آزادانه
 * با صفحه‌ها عوض می‌شود — چهار تای آن اصلاً HTML برمی‌گردانند، نه JSON.
 * اگر اپ موبایل روی همان‌ها می‌نشست، هر تغییر در سایت اپ‌های نصب‌شده را
 * می‌شکست. پس این دو عمداً از هم جدا هستند.
 *
 * مجازِ v1:  افزودن کلید تازه به پاسخ، افزودن پارامتر اختیاری، افزودن
 *            اندپوینت تازه.
 * ممنوعِ v1: حذف یا تغییر نامِ کلید، تغییر نوع یا معنای یک مقدار،
 *            اجباری کردن پارامتری که پیش‌تر اختیاری بود، تغییر کد وضعیت.
 * هر کدام از این‌ها لازم شد → `api/v2/`، و v1 تا وقتی اپ‌های قدیمی زنده‌اند
 * سر جایش می‌ماند.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/api_auth.php';

class Api
{
    public const VERSION = 1;

    /** بدنه‌ی JSON درخواست، یک بار خوانده و نگه داشته می‌شود. */
    private static ?array $body = null;

    /**
     * پاسخ موفق.
     *
     * پاکت همیشه یک شکل دارد: { ok, data } — تا مشتری بتواند بدون
     * دانستنِ اندپوینت بفهمد موفق بوده یا نه.
     */
    public static function ok($data = null, int $status = 200): void
    {
        self::send(['ok' => true, 'data' => $data], $status);
    }

    /**
     * پاسخ خطا: { ok: false, error: { code, message } }
     *
     * `code` ماشین‌خوان است و ترجمه نمی‌شود؛ `message` فارسیِ قابل نمایش.
     * اپ باید روی `code` تصمیم بگیرد، نه روی متن — وگرنه اصلاحِ یک غلط
     * املایی در پیام، منطق اپ را می‌شکند.
     */
    public static function fail(string $code, string $message, int $status = 400, array $extra = []): void
    {
        // ⚠ `request_id` کلیدِ **افزوده** است (مجازِ v1): اپ می‌تواند آن
        //   را در پیامِ خطا نشان بدهد و کاربر گزارشش کند.
        self::send(['ok' => false, 'error' => ['code' => $code, 'message' => $message,
            'request_id' => Log::requestId()] + $extra], $status);
    }

    private static function send(array $payload, int $status): void
    {
        Log::stage('response');
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-API-Version: ' . self::VERSION);
            // پاسخ‌های API شخصی‌اند و هرگز نباید کش شوند
            header('Cache-Control: no-store, private');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** متد HTTP درخواست. */
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** اگر متد در فهرست مجاز نبود، ۴۰۵ با سرآیند Allow. */
    public static function requireMethod(array $allowed): void
    {
        if (in_array(self::method(), $allowed, true)) { return; }
        if (!headers_sent()) { header('Allow: ' . implode(', ', $allowed)); }
        self::fail('method_not_allowed', 'این متد برای این آدرس مجاز نیست.', 405);
    }

    /**
     * بدنه‌ی درخواست.
     *
     * هم JSON را می‌فهمد (چیزی که اپ موبایل می‌فرستد) و هم فرمِ معمولی
     * را، تا آزمودن با curl ساده بماند.
     */
    public static function body(): array
    {
        if (self::$body !== null) { return self::$body; }

        $type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                self::fail('invalid_json', 'بدنه‌ی درخواست JSON معتبر نیست.', 400);
            }
            self::$body = $decoded;
        } else {
            self::$body = $_POST;
        }

        return self::$body;
    }

    /** یک مقدار از بدنه، به‌صورت رشته‌ی trim شده. */
    public static function input(string $key, string $default = ''): string
    {
        $v = self::body()[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    /** یک مقدار از query string. */
    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    /**
     * کاربرِ احراز هویت‌شده، وگرنه ۴۰۱.
     *
     * هر اندپوینتی جز login باید از همین رد شود. شناسه‌ی کاربر **همیشه**
     * از توکن می‌آید، هرگز از ورودی — همان قاعده‌ای که در کل پروژه هست.
     */
    public static function requireUser(): int
    {
        if (!ApiAuth::available()) {
            self::fail('api_unavailable',
                'سرویس هنوز آماده نیست. migration اجرا نشده است.', 503);
        }

        $id = ApiAuth::userId();
        if ($id === null) {
            if (!headers_sent()) { header('WWW-Authenticate: Bearer'); }
            self::fail('unauthenticated', 'برای این درخواست باید وارد شوید.', 401);
        }

        return $id;
    }

    /**
     * تاریخ را هم میلادی و هم شمسیِ آماده می‌فرستد.
     *
     * ⚠ این عمدی است و مهم. منطق تبدیل شمسی الان **دو بار** در پروژه
     * هست (PHP و assets/js/jalali-datepicker.js) و tests/test_jalali_parity
     * هم‌خوانی‌شان را می‌سنجد. اگر اپ موبایل خودش تبدیل کند، می‌شود
     * پیاده‌سازی سوم — بیرون از پوشش آن تست، و دیر یا زود یک روز اختلاف
     * پیدا می‌کند. پس تبدیل همیشه اینجا انجام می‌شود و اپ فقط نمایش
     * می‌دهد.
     */
    public static function date(?string $gregorian): ?array
    {
        if (!$gregorian) { return null; }
        return [
            'iso'    => $gregorian,        // 2026-08-31 — برای مرتب‌سازی و ارسال دوباره
            'jalali' => toJalali($gregorian),
        ];
    }

    /**
     * مبلغ همیشه عدد صحیح است، هرگز اعشاری.
     *
     * ستون‌ها BIGINT اند و واحد تومان. اگر مبلغ به شکل float از JSON رد
     * شود، مقدارهای بزرگ دقتشان را از دست می‌دهند و جمع‌ها نمی‌خوانند.
     */
    public static function money($value): int
    {
        return (int)$value;
    }
}
