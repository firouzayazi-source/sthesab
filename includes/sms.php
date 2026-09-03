<?php
/**
 * ارسال پیامک — لایه‌ی راه‌انداز.
 *
 * ⛔ این پروژه هیچ وابستگیِ بیرونیِ اجباری ندارد و این فایل هم آن قاعده
 *    را نمی‌شکند: بدون `SMS_METHOD` در `config.php` هیچ چیزی فرستاده
 *    نمی‌شود و `isConfigured()` نادرست برمی‌گرداند. همان رفتارِ
 *    `Mailer`: صفحه‌ای که نمی‌تواند کار کند صریح می‌گوید، نه اینکه
 *    وانمود کند فرستاده شد.
 *
 * ⚠ پنلِ پیامکِ ایرانی حساب و اعتبار و خطِ تأییدشده می‌خواهد. تا وقتی
 *   مالکِ نصب آن را نگرفته باشد، «ورود با پیامک» در پنل مدیر روشن هم
 *   بشود کار نمی‌کند — و خودِ آن کلید همین را می‌گوید.
 *
 * ⛔ چرا `curl` **و** `stream` هر دو:
 *   pool این اپ `exec`/`shell_exec` را غیرفعال کرده (قانون جداسازی
 *   سرور)، پس فراخوانیِ `curl` خط‌فرمانی ممکن نیست. افزونه‌ی curl هم
 *   روی هر نصبی نیست. اگر هیچ‌کدام نبود، صریح خطا می‌دهیم؛ سکوت یعنی
 *   کدی که فرستاده نشده ولی «موفق» گزارش شده.
 */

class Sms
{
    /** آخرین خطا، برای نشان دادن به مدیر (نه به کاربرِ ورود). */
    public static string $lastError = '';

    /**
     * پیامک‌هایی که در حالت `log` «فرستاده» شده‌اند.
     *
     * فقط برای توسعه و تست. در `var/sms.log` هم نوشته می‌شوند.
     * @var array<int, array{to:string, text:string}>
     */
    public static array $sent = [];

    public static function isConfigured(): bool
    {
        $m = defined('SMS_METHOD') ? (string)SMS_METHOD : '';
        return $m !== '';
    }

    public static function method(): string
    {
        return defined('SMS_METHOD') ? (string)SMS_METHOD : '';
    }

