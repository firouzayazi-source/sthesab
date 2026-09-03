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

require_once __DIR__ . '/functions.php';

/**
 * ⛔ تنظیمات از دو جا می‌آید و ترتیبش عمدی است: **اول `config.php`،
 *    بعد پنل مدیر.**
 *
 *    دلیلش این است که `config.php` روی سرور دستِ مالکِ نصب است و در
 *    گیت هم نمی‌آید؛ اگر پنل رویش می‌نوشت، کسی که به پنل مدیر دسترسی
 *    پیدا کند می‌توانست پیامک‌ها را به پنلِ خودش ببرد. پس هر کلیدی که
 *    در `config.php` مقدار داشته باشد **برنده است** و پنل فقط جای
 *    خالی‌ها را پر می‌کند.
 *
 *    و چرا اصلاً پنل: چون خواستنِ ویرایشِ `config.php` با SSH از کسی
 *    که فقط می‌خواهد پنلِ کاوه‌نگارش را وصل کند، یعنی این قابلیت عملاً
 *    وصل نمی‌شود.
 *
 * ⚠ کلیدِ پنل در `app_settings` می‌نشیند، یعنی **در بکاپِ دیتابیس هم
 *   می‌آید**. اگر این برایتان قابل قبول نیست، همان را در `config.php`
 *   بگذارید که در دامپ نیست.
 */
function smsSetting(string $key, string $constName): string
{
    if (defined($constName) && (string)constant($constName) !== '') {
        return (string)constant($constName);
    }
    return (string)getSetting($key, '');
}

/** کلیدهای پنل که مدیر می‌تواند از صفحه‌ی «مدیریت کاربران» تنظیمشان کند. */
const SMS_SETTING_KEYS = [
    'sms_method'  => 'SMS_METHOD',
    'sms_api_key' => 'SMS_API_KEY',
    'sms_sender'  => 'SMS_SENDER',
    'sms_user'    => 'SMS_USER',
    'sms_pass'    => 'SMS_PASS',
    'sms_pattern' => 'SMS_PATTERN',
    'sms_text'    => 'SMS_TEXT',
];

/**
 * متنِ پیامکِ کدِ ورود در حالتِ **متن آزاد**.
 *
 * ⚠ در حالتِ الگو اصلاً استفاده نمی‌شود — متن را خودِ الگو دارد.
 * `{code}` جای کد و `{ttl}` جای مهلت (دقیقه) می‌نشیند.
 */
function smsCodeText(string $code, int $ttlMinutes): string
{
    $tpl = smsSetting('sms_text', 'SMS_TEXT');
    if ($tpl === '') {
        $app = defined('APP_NAME') ? APP_NAME : 'دفتر مالی';
        $tpl = "کد ورود به {$app}: {code}\nتا {ttl} دقیقه اعتبار دارد.";
    }
    return str_replace(['{code}', '{ttl}'], [$code, (string)$ttlMinutes], $tpl);
}

