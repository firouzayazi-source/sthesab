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
    'sms_method'    => 'SMS_METHOD',
    'sms_api_key'   => 'SMS_API_KEY',
    'sms_sender'    => 'SMS_SENDER',
    'sms_user'      => 'SMS_USER',
    'sms_pass'      => 'SMS_PASS',
    'sms_pattern'   => 'SMS_PATTERN',
    'sms_text'      => 'SMS_TEXT',
    'sms_meli_mode' => 'SMS_MELI_MODE',
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

/**
 * ⛔ نشانه‌ی «ثبت شده» برای مقدارِ محرمانه — ستاره به‌علاوه‌ی چند حرفِ آخر.
 *
 * فرم مقدارِ کلید و رمز را نشان نمی‌دهد (و نباید بدهد)، ولی فیلدِ خالی
 * بعد از ذخیره دقیقاً شبیهِ «ذخیره نشد» است. همین یک ابهام باعث شد کاربر
 * فکر کند کلید پاک می‌شود، در حالی که ذخیره شده بود.
 *
 * ⚠ برای کلید چهار حرفِ آخر می‌آید (تا بشود فهمید **کدام** کلید ثبت
 *   شده)، ولی برای رمز عبور هیچ حرفی — رمز را نباید حتی تکه‌تکه نشان داد.
 */
function smsMaskedHint(string $key, string $constName, bool $showTail = true): string
{
    $v = smsSetting($key, $constName);
    if ($v === '') { return ''; }
    if (!$showTail || mb_strlen($v) <= 4) { return str_repeat('•', 10); }
    return str_repeat('•', 10) . mb_substr($v, -4);
}

/**
 * ⛔ کلیدِ چسبانده‌شده ممکن است یک **آدرس کامل** باشد، نه خودِ کلید.
 *
 * کنسول ملی‌پیامک کلید را داخل یک آدرسِ نمونه نشان می‌دهد، و کپی کردنِ
 * کلِ آن طبیعی‌ترین کارِ ممکن است. نتیجه‌اش «کلید کنسول معتبر نیست» بود —
 * پیامی که درست است ولی کاربر را دنبالِ کلیدِ تازه می‌فرستد، در حالی که
 * کلیدش درست بوده و فقط چند تکه‌ی اضافه همراهش آمده.
 *
 * فاصله و نقل‌قول و اسلشِ آخر هم پاک می‌شوند — همه‌شان از کپی/پیست می‌آیند.
 */
function smsNormalizeSecret(string $v): string
{
    $v = trim($v, " \t\n\r\0\x0B\"'");
    if (preg_match('~^https?://~i', $v)) {
        $path = parse_url($v, PHP_URL_PATH) ?: '';
        $seg  = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
        if ($seg !== []) { $v = end($seg); }
    }
    return trim($v, " \t\n\r\0\x0B\"'/");
}

/**
 * ⛔ ارسالِ آزمایشی — و اگر ملی‌پیامک یک مسیر را رد کرد، **مسیرِ دیگر
 *    هم آزموده می‌شود**.
 *
 *    دلیلش این است که «نوع حساب» چیزی است که خودِ صاحبِ حساب هم اغلب
 *    نمی‌داند. پیامِ «کلید کنسول معتبر نیست» درست است ولی کاربر را
 *    دنبالِ کلیدِ تازه می‌فرستد، در حالی که ممکن است اصلاً کلیدی نداشته
 *    باشد و حسابش از نوعِ قدیمی باشد. اینجا به‌جای اینکه از او بپرسیم،
 *    خودمان امتحان می‌کنیم و اگر مسیرِ دوم جواب داد **همان را ذخیره
 *    می‌کنیم** — دفعه‌ی بعد دیگر لازم نیست کسی حدس بزند.
 *
 * ⚠ فقط وقتی مسیرِ دوم آزموده می‌شود که اعتبارنامه‌اش واقعاً پر باشد،
 *   وگرنه یک خطای بی‌ربطِ دوم به خطای اول اضافه می‌شود.
 *
 * @return array{0: bool, 1: string}  موفق بود؟ و پیامی که به مدیر نشان داده می‌شود.
 */
