<?php
class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): bool
    {
        if (empty($token) || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function verifyOrFail(?string $token): void
    {
        if (self::validate($token)) { return; }

        http_response_code(403);

        if (self::isJsonRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['success' => false, 'message' => 'نشست شما منقضی شده. صفحه را تازه کنید و دوباره تلاش کنید.'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        self::renderExpiredPage();
        exit;
    }

    /**
     * صفحه‌ی «نشست منقضی شد».
     *
     * چرا این هست: قبلاً اینجا فقط `die()` با یک رشته‌ی خام بود. کاربر
     * روی گوشی یک صفحه‌ی کاملاً سفید با یک خط متنِ بی‌قالب می‌دید، بدون
     * هیچ دکمه‌ای — بن‌بستِ کامل. تنها راه، بستن و باز کردن دستیِ اپ بود.
     *
     * شایع‌ترین علتش هم اصلاً حمله نیست: فرمی که ساعت‌ها روی گوشی باز
     * مانده و در این فاصله نشست منقضی شده. پس متن هم زبانِ حمله ندارد.
     *
     * عمداً بدون وابستگی به style.css نوشته شده تا در هر حالتی — از جمله
     * وقتی این پاسخ وسط یک درخواست نیمه‌کاره می‌آید — درست دیده شود.
     */
    private static function renderExpiredPage(): void
    {
        $loggedIn = !empty($_SESSION['user_id']);
        $base     = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        $href     = $loggedIn ? $base . '/index.php' : $base . '/login.php';
        $label    = $loggedIn ? 'رفتن به خانه' : 'رفتن به صفحه‌ی ورود';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نشست منقضی شد</title>
<style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100dvh;
        display: flex; align-items: center; justify-content: center; padding: 24px;
        font-family: 'Vazirmatn', system-ui, -apple-system, 'Segoe UI', sans-serif;
        background: #f6f7f9; color: #14161a;
    }
    .box {
        width: 100%; max-width: 360px; text-align: center;
        background: #fff; border: 1px solid #e6e8ec; border-radius: 18px;
        padding: 32px 22px 26px;
    }
    .icon {
        width: 62px; height: 62px; margin: 0 auto 16px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: #fff4e5; color: #b26a00;
    }
    h1 { margin: 0 0 8px; font-size: 17px; font-weight: 800; }
    p  { margin: 0 0 20px; font-size: 13px; line-height: 1.9; color: #666d78; }
    .btn {
        display: block; width: 100%; padding: 12px; font: inherit; font-weight: 700;
        color: #fff; background: #14161a; border: 0; border-radius: 12px;
        text-decoration: none; cursor: pointer;
    }
    .btn-ghost {
        margin-top: 9px; background: transparent; color: #666d78;
        border: 1px solid #e6e8ec;
    }
    @media (prefers-color-scheme: dark) {
        body { background: #0b0b0b; color: #f2f3f5; }
        .box { background: #16181c; border-color: #262a31; }
        .icon { background: #3a2f1a; color: #e0a437; }
        p { color: #9aa1ac; }
        .btn { background: #f2f3f5; color: #14161a; }
        .btn-ghost { background: transparent; color: #9aa1ac; border-color: #262a31; }
    }
</style>
</head>
<body>
    <div class="box">
        <div class="icon" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            </svg>
        </div>
        <h1>نشست شما منقضی شد</h1>
        <p>
            این صفحه مدتی باز مانده بود و اعتبارش تمام شده.
            چیزی از دست نرفته — فقط یک بار دیگر تلاش کنید.
        </p>
        <a class="btn" href="{$href}">{$label}</a>
        <button class="btn btn-ghost" type="button" onclick="history.back()">بازگشت</button>
    </div>
</body>
</html>
HTML;
    }

    /**
     * آیا فراخوان جواب JSON می‌خواهد؟
     *
     * ⚠ `X-Requested-With` اینجا لازم است. همه‌ی fetch های `app.js` این
     * سرآیند را می‌فرستند ولی `Accept: application/json` نمی‌فرستند و
     * بدنه‌شان `FormData` است. بدون این شرط، یک ردِ CSRF به آن‌ها
     * صفحه‌ی HTML برمی‌گرداند، `res.json()` می‌شکند، و کاربر فقط
     * «خطا در ارتباط با سرور» می‌بیند — یعنی دلیل واقعی گم می‌شود.
     */
    private static function isJsonRequest(): bool
    {
        if (strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0) {
            return true;
        }
        return (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));
    }
}