    /**
     * فرستادن یک پیامک. `true` یعنی پنل قبولش کرد، نه اینکه به گوشی رسید.
     */
    public static function send(string $to, string $text): bool
    {
        self::$lastError = '';

        if (!self::isConfigured()) {
            self::$lastError = 'ارسال پیامک تنظیم نشده است (SMS_METHOD در config.php).';
            return false;
        }
        if ($to === '') {
            self::$lastError = 'شماره‌ی گیرنده خالی است.';
            return false;
        }

        try {
            switch (self::method()) {
                case 'log':         return self::sendLog($to, $text);
                case 'kavenegar':   return self::sendKavenegar($to, $text);
                case 'smsir':       return self::sendSmsIr($to, $text);
                case 'melipayamak': return self::sendMeliPayamak($to, $text);
                default:
                    self::$lastError = 'SMS_METHOD نامعتبر است: ' . self::method();
                    return false;
            }
        } catch (Throwable $e) {
            self::$lastError = 'خطا در ارسال پیامک: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * حالت توسعه: چیزی به هیچ‌جا نمی‌رود، فقط در `var/sms.log` می‌نشیند.
     *
     * ⛔ روی سرورِ واقعی این حالت یعنی **کدها در یک فایلِ متنی روی دیسک
     *    نوشته می‌شوند**. `SmsLogin` به همین دلیل جدا هشدار می‌دهد؛
     *    اینجا هم برای اینکه فراموش نشود تکرار می‌شود.
     */
    private static function sendLog(string $to, string $text): bool
    {
        self::$sent[] = ['to' => $to, 'text' => $text];

        $dir = __DIR__ . '/../var';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents(
            $dir . '/sms.log',
            date('Y-m-d H:i:s') . "  {$to}  {$text}\n",
            FILE_APPEND
        );
        return true;
    }

    private static function sendKavenegar(string $to, string $text): bool
    {
        $key = defined('SMS_API_KEY') ? (string)SMS_API_KEY : '';
        if ($key === '') { self::$lastError = 'SMS_API_KEY تنظیم نشده است.'; return false; }

        $url  = 'https://api.kavenegar.com/v1/' . rawurlencode($key) . '/sms/send.json';
        $body = ['receptor' => $to, 'message' => $text];
        if (defined('SMS_SENDER') && SMS_SENDER !== '') { $body['sender'] = (string)SMS_SENDER; }

        $res = self::post($url, http_build_query($body), ['Content-Type: application/x-www-form-urlencoded']);
        if ($res === null) { return false; }

        // کاوه‌نگار وضعیت را داخل بدنه می‌گذارد، پس ۲۰۰ گرفتن کافی نیست.
        $json = json_decode($res['body'], true);
        $st   = (int)($json['return']['status'] ?? 0);
        if ($st !== 200) {
            self::$lastError = 'پاسخ کاوه‌نگار: ' . ($json['return']['message'] ?? $res['body']);
            return false;
        }
        return true;
    }

    private static function sendSmsIr(string $to, string $text): bool
    {
        $key = defined('SMS_API_KEY') ? (string)SMS_API_KEY : '';
        if ($key === '') { self::$lastError = 'SMS_API_KEY تنظیم نشده است.'; return false; }

        $payload = json_encode([
            'lineNumber'  => defined('SMS_SENDER') ? (string)SMS_SENDER : '',
            'messageText' => $text,
            'mobiles'     => [$to],
        ], JSON_UNESCAPED_UNICODE);

        $res = self::post('https://api.sms.ir/v1/send/bulk', $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $key,
        ]);
        if ($res === null) { return false; }

        $json = json_decode($res['body'], true);
        if ((int)($json['status'] ?? 0) !== 1) {
            self::$lastError = 'پاسخ sms.ir: ' . ($json['message'] ?? $res['body']);
            return false;
        }
        return true;
    }

    private static function sendMeliPayamak(string $to, string $text): bool
    {
        $user = defined('SMS_USER') ? (string)SMS_USER : '';
        $pass = defined('SMS_PASS') ? (string)SMS_PASS : '';
        if ($user === '' || $pass === '') {
            self::$lastError = 'SMS_USER یا SMS_PASS تنظیم نشده است.';
            return false;
        }

        $res = self::post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', http_build_query([
            'username' => $user,
            'password' => $pass,
            'to'       => $to,
            'from'     => defined('SMS_SENDER') ? (string)SMS_SENDER : '',
            'text'     => $text,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if ($res === null) { return false; }

        $json = json_decode($res['body'], true);
        // این پنل شناسه‌ی پیام را برمی‌گرداند؛ مقدارهای یک‌رقمی کدِ خطا هستند.
        $ret = (string)($json['Value'] ?? '');
        if ($ret === '' || strlen($ret) < 2) {
            self::$lastError = 'پاسخ ملی‌پیامک: ' . $res['body'];
            return false;
        }
        return true;
    }

    /**
     * یک POST ساده. `null` یعنی نرسید و `$lastError` پر شده است.
     *
     * @return array{code:int, body:string}|null
     */
    private static function post(string $url, string $body, array $headers): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            $out  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($out === false) { self::$lastError = 'خطای شبکه: ' . $err; return null; }
            return ['code' => $code, 'body' => (string)$out];
        }

        if (!ini_get('allow_url_fopen')) {
            self::$lastError = 'نه افزونه‌ی curl هست و نه allow_url_fopen — ارسال پیامک ممکن نیست.';
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 12,
            'ignore_errors' => true,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        if ($out === false) { self::$lastError = 'خطای شبکه در ارسال پیامک.'; return null; }

        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $code = (int)$m[1]; }
        }
        return ['code' => $code, 'body' => (string)$out];
    }
}