/** پنل‌هایی که پشتیبانی می‌شوند و اینکه هرکدام چه می‌خواهد. */
function smsProviders(): array
{
    return [
        ''            => ['label' => 'خاموش',        'needs' => []],
        'kavenegar'   => ['label' => 'کاوه‌نگار',     'needs' => ['sms_api_key']],
        'smsir'       => ['label' => 'sms.ir',       'needs' => ['sms_api_key', 'sms_sender']],
        'melipayamak' => ['label' => 'ملی‌پیامک',     'needs' => ['sms_user', 'sms_pass']],
        'log'         => ['label' => 'فقط ثبت در فایل (توسعه)', 'needs' => []],
    ];
}

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
        return self::method() !== '';
    }

    public static function method(): string
    {
        return smsSetting('sms_method', 'SMS_METHOD');
    }

    /** آیا این پنل هر چیزی که لازم دارد را دارد؟ '' یعنی آماده است. */
    public static function missingFor(string $method): string
    {
        $key = smsSetting('sms_api_key', 'SMS_API_KEY');

        // ⚠ ملی‌پیامک شرطش «یا/یا» است، نه فهرستِ ثابت: یا کلیدِ حسابِ
        //   جدید، یا نام کاربری و رمزِ حسابِ قدیمی. با فهرستِ ثابت،
        //   حسابِ جدید همیشه «ناقص» گزارش می‌شد در حالی که کار می‌کرد.
        if ($method === 'melipayamak') {
            $user = smsSetting('sms_user', 'SMS_USER');
            $pass = smsSetting('sms_pass', 'SMS_PASS');
            if ($user !== '' && $pass === '' && $key === '') {
                return 'رمز عبور پنل وارد نشده است.';
            }
            if ($user === '' && $key === '') {
                return 'کلید API وارد نشده است.';
            }
            if (smsSetting('sms_pattern', 'SMS_PATTERN') === '') {
                return 'کد بادی الگو وارد نشده است — برای کد ورود عملاً لازم است.';
            }
            return '';
        }

        $providers = smsProviders();
        if (!isset($providers[$method])) { return 'پنل ناشناخته است.'; }

        $labels = [
            'sms_api_key' => 'کلید API',
            'sms_sender'  => 'شماره خط',
            'sms_user'    => 'نام کاربری پنل',
            'sms_pass'    => 'رمز پنل',
        ];
        $missing = [];
        foreach ($providers[$method]['needs'] as $need) {
            if (smsSetting($need, SMS_SETTING_KEYS[$need]) === '') { $missing[] = $labels[$need]; }
        }
        return $missing === [] ? '' : implode(' و ', $missing) . ' وارد نشده است.';
    }

    /**
     * ⛔ فرستادنِ **کدِ ورود** — و این با `send()` یکی نیست.
     *
     *    پنل‌های ایرانی برای پیامکِ خدماتی (کدِ ورود) یک «الگو» یا
     *    «خدمات پایه» دارند که از قبل تأییدش می‌کنند: ارزان‌تر است، به
     *    خطِ اختصاصی نیاز ندارد، و مهم‌تر از همه شبانه‌روزی می‌رود در
     *    حالی که پیامکِ تبلیغاتیِ متن‌آزاد ممکن است اصلاً تحویل نشود.
     *
     *    اگر `sms_pattern` پر باشد از همان مسیر می‌رود و **فقط خودِ کد**
     *    به‌عنوان پارامتر فرستاده می‌شود — نه متنِ کامل، چون متن را خودِ
     *    الگو دارد. اگر خالی باشد، همان پیامکِ متن‌آزادِ قبلی می‌رود.
     */
    public static function sendCode(string $to, string $code, string $fullText): bool
    {
        $pattern = smsSetting('sms_pattern', 'SMS_PATTERN');
        if ($pattern === '') { return self::send($to, $fullText); }

        self::$lastError = '';
        if (!self::isConfigured()) {
            self::$lastError = 'پنل پیامک تنظیم نشده است.';
            return false;
        }

        try {
            switch (self::method()) {
                case 'log':         return self::sendLog($to, $fullText . ' [الگوی ' . $pattern . ']');
                case 'melipayamak': return self::sendMeliPattern($to, $code, $pattern);
                case 'kavenegar':   return self::sendKavenegarLookup($to, $code, $pattern);
                case 'smsir':       return self::sendSmsIrVerify($to, $code, $pattern);
                default:
                    self::$lastError = 'پنلِ پیامکِ ناشناخته: ' . self::method();
                    return false;
            }
        } catch (Throwable $e) {
            self::$lastError = 'خطا در ارسال پیامک: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * ملی‌پیامک — «خدمات پایه» (پیامکِ ساخته‌شده در پنل).
     *
     * ⚠ `bodyId` همان شناسه‌ی عددیِ متنی است که در پنل ساخته و تأیید
     *   شده، نه شماره‌ی خط. اینجا `from` اصلاً فرستاده نمی‌شود؛ خودِ
     *   سرویس خطش را انتخاب می‌کند — به همین دلیل بدونِ خطِ اختصاصی هم
     *   کار می‌کند.
     *
     * ⚠ چند پارامتری با `;` جدا می‌شود. الگوی کدِ ورود یک پارامتر
     *   بیشتر ندارد، پس فقط خودِ کد می‌رود.
     */
    private static function sendMeliPattern(string $to, string $code, string $bodyId): bool
    {
        $key  = smsSetting('sms_api_key', 'SMS_API_KEY');
        $user = smsSetting('sms_user', 'SMS_USER');

        // ⛔ ملی‌پیامک **دو نوع حساب** دارد و مسیرشان یکی نیست. نبودِ
        //    این تفکیک همان چیزی بود که پیامک را نمی‌فرستاد:
        //
        //    • حسابِ جدید فقط یک «کلید وب‌سرویس» می‌دهد و از کنسول کار
        //      می‌کند: `console.melipayamak.com/api/send/shared/{key}`.
        //    • حسابِ قدیمی نام کاربری و رمزِ پنل دارد و از REST قدیمی:
        //      `rest.payamak-panel.com/.../BaseServiceNumber`.
        //
        //    ⚠ «نام کاربری» پر باشد یعنی روشِ قدیمی. پس اگر کلید دارید
        //      آن فیلد را خالی بگذارید — پر بودنش مسیر را عوض می‌کند.
        if ($user === '') {
            if ($key === '') {
                self::$lastError = 'کلید API ملی‌پیامک تنظیم نشده است.';
                return false;
            }
            $res = self::post(
                'https://console.melipayamak.com/api/send/shared/' . rawurlencode($key),
                json_encode(['bodyId' => (int)$bodyId, 'to' => $to, 'args' => [$code]],
                            JSON_UNESCAPED_UNICODE),
                ['Content-Type: application/json', 'Accept: application/json']
            );
            if ($res === null) { return false; }

            // کنسول شناسه‌ی پیام را در `recId` برمی‌گرداند. صفر یا نبودنش
            // یعنی نرفته، حتی اگر کدِ HTTP دویست باشد.
            $json = json_decode($res['body'], true);
            if ((int)($json['recId'] ?? 0) <= 0) {
                self::$lastError = 'پاسخ ملی‌پیامک: '
                    . ($json['status'] ?? $json['message'] ?? $res['body']);
                return false;
            }
            return true;
        }

        $pass = smsSetting('sms_pass', 'SMS_PASS');
        if ($pass === '') { $pass = $key; }   // روشِ قدیمی: رمز در همان فیلدِ کلید
        if ($pass === '') {
            self::$lastError = 'رمزِ پنلِ ملی‌پیامک تنظیم نشده است.';
            return false;
        }

        $res = self::post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', http_build_query([
            'username' => $user,
            'password' => $pass,
            'text'     => $code,
            'to'       => $to,
            'bodyId'   => $bodyId,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if ($res === null) { return false; }

        // ⚠ ۲۰۰ گرفتن کافی نیست: وضعیت داخلِ بدنه است. RetStatus = 1
        //   یعنی پذیرفته شد؛ هر چیز دیگری خطاست.
        $json = json_decode($res['body'], true);
        if ((int)($json['RetStatus'] ?? 0) !== 1) {
            self::$lastError = 'پاسخ ملی‌پیامک: '
                . ($json['StrRetStatus'] ?? $res['body']);
            return false;
        }
        return true;
    }

    /** کاوه‌نگار — `verify/lookup` با نامِ الگو. */
    private static function sendKavenegarLookup(string $to, string $code, string $template): bool
    {
        $key = smsSetting('sms_api_key', 'SMS_API_KEY');
        if ($key === '') { self::$lastError = 'کلید API کاوه‌نگار تنظیم نشده است.'; return false; }

        $url = 'https://api.kavenegar.com/v1/' . rawurlencode($key) . '/verify/lookup.json';
        $res = self::post($url, http_build_query([
            'receptor' => $to,
            'token'    => $code,
            'template' => $template,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if ($res === null) { return false; }

        $json = json_decode($res['body'], true);
        if ((int)($json['return']['status'] ?? 0) !== 200) {
            self::$lastError = 'پاسخ کاوه‌نگار: ' . ($json['return']['message'] ?? $res['body']);
            return false;
        }
        return true;
    }

    /**
     * sms.ir — `send/verify` با شناسه‌ی الگو.
     *
     * ⚠ نامِ پارامتر باید با همان چیزی که در الگوی پنل تعریف شده یکی
     *   باشد. `CODE` رایج‌ترین است و اگر الگوی شما نامِ دیگری دارد،
     *   الگو را با همین نام بسازید.
     */
    private static function sendSmsIrVerify(string $to, string $code, string $templateId): bool
    {
        $key = smsSetting('sms_api_key', 'SMS_API_KEY');
        if ($key === '') { self::$lastError = 'کلید API sms.ir تنظیم نشده است.'; return false; }

        $payload = json_encode([
            'mobile'     => $to,
            'templateId' => (int)$templateId,
            'parameters' => [['name' => 'CODE', 'value' => $code]],
        ], JSON_UNESCAPED_UNICODE);

        $res = self::post('https://api.sms.ir/v1/send/verify', $payload, [
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

    /**
     * فرستادن یک پیامک. `true` یعنی پنل قبولش کرد، نه اینکه به گوشی رسید.
     */
    public static function send(string $to, string $text): bool
    {
        self::$lastError = '';

        if (!self::isConfigured()) {
            self::$lastError = 'پنل پیامک تنظیم نشده است (در «مدیریت کاربران» یا config.php).';
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
                    self::$lastError = 'پنل پیامکِ ناشناخته: ' . self::method();
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
        $key = smsSetting('sms_api_key', 'SMS_API_KEY');
        if ($key === '') { self::$lastError = 'کلید API کاوه‌نگار تنظیم نشده است.'; return false; }

        $url  = 'https://api.kavenegar.com/v1/' . rawurlencode($key) . '/sms/send.json';
        $body = ['receptor' => $to, 'message' => $text];
        $sender = smsSetting('sms_sender', 'SMS_SENDER');
        if ($sender !== '') { $body['sender'] = $sender; }

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
        $key = smsSetting('sms_api_key', 'SMS_API_KEY');
        if ($key === '') { self::$lastError = 'کلید API sms.ir تنظیم نشده است.'; return false; }

        $payload = json_encode([
            'lineNumber'  => smsSetting('sms_sender', 'SMS_SENDER'),
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
        $key  = smsSetting('sms_api_key', 'SMS_API_KEY');
        $user = smsSetting('sms_user', 'SMS_USER');
        $pass = smsSetting('sms_pass', 'SMS_PASS');
        if ($pass === '') { $pass = $key; }

        // حسابِ جدید (فقط کلید): متنِ آزاد از کنسول می‌رود.
        if ($user === '') {
            if ($key === '') {
                self::$lastError = 'کلید API ملی‌پیامک تنظیم نشده است.';
                return false;
            }
            $res = self::post(
                'https://console.melipayamak.com/api/send/simple/' . rawurlencode($key),
                json_encode(['from' => smsSetting('sms_sender', 'SMS_SENDER'),
                             'to' => $to, 'text' => $text], JSON_UNESCAPED_UNICODE),
                ['Content-Type: application/json', 'Accept: application/json']
            );
            if ($res === null) { return false; }
            $json = json_decode($res['body'], true);
            if ((int)($json['recId'] ?? 0) <= 0) {
                self::$lastError = 'پاسخ ملی‌پیامک: '
                    . ($json['status'] ?? $json['message'] ?? $res['body']);
                return false;
            }
            return true;
        }

        if ($pass === '') {
            self::$lastError = 'رمزِ پنلِ ملی‌پیامک تنظیم نشده است.';
            return false;
        }

        $res = self::post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', http_build_query([
            'username' => $user,
            'password' => $pass,
            'to'       => $to,
            'from'     => smsSetting('sms_sender', 'SMS_SENDER'),
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