function smsTestSend(string $to): array
{
    $code = '12345';
    $okMsg = 'پیامک آزمایشی به پنل تحویل داده شد. اگر نرسید، از خودِ پنل وضعیت ارسال را ببینید.';

    if (Sms::sendCode($to, $code, smsCodeText($code, 3))) { return [true, $okMsg]; }

    $first = Sms::$lastError ?: 'پنل دلیلی نگفت.';
    if (Sms::method() !== 'melipayamak') { return [false, 'ارسال نشد — ' . $first]; }

    $mode  = Sms::meliMode();
    $other = $mode === 'console' ? 'panel' : 'console';

    // آیا مسیرِ دوم اصلاً اعتبارنامه دارد؟
    $key  = smsSetting('sms_api_key', 'SMS_API_KEY');
    $user = smsSetting('sms_user', 'SMS_USER');
    $pass = smsSetting('sms_pass', 'SMS_PASS');
    if ($pass === '') { $pass = $key; }
    $canTry = $other === 'console' ? $key !== '' : ($user !== '' && $pass !== '');
    if (!$canTry) {
        return [false, 'ارسال نشد — ' . $first];
    }

    // ⚠ فقط برای همین یک تلاش عوض می‌شود؛ اگر جواب نداد به حالت اول
    //   برمی‌گردد و هیچ چیزی در تنظیمات عوض نمی‌ماند.
    setSetting('sms_meli_mode', $other);
    if (Sms::sendCode($to, $code, smsCodeText($code, 3))) {
        $label = $other === 'panel' ? 'حساب قدیمی (نام کاربری و رمز)' : 'حساب جدید (کلید وب‌سرویس)';
        return [true, 'مسیرِ اول جواب نداد، ولی مسیرِ دوم داد. «نوع حساب» روی «'
                     . $label . '» تنظیم شد. ' . $okMsg];
    }
    $second = Sms::$lastError ?: 'پنل دلیلی نگفت.';
    setSetting('sms_meli_mode', $mode);

    return [false, 'هر دو مسیر آزموده شد و هیچ‌کدام جواب نداد. '
                 . 'مسیرِ اول: ' . $first . ' | مسیرِ دوم: ' . $second];
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

    /**
     * ⛔ نوعِ حسابِ ملی‌پیامک — **تنها جایی که این تصمیم گرفته می‌شود**
     *    (مثل `categoryScopeSql()`). هر جایی که به ملی‌پیامک وصل می‌شود
     *    باید از همین رد شود، وگرنه فرم یک نوع را نشان می‌دهد و
     *    فرستنده نوعِ دیگری را صدا می‌زند.
     *
     * دو نوع حساب هست و آدرسشان یکی نیست:
     *   `console` → حسابِ جدید، فقط «کلید وب‌سرویس»، کنسول.
     *   `panel`   → حسابِ قدیمی، نام کاربری و رمزِ پنل، REST قدیمی.
     *
     * ⛔ پیش از این این تصمیم از «خالی بودنِ نام کاربری» **حدس زده
     *    می‌شد**، و همان یک خطِ نادیدنی کلِ خرابی را ساخت: مالکِ یک
     *    حسابِ قدیمی که فقط کلید را پر کرده بود، بی‌آنکه بداند به کنسول
     *    فرستاده می‌شد و جواب می‌گرفت «کلید کنسول معتبر نیست» — پیامی که
     *    درست است ولی هیچ نمی‌گوید کدام مسیر رفته و چرا. حالا کلید صریح
     *    است و روی فرم دیده می‌شود.
     *
     * ⚠ نبودنِ مقدار یعنی نصبِ قدیمی: همان حدسِ قبلی را می‌زنیم تا رفتارِ
     *   نصب‌هایی که امروز کار می‌کنند عوض نشود.
     */
    public static function meliMode(): string
    {
        $mode = smsSetting('sms_meli_mode', 'SMS_MELI_MODE');
        if ($mode === 'console' || $mode === 'panel') { return $mode; }
        return smsSetting('sms_user', 'SMS_USER') !== '' ? 'panel' : 'console';
    }

    /** آیا این پنل هر چیزی که لازم دارد را دارد؟ '' یعنی آماده است. */
    public static function missingFor(string $method): string
    {
        $key = smsSetting('sms_api_key', 'SMS_API_KEY');

        // ⚠ ملی‌پیامک شرطش «یا/یا» است، نه فهرستِ ثابت: یا کلیدِ حسابِ
        //   جدید، یا نام کاربری و رمزِ حسابِ قدیمی. با فهرستِ ثابت،
        //   حسابِ جدید همیشه «ناقص» گزارش می‌شد در حالی که کار می‌کرد.
        if ($method === 'melipayamak') {
            if (self::meliMode() === 'panel') {
                $user = smsSetting('sms_user', 'SMS_USER');
                $pass = smsSetting('sms_pass', 'SMS_PASS');
                if ($pass === '') { $pass = $key; }   // نصب‌های قدیمی: رمز در فیلدِ کلید
                $missing = [];
                if ($user === '') { $missing[] = 'نام کاربری پنل'; }
                if ($pass === '') { $missing[] = 'رمز عبور پنل'; }
                if ($missing !== []) { return implode(' و ', $missing) . ' وارد نشده است.'; }
            } elseif ($key === '') {
                return 'کلید وب‌سرویس وارد نشده است.';
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

        // ⛔ ملی‌پیامک **دو نوع حساب** دارد و مسیرشان یکی نیست:
        //
        //    • حسابِ جدید فقط یک «کلید وب‌سرویس» می‌دهد و از کنسول کار
        //      می‌کند: `console.melipayamak.com/api/send/shared/{key}`.
        //    • حسابِ قدیمی نام کاربری و رمزِ پنل دارد و از REST قدیمی:
        //      `rest.payamak-panel.com/.../BaseServiceNumber`.
        //
        //    کدام‌یک، از `meliMode()` می‌آید — یک تنظیمِ صریح روی فرم،
        //    نه حدس از روی خالی بودنِ نام کاربری.
        if (self::meliMode() === 'console') {
            if ($key === '') {
                self::$lastError = 'کلید وب‌سرویس ملی‌پیامک تنظیم نشده است.';
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
                self::$lastError = self::meliError(
                    'console',
                    (string)($json['status'] ?? $json['message'] ?? $res['body'])
                );
                return false;
            }
            return true;
        }

        $pass = smsSetting('sms_pass', 'SMS_PASS');
        if ($pass === '') { $pass = $key; }   // نصب‌های قدیمی: رمز در همان فیلدِ کلید
        if ($user === '' || $pass === '') {
            self::$lastError = 'نام کاربری یا رمزِ پنلِ ملی‌پیامک تنظیم نشده است.';
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
            self::$lastError = self::meliError(
                'panel',
                (string)($json['StrRetStatus'] ?? $res['body'])
            );
            return false;
        }
        return true;
    }

    /**
     * ⛔ خطای خامِ پنل + «حالا چه کار کنم».
     *
     * خطای خام لازم است (بدونش «پیامک ارسال نشد» یعنی حدس زدن بین کلیدِ
     * غلط، الگوی تأییدنشده و اعتبارِ تمام‌شده) ولی **کافی نیست**:
     * «کلید کنسول معتبر نیست» درست است و هیچ نمی‌گوید که مشکل می‌تواند
     * اصلاً نوعِ حساب باشد، نه خودِ کلید. مالکِ یک حسابِ قدیمی همین را
     * می‌دید و دنبالِ کلیدِ تازه می‌گشت، در حالی که اصلاً کلیدی ندارد.
     *
     * ⚠ تطبیق روی زیررشته است چون متنِ پنل نسخه به نسخه عوض می‌شود؛
     *   نخوردنش فقط یعنی راهنمایی اضافه نمی‌شود، نه اینکه خطا گم شود.
     */
    private static function meliError(string $mode, string $raw): string
    {
        $raw  = trim($raw) !== '' ? trim($raw) : 'پاسخِ خالی از پنل.';
        $hint = '';

        if ($mode === 'console') {
            if (mb_strpos($raw, 'کلید') !== false || mb_stripos($raw, 'key') !== false) {
                $hint = 'این مقدار به‌عنوان «کلید وب‌سرویسِ کنسول» پذیرفته نشد. '
                      . 'اگر حسابتان از نوع قدیمی است (با نام کاربری و رمزِ پنل وارد '
                      . 'می‌شوید و کلیدی ندارید)، «نوع حساب» را روی «حساب قدیمی» '
                      . 'بگذارید و نام کاربری و رمز را پر کنید.';
            }
        } else {
            if (mb_strpos($raw, 'کاربر') !== false || mb_strpos($raw, 'رمز') !== false
                || mb_stripos($raw, 'invalid') !== false) {
                $hint = 'نام کاربری یا رمزِ پنل پذیرفته نشد. اگر حسابتان از نوع '
                      . 'جدید است و فقط کلید وب‌سرویس دارید، «نوع حساب» را روی '
                      . '«حساب جدید» بگذارید.';
            }
        }

        $where = $mode === 'console' ? 'کنسول (حساب جدید)' : 'وب‌سرویس قدیمی (حساب قدیمی)';
        return 'پاسخ ملی‌پیامک از مسیر ' . $where . ': ' . $raw
             . ($hint === '' ? '' : ' — ' . $hint);
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

        // ⚠ از همان `meliMode()` رد می‌شود که مسیرِ الگو از آن رد می‌شود.
        //   دو تصمیمِ جدا یعنی فرم یک نوعِ حساب را نشان بدهد و متنِ آزاد
        //   نوعِ دیگری را صدا بزند — و آن خرابی بی‌صداست.
        if (self::meliMode() === 'console') {
            if ($key === '') {
                self::$lastError = 'کلید وب‌سرویس ملی‌پیامک تنظیم نشده است.';
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
                self::$lastError = self::meliError(
                    'console',
                    (string)($json['status'] ?? $json['message'] ?? $res['body'])
                );
                return false;
            }
            return true;
        }

        if ($user === '' || $pass === '') {
            self::$lastError = 'نام کاربری یا رمزِ پنلِ ملی‌پیامک تنظیم نشده است.';
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
            self::$lastError = self::meliError('panel', $res['body']);
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